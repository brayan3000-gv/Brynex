<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Gasto extends BaseModel
{
    protected $table = 'gastos';

    /**
     * Cuántos registros de plano se liberaron al eliminar este gasto.
     * Lo llena el evento `deleting`; sirve para armar el mensaje al usuario.
     */
    public int $planosLiberados = 0;

    protected $fillable = [
        'aliado_id', 'usuario_id', 'cuadre_id',
        'fecha', 'periodo', 'tipo', 'numero_planilla', 'descripcion', 'pagado_a', 'cc_pagado_a',
        'forma_pago', 'banco_origen_id', 'banco_destino_id',
        'valor', 'recibo_caja', 'lugar', 'observacion',
        'imagen_path',  // soporte / comprobante de pago
        // Origen del gasto cuando nace de una incapacidad: permite deshacerlo
        // si se reversa el estado que lo creó, sin adivinar por la descripción.
        'incapacidad_id', 'gestion_incapacidad_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'periodo' => 'date',
        'valor' => 'integer',
    ];

    // ── Etiquetas legibles para el frontend ─────────────────────────
    const TIPOS = [
        // MOVIMIENTOS
        'efectivo_banco' => 'Efectivo → Banco',
        'banco_banco' => 'Banco → Banco',
        // GASTOS SEDE
        'facturas' => 'Facturas',
        'arriendo' => 'Arriendo',
        'papeleria' => 'Papelería / útiles',
        'viaticos' => 'Viáticos / transporte',
        'servicios' => 'Pago servicios',
        // Lo crea solo la carga del extracto: 4x1000, cuota de manejo, IVA…
        'cargos_bancarios' => 'Cargos bancarios',
        // NÓMINA
        'salarios' => 'Salarios',
        'vales' => 'Vales',
        'comisiones_nomina' => 'Comisiones',
        'nomina' => 'Pago nómina',       // legacy
        // OTROS
        'otros' => 'Otros',
        'otro_oficina' => 'Otro gasto oficina', // legacy
        'otro_admin' => 'Otro gasto admin',   // legacy
        // INCAPACIDADES (Canal 5 — no afectan Canal 1)
        'pago_incapacidad' => 'Pago Incapacidad Afiliado',
        'cuatropormil_incapacidad' => '4x1000 Incapacidad',
        'otros_incapacidad' => 'Otros Desc. Incapacidad',
        'admon_incapacidad' => 'Ganancia Admon Incapacidad',
        // ADMIN (ocultos del select normal)
        'transferencia_banco' => 'Pago desde banco',
        'pago_planilla' => 'Pago Planilla SS',
    ];

    // ── Agrupación para <optgroup> en el select ──────────────────────────────────────
    const TIPOS_GRUPOS = [
        '💱 Movimientos' => ['efectivo_banco', 'banco_banco'],
        '🏢 Gastos Sede' => ['facturas', 'arriendo', 'papeleria', 'viaticos', 'servicios', 'cargos_bancarios'],
        '👥 Nómina' => ['salarios', 'vales', 'comisiones_nomina', 'nomina'],
        '📦 Otros' => ['otros', 'otro_oficina', 'otro_admin'],
        '🏥 Incapacidades' => ['pago_incapacidad', 'cuatropormil_incapacidad', 'otros_incapacidad', 'admon_incapacidad'],
    ];

    // ── Tipos de incapacidad (excluidos de gastosOp Canal 1) ───────────────────────
    const TIPOS_INCAPACIDAD = [
        'pago_incapacidad',
        'cuatropormil_incapacidad',
        'otros_incapacidad',
        'admon_incapacidad',
    ];

    // ── Tipos de Nómina (muestran select de usuario) ─────────────────
    const TIPOS_NOMINA = ['salarios', 'vales', 'comisiones_nomina', 'nomina'];

    // ── Tipos que solo puede usar admin/superadmin ───────────────────
    const TIPOS_ADMIN = ['banco_banco', 'efectivo_banco', 'nomina', 'transferencia_banco', 'otro_admin'];

    // ── Eventos ───────────────────────────────────────────────────────
    protected static function booted(): void
    {
        // Un gasto `pago_planilla` es lo que marca los planos como pagados
        // (ver PlanoPagoController::confirmarPago). Si el gasto desaparece,
        // los planos deben volver a «pendiente»: se les quita el número de
        // planilla para poder confirmar el pago de nuevo. Los planos NO se
        // borran. Va aquí y no en el controlador para que aplique desde
        // cualquier punto que elimine el gasto.
        static::deleting(function (self $gasto) {
            $gasto->planosLiberados = $gasto->liberarPlanos();
        });
    }

    /**
     * Envuelve el borrado en una transacción para que quitar el número de
     * planilla a los planos y eliminar el gasto sean atómicos, sin depender
     * de que quien llama abra la transacción. Si ya hay una transacción
     * abierta, esta anida con savepoint y no cambia nada.
     */
    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    // ── Pago de planilla ──────────────────────────────────────────────
    public function esPagoPlanilla(): bool
    {
        return $this->tipo === 'pago_planilla' && trim((string) $this->numero_planilla) !== '';
    }

    /**
     * Query de los planos marcados con el número de planilla de este gasto.
     * $alias = null para poder hacer UPDATE (SQL Server no acepta alias ahí).
     */
    public function planosPagados(?string $alias = 'p')
    {
        $col = $alias ? "{$alias}." : '';

        return DB::table($alias ? "planos AS {$alias}" : 'planos')
            ->where("{$col}aliado_id", $this->aliado_id)
            ->whereNull("{$col}deleted_at")
            ->where("{$col}numero_planilla", trim((string) $this->numero_planilla));
    }

    /** Devuelve los planos de esta planilla a «pendiente de pago». */
    public function liberarPlanos(): int
    {
        if (! $this->esPagoPlanilla()) {
            return 0;
        }

        $liberados = $this->planosPagados(null)->update([
            'numero_planilla' => null,
            'updated_at' => now(),
        ]);

        // Dos pasos: el plano pagado lleva el número de la corrección, no el
        // del paso 1. Sin el pago del paso 1 el mes tampoco está cubierto.
        $correcciones = DB::table('operador_planillas_api')
            ->where('aliado_id', $this->aliado_id)
            ->where('paso', 2)
            ->where('planilla_asociada_numero', trim((string) $this->numero_planilla))
            ->whereNotNull('numero_planilla')
            ->pluck('numero_planilla');

        if ($correcciones->isNotEmpty()) {
            $liberados += DB::table('planos')
                ->where('aliado_id', $this->aliado_id)
                ->whereNull('deleted_at')
                ->whereIn('numero_planilla', $correcciones->all())
                ->update(['numero_planilla' => null, 'updated_at' => now()]);
        }

        return $liberados;
    }

    // ── Relaciones ────────────────────────────────────────────────────
    public function cuadre()
    {
        return $this->belongsTo(Cuadre::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function bancoOrigen()
    {
        return $this->belongsTo(BancoCuenta::class, 'banco_origen_id');
    }

    public function bancoDestino()
    {
        return $this->belongsTo(BancoCuenta::class, 'banco_destino_id');
    }

    // ── Mes al que corresponde ────────────────────────────────────────
    // `fecha` es el día en que se pagó y manda en bancos y efectivo (cuadra
    // contra el extracto). `periodo` es el mes al que corresponde el gasto y
    // manda en la utilidad; vacío = el mes de `fecha`.

    /**
     * Filtra un query de `gastos` por el mes al que corresponde. Sirve para
     * DB::table() y Eloquent; $alias es el de la tabla en el query ('g').
     * Se escribe como OR en vez de COALESCE para que siga usando el índice de fecha.
     */
    public static function wherePeriodo($query, int $mes, int $anio, string $alias = '')
    {
        $desde = Carbon::create($anio, $mes, 1);

        return static::wherePeriodoEntre($query, $desde, $desde->copy()->endOfMonth(), $alias);
    }

    /** Igual que wherePeriodo, para varios meses seguidos (de $desde a $hasta). */
    public static function wherePeriodoEntre($query, Carbon $desde, Carbon $hasta, string $alias = '')
    {
        $col = $alias ? "{$alias}." : '';
        $ini = $desde->copy()->startOfMonth()->toDateString();
        $fin = $hasta->copy()->endOfMonth()->toDateString();

        return $query->where(function ($q) use ($col, $ini, $fin) {
            $q->whereBetween("{$col}periodo", [$ini, $fin])
              ->orWhere(function ($q2) use ($col, $ini, $fin) {
                  $q2->whereNull("{$col}periodo")
                     ->whereBetween("{$col}fecha", [$ini, $fin]);
              });
        });
    }

    /** Expresión SQL del mes al que corresponde (día 1), para agrupar o mostrar. */
    public static function periodoSql(string $alias = ''): string
    {
        $col = $alias ? "{$alias}." : '';

        return "ISNULL({$col}periodo, DATEFROMPARTS(YEAR({$col}fecha), MONTH({$col}fecha), 1))";
    }

    /**
     * Normaliza el «Mes al que corresponde» del formulario ('2026-08') al día 1.
     * Si es el mismo mes de la fecha se guarda vacío: así, si después se corrige
     * la fecha, el mes la sigue.
     */
    public static function periodoDesdeFormulario(?string $periodo, $fecha): ?string
    {
        if (! $periodo) {
            return null;
        }
        $p = Carbon::createFromFormat('!Y-m', $periodo);
        $f = Carbon::parse($fecha);

        return $p->format('Y-m') === $f->format('Y-m') ? null : $p->toDateString();
    }

    // ── Scopes ────────────────────────────────────────────────────────
    public function scopeEfectivo($q)
    {
        return $q->where('forma_pago', 'efectivo');
    }

    public function scopeEnFecha($q, $fecha)
    {
        return $q->whereDate('fecha', $fecha);
    }

    public function scopeDelAliado($q, int $aliadoId)
    {
        return $q->where('aliado_id', $aliadoId);
    }

    // ── Helpers ───────────────────────────────────────────────────────
    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? ucfirst($this->tipo);
    }

    public function esDeEfectivo(): bool
    {
        return $this->forma_pago === 'efectivo' || $this->tipo === 'efectivo_banco';
    }
}
