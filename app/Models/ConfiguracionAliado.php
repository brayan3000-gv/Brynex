<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracionAliado extends BaseModel
{
    protected $table = 'configuracion_aliado';
    protected $fillable = [
        'aliado_id', 'plan_id',
        'administracion', 'costo_afiliacion', 'admon_asesor',
        'promocion_costo_afiliacion', 'promocion_vencimiento',
        'seguro_valor', 'seguro_logo', 'encargado_default_id', 'activo',
        'dist_admon_pct', 'dist_retiro_pct', 'dia_ingreso_ir',
        'mora_dia_habil_inicio', 'mora_minimo', 'mora_segundo',
        'marketing_max_campanas', 'marketing_dias_periodo',
        'ingreso_retiro_valor_mensual', 'tiempo_parcial_costo_afiliacion', 'arl_descuento_porcentaje',
        'prospectos_recordatorio', 'prospectos_recordatorio_celular',
        'prospectos_dias_sin_respuesta', 'prospectos_dias_cierre',
    ];
    protected $casts = [
        'administracion'             => 'decimal:2',
        'costo_afiliacion'           => 'decimal:2',
        'admon_asesor'               => 'decimal:2',
        'promocion_costo_afiliacion' => 'decimal:2',
        'promocion_vencimiento'      => 'date',
        'seguro_valor'               => 'decimal:2',
        'dist_admon_pct'             => 'decimal:2',
        'dist_retiro_pct'            => 'decimal:2',
        'dia_ingreso_ir'             => 'integer',
        'activo'                     => 'boolean',
        'ingreso_retiro_valor_mensual'    => 'decimal:2',
        'tiempo_parcial_costo_afiliacion' => 'decimal:2',
        'arl_descuento_porcentaje'        => 'integer',
        'prospectos_recordatorio'         => 'boolean',
        'prospectos_dias_sin_respuesta'   => 'integer',
        'prospectos_dias_cierre'          => 'integer',
    ];

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanContrato::class, 'plan_id');
    }

    public function encargadoDefault(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_default_id');
    }

    /** ¿Hay una promoción de costo de afiliación activa (con precio y sin vencer) en esta fila? */
    public function promocionVigente(): bool
    {
        return $this->promocion_costo_afiliacion !== null
            && $this->promocion_vencimiento !== null
            && $this->promocion_vencimiento->greaterThanOrEqualTo(now()->startOfDay());
    }

    private static array $cacheParaAliado = [];

    /**
     * Obtiene la configuración del aliado para un plan dado.
     * Si no existe configuración específica para el plan, devuelve la genérica (plan_id null).
     */
    public static function paraAliado(int $alidoId, ?int $planId = null): ?static
    {
        $key = "{$alidoId}_" . ($planId ?? 'null');
        if (!array_key_exists($key, self::$cacheParaAliado)) {
            $cfg = null;
            // Primero buscar específica por plan
            if ($planId) {
                $cfg = static::where('aliado_id', $alidoId)
                    ->where('plan_id', $planId)
                    ->where('activo', true)
                    ->first();
            }

            if (!$cfg) {
                // Luego la genérica sin plan
                $cfg = static::where('aliado_id', $alidoId)
                    ->whereNull('plan_id')
                    ->where('activo', true)
                    ->first();
            }
            self::$cacheParaAliado[$key] = $cfg;
        }
        return self::$cacheParaAliado[$key];
    }

    /**
     * Calcula la distribución del costo de afiliación.
     * Retorna [admon, asesor, retiro, utilidad] en pesos.
     */
    public function calcularDistribucion(int $totalAfil, ?Asesor $asesor = null): array
    {
        $admon  = (int) round($totalAfil * (float)($this->dist_admon_pct  ?? 0) / 100);
        $retiro = (int) round($totalAfil * (float)($this->dist_retiro_pct ?? 0) / 100);

        // Comisión del asesor
        $asesorVal = 0;
        if ($asesor) {
            if ($asesor->comision_afil_tipo === 'porcentaje') {
                $asesorVal = (int) round($totalAfil * (float)$asesor->comision_afil_valor / 100);
            } else {
                $asesorVal = (int)$asesor->comision_afil_valor;
            }
        }

        $utilidad = max(0, $totalAfil - $admon - $asesorVal - $retiro);

        return [
            'admon'    => $admon,
            'asesor'   => $asesorVal,
            'retiro'   => $retiro,
            'utilidad' => $utilidad,
        ];
    }
}
