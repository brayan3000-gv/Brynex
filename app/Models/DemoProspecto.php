<?php

namespace App\Models;

/**
 * Alguien que entró a la demo pública. Ver la migración create_demo_prospectos
 * y App\Services\Demo\RelevoDemo.
 */
class DemoProspecto extends BaseModel
{
    protected $table = 'demo_prospectos';

    protected $fillable = [
        'aliado_id', 'nombre', 'empresa', 'celular', 'codigo', 'acepto_contacto',
        'telefono_whatsapp', 'nombre_whatsapp', 'verificado_at', 'envios',
        'ultimo_envio_at', 'ultimo_uso_at', 'ip', 'actividad',
    ];

    protected $casts = [
        'acepto_contacto' => 'boolean',
        'verificado_at' => 'datetime',
        'ultimo_envio_at' => 'datetime',
        'ultimo_uso_at' => 'datetime',
        'envios' => 'integer',
        'actividad' => 'array',
    ];

    /**
     * Puede recibir un envío de verdad: verificó por WhatsApp, le quedan envíos
     * y su último mensaje fue hace menos de 24 h (fuera de esa ventana Meta no
     * deja mandar mensajes libres, solo plantillas).
     */
    public function puedeRecibir(int $maximo): bool
    {
        return $this->verificado_at
            && $this->telefono_whatsapp
            && $this->envios < $maximo
            && $this->verificado_at->gt(now()->subHours(23));
    }
}
