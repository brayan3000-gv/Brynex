<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class TipoModalidad extends BaseModel
{
    public $timestamps = false;

    protected $table = 'tipo_modalidad';

    protected $primaryKey = 'id';

    public $incrementing = false;  // El ID NO es auto-incremental

    protected $fillable = [
        'id', 'tipo_modalidad', 'observacion', 'descripcion', 'orden', 'modalidad', 'activo',
        'es_tiempo_parcial', 'dias_arl', 'dias_afp', 'dias_caja',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'es_tiempo_parcial' => 'boolean',
        'dias_arl' => 'integer',
        'dias_afp' => 'integer',
        'dias_caja' => 'integer',
    ];

    /**
     * Scope: activos, ordenados, sin los registros que no son una modalidad
     * que se pueda contratar — "Todos" (-100), que es un filtro, y "Corrección"
     * (16), que solo existe para que el traslado de razón social marque sus
     * planos con tipo_p = 16 y el TXT salga como planilla tipo N.
     */
    public function scopeActivos($q)
    {
        // Las que se están probando en este entorno se ofrecen aunque sigan
        // inactivas (config app.modalidades_en_prueba, vacío en producción).
        $enPrueba = config('app.modalidades_en_prueba', []);

        $q->where(fn ($w) => $w->where('activo', true)->when($enPrueba, fn ($x) => $x->orWhereIn('id', $enPrueba)))
            ->whereNotIn('id', self::IDS_NO_CONTRATABLES);

        // Modalidades reservadas a un aliado: fuera de él no se ofrecen. Sin
        // aliado en sesión —una cola, un comando— quedan ocultas, que es el
        // lado seguro: no se vende lo que no se puede sostener.
        $aliadoActivo = (int) session('aliado_id_activo');

        foreach (self::ALIADOS_POR_MODALIDAD as $modalidadId => $aliados) {
            if (! in_array($aliadoActivo, $aliados, true)) {
                $q->where('id', '!=', $modalidadId);
            }
        }

        return $q->orderBy('orden');
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'tipo_modalidad_id');
    }

    /** Nombre para mostrar en la UI: usa observacion si existe, si no tipo_modalidad */
    public function getNombreAttribute(): string
    {
        return $this->observacion ?: $this->tipo_modalidad;
    }

    /** Corrección: planilla tipo N generada por un traslado de razón social, no se contrata */
    const ID_CORRECCION = 16;

    /** No se le ofrecen a un cliente: "Todos" es un filtro y "Corrección" es operativa */
    const IDS_NO_CONTRATABLES = [-100, self::ID_CORRECCION];

    /**
     * Solo Caja: el cliente compra únicamente la caja de compensación, por el
     * mes completo o por media jornada del mes. Se paga en dos planillas
     * encadenadas — ver PilaCotizanteDosPasos.
     *
     * Las dos se calculan igual y solo se diferencian en los días de caja, que
     * salen de la columna `dias_caja` del catálogo: cambiar 14 por otro número
     * es cambiar el dato, no el código.
     */
    const ID_CAJA_30 = -5;

    const ID_CAJA_14 = -10;

    const IDS_SOLO_CAJA = [self::ID_CAJA_30, self::ID_CAJA_14];

    /**
     * Solo Pensión: el cliente compra únicamente el aporte a pensión. Mismo
     * esquema de dos planillas que Solo Caja —el paso 1 es el mismo archivo—,
     * y los días salen de `dias_afp`. Ver PilaCotizanteDosPasos.
     */
    const ID_PENSION_30 = -11;

    const IDS_SOLO_PENSION = [self::ID_PENSION_30];

    /**
     * Tipo E - Extras: la modalidad que reemplaza a las tres de arriba.
     *
     * Ellas dicen a la vez cómo se paga y qué se vende, así que cada
     * combinación nueva era una fila más del catálogo. Esta separa las dos
     * cosas: la modalidad dice **cómo** se paga —dos planillas encadenadas— y
     * el plan del contrato dice **qué** se paga. Ver PLANES_EXTRAS.
     */
    const ID_EXTRAS = -12;

    /**
     * Qué vende cada plan de Tipo E - Extras, y en cuántos días.
     *
     * `dias` son los del subsistema que se vende; `api` dice si la corrección
     * se puede liquidar contra la API del operador. La salud NO: el validador
     * de archivos planos la rechaza con eo.val.2.198 / 2.244 aunque el portal
     * web del mismo operador la acepte (probado en Simple el 17-sep-2026), así
     * que esos dos planes se corrigen por el portal.
     */
    const PLANES_EXTRAS = [
        'SOLO_EPS' => ['dias' => 30, 'api' => false],
        'EPS_ARL' => ['dias' => 30, 'api' => false],
        'SOLO_CCF_14' => ['dias' => 14, 'api' => true],
        'SOLO_CCF_30' => ['dias' => 30, 'api' => true],
        'SOLO_AFP' => ['dias' => 30, 'api' => true],
    ];

    /**
     * Tipo E - X1: salud sin pensión en UNA sola planilla.
     *
     * La contraparte de Tipo E - Extras. Allá se vende un subsistema suelto y
     * hacen falta dos liquidaciones encadenadas; aquí todo entra en una
     * planilla E ordinaria que el operador liquida de una vez, apoyada en el
     * subtipo de cotizante 03/04 que exime del aporte a pensión.
     *
     * **Para quién es.** Quien está exento —ya pensionado, hombre 55+, mujer
     * 50+, o documento CE/PT/PP/PE/PA— no la necesita: va por Tipo E normal
     * (modalidad 0) con los planes EPS+ARL o EPS+ARL+CCF, que liquidan en
     * cualquier operador. Esta modalidad es para **quien no cumple requisitos**.
     *
     * Ver PilaCotizanteEX1, que tiene el detalle de cada plan y lo que cuesta.
     */
    const ID_EX1 = -13;

    /**
     * Tipo E - X1 TP14: la variante de tiempo parcial de la anterior — ARL del
     * mes completo + caja de 14 días, sin pensión y sin salud, con cotizante 51.
     *
     * Vive aparte porque `es_tiempo_parcial` es una columna de la modalidad y
     * una no puede ser dependiente y tiempo parcial a la vez; además la pantalla
     * de planos filtra por modalidad, así que juntas no se podían descargar por
     * separado. Siendo un tiempo parcial normal, toda la maquinaria que ya
     * existe la entiende sin ayuda.
     */
    const ID_EX1_TP = -14;

    /** Las dos caras de Tipo E - X1: solo liquidan por Simple. */
    const IDS_EX1 = [self::ID_EX1, self::ID_EX1_TP];

    /**
     * Modalidades que no se mezclan con gente normal en un mismo plano.
     *
     * Las de dos pasos porque su corrección se busca después por el mismo
     * filtro con el que se liquidó. Tipo E - X1 por otra razón: **toda la
     * planilla tiene que ir por Simple**, y basta un cotizante suyo para que
     * Enlace rechace la tanda entera con `eo.val.2.655`.
     */
    const IDS_TANDA_APARTE = [
        self::ID_CAJA_30, self::ID_CAJA_14, self::ID_PENSION_30, self::ID_EXTRAS,
        self::ID_EX1, self::ID_EX1_TP,
    ];

    /** Las que se pagan en dos liquidaciones encadenadas contra el operador. */
    const IDS_DOS_PASOS = [self::ID_CAJA_30, self::ID_CAJA_14, self::ID_PENSION_30, self::ID_EXTRAS];

    /**
     * Modalidades que solo se le ofrecen a ciertos aliados.
     *
     * No es un permiso ni una restricción de seguridad: es qué se puede vender
     * dónde. Las de dos pasos arrancaron como planes de BRYGAR (aliado 2) y se
     * pagan en dos liquidaciones encadenadas contra la API del operador, así
     * que ofrecerlas a un aliado que no las conoce es venderle algo que no va a
     * poder pagar. Para abrirlas a otro aliado basta agregar su id aquí.
     *
     * Tipo E - X1 no está: es una planilla E ordinaria que liquida por Simple,
     * así que se ofrece en todos los aliados (14-oct-2026).
     */
    const ALIADOS_POR_MODALIDAD = [
        self::ID_CAJA_30 => [2],
        self::ID_CAJA_14 => [2],
        self::ID_PENSION_30 => [2],
        // Fecop (6) la vende desde el 28-oct-2026, solo en los planes que
        // limita PLANES_POR_ALIADO.
        self::ID_EXTRAS => [2, 6],
    ];

    /**
     * Planes que un aliado vende de una modalidad, cuando no son todos los de
     * `modalidad_planes`. Por código de plan.
     *
     * Fecop vende de Tipo E - Extras solo caja de 30 días y pensión: las que
     * se corrigen por API. Los de salud se corrigen por el portal y en Fecop
     * todavía no se ha podido subir ese plano (28-oct-2026).
     */
    const PLANES_POR_ALIADO = [
        self::ID_EXTRAS => [
            6 => ['SOLO_CCF_30', 'SOLO_AFP'],
        ],
    ];

    /**
     * Mapa tipo_modalidad_id => [plan_id] que ofrece el formulario al aliado:
     * los de `modalidad_planes`, recortados por PLANES_POR_ALIADO.
     */
    public static function planesPermitidos(int $aliadoId): array
    {
        $mapa = DB::table('modalidad_planes')
            ->get()
            ->groupBy('tipo_modalidad_id')
            ->map(fn ($rows) => $rows->pluck('plan_id')->values())
            ->toArray();

        foreach (self::PLANES_POR_ALIADO as $modalidadId => $porAliado) {
            if (! isset($porAliado[$aliadoId], $mapa[$modalidadId])) {
                continue;
            }

            $ids = DB::table('planes_contrato')
                ->whereIn('codigo', $porAliado[$aliadoId])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $mapa[$modalidadId] = collect($mapa[$modalidadId])
                ->filter(fn ($id) => in_array((int) $id, $ids, true))
                ->values()
                ->all();
        }

        return $mapa;
    }

    /**
     * Tiempo Parcial Independiente: cotizante 76 de la Resolución 1529 de 2026.
     * Pensión por semanas, ARL sobre el mínimo completo, caja voluntaria y sin
     * salud. Los días NO están en el catálogo: son del contrato.
     */
    const ID_TP_INDEPENDIENTE = 18;

    /**
     * Fondo de Solidaridad: Programa de Subsidio al Aporte en Pensión (PSAP),
     * cotizante 33 en planilla I. El Estado subsidia parte de la pensión sobre
     * un salario mínimo y el cotizante paga el resto según su grupo; la salud
     * va completa (el operador no deja liquidar el 33 sin ella).
     *
     * El grupo es del contrato, no de la modalidad: es lo que decide la tarifa
     * de pensión, igual que los días en el Tiempo Parcial Independiente.
     */
    const ID_FONDO_SOLIDARIDAD = 19;

    /**
     * Grupos del PSAP y la tarifa de pensión que paga cada uno: el 16% menos el
     * subsidio del plan de extensión de cobertura (CONPES 3605 de 2009). Son las
     * tarifas que la Resolución 2388 de 2016 admite para el cotizante 33:
     * 0,8 %, 1,6 %, 3,2 %, 4,0 % y 4,8 %.
     *
     * Los requisitos para entrar al programa los fija el Decreto 543 de 2026.
     */
    const GRUPOS_FONDO_SOLIDARIDAD = [
        'independiente' => ['nombre' => 'Independiente',                        'pct_pension' => 4.0, 'subsidio' => 75],
        'desempleado' => ['nombre' => 'Desempleado',                          'pct_pension' => 4.8, 'subsidio' => 70],
        'madre_sustituta' => ['nombre' => 'Madre sustituta o FAMI',               'pct_pension' => 3.2, 'subsidio' => 80],
        'discapacidad' => ['nombre' => 'Persona con discapacidad',             'pct_pension' => 0.8, 'subsidio' => 95],
        'concejal' => ['nombre' => 'Concejal o edil (municipio 4, 5 o 6)', 'pct_pension' => 4.0, 'subsidio' => 75],
    ];

    /**
     * Grupos a los que se les ofrece el plan Solo AFP. Ojo: hoy Enlace rechaza
     * al cotizante 33 sin salud (eo.val.2.066), así que ese plan no liquida por
     * planilla. Se dejó por decisión del negocio, sep-2026.
     */
    const GRUPOS_FONDO_SOLIDARIDAD_SOLO_AFP = ['desempleado'];

    /** Edad para entrar al PSAP (Decreto 543 de 2026): mayor de 35 y menor de 65. */
    const EDAD_MIN_FONDO_SOLIDARIDAD = 35;

    const EDAD_MAX_FONDO_SOLIDARIDAD = 65;

    /**
     * Tarifa de pensión (en %) del grupo. Sin grupo cae en la de independiente:
     * el formulario lo exige, así que solo pasa con datos armados a mano.
     */
    public static function pctPensionFondoSolidaridad(?string $grupo): float
    {
        return (float) (self::GRUPOS_FONDO_SOLIDARIDAD[$grupo]['pct_pension']
            ?? self::GRUPOS_FONDO_SOLIDARIDAD['independiente']['pct_pension']);
    }

    /** IDs que corresponden a modalidades independientes (I Venc=10, UPC=13, En el Exterior=14, TP Ind=18, Fondo de Solidaridad=19) */
    const IDS_INDEPENDIENTE = [10, 13, 14, self::ID_TP_INDEPENDIENTE, self::ID_FONDO_SOLIDARIDAD];

    /** UPC: afiliar a alguien fuera del núcleo familiar — no depende del salario */
    const ID_UPC = 13;

    /** Gestión ARL: no cotiza seguridad social, solo se le hace el trámite de la ARL */
    const ID_GESTION_ARL = 15;

    /** Seguros: solo se le vendió un seguro — sin EPS, ARL, pensión, caja ni planilla */
    const ID_SEGUROS = 17;

    /** Modalidades que no cotizan seguridad social: no liquidan planilla ni generan plano */
    const IDS_SIN_SEGURIDAD_SOCIAL = [self::ID_GESTION_ARL, self::ID_SEGUROS];

    /** IDs que requieren el campo "Modo ARL" */
    const IDS_MODO_ARL = [10, -1, self::ID_TP_INDEPENDIENTE];

    /** IDs en que la ARL es libre (no bloqueada a la razon social) */
    const IDS_ARL_LIBRE = [10, -1, 8, self::ID_TP_INDEPENDIENTE];

    public function esIndependiente(): bool
    {
        return in_array($this->id, self::IDS_INDEPENDIENTE);
    }

    /** ¿Fondo de Solidaridad (PSAP, cotizante 33)? */
    public function esFondoSolidaridad(): bool
    {
        return (int) $this->id === self::ID_FONDO_SOLIDARIDAD;
    }

    /** ¿Solo seguro? Sin EPS, ARL, pensión ni caja: el mes vale lo que valga el seguro. */
    public function esSeguros(): bool
    {
        return (int) $this->id === self::ID_SEGUROS;
    }

    /** ¿Esta modalidad cotiza seguridad social? Gestión ARL y Seguros no. */
    public function cotizaSeguridadSocial(): bool
    {
        return ! in_array((int) $this->id, self::IDS_SIN_SEGURIDAD_SOCIAL, true);
    }

    /**
     * ¿Es modalidad de Tiempo Parcial?
     * Los planes TP tienen días fijos por entidad definidos en BD.
     */
    public function esTiempoParcial(): bool
    {
        return (bool) $this->es_tiempo_parcial;
    }


    /**
     * Retorna los días a cotizar por entidad para este plan de Tiempo Parcial.
     * Array: ['arl' => X, 'afp' => Y, 'caja' => Z]
     *
     * Regla de negocio:
     *   - ARL: siempre 30 días (cotización mensual completa, sin importar el plan)
     *   - AFP: días fijos del plan (7, 14 ó 21)
     *   - CAJA: días fijos del plan (7, 14 ó 21)
     *
     * Si no es TP, retorna 30 para todas (mes completo).
     */
    public function diasPorEntidad(): array
    {
        if ($this->esTiempoParcial()) {
            return [
                'arl' => 30,                    // ARL siempre mensual completa
                'afp' => $this->dias_afp ?? 30,
                'caja' => $this->dias_caja ?? 30,
            ];
        }

        return ['arl' => 30, 'afp' => 30, 'caja' => 30];
    }

    /**
     * ¿Los días de esta modalidad los pone el contrato en vez del catálogo?
     *
     * El Tiempo Parcial de dependientes nació como una modalidad por cada
     * combinación de días — ocho en total — y por eso `dias_afp`/`dias_caja`
     * viven en esta tabla. El Tiempo Parcial Independiente no repite eso: sus
     * días son un dato del contrato y del mes, no del catálogo, así que aquí
     * quedan en NULL.
     */
    public function diasEnElContrato(): bool
    {
        return $this->esTiempoParcial() && $this->dias_afp === null;
    }

    /** Fracción del SMMLV según los días AFP del plan de Tiempo Parcial */
    const FACTOR_SALARIO_POR_DIAS = [7 => 0.25, 14 => 0.50, 21 => 0.75, 30 => 1.00];

    /**
     * Fracción del salario mínimo que corresponde a esta modalidad.
     * Tiempo completo → 1.0; Tiempo Parcial → 0.25 / 0.50 / 0.75 según días AFP.
     *
     * $diasAfp lo pasa el contrato cuando es él quien tiene los días
     * (Tiempo Parcial Independiente); sin él se usan los del catálogo.
     */
    public function factorSalario(?int $diasAfp = null, ?string $codigoPlan = null): float
    {
        // Tipo E - Extras: los días los dice el plan, no el catálogo. "Solo CCF
        // 14" admite medio mínimo por la misma razón que Caja 14 lo admitía
        // cuando era su propia modalidad.
        if ((int) $this->id === self::ID_EXTRAS) {
            $dias = self::PLANES_EXTRAS[$codigoPlan]['dias'] ?? 30;

            return self::FACTOR_SALARIO_POR_DIAS[$dias] ?? 1.0;
        }

        // Solo Caja no es Tiempo Parcial —cotiza como dependiente (cotizante 1)—
        // pero solo vende una parte del mes, así que su piso es esa misma
        // fracción: "Caja 14" admite medio mínimo, igual que un TP(14). Con el
        // piso completo no dejaba guardar el contrato.
        if (in_array((int) $this->id, self::IDS_SOLO_CAJA, true)) {
            return self::FACTOR_SALARIO_POR_DIAS[$this->dias_caja] ?? 1.0;
        }
        if (! $this->esTiempoParcial()) {
            return 1.0;
        }

        return self::FACTOR_SALARIO_POR_DIAS[$diasAfp ?? $this->dias_afp] ?? 1.0;
    }

    /**
     * Salario mínimo legal que puede tener un contrato en esta modalidad.
     * UPC (13) no depende del salario: no tiene piso.
     */
    public function salarioMinimoPermitido(?int $diasAfp = null, ?string $codigoPlan = null): float
    {
        // (int) obligatorio: el id no es IDENTITY y llega como string
        // Seguros no cotiza nada, así que tampoco tiene piso de salario.
        if (in_array((int) $this->id, [self::ID_UPC, self::ID_SEGUROS], true)) {
            return 0.0;
        }

        return round(ConfiguracionBrynex::salarioMinimo() * $this->factorSalario($diasAfp, $codigoPlan));
    }
}
