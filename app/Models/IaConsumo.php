<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IaConsumo extends BaseModel
{
    protected $table = 'ia_consumo';

    protected $fillable = [
        'aliado_id', 'canal', 'conversacion_id', 'proveedor', 'modelo',
        'tokens_entrada', 'tokens_salida', 'tokens_cache_lectura', 'tokens_cache_escritura',
        'latencia_ms', 'costo_estimado_usd',
    ];

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }
}
