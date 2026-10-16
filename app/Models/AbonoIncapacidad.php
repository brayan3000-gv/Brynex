<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gestiona el flujo financiero de cada incapacidad.
 *
 * Tipos:
 *   'abono'        → Préstamo/anticipo del aliado al cliente (dinero personal/caja).
 *                    INFORMATIVO: no descuenta el saldo_pendiente.
 *                    Indica al aliado cuánto dinero personal tiene comprometido
 *                    y cuánto debe recuperar cuando la EPS pague.
 *
 *   'entrada_incapacidad' → Lo que la EPS/ARL/AFP consignó a la Razón Social.
 *                           SÍ descuenta el saldo_pendiente.
 *                           Genera un registro paralelo en consignaciones.
 *                           Aparece en Canal 5 del informe financiero.
 *
 *   'pago_cliente' → Lo que el aliado transfirió al cliente o empresa.
 *                    SÍ descuenta el saldo_pendiente.
 *                    Salió plata de la caja del aliado: aparece en Canal 5.
 *
 *   'pago_directo_entidad' → Lo que la EPS/ARL/AFP le consignó al afiliado sin
 *                    pasar por la razón social (típico tras derecho de petición
 *                    o tutela). SÍ descuenta el saldo_pendiente: ya no hay plata
 *                    por entrar. NO aparece en Canal 5 — no hubo ni entrada ni
 *                    salida de la caja del aliado. Registrarlo como
 *                    'entrada_incapacidad' infla el informe financiero.
 *
 * Fórmulas (calculadas en Incapacidad model):
 *   saldo_pendiente = valor_esperado - SUM(entrada_incapacidad) - SUM(pago_cliente)
 *                                    - SUM(pago_directo_entidad)
 *   total_prestado  = SUM(abono)   ← informativo
 */
class AbonoIncapacidad extends BaseModel
{
    protected $table = 'abonos_incapacidades';

    protected $fillable = [
        'aliado_id',
        'incapacidad_id',
        'razon_social_id',
        'tipo',
        'forma_recepcion',
        'valor',
        'fecha',
        'banco_cuenta_id',
        'consignacion_id',
        'odi_id',
        'usuario_id',
        'observacion',
        'imagen_path',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    // ── Catálogos ─────────────────────────────────────────────────────────────

    const TIPOS = [
        'abono'                => ['label' => '💵 Préstamo/Anticipo del Aliado', 'descuenta' => false, 'color' => 'warning'],
        'entrada_incapacidad'  => ['label' => '📥 Entradas Incapacidad',          'descuenta' => true,  'color' => 'info'],
        'pago_cliente'         => ['label' => '💸 Pago al Cliente/Empresa',       'descuenta' => true,  'color' => 'success'],
        'pago_directo_entidad' => ['label' => '🏛️ Pago directo de la entidad',   'descuenta' => true,  'color' => 'secondary'],
    ];

    /** Tipos que cierran el saldo por cobrar a la EPS/ARL/AFP. */
    const TIPOS_DESCUENTAN = ['entrada_incapacidad', 'pago_cliente', 'pago_directo_entidad'];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function incapacidad(): BelongsTo
    {
        return $this->belongsTo(Incapacidad::class);
    }

    public function bancoCuenta(): BelongsTo
    {
        return $this->belongsTo(BancoCuenta::class, 'banco_cuenta_id');
    }

    public function consignacion(): BelongsTo
    {
        return $this->belongsTo(Consignacion::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    // ── Helpers UI ────────────────────────────────────────────────────────────

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo]['label'] ?? ucfirst($this->tipo);
    }

    public function tipoColor(): string
    {
        return self::TIPOS[$this->tipo]['color'] ?? 'secondary';
    }

    /** ¿Este tipo descuenta el saldo_pendiente de la incapacidad? */
    public function descuentaSaldo(): bool
    {
        return self::TIPOS[$this->tipo]['descuenta'] ?? false;
    }
}
