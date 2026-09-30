<?php

namespace App\Services\EpsSura;

use App\Models\Contrato;
use App\Models\Radicado;
use App\Services\ArlSura\ArlSuraSesionService;
use App\Services\EpsPortal\EpsRadicado;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reingreso de un trabajador en EPS SURA desde BryNex.
 *
 * Es el trámite de Transacciones → Afiliados → Reingresos del portal de
 * empleadores, que corre en otra aplicación (ASP.NET WebForms) y se maneja con
 * `scripts/eps-sura-reingreso.mjs`.
 *
 * Ojo con la diferencia que decide si esto sirve o no: el portal hace
 * REINGRESOS, no afiliaciones nuevas. Si la persona nunca estuvo en SURA, el
 * buscador no devuelve el nombre y lo que corresponde es un traslado, que va por
 * otro camino. Por eso `consultar()` existe y se usa antes de radicar.
 *
 * El radicado de BryNex queda en trámite; pasa a OK cuando la conciliación
 * (`eps:conciliar-sura`) ve a la persona vigente con esa empresa.
 */
class EpsSuraReingresoService
{
    public const ENTIDAD = 'eps_sura';

    /** Tipos de documento que reconoce el buscador de personas del portal. */
    private const TIPOS_DOCUMENTO = ['CC', 'CE', 'TI', 'PA', 'PP', 'PT', 'PPT', 'PE', 'PEP', 'RC', 'SC', 'CD'];

    /**
     * Revisa el contrato sin tocar el portal.
     *
     * @return array{problemas: string[], avisos: string[], resumen: array, datos: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.eps', 'eps', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs = $contrato->razonSocial;
        $eps = $contrato->eps ?: $cliente?->eps;
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if ($eps?->codigo !== EpsSuraConciliacionService::CODIGO_EPS) {
            $problemas[] = 'La EPS del contrato no es EPS SURA.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'Solo se tramitan dependientes: los independientes entran al portal con su propio usuario.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! in_array(strtoupper((string) $cliente->tipo_doc), self::TIPOS_DOCUMENTO, true)) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en el portal de SURA.";
        }

        $ibc = (int) round((float) ($contrato->ibc ?: $contrato->salario));
        if ($ibc <= 0) {
            $problemas[] = 'El contrato no tiene IBC ni salario.';
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }

        $credencial = $rs ? $this->credencial($rs) : null;
        if (! $credencial) {
            $problemas[] = 'Esta empresa no tiene usuario del portal de Sura registrado en BryNex.';
        }

        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', Radicado::TIPO_EPS)->first();
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de EPS ya está en OK.';
        }
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite (N° {$radicado->numero_radicado}).";
        }

        $resumen = [
            'trabajador' => trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido]))),
            'documento' => trim(strtoupper((string) $cliente?->tipo_doc).' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit' => $rs?->nit,
            'eps' => $eps?->nombre,
            'ibc' => $ibc,
            'fecha_ingreso' => $contrato->fecha_ingreso?->format('d/m/Y'),
            'usuario_portal' => $credencial?->usuario,
            'estado_radicado' => $radicado?->estado,
            'numero_radicado' => $radicado?->numero_radicado,
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen, 'datos' => $problemas ? null : [
            'persona' => [
                'tipo' => strtoupper((string) $cliente->tipo_doc),
                'numero' => (string) $contrato->cedula,
            ],
            'ibc' => $ibc,
            'fechaIngreso' => $contrato->fecha_ingreso->toDateString(),
            // Sin asesor el portal no deja guardar; 0 es «SIN ASESOR».
            'asesor' => '0',
        ]];
    }

    /**
     * Pregunta al portal sin registrar nada: si la persona aparece, es
     * reingreso; si no, está fuera de SURA y toca traslado.
     */
    public function consultar(Contrato $contrato): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            return ['ok' => false, 'problemas' => $prep['problemas'], 'resumen' => $prep['resumen']];
        }

        return $this->correr($contrato, 'consultar', $prep['datos']) + ['resumen' => $prep['resumen']];
    }

    /**
     * Inventario de los campos de la pantalla de reingresos. No escribe nada:
     * es para ajustar los selectores contra el portal de verdad.
     */
    public function explorar(Contrato $contrato): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            return ['ok' => false, 'problemas' => $prep['problemas']];
        }

        return $this->correr($contrato, 'explorar', []);
    }

    /**
     * Radica el reingreso y deja el radicado de BryNex en trámite.
     */
    public function registrar(Contrato $contrato, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $salida = $this->correr($contrato, 'registrar', $prep['datos']);
        $radicado = $this->radicadoEps($contrato);
        $nota = trim((string) ($salida['alerta'] ?? $salida['error'] ?? ''));

        if (! ($salida['ok'] ?? false)) {
            EpsRadicado::marcar(
                $radicado, (string) $radicado->numero_radicado, Radicado::ESTADO_ERROR, null,
                'EPS SURA (reingreso): no se pudo radicar. '.($nota ?: 'Sin detalle del portal.'),
                $usuarioId
            );

            return $salida + ['radicado_brynex' => $radicado->fresh()->estado];
        }

        EpsRadicado::marcar(
            $radicado, (string) $radicado->numero_radicado, Radicado::ESTADO_TRAMITE, null,
            'EPS SURA (reingreso) radicado en el portal de empleadores'.($nota ? ': '.$nota : '.')
                .' Queda en trámite hasta que la conciliación lo vea vigente.',
            $usuarioId
        );

        return $salida + ['radicado_brynex' => $radicado->fresh()->estado];
    }

    /** El radicado de EPS del contrato; se crea si el plan lo incluye y no existía. */
    private function radicadoEps(Contrato $contrato): Radicado
    {
        return Radicado::firstOrCreate(
            ['contrato_id' => $contrato->id, 'tipo' => Radicado::TIPO_EPS],
            ['aliado_id' => $contrato->aliado_id, 'estado' => Radicado::ESTADO_PENDIENTE]
        );
    }

    /** Usuario del portal de Sura de la empresa (el mismo de la ARL). */
    private function credencial($rs)
    {
        $credencial = ArlSuraSesionService::credencialPara((int) $rs->aliado_id, (string) $rs->arl_poliza, (string) $rs->nit);

        return $credencial?->exists ? $credencial : null;
    }

    /**
     * Corre el Chrome headless que opera la pantalla de reingresos.
     */
    private function correr(Contrato $contrato, string $modo, array $datos): array
    {
        $credencial = $this->credencial($contrato->razonSocial);
        if (! $credencial) {
            return ['ok' => false, 'error' => 'Esta empresa no tiene usuario del portal de Sura registrado en BryNex.'];
        }

        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario' => $credencial->usuario,
            'contrasena' => $credencial->contrasena,
            'nitEmpresa' => preg_replace('/\D/', '', (string) $contrato->razonSocial->nit),
            'modo' => $modo,
        ] + $datos, JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(240)
            ->input($entrada)
            ->run(ArlSuraSesionService::binarioNode().' scripts/eps-sura-reingreso.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            // El mensaje nunca trae la clave: el script no la imprime.
            Log::warning('EPS SURA: el reingreso no salió', [
                'contrato' => $contrato->id,
                'modo' => $modo,
                'paso' => $salida['paso'] ?? null,
                'error' => $salida['error'] ?? trim($resultado->errorOutput()),
            ]);

            $salida['error'] ??= trim($resultado->errorOutput()) ?: 'El proceso del portal no devolvió respuesta.';
        }

        return $salida;
    }
}
