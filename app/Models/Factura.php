<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Factura extends BaseModel
{
    use SoftDeletes;

    protected $table    = 'facturas';
    /**
     * Hasta este valor, un préstamo no le cambia la cara al recibo: sale con
     * sello PAGO y la deuda vive en /admin/prestamos, donde alguien la cobra.
     * Por encima, el recibo sale con sello PRÉSTAMO.
     *
     * De los 617 préstamos de 2026, 491 están por debajo: son moras, redondeos
     * y faltantes chicos. Los que pasan de aquí son deuda de verdad y el cliente
     * tiene que verla en su recibo.
     */
    public const PRESTAMO_SELLO_PAGO = 20000;

    protected $fillable = [
        'aliado_id','numero_factura','tipo','cedula','contrato_id',
        'empresa_id',
        'mes','anio','fecha_pago',
        'estado','es_prestamo','forma_pago',
        'valor_consignado','valor_efectivo','valor_prestamo',
        'dias_cotizados',
        'v_eps','v_arl','v_afp','v_caja','v_parafiscales','total_ss',
        'admon','admin_asesor','otros_admon','seguro','afiliacion','mensajeria','otros','mora','iva','total',
        'saldo_proximo',
        // ── Anticipo: pagos previos aplicados a esta factura ──────
        // IMPORTANTE: debe estar en fillable para que Factura::create() lo guarde.
        // Sin esto, saldo_proximo = efectivo - total (ignora el anticipo → aparece como deuda).
        'anticipo_aplicado',
        'c_asesor','c_utilidad','retiro',
        'dist_admon','dist_asesor','dist_retiro','dist_utilidad','dist_encargado',
        'np','n_plano','razon_social_id',
        'usuario_id','observacion','obs_factura',
        'motivo_anulacion','anulado_por',
        // ── Otro ingreso / trámite ─────────────────────────────
        'descripcion_tramite','admon_asesor_oi',
        // ── Facturación Electrónica (Dataico) ─────────────────
        'fe_marcada','fe_marcada_at','fe_marcada_por',
        // ── Retiro facturado: enlace con la factura 0 de origen ──
        // Permite reactivar la factura 0 si se anula la factura real del retiro.
        'factura_retiro_origen_id',
    ];

    protected $casts = [
        'fecha_pago'           => 'date',
        'es_prestamo'          => 'boolean',
        'fe_marcada'           => 'boolean',
        'fe_marcada_at'        => 'datetime',
    ];

    // ── Tipos ──────────────────────────────────────────────────
    const TIPO_PLANILLA      = 'planilla';
    const TIPO_AFILIACION    = 'afiliacion';
    const TIPO_OTRO_INGRESO  = 'otro_ingreso';

    // ── Estados ────────────────────────────────────────────────
    const ESTADO_PRE       = 'pre_factura';
    const ESTADO_ABONO     = 'abono';
    const ESTADO_PAGADA    = 'pagada';
    const ESTADO_PRESTAMO  = 'prestamo';
    // nota: 'anulada' se maneja con SoftDeletes (deleted_at), no como estado

    // ── Relaciones ───────────────────────────────────────────────────
    public function consignaciones(){ return $this->hasMany(Consignacion::class); }
    public function contrato()   { return $this->belongsTo(Contrato::class); }
    public function abonos()     { return $this->hasMany(Abono::class); }
    public function plano()      { return $this->hasOne(Plano::class); }
    public function usuario()    { return $this->belongsTo(User::class, 'usuario_id'); }
    public function razonSocial(){ return $this->belongsTo(RazonSocial::class, 'razon_social_id'); }
    /** Empresa que procesó el pago en lote (null = pago individual) */
    public function empresa()    { return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id'); }


    // ── Scopes ───────────────────────────────────────────────────────
    public function scopePeriodo(Builder $q, int $mes, int $anio): Builder
    {
        return $q->where('mes', $mes)->where('anio', $anio);
    }

    public function scopePorEmpresa(Builder $q, int $empresaId): Builder
    {
        $cedulas = DB::table('clientes')->where('cod_empresa', $empresaId)->pluck('cedula');
        return $q->whereIn('cedula', $cedulas);
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::ESTADO_PRE, self::ESTADO_ABONO, self::ESTADO_PRESTAMO]);
    }

    public function scopeAliado(Builder $q, int $aliadoId): Builder
    {
        return $q->where('aliado_id', $aliadoId);
    }

    /** Préstamos con saldo pendiente (no anulados) */
    public function scopePrestamoPendiente(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_PRESTAMO)->whereNull('deleted_at');
    }

    // ── Helpers ──────────────────────────────────────────────────────
    /** Total ya abonado a esta factura */
    public function getTotalAbonadoAttribute(): int
    {
        // Usar $this->abonos (sin paréntesis) para aprovechar el eager loading.
        // Con paréntesis dispararía una nueva SQL query por cada Factura.
        return (int) $this->abonos->sum('valor');
    }

    /** Saldo restante para completar el pago */
    public function getSaldoRestanteAttribute(): int
    {
        return max(0, (int)$this->total - $this->total_abonado);
    }

    /** ¿Está completamente pagada? */
    public function estaCompletamentePagada(): bool
    {
        // Considera el pago inicial (valor_consignado + valor_efectivo + anticipo_aplicado)
        // y los abonos posteriores registrados en la tabla abonos.
        $pagadoAlFacturar = (int)$this->valor_consignado
                          + (int)$this->valor_efectivo
                          + (int)$this->anticipo_aplicado; // ← anticipo ya contabilizado al facturar
        return ($pagadoAlFacturar + $this->total_abonado) >= (int)$this->total;
    }

    /**
     * Saldo pendiente del préstamo = abs(saldo_proximo) - sum(abonos_posteriores).
     * Solo relevante cuando estado = 'prestamo'.
     *
     * IMPORTANTE: Usar saldo_proximo (NO total - valor_efectivo - valor_consignado)
     * porque saldo_proximo fue calculado correctamente al facturar con la fórmula:
     *   saldo_proximo = pagadoReal - total
     *   donde pagadoReal = efectivo + consignado + anticipo (todos los pagos al facturar)
     *
     * En lotes empresa, valor_efectivo puede no estar bien distribuido en
     * todas las filas (problema de datos históricos), pero saldo_proximo siempre
     * refleja la deuda real de esa fila al momento de facturar.
     */
    public function getSaldoPendientePrestamoAttribute(): int
    {
        // abs(saldo_proximo): si saldo_proximo = -846, la fila debía $846 al facturar.
        // Si saldo_proximo = 0 o positivo, la fila estaba pagada al facturar → 0.
        $saldoOriginal = max(0, -(float)($this->saldo_proximo ?? 0));
        return max(0, (int)$saldoOriginal - $this->total_abonado);
    }

    /**
     * Lo que debe un lote de empresa (las filas de un mismo numero_factura),
     * antes de descontar los abonos. Acepta modelos o arreglos.
     *
     * Manda la deuda más grande entre lo pactado (`valor_prestamo`) y lo que
     * las facturas quedaron debiendo (`saldo_proximo`): en 17 lotes lo pactado
     * quedó por debajo, y con la resta contra `valor_prestamo` un abono los
     * dejaba en cero con deuda viva. Una sola regla para el listado, el
     * detalle, el abono, la cartera al facturar y los informes: cuando cada
     * pantalla usaba la suya, EL EMBAL salía debiendo $549.200 en el listado y
     * saldado al abrirlo.
     */
    public static function deudaLote(iterable $lote): int
    {
        $lote = collect($lote);

        return max((int) $lote->sum('valor_prestamo'), abs((int) $lote->sum('saldo_proximo')));
    }

    /** Genera el siguiente número de factura para un aliado */
    public static function siguienteNumero(int $aliadoId): int
    {
        $seq = DB::table('factura_secuencias')
            ->where('aliado_id', $aliadoId)
            ->lockForUpdate()
            ->first();

        if ($seq) {
            $nuevo = $seq->ultimo_numero + 1;
            DB::table('factura_secuencias')
                ->where('aliado_id', $aliadoId)
                ->update(['ultimo_numero' => $nuevo]);
        } else {
            $nuevo = 1;
            DB::table('factura_secuencias')
                ->insert(['aliado_id' => $aliadoId, 'ultimo_numero' => 1]);
        }

        return $nuevo;
    }

    /** Saldo acumulativo del cliente (SUMA de todos los saldo_proximo anteriores).
     *  Formula: Abril +350k + Mayo -350k = 0 para Junio.
     *  Excluye: soft-deleted (anuladas), facturas sin saldo_proximo, pre_facturas.
     */
    public static function saldoClienteMesPrevio(int $aliadoId, int $cedula, int $mes, int $anio, ?int $contratoId = null): array
    {
        $query = static::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->where(fn($q) => $q->where('anio', '<', $anio)
                ->orWhere(fn($q2) => $q2->where('anio', $anio)->where('mes', '<', $mes)))
            ->whereNotNull('saldo_proximo')
            ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
            // Solo facturas individuales (sin empresa): las facturas de empresa
            // tienen su propio saldo calculado por empresa_id en saldosContratos().
            ->whereNull('empresa_id');

        // Filtrar por contrato específico si se proporciona
        // Esto evita mezclar saldos de contratos distintos del mismo cliente
        if ($contratoId !== null) {
            $query->where('contrato_id', $contratoId);
        }

        $suma = (int) $query->sum('saldo_proximo');

        // El crédito que el aliado ya dio por consumido no se le ofrece otra vez
        // al cliente (ver SaldoAjuste). Solo baja saldos a favor: un ajuste nunca
        // convierte un crédito en deuda.
        if ($suma > 0) {
            $suma = max(0, $suma - SaldoAjuste::totalDe($aliadoId, $cedula));
        }

        return [
            'a_favor'   => $suma > 0 ? $suma : 0,
            'pendiente' => $suma < 0 ? abs($suma) : 0,
        ];
    }

    /** Etiqueta de estado para UI */
    public function getEtiquetaEstadoAttribute(): string
    {
        return match($this->estado) {
            self::ESTADO_PRE      => 'Pre-factura',
            self::ESTADO_ABONO    => 'Abono',
            self::ESTADO_PAGADA   => 'Pagada',
            self::ESTADO_PRESTAMO => 'Préstamo',
            default               => ucfirst($this->estado),
        };
    }

    public function getBadgeColorAttribute(): string
    {
        return match($this->estado) {
            self::ESTADO_PAGADA   => '#16a34a',
            self::ESTADO_ABONO    => '#d97706',
            self::ESTADO_PRESTAMO => '#7c3aed',
            default               => '#64748b',
        };
    }
}
