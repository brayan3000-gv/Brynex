<?php

namespace App\Services\ArlColmena;

use App\Models\ArlAfiliacion;
use App\Models\Contrato;
use App\Models\DocumentoCliente;
use App\Models\Radicado;
use App\Services\ArlSura\ArlAfiliacionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orquesta el ciclo del contrato ante ARL Colmena: afiliar, anular y retirar.
 *
 * Es el gemelo de [[App\Services\ArlSura\ArlAfiliacionService]] y guarda en la
 * misma tabla (`arl_afiliaciones`, con `entidad = arl_colmena`), para que el
 * historial del contrato se lea igual sin importar la ARL.
 *
 * Tres reglas de Colmena que mandan sobre el flujo, comprobadas en el portal el
 * 15-sep-2026:
 *
 *   1. **El ingreso solo puede empezar mañana o después.** No hay afiliación
 *      retroactiva ni el mismo día.
 *   2. **La anulación del ingreso caduca**: se acepta hasta un día calendario
 *      después del inicio de la vigencia. Después, la salida es el retiro.
 *   3. **El retiro retroactivo tiene tope** (~40 días hacia atrás), así que
 *      esperar a fin de mes para radicar retiros del mes anterior no funciona.
 */
class ColmenaAfiliacionService
{
    public function __construct(
        private ColmenaApiService $api,
        private ColmenaPayloadBuilder $builder,
    ) {}

    /**
     * @param  string|null  $nit  Empresa ante Colmena, cuando no es la razón
     *                            social del contrato. Pasa en las agrupadoras —el contrato con la ARL
     *                            está a nombre de otra empresa del grupo— y en las pruebas.
     */
    public static function paraContrato(Contrato $contrato, ?string $nit = null): self
    {
        $nit ??= $contrato->razonSocial?->nit
            ?? throw new RuntimeException("La razón social del contrato {$contrato->id} no tiene NIT.");

        $api = new ColmenaApiService((string) $nit);

        return new self($api, new ColmenaPayloadBuilder($api));
    }

    public function api(): ColmenaApiService
    {
        return $this->api;
    }

    /** @var (callable(string): void)|null Avisa cada paso a quien esté mirando (la pantalla). */
    private $avance = null;

    public function conAvance(?callable $avance): static
    {
        $this->avance = $avance;

        return $this;
    }

    private function avisar(string $paso): void
    {
        if ($this->avance) {
            ($this->avance)($paso);
        }
    }

    public function builder(): ColmenaPayloadBuilder
    {
        return $this->builder;
    }

    // ─── Consulta ────────────────────────────────────────────────────

    /**
     * La cobertura del trabajador según Colmena, o null si no está.
     *
     * Es la única fuente confiable: los contratos afiliados a mano no tienen
     * historial en BryNex aunque su cobertura exista.
     */
    public function coberturaEnColmena(Contrato $contrato): ?array
    {
        $tipo = $this->builder->tipoDocumento($contrato->cliente?->tipo_doc)['id'];

        return $this->api->consultarDependiente($tipo, (string) $contrato->cedula);
    }

    /** Vigente = sin fecha de fin real (Colmena pone 2050-12-31 a los vigentes). */
    public function estaVigente(?array $cobertura): bool
    {
        $fin = $cobertura['endEffectiveDate'] ?? null;

        return $fin ? Carbon::parse($fin)->year >= 2050 : false;
    }

    // ─── Afiliación ──────────────────────────────────────────────────

    /**
     * Radica el ingreso del trabajador.
     *
     * `$inicio` por defecto es mañana, que es lo primero que Colmena acepta.
     */
    public function afiliar(Contrato $contrato, ?Carbon $inicio = null, ?int $usuarioId = null, ?string $arlAnteriorId = null): ArlAfiliacion
    {
        $inicio ??= now()->addDay();

        if ($inicio->startOfDay()->lte(now()->startOfDay())) {
            throw new RuntimeException(
                'Colmena no cubre el mismo día: la vigencia tiene que empezar mañana ('.
                now()->addDay()->format('d/m/Y').') o después.'
            );
        }

        $this->avisar('Armando los datos del ingreso');
        $payload = $this->builder->paraAfiliacion($contrato, $inicio, $arlAnteriorId);

        $registro = new ArlAfiliacion([
            'aliado_id' => $contrato->aliado_id,
            'contrato_id' => $contrato->id,
            'razon_social_id' => $contrato->razon_social_id,
            'cedula' => $contrato->cedula,
            'entidad' => ArlAfiliacion::ENTIDAD_COLMENA,
            'operacion' => ArlAfiliacion::OP_AFILIACION,
            // Colmena no tiene póliza: lo que identifica a la empresa es el
            // consecutivo del contrato, y es lo que pide su asesor.
            'poliza' => $payload['contractId'],
            'tipo_afiliado' => 'D',
            'tipo_cotizante' => $payload['contributorType']['id'],
            'codigo_centro' => $payload['headquarterId'],
            'nivel_riesgo' => (int) $contrato->n_arl,
            'fecha_inicio_cobertura' => $inicio->toDateString(),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'usuario_id' => $usuarioId,
        ]);

        try {
            $this->avisar('Radicando el ingreso en Colmena');

            try {
                $respuesta = $this->api->afiliarDependiente($payload);
            } catch (RuntimeException $e) {
                // Colmena valida la dirección con reglas que no publica. Si la del
                // trabajador no le sirve, se manda una sola vez la de la empresa:
                // el rechazo no deja nada radicado, así que no hay duplicado.
                if (! str_contains($e->getMessage(), 'dirección del trabajador no es válida')) {
                    throw $e;
                }

                $this->avisar('Colmena rechazó la dirección del trabajador: se usa la de la empresa');
                try {
                    // La bandera no puede quedarse puesta: el builder se reutiliza en lotes.
                    $payload = $this->builder->usarDireccionDeLaEmpresa()->paraAfiliacion($contrato, $inicio, $arlAnteriorId);
                } finally {
                    $this->builder->usarDireccionDeLaEmpresa(false);
                }
                $registro->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $respuesta = $this->api->afiliarDependiente($payload);
            }
        } catch (Throwable $e) {
            $registro->fill([
                'estado' => ArlAfiliacion::ESTADO_FALLIDA,
                'mensaje_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            throw $e;
        }

        $registro->fill([
            'estado' => ArlAfiliacion::ESTADO_EXITOSA,
            'codigo_transaccion' => (string) ($respuesta['filingNumber'] ?? $respuesta['radicationNumber'] ?? ''),
            'fecha_proceso' => isset($respuesta['filingNewDate'])
                ? Carbon::parse($respuesta['filingNewDate'])
                : now(),
            'respuesta' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
        ])->save();

        $contrato->update(['fecha_arl' => $registro->fecha_inicio_cobertura]);

        // Sin certificado no se deshace nada: la afiliación ya quedó radicada y
        // el PDF se puede bajar después con `arl:colmena certificado`.
        $certificado = null;
        $this->avisar('Descargando el certificado de afiliación');
        try {
            $certificado = $this->archivarCertificado($contrato, $usuarioId);
        } catch (Throwable $e) {
            Log::warning('ARL Colmena: afiliado, pero sin certificado', ['contrato' => $contrato->id, 'error' => $e->getMessage()]);
            $registro->update(['mensaje_error' => 'Afiliado, pero sin certificado: '.Str::limit($e->getMessage(), 400)]);
        }

        $this->avisar('Cerrando el radicado en BryNex');
        $this->cerrarRadicado($contrato, $registro, $usuarioId, $certificado);

        return $registro;
    }

    /**
     * Deja el radicado de ARL en OK con el número de radicación de Colmena.
     *
     * El certificado no viene en la respuesta del ingreso: se pide aparte
     * (`archivarCertificado`) y, si se tiene, se adjunta al radicado.
     */
    private function cerrarRadicado(Contrato $contrato, ArlAfiliacion $afiliacion, ?int $usuarioId, ?DocumentoCliente $certificado = null): void
    {
        Radicado::updateOrCreate(
            ['contrato_id' => $contrato->id, 'tipo' => Radicado::TIPO_ARL],
            [
                'aliado_id' => $contrato->aliado_id,
                'estado' => Radicado::ESTADO_OK,
                'numero_radicado' => $afiliacion->codigo_transaccion,
                'canal_envio' => Radicado::CANAL_WEB,
                'ruta_pdf' => $certificado?->ruta,
                'fecha_confirmacion' => now(),
                'confirmado_por' => 'arl_colmena',
                'confirmado_en' => now(),
                'user_id' => $usuarioId,
                'observacion' => 'Afiliación automática en ARL Colmena desde BryNex. Cobertura desde '.
                    $afiliacion->fecha_inicio_cobertura->format('d/m/Y').'.',
            ]
        );
    }

    // ─── Certificado ─────────────────────────────────────────────────

    /**
     * Baja el certificado de afiliación del portal y lo archiva contra la
     * cédula del cliente, igual que el soporte de ARL Sura. Va al disco
     * `local`: lleva salario y documento.
     */
    public function archivarCertificado(Contrato $contrato, ?int $usuarioId = null): DocumentoCliente
    {
        $tipo = $this->builder->tipoDocumento($contrato->cliente?->tipo_doc)['id'];
        $pdf = $this->api->certificadoAfiliacionDependiente($tipo, (string) $contrato->cedula);

        $nombre = ArlAfiliacionService::DOC_SOPORTE.'_'.$contrato->cedula.'_'.now()->format('Ymd_His').'.pdf';
        $ruta = "documentos/{$contrato->aliado_id}/{$contrato->cedula}/{$nombre}";

        Storage::disk('local')->put($ruta, $pdf);

        $documento = DocumentoCliente::create([
            'aliado_id' => $contrato->aliado_id,
            'cc_cliente' => $contrato->cedula,
            'tipo_documento' => ArlAfiliacionService::DOC_SOPORTE,
            'nombre_archivo' => $nombre,
            'ruta' => $ruta,
            // Por comando no hay sesión y la columna no admite nulo: el 2 es el dueño.
            'subido_por' => $usuarioId ?? 2,
        ]);

        Radicado::where('contrato_id', $contrato->id)
            ->where('tipo', Radicado::TIPO_ARL)
            ->update(['ruta_pdf' => $ruta]);

        return $documento;
    }

    // ─── Anulación ───────────────────────────────────────────────────

    /**
     * Anula el ingreso: la vigencia desaparece y no se paga ese día.
     *
     * Colmena lo acepta hasta un día calendario después del inicio de la
     * vigencia. Si ya no hay ingreso que anular responde 404 con ese mismo
     * mensaje del plazo, que despista: por eso se consulta antes.
     */
    public function anular(Contrato $contrato, ?int $usuarioId = null): ArlAfiliacion
    {
        $vigente = ArlAfiliacion::where('contrato_id', $contrato->id)
            ->where('entidad', ArlAfiliacion::ENTIDAD_COLMENA)
            ->where('operacion', ArlAfiliacion::OP_AFILIACION)
            ->where('estado', ArlAfiliacion::ESTADO_EXITOSA)
            ->orderByDesc('id')
            ->first();

        $cobertura = $this->coberturaEnColmena($contrato);
        $inicio = isset($cobertura['initEffectiveDate'])
            ? Carbon::parse($cobertura['initEffectiveDate'])
            : ($vigente?->fecha_inicio_cobertura ?: $contrato->fecha_arl);

        if (! $inicio) {
            throw new RuntimeException('Este contrato no tiene una afiliación en Colmena que anular.');
        }

        if ($inicio->copy()->addDay()->startOfDay()->lt(now()->startOfDay())) {
            throw new RuntimeException(
                'El plazo para anular venció: la vigencia empezó el '.$inicio->format('d/m/Y').
                ' y Colmena solo anula hasta un día calendario después. Toca retirar.'
            );
        }

        $tipo = $this->builder->tipoDocumento($contrato->cliente?->tipo_doc)['id'];

        $registro = new ArlAfiliacion([
            'aliado_id' => $contrato->aliado_id,
            'contrato_id' => $contrato->id,
            'razon_social_id' => $contrato->razon_social_id,
            'cedula' => $contrato->cedula,
            'entidad' => ArlAfiliacion::ENTIDAD_COLMENA,
            'operacion' => ArlAfiliacion::OP_ANULACION,
            'poliza' => $this->api->contrato(),
            'fecha_inicio_cobertura' => $inicio->toDateString(),
            'usuario_id' => $usuarioId,
        ]);

        try {
            $respuesta = $this->api->anularIngresoDependiente($tipo, (string) $contrato->cedula);
        } catch (Throwable $e) {
            $registro->fill([
                'estado' => ArlAfiliacion::ESTADO_FALLIDA,
                'mensaje_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            throw $e;
        }

        $registro->fill([
            'estado' => ArlAfiliacion::ESTADO_EXITOSA,
            'codigo_transaccion' => (string) ($respuesta['radicationNumber'] ?? ''),
            'fecha_proceso' => now(),
            'respuesta' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
        ])->save();

        $vigente?->update(['estado' => ArlAfiliacion::ESTADO_ANULADA]);

        // El radicado vuelve a pendiente: la cobertura ya no existe, y dejarlo
        // en OK sería peor que no tener nada. El update() de query no pasa por
        // el evento del modelo, así que las marcas se limpian a mano.
        Radicado::where('contrato_id', $contrato->id)
            ->where('tipo', Radicado::TIPO_ARL)
            ->update([
                'estado' => Radicado::ESTADO_PENDIENTE,
                'numero_radicado' => null,
                'fecha_confirmacion' => null,
                'confirmado_por' => null,
                'confirmado_en' => null,
                // El certificado describía una cobertura que ya no existe.
                'ruta_pdf' => null,
                'observacion' => 'Ingreso anulado en ARL Colmena el '.now()->format('d/m/Y H:i').
                    ($vigente?->codigo_transaccion ? ' (radicación '.$vigente->codigo_transaccion.').' : '.'),
            ]);

        $contrato->update(['fecha_arl' => null]);

        return $registro;
    }

    // ─── Retiro ──────────────────────────────────────────────────────

    /**
     * Retira al trabajador con la fecha indicada (por defecto, hoy).
     *
     * Colmena solo deja retroceder ~40 días y nunca antes de la fecha de
     * ingreso; el consecutivo del trabajador y el del centro salen de la
     * consulta, no de BryNex.
     */
    public function retirar(Contrato $contrato, ?Carbon $fecha = null, ?int $usuarioId = null): ArlAfiliacion
    {
        $fecha ??= now();
        $cobertura = $this->coberturaEnColmena($contrato)
            ?? throw new RuntimeException("El trabajador {$contrato->cedula} no está en este contrato de Colmena.");

        $trabajador = (int) ($cobertura['workerId'] ?? 0);
        $centro = (int) ($cobertura['headquarterId'] ?? 0);

        if (! $trabajador || ! $centro) {
            throw new RuntimeException('Colmena no devolvió el consecutivo del trabajador o del centro de trabajo.');
        }

        $registro = new ArlAfiliacion([
            'aliado_id' => $contrato->aliado_id,
            'contrato_id' => $contrato->id,
            'razon_social_id' => $contrato->razon_social_id,
            'cedula' => $contrato->cedula,
            'entidad' => ArlAfiliacion::ENTIDAD_COLMENA,
            'operacion' => ArlAfiliacion::OP_RETIRO,
            'poliza' => $this->api->contrato(),
            'codigo_centro' => (string) $centro,
            'fecha_fin_cobertura' => $fecha->toDateString(),
            'usuario_id' => $usuarioId,
        ]);

        try {
            $respuesta = $this->api->retirarDependiente($trabajador, $centro, $fecha->toDateString());
        } catch (Throwable $e) {
            $registro->fill([
                'estado' => ArlAfiliacion::ESTADO_FALLIDA,
                'mensaje_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            throw $e;
        }

        $registro->fill([
            'estado' => ArlAfiliacion::ESTADO_EXITOSA,
            'codigo_transaccion' => (string) ($respuesta['radicationNumber'] ?? ''),
            'fecha_proceso' => now(),
            'respuesta' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
        ])->save();

        return $registro;
    }
}
