<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Tarea extends BaseModel
{
    use SoftDeletes;

    protected $table = 'tareas';

    protected $fillable = [
        'aliado_id', 'empresa_id', 'tipo', 'estado', 'resultado',
        'cedula', 'contrato_id', 'razon_social_id', 'entidad',
        'tarea', 'observacion',
        'encargado_id', 'creado_por',
        'fecha_limite', 'fecha_alerta',
        'fecha_radicado', 'numero_radicado', 'correo',
        // De qué hallazgo automático nació, para no repetirla cada noche.
        // Ver TareaAutomaticaService.
        'llave_auto',
    ];

    protected $casts = [
        'fecha_limite' => 'date',
        'fecha_alerta' => 'date',
        'fecha_radicado' => 'date',
    ];

    // ── Constantes de Tipo ──────────────────────────────────────────────────
    const TIPOS = [
        'traslado_eps' => '🔄 Traslado EPS',
        'inclusion_beneficiarios' => '👥 Inclusión Beneficiarios',
        'exclusion' => '❌ Exclusión',
        'subsidios' => '💰 Subsidios',
        'mora_eps' => '⚠️ Mora EPS',
        'actualizar_documentos' => '📄 Actualizar Documentos',
        'devolucion_aportes' => '💵 Devolución de Aportes',
        'solicitud_documentos' => '📋 Solicitud Documentos',
        'otros' => '📝 Otros',
    ];

    /**
     * Las que abre una empresa desde su portal (ver EmpresaSolicitud). Van
     * aparte de TIPOS para que no salgan al crear una tarea a mano.
     */
    const TIPOS_PORTAL = [
        'portal_ingreso' => '🌐 Ingreso (portal)',
        'portal_retiro' => '🌐 Retiro (portal)',
        'portal_incapacidad' => '🌐 Incapacidad (portal)',
        'portal_solicitud' => '🌐 Solicitud (portal)',
    ];

    // ── Constantes de Estado ────────────────────────────────────────────────
    const ESTADO_PENDIENTE = 'pendiente';

    const ESTADO_EN_GESTION = 'en_gestion';

    const ESTADO_EN_ESPERA = 'en_espera';

    const ESTADO_CERRADA = 'cerrada';

    const ESTADOS = [
        'pendiente' => '⏳ Pendiente',
        'en_gestion' => '🔵 En Gestión',
        'en_espera' => '🟠 En Espera',
        'cerrada' => '✅ Cerrada',
    ];

    const ESTADOS_ACTIVOS = ['pendiente', 'en_gestion', 'en_espera'];

    // ── Relaciones ──────────────────────────────────────────────────────────
    public function encargado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    /** La solicitud del portal que abrió esta tarea, si la abrió una empresa. */
    public function solicitudEmpresa(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmpresaSolicitud::class, 'tarea_id');
    }

    public function gestiones(): HasMany
    {
        return $this->hasMany(TareaGestion::class)->orderByDesc('id');
    }

    /**
     * Última gestión registrada. Se resuelve por MAX(id) y no por created_at
     * porque tarea_gestiones tiene $timestamps = false y varias filas viejas
     * comparten la misma fecha.
     */
    public function ultimaGestion(): HasOne
    {
        return $this->hasOne(TareaGestion::class)->ofMany('id', 'max');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(TareaDocumento::class)->orderByDesc('id');
    }

    // ── Helpers de Semáforo ─────────────────────────────────────────────────

    /**
     * Color del semáforo: verde / amarillo / rojo / azul / gris
     */
    public function colorSemaforo(): string
    {
        if ($this->estado === self::ESTADO_CERRADA) {
            return 'gris';
        }

        // Tarea en espera: si fecha_alerta llegó => azul (urgente consultar)
        if ($this->estado === self::ESTADO_EN_ESPERA) {
            if ($this->fecha_alerta && $this->fecha_alerta->isPast()) {
                return 'azul';
            }

            return 'naranja';
        }

        if (! $this->fecha_limite) {
            return 'verde';
        }

        $diasRestantes = now()->startOfDay()->diffInDays($this->fecha_limite->startOfDay(), false);

        if ($diasRestantes < 0) {
            return 'rojo';
        }

        // Obtener umbral amarillo para este tipo
        $config = TareaSemaforoConfig::configParaTipo($this->tipo, $this->aliado_id);
        $umbralAmarillo = $config ? $config->dias_alerta_amarilla : 5;

        return $diasRestantes <= $umbralAmarillo ? 'amarillo' : 'verde';
    }

    public function iconoSemaforo(): string
    {
        return match ($this->colorSemaforo()) {
            'verde' => '🟢',
            'amarillo' => '🟡',
            'rojo' => '🔴',
            'azul' => '🔵',
            'naranja' => '🟠',
            default => '⚫',
        };
    }

    public function diasRestantes(): int
    {
        if (! $this->fecha_limite) {
            return 999;
        }

        return (int) now()->startOfDay()->diffInDays($this->fecha_limite->startOfDay(), false);
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? self::TIPOS_PORTAL[$this->tipo] ?? ucfirst($this->tipo);
    }

    public function estadoLabel(): string
    {
        return self::ESTADOS[$this->estado] ?? ucfirst($this->estado);
    }

    /**
     * La cédula se repite entre aliados: sin el aliado en la relación, un
     * with('cliente') trae el cliente de cualquiera. Ver BelongsToDelAliado.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsToDelAliado(Cliente::class, 'cedula', 'cedula', 'cliente');
    }

    // ── Helpers del cliente ─────────────────────────────────────────────────
    public function getClienteAttribute()
    {
        if ($this->relationLoaded('cliente')) {
            return $this->getRelation('cliente');
        }

        // Atajo para no hidratar el modelo completo. Filtra por aliado igual que
        // la relación: sin esto, la cédula sola traía el cliente de otro aliado
        // por una ruta que ni siquiera pasa por cliente().
        $aliadoId = $this->aliado_id ?? session('aliado_id_activo');

        if (! $aliadoId) {
            return null;
        }

        return DB::table('clientes')
            ->where('cedula', $this->cedula)
            ->where('aliado_id', $aliadoId)
            ->select('id', 'cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'celular', 'correo')
            ->first();
    }

    public function getNombreClienteAttribute(): string
    {
        $c = $this->cliente;
        if (! $c) {
            return $this->cedula;
        }

        return trim(($c->primer_nombre ?? '').' '.($c->segundo_nombre ?? '').' '.
                    ($c->primer_apellido ?? '').' '.($c->segundo_apellido ?? ''));
    }
}
