<?php

namespace App\Models;

/**
 * Documento personal de un representante legal (cédula, RUT personal). El
 * archivo vive en el disco `local`; descargarlo deja rastro en la bitácora.
 */
class BrynexRepresentanteDocumento extends BaseModel
{
    protected $table = 'brynex_representante_documentos';

    protected $fillable = [
        'representante_id', 'tipo', 'nombre_original', 'ruta', 'mime', 'tamano',
        'observacion', 'subido_por',
    ];

    public const TIPOS = [
        'CEDULA' => 'Cédula',
        'RUT' => 'RUT personal',
        'OTRO' => 'Otro',
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
