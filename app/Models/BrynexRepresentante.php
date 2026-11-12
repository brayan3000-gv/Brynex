<?php

namespace App\Models;

/**
 * Representante legal de una o varias razones sociales de BryNex. Una fila por
 * persona (cédula): sus claves personales sirven para todas sus empresas.
 *
 * Qué empresas representa no se guarda aquí; lo calcula
 * `BrynexRepresentanteService::agrupar()` desde `razones_sociales.cedula_rep`.
 */
class BrynexRepresentante extends BaseModel
{
    protected $table = 'brynex_representantes';

    protected $fillable = [
        'tipo_documento', 'cedula', 'nombre', 'correo_rut', 'telefono', 'notas',
        'creado_por', 'actualizado_por',
    ];

    public const TIPOS_DOCUMENTO = [
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'PT' => 'Permiso por protección temporal',
        'PA' => 'Pasaporte',
    ];

    public function credenciales()
    {
        return $this->hasMany(BrynexRepresentanteCredencial::class, 'representante_id');
    }
}
