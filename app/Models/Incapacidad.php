<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Incapacidad extends BaseModel
{
    use SoftDeletes;

    protected $table = 'incapacidades';

    protected $fillable = [
        'aliado_id',
        'incapacidad_padre_id',
        'numero_proroga',
        'contrato_id',
        'cedula_usuario',
        'quien_remite',
        'quien_recibe_id',
        'tipo_incapacidad',
        'dias_incapacidad',
        'fecha_inicio',
        'fecha_terminacion',
        'fecha_recibido',
        'prorroga',
        'tipo_entidad',
        'entidad_responsable_id',
        'entidad_nombre',
        'razon_social_id',
        'razon_social_nombre',
        'numero_radicado',
        'fecha_radicado',
        'transcripcion_requerida',
        'transcripcion_completada',
        'estado_pago',
        'fecha_pago',
        'valor_pago',
        'valor_esperado',
        'salario_base',
        'detalle_pago',
        'pagado_a',
        'pagado_a_tipo',
        'pagado_a_cliente_id',
        'pagado_a_empresa_id',
        'ruta_soporte_pago',
        'diagnostico',
        'concepto_rehabilitacion',
        'observacion',
        'descripcion_cliente',
        'estado',
        'motivo_anulacion',
        'anulacion_observacion',
        'estado_previo_anulacion',
        'anulada_por',
        'anulada_en',
        'token_subida',
        'created_by',
    ];

    protected $casts = [
        'fecha_inicio'             => 'date',
        'fecha_terminacion'        => 'date',
        'fecha_recibido'           => 'date',
        'fecha_radicado'           => 'date',
        'fecha_pago'               => 'date',
        'anulada_en'               => 'datetime',
        'prorroga'                 => 'boolean',
        'transcripcion_requerida'  => 'boolean',
        'transcripcion_completada' => 'boolean',
        'valor_pago'               => 'decimal:2',
        'valor_esperado'           => 'decimal:2',
        'salario_base'             => 'decimal:2',
    ];

    // ════════════════════════════════════════════════════════════════════════
    // CATÁLOGOS
    // ════════════════════════════════════════════════════════════════════════

    const TIPOS_INCAPACIDAD = [
        'enfermedad_general'   => '🤒 Enfermedad General',
        'licencia_maternidad'  => '🤱 Licencia Maternidad',
        'licencia_paternidad'  => '👶 Licencia Paternidad',
        'accidente_transito'   => '🚗 Accidente Tránsito',
        'accidente_laboral'    => '⚠️ Accidente Laboral',
    ];

    const TIPOS_ENTIDAD = [
        'eps' => 'EPS',
        'arl' => 'ARL',
        'afp' => 'AFP / Pensión',
    ];

    /**
     * Estados del proceso de gestión de la incapacidad.
     * Flujo: recibido → transcripcion → radicada → liquidacion → pagada
     *                                      ↓
     *                                   negada → tutela → tutela_radicada → liquidacion → pagada
     *                                      ↓
     *                                  rechazado (final)
     *
     * Solo cambian estado las gestiones de tipo:
     *   radico, negada, tutela, tutela_radicada, liquidacion, pago, rechazado
     * El estado 'negada' se asigna manualmente desde la vista de detalle.
     */
    const ESTADOS = [
        // ── Ciclo Normal ─────────────────────────────────────────────────────
        'recibido'                  => ['label' => '📬 Recibido',                     'color' => 'secondary'],
        'transcripcion_ips'         => ['label' => '🏥 Transcripción IPS',            'color' => 'info'],
        'radicada'                  => ['label' => '📋 Radicada',                     'color' => 'primary'],
        // ── Ciclo Negado ──────────────────────────────────────────────────────
        'negada'                    => ['label' => '🚫 Negada',                       'color' => 'danger'],
        'derecho_peticion'          => ['label' => '📄 Derecho de Petición',          'color' => 'warning'],
        'derecho_peticion_radicado' => ['label' => '📄 D. Petición Radicado',         'color' => 'warning'],
        'tutela'                    => ['label' => '⚖️ Tutela',                        'color' => 'warning'],
        'tutela_radicada'           => ['label' => '📜 Tutela Radicada',              'color' => 'warning'],
        'rechazado'                 => ['label' => '❌ Rechazado',                    'color' => 'danger'],
        // ── Ciclo de Pago ─────────────────────────────────────────────────────
        'en_liquidacion'            => ['label' => '💰 En Liquidación',              'color' => 'info'],
        'pagada_razon_social'       => ['label' => '🏢 Pagada a Razón Social',       'color' => 'info'],
        'pagada_afiliado'           => ['label' => '🏦 Pagada al Afiliado',          'color' => 'success'],
        'cierre_exitoso'            => ['label' => '✅ Cierre Exitoso',              'color' => 'success'],
        // ── Cierre administrativo ─────────────────────────────────────────────
        // No es una respuesta de la entidad como 'negada'/'rechazado': es el
        // aliado dando por muerto un caso que nunca entró a trámite. El porqué
        // vive en `motivo_anulacion`, no en el estado.
        'anulada'                   => ['label' => '⛔ Anulada',                      'color' => 'secondary'],
        // ── Legacy (no mostrar en selector) ──────────────────────────────────
        'pagada'                    => ['label' => '✅ Pagada (legacy)',              'color' => 'success', 'legacy' => true],
        // Ortografía vieja de 'pagada_afiliado' que dejó la migración del legacy.
        // Sin esta entrada el badge sale crudo y el estado queda fuera del mapa de
        // transiciones, así que la incapacidad no se puede cerrar (caso #329).
        'pagado_afiliado'           => ['label' => '🏦 Pagada al Afiliado (legacy)',  'color' => 'success', 'legacy' => true],
        'liquidacion'               => ['label' => '💰 En Liquidación (legacy)',     'color' => 'info',    'legacy' => true],
        'transcripcion'             => ['label' => '🏥 Transcripción (legacy)',      'color' => 'info',    'legacy' => true],
    ];


    /**
     * Motivos por los que una incapacidad se anula.
     *
     * Catálogo aparte y no estados sueltos: agregar un motivo aquí no obliga a
     * tocar ESTADOS_FINALES, ESTADOS_SIN_PENDIENTE, el mapa de transiciones ni
     * los informes. 'otro' obliga a escribir la observación (lo valida el
     * controlador).
     */
    const MOTIVOS_ANULACION = [
        'falta_documentacion' => '📄 El cliente nunca envió la documentación',
        'creada_por_error'    => '⌨️ Se creó por error',
        'duplicada'           => '👯 Duplicada / ya existe otra igual',
        'cliente_desistio'    => '🙅 El cliente desistió del trámite',
        'fuera_de_termino'    => '⏰ Fuera de término para radicar',
        'otro'                => '📝 Otro (explicar)',
    ];

    /**
     * Estados de pago del valor de la incapacidad.
     * Independiente del estado del proceso.
     */
    const ESTADOS_PAGO = [
        'pendiente'       => ['label' => '⏳ Pendiente',          'color' => 'warning'],
        'pagado_afiliado' => ['label' => '🏦 Pagado al Afiliado', 'color' => 'success'],
        'rechazado'       => ['label' => '❌ Rechazado',          'color' => 'danger'],
    ];

    /**
     * Tipos de gestión (canales de contacto).
     * Ninguno cambia el estado automáticamente — el estado se
     * actualiza manualmente desde el selector de estado en el modal.
     */
    const TIPOS_GESTION = [
        'llamada'  => ['label' => '📞 Llamada',         'cambia_estado' => false, 'nuevo_estado' => null],
        'correo'   => ['label' => '📧 Correo',          'cambia_estado' => false, 'nuevo_estado' => null],
        'whatsapp' => ['label' => '💬 WhatsApp',        'cambia_estado' => false, 'nuevo_estado' => null],
        'portal'   => ['label' => '🌐 Portal Web',       'cambia_estado' => false, 'nuevo_estado' => null],
        'otro'     => ['label' => '📝 Otro',            'cambia_estado' => false, 'nuevo_estado' => null],
    ];

    // ════════════════════════════════════════════════════════════════════════
    // RELACIONES
    // ════════════════════════════════════════════════════════════════════════

    /** Incapacidad padre (si esta es una prórroga) */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(Incapacidad::class, 'incapacidad_padre_id');
    }

    /**
     * Prórrogas de esta incapacidad (solo hijas directas).
     *
     * Ordenadas por fecha de inicio y no por `numero_proroga`: el número es el
     * orden en que se registraron, que no siempre es el orden en que ocurrieron
     * (una prórroga vieja se puede cargar después de una más reciente). El
     * listado debe leerse como la línea de tiempo del paciente.
     */
    public function prorrogas(): HasMany
    {
        return $this->hasMany(Incapacidad::class, 'incapacidad_padre_id')
                    ->orderBy('fecha_inicio')
                    ->orderBy('numero_proroga');
    }

    /**
     * Renumera las prórrogas de una familia como 1..N por fecha de inicio.
     *
     * El número es la etiqueta con la que el usuario nombra cada prórroga en el
     * modal, en los documentos y por teléfono con la EPS, así que tiene que
     * leerse como la línea de tiempo: la prórroga 2 empieza después de la 1.
     * Antes se asignaba con un `count() + 1` al crear, que es el orden en que
     * se registraron: cargar una prórroga vieja después de una más reciente
     * dejaba la numeración cruzada contra las fechas.
     *
     * Escribe con el query builder a propósito: renumerar es contabilidad
     * interna de la familia, no una gestión sobre la incapacidad, y no debe
     * mover `updated_at` ni disparar eventos del modelo.
     *
     * @return array<int,array{id:int,antes:int,despues:int}> solo lo que cambió
     */
    public static function renumerarFamilia(int $padreId): array
    {
        $hermanas = static::where('incapacidad_padre_id', $padreId)
            ->orderBy('fecha_inicio')
            ->orderBy('id')          // empate de fechas: manda la que entró primero
            ->get(['id', 'numero_proroga']);

        $cambios = [];

        foreach ($hermanas as $i => $hermana) {
            $nuevo = $i + 1;
            if ((int) $hermana->numero_proroga === $nuevo) {
                continue;
            }

            DB::table('incapacidades')->where('id', $hermana->id)
                ->update(['numero_proroga' => $nuevo]);

            $cambios[] = [
                'id' => (int) $hermana->id,
                'antes' => (int) $hermana->numero_proroga,
                'despues' => $nuevo,
            ];
        }

        return $cambios;
    }

    /** Todas las gestiones (con cambio de estado y seguimiento) */
    public function gestiones(): HasMany
    {
        return $this->hasMany(GestionIncapacidad::class)->orderByDesc('id');
    }

    /**
     * Solo la última gestión — eager-loadable para evitar N+1 en el index.
     * Usar: $query->with('latestGestion')
     */
    public function latestGestion(): HasOne
    {
        return $this->hasOne(GestionIncapacidad::class)->latestOfMany('id');
    }

    /** Documentos del cliente (radicados con incapacidad_id) */
    public function documentos(): HasMany
    {
        return $this->hasMany(Radicado::class, 'incapacidad_id')->orderByDesc('id');
    }

    /** Abonos, préstamos y pagos ligados a esta incapacidad */
    public function abonos(): HasMany
    {
        return $this->hasMany(AbonoIncapacidad::class)->orderBy('fecha')->orderBy('id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function quienRecibe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quien_recibe_id');
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    // ════════════════════════════════════════════════════════════════════════
    // CÁLCULO DE VALOR ESPERADO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula y persiste el valor_esperado usando el salario_base guardado.
     *
     * Reglas:
     *  - EPS original (prorroga=false): (salario/30) × (días - 2)  — mínimo 3 días para pagar
     *  - EPS prórroga  (prorroga=true) : (salario/30) × días       — sin descuento
     *  - ARL / AFP                     : (salario/30) × días       — 100%, desde día 1
     *
     * El salario_base se guarda al crear la incapacidad (de contratos.salario).
     * Si no hay salario_base se retorna null sin persistir.
     *
     * @param bool $persistir  Si true guarda el resultado en valor_esperado.
     * @return float|null
     */
    public function calcularValorEsperado(bool $persistir = false): ?float
    {
        if ($this->salario_base === null || (float)$this->salario_base <= 0) {
            $this->resolverYGuardarSalario();
        }

        $salario = (float) ($this->salario_base ?? 0);
        $dias    = (int)   ($this->dias_incapacidad ?? 0);

        if ($salario <= 0 || $dias <= 0) {
            return null;
        }

        $valorDiario = $salario / 30;
        $esProrroga  = (bool) $this->prorroga || ($this->incapacidad_padre_id !== null);

        $valor = match($this->tipo_entidad) {
            'eps' => $dias < 3 ? 0.0
                               : round(max(0, ($esProrroga ? $dias : $dias - 2)) * $valorDiario, 2),
            'arl',
            'afp' => round($dias * $valorDiario, 2),
            default => 0.0,
        };

        if ($persistir) {
            $this->valor_esperado = $valor;
            $this->saveQuietly();
        }

        return $valor;
    }

    /**
     * Retorna el salario del contrato activo al momento de la fecha_inicio.
     * Lo guarda en salario_base si aún está vacío.
     */
    public function resolverYGuardarSalario(): ?float
    {
        if ($this->salario_base > 0) {
            return (float) $this->salario_base;
        }

        if (!$this->contrato_id) return null;

        $salario = DB::table('contratos')
            ->where('id', $this->contrato_id)
            ->value('salario');

        if (!is_numeric($salario) || $salario <= 0) return null;

        $this->salario_base = (float) $salario;
        $this->saveQuietly();

        return (float) $salario;
    }

    // ════════════════════════════════════════════════════════════════════════
    // FINANCIERO (ABONOS)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Saldo pendiente de cobro a la EPS/ARL/AFP.
     * saldo = valor_esperado - SUM(entrada_incapacidad) - SUM(pago_cliente)
     *                          - SUM(pago_directo_entidad)
     *
     * Los préstamos del aliado (tipo='abono') NO descuentan este saldo,
     * son solo informativos para que el aliado sepa cuánto recuperar.
     *
     * Requiere: $this->abonos ya cargado (eager-load) para evitar N+1.
     */
    public function getSaldoPendienteAttribute(): float
    {
        $esperado = (float) ($this->valor_esperado ?? 0);

        if ($this->relationLoaded('abonos')) {
            $pagado = $this->abonos
                ->whereIn('tipo', AbonoIncapacidad::TIPOS_DESCUENTAN)
                ->sum('valor');
        } else {
            $pagado = DB::table('abonos_incapacidades')
                ->where('incapacidad_id', $this->id)
                ->whereIn('tipo', AbonoIncapacidad::TIPOS_DESCUENTAN)
                ->sum('valor');
        }

        return max(0, $esperado - (float) $pagado);
    }

    /**
     * Total prestado/adelantado por el aliado al cliente.
     * Es informativo: le recuerda al aliado cuánto dinero personal tiene comprometido
     * y cuánto debe recuperar cuando la EPS pague.
     */
    public function getTotalPrestadoAttribute(): float
    {
        if ($this->relationLoaded('abonos')) {
            return (float) $this->abonos->where('tipo', 'abono')->sum('valor');
        }
        return (float) DB::table('abonos_incapacidades')
            ->where('incapacidad_id', $this->id)
            ->where('tipo', 'abono')
            ->sum('valor');
    }

    /**
     * Total recibido de incapacidad (entradas de EPS/ARL/AFP).
     */
    public function getTotalPagoEpsAttribute(): float
    {
        if ($this->relationLoaded('abonos')) {
            return (float) $this->abonos->where('tipo', 'entrada_incapacidad')->sum('valor');
        }
        return (float) DB::table('abonos_incapacidades')
            ->where('incapacidad_id', $this->id)
            ->where('tipo', 'entrada_incapacidad')
            ->sum('valor');
    }

    /**
     * Total pagado al cliente/empresa.
     */
    public function getTotalPagoClienteAttribute(): float
    {
        if ($this->relationLoaded('abonos')) {
            return (float) $this->abonos->where('tipo', 'pago_cliente')->sum('valor');
        }
        return (float) DB::table('abonos_incapacidades')
            ->where('incapacidad_id', $this->id)
            ->where('tipo', 'pago_cliente')
            ->sum('valor');
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPERS DEL CLIENTE
    // ════════════════════════════════════════════════════════════════════════

    public function getClienteAttribute()
    {
        return DB::table('clientes')
            ->where('cedula', $this->cedula_usuario)
            ->select('id', 'cedula', 'primer_nombre', 'segundo_nombre',
                     'primer_apellido', 'segundo_apellido', 'celular', 'correo', 'cod_empresa')
            ->first();
    }

    public function getNombreClienteAttribute(): string
    {
        $c = $this->cliente;
        if (!$c) return $this->cedula_usuario;
        return trim(($c->primer_nombre ?? '') . ' ' . ($c->segundo_nombre ?? '') . ' ' .
                    ($c->primer_apellido ?? '') . ' ' . ($c->segundo_apellido ?? ''));
    }

    // ════════════════════════════════════════════════════════════════════════
    // FAMILIA (PADRE + PRÓRROGAS)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Precargas para quien ya tiene la familia en memoria (el detalle del modal
     * la trae completa en una sola consulta). Sin esto, cada llamada al semáforo
     * y a los días de familia vuelve a la BD: abrir el detalle costaba 25
     * consultas y ~5 s, la mitad repetidas.
     */
    protected ?int $diasFamiliaCache = null;

    protected ?\Illuminate\Support\Collection $gestionesFamiliaCache = null;

    protected ?int $diasGestionCache = null;

    /** Días totales de la familia, ya calculados por quien llama. */
    public function precargarDiasFamilia(int $dias): static
    {
        $this->diasFamiliaCache = $dias;

        return $this;
    }

    /** Gestiones de toda la familia, para que el semáforo no vuelva a consultar. */
    public function precargarGestionesFamilia(\Illuminate\Support\Collection $gestiones): static
    {
        $this->gestionesFamiliaCache = $gestiones;
        $this->diasGestionCache = null;

        return $this;
    }

    /**
     * Retorna el total de días de toda la familia (original + prórrogas).
     * Si $this es una prórroga, sube al padre primero.
     */
    public function totalDiasFamilia(): int
    {
        if ($this->diasFamiliaCache !== null) {
            return $this->diasFamiliaCache;
        }

        $padreId = $this->incapacidad_padre_id ?? $this->id;
        return $this->diasFamiliaCache = (int) DB::table('incapacidades')
            ->where(function ($q) use ($padreId) {
                $q->where('id', $padreId)
                  ->orWhere('incapacidad_padre_id', $padreId);
            })
            ->whereNull('deleted_at')
            ->sum('dias_incapacidad');
    }

    /**
     * Cantidad de prórrogas de este grupo.
     */
    public function numeroProrrogas(): int
    {
        $padreId = $this->incapacidad_padre_id ?? $this->id;
        return (int) DB::table('incapacidades')
            ->where('incapacidad_padre_id', $padreId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * El `$campo` del miembro más reciente de la familia (la original y sus
     * prórrogas), resuelto con las prórrogas que ya vienen cargadas.
     *
     * El listado agrupado pide `estado_grupo` y `entidad_grupo` por cada fila,
     * y cada uno era una consulta: 80 por página. Como el listado ya carga las
     * prórrogas, se resuelve en memoria con la misma regla de la consulta —
     * el `numero_proroga` más alto, con NULL al final como en SQL Server — y
     * la relación ya excluye las borradas (SoftDeletes). Contrastado contra la
     * consulta en las 3.869 familias que había el 17-sep-2026: cero diferencias.
     *
     * Devuelve `false` (distinto de null) cuando no se puede resolver así: una
     * prórroga, o las prórrogas sin cargar o cargadas sin esa columna. Ahí el
     * que llama sigue con la consulta de siempre.
     */
    private function ultimoMiembroCargado(string $campo): string|null|false
    {
        if ($this->incapacidad_padre_id !== null || ! $this->relationLoaded('prorrogas')) {
            return false;
        }

        $prorrogas = $this->prorrogas;
        foreach ($prorrogas as $p) {
            if (! array_key_exists($campo, $p->getAttributes()) || ! array_key_exists('numero_proroga', $p->getAttributes())) {
                return false;
            }
        }

        return collect([$this])->concat($prorrogas)
            ->sortByDesc(fn ($m) => $m->numero_proroga ?? -INF)
            ->first()
            ?->{$campo};
    }

    /**
     * Estado del grupo: siempre refleja el estado de la incapacidad más reciente
     * (la última prórroga, o la original si no hay prórrogas).
     * Útil para el encabezado de la familia en la vista agrupada.
     */
    public function getEstadoGrupoAttribute(): string
    {
        $ultimo = $this->ultimoMiembroCargado('estado');
        if ($ultimo !== false) {
            return $ultimo ?? $this->estado;
        }

        $padreId = $this->incapacidad_padre_id ?? $this->id;
        $ultimaEstado = DB::table('incapacidades')
            ->where(function ($q) use ($padreId) {
                $q->where('id', $padreId)
                  ->orWhere('incapacidad_padre_id', $padreId);
            })
            ->whereNull('deleted_at')
            ->orderByDesc('numero_proroga')
            ->value('estado');
        return $ultimaEstado ?? $this->estado;
    }

    /**
     * Entidad del grupo: la de la incapacidad más reciente (puede haber pasado de EPS a AFP).
     */
    public function getEntidadGrupoAttribute(): string
    {
        $ultimo = $this->ultimoMiembroCargado('tipo_entidad');
        if ($ultimo !== false) {
            return $ultimo ?? $this->tipo_entidad;
        }

        $padreId = $this->incapacidad_padre_id ?? $this->id;
        $ultimaEntidad = DB::table('incapacidades')
            ->where(function ($q) use ($padreId) {
                $q->where('id', $padreId)
                  ->orWhere('incapacidad_padre_id', $padreId);
            })
            ->whereNull('deleted_at')
            ->orderByDesc('numero_proroga')
            ->value('tipo_entidad');
        return $ultimaEntidad ?? $this->tipo_entidad;
    }

    /**
     * ¿La familia supera los 180 días de EPS?
     * Alerta para indicar que se debe trasladar a AFP.
     */
    public function alertaDias180(): bool
    {
        return $this->tipo_entidad === 'eps' && $this->totalDiasFamilia() >= 180;
    }

    /**
     * Porcentaje de progreso hacia los 180 días de EPS (para barra visual).
     */
    public function progreso180(): int
    {
        return min(100, (int) round(($this->totalDiasFamilia() / 180) * 100));
    }

    // ════════════════════════════════════════════════════════════════════════
    // SEMÁFORO (basado en días desde la última gestión del grupo)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Días desde la última gestión (en cualquier miembro de la familia).
     * Usa eager-load 'latestGestion' si está disponible (evita N+1 en index).
     */
    public function diasDesdeUltimaGestion(): int
    {
        // Memoizado: colorSemaforo() e iconoSemaforo() también lo llaman, y sin
        // esto cada uno repetía las mismas dos consultas.
        if ($this->diasGestionCache !== null) {
            return $this->diasGestionCache;
        }

        // Con la familia precargada no hace falta ir a la BD: la última gestión
        // que cuenta es la propia o una del padre marcada "aplica a familia",
        // que es la misma regla de abajo.
        if ($this->gestionesFamiliaCache !== null) {
            $padreId = (int) ($this->incapacidad_padre_id ?? 0);
            $ultima = $this->gestionesFamiliaCache
                ->filter(fn ($g) => (int) $g->incapacidad_id === (int) $this->id
                    || ($padreId && (int) $g->incapacidad_id === $padreId && $g->aplica_a_familia))
                ->sortByDesc(fn ($g) => $g->created_at)
                ->first();

            return $this->diasGestionCache = max(0, (int) now()->diffInDays(
                $ultima->created_at ?? $this->created_at
            ));
        }

        // Buscar la gestión más reciente propia
        if ($this->relationLoaded('latestGestion')) {
            $ultima = $this->latestGestion;
        } else {
            $ultima = $this->gestiones()->first();
        }

        // También buscar gestiones de familia del padre (aplica_a_familia=true)
        if ($this->incapacidad_padre_id) {
            $ultimaFamilia = \App\Models\GestionIncapacidad::where('incapacidad_id', $this->incapacidad_padre_id)
                ->where('aplica_a_familia', true)
                ->orderByDesc('created_at')
                ->first();
            // Tomar la más reciente entre la propia y la de familia
            if ($ultimaFamilia && (!$ultima || $ultimaFamilia->created_at->gt($ultima->created_at))) {
                $ultima = $ultimaFamilia;
            }
        }

        if (!$ultima) {
            return $this->diasGestionCache = max(0, (int) now()->diffInDays($this->created_at));
        }
        return $this->diasGestionCache = max(0, (int) now()->diffInDays($ultima->created_at));
    }

    /**
     * Color del semáforo.
     * 🟢 verde    < 7 días sin gestión
     * 🟡 amarillo  7–14 días
     * 🔴 rojo     > 14 días
     * ⚫ gris      pagada / rechazada (ya no requiere gestión)
     */
    public function colorSemaforo(): string
    {
        // Cerradas — no les queda nada pendiente. 'negada' incluida: la entidad
        // ya resolvió. Los pagos a medias NO: falta entregar o recibir la plata.
        if (in_array($this->estado, \App\Http\Controllers\Admin\IncapacidadController::ESTADOS_CERRADOS)) {
            return 'gris';
        }

        return self::colorPorDias($this->diasDesdeUltimaGestion());
    }

    /** Color del semáforo según los días sin gestión (ver colorSemaforo()). */
    public static function colorPorDias(int $dias): string
    {
        if ($dias < 7)   return 'verde';
        if ($dias <= 14) return 'amarillo';
        return 'rojo';
    }

    public function iconoSemaforo(): string
    {
        return match($this->colorSemaforo()) {
            'verde'   => '🟢',
            'amarillo'=> '🟡',
            'rojo'    => '🔴',
            default   => '⚫',
        };
    }

    // ════════════════════════════════════════════════════════════════════════
    // LINK DE SUBIDA DE DOCUMENTOS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Genera (o retorna el existente) token de subida para el cliente.
     * El token es un UUID único guardado en incapacidades.token_subida.
     */
    public function generarTokenSubida(): string
    {
        if (!$this->token_subida) {
            $this->token_subida = Str::uuid()->toString();
            $this->saveQuietly();
        }
        return $this->token_subida;
    }

    /**
     * URL pública para que el cliente suba sus documentos.
     */
    public function getLinkSubidaAttribute(): string
    {
        return route('incapacidades.subir', ['token' => $this->generarTokenSubida()]);
    }

    /**
     * Texto pre-armado para WhatsApp.
     * Incluye saludo personalizado y el link de subida.
     */
    public function getMensajeWhatsappSubidaAttribute(): string
    {
        $nombre = $this->getNombreClienteAttribute();
        $aliado = DB::table('aliados')->where('id', $this->aliado_id)->value('nombre') ?? 'nuestra empresa';
        $link   = $this->link_subida;

        $texto = "Hola {$nombre}, desde {$aliado} le informamos que necesitamos que suba "
               . "los documentos requeridos para gestionar su incapacidad. "
               . "Por favor ingrese al siguiente link y cargue los archivos: {$link}";

        return 'https://wa.me/' . '?text=' . urlencode($texto);
    }

    // ════════════════════════════════════════════════════════════════════════
    // LABELS Y HELPERS UI
    // ════════════════════════════════════════════════════════════════════════

    public function tipoIncapacidadLabel(): string
    {
        return self::TIPOS_INCAPACIDAD[$this->tipo_incapacidad] ?? ucfirst($this->tipo_incapacidad);
    }

    public function tipoEntidadLabel(): string
    {
        return self::TIPOS_ENTIDAD[$this->tipo_entidad] ?? strtoupper($this->tipo_entidad);
    }

    public function estadoLabel(): string
    {
        return self::ESTADOS[$this->estado]['label'] ?? ucfirst($this->estado);
    }

    public function estadoColor(): string
    {
        return self::ESTADOS[$this->estado]['color'] ?? 'secondary';
    }

    public function estaAnulada(): bool
    {
        return $this->estado === 'anulada';
    }

    public function motivoAnulacionLabel(): ?string
    {
        if (! $this->motivo_anulacion) {
            return null;
        }

        return self::MOTIVOS_ANULACION[$this->motivo_anulacion] ?? ucfirst($this->motivo_anulacion);
    }

    public function estadoPagoLabel(): string
    {
        return self::ESTADOS_PAGO[$this->estado_pago]['label'] ?? ucfirst($this->estado_pago);
    }

    public function estadoPagoColor(): string
    {
        return self::ESTADOS_PAGO[$this->estado_pago]['color'] ?? 'secondary';
    }

    /**
     * Número de serie en el grupo: "Original", "Prórroga 1", "Prórroga 2"...
     */
    public function getLabelFamiliaAttribute(): string
    {
        if (!$this->incapacidad_padre_id) return 'Original';
        return 'Prórroga ' . $this->numero_proroga;
    }
}
