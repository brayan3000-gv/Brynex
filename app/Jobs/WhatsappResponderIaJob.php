<?php

namespace App\Jobs;

use App\Events\WhatsappConversacionActualizada;
use App\Events\WhatsappMensajeNuevo;
use App\Models\{IaConfiguracionAliado, IaConversacion, IaMensaje, WhatsappConfig, WhatsappConversacion, WhatsappMensaje};
use App\Services\Ia\AsistenteIaService;
use App\Services\WhatsappApiService;
use App\Services\WhatsappWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Genera y envía la respuesta del Asistente IA a un mensaje entrante de WhatsApp.
 * Se ejecuta en background (igual que WhatsappDescargarMediaJob) para no bloquear
 * el webhook de Meta, que espera una respuesta rápida.
 *
 * Debounce: este job se dispara con delay (ver WhatsappWebhookService) para dar tiempo a
 * que lleguen más mensajes seguidos del mismo cliente. $tokenMensajeId es el id del mensaje
 * que originó ESTE job — si al ejecutarse ya no es el último mensaje de la conversación
 * (llegó uno más nuevo mientras esperaba), se aborta en silencio: el job programado para
 * ese mensaje más reciente es el que agrupa y responde todo el lote de una vez.
 *
 * Si la IA falla (sin crédito, error de proveedor, etc.), la conversación se escala
 * a un humano en vez de quedar en silencio — nadie debe quedar sin respuesta.
 */
class WhatsappResponderIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(protected int $conversacionId, protected int $tokenMensajeId) {}

    public function handle(AsistenteIaService $asistenteIa, WhatsappApiService $whatsappApi): void
    {
        if (Cache::get(WhatsappWebhookService::claveDebounce($this->conversacionId)) !== $this->tokenMensajeId) {
            return;
        }

        // Este job sí va a procesar el lote actual — liberar el marcador de "inicio de lote"
        // para que el próximo mensaje del cliente arranque un lote nuevo, no siga acumulando.
        Cache::forget(WhatsappWebhookService::claveInicioLote($this->conversacionId));

        $conversacion = WhatsappConversacion::find($this->conversacionId);
        if (!$conversacion || !$conversacion->bot_activo) return;

        $iaConfig = IaConfiguracionAliado::where('aliado_id', $conversacion->aliado_id)->first();
        if (!$iaConfig || !$iaConfig->activo_whatsapp) return;

        $config = WhatsappConfig::paraAliado($conversacion->aliado_id);
        if (!$config->credencialesCompletas()) return;

        $nombreBot = $iaConfig->nombreBot();

        // Agrupar todos los mensajes de texto entrantes desde la última respuesta del bot,
        // para responder de una sola vez con el contexto completo en vez de mensaje por mensaje.
        $iaConversacion  = IaConversacion::paraTelefono($conversacion->aliado_id, $conversacion->wa_contact_id);
        $ultimaRespuesta = IaMensaje::where('conversacion_id', $iaConversacion->id)
            ->where('rol', 'assistant')
            ->latest('id')
            ->first();

        // Pendientes = lo que llegó DESPUÉS del último mensaje que ya se le pasó al modelo. Antes
        // se comparaba contra la hora de la última respuesta del bot, y un mensaje que entraba
        // mientras la IA generaba (ej. «Si» y 10 s después «20») quedaba con hora anterior a esa
        // respuesta: se daba por atendido y nunca se respondió (visto el 6-oct-2026).
        $claveUltimoAtendido = "wa_ia_ultimo_atendido_{$conversacion->id}";
        $ultimoAtendidoId = (int) Cache::get($claveUltimoAtendido, 0);
        $ultimoUsuario = IaMensaje::where('conversacion_id', $iaConversacion->id)
            ->where('rol', 'user')
            ->latest('id')
            ->first();

        $mensajesPendientes = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'entrante')
            // Las notas de voz y las fotos o PDF entran cuando ya tienen texto (transcripción o
            // lectura de la IA). Hasta oct-2026 solo entraban texto y botón, y por eso ninguna nota
            // de voz transcrita le llegó nunca a la IA: el 30-sep un prospecto mandó «Hola, quiero
            // información» y 12 audios, y el bot contestó solo al saludo.
            ->whereIn('tipo', ['text', 'button', 'audio', 'image', 'document'])
            ->whereNotNull('contenido')
            ->where('contenido', '<>', '')
            ->when($ultimoAtendidoId > 0, fn ($q) => $q->where('id', '>', $ultimoAtendidoId))
            ->when($ultimoAtendidoId === 0 && $ultimoUsuario, fn ($q) => $q->where('created_at', '>', $ultimoUsuario->created_at))
            ->when($ultimoAtendidoId === 0 && ! $ultimoUsuario && $ultimaRespuesta, fn ($q) => $q->where('created_at', '>', $ultimaRespuesta->created_at))
            ->orderBy('id')
            ->get(['id', 'contenido', 'wa_message_id']);

        if ($mensajesPendientes->isEmpty()) return;

        Cache::put($claveUltimoAtendido, (int) $mensajesPendientes->last()->id, now()->addDays(30));

        $textoUsuario = $mensajesPendientes->pluck('contenido')->implode("\n");
        $ultimoWaMessageId = $mensajesPendientes->last()->wa_message_id;

        try {
            $resultado = $asistenteIa->responderWhatsapp(
                $conversacion->aliado_id,
                $conversacion->wa_contact_id,
                $textoUsuario,
                $conversacion->id,
                $conversacion->origen_campana,
                $conversacion->origen_campana_categoria,
                $conversacion->origen_campana_id
            );
        } catch (\Exception $e) {
            // error, no warning: en producción LOG_LEVEL=error, así que con warning esto
            // quedaba invisible — cada vez que un cliente real recibía el mensaje genérico
            // había que reproducir la llamada a mano por SSH para ver la excepción real.
            Log::error('IA WhatsApp: no se pudo generar respuesta, se escala a un humano', [
                'error' => $e->getMessage(),
                'conversacion_id' => $conversacion->id,
            ]);

            $conversacion->escalarAHumano('El asistente tuvo un problema técnico y no pudo responder.');
            $this->enviarYRegistrar(
                $conversacion,
                $config,
                $whatsappApi,
                $nombreBot,
                'Disculpa, estoy teniendo un inconveniente técnico. Ya avisé a nuestro equipo para que te '
                . 'contacte en breve. 🙏'
            );
            return;
        }

        // La conversación pudo haber sido tomada por un humano MIENTRAS se generaba la
        // respuesta — en ese caso sí hay que callar. Pero si fue la IA la que apagó
        // bot_activo en este mismo turno (hablar_con_asesor), su propio mensaje de
        // despedida SÍ debe salir: es la confirmación de que ya está gestionando el
        // traslado a un asesor, no una respuesta huérfana después de que alguien más tomó la conversación.
        $escalonPorEstaMismaIa = in_array('hablar_con_asesor', $resultado['herramientas_usadas'] ?? [], true);
        if (!$escalonPorEstaMismaIa && !$conversacion->fresh()->bot_activo) return;

        $this->enviarYRegistrar(
            $conversacion,
            $config,
            $whatsappApi,
            $nombreBot,
            $resultado['respuesta'],
            $ultimoWaMessageId
        );
    }

    /**
     * Envía la respuesta de la IA como una o varias burbujas (separadas por "|||" en el texto
     * generado) y registra cada una como mensaje saliente. Entre burbujas simula "escribiendo…"
     * con marcarLeidoYEscribiendo() y una pausa corta, para que se sienta como una persona
     * escribiendo varios mensajes seguidos en vez de un párrafo largo. No relanza si falla un envío.
     */
    private function enviarYRegistrar(
        WhatsappConversacion $conversacion,
        WhatsappConfig $config,
        WhatsappApiService $whatsappApi,
        string $nombreBot,
        string $texto,
        ?string $ultimoWaMessageId = null
    ): void {
        $burbujas = array_values(array_filter(array_map('trim', explode('|||', $texto)), fn ($b) => $b !== ''));
        if (empty($burbujas)) {
            $burbujas = [$texto];
        }
        // Tope de seguridad: si la IA se pasó de 3 burbujas, se fusiona el resto en la última.
        if (count($burbujas) > 3) {
            $burbujas = array_merge(array_slice($burbujas, 0, 2), [implode(' ', array_slice($burbujas, 2))]);
        }

        foreach ($burbujas as $indice => $burbuja) {
            if ($indice > 0) {
                if ($ultimoWaMessageId) {
                    $whatsappApi->marcarLeidoYEscribiendo($ultimoWaMessageId, $config);
                }
                usleep(random_int(1200, 2500) * 1000);
            }

            $textoFirmado = $indice === 0 ? "🤖 *{$nombreBot}:*\n" . $burbuja : $burbuja;

            $envio = $whatsappApi->enviarTexto($conversacion->wa_contact_id, $textoFirmado, $config);
            if (!$envio['ok']) {
                Log::error('IA WhatsApp: fallo al enviar mensaje', [
                    'error' => $envio['error'] ?? null,
                    'conversacion_id' => $conversacion->id,
                ]);
                continue;
            }

            $mensajeBot = WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id'       => $conversacion->aliado_id,
                'wa_message_id'   => $envio['wa_message_id'],
                'direccion'       => 'saliente',
                'tipo'            => 'text',
                'contenido'       => $textoFirmado,
                'estado'          => 'enviado',
                'es_bot'          => true,
            ]);

            $conversacion->update(['ultimo_mensaje_at' => now()]);

            broadcast(new WhatsappMensajeNuevo($mensajeBot, $conversacion));
            broadcast(new WhatsappConversacionActualizada($conversacion));
        }
    }
}
