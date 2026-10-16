<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que una planilla le descontó a una ODI del aporte a la ARL.
 *
 * origen: manual (se digitó en el operador y se registró aquí) | api (lo metió
 * BryNex al liquidar) | correccion (cargado después, al cuadrar lo ya pagado).
 */
class OdiDescuento extends BaseModel
{
    protected $table = 'odi_descuentos';

    protected $fillable = [
        'aliado_id',
        'odi_id',
        'numero_planilla',
        'periodo',
        'valor',
        'fecha',
        'origen',
        'observacion',
        'usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    public function odi(): BelongsTo
    {
        return $this->belongsTo(OdiArl::class, 'odi_id');
    }
}
