<?php

namespace App\Events;

use App\Models\{WhatsappConversacion, WhatsappMensaje};
use Illuminate\Broadcasting\{Channel, InteractsWithSockets, PrivateChannel};
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de broadcasting para notificar en tiempo real (Reverb)
 * cuando llega un mensaje nuevo en una conversación de WhatsApp.
 *
 * Canal privado: whatsapp-aliado.{aliado_id}
 * Solo los usuarios autenticados del aliado pueden suscribirse.
 */
class WhatsappMensajeNuevo implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;

    public function __construct(
        protected WhatsappMensaje       $mensaje,
        protected WhatsappConversacion  $conversacion
    ) {
        $this->payload = [
            'conversacion_id'   => $conversacion->id,
            'mensaje_id'        => $mensaje->id,
            'direccion'         => $mensaje->direccion,
            'tipo'              => $mensaje->tipo,
            'contenido'         => $mensaje->contenido,
            'media_url'         => $mensaje->media_url ? route('admin.whatsapp.chat.media', $mensaje->id) : null,
            'media_mime_type'   => $mensaje->media_mime_type,
            'media_nombre'      => $mensaje->media_nombre,
            'estado'            => $mensaje->estado,
            'usuario_nombre'    => $mensaje->usuario?->nombre,
            // Para que la vista no repita lo que envió el mismo usuario (eso ya lo pinta al enviar)
            'usuario_id'        => $mensaje->usuario_id,
            'es_bot'            => (bool) $mensaje->es_bot,
            'timestamp'         => $mensaje->created_at?->toIso8601String(),
            'preview'           => $conversacion->previewUltimoMensaje(),
            'no_leidos'         => $conversacion->total_mensajes_no_leidos,
        ];
    }

    /**
     * Canal privado por aliado — solo los usuarios del aliado se suscriben.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('whatsapp-aliado.' . $this->conversacion->aliado_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mensaje.nuevo';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
