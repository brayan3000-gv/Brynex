<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centro de trabajo de una razón social en ARL Sura.
 *
 * Es un caché de lo que responde el portal: no se digita, se trae con
 * `arl:sincronizar-centros`. Cada razón social nombró los suyos a su manera
 * (BRYGAR usa 000RIESGO1 / 000RIESGO3 / 0000000001), así que el único camino
 * confiable de `contratos.n_arl` al `cdSucursal` que exige el afiliar es esta
 * tabla.
 */
class ArlCentroTrabajo extends BaseModel
{
    protected $table = 'arl_centros_trabajo';

    protected $fillable = [
        'aliado_id', 'razon_social_id',
        'codigo_centro', 'nombre_centro', 'nivel_riesgo', 'tasa',
        'cd_actividad', 'municipio_sura', 'departamento', 'municipio',
        'direccion', 'telefono', 'activo', 'sincronizado_at',
    ];

    protected $casts = [
        'nivel_riesgo'    => 'integer',
        'tasa'            => 'decimal:3',
        'activo'          => 'boolean',
        'sincronizado_at' => 'datetime',
    ];

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function scopeDelAliado($q, int $aliadoId)
    {
        return $q->where('aliado_id', $aliadoId);
    }

    /**
     * El centro que le corresponde a un contrato según su nivel de riesgo.
     * Devuelve null a propósito cuando no hay uno: preferimos que la afiliación
     * se detenga con un mensaje claro antes que mandar a Sura un centro que no
     * corresponde al riesgo real del trabajador.
     *
     * Los centros son de la póliza, no de la fila: si la razón social no tiene
     * los suyos —una copia prestada a otro aliado después de la última
     * sincronización—, sirven los de cualquier otra con la misma póliza.
     */
    public static function paraRiesgo(int $razonSocialId, int $nivelRiesgo): ?self
    {
        $centro = static::where('razon_social_id', $razonSocialId)
            ->where('nivel_riesgo', $nivelRiesgo)
            ->where('activo', true)
            ->orderBy('codigo_centro')
            ->first();

        if ($centro) {
            return $centro;
        }

        $poliza = RazonSocial::where('id', $razonSocialId)->value('arl_poliza');

        if (! $poliza) {
            return null;
        }

        return static::whereIn('razon_social_id', RazonSocial::where('arl_poliza', $poliza)->select('id'))
            ->where('nivel_riesgo', $nivelRiesgo)
            ->where('activo', true)
            ->orderBy('codigo_centro')
            ->first();
    }

    /** Los niveles de riesgo que tienen centro activo en una póliza. */
    public static function nivelesDePoliza(string $poliza): array
    {
        if ($poliza === '') {
            return [];
        }

        return static::whereIn('razon_social_id', RazonSocial::where('arl_poliza', $poliza)->select('id'))
            ->where('activo', true)
            ->distinct()
            ->orderBy('nivel_riesgo')
            ->pluck('nivel_riesgo')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
