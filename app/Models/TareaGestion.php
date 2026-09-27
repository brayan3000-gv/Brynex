<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TareaGestion extends BaseModel
{
    public $timestamps = false;
    protected $table = 'tarea_gestiones';

    protected $fillable = [
        'tarea_id', 'user_id', 'tipo_accion',
        'observacion', 'recordar_dias', 'fecha_alerta',
        'encargado_anterior', 'encargado_nuevo', 'estado_tarea',
        // La ve la empresa en su portal como un avance. Ver EmpresaSolicitud.
        'visible_empresa',
    ];

    protected $casts = [
        'created_at'  => 'datetime',
        'fecha_alerta'=> 'date',
        'visible_empresa' => 'boolean',
    ];

    const TIPOS_ACCION = [
        'tramite_realizado' => '📋 Trámite realizado',
        'traslado'          => '🔀 Traslado',
        'cambio_estado'     => '🔄 Cambio de estado',
        'nota'              => '📝 Nota',
    ];

    public function tarea(): BelongsTo
    {
        return $this->belongsTo(Tarea::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function encargadoAnterior(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_anterior');
    }

    public function encargadoNuevo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_nuevo');
    }
}
