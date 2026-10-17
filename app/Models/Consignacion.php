<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Consignacion extends BaseModel
{
    use SoftDeletes;

    protected $table = 'consignaciones';

    protected $fillable = [
        'aliado_id', 'factura_id', 'anticipo_id', 'abono_id', 'banco_cuenta_id',
        'fecha', 'valor', 'tipo', 'referencia', 'imagen_path',
        'confirmado', 'observacion', 'usuario_id',
        // Cuadre diario - traslados internos
        'cuadre_id', 'gasto_id',
        'no_aparece', 'usuario_validador_id', 'fecha_validacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'confirmado' => 'boolean',
        'valor' => 'integer',
        'no_aparece' => 'boolean',
    ];

    // ── Tipos de consignación ────────────────────────────────────────
    /** Pago de cliente por factura (seguridad social, afiliación, etc.) */
    const TIPO_CLIENTE = 'cliente';

    /** Traslado de efectivo del cuadre a una cuenta bancaria */
    const TIPO_TRASLADO_EFECTIVO = 'traslado_efectivo';

    /** Entrada por transferencia banco→banco */
    const TIPO_BANCO_RECIBIDO = 'banco_recibido';

    /** Pago anticipado de cliente (antes de la factura) */
    const TIPO_ANTICIPO = 'anticipo';

    /**
     * Abono consignado a un préstamo. Cuelga del abono (`abono_id`) y no de la
     * factura: la factura ya tiene las consignaciones de su pago inicial, y la
     * corrección de pago las reescribe todas.
     */
    const TIPO_ABONO = 'abono_prestamo';

    // ── Relaciones ───────────────────────────────────────────────────
    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }

    public function bancoCuenta()
    {
        return $this->belongsTo(BancoCuenta::class, 'banco_cuenta_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Movimientos del extracto que respaldan esta consignación. */
    public function movimientosBanco()
    {
        return $this->belongsToMany(
            BancoMovimiento::class,
            'banco_movimiento_consignacion',
            'consignacion_id',
            'banco_movimiento_id'
        )->withPivot('valor_aplicado', 'regla', 'dias_diferencia')->withTimestamps();
    }

    /** Anticipo asociado (cuando tipo = 'anticipo') */
    public function anticipo()
    {
        return $this->belongsTo(Anticipo::class, 'anticipo_id');
    }

    public function usuarioValidador()
    {
        return $this->belongsTo(User::class, 'usuario_validador_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopeSinConfirmar($query)
    {
        return $query->where('confirmado', false);
    }

    public function scopeConfirmadas($query)
    {
        return $query->where('confirmado', true);
    }

    public function scopeClientes($query)
    {
        return $query->where('tipo', self::TIPO_CLIENTE);
    }

    /**
     * La entrada al banco de un abono consignado a un préstamo.
     *
     * El abono vivía solo en su tabla: la plata entraba a la cuenta pero no
     * sumaba al saldo del banco ni al informe, y la conciliación no tenía con
     * qué cruzar el movimiento del extracto ($2,1M en octubre de 2026 en
     * BRYGAR). No se crea si no hay parte consignada o cuenta, ni dos veces
     * para el mismo abono.
     */
    public static function registrarDeAbono(Abono $abono, int $aliadoId): ?self
    {
        $valor = (int) $abono->valor_consignado;
        if ($valor <= 0 || ! $abono->banco_cuenta_id) {
            return null;
        }

        return static::firstOrCreate(
            ['abono_id' => $abono->id],
            [
                'aliado_id'       => $aliadoId,
                'factura_id'      => null,
                'banco_cuenta_id' => $abono->banco_cuenta_id,
                'fecha'           => $abono->fecha,
                'valor'           => $valor,
                'tipo'            => self::TIPO_ABONO,
                'referencia'      => 'Abono #' . $abono->id,
                'confirmado'      => false,
                'observacion'     => $abono->observacion,
                'usuario_id'      => $abono->usuario_id,
            ]
        );
    }

    // ── Helper: saldo actual de una cuenta bancaria ──────────────────
    /**
     * Calcula el saldo real de una cuenta bancaria combinando:
     *  + consignaciones confirmadas (clientes + traslados efectivo + banco recibido)
     *  – gastos cuyo banco_origen_id = $bancoCuentaId (pagos y transferencias salientes)
     */
    public static function saldoBanco(int $aliadoId, int $bancoCuentaId): int
    {
        // ── Estrategia: si existe un saldo_inicial en saldos_banco, úsalo
        // como punto de partida y solo cuenta movimientos POSTERIORES a esa fecha.
        // Si no existe, recalcula desde el principio (comportamiento original).
        $ledger = DB::table('saldos_banco')
            ->where('aliado_id', $aliadoId)
            ->where('banco_cuenta_id', $bancoCuentaId)
            ->where('tipo', 'saldo_inicial')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first(['fecha', 'saldo_acumulado']);

        if ($ledger) {
            $baseDate = $ledger->fecha;
            $baseSaldo = (int) $ledger->saldo_acumulado; // normalmente 0

            $entradas = (int) static::where('aliado_id', $aliadoId)
                ->where('banco_cuenta_id', $bancoCuentaId)
                ->where('fecha', '>=', '2026-07-01')
                ->where('fecha', '>=', $baseDate)
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            $salidas = (int) DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('banco_origen_id', $bancoCuentaId)
                ->where('fecha', '>=', '2026-07-01')
                ->where('tipo', '!=', 'ajuste_apertura')
                ->where('tipo', '!=', 'efectivo_banco')
                ->where('fecha', '>=', $baseDate)
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            return $baseSaldo + $entradas - $salidas;
        }

        // ── Fallback: recalcular todo el historial ────────────────────────
        // Usa selectRaw + alias para compatibilidad con SQL Server (no value(DB::raw()))
        $entradas = (int) static::where('aliado_id', $aliadoId)
            ->where('banco_cuenta_id', $bancoCuentaId)
            ->where('fecha', '>=', '2026-07-01')
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->value('total');

        $salidas = (int) DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where('banco_origen_id', $bancoCuentaId)
            ->where('fecha', '>=', '2026-07-01')
            ->where('tipo', '!=', 'efectivo_banco')
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->value('total');

        return $entradas - $salidas;
    }

    /**
     * Calcula los saldos de múltiples cuentas bancarias en un lote optimizado.
     * Retorna un array asociativo [banco_cuenta_id => saldo].
     */
    public static function saldosBancosOptimizados(int $aliadoId, array $bancoCuentaIds, ?string $fechaFin = null): array
    {
        if (empty($bancoCuentaIds)) {
            return [];
        }

        $saldos = array_fill_keys($bancoCuentaIds, 0);

        // 1. Obtener los saldos iniciales (ledgers) si existen para estos bancos
        $ledgersQuery = DB::table('saldos_banco')
            ->where('aliado_id', $aliadoId)
            ->whereIn('banco_cuenta_id', $bancoCuentaIds)
            ->where('tipo', 'saldo_inicial');

        // Siempre aplicar el corte de julio 2026
        $ledgersQuery->where('fecha', '>=', '2026-07-01');
        if ($fechaFin) {
            $ledgersQuery->where('fecha', '<=', $fechaFin);
        }

        $ledgers = $ledgersQuery->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get()
            ->groupBy('banco_cuenta_id');

        $bancosConLedger = [];
        $bancosSinLedger = [];

        foreach ($bancoCuentaIds as $bancoId) {
            if ($ledgers->has($bancoId)) {
                $bancosConLedger[] = $bancoId;
            } else {
                $bancosSinLedger[] = $bancoId;
            }
        }

        // 2. Procesar en lote bancos SIN ledger (la gran mayoría)
        if (! empty($bancosSinLedger)) {
            $entradasQuery = DB::table('consignaciones')
                ->where('aliado_id', $aliadoId)
                ->whereNull('deleted_at')
                ->where('fecha', '>=', '2026-07-01')
                ->whereIn('banco_cuenta_id', $bancosSinLedger);

            if ($fechaFin) {
                $entradasQuery->where('fecha', '<=', $fechaFin);
            }

            $entradas = $entradasQuery
                ->groupBy('banco_cuenta_id')
                ->selectRaw('banco_cuenta_id, ISNULL(SUM(CAST(valor AS BIGINT)), 0) as total')
                ->pluck('total', 'banco_cuenta_id')
                ->toArray();

            $salidasQuery = DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('fecha', '>=', '2026-07-01')
                ->where('tipo', '!=', 'efectivo_banco')
                ->whereIn('banco_origen_id', $bancosSinLedger);

            if ($fechaFin) {
                $salidasQuery->where('fecha', '<=', $fechaFin);
            }

            $salidas = $salidasQuery
                ->groupBy('banco_origen_id')
                ->selectRaw('banco_origen_id, ISNULL(SUM(CAST(valor AS BIGINT)), 0) as total')
                ->pluck('total', 'banco_origen_id')
                ->toArray();

            foreach ($bancosSinLedger as $bancoId) {
                $ent = (int) ($entradas[$bancoId] ?? 0);
                $sal = (int) ($salidas[$bancoId] ?? 0);
                $saldos[$bancoId] = $ent - $sal;
            }
        }

        // 3. Procesar bancos CON ledger de forma individual (son muy pocos o ninguno)
        foreach ($bancosConLedger as $bancoId) {
            $ledger = $ledgers->get($bancoId)->first();
            $baseDate = $ledger->fecha;
            $baseSaldo = (int) $ledger->saldo_acumulado;

            $entradasQuery = static::where('aliado_id', $aliadoId)
                ->where('banco_cuenta_id', $bancoId)
                ->where('fecha', '>=', '2026-07-01')
                ->where('fecha', '>=', $baseDate);

            if ($fechaFin) {
                $entradasQuery->where('fecha', '<=', $fechaFin);
            }

            $entradas = (int) $entradasQuery
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            $salidasQuery = DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('banco_origen_id', $bancoId)
                ->where('fecha', '>=', '2026-07-01')
                ->where('tipo', '!=', 'ajuste_apertura')
                ->where('tipo', '!=', 'efectivo_banco')
                ->where('fecha', '>=', $baseDate);

            if ($fechaFin) {
                $salidasQuery->where('fecha', '<=', $fechaFin);
            }

            $salidas = (int) $salidasQuery
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            $saldos[$bancoId] = $baseSaldo + $entradas - $salidas;
        }

        return $saldos;
    }

    /**
     * Retorna un array con [banco_cuenta_id => saldo] para todas las cuentas activas.
     */
    public static function saldosTodos(int $aliadoId): array
    {
        $bancoCuentaIds = BancoCuenta::where('aliado_id', $aliadoId)->where('activo', true)->pluck('id')->toArray();

        return static::saldosBancosOptimizados($aliadoId, $bancoCuentaIds);
    }

    /**
     * Calcula el saldo de un banco al CIERRE de un mes/año específico.
     * Usado en el informe financiero cuando el filtro es un mes pasado.
     *
     * Lógica:
     *  - Si existe saldo_inicial anterior o igual al último día del mes → parte desde él
     *  - Suma entradas y resta salidas hasta el último día del mes (inclusive)
     */
    public static function saldoBancoAlFin(int $aliadoId, int $bancoCuentaId, int $mes, int $anio): int
    {
        // Último día del mes filtrado
        $fechaFin = \Carbon\Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

        // Buscar saldo_inicial que aplique (anterior o igual al cierre del mes)
        $ledger = DB::table('saldos_banco')
            ->where('aliado_id', $aliadoId)
            ->where('banco_cuenta_id', $bancoCuentaId)
            ->where('tipo', 'saldo_inicial')
            ->where('fecha', '<=', $fechaFin)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first(['fecha', 'saldo_acumulado']);

        if ($ledger) {
            $baseDate = $ledger->fecha;
            $baseSaldo = (int) $ledger->saldo_acumulado;

            $entradas = (int) static::where('aliado_id', $aliadoId)
                ->where('banco_cuenta_id', $bancoCuentaId)
                ->where('fecha', '>', $baseDate)
                ->where('fecha', '<=', $fechaFin)
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            $salidas = (int) DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('banco_origen_id', $bancoCuentaId)
                ->where('tipo', '!=', 'ajuste_apertura')
                ->where('fecha', '>', $baseDate)
                ->where('fecha', '<=', $fechaFin)
                ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
                ->value('total');

            return $baseSaldo + $entradas - $salidas;
        }

        // Fallback: sumar todo hasta el cierre del mes
        $entradas = (int) static::where('aliado_id', $aliadoId)
            ->where('banco_cuenta_id', $bancoCuentaId)
            ->where('fecha', '>=', '2026-07-01')
            ->where('fecha', '<=', $fechaFin)
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->value('total');

        $salidas = (int) DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where('banco_origen_id', $bancoCuentaId)
            ->where('fecha', '>=', '2026-07-01')
            ->where('fecha', '<=', $fechaFin)
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->value('total');

        return $entradas - $salidas;
    }
}
