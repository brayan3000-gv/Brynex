<?php

namespace App\Jobs;

use App\Events\WhatsappConversacionActualizada;
use App\Events\WhatsappMensajeNuevo;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Finanzas\TelefonosDeudores;
use App\Services\WhatsappApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Acuse de recibo para los aliados que no tienen IA en WhatsApp.
 *
 * Se programa con unos minutos de retraso cada vez que un cliente escribe. Al correr,
 * mira si alguien ya le contestó; si no, le manda "recibimos tu mensaje, en breve te
 * atendemos" y deja la conversación pendiente por atender, para que salga en la pestaña
 * «Esperando» y en el aviso. Si una persona ya estaba chateando con él, el job no hace
 * nada: un acuse automático en mitad de una conversación viva sería un estorbo.
 *
 * Un solo acuse por «episodio» de espera: si el cliente manda cinco mensajes seguidos, o
 * vuelve a escribir al rato, no se le repite. Vuelve a aplicar cuando ya pasaron
 * HORAS_ENTRE_ACUSES desde el último.
 *
 * Sale como texto libre: el cliente acaba de escribir, así que la ventana está abierta
 * y no cuesta.
 */
class WhatsappAcuseRecepcionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    /** Minutos que se le dan a una persona para contestar antes de mandar el acuse. */
    public const MINUTOS_ESPERA = 5;

    /** No se repite el acuse al mismo contacto antes de este tiempo. */
    public const HORAS_ENTRE_ACUSES = 12;

    private const FIRMA = '🤖 *Respuesta automática:*';

    private const TEXTOS = [
        'text' => 'Hola 👋 Recibimos tu mensaje. En breve una persona de nuestro equipo te responde.',
        'button' => 'Hola 👋 Recibimos tu respuesta. En breve una persona de nuestro equipo te atiende.',
        'image' => 'Recibimos tu imagen 📎. Si es un comprobante de pago, lo revisamos y te confirmamos en breve.',
        'document' => 'Recibimos tu documento 📎. Lo revisamos y te respondemos en breve.',
        'video' => 'Recibimos tu video 📎. Lo revisamos y te respondemos en breve.',
        'audio' => 'Recibimos tu nota de voz 🎙️. En breve una persona de nuestro equipo te responde.',
    ];

    public function __construct(protected int $conversacionId, protected int $mensajeId) {}

    /**
     * ¿Tiene sentido programar un acuse para este contacto en este aliado?
     *
     * Se decide en el webhook, antes de encolar nada: el dueño y los deudores de sus
     * préstamos no son clientes del aliado (ya le llegan reenviados), y hay un
     * interruptor por aliado en config para apagarlo sin tocar código.
     */
    public static function aplicaA(int $aliadoId, string $waFrom): bool
    {
        if (! config('services.whatsapp.acuse_sin_bot', true)) {
            return false;
        }

        $excluidos = array_filter(array_map('intval', explode(',', (string) config('services.whatsapp.acuse_sin_bot_excluir', ''))));
        if (in_array($aliadoId, $excluidos, true)) {
            return false;
        }

        $tel = preg_replace('/\D/', '', $waFrom);
        $dueno = preg_replace('/\D/', '', (string) config('finanzas.whatsapp_personal_dueno'));

        return $tel !== $dueno && ! TelefonosDeudores::esDeudor($waFrom);
    }

    public function handle(WhatsappApiService $whatsappApi): void
    {
        $conversacion = WhatsappConversacion::find($this->conversacionId);
        if (! $conversacion || $conversacion->estado === 'cerrada') {
            return;
        }

        $mensaje = WhatsappMensaje::find($this->mensajeId);
        if (! $mensaje) {
            return;
        }

        // Alguien (persona, bot o plantilla) ya le escribió después de este mensaje.
        $yaRespondido = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'saliente')
            ->where('id', '>', $this->mensajeId)
            ->exists();
        if ($yaRespondido) {
            return;
        }

        // Ya se le mandó un acuse hace poco: con uno basta.
        $acuseReciente = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'saliente')
            ->where('es_bot', true)
            ->where('contenido', 'like', self::FIRMA.'%')
            ->where('created_at', '>=', now()->subHours(self::HORAS_ENTRE_ACUSES))
            ->exists();

        $motivo = 'Escribió y nadie le ha respondido.'
            .(in_array($mensaje->tipo, ['image', 'document', 'video'], true) ? ' Envió un archivo (posible comprobante de pago): revisar adjunto.' : '');

        if (! $conversacion->pendiente_atencion) {
            $conversacion->marcarPendiente($motivo);
        }

        if ($acuseReciente) {
            broadcast(new WhatsappConversacionActualizada($conversacion));

            return;
        }

        $config = WhatsappConfig::paraAliado($conversacion->aliado_id);
        if (! $config->credencialesCompletas()) {
            return;
        }

        $texto = self::FIRMA."\n".(self::TEXTOS[$mensaje->tipo] ?? self::TEXTOS['text']);

        $envio = $whatsappApi->enviarTexto($conversacion->wa_contact_id, $texto, $config);
        if (! ($envio['ok'] ?? false)) {
            Log::warning('WhatsApp: no salió el acuse automático', [
                'error' => $envio['error'] ?? null,
                'conversacion_id' => $conversacion->id,
            ]);

            return;
        }

        $mensajeBot = WhatsappMensaje::create([
            'conversacion_id' => $conversacion->id,
            'aliado_id' => $conversacion->aliado_id,
            'wa_message_id' => $envio['wa_message_id'],
            'direccion' => 'saliente',
            'tipo' => 'text',
            'contenido' => $texto,
            'estado' => 'enviado',
            'es_bot' => true,
        ]);

        $conversacion->update(['ultimo_mensaje_at' => now()]);

        broadcast(new WhatsappMensajeNuevo($mensajeBot, $conversacion));
        broadcast(new WhatsappConversacionActualizada($conversacion));
    }
}
