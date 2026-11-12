<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Clave personal de un representante legal (correo, DIAN como persona
 * natural, usuario autorizado de facturación electrónica…). Cifrada, fuera
 * de cualquier JSON, y se revela una a una dejando rastro en la bitácora.
 */
class BrynexRepresentanteCredencial extends BaseModel
{
    use SoftDeletes;

    protected $table = 'brynex_representante_credenciales';

    protected $fillable = [
        'representante_id', 'tipo', 'entidad', 'link_acceso', 'usuario',
        'contrasena', 'observacion', 'activo', 'creado_por', 'actualizado_por',
    ];

    /** Nunca serializar el secreto hacia una respuesta JSON o una vista. */
    protected $hidden = ['contrasena'];

    protected $casts = [
        'contrasena' => 'encrypted',
        'activo' => 'boolean',
    ];

    public const TIPOS = [
        'CORREO' => '📧 Correo',
        'DIAN' => '🏛️ DIAN (persona natural)',
        'FACTURACION' => '🧾 Facturación electrónica',
        'FIRMA' => '✍️ Firma electrónica',
        'OTRO' => '🔗 Otro portal',
    ];

    /** Lo que se propone en «Portal / entidad» al escoger el tipo. */
    public const ENTIDAD_SUGERIDA = [
        'CORREO' => 'Gmail',
        'DIAN' => 'DIAN MUISCA',
        'FACTURACION' => 'DIAN Facturación electrónica',
        'FIRMA' => 'Firma electrónica DIAN',
        'OTRO' => '',
    ];

    public function representante()
    {
        return $this->belongsTo(BrynexRepresentante::class, 'representante_id');
    }

    public function tipoEtiqueta(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
