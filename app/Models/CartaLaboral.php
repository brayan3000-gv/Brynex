<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una carta laboral generada a nombre de una razón social (módulo Cartas
 * laborales). `datos` es el contenido ya resuelto: volver a descargarla da el
 * mismo texto aunque el contrato haya cambiado. Ver CartaLaboralService.
 */
class CartaLaboral extends BaseModel
{
    protected $table = 'cartas_laborales';

    protected $fillable = [
        'aliado_id', 'contrato_id', 'razon_social_id', 'cedula', 'destino',
        'firmante_tipo', 'firmante_user_id', 'firmante_nombre', 'firmante_cargo',
        'datos', 'user_id',
    ];

    protected $casts = [
        'datos' => 'array',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
