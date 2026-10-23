<?php

namespace App\Models;

/**
 * Un intento de llamada de Avappi Voz (ver App\Services\Voz\LlamadasCobroVoz).
 */
class LlamadaVoz extends BaseModel
{
    protected $table = 'llamadas_voz';

    protected $guarded = ['id'];

    protected $casts = [
        'contrato_ids' => 'array',
        'factura_ids' => 'array',
        'datos' => 'array',
        'final' => 'boolean',
        'vence' => 'date',
        'fecha_promesa' => 'date',
        'proximo_intento_en' => 'datetime',
        'terminada_en' => 'datetime',
        'aviso_en' => 'datetime',
    ];

    /** Lo que se ve en Cobros por cada resultado del agente. */
    public const RESULTADOS = [
        'PROMESA_DE_PAGO' => '🤝 Promete pagar',
        'YA_PAGO' => '✅ Dice que ya pagó',
        'NO_PUEDE' => '⚠️ No puede pagar',
        'NO_CONTINUA' => '🚪 No sigue con el plan',
        'LLAMAR_DESPUES' => '🔁 Llamar después',
        'RECADO' => '📝 Dejó recado',
        'NUMERO_EQUIVOCADO' => '❌ Número equivocado',
        'QUIERE_PERSONA' => '🙋 Quiere hablar con alguien',
        'NO_CONTACTAR' => '⛔ No quiere más llamadas',
    ];

    /** Resultados que alguien del equipo tiene que atender. */
    public const PARA_EL_EQUIPO = ['YA_PAGO', 'NO_PUEDE', 'NO_CONTINUA', 'QUIERE_PERSONA'];

    /** Alguien tiene que hacer algo: verificar un pago, llamar, mandar las cuentas. */
    public function paraElEquipo(): bool
    {
        return in_array($this->resultado, self::PARA_EL_EQUIPO, true)
            || ! empty(((array) ($this->datos ?? []))['pide_cuentas']);
    }

    public function getEtiquetaAttribute(): string
    {
        if ($this->resultado) {
            return self::RESULTADOS[$this->resultado] ?? $this->resultado;
        }

        return match (true) {
            $this->tipo === 'llamada.rechazada' => '🚫 No salió',
            $this->tipo === 'llamada.cancelada' => '🚫 Cancelada',
            $this->aviso_en === null => '⏳ En cola',
            default => '📵 No contestó',
        };
    }
}
