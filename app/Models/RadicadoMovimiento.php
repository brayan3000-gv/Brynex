<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class RadicadoMovimiento extends BaseModel
{
    public $timestamps = false;

    protected $table = 'radicado_movimientos';

    protected $fillable = [
        'radicado_id',
        'contrato_id',
        'tipo_proceso',
        'entidad',
        'user_id',
        'estado_anterior',
        'estado_nuevo',
        'observacion',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Tipos de proceso ──
    const TIPO_AFILIACION            = 'afiliacion';
    const TIPO_INCAPACIDAD           = 'incapacidad';
    const TIPO_TUTELA                = 'tutela';
    const TIPO_DERECHO_PETICION      = 'derecho_peticion';
    const TIPO_INCLUSION_BENEFICIARIO= 'inclusion_beneficiario';
    const TIPO_TRASLADO_EPS          = 'traslado_eps';
    const TIPO_OTRO                  = 'otro';

    public static function tiposProceso(): array
    {
        return [
            self::TIPO_AFILIACION             => 'Afiliación',
            self::TIPO_INCAPACIDAD            => 'Incapacidad',
            self::TIPO_TUTELA                 => 'Tutela',
            self::TIPO_DERECHO_PETICION       => 'Derecho de Petición',
            self::TIPO_INCLUSION_BENEFICIARIO => 'Inclusión Beneficiario',
            self::TIPO_TRASLADO_EPS           => 'Traslado EPS',
            self::TIPO_OTRO                   => 'Otro',
        ];
    }

    /**
     * Movimientos que dejó el propio radicado al cambiar de estado, por id de
     * radicado: [id del movimiento, estado nuevo, cuándo]. Sirven para no
     * duplicar cuando el código que cambió el estado anota después su propio
     * movimiento, que es el que trae la observación de quien lo hizo.
     */
    private static array $automaticos = [];

    protected static function booted(): void
    {
        static::creating(function (RadicadoMovimiento $m) {
            if (! $m->radicado_id) {
                return;
            }
            $auto = self::$automaticos[$m->radicado_id] ?? null;
            unset(self::$automaticos[$m->radicado_id]);

            // Un movimiento explícito del mismo cambio reemplaza al automático.
            if (! $auto || $auto[1] !== $m->estado_nuevo || $auto[2] < microtime(true) - 120) {
                return;
            }

            $previo = static::find($auto[0]);
            if (! $previo) {
                return;
            }

            $m->user_id ??= $previo->user_id;
            $m->estado_anterior ??= $previo->estado_anterior;
            if (trim((string) $m->observacion) === '') {
                $m->observacion = $previo->observacion;
            }
            $previo->delete();
        });
    }

    /** El movimiento de un radicado que acaba de cambiar de estado. */
    public static function registrarAutomatico(Radicado $r): void
    {
        $partes = [];
        if ($r->estado === Radicado::ESTADO_OK && $r->confirmado_por) {
            $partes[] = 'Confirmado en '.(Radicado::CONFIRMADORES[$r->confirmado_por] ?? $r->confirmado_por);
        }
        if ($r->numero_radicado && ($r->wasRecentlyCreated || $r->wasChanged('numero_radicado'))) {
            $partes[] = 'radicación '.$r->numero_radicado;
        }
        if ($r->observacion && ($r->wasRecentlyCreated || $r->wasChanged('observacion'))) {
            $partes[] = $r->observacion;
        }

        try {
            unset(self::$automaticos[$r->id]);
            $m = static::create([
                'radicado_id' => $r->id,
                'contrato_id' => $r->contrato_id,
                'tipo_proceso' => self::TIPO_AFILIACION,
                'entidad' => $r->tipo,
                'user_id' => Auth::id() ?? (($r->wasRecentlyCreated || $r->wasChanged('user_id')) ? $r->user_id : null),
                'estado_anterior' => $r->wasRecentlyCreated ? null : $r->getOriginal('estado'),
                'estado_nuevo' => $r->estado,
                'observacion' => $partes ? implode(' · ', $partes) : null,
            ]);
            self::$automaticos[$r->id] = [$m->id, $r->estado, microtime(true)];
        } catch (\Throwable $e) {
            // El rastro nunca debe tumbar el cambio de estado.
            \Log::warning('RadicadoMovimiento automático falló: '.$e->getMessage());
        }
    }

    // ── Relaciones ──
    public function radicado(): BelongsTo
    {
        return $this->belongsTo(Radicado::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ──
    public function tipoLabel(): string
    {
        return self::tiposProceso()[$this->tipo_proceso] ?? ucfirst($this->tipo_proceso);
    }

    public function entidadLabel(): string
    {
        return match($this->entidad) {
            'eps'     => 'EPS',
            'arl'     => 'ARL',
            'caja'    => 'Caja',
            'pension' => 'Pensión',
            default   => strtoupper($this->entidad ?? ''),
        };
    }
}
