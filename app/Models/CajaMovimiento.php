<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Efectivo que se mueve entre personas o sale para el dueño: no es cobro ni
 * gasto, pero cambia lo que cada uno tiene «pendiente por pasar». Ver la
 * migración de `caja_movimientos` y CuadreDiarioController::saldoAntesDe().
 */
class CajaMovimiento extends BaseModel
{
    use SoftDeletes;

    protected $table = 'caja_movimientos';

    const TIPO_SALDO_INICIAL = 'saldo_inicial';
    const TIPO_ENTREGA       = 'entrega';
    const TIPO_RETIRO_DUENO  = 'retiro_dueno';

    const TIPOS = [
        self::TIPO_SALDO_INICIAL => 'Saldo inicial',
        self::TIPO_ENTREGA       => 'Entrega',
        self::TIPO_RETIRO_DUENO  => 'Retiro del dueño',
    ];

    protected $fillable = [
        'aliado_id', 'fecha', 'tipo', 'usuario_origen_id', 'usuario_destino_id',
        'valor', 'observacion', 'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'integer',
    ];

    public function origen()
    {
        return $this->belongsTo(User::class, 'usuario_origen_id');
    }

    public function destino()
    {
        return $this->belongsTo(User::class, 'usuario_destino_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** El saldo inicial vigente de una persona en un día: el último con fecha <= $fecha. */
    public static function saldoInicialVigente(int $aliadoId, int $usuarioId, string $fecha): ?self
    {
        return static::where('aliado_id', $aliadoId)
            ->where('tipo', self::TIPO_SALDO_INICIAL)
            ->where('usuario_destino_id', $usuarioId)
            ->where('fecha', '<=', $fecha)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();
    }
}
