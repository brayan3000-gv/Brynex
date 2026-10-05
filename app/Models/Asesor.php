<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asesor extends BaseModel
{
    use SoftDeletes;

    protected $table = 'asesores';

    protected $fillable = [
        'aliado_id',
        'cedula',
        'nombre',
        'telefono',
        'celular',
        'correo',
        'direccion',
        'ciudad',
        'departamento',
        'cuenta_bancaria',
        'comision_afil_tipo',
        'comision_afil_valor',
        'comision_admon_tipo',
        'comision_admon_valor',
        'tipo_cobro',
        'tarifa_neta',
        'forma_pago',
        'nivel_id',
        'fecha_ingreso',
        'activo',
        'id_original_access',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'fecha_ingreso' => 'date',
        'comision_afil_valor' => 'decimal:2',
        'comision_admon_valor' => 'decimal:2',
        'tarifa_neta' => 'decimal:2',
    ];

    /** Cómo trabaja el asesor con el aliado. Se cambia a mano en su ficha. */
    const TIPOS_COBRO = [
        'comision' => 'Comisión en el programa',
        'neta' => 'Tarifa neta (cobra lo suyo por fuera)',
        'referido' => 'Solo refiere (gana por afiliación)',
        'interno' => 'Interno (oficina)',
    ];

    const FORMAS_PAGO = [
        'descuenta' => 'Descuenta al cobrar',
        'quincenal' => 'Quincenal',
        'mensual' => 'Mensual',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────
    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class, 'aliado_id');
    }

    public function comisiones(): HasMany
    {
        return $this->hasMany(ComisionAsesor::class, 'asesor_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(AsesorNivel::class, 'nivel_id');
    }

    /** Matriz propia de tarifas de afiliación (copia editable de la plantilla del nivel). */
    public function tarifas(): HasMany
    {
        return $this->hasMany(AsesorTarifa::class, 'asesor_id');
    }

    /**
     * Contratos vigentes a su nombre — es el conteo que sugiere el nivel.
     * Vigente = estado 'vigente', el mismo criterio que Contrato::estaVigente();
     * fecha_retiro no sirve como filtro (hay retirados que la traen nula).
     */
    public function contratosVigentes(): int
    {
        return (int) Contrato::where('aliado_id', $this->aliado_id)
            ->where('asesor_id', $this->id)
            ->where('estado', 'vigente')
            ->count();
    }

    // ─── Scopes ───────────────────────────────────────────────────────
    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    public function scopeDelAliado(Builder $q, int $alidoId): Builder
    {
        return $q->where('aliado_id', $alidoId);
    }

    // ─── Lógica de cálculo de comisiones ─────────────────────────────

    /**
     * Calcula la comisión de afiliación dado el valor del contrato.
     * Si tipo = 'fijo' → retorna el valor fijo del asesor.
     * Si tipo = 'porcentaje' → retorna el % del valor del contrato.
     */
    public function calcularComisionAfiliacion(float $valorContrato = 0): float
    {
        if ($this->comision_afil_tipo === 'porcentaje') {
            return round($valorContrato * ($this->comision_afil_valor / 100), 2);
        }

        return (float) $this->comision_afil_valor;
    }

    /**
     * Calcula la comisión de administración mensual dado el valor de la cuota.
     */
    public function calcularComisionAdmon(float $valorAdmon = 0): float
    {
        if ($this->comision_admon_tipo === 'porcentaje') {
            return round($valorAdmon * ($this->comision_admon_valor / 100), 2);
        }

        return (float) $this->comision_admon_valor;
    }

    // ─── Helpers de presentación ─────────────────────────────────────
    public function comisionAfiliacionLabel(): string
    {
        if ($this->comision_afil_tipo === 'porcentaje') {
            return "{$this->comision_afil_valor}%";
        }

        return '$'.number_format($this->comision_afil_valor, 0, ',', '.');
    }

    public function comisionAdmonLabel(): string
    {
        if ($this->comision_admon_tipo === 'porcentaje') {
            return "{$this->comision_admon_valor}%";
        }

        return '$'.number_format($this->comision_admon_valor, 0, ',', '.');
    }

    // ─── Nivel: copiar la plantilla a la matriz propia ────────────────

    /**
     * Asigna un nivel y COPIA su plantilla a la matriz propia del asesor. Desde ese momento
     * las tarifas del asesor son independientes: editar el nivel no vuelve a tocarlas, y solo
     * se re-copian si se reasigna el nivel a mano.
     *
     * La admon del asesor se guarda en comision_admon_* (valor único del nivel) porque es ahí
     * donde la lee la facturación de planillas de siempre.
     *
     * @param  bool  $conservarEditadas  true = respeta lo que el asesor ya tenía ajustado —tanto
     *                                   sus celdas como su admon mensual— y solo completa lo que
     *                                   falte; false = reemplaza toda la matriz y le pone la
     *                                   admon del nivel.
     * @return int celdas escritas
     */
    public function aplicarNivel(AsesorNivel $nivel, bool $conservarEditadas = false): int
    {
        if ((int) $nivel->aliado_id !== (int) $this->aliado_id) {
            throw new \InvalidArgumentException('El nivel pertenece a otro aliado.');
        }

        // Releer el nivel: si el llamador trae una instancia cargada antes de editarla, se
        // copiaría una admon vieja al asesor y nadie lo notaría hasta la liquidación.
        $nivel = $nivel->fresh(['tarifas']);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($nivel, $conservarEditadas) {
            $existentes = AsesorTarifa::where('asesor_id', $this->id)
                ->get()
                ->keyBy(fn ($t) => AsesorNivelTarifa::claveCelda(
                    (int) $t->plan_id,
                    (int) $t->tipo_modalidad_id,
                    (int) $t->nivel_arl
                ));

            if (! $conservarEditadas) {
                AsesorTarifa::where('asesor_id', $this->id)->delete();
                $existentes = collect();
            }

            // Inserción en bloque: una plantilla completa son ~193 filas y crearlas una por una
            // tardaba medio minuto contra este servidor (~250ms por consulta).
            $ahora = now();
            $filas = [];

            foreach ($nivel->tarifas as $plantilla) {
                $clave = AsesorNivelTarifa::claveCelda(
                    (int) $plantilla->plan_id,
                    (int) $plantilla->tipo_modalidad_id,
                    (int) $plantilla->nivel_arl
                );

                if ($existentes->has($clave)) {
                    continue;
                }

                $filas[] = [
                    'aliado_id' => $this->aliado_id,
                    'asesor_id' => $this->id,
                    'plan_id' => $plantilla->plan_id,
                    'tipo_modalidad_id' => $plantilla->tipo_modalidad_id,
                    'nivel_arl' => $plantilla->nivel_arl,
                    'afil_asesor' => $plantilla->afil_asesor,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            // 150 filas × 8 columnas = 1.200 parámetros, bajo el tope de 2.100 de SQL Server.
            foreach (array_chunk($filas, 150) as $lote) {
                AsesorTarifa::insert($lote);
            }

            $escritas = count($filas);

            // La admon del nivel solo se copia si el asesor no tiene una propia distinta. Con
            // «No pisar las que ya ajusté» marcada, a un asesor al que le dejaron 5.500 no se
            // le devuelve a los 5.000 del nivel: era justo lo que la casilla prometía y no
            // cumplía. Sin la casilla, el nivel manda como siempre.
            $datos = ['nivel_id' => $nivel->id];

            // Cuenta como "ajustada" cualquier admon propia que no sea la del nivel: un valor
            // fijo distinto, o una comisión por porcentaje (que el nivel no sabe expresar y
            // convertirla a fijo le cambiaría la plata al asesor sin avisar).
            $valor = (float) $this->comision_admon_valor;
            $ajustada = $valor > 0 && (
                $this->comision_admon_tipo === 'porcentaje' || $valor !== (float) $nivel->admon_asesor
            );

            if (! ($conservarEditadas && $ajustada)) {
                $datos['comision_admon_tipo'] = 'fijo';
                $datos['comision_admon_valor'] = $nivel->admon_asesor;
            }

            $this->update($datos);

            \App\Services\TarifaAsesorService::limpiarCache();

            return $escritas;
        });
    }

    // ─── Totales pendientes de pago ───────────────────────────────────
    public function totalPendiente(): float
    {
        return (float) $this->comisiones()->where('pagado', false)->sum('valor_comision');
    }

    public function totalPagado(): float
    {
        return (float) $this->comisiones()->where('pagado', true)->sum('valor_comision');
    }
}
