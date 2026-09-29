<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Radicado extends BaseModel
{
    protected $table = 'radicados';

    protected $fillable = [
        'contrato_id', 'aliado_id', 'tipo',
        'incapacidad_id', 'tipo_documento',
        'numero_radicado', 'estado',
        'canal_envio', 'enviado_al_cliente',
        'canal_envio_cliente', 'fecha_envio_cliente',
        'fecha_inicio_tramite', 'fecha_confirmacion',
        'user_id', 'observacion', 'ruta_pdf',
        'confirmado_por', 'confirmado_en',
    ];

    protected $casts = [
        'enviado_al_cliente' => 'boolean',
        'fecha_envio_cliente' => 'datetime',
        'fecha_inicio_tramite' => 'datetime',
        'fecha_confirmacion' => 'datetime',
        'confirmado_en' => 'datetime',
    ];

    /**
     * Entidades que confirman un OK por su portal o API. Un OK con
     * `confirmado_por` lo dio por hecho la entidad; sin él, lo marcó alguien a mano.
     */
    const CONFIRMADORES = [
        'nueva_eps' => 'Nueva EPS',
        'eps_sura' => 'EPS SURA',
        'salud_total' => 'Salud Total',
        'eps_sanitas' => 'Sanitas',
        'arl_sura' => 'ARL Sura',
        'caja_comfenalco' => 'Comfenalco Valle',
        'caja_comfandi' => 'Comfandi',
        'arl_colmena' => 'ARL Colmena',
        'coosalud' => 'Coosalud',
        'emssanar' => 'Emssanar',
        'asmet_salud' => 'Asmet Salud',
        'eps_sos' => 'S.O.S.',
        'ruaf' => 'RUAF',
    ];

    protected static function booted(): void
    {
        // La confirmación solo vale mientras el radicado sigue en OK: si alguien
        // lo reabre (a mano o por una anulación), deja de estar confirmado.
        static::saving(function (Radicado $r) {
            if ($r->estado !== self::ESTADO_OK && ($r->confirmado_por || $r->confirmado_en)) {
                $r->confirmado_por = null;
                $r->confirmado_en = null;
            }
        });
    }

    /**
     * Columnas para marcar un OK como confirmado por la entidad, sin pisar una
     * confirmación anterior (conserva la fecha en que se confirmó primero).
     */
    public function datosConfirmacion(string $entidad): array
    {
        return [
            'confirmado_por' => $this->confirmado_por ?: $entidad,
            'confirmado_en' => $this->confirmado_en ?: now(),
        ];
    }

    public function esConfirmadoPorEntidad(): bool
    {
        return $this->estado === self::ESTADO_OK && (bool) $this->confirmado_por;
    }

    /** "Confirmado en Nueva EPS el 15/09/2026 · radicado 10758215" */
    public function textoConfirmacion(): ?string
    {
        if (! $this->esConfirmadoPorEntidad()) {
            return null;
        }

        return 'Confirmado en '.(self::CONFIRMADORES[$this->confirmado_por] ?? $this->confirmado_por)
            .($this->confirmado_en ? ' el '.$this->confirmado_en->format('d/m/Y') : '')
            .($this->numero_radicado ? ' · radicado '.$this->numero_radicado : '');
    }

    /**
     * El radicado para gestionarlo desde el panel: el del aliado activo y, para
     * un usuario de BryNex, el de cualquier aliado al que tenga acceso, porque
     * ve las afiliaciones de varios aliados en una sola lista (botón 🏢).
     * Ver {@see Contrato::paraTramite()}.
     */
    public static function paraTramite(int $id, array $with = []): self
    {
        $radicado = self::with($with)->findOrFail($id);
        $user = Auth::user();

        $delActivo = (int) $radicado->aliado_id === (int) session('aliado_id_activo');

        abort_unless(
            $delActivo || ($user?->es_brynex && $user->puedeAccederAliado((int) $radicado->aliado_id)),
            404
        );

        return $radicado;
    }

    // ── Constantes de estado ──
    const ESTADO_PENDIENTE = 'pendiente';

    const ESTADO_TRAMITE = 'tramite';

    const ESTADO_TRASLADO = 'traslado';

    const ESTADO_ERROR = 'error';

    const ESTADO_OK = 'ok';

    // Estados activos (requieren seguimiento)
    public static function estadosActivos(): array
    {
        return [self::ESTADO_PENDIENTE, self::ESTADO_TRAMITE, self::ESTADO_TRASLADO, self::ESTADO_ERROR];
    }

    public static function todosEstados(): array
    {
        return [
            self::ESTADO_PENDIENTE => 'Pendiente',
            self::ESTADO_TRAMITE => 'Trámite',
            self::ESTADO_TRASLADO => 'Traslado',
            self::ESTADO_ERROR => 'Error',
            self::ESTADO_OK => 'OK',
        ];
    }

    // ── Constantes de tipo ──
    const TIPO_EPS = 'eps';

    const TIPO_ARL = 'arl';

    const TIPO_CAJA = 'caja';

    const TIPO_PENSION = 'pension';

    // ── Constantes de canal ──
    const CANAL_WEB = 'web';

    const CANAL_CORREO = 'correo';

    const CANAL_ASESOR = 'asesor';

    const CANAL_PRESENCIAL = 'presencial';

    const CANAL_OTRO = 'otro';

    const CANAL_WHATSAPP = 'whatsapp';

    const CANAL_FISICA = 'fisica';

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RadicadoMovimiento::class)->orderByDesc('id');
    }

    /**
     * El movimiento más reciente, cargable de una vez para toda una página.
     * Es el mismo que `movimientos()->reorder()->orderByDesc('id')->first()`,
     * pero con `with('ultimoMovimiento')` sale en UNA consulta para todos los
     * radicados en vez de una por cada uno (ver diasEnEstado()).
     */
    public function ultimoMovimiento(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RadicadoMovimiento::class)->latestOfMany('id');
    }

    // ── Helpers de estado ──
    public function esPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function esTramite(): bool
    {
        return $this->estado === self::ESTADO_TRAMITE;
    }

    public function esTraslado(): bool
    {
        return $this->estado === self::ESTADO_TRASLADO;
    }

    public function esError(): bool
    {
        return $this->estado === self::ESTADO_ERROR;
    }

    public function esOk(): bool
    {
        return $this->estado === self::ESTADO_OK;
    }

    public function esFinalizado(): bool
    {
        return $this->estado === self::ESTADO_OK;
    }

    /**
     * Determina si el radicado está programado/futuro (es decir, está pendiente
     * pero la fecha de ingreso del contrato es futura, faltando más de 1 día).
     */
    public function esFuturoProgramado(): bool
    {
        if ($this->estado !== self::ESTADO_PENDIENTE) {
            return false;
        }

        $contrato = $this->contrato;
        if (! $contrato || ! $contrato->fecha_ingreso) {
            return false;
        }

        $hoy = now()->startOfDay();
        $ingreso = \Carbon\Carbon::parse($contrato->fecha_ingreso)->startOfDay();

        return $hoy->diffInDays($ingreso, false) > 1;
    }

    public function estadoClaseEfectiva(): string
    {
        if ($this->esFuturoProgramado()) {
            return 'programado';
        }
        if ($this->esConfirmadoPorEntidad()) {
            return 'ok-confirmado';
        }

        return $this->estado;
    }

    public function estadoTextoEfectivo(): string
    {
        if ($this->esFuturoProgramado()) {
            return '📅 F';
        }
        if ($this->esConfirmadoPorEntidad()) {
            return '✔✔ OK';
        }
        // "OK" se muestra completo; el resto con su inicial.
        $texto = $this->estado === self::ESTADO_OK
            ? 'OK'
            : strtoupper(substr($this->estado, 0, 1));

        return $this->estadoIcono().' '.$texto;
    }

    /**
     * Calcula los días transcurridos desde el inicio del estado actual.
     * Si hay movimientos, toma la fecha del último; si no, usa created_at.
     */
    public function diasEnEstado(): int
    {
        // En el listado de afiliaciones viene precargado: sin eso eran dos
        // consultas por radicado en trámite (tieneAlertaDias + diasEnEstado).
        $ultimoMov = $this->relationLoaded('ultimoMovimiento')
            ? $this->ultimoMovimiento
            : $this->movimientos()->reorder()->orderByDesc('id')->first();
        $desde = $ultimoMov ? $ultimoMov->created_at : $this->created_at;

        return max(0, (int) now()->diffInDays($desde));
    }

    /** ¿Alerta por llevar demasiado tiempo en trámite? (>5 días) */
    public function tieneAlertaDias(): bool
    {
        return $this->estado === self::ESTADO_TRAMITE && $this->diasEnEstado() > 5;
    }

    /** Etiqueta legible del tipo de radicado */
    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'eps' => 'EPS',
            'arl' => 'ARL',
            'caja' => 'Caja Compensación',
            'pension' => 'Pensión (AFP)',
            default => strtoupper($this->tipo),
        };
    }

    /** Color badge para la UI según estado */
    public function estadoColor(): string
    {
        return match ($this->estado) {
            'pendiente' => 'warning',
            'tramite' => 'info',
            'traslado' => 'orange',
            'error' => 'danger',
            'ok' => 'success',
            default => 'secondary',
        };
    }

    /** Icono emoji por estado */
    public function estadoIcono(): string
    {
        return match ($this->estado) {
            'pendiente' => '⏳',
            'tramite' => '🔵',
            'traslado' => '🔄',
            'error' => '❌',
            'ok' => '✅',
            default => '❓',
        };
    }
}
