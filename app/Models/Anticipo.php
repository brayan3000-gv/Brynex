<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Anticipo extends BaseModel
{
    use SoftDeletes;
    protected $table    = 'anticipos';
    protected $fillable = [
        'aliado_id', 'cedula', 'contrato_id', 'empresa_id',
        'fecha_pago', 'valor', 'valor_aplicado',
        'forma_pago', 'banco_cuenta_id', 'referencia', 'observacion',
        'motivo_anulacion', 'anulado_por',
        'estado', 'factura_id', 'usuario_id',
        'origen', 'anticipo_padre_id', 'periodo_mes', 'periodo_anio',
    ];

    protected $casts = [
        'fecha_pago' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($anticipo) {
            // Si es un anticipo maestro, borrar todos sus hijos de forma manual para evitar ciclos en SQL Server
            if ($anticipo->estado === self::ESTADO_DISTRIBUIDO) {
                $anticipo->hijos()->delete();
            }
        });
    }

    // ── Estados ────────────────────────────────────────────────────────
    const ESTADO_DISPONIBLE  = 'disponible';
    const ESTADO_PARCIAL     = 'parcial';
    const ESTADO_APLICADO    = 'aplicado';
    const ESTADO_DEVUELTO    = 'devuelto';
    const ESTADO_DISTRIBUIDO = 'distribuido';
    const ESTADO_ANULADO     = 'anulado';   // anulación lógica (soft delete)

    // ── Formas de pago aceptadas ─────────────────────────────────────────────────────
    const FORMAS_PAGO = [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        // Mantenidos por compatibilidad con registros históricos:
        'nequi'         => 'Nequi',
        'consignacion'  => 'Consignación',
    ];

    // ── Relaciones ────────────────────────────────────────────────────
    public function contrato()    { return $this->belongsTo(Contrato::class); }
    // La cédula se repite entre aliados: sin el aliado en la relación, un with('cliente')
    // trae el cliente de cualquiera. Ver App\Models\Relations\BelongsToDelAliado.
    public function cliente()     { return $this->belongsToDelAliado(Cliente::class, 'cedula', 'cedula', 'cliente'); }
    public function empresa()     { return $this->belongsTo(Empresa::class); }
    public function factura()     { return $this->belongsTo(Factura::class); }
    public function usuario()     { return $this->belongsTo(User::class, 'usuario_id'); }
    public function bancoCuenta() { return $this->belongsTo(BancoCuenta::class, 'banco_cuenta_id'); }
    public function padre()       { return $this->belongsTo(Anticipo::class, 'anticipo_padre_id'); }
    public function hijos()       { return $this->hasMany(Anticipo::class, 'anticipo_padre_id'); }

    // ── Scopes ────────────────────────────────────────────────────────

    /** Anticipos con saldo disponible (no totalmente aplicados) */
    public function scopeConSaldo($q)
    {
        return $q->whereIn('estado', [self::ESTADO_DISPONIBLE, self::ESTADO_PARCIAL]);
    }

    /** Anticipos de un aliado */
    public function scopeAliado($q, int $aliadoId)
    {
        return $q->where('aliado_id', $aliadoId);
    }

    /** Anticipos de un contrato específico */
    public function scopePorContrato($q, int $contratoId)
    {
        return $q->where('contrato_id', $contratoId);
    }

    /** Anticipos de una empresa específica */
    public function scopePorEmpresa($q, int $empresaId)
    {
        return $q->where('empresa_id', $empresaId);
    }

    /**
     * Todos los anticipos de un cliente (por cédula):
     * incluye individuales (contrato_id) y los distribuidos por empresa (cedula directa).
     * Incluye también los anulados (withTrashed) para historial completo.
     */
    public function scopePorCliente($q, string $cedula)
    {
        return $q->withTrashed()->where('cedula', $cedula);
    }

    /** Anticipos asociados a contratos de clientes que pertenecen a una empresa específica */
    public function scopePorContratoDeEmpresa($q, int $aliadoId, int $empresaId)
    {
        return $q->where('aliado_id', $aliadoId)
            ->whereNotNull('contrato_id')
            ->whereIn('contrato_id', function ($query) use ($empresaId) {
                $query->select('contratos.id')
                    ->from('contratos')
                    ->join('clientes', 'clientes.cedula', '=', 'contratos.cedula')
                    ->where('clientes.cod_empresa', $empresaId);
            });
    }

    // ── Helpers / Atributos calculados ────────────────────────────────

    /** Cuánto dinero queda disponible de este anticipo */
    public function getValorDisponibleAttribute(): int
    {
        return max(0, (int)$this->valor - (int)$this->valor_aplicado);
    }

    /** ¿Tiene aún saldo disponible? */
    public function tieneSaldo(): bool
    {
        return $this->valor_disponible > 0;
    }

    /**
     * Descripción legible del anticipo para el recibo/PDF.
     * Ejemplo: "Anticipo (Nequi, 15-abr-2026, ref: 3421xxx)"
     */
    public function getDescripcionPagoAttribute(): string
    {
        $fecha = $this->fecha_pago->format('d-M-Y');
        $forma = self::FORMAS_PAGO[$this->forma_pago] ?? ucfirst($this->forma_pago);
        $ref   = $this->referencia ? ", ref: {$this->referencia}" : '';
        return "Anticipo ({$forma}, {$fecha}{$ref})";
    }

    /** Etiqueta del estado para UI */
    public function getEtiquetaEstadoAttribute(): string
    {
        if ($this->trashed()) return 'Anulado';
        return match($this->estado) {
            self::ESTADO_DISPONIBLE  => 'Disponible',
            self::ESTADO_PARCIAL     => 'Parcial',
            self::ESTADO_APLICADO    => 'Aplicado',
            self::ESTADO_DEVUELTO    => 'Devuelto',
            self::ESTADO_DISTRIBUIDO => 'Distribuido',
            default                  => ucfirst($this->estado),
        };
    }

    /** Color del badge de estado */
    public function getBadgeColorAttribute(): string
    {
        if ($this->trashed()) return '#7f1d1d'; // rojo oscuro = anulado
        return match($this->estado) {
            self::ESTADO_DISPONIBLE  => '#16a34a', // verde
            self::ESTADO_PARCIAL     => '#d97706', // ámbar
            self::ESTADO_APLICADO    => '#64748b', // gris
            self::ESTADO_DEVUELTO    => '#dc2626', // rojo
            self::ESTADO_DISTRIBUIDO => '#1d4ed8', // azul
            default                  => '#64748b',
        };
    }

    /**
     * ¿Puede este anticipo ser anulado?
     * Solo si está disponible y no se ha aplicado nada.
     * (Si la factura fue anulada, el anticipo ya vuelve a disponible con valor_aplicado=0
     *  automáticamente — ver FacturacionController::anular())
     */
    public function puedeAnularse(): bool
    {
        return !$this->trashed()
            && $this->estado === self::ESTADO_DISPONIBLE
            && (int)$this->valor_aplicado === 0;
    }

    // ── Métodos estáticos de consulta ─────────────────────────────────

    /**
     * Suma total disponible para un contrato o empresa.
     * Usa SELECT SUM(valor - valor_aplicado) para evitar cargar todos los registros.
     */
    public static function saldoDisponible(
        int $aliadoId,
        ?int $contratoId = null,
        ?int $empresaId  = null
    ): int {
        $q = static::aliado($aliadoId)->conSaldo();
        if ($contratoId) $q->porContrato($contratoId);
        if ($empresaId)  $q->porEmpresa($empresaId);
        return (int) $q->sum(DB::raw('valor - valor_aplicado'));
    }

    /**
     * Anticipos que puede consumir la factura de un contrato (modal facturar
     * y cobro en visita).
     *
     * El anticipo es de quien pagó —la cédula—, no del contrato donde se
     * registró: entran los de la persona en cualquiera de sus contratos,
     * primero los del mismo contrato. También las partes de un anticipo de
     * empresa ya repartidas a esa cédula. Decisión del dueño, 28-sep-2026:
     * antes una persona con dos contratos veía su anticipo en uno y en el
     * otro le cobraban completo.
     */
    public static function disponiblesParaContrato(int $aliadoId, int $contratoId): \Illuminate\Support\Collection
    {
        $cedula = Contrato::where('aliado_id', $aliadoId)->whereKey($contratoId)->value('cedula');

        return static::aliado($aliadoId)
            ->conSaldo()
            ->where('estado', '!=', self::ESTADO_DISTRIBUIDO)
            ->where(fn ($q) => $q->where('contrato_id', $contratoId)
                ->when($cedula, fn ($q2) => $q2->orWhere('cedula', (string) $cedula)))
            ->orderByRaw('CASE WHEN contrato_id = ? THEN 0 ELSE 1 END', [$contratoId])
            ->orderBy('fecha_pago')
            ->get();
    }

    /**
     * Anticipos disponibles para una empresa (lista completa para el modal facturar).
     */
    public static function disponiblesParaEmpresa(int $aliadoId, int $empresaId): \Illuminate\Support\Collection
    {
        return static::aliado($aliadoId)
            ->porEmpresa($empresaId)
            ->conSaldo()
            ->with('contrato.cliente') // el detalle muestra a quién pertenece cada anticipo
            ->orderBy('fecha_pago')
            ->get();
    }

    /**
     * Anticipos disponibles para un contrato específico y los anticipos de su empresa sin asignar a contratos
     */
    public static function disponiblesParaContratoYEmpresa(int $aliadoId, int $contratoId): \Illuminate\Support\Collection
    {
        // Obtener empresa_id a partir del contrato
        $empresaId = DB::table('contratos')
            ->join('clientes', 'clientes.cedula', '=', 'contratos.cedula')
            ->where('contratos.id', $contratoId)
            ->value('clientes.cod_empresa');

        return static::aliado($aliadoId)
            ->conSaldo()
            ->where(function ($q) use ($contratoId, $empresaId) {
                // Individuales del contrato
                $q->where('contrato_id', $contratoId);
                
                // O colectivos/de empresa sin asignar a un contrato individual y que no sean maestros distribuidos
                if ($empresaId) {
                    $q->orWhere(function ($sub) use ($empresaId) {
                        $sub->where('empresa_id', $empresaId)
                            ->whereNull('contrato_id')
                            ->where('estado', '!=', self::ESTADO_DISTRIBUIDO);
                    });
                }
            })
            ->orderBy('fecha_pago')
            ->get();
    }

    /**
     * Calcula mes y año informativos recomendados para el período destino de un anticipo.
     * Es el mes inmediatamente posterior al de la última factura registrada para el contrato.
     */
    public static function periodoDestinoInfo(int $contratoId): array
    {
        $ultimaFactura = Factura::where('contrato_id', $contratoId)
            ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->first(['mes', 'anio']);

        if (!$ultimaFactura) {
            $contrato = Contrato::find($contratoId);
            if ($contrato && $contrato->fecha_ingreso) {
                $carbon = \Carbon\Carbon::parse($contrato->fecha_ingreso);
                return [
                    'mes'  => $carbon->month,
                    'anio' => $carbon->year
                ];
            }
            return [
                'mes'  => (int) now()->month,
                'anio' => (int) now()->year
            ];
        }

        $mes  = (int) $ultimaFactura->mes;
        $anio = (int) $ultimaFactura->anio;
        
        $sigMes  = $mes === 12 ? 1 : $mes + 1;
        $sigAnio = $mes === 12 ? $anio + 1 : $anio;

        return [
            'mes'  => $sigMes,
            'anio' => $sigAnio
        ];
    }

    /**
     * Todos los anticipos aplicados a una factura (para el recibo/PDF).
     */
    public static function paraFactura(int $facturaId): \Illuminate\Support\Collection
    {
        return static::where('factura_id', $facturaId)
            ->orderBy('fecha_pago')
            ->get();
    }

    /**
     * Aplica este anticipo (parcial o total) a una factura.
     * Devuelve cuánto se aplicó realmente.
     *
     * @param  int $facturaId
     * @param  int $montoAplicar  Monto a aplicar (máx = valor_disponible)
     * @return int Monto efectivamente aplicado
     */
    public function aplicarAFactura(int $facturaId, int $montoAplicar): int
    {
        $aplicar       = min($this->valor_disponible, max(0, $montoAplicar));
        if ($aplicar <= 0) return 0;

        $nuevoAplicado = (int)$this->valor_aplicado + $aplicar;
        $nuevoEstado   = $nuevoAplicado >= (int)$this->valor
            ? self::ESTADO_APLICADO
            : self::ESTADO_PARCIAL;

        $this->update([
            'valor_aplicado' => $nuevoAplicado,
            'estado'         => $nuevoEstado,
            'factura_id'     => $facturaId,
        ]);

        return $aplicar;
    }
}
