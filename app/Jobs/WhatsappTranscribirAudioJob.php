<?php

namespace App\Jobs;

use App\Models\IaConfiguracionAliado;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Ia\TranscripcionAudioService;
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
 * Pasa a texto una nota de voz y devuelve la conversación al flujo normal de la IA.
 *
 * Antes, todo audio escalaba a un humano con un "no puedo escuchar notas de voz" y la
 * conversación se quedaba ahí. Quien contesta un anuncio desde el celular manda audio: el
 * primer asesor que llegó por la pieza #90 respondió con una nota y pasó un día sin respuesta.
 *
 * Corre aparte de la descarga en vez de dentro de ella porque son dos cosas que fallan por
 * motivos distintos —Meta puede tardar en servir el archivo, Gemini puede no entender el
 * audio— y mezclarlas haría que un fallo de transcripción reintentara también la descarga.
 * Si al final no se puede transcribir, se cae al aviso de siempre: nunca se deja al cliente
 * sin respuesta.
 */
class WhatsappTranscribirAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 150;

    /** Espera a que el job de descarga deje el archivo en disco. */
    public function backoff(): array
    {
        return [5, 10, 20, 30, 60];
    }

    public function __construct(protected int $mensajeId) {}

    public function handle(): void
    {
        $mensaje = WhatsappMensaje::find($this->mensajeId);
        if (! $mensaje) {
            return;
        }

        $conversacion = WhatsappConversacion::find($mensaje->conversacion_id);
        if (! $conversacion) {
            return;
        }

        // Se transcribe aunque la conversación ya la lleve una persona: el texto sirve igual en
        // el inbox y en el aviso de pendientes, donde antes solo se leía "[nota de voz]" y tocaba
        // escucharla una por una para saber qué pedían. Lo que sí depende del bot es responder.
        $contestaElBot = (bool) $conversacion->bot_activo;

        // El archivo lo baja WhatsappDescargarMediaJob. Si todavía no llegó, se reintenta:
        // el release cuenta como intento, así que tras agotarlos se escala igual.
        if (! $mensaje->mediaExiste()) {
            if ($this->attempts() >= $this->tries) {
                if ($contestaElBot) {
                    $this->escalar($conversacion);
                }

                return;
            }
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);

            return;
        }

        $apiKey = IaConfiguracionAliado::paraAliado($conversacion->aliado_id)->gemini_api_key;
        if (! $apiKey) {
            if ($contestaElBot) {
                $this->escalar($conversacion);
            }

            return;
        }

        $r = TranscripcionAudioService::transcribir(
            $apiKey,
            Storage::disk('local')->path($mensaje->media_url),
            $mensaje->media_mime_type
        );
        TranscripcionAudioService::registrarConsumo($conversacion->aliado_id, 'whatsapp_audio', $r);

        if (! $r['ok']) {
            Log::warning("No se pudo transcribir el audio del mensaje {$mensaje->id}: {$r['error']}");
            if ($contestaElBot) {
                $this->escalar($conversacion);
            }

            return;
        }

        // El texto queda como contenido del mensaje: así lo lee la IA y también se ve en el
        // inbox, junto al audio original, que no se toca.
        $mensaje->update(['contenido' => $r['texto']]);

        if (! $contestaElBot) {
            return; // la lleva una persona: el texto ya quedó, no hay que responder nada
        }

        // Si mientras se transcribía llegó otro mensaje del cliente, ese ya programó su propia
        // respuesta y va a leer el historial completo —con esta transcripción ya guardada—.
        // Despachar aquí solo adelantaría una respuesta a medias.
        $hayMasNuevo = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'entrante')
            ->where('id', '>', $mensaje->id)
            ->exists();

        if ($hayMasNuevo) {
            return;
        }

        // WhatsappResponderIaJob se aborta si este id no es el token de debounce vigente, así
        // que hay que dejarlo puesto: sin esto la respuesta nunca sale y el audio seguiría
        // quedándose sin contestar, que es justo lo que se está arreglando.
        Cache::put(
            WhatsappWebhookService::claveDebounce($conversacion->id),
            $mensaje->id,
            now()->addSeconds(30)
        );

        WhatsappResponderIaJob::dispatch($conversacion->id, $mensaje->id);
    }

    /** Sin transcripción, el comportamiento de antes: avisar y pasar a un humano. */
    private function escalar(WhatsappConversacion $conversacion): void
    {
        dispatch(new WhatsappEscalarMultimediaJob($conversacion->id, 'audio'));
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Transcripción de audio fallida (mensaje {$this->mensajeId}): ".$e->getMessage());

        $mensaje = WhatsappMensaje::find($this->mensajeId);
        if ($mensaje) {
            dispatch(new WhatsappEscalarMultimediaJob($mensaje->conversacion_id, 'audio'));
        }
    }
}
