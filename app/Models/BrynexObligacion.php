<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un renglón del checklist: "el anticipo bimestral 1 de 2026 de ELITES".
 *
 * `fecha_vencimiento` puede venir en null: son los años anteriores al
 * calendario cargado. Esos renglones existen para poder ponerse al día
 * (chulear y subir el soporte) pero quedan fuera del semáforo y de las
 * alertas, porque no hay fecha confiable contra la cual medirlos.
 */
class BrynexObligacion extends BaseModel
{
    use SoftDeletes;

    protected $table = 'brynex_obligaciones';

    protected $fillable = [
        'ficha_id', 'anio', 'obligacion_codigo', 'periodo', 'periodo_etiqueta',
        'fecha_vencimiento', 'estado', 'valor_pagado', 'fecha_pago',
        'observacion', 'usuario_id',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'fecha_pago' => 'date',
        'valor_pagado' => 'decimal:2',
    ];

    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'presentada' => 'Presentada',
        'pagada' => 'Pagada',
        'no_aplica' => 'No aplica',
    ];

    /** Los estados que cierran el renglón: no vencen ni alertan. */
    public const ESTADOS_CERRADOS = ['pagada', 'no_aplica'];

    public function ficha()
    {
        return $this->belongsTo(BrynexRazonSocial::class, 'ficha_id');
    }

    public function documentos()
    {
        return $this->hasMany(BrynexObligacionDocumento::class, 'obligacion_id');
    }

    public function catalogo()
    {
        return $this->belongsTo(
            BrynexObligacionCatalogo::class, 'obligacion_codigo', 'codigo'
        );
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * Vencidas: pasó la fecha y sigue sin presentarse ni pagarse.
     *
     * Las columnas van calificadas con la tabla porque el tablero usa estos
     * scopes sobre un join con `brynex_razones_sociales`, que también tiene
     * una columna `estado`: sin calificar, SQL Server responde
     * «Ambiguous column name».
     */
    public function scopeVencidas($query)
    {
        $t = $this->getTable();

        return $query->whereNotNull("{$t}.fecha_vencimiento")
            ->where("{$t}.fecha_vencimiento", '<', now()->toDateString())
            ->whereNotIn("{$t}.estado", self::ESTADOS_CERRADOS)
            ->where("{$t}.estado", '!=', 'presentada');
    }

    public function scopePorVencer($query, int $dias = 15)
    {
        $t = $this->getTable();

        return $query->whereNotNull("{$t}.fecha_vencimiento")
            ->whereBetween("{$t}.fecha_vencimiento", [
                now()->toDateString(),
                now()->addDays($dias)->toDateString(),
            ])
            ->whereNotIn("{$t}.estado", self::ESTADOS_CERRADOS)
            // Presentada en cero ya está cumplida: no hay nada por vencer.
            ->where(fn ($q) => $q->where("{$t}.estado", '!=', 'presentada')
                ->orWhereNull("{$t}.valor_pagado")
                ->orWhere("{$t}.valor_pagado", '>', 0));
    }

    // ─── Semáforo ─────────────────────────────────────────────────────

    /**
     * Presentada sin nada que pagar: un 2593 o un 260 en cero. Se marca
     * dejándola «Presentada» con valor pagado 0 (no vacío: vacío es «aún no
     * se sabe»), y queda cumplida igual que una pagada.
     */
    public function presentadaEnCero(): bool
    {
        return $this->estado === 'presentada'
            && $this->valor_pagado !== null
            && (float) $this->valor_pagado === 0.0;
    }

    /** Cumplida: pagada, no aplica o presentada en cero. */
    public function cerrada(): bool
    {
        return in_array($this->estado, self::ESTADOS_CERRADOS, true) || $this->presentadaEnCero();
    }

    /** verde | amarillo | rojo | gris */
    public function semaforo(): string
    {
        if ($this->cerrada()) {
            return 'verde';
        }

        // Sin fecha (años viejos) no se puede juzgar: queda gris.
        if (! $this->fecha_vencimiento) {
            return 'gris';
        }

        $hoy = Carbon::today();

        if ($this->fecha_vencimiento->lt($hoy)) {
            return $this->estado === 'presentada' ? 'amarillo' : 'rojo';
        }

        return $this->fecha_vencimiento->diffInDays($hoy) <= 15 ? 'amarillo' : 'verde';
    }

    public function diasParaVencer(): ?int
    {
        if (! $this->fecha_vencimiento) {
            return null;
        }

        return Carbon::today()->diffInDays($this->fecha_vencimiento, false);
    }
}
