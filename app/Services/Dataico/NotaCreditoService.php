<?php

namespace App\Services\Dataico;

use App\Models\DataicoConfiguracion;
use App\Models\DataicoEnvio;
use App\Models\DataicoNotaCredito;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anula ante la DIAN una factura electrónica emitida por Dataico.
 *
 * Una FE aceptada no se borra: se cancela con una nota crédito que la
 * referencia. Anular el recibo en Brynex sin esto dejaba la FE viva como
 * ingreso, y al re-facturar salía otra FE por la misma plata.
 *
 * La nota es TOTAL (razón ANULACION): repite los ítems de la FE tal como se
 * enviaron, que están guardados en `dataico_envios.payload`. El cliente, la
 * fecha y el CUFE los toma Dataico de la factura referenciada por su uuid.
 *
 * Forma del cuerpo sacada del ejemplo oficial "NC estructura básica" del portal
 * de Dataico (credit_note con invoice_id + numbering + items; actions en la
 * raíz, igual que en las facturas).
 */
class NotaCreditoService
{
    public const RAZON_ANULACION = 'ANULACION';

    private const PREFIJO_POR_DEFECTO = 'NC';

    /**
     * FE emitida y todavía vigente (sin nota crédito aceptada) de un recibo.
     */
    public static function feVigente(int $aliadoId, int $numeroFactura): ?DataicoEnvio
    {
        $envio = DataicoEnvio::where('aliado_id', $aliadoId)
            ->where('numero_factura', $numeroFactura)
            ->where('estado', DataicoEnvio::ESTADO_ENVIADO)
            ->first();

        if (! $envio) {
            return null;
        }

        $anulada = DataicoNotaCredito::where('dataico_envio_id', $envio->id)
            ->where('estado', DataicoNotaCredito::ESTADO_ENVIADO)
            ->exists();

        return $anulada ? null : $envio;
    }

    /**
     * @return array{ok: bool, mensaje: string, nota: ?DataicoNotaCredito, payload: ?array}
     */
    public function anular(DataicoEnvio $envio, string $motivo, ?int $usuarioId = null, bool $simular = false, bool $enviarCorreo = true): array
    {
        if (! $envio->fueEnviado() || blank($envio->dataico_uuid)) {
            return $this->falla('La factura electrónica no figura como emitida: no hay nada que anular.');
        }

        $cfg = DataicoConfiguracion::where('aliado_id', $envio->aliado_id)->first();
        if (! $cfg || blank($cfg->dataico_account_id) || blank($cfg->auth_token)) {
            return $this->falla('El aliado no tiene configurada la conexión con Dataico.');
        }

        $items = $this->itemsDeLaFactura($envio);
        if ($items === []) {
            return $this->falla("No se encontraron los ítems de {$envio->dataico_numero}: la nota crédito se debe hacer en el portal de Dataico.");
        }

        $consecutivo = (int) ($cfg->nc_ultimo_numero ?? 0) + 1;
        $payload = $this->construir($cfg, $envio, $items, $motivo, $consecutivo, $enviarCorreo);

        if ($simular) {
            return ['ok' => true, 'mensaje' => 'Simulación: no se envió nada.', 'nota' => null, 'payload' => $payload];
        }

        $nota = $this->reclamar($envio, $motivo, $usuarioId, $payload, $items);
        if ($nota === null) {
            return $this->falla("{$envio->dataico_numero} ya tiene una nota crédito emitida o en curso.");
        }

        $cliente = new ApiClient($cfg);
        $respuesta = $cliente->crearNotaCredito($payload);

        // Igual que en las facturas: si el consecutivo no es el que espera,
        // Dataico dice cuál es. Pasa si alguien hizo una nota por el portal.
        if (! $respuesta['ok'] && $esperado = $this->consecutivoEsperado($respuesta['error'] ?? '')) {
            $consecutivo = $esperado;
            $payload = $this->construir($cfg, $envio, $items, $motivo, $consecutivo, $enviarCorreo);
            $respuesta = $cliente->crearNotaCredito($payload);
        }

        if (! $respuesta['ok']) {
            $this->marcarError($nota, $payload, $respuesta['raw'], (string) $respuesta['error']);

            return $this->falla('Dataico no aceptó la nota crédito: '.$respuesta['error'], $nota);
        }

        // El documento ya existe en Dataico aunque la DIAN lo rechace: el
        // consecutivo queda consumido.
        $cfg->forceFill([
            'nc_prefijo' => $cfg->nc_prefijo ?: self::PREFIJO_POR_DEFECTO,
            'nc_ultimo_numero' => $consecutivo,
        ])->save();

        $body = $respuesta['body'] ?? [];
        if ($rechazo = $this->rechazoDian($body)) {
            $this->marcarError($nota, $payload, $respuesta['raw'], $rechazo);

            return $this->falla('La DIAN rechazó la nota crédito: '.$rechazo, $nota);
        }

        $nota->forceFill([
            'estado' => DataicoNotaCredito::ESTADO_ENVIADO,
            'numero' => $this->buscar($body, ['number', 'numero']),
            'dataico_uuid' => $this->buscar($body, ['uuid', 'id']),
            'cude' => $this->buscar($body, ['cude', 'cufe']),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'respuesta' => $this->respuestaGuardable($respuesta),
            'error_mensaje' => null,
            'enviado_at' => now(),
        ])->save();

        // Anticipos que salieron de una fila de este recibo con la administración
        // marcada como facturada en esta FE: anulada la FE, esa administración
        // ya no está facturada y la factura que los consuma debe volver a emitirla.
        \App\Models\Anticipo::where('aliado_id', $envio->aliado_id)
            ->where('dataico_envio_id', $envio->id)
            ->where('admon_fe_pendiente', '>', 0)
            ->update(['admon_fe_pendiente' => 0]);

        Log::info('[dataico] nota crédito emitida', [
            'aliado_id' => $envio->aliado_id,
            'numero_factura' => $envio->numero_factura,
            'factura' => $envio->dataico_numero,
            'nota' => $nota->numero,
        ]);

        return [
            'ok' => true,
            'mensaje' => "Nota crédito {$nota->numero} emitida: anula {$envio->dataico_numero}.",
            'nota' => $nota,
            'payload' => null,
        ];
    }

    // ─── Piezas ──────────────────────────────────────────────────────────

    private function construir(DataicoConfiguracion $cfg, DataicoEnvio $envio, array $items, string $motivo, int $consecutivo, bool $enviarCorreo): array
    {
        $original = json_decode((string) $envio->payload, true) ?: [];
        $accionesFe = $original['actions'] ?? [];

        // El correo va a donde fue la FE, salvo que se pida no avisarle al cliente
        // (una nota que corrige un error interno, como un recibo duplicado).
        $actions = ['send_dian' => true, 'send_email' => $enviarCorreo && (bool) ($accionesFe['send_email'] ?? false)];
        if ($actions['send_email'] && filled($accionesFe['email'] ?? null)) {
            $actions['email'] = $accionesFe['email'];
        }

        return [
            'credit_note' => [
                'env' => $cfg->env ?: 'PRODUCCION',
                'dataico_account_id' => $cfg->dataico_account_id,
                'invoice_id' => $envio->dataico_uuid,
                // Con solo el uuid la DIAN la acepta, pero deja la notificación
                // CBF02 «No se informó el número de la factura referenciada» (NC1).
                'invoice_number' => $envio->dataico_numero,
                'issue_date' => now()->format('d/m/Y H:i:s'),
                'reason' => self::RAZON_ANULACION,
                'number' => (string) $consecutivo,
                'numbering' => [
                    'prefix' => $cfg->nc_prefijo ?: self::PREFIJO_POR_DEFECTO,
                    'flexible' => true,
                ],
                'items' => $items,
                'notes' => array_values(array_filter([
                    mb_substr(trim($motivo), 0, 250),
                    "Brynex {$envio->numero_factura}",
                ])),
            ],
            'actions' => $actions,
        ];
    }

    /** Los ítems con que salió la FE, sin tocar: la nota la cancela completa. */
    private function itemsDeLaFactura(DataicoEnvio $envio): array
    {
        $original = json_decode((string) $envio->payload, true) ?: [];
        $items = $original['invoice']['items'] ?? [];

        return collect($items)
            ->filter(fn ($i) => is_array($i) && isset($i['price'], $i['quantity']))
            ->map(fn ($i) => array_intersect_key($i, array_flip(['sku', 'description', 'quantity', 'price', 'measuring_unit', 'taxes'])))
            ->values()
            ->all();
    }

    /**
     * Toma la nota en estado `enviando`. Devuelve null si la FE ya tiene una
     * emitida o en curso; una que quedó en error se reintenta.
     */
    private function reclamar(DataicoEnvio $envio, string $motivo, ?int $usuarioId, array $payload, array $items): ?DataicoNotaCredito
    {
        $datos = [
            'estado' => DataicoNotaCredito::ESTADO_ENVIANDO,
            'motivo' => mb_substr($motivo, 0, 500),
            'usuario_id' => $usuarioId,
            'valor' => collect($items)->sum(fn ($i) => (float) $i['price'] * (float) $i['quantity']),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'error_mensaje' => null,
        ];

        $existente = DataicoNotaCredito::where('dataico_envio_id', $envio->id)->first();

        if ($existente) {
            $tomadas = DB::table('dataico_notas_credito')
                ->where('id', $existente->id)
                ->where('estado', DataicoNotaCredito::ESTADO_ERROR)
                ->update($datos + ['updated_at' => now()]);

            return $tomadas === 1 ? $existente->refresh() : null;
        }

        try {
            return DataicoNotaCredito::create($datos + [
                'aliado_id' => $envio->aliado_id,
                'dataico_envio_id' => $envio->id,
                'numero_factura' => $envio->numero_factura,
                'factura_dataico_numero' => $envio->dataico_numero,
                'razon' => self::RAZON_ANULACION,
            ]);
        } catch (QueryException $e) {
            // Choque contra el índice único: otro proceso la tomó primero.
            return null;
        }
    }

    private function marcarError(DataicoNotaCredito $nota, array $payload, string $raw, string $error): void
    {
        $nota->forceFill([
            'estado' => DataicoNotaCredito::ESTADO_ERROR,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'respuesta' => mb_substr($raw, 0, 8000),
            'error_mensaje' => mb_substr($error, 0, 1000),
        ])->save();

        Log::warning('[dataico] nota crédito rechazada', [
            'aliado_id' => $nota->aliado_id,
            'numero_factura' => $nota->numero_factura,
            'error' => $error,
        ]);
    }

    /** Solo DIAN_RECHAZADO es rechazo; pendiente o no enviado ya consumió el número. */
    private function rechazoDian(array $body): ?string
    {
        $doc = $body['credit_note'] ?? $body;
        if ((string) ($doc['dian_status'] ?? '') !== 'DIAN_RECHAZADO') {
            return null;
        }

        $motivos = collect($doc['dian_messages'] ?? [])
            ->filter(fn ($m) => is_string($m) && str_contains($m, 'Rechazo'))
            ->implode(' | ');

        return 'DIAN_RECHAZADO: '.($motivos !== '' ? $motivos : 'sin detalle');
    }

    /** "…Tiene que ser el siguiente número 'NC3'" → 3 */
    private function consecutivoEsperado(string $error): ?int
    {
        if (! str_contains($error, 'siguiente número') || ! preg_match_all("/'([^']*?)(\d+)'/", $error, $m)) {
            return null;
        }

        $ultimo = end($m[2]);

        return $ultimo !== false ? (int) $ultimo : null;
    }

    private function buscar(array $body, array $llaves): ?string
    {
        foreach ([$body, $body['credit_note'] ?? [], $body['data'] ?? []] as $nivel) {
            foreach ($llaves as $k) {
                if (isset($nivel[$k]) && is_scalar($nivel[$k])) {
                    return (string) $nivel[$k];
                }
            }
        }

        return null;
    }

    /**
     * La respuesta trae el XML firmado en base64 (decenas de KB): cortada a 8000
     * caracteres quedaba un JSON inválido. Se guarda sin él; el XML y el PDF se
     * bajan de `xml_url` / `pdf_url`, que sí quedan.
     */
    private function respuestaGuardable(array $respuesta): string
    {
        $body = $respuesta['body'] ?? null;
        if (! is_array($body)) {
            return mb_substr((string) $respuesta['raw'], 0, 8000);
        }

        unset($body['xml'], $body['credit_note']['xml']);

        return mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8000);
    }

    private function falla(string $mensaje, ?DataicoNotaCredito $nota = null): array
    {
        return ['ok' => false, 'mensaje' => $mensaje, 'nota' => $nota, 'payload' => null];
    }
}
