<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMensaje extends BaseModel
{
    protected $table = 'whatsapp_mensajes';

    protected $fillable = [
        'conversacion_id',
        'aliado_id',
        'wa_message_id',
        'direccion',
        'tipo',
        'contenido',
        'media_url',
        'media_mime_type',
        'media_nombre',
        'media_wa_id',
        'plantilla_id',
        'plantilla_parametros',
        'estado',
        'estado_at',
        'usuario_id',
        'error_detalle',
        'es_bot',
    ];

    protected $casts = [
        'plantilla_parametros' => 'array',
        'estado_at'            => 'datetime',
        'es_bot'               => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────────

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversacion::class, 'conversacion_id');
    }

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    // withTrashed: el mensaje enviado conserva de qué plantilla salió aunque
    // esta se haya retirado del catálogo después.
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(WhatsappPlantilla::class, 'plantilla_id')->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function esEntrante(): bool
    {
        return $this->direccion === 'entrante';
    }

    public function esSaliente(): bool
    {
        return $this->direccion === 'saliente';
    }

    public function esMedia(): bool
    {
        return in_array($this->tipo, ['image', 'audio', 'document', 'video']);
    }

    public function tieneMedia(): bool
    {
        return $this->esMedia() && !empty($this->media_url);
    }

    /**
     * Ícono representativo del tipo de mensaje.
     */
    public function icono(): string
    {
        return match($this->tipo) {
            'image'    => '📷',
            'audio'    => '🎵',
            'document' => '📄',
            'video'    => '🎥',
            'template' => '📋',
            default    => '💬',
        };
    }

    /**
     * Qué quieren decir, en cristiano, los códigos con los que Meta rechaza un mensaje.
     * Solo los que de verdad salen; el resto se muestra con el título que manda Meta.
     */
    private const MOTIVOS_FALLO = [
        131026 => 'WhatsApp no pudo entregarlo: el número no tiene WhatsApp, lo tiene desactualizado o no acepta mensajes de empresas.',
        131047 => 'Pasaron más de 24 h desde su último mensaje: solo se le puede escribir con plantilla.',
        470    => 'Pasaron más de 24 h desde su último mensaje: solo se le puede escribir con plantilla.',
        131049 => 'Meta no lo entregó para no saturar a esta persona con mensajes de empresas. Se puede reintentar más adelante.',
        131048 => 'Meta frenó el envío por el límite antispam de la cuenta.',
        131056 => 'Demasiados mensajes seguidos a este mismo número: reintentar en unos minutos.',
        131021 => 'El destinatario es el mismo número que envía.',
        131042 => 'Hay un problema con el pago de la cuenta de WhatsApp en Meta.',
        131051 => 'Meta no admite ese tipo de mensaje.',
        131052 => 'Meta no pudo procesar el archivo adjunto.',
        131053 => 'Meta no pudo procesar el archivo adjunto.',
        130472 => 'Meta no lo entregó: el número hace parte de un experimento de Meta.',
        132000 => 'La plantilla se envió con un número de variables distinto al que espera.',
        132001 => 'La plantilla no existe o no está aprobada en ese idioma.',
        132012 => 'Las variables de la plantilla no tienen el formato que espera Meta.',
        132015 => 'Meta pausó la plantilla por baja calidad.',
        132016 => 'Meta desactivó la plantilla por baja calidad.',
        133010 => 'El número de la cuenta no está registrado en Meta.',
        131000 => 'Error interno de Meta: reintentar.',
    ];

    /**
     * Lo que se guarda en `error_detalle` cuando Meta reporta un mensaje como fallido:
     * el primer error del webhook, compacto, con el código para poder traducirlo.
     */
    public static function detalleDeErrorMeta(?array $errors): ?string
    {
        $e = $errors[0] ?? null;
        if (! is_array($e)) {
            return null;
        }

        return json_encode([
            'code'    => $e['code'] ?? null,
            'title'   => $e['title'] ?? ($e['message'] ?? null),
            'detalle' => $e['error_data']['details'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Por qué no se entregó, para mostrarlo en el chat. Null si el mensaje no falló o
     * si Meta no dio el motivo (los fallidos anteriores a oct-2026 no lo guardaban).
     */
    public function motivoFallo(): ?string
    {
        if ($this->estado !== 'fallido' || ! $this->error_detalle) {
            return null;
        }

        $e = json_decode((string) $this->error_detalle, true);
        if (! is_array($e)) {
            return (string) $this->error_detalle;
        }

        $codigo = (int) ($e['code'] ?? 0);

        return self::MOTIVOS_FALLO[$codigo]
            ?? trim(($e['title'] ?? 'Meta rechazó el mensaje').($e['detalle'] ? ': '.$e['detalle'] : '').($codigo ? " (código {$codigo})" : ''));
    }

    /**
     * Ícono del estado de entrega para mensajes salientes.
     */
    public function iconoEstado(): string
    {
        return match($this->estado) {
            'enviado'    => '✓',
            'entregado'  => '✓✓',
            'leido'      => '🔵',
            'fallido'    => '❌',
            default      => '',
        };
    }

    /**
     * URL pública para acceder al media a través del controller.
     * El media se sirve desde el controlador para mantener el aislamiento.
     */
    public function urlMedia(): ?string
    {
        if (!$this->media_url) return null;
        return route('admin.whatsapp.chat.media', ['mensajeId' => $this->id]);
    }

    /**
     * Verifica si el archivo de media existe en disco.
     */
    public function mediaExiste(): bool
    {
        if (!$this->media_url) return false;
        return \Illuminate\Support\Facades\Storage::disk('local')->exists($this->media_url);
    }
}
