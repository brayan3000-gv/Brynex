<?php

namespace App\Services\NuevaEps;

use App\Models\Contrato;
use App\Models\EpsAfiliacion;
use App\Models\EpsPortalEmpresa;
use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reingreso de un trabajador dependiente en Nueva EPS desde BryNex.
 *
 * Es el mismo trámite que se hace a mano en "Reingresos y Retiros Laborales"
 * del portal (primer caso: Dennis Perez, radicado 10757307, 14-sep-2026). El
 * portal responde con el número de radicado y el certificado en PDF en la misma
 * llamada, así que el radicado de BryNex queda en trámite con ambos. Pasa a OK
 * cuando Nueva EPS lo procesa, lo que detecta `eps:conciliar-nueva-eps`.
 */
class NuevaEpsReingresoService
{
    /** Tipos de documento de BryNex que Nueva EPS reconoce en reingresos. */
    private const TIPOS_DOCUMENTO = ['CC', 'CE', 'TI', 'PA', 'PT', 'PE', 'RC', 'SC', 'CD'];

    /**
     * Revisa el contrato sin tocar el portal.
     *
     * @return array{problemas: string[], resumen: array, datos: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.eps', 'eps', 'plan', 'razonSocial', 'tipoModalidad']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $eps     = $contrato->eps ?: $cliente?->eps;
        $problemas = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if (! in_array($eps?->codigo, NuevaEpsPortalService::CODIGOS_EPS, true)) {
            $problemas[] = 'La EPS del contrato no es Nueva EPS.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'Solo se tramitan dependientes: los independientes entran al portal con su propio usuario.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } else {
            if (! in_array(strtoupper((string) $cliente->tipo_doc), self::TIPOS_DOCUMENTO, true)) {
                $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en Nueva EPS.";
            }
            if (! trim((string) $cliente->primer_apellido)) {
                $problemas[] = 'El cliente no tiene primer apellido para comparar con Nueva EPS.';
            }
        }

        $ibc = (int) round((float) ($contrato->ibc ?: $contrato->salario));
        if ($ibc <= 0) {
            $problemas[] = 'El contrato no tiene IBC ni salario.';
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }

        $cargo = $this->cargo($contrato);
        if (isset($cargo['error'])) {
            $problemas[] = $cargo['error'];
        }

        $credencial = $rs ? NuevaEpsPortalService::credencial((string) $rs->nit) : ['error' => 'Sin razón social.'];
        if (isset($credencial['error'])) {
            $problemas[] = $credencial['error'];
        }

        $empresa = $rs ? EpsPortalEmpresa::de(NuevaEpsPortalService::ENTIDAD, (string) $rs->nit) : null;

        $resumen = [
            'trabajador'   => trim(($cliente?->primer_nombre ?? '').' '.($cliente?->segundo_nombre ?? '').' '.($cliente?->primer_apellido ?? '').' '.($cliente?->segundo_apellido ?? '')),
            'documento'    => trim(($cliente?->tipo_doc ?? '').' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit'          => $rs?->nit,
            'eps'          => $eps?->nombre,
            'plan'         => $contrato->plan?->nombre,
            'ibc'          => $ibc,
            'fecha_inicio' => $contrato->fecha_ingreso?->format('d/m/Y'),
            'nivel_arl'    => $contrato->n_arl,
            'cargo_brynex' => $cargo['cargo_brynex'] ?? $contrato->cargo,
            'cargo_eps'    => isset($cargo['codigo']) ? $cargo['codigo'].' '.$cargo['descripcion'] : null,
            'asesor'       => $empresa?->codigo_asesor ? $empresa->codigo_asesor.' '.$empresa->nombre_asesor : null,
        ];

        $datos = $problemas ? null : [
            'modo'        => 'reingreso',
            'persona'     => [
                'tipo'     => strtoupper((string) $cliente->tipo_doc),
                'numero'   => (string) $contrato->cedula,
                'apellido' => (string) $cliente->primer_apellido,
            ],
            'ibc'         => $ibc,
            'fechaInicio' => $contrato->fecha_ingreso->toDateString(),
            // Un reingreso radicado poco antes del ingreso también cuenta como hecho.
            'buscarDesde' => $contrato->fecha_ingreso->copy()->subDays(45)->toDateString(),
            'cargo'       => ['codigo' => $cargo['codigo'], 'descripcion' => $cargo['descripcion']],
            'asesor'      => $empresa?->codigo_asesor,
        ];

        return ['problemas' => $problemas, 'resumen' => $resumen, 'datos' => $datos];
    }

    /**
     * Pregunta al portal sin registrar nada: nombre en la EPS, reingresos ya
     * radicados y asesor. Si la empresa no tiene asesor, guarda el más usado.
     */
    public function consultar(Contrato $contrato, ?int $usuarioId = null): array
    {
        $prep = $this->preparar($contrato);

        if ($prep['problemas']) {
            return ['ok' => false, 'problemas' => $prep['problemas'], 'resumen' => $prep['resumen']];
        }

        $empresa = $this->conciliacion()->deLaEmpresa($contrato);
        $salida  = NuevaEpsPortalService::ejecutar((string) $contrato->razonSocial->nit, $prep['datos'] + [
            'registrar' => false,
            'conciliar' => $this->conciliacion()->documentos($empresa),
        ]);
        $salida['conciliacion'] = $this->conciliarDePaso($empresa, $salida, $usuarioId);

        if (($salida['ok'] ?? false) && ! $prep['datos']['asesor'] && ($salida['asesor_sugerido'] ?? null)) {
            $nombre = collect($salida['asesores'] ?? [])->firstWhere('codigo', $salida['asesor_sugerido'])['nombre'] ?? null;
            EpsPortalEmpresa::de(NuevaEpsPortalService::ENTIDAD, (string) $contrato->razonSocial->nit)
                ->update(['codigo_asesor' => $salida['asesor_sugerido'], 'nombre_asesor' => $nombre]);
            $prep['resumen']['asesor'] = trim($salida['asesor_sugerido'].' '.$nombre);
        }

        return $salida + ['resumen' => $prep['resumen']];
    }

    /**
     * Radica el reingreso y deja el radicado de BryNex en trámite con el número
     * y el certificado. Si ya había uno radicado, no repite: lo vincula.
     */
    public function registrar(Contrato $contrato, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);

        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }
        if (! $prep['datos']['asesor']) {
            throw new RuntimeException('La empresa no tiene asesor de Nueva EPS: consulta primero para que se asigne.');
        }

        $empresa = $this->conciliacion()->deLaEmpresa($contrato);
        $salida  = NuevaEpsPortalService::ejecutar((string) $contrato->razonSocial->nit, $prep['datos'] + [
            'registrar' => true,
            'conciliar' => $this->conciliacion()->documentos($empresa),
        ]);
        $deLaEmpresa = $this->conciliarDePaso($empresa, $salida, $usuarioId);
        $radicado = $this->radicadoEps($contrato);

        $log = EpsAfiliacion::create([
            'aliado_id'       => $contrato->aliado_id,
            'contrato_id'     => $contrato->id,
            'radicado_id'     => $radicado->id,
            'entidad'         => NuevaEpsPortalService::ENTIDAD,
            'operacion'       => 'reingreso',
            'estado'          => match (true) {
                ! ($salida['ok'] ?? false)                  => 'fallida',
                ($salida['motivo'] ?? null) === 'ya_existe' => 'existente',
                default                                     => 'exitosa',
            },
            'numero_radicado' => $salida['radicado'] ?? ($salida['existentes'][0]['radicado'] ?? null),
            'payload'         => json_encode($salida['dto'] ?? $prep['datos'], JSON_UNESCAPED_UNICODE),
            // Los PDF van a disco, no a la base (también los de la conciliación de paso).
            'respuesta'       => json_encode(collect($salida)->except(['pdf', 'conciliacion'])->all(), JSON_UNESCAPED_UNICODE),
            'mensaje_error'   => isset($salida['error']) ? mb_substr((string) $salida['error'], 0, 500) : null,
            'usuario_id'      => $usuarioId,
        ]);

        if (! ($salida['ok'] ?? false)) {
            throw new RuntimeException($salida['error'] ?? 'Nueva EPS no respondió.');
        }

        if (($salida['motivo'] ?? null) === 'ya_existe') {
            $ex = $salida['existentes'][0];
            $this->marcarRadicado($radicado, $ex['radicado'], $ex['estado'], null,
                "Ya tenía reingreso en Nueva EPS: radicado {$ex['radicado']} del {$ex['fecha_radicacion']} ({$ex['estado']}). No se volvió a radicar.",
                $usuarioId);

            return ['ok' => true, 'ya_existia' => true, 'radicado' => $ex['radicado'], 'estado_eps' => $ex['estado'], 'conciliacion' => $deLaEmpresa];
        }

        $ruta = $this->guardarPdf($contrato, $salida['pdf'] ?? null);
        $log->update(['ruta_pdf' => $ruta]);

        $this->marcarRadicado($radicado, $salida['radicado'], 'RADICADO', $ruta, sprintf(
            'Reingreso radicado automáticamente en Nueva EPS el %s (radicado %s, asesor %s, cargo %s %s, IBC %s, inicio %s). Pendiente de que Nueva EPS lo procese.',
            now()->format('d/m/Y'), $salida['radicado'], $prep['datos']['asesor'],
            $prep['datos']['cargo']['codigo'], $prep['datos']['cargo']['descripcion'],
            number_format($prep['datos']['ibc'], 0, ',', '.'), $contrato->fecha_ingreso->format('d/m/Y')
        ), $usuarioId);

        return ['ok' => true, 'radicado' => $salida['radicado'], 'pdf' => (bool) $ruta, 'nombre_eps' => $salida['nombre_eps'] ?? null, 'conciliacion' => $deLaEmpresa];
    }

    /**
     * Aplica lo que el portal dijo de los demás radicados abiertos de la empresa
     * y devuelve un resumen corto para la pantalla (sin los PDF). Nunca tumba el
     * trámite principal: si falla, queda en el log y en el resumen.
     */
    private function conciliarDePaso(Collection $radicados, array $salida, ?int $usuarioId): ?array
    {
        $resultados = $salida['conciliacion'] ?? null;

        if (! ($salida['ok'] ?? false) || $radicados->isEmpty() || ! is_array($resultados)) {
            return null;
        }
        if (isset($resultados['error'])) {
            return ['error' => $resultados['error']];
        }

        try {
            $detalle = $this->conciliacion()->aplicar($radicados, $resultados, false, $usuarioId);
            $resumen = $this->conciliacion()->resumen($detalle, $radicados->count());
        } catch (Throwable $e) {
            Log::warning('Nueva EPS: falló la conciliación de paso', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }

        return [
            'revisados' => $resumen['total'],
            'cerrados'  => $resumen['cerrados'],
            'tramite'   => $resumen['tramite'],
            'faltan'    => $resumen['faltan'],
            'nombres_cerrados' => collect($detalle)->where('accion', 'cerrado')->pluck('nombre')->values()->all(),
        ];
    }

    /** Resuelta al usarse: la conciliación depende de este servicio. */
    private function conciliacion(): NuevaEpsConciliacionService
    {
        return app(NuevaEpsConciliacionService::class);
    }

    /**
     * Deja el radicado con el número de Nueva EPS: PROCESADO → ok, RADICADO →
     * trámite. No retrocede uno que ya esté en ok.
     */
    public function marcarRadicado(Radicado $radicado, string $numero, string $estadoEps, ?string $rutaPdf, string $observacion, ?int $usuarioId): void
    {
        DB::transaction(function () use ($radicado, $numero, $estadoEps, $rutaPdf, $observacion, $usuarioId) {
            $r = Radicado::whereKey($radicado->id)->lockForUpdate()->first();
            $anterior = $r->estado;
            $nuevo = strtoupper($estadoEps) === 'PROCESADO' ? Radicado::ESTADO_OK
                : ($anterior === Radicado::ESTADO_OK ? Radicado::ESTADO_OK : Radicado::ESTADO_TRAMITE);

            $r->update([
                'estado'               => $nuevo,
                'numero_radicado'      => $numero,
                'canal_envio'          => 'portal',
                'user_id'              => $usuarioId,
                'fecha_inicio_tramite' => $r->fecha_inicio_tramite ?? now(),
                'fecha_confirmacion'   => $nuevo === Radicado::ESTADO_OK ? ($r->fecha_confirmacion ?? now()) : $r->fecha_confirmacion,
                'ruta_pdf'             => $rutaPdf ?? $r->ruta_pdf,
                'observacion'          => trim(($r->observacion ? $r->observacion.' | ' : '').$observacion),
            ] + (strtoupper($estadoEps) === 'PROCESADO' ? $r->datosConfirmacion('nueva_eps') : []));

            RadicadoMovimiento::create([
                'radicado_id'     => $r->id,
                'contrato_id'     => $r->contrato_id,
                'tipo_proceso'    => 'afiliacion',
                'entidad'         => Radicado::TIPO_EPS,
                'user_id'         => $usuarioId,
                'estado_anterior' => $anterior,
                'estado_nuevo'    => $nuevo,
                'observacion'     => $observacion,
            ]);
        });
    }

    /** El certificado va al disco local, en la misma ruta que usa la carga manual de PDF del radicado. */
    public function guardarPdf(Contrato $contrato, ?string $base64): ?string
    {
        $binario = $base64 ? base64_decode($base64, true) : false;

        if (! $binario || ! str_starts_with($binario, '%PDF')) {
            return null;
        }

        $ruta = "radicados/{$contrato->aliado_id}/{$contrato->id}/{$contrato->cedula}/eps_".now()->format('Ymd_His').'.pdf';
        Storage::disk('local')->put($ruta, $binario);

        return $ruta;
    }

    public function radicadoEps(Contrato $contrato): Radicado
    {
        return Radicado::firstOrCreate(
            ['contrato_id' => $contrato->id, 'tipo' => Radicado::TIPO_EPS],
            ['aliado_id' => $contrato->aliado_id, 'estado' => Radicado::ESTADO_PENDIENTE]
        );
    }

    /**
     * El cargo con el que se reporta: el de la razón social para el nivel de ARL
     * del contrato, traducido al catálogo de Nueva EPS.
     *
     * @return array{codigo:string, descripcion:string, cargo_brynex:string}|array{error:string, cargo_brynex?:string}
     */
    private function cargo(Contrato $contrato): array
    {
        // El catálogo común (razon_social_id NULL) vale para todos los aliados, igual
        // que en RazonSocialCargo::porDefecto(): vive bajo el aliado 1 y los aliados
        // sin cargos propios (Global Contact en el 9) se quedaban sin ninguno.
        $query = DB::table('razon_social_cargos')
            ->where('activo', true)
            ->where(fn ($q) => $q->where('razon_social_id', $contrato->razon_social_id)->orWhereNull('razon_social_id'))
            ->whereNotNull('codigo_ocupacion');

        if ((int) $contrato->n_arl) {
            $query->where('nivel_riesgo', (int) $contrato->n_arl);
        }

        $nombre = Str::upper(Str::ascii((string) $contrato->cargo));

        $fila = $query->get(['cargo', 'codigo_ocupacion', 'razon_social_id', 'aliado_id', 'por_defecto'])
            ->sortByDesc(fn ($c) => [
                $c->razon_social_id ? 1 : 0,
                Str::upper(Str::ascii($c->cargo)) === $nombre ? 1 : 0,
                (int) $c->aliado_id === (int) $contrato->aliado_id ? 1 : 0,
                (int) $c->por_defecto,
            ])
            ->first();

        if (! $fila) {
            return ['error' => 'No hay cargo con código de ocupación para el nivel de riesgo '.((int) $contrato->n_arl ?: '—').' en el catálogo de cargos de la razón social.'];
        }

        $eq = DB::table('eps_ocupaciones')
            ->where('entidad', NuevaEpsPortalService::ENTIDAD)
            ->where('codigo_ocupacion', $fila->codigo_ocupacion)
            ->first();

        if (! $eq) {
            return ['error' => "El cargo {$fila->cargo} (ocupación {$fila->codigo_ocupacion}) no tiene equivalente en el catálogo de Nueva EPS todavía.", 'cargo_brynex' => $fila->cargo];
        }

        return ['codigo' => $eq->codigo_entidad, 'descripcion' => $eq->descripcion_entidad, 'cargo_brynex' => $fila->cargo];
    }
}
