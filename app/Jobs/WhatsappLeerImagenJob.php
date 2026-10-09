<?php

namespace App\Jobs;

use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Ia\LecturaImagenService;
use App\Services\WhatsappWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lee una foto o un PDF que mandó un cliente y la devuelve al flujo de la IA, igual que
 * WhatsappTranscribirAudioJob con las notas de voz.
 *
 * - Lo leído queda como texto del mensaje, atienda el bot o una persona: en el inbox ya se ve
 *   «[Comprobante de pago leído por la IA: $245.600 · 8 de octubre…]» sin abrir la foto.
 * - Un comprobante, con el bot activo, recibe un mensaje armado con lo leído (no redactado por
 *   la IA, para que nunca diga «pago registrado») y la conversación pasa a una persona, que es
 *   quien confirma el pago y lo registra. Decisión del dueño, 8-oct-2026.
 * - Cualquier otra imagen (cédula, cotización de otro lado, incapacidad…) sigue en la
 *   conversación: la IA la lee como texto y responde; si no la puede resolver, pasa a persona.
 * - Si no se puede leer, el comportamiento de antes: mensaje fijo y pasa a una persona.
 */
class WhatsappLeerImagenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 120;

    /** Espera a que WhatsappDescargarMediaJob deje el archivo en disco. */
    public function backoff(): array
    {
        return [5, 10, 20, 30, 60];
    }

    public function __construct(protected int $mensajeId) {}

    public function handle(): void
    {
        $mensaje = WhatsappMensaje::find($this->mensajeId);
        $conversacion = $mensaje ? WhatsappConversacion::find($mensaje->conversacion_id) : null;
        if (! $conversacion) {
            return;
        }

        $contestaElBot = (bool) $conversacion->bot_activo;

        if (! $mensaje->mediaExiste()) {
            if ($this->attempts() >= $this->tries) {
                $this->escalar($conversacion, $mensaje->tipo, $contestaElBot);

                return;
            }
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);

            return;
        }

        $r = LecturaImagenService::leer(
            $conversacion->aliado_id,
            Storage::disk('local')->path($mensaje->media_url),
            $mensaje->media_mime_type,
            $mensaje->contenido // la leyenda que el cliente escribió con la foto, si la hay
        );
        LecturaImagenService::registrarConsumo($conversacion->aliado_id, $r);

        if (! $r['ok']) {
            Log::info("No se leyó la imagen del mensaje {$mensaje->id}: {$r['error']}");
            $this->escalar($conversacion, $mensaje->tipo, $contestaElBot);

            return;
        }

        $mensaje->update(['contenido' => $r['texto']]);

        if (! $contestaElBot) {
            return; // la lleva una persona: la lectura ya quedó en el inbox
        }

        if ($r['comprobante']) {
            $this->comprobante($conversacion, $r['comprobante']);

            return;
        }

        // Mismo cuidado que con las notas de voz: si llegó otro mensaje mientras se leía, ese ya
        // programó su respuesta y va a leer todo junto.
        $hayMasNuevo = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'entrante')
            ->where('id', '>', $mensaje->id)
            ->exists();

        if ($hayMasNuevo) {
            return;
        }

        Cache::put(WhatsappWebhookService::claveDebounce($conversacion->id), $mensaje->id, now()->addSeconds(30));
        WhatsappResponderIaJob::dispatch($conversacion->id, $mensaje->id);
    }

    /**
     * El cliente sabe que llegó y con qué valor; la persona que lo atiende ve lo leído en el
     * motivo y solo tiene que confirmar contra el banco y registrar el pago.
     */
    private function comprobante(WhatsappConversacion $conversacion, array $comprobante): void
    {
        $resumen = LecturaImagenService::resumenComprobante($comprobante);
        $valor = is_numeric($comprobante['valor'] ?? null) && (int) $comprobante['valor'] > 0
            ? ' por *$'.number_format((int) $comprobante['valor'], 0, ',', '.').'*'
            : '';
        $fecha = '';
        if (! empty($comprobante['fecha'])) {
            try {
                $fecha = ' del '.\Carbon\Carbon::parse($comprobante['fecha'])->translatedFormat('j \d\e F');
            } catch (\Throwable $e) {
                $fecha = '';
            }
        }

        dispatch(new WhatsappEscalarMultimediaJob(
            $conversacion->id,
            'image',
            "Recibí tu comprobante de pago{$valor}{$fecha}. 🙌 Nuestro equipo lo revisa y te confirma por aquí.",
            'Comprobante de pago leído por la IA: '.($resumen ?: 'sin datos legibles').' — confirmar y registrar el pago.'
        ));
    }

    private function escalar(WhatsappConversacion $conversacion, string $tipo, bool $contestaElBot): void
    {
        if ($contestaElBot) {
            dispatch(new WhatsappEscalarMultimediaJob($conversacion->id, $tipo));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Lectura de imagen fallida (mensaje {$this->mensajeId}): ".$e->getMessage());

        $mensaje = WhatsappMensaje::find($this->mensajeId);
        if ($mensaje) {
            dispatch(new WhatsappEscalarMultimediaJob($mensaje->conversacion_id, $mensaje->tipo));
        }
    }
}
