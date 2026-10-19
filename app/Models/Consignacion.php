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

    /**
     * La entrada al banco de un pago conjunto («Pagar todo»): una sola
     * consignación por el total consignado, colgada del primer abono del
     * pago. Cuadra uno a uno con el movimiento del extracto; con una por
     * período eran cuatro líneas para una sola plata. Los demás abonos llevan
     * su parte en valor_consignado, como las facturas 2..N de un lote.
     *
     * @param \Illuminate\Support\Collection<int, Abono> $abonos los del pago, el primero primero
     */
    public static function registrarDePago($abonos, int $aliadoId): ?self
    {
        $primero = $abonos->first();
        $valor   = (int) $abonos->sum('valor_consignado');
        $cuenta  = $abonos->firstWhere('banco_cuenta_id', '!=', null)?->banco_cuenta_id;
        if (! $primero || $valor <= 0 || ! $cuenta) {
            return null;
        }

        return static::firstOrCreate(
            ['abono_id' => $primero->id],
            [
                'aliado_id'       => $aliadoId,
                'factura_id'      => null,
                'banco_cuenta_id' => $cuenta,
                'fecha'           => $primero->fecha,
                'valor'           => $valor,
                'tipo'            => self::TIPO_ABONO,
                'referencia'      => mb_substr('Abonos #' . $abonos->pluck('id')->join(', #'), 0, 100),
                'confirmado'      => false,
                'observacion'     => $primero->observacion,
                'usuario_id'      => $primero->usuario_id,
            ]
        );
    }

    /**
     * Al anular una factura que tiene abonos de un pago conjunto: la
     * consignación es de todo el pago, así que no se borra entera. Pasa al
     * primer abono que queda vivo en otra factura y baja a lo que esos abonos
     * llevan consignado. Si no queda ninguno, se deja colgada de su abono y la
     * borra la anulación como a cualquier otra.
     */
    public static function reajustarPago(int $pagoId, int $sinFacturaId): void
    {
        $delPago = Abono::where('pago_id', $pagoId)->orderBy('id')->get();
        $consignacion = static::whereIn('abono_id', $delPago->pluck('id')->all())->first();
        if (! $consignacion) {
            return;
        }

        $quedan = $delPago->where('factura_id', '!=', $sinFacturaId)->where('valor_consignado', '>', 0)->values();
        if ($quedan->isEmpty()) {
            return;
        }

        $consignacion->update([
            'abono_id' => $quedan->first()->id,
            'valor'    => (int) $quedan->sum('valor_consignado'),
        ]);
    }

    // ── Helper: saldo actual de una cuenta bancaria ──────────────────
    /** Saldo de hoy de una cuenta bancaria (regla: App\Services\Caja\SaldosBanco). */
    public static function saldoBanco(int $aliadoId, int $bancoCuentaId): int
    {
        // Una sola regla para todo el sistema: ver App\Services\Caja\SaldosBanco.
        return \App\Services\Caja\SaldosBanco::hoy($aliadoId, $bancoCuentaId);
    }

    /**
     * Saldos de varias cuentas [banco_cuenta_id => saldo] al día $fechaFin (hoy
     * si es null). Regla: App\Services\Caja\SaldosBanco.
     */
    public static function saldosBancosOptimizados(int $aliadoId, array $bancoCuentaIds, ?string $fechaFin = null): array
    {
        $saldos = \App\Services\Caja\SaldosBanco::al($aliadoId, $bancoCuentaIds, $fechaFin ?? today()->toDateString());

        return array_map(fn ($s) => $s['saldo'], $saldos) + array_fill_keys($bancoCuentaIds, 0);
    }

    /**
     * Retorna un array con [banco_cuenta_id => saldo] para todas las cuentas activas.
     */
    public static function saldosTodos(int $aliadoId): array
    {
        $bancoCuentaIds = BancoCuenta::where('aliado_id', $aliadoId)->where('activo', true)->pluck('id')->toArray();

        return static::saldosBancosOptimizados($aliadoId, $bancoCuentaIds);
    }

    /** Saldo de una cuenta al cierre de un mes (regla: App\Services\Caja\SaldosBanco). */
    public static function saldoBancoAlFin(int $aliadoId, int $bancoCuentaId, int $mes, int $anio): int
    {
        $fechaFin = \Carbon\Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

        return \App\Services\Caja\SaldosBanco::al($aliadoId, [$bancoCuentaId], $fechaFin)[$bancoCuentaId]['saldo'] ?? 0;
    }
}
