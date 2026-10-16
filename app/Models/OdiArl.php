<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Orden de Descuento de Incapacidad (ODI) que expide la ARL a una razón social.
 *
 * No es plata que entre al banco: se descuenta del aporte a la ARL en las
 * planillas siguientes, hasta el 99 % de ese aporte por planilla (el 1 % es del
 * Fondo de Riesgos Laborales). Lo que no alcance queda para el mes siguiente.
 *
 * total = valor_incapacidades (renglón 32) + valor_otros_riesgos (renglón 34,
 * la salud y pensión que la empresa pagó durante la incapacidad).
 *
 * `descontado` es la suma de `odi_descuentos`; se guarda para leer el saldo sin
 * sumar, y se recalcula con recalcularDescontado() cada vez que cambia.
 */
class OdiArl extends BaseModel
{
    protected $table = 'odis_arl';

    /** Parte del aporte ARL de una planilla que se puede descontar. */
    public const TOPE_APORTE_ARL = 0.99;

    protected $fillable = [
        'aliado_id',
        'razon_social_id',
        'arl_id',
        'numero',
        'fecha',
        'valor_incapacidades',
        'valor_otros_riesgos',
        'total',
        'descontado',
        'imagen_path',
        'observacion',
        'usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor_incapacidades' => 'decimal:2',
        'valor_otros_riesgos' => 'decimal:2',
        'total' => 'decimal:2',
        'descontado' => 'decimal:2',
    ];

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function descuentos(): HasMany
    {
        return $this->hasMany(OdiDescuento::class, 'odi_id');
    }

    /** Las entradas de incapacidad que cubre esta orden. */
    public function entradas(): HasMany
    {
        return $this->hasMany(AbonoIncapacidad::class, 'odi_id');
    }

    public function getSaldoAttribute(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->descontado, 2));
    }

    public function recalcularDescontado(): void
    {
        $this->descontado = (float) $this->descuentos()->sum('valor');
        $this->saveQuietly();
    }

    /**
     * Suma una incapacidad a la ODI con ese número, o la crea.
     *
     * Una ODI suele cubrir varias incapacidades y cada una se registra en su
     * propia gestión. Las cotizaciones a otros riesgos son de la orden entera
     * (renglón 34 del PDF): se toman la primera vez que llegan y no se suman
     * de nuevo, para no contarlas dos veces.
     */
    public static function registrarEntrada(
        int $aliadoId,
        int $razonSocialId,
        ?int $arlId,
        string $numero,
        string $fecha,
        float $valorIncapacidad,
        float $otrosRiesgos,
        ?int $usuarioId
    ): self {
        $odi = static::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $razonSocialId)
            ->where('numero', $numero)
            ->lockForUpdate()
            ->first();

        if (! $odi) {
            $odi = new static([
                'aliado_id' => $aliadoId,
                'razon_social_id' => $razonSocialId,
                'arl_id' => $arlId,
                'numero' => $numero,
                'fecha' => $fecha,
                'valor_incapacidades' => 0,
                'valor_otros_riesgos' => 0,
                'descontado' => 0,
                'usuario_id' => $usuarioId,
            ]);
        }

        $odi->valor_incapacidades = (float) $odi->valor_incapacidades + $valorIncapacidad;
        if ($otrosRiesgos > 0 && (float) $odi->valor_otros_riesgos <= 0) {
            $odi->valor_otros_riesgos = $otrosRiesgos;
        }
        $odi->total = (float) $odi->valor_incapacidades + (float) $odi->valor_otros_riesgos;
        $odi->save();

        return $odi;
    }

    /**
     * Resta de sus ODI las entradas que se van a borrar (reversión de un pago a
     * la razón social). Una ODI que se queda sin incapacidades y sin descuentos
     * se borra: fue un registro equivocado, no una orden.
     *
     * @param  int[]  $abonoIds
     */
    public static function quitarEntradas(array $abonoIds): void
    {
        $entradas = DB::table('abonos_incapacidades')
            ->whereIn('id', $abonoIds)
            ->whereNotNull('odi_id')
            ->get(['id', 'odi_id', 'valor']);

        foreach ($entradas->groupBy('odi_id') as $odiId => $grupo) {
            $odi = static::find($odiId);
            if (! $odi) {
                continue;
            }

            $odi->valor_incapacidades = max(0, (float) $odi->valor_incapacidades - (float) $grupo->sum('valor'));
            $quedan = DB::table('abonos_incapacidades')
                ->where('odi_id', $odiId)
                ->whereNotIn('id', $grupo->pluck('id'))
                ->exists();

            DB::table('abonos_incapacidades')->whereIn('id', $grupo->pluck('id'))->update(['odi_id' => null]);

            if (! $quedan && ! $odi->descuentos()->exists()) {
                $odi->delete();

                continue;
            }

            $odi->total = (float) $odi->valor_incapacidades + (float) $odi->valor_otros_riesgos;
            $odi->save();
        }
    }

    /** ODI con saldo de una razón social, de la más vieja a la más nueva. */
    public static function conSaldo(int $aliadoId, int $razonSocialId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $razonSocialId)
            ->whereColumn('descontado', '<', 'total')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();
    }
}
