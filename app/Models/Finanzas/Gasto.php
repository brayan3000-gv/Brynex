<?php

namespace App\Models\Finanzas;

use App\Models\User;

/**
 * App\Models\Finanzas\Gasto
 *
 * @property int $id
 * @property int $user_id
 * @property int $categoria_id
 * @property string $fecha
 * @property float $monto
 * @property string|null $descripcion
 * @property string $tipo_movimiento
 * @property bool $es_patrimonio
 * @property int|null $patrimonio_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Gasto extends BaseFinanzasModel
{
    protected $table = 'finanzas_gastos';

    protected $fillable = [
        'user_id',
        'categoria_id',
        'cuenta_id',
        'fecha',
        'monto',
        'descripcion',
        'tipo_movimiento', // gasto | prestamo | inversion
        'es_patrimonio',
        'patrimonio_id',
        'cc_trabajo_id',
        'soporte_path',
        'origen', // null = digitado en la pantalla; garvis:… = por WhatsApp
    ];

    protected $casts = [
        'user_id' => 'integer',
        'categoria_id' => 'integer',
        'monto' => 'float',
        'es_patrimonio' => 'boolean',
        'patrimonio_id' => 'integer',
    ];

    /**
     * Relación con el usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relación con la categoría
     */
    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_id');
    }

    /**
     * Cuenta / bolsillo desde donde salió (o entró) el dinero
     */
    public function cuenta()
    {
        return $this->belongsTo(Cuenta::class, 'cuenta_id');
    }

    /**
     * Relación con el patrimonio si aplica
     */
    public function patrimonio()
    {
        return $this->belongsTo(Patrimonio::class, 'patrimonio_id');
    }

    /**
     * Scope para gastos del mes y año específico
     */
    public function scopeDelMes($query, $anio, $mes)
    {
        return $query->whereYear('fecha', $anio)->whereMonth('fecha', $mes);
    }
}
