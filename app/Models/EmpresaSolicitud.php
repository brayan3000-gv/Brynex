<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que una empresa pide desde su portal: un ingreso, un retiro, una
 * incapacidad u otra cosa. Nunca se ejecuta sola: abre una tarea y el equipo
 * la resuelve (ver EmpresaSolicitudService).
 *
 * `datos` guarda lo que llenó la empresa tal cual; `datos.archivos` lista los
 * adjuntos, que están en el disco `local`.
 */
class EmpresaSolicitud extends BaseModel
{
    protected $table = 'empresa_solicitudes';

    public const TIPOS = [
        'ingreso' => 'Ingreso de trabajador',
        'retiro' => 'Retiro de trabajador',
        'incapacidad' => 'Incapacidad',
        'otra' => 'Otra solicitud',
    ];

    /** La tarea que abre cada tipo. */
    public const TIPO_TAREA = [
        'ingreso' => 'portal_ingreso',
        'retiro' => 'portal_retiro',
        'incapacidad' => 'portal_incapacidad',
        'otra' => 'portal_solicitud',
    ];

    public const ESTADOS = [
        'pendiente' => 'En revisión',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
    ];

    protected $fillable = [
        'aliado_id', 'empresa_id', 'empresa_acceso_id', 'tarea_id',
        'tipo', 'estado', 'tipo_doc', 'cedula', 'contrato_id',
        'datos', 'ruaf', 'resultado_id',
        'vista_at', 'atendida_por', 'atendida_at',
    ];

    protected $casts = [
        'datos' => 'array',
        'ruaf' => 'array',
        'vista_at' => 'datetime',
        'atendida_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function tarea(): BelongsTo
    {
        return $this->belongsTo(Tarea::class, 'tarea_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? ucfirst($this->tipo);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    /** El adjunto número $i de la solicitud, o null. */
    public function archivo(int $i): ?array
    {
        return $this->datos['archivos'][$i] ?? null;
    }
}
