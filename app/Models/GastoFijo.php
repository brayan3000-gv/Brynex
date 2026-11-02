<?php

namespace App\Models;

/**
 * Un gasto que el aliado paga cada mes (arriendo, servicios, nómina…). El
 * estado financiero avisa cuál falta reportar en el mes: ver
 * App\Services\Caja\GastosFijos y la migración de `gastos_fijos`.
 */
class GastoFijo extends BaseModel
{
    protected $table = 'gastos_fijos';

    const FRECUENCIAS = [
        'mensual'   => 'Cada mes',
        'quincenal' => 'Cada quincena (2 al mes)',
        'bimestral' => 'Cada dos meses',
    ];

    protected $fillable = [
        'aliado_id', 'concepto', 'tipo', 'pagado_a', 'palabra_clave', 'valor', 'frecuencia',
        'dia', 'forma_pago', 'banco_origen_id', 'desde', 'activo', 'usuario_id',
    ];

    protected $casts = [
        'valor'  => 'integer',
        'dia'    => 'integer',
        'desde'  => 'date',
        'activo' => 'boolean',
    ];

    /** Cuántas veces se paga en un mes (0 en el mes que no le toca al bimestral). */
    public function vecesEnMes(int $anio, int $mes): int
    {
        if ($this->frecuencia === 'quincenal') {
            return 2;
        }
        if ($this->frecuencia === 'bimestral') {
            $base = $this->desde ?? $this->created_at;
            $meses = ($anio * 12 + $mes) - ($base->year * 12 + $base->month);

            return $meses % 2 === 0 ? 1 : 0;
        }

        return 1;
    }
}
