<?php

namespace App\Jobs;

use App\Events\WhatsappConversacionActualizada;
use App\Events\WhatsappMensajeNuevo;
use App\Models\{WhatsappConfig, WhatsappConversacion, WhatsappMensaje};
use App\Services\WhatsappApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Un archivo que la IA no pudo leer (o un comprobante ya leído): en vez de quedarse en
 * silencio, se avisa al cliente y se escala la conversación a un humano. Desde oct-2026 las
 * fotos y los PDF se leen primero (WhatsappLeerImagenJob) y este job queda para lo que no
 * se pudo leer y para los comprobantes; validar un pago sigue siendo de una persona.
 */
class WhatsappEscalarMultimediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    private const MENSAJES = [
        'image'    => 'Recibí tu imagen. Si es un comprobante de pago, ya avisé a nuestro equipo para que lo revise y te confirme. En breve te contactamos. 🙏',
        'document' => 'Recibí tu documento. Ya avisé a nuestro equipo para que lo revise. En breve te contactamos. 🙏',
        'video'    => 'Recibí tu video. Ya avisé a nuestro equipo para que lo revise. En breve te contactamos. 🙏',
        'audio'    => 'Recibí tu audio. Por ahora no puedo escuchar notas de voz, pero ya avisé a nuestro equipo para que te responda pronto. 🙏',
    ];

    private const MOTIVOS = [
        'image'    => 'Envió una imagen (posible comprobante de pago) — revisar adjunto.',
        'document' => 'Envió un documento — revisar adjunto.',
        'video'    => 'Envió un video — revisar adjunto.',
        'audio'    => 'Envió un audio — revisar adjunto.',
    ];

    /**
     * $texto y $motivo reemplazan los fijos cuando ya se sabe qué es el archivo: un comprobante
     * leído por la IA llega con el valor y la fecha (ver WhatsappLeerImagenJob).
     */
    public function __construct(
        protected int $conversacionId,
        protected string $tipo,
        protected ?string $texto = null,
        protected ?string $motivo = null,
    ) {}

    public function handle(WhatsappApiService $whatsappApi): void
    {
        $conversacion = WhatsappConversacion::find($this->conversacionId);
        if (!$conversacion || !$conversacion->bot_activo) return;

        $config = WhatsappConfig::paraAliado($conversacion->aliado_id);
        if (!$config->credencialesCompletas()) return;

        $conversacion->escalarAHumano(mb_substr($this->motivo ?? self::MOTIVOS[$this->tipo] ?? 'Envió un archivo que la IA no puede leer.', 0, 255));

        $iaConfig = \App\Models\IaConfiguracionAliado::where('aliado_id', $conversacion->aliado_id)->first();
        $nombreBot = $iaConfig?->nombreBot() ?? 'Asistente Virtual';

        $texto = $this->texto ?? self::MENSAJES[$this->tipo] ?? 'Recibí tu archivo. Ya avisé a nuestro equipo para que te contacte. 🙏';
        $textoFirmado = "🤖 *{$nombreBot}:*\n" . $texto;

        $envio = $whatsappApi->enviarTexto($conversacion->wa_contact_id, $textoFirmado, $config);
        if (!$envio['ok']) {
            Log::warning('IA WhatsApp: fallo al enviar acuse de multimedia', [
                'error' => $envio['error'] ?? null,
                'conversacion_id' => $conversacion->id,
            ]);
            return;
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
