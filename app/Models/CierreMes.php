<?php

namespace App\Models;

/** El cierre de un mes de un aliado. Ver la migración de `cierres_mes`. */
class CierreMes extends BaseModel
{
    protected $table = 'cierres_mes';

    protected $fillable = [
        'aliado_id', 'anio', 'mes', 'foto', 'foto_at', 'saldos_reales', 'saldos_por', 'saldos_at',
        'es_apertura', 'utilidad_acumulada',
    ];

    protected $casts = [
        'foto'               => 'array',
        'saldos_reales'      => 'array',
        'foto_at'            => 'datetime',
        'saldos_at'          => 'datetime',
        'es_apertura'        => 'boolean',
        'utilidad_acumulada' => 'integer',
    ];

    public static function de(int $aliadoId, int $anio, int $mes): ?self
    {
        return static::where('aliado_id', $aliadoId)->where('anio', $anio)->where('mes', $mes)->first();
    }

    /** El cierre del mes anterior que tenga foto. */
    public static function anteriorConFoto(int $aliadoId, int $anio, int $mes): ?self
    {
        $ant = \Carbon\Carbon::createFromDate($anio, $mes, 1)->subMonth();

        return static::where('aliado_id', $aliadoId)->where('anio', $ant->year)->where('mes', $ant->month)
            ->whereNotNull('foto')->first();
    }
}
