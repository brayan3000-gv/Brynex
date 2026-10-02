<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Facades\DB;

/**
 * Modelo Cliente - Tabla local 'clientes' en BryNex
 * (Migrada desde [Brygar_BD].[dbo].[Base_De_Datos])
 */
class Cliente extends BaseModel
{
    protected $table      = 'clientes';
    protected $primaryKey = 'id';
    public $incrementing  = false;
    public $timestamps    = true;

    /** Almacena el diff de auditoría entre updating() y updated() — nunca se persiste en BD */
    public array $_diffAudit = [];

    protected $fillable = [
        'id', 'aliado_id', 'cod_empresa', 'tipo_doc', 'cedula',
        'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido',
        'genero', 'sisben',
        'fecha_nacimiento', 'fecha_expedicion', 'rh',
        'telefono', 'celular', 'correo',
        'departamento_id', 'municipio_id',
        'direccion_vivienda', 'direccion_cobro', 'barrio',
        'eps_id', 'pension_id',
        'operador_planilla_id', // operador PILA asignado (solo para RS independientes)
        'ips', 'urgencias', 'iva',
        'ocupacion', 'referido', 'observacion',
        'observacion_llamada', 'claves', 'datos',
        'deuda', 'fecha_probable_pago', 'modo_probable_pago',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_expedicion' => 'date',
        'operador_planilla_id' => 'integer',
    ];

    // ─── Relaciones ──────────────────────────────────────────────────

    public function eps()
    {
        return $this->belongsTo(Eps::class, 'eps_id');
    }

    public function pension()
    {
        return $this->belongsTo(Pension::class, 'pension_id');
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function municipio()
    {
        return $this->belongsTo(Ciudad::class, 'municipio_id');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'cod_empresa');
    }

    // ─── Atributos calculados ────────────────────────────────────────

    public function getNombreCompletoAttribute(): string
    {
        return trim(
            ($this->primer_nombre ?? '') . ' ' .
            ($this->segundo_nombre ?? '') . ' ' .
            ($this->primer_apellido ?? '') . ' ' .
            ($this->segundo_apellido ?? '')
        );
    }

    public function getNombreCortoAttribute(): string
    {
        return trim(
            ($this->primer_nombre ?? '') . ' ' .
            ($this->primer_apellido ?? '')
        );
    }

    public function getEdadAttribute(): ?int
    {
        if (!$this->fecha_nacimiento) return null;
        return $this->fecha_nacimiento->age;
    }

    /**
     * Tipos de documento válidos para un cliente. Un cliente es siempre una
     * persona natural, así que NIT/NI no van acá: el NIT es de la empresa o de
     * la razón social, nunca del afiliado. Fuente única para el formulario, el
     * cotizador y la validación.
     */
    public const TIPOS_DOC = [
        'CC' => 'CC - Cédula de Ciudadanía',
        'TI' => 'TI - Tarjeta de Identidad',
        'CE' => 'CE - Cédula de Extranjería',
        'PA' => 'PA - Pasaporte',
        'PT' => 'PT - Permiso de Protección Temporal',
        'PE' => 'PE - Permiso Especial de Permanencia',
    ];

    /** Documentos cuyo titular puede omitir el aporte a pensión. */
    public const DOCS_EXENTOS_AFP = ['CE', 'PT', 'PP', 'PE', 'PA'];

    /**
     * ¿Este cliente puede omitir el aporte a pensión? Fuente única para el cotizador admin,
     * la web y la IA. Devuelve el motivo (o null si no está exento) para poder explicarlo:
     *   - Ya pensionado (fondo "PENSIONADO" en su ficha): sin importar edad, género ni documento
     *   - Documento CE / PT / PP / PE / PA
     *   - Hombre desde 55 años | Mujer desde 50 años
     */
    public function motivoExencionAfp(): ?string
    {
        if ((int) ($this->pension_id ?? 0) === Pension::ID_PENSIONADO) {
            return 'ya está pensionado';
        }

        $tipoDoc = strtoupper(trim($this->tipo_doc ?? ''));
        if (in_array($tipoDoc, self::DOCS_EXENTOS_AFP, true)) {
            return "documento {$tipoDoc}";
        }

        $edad = $this->edad;
        if ($edad === null) {
            return null;
        }

        $genero = strtoupper(trim($this->genero ?? ''));
        if ($genero === 'M' && $edad >= 55) {
            return "hombre de {$edad} años";
        }
        if ($genero === 'F' && $edad >= 50) {
            return "mujer de {$edad} años";
        }

        return null;
    }

    public function esExentoAfp(): bool
    {
        return $this->motivoExencionAfp() !== null;
    }

    public function getEpsNombreAttribute(): string
    {
        return $this->eps?->nombre ?? '—';
    }

    public function getPensionNombreAttribute(): string
    {
        return $this->pension?->razon_social ?? '—';
    }

    // ─── Contratos del cliente (por cédula) ──────────────────────────

    public function contratos()
    {
        return DB::table('contratos')
            ->where('cedula', $this->cedula)
            ->orderByDesc('fecha_ingreso')
            ->get();
    }

    public function beneficiarios()
    {
        return $this->hasMany(Beneficiario::class, 'cc_cliente', 'cedula');
    }

    public function documentos()
    {
        return $this->hasMany(DocumentoCliente::class, 'cc_cliente', 'cedula');
    }

    // ─── Lookup helpers estáticos ────────────────────────────────────

    public static function listaEps(): array
    {
        return DB::table('eps')
            ->whereNull('reemplazada_por_id')
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->toArray();
    }

    public static function listaPension(): array
    {
        return DB::table('pensiones')
            ->orderBy('razon_social')
            ->pluck('razon_social', 'id')
            ->toArray();
    }

    public static function listaRazonSocial(): array
    {
        return DB::table('razones_sociales')
            ->where('estado', 'Activa')
            ->orderBy('razon_social')
            ->pluck('razon_social', 'id')
            ->toArray();
    }

    /**
     * Asesores del aliado activo (id => nombre). Cada aliado ve solo los suyos;
     * si no hay aliado en sesión (comandos), la lista va vacía.
     */
    public static function listaAsesores(?int $aliadoId = null): array
    {
        $aliadoId = $aliadoId ?? (int) session('aliado_id_activo');
        if (! $aliadoId) {
            return [];
        }

        return DB::table('asesores')
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->toArray();
    }
}
