<?php

namespace App\Models;

/**
 * Un trabajador dentro de una cotización de empresa: su cargo, el plan que se
 * le cotizó y el resultado (mes completo y primer mes proporcional).
 */
class CotizacionTrabajador extends BaseModel
{
    protected $table = 'cotizacion_trabajadores';

    protected $fillable = [
        'aliado_id',
        'cotizacion_id',
        'orden',
        'cargo',
        'nombre',
        'modalidad_id',
        'plan_id',
        'salario',
        'n_arl',
        'resultado',
    ];

    protected $casts = [
        'resultado' => 'array',
        'salario' => 'integer',
        'n_arl' => 'integer',
        'orden' => 'integer',
    ];

    public function cotizacion()
    {
        return $this->belongsTo(CotizacionProspecto::class, 'cotizacion_id');
    }

    public function modalidad()
    {
        return $this->belongsTo(TipoModalidad::class, 'modalidad_id');
    }

    public function plan()
    {
        return $this->belongsTo(PlanContrato::class, 'plan_id');
    }

    public function getValorMensualAttribute(): float
    {
        $completo = (float) ($this->resultado['completo']['total'] ?? 0);

        return $completo > 0 ? $completo : (float) ($this->resultado['proporcional']['total'] ?? 0);
    }
}
