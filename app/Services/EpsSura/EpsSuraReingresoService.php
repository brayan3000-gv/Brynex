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
     * Registra en BryNex lo que la extensión trajo del portal.
     *
     * El reingreso por extensión lo opera el navegador de la persona, que es el
     * único sitio donde el visor del comprobante dibuja: de ahí llegan el
     * número, el código de transacción y el PDF del comprobante —el que muestra
     * solo la empresa del trámite, a diferencia del certificado—.
     *
     * @param  array{radicado?:?string, transaccion?:?string, periodoPago?:?string, resultado?:?string, pdf?:?string, error?:?string, ok?:bool}  $entrada
     */
    public function aplicar(Contrato $contrato, array $entrada, ?int $usuarioId): array
    {
        $radicado = $this->radicadoEps($contrato);
        $numero = trim((string) ($entrada['radicado'] ?? '')) ?: (string) $radicado->numero_radicado;
        $aplicada = (bool) ($entrada['ok'] ?? false);

        $ruta = null;
        if ($pdf = $entrada['pdf'] ?? null) {
            $ruta = EpsRadicado::guardarPdf($contrato, base64_decode($pdf, true) ?: null, 'eps_sura_comprobante');
        }

        $detalle = collect([
            ($entrada['transaccion'] ?? null) ? 'transacción '.$entrada['transaccion'] : null,
            ($entrada['periodoPago'] ?? null) ? 'inicio de pago '.$entrada['periodoPago'] : null,
        ])->filter()->implode('; ');

        // Que el portal diga que ya está vigente no es un fallo: ya estaba hecho.
        $yaEstaba = (bool) preg_match('/vigente(,)? (con|para) (el|este) (mismo )?empleador|ya se encuentra|ya existe/i', (string) ($entrada['error'] ?? ''));

        EpsRadicado::marcar(
            $radicado, $numero,
            match (true) {
                $aplicada => Radicado::ESTADO_TRAMITE,
                $yaEstaba => Radicado::ESTADO_PENDIENTE,
                default => Radicado::ESTADO_ERROR,
            },
            $ruta,
            match (true) {
                $aplicada => 'EPS SURA: novedad de reingreso aplicada con éxito desde el portal'.($detalle ? ' ('.$detalle.')' : '').'.'
                    .($ruta ? ' Comprobante guardado.' : '').' Queda en trámite hasta que la conciliación lo vea vigente.',
                $yaEstaba => 'EPS SURA: el afiliado ya está vigente con este empleador, así que la novedad no hacía falta. '.($entrada['error'] ?? ''),
                default => 'EPS SURA (reingreso por extensión): '.($entrada['error'] ?? 'el portal no confirmó la novedad.'),
            },
            $usuarioId
        );

        $radicado = $radicado->fresh();

        return [
            'ok' => $aplicada,
            'estado' => $radicado->estado,
            'numero' => $radicado->numero_radicado,
            'comprobante' => (bool) $ruta,
            'radicado' => $radicado->paraLaLista(),
        ];
    }

    /**
     * Baja el certificado de afiliación al PBS y lo guarda con los soportes del
     * contrato. Solo lee del portal, así que se puede pedir cuantas veces haga
     * falta —y también para quien ya estaba afiliado—.
     *
     * @return array{ok: bool, ruta?: ?string, error?: string}
     */
    public function certificado(Contrato $contrato, ?int $usuarioId = null): array
    {
        $prep = $this->preparar($contrato);
        $datos = $prep['datos'] ?? [
            'persona' => [
                'tipo' => strtoupper((string) $contrato->cliente?->tipo_doc),
                'numero' => (string) $contrato->cedula,
            ],
        ];

        $salida = $this->correr($contrato, 'certificado', $datos);
        $ruta = $this->guardarSoporte($contrato, $salida['soporte'] ?? null);

        if ($ruta) {
            $radicado = $this->radicadoEps($contrato);
            EpsRadicado::marcar(
                $radicado, (string) $radicado->numero_radicado, $radicado->estado, $ruta,
                'Certificado de afiliación de EPS SURA guardado con los soportes.',
                $usuarioId
            );

            return ['ok' => true, 'ruta' => $ruta, 'radicado' => $radicado->fresh()->paraLaLista()];
        }

        return ['ok' => false, 'error' => $salida['error'] ?? 'El portal no entregó el certificado.'];
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
        $ruta = $this->guardarSoporte($contrato, $salida['soporte'] ?? null);

        // El portal no siempre entrega el soporte de la novedad; el certificado
        // de afiliación sirve de reemplazo. Se pide también cuando el
        // comprobante no se pudo leer —antes solo se intentaba si todo había
        // salido redondo, que es justo cuando menos falta hacía—.
        if (! $ruta && (($salida['ok'] ?? false) || ($salida['enComprobante'] ?? false))) {
            $cert = $this->correr($contrato, 'certificado', $prep['datos']);
            $ruta = $this->guardarSoporte($contrato, $cert['soporte'] ?? null);
            $salida['certificado_error'] = $ruta ? null : ($cert['error'] ?? null);
        }
        $salida['soporte_guardado'] = (bool) $ruta;

        if (! ($salida['ok'] ?? false)) {
            // Si el portal llegó a mostrar el comprobante, la novedad se envió
            // aunque no se haya podido leer: queda en trámite, porque marcarlo
            // como error invita a repetirlo y a duplicar el trámite en SURA.
            $llegoAlComprobante = (bool) ($salida['enComprobante'] ?? false);

            // Que el portal rechace por «ya está vigente con este empleador» no
            // es un fallo del trámite: es que ya estaba hecho. Queda pendiente
            // —no en error— y la conciliación lo cerrará cuando lo confirme.
            $yaEstaba = (bool) preg_match('/vigente(,)? (con|para) (el|este) (mismo )?empleador|ya se encuentra|ya existe/i', $nota);

            EpsRadicado::marcar(
                $radicado,
                // El número del formulario se guarda solo si se llegó al
                // comprobante: si el portal rechazó, ese número no es de nadie.
                $llegoAlComprobante ? (trim((string) ($salida['radicado'] ?? '')) ?: (string) $radicado->numero_radicado) : (string) $radicado->numero_radicado,
                match (true) {
                    $yaEstaba => Radicado::ESTADO_PENDIENTE,
                    $llegoAlComprobante => Radicado::ESTADO_TRAMITE,
                    default => Radicado::ESTADO_ERROR,
                },
                // El soporte se baja igual aunque el comprobante no se lea: sin
                // esto el PDF quedaba en disco y el radicado sin él.
                $ruta,
                match (true) {
                    $yaEstaba => 'EPS SURA: el afiliado ya está vigente con este empleador, así que la novedad no hacía falta. '.$nota,
                    $llegoAlComprobante => 'EPS SURA: la novedad se envió y el portal mostró el comprobante, pero BryNex no pudo leer el resultado'
                        .(($salida['radicado'] ?? null) ? ' (la solicitud del formulario era la '.$salida['radicado'].', sin confirmar)' : '')
                        .'.'.($ruta ? ' Soporte guardado.' : '').' NO repetir sin revisarlo antes en el portal.',
                    default => 'EPS SURA (reingreso): no se pudo radicar. '.($nota ?: 'Sin detalle del portal.'),
                },
                $usuarioId
            );

            return $salida + [
                'radicado_brynex' => $radicado->fresh()->estado,
                'radicado' => $radicado->fresh()->paraLaLista(),
            ];
        }

        // El comprobante trae el número de solicitud —que es el radicado del
        // trámite— y el código de transacción, que es su respaldo.
        $numero = trim((string) ($salida['radicado'] ?? '')) ?: (string) $radicado->numero_radicado;
        $detalle = collect([
            $nota ?: null,
            ($salida['transaccion'] ?? null) ? 'transacción '.$salida['transaccion'] : null,
            ($salida['periodoPago'] ?? null) ? 'inicio de pago '.$salida['periodoPago'] : null,
        ])->filter()->implode('; ');

        EpsRadicado::marcar(
            $radicado, $numero, Radicado::ESTADO_TRAMITE, $ruta,
            'EPS SURA: novedad de reingreso aplicada con éxito'.($detalle ? ' ('.$detalle.')' : '').'.'
                .($ruta ? ' Comprobante guardado.' : ' El portal no entregó el comprobante.')
                .' Queda en trámite hasta que la conciliación lo vea vigente.',
            $usuarioId
        );

        return $salida + [
            'radicado_brynex' => $radicado->fresh()->estado,
            // Con esto el listado repinta la pastilla de EPS sin recargar.
            'radicado' => $radicado->fresh()->paraLaLista(),
        ];
    }

    /**
     * Cómo se lanza el script: en el servidor, con un display virtual.
     *
     * El comprobante del reingreso lo pinta un visor de Crystal Reports que en
     * un navegador sin ventana no dibuja nada —de ahí que no se pudiera leer el
     * resultado ni imprimir la pantalla—. `xvfb-run` le da esa ventana sin
     * necesidad de pantalla de verdad. Donde no hay Xvfb (el Mac) se corre sin
     * ventana, que sirve para todo menos para el comprobante.
     */
    private function comando(): string
    {
        $node = ArlSuraSesionService::binarioNode().' scripts/eps-sura-reingreso.mjs';

        return $this->hayXvfb()
            ? 'xvfb-run -a --server-args="-screen 0 1400x900x24" '.$node
            : $node;
    }

    private function hayXvfb(): bool
    {
        static $hay = null;

        return $hay ??= is_executable('/usr/bin/xvfb-run');
    }

    /**
     * Guarda en los soportes del contrato el documento que entregó el portal.
     *
     * El script lo deja en un archivo temporal del servidor y solo devuelve la
     * ruta: por la salida del proceso no cabe (se corta a 64 KB y un PDF pesa
     * más). Aquí se lee, se guarda con los demás soportes y se borra el
     * temporal.
     */
    private function guardarSoporte(Contrato $contrato, ?array $soporte): ?string
    {
        $temporal = $soporte['ruta'] ?? null;
        if (! $temporal || ! is_file($temporal)) {
            return null;
        }

        $ruta = EpsRadicado::guardarPdf($contrato, file_get_contents($temporal) ?: null, 'eps_sura_reingreso');
        @unlink($temporal);
        @rmdir(dirname($temporal));

        return $ruta;
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
            // Con ventana el visor del comprobante se dibuja y el portal
            // entrega el documento; sin ella no pinta nada (ver el script).
            ->env(['SURA_CON_VENTANA' => $this->hayXvfb() ? '1' : '0'])
            // El login ronda los 40 s y el trámite otro tanto; con 240 s una
            // corrida con tropiezos se cortaba a la mitad, dejando la novedad
            // aplicada en SURA sin registrar en BryNex.
            ->timeout(420)
            ->input($entrada)
            ->run($this->comando());

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
