<?php

namespace App\Services;

use App\Models\AfiliacionArlModalidad;
use App\Models\ConfiguracionAliado;
use App\Models\ConfiguracionBrynex;
use App\Models\PlanContrato;
use App\Models\TipoModalidad;
use Carbon\Carbon;

/**
 * Lógica de cotización pública, extraída de App\Services\Ia\Tools\CotizarPlanTool para que la
 * página web pública del aliado y la tool de la IA (cotizar_plan) usen EXACTAMENTE el mismo
 * cálculo — mismos precios, mismo plan de pago inicial, sin duplicar la lógica en dos lugares
 * que puedan desincronizarse. CotizarPlanTool delega aquí y solo conserva lo específico de la
 * IA (parseo de modalidad en texto libre, mensajes de error conversacionales).
 */
class CotizacionPublicaService
{
    public const MODALIDAD_DEPENDIENTE_ID   = 0;
    public const MODALIDAD_INDEPENDIENTE_ID = 10; // "I Venc" — la misma que usa CotizarPlanTool para el plan de pago inicial

    /**
     * Combinaciones "destacadas" que se muestran como tarjetas en la sección de planes de la
     * página pública Y como presets de precio en vivo en el generador de publicidad. El
     * cotizador interactivo, en cambio, acepta CUALQUIER combinación (hay 11 activas en el
     * sistema) — estas 3 son solo los anclajes de marketing más comunes.
     */
    /**
     * Copy PÚBLICO de cada plan ofrecible en la página web, keyed por `codigo` de
     * planes_contrato. La columna `descripcion` de esa tabla es texto INTERNO para la IA
     * (menciona ofertas solo_ia, el plan B de Gestión ARL, reglas de exención de AFP, etc.) y
     * NUNCA debe renderizarse en la web — por eso este mapa existe aparte, con redacción
     * comercial corta y revisada. Un plan que exista en planes_contrato pero no tenga entrada
     * aquí simplemente no aparece en la web (protección para planes nuevos sin copy revisado).
     *
     * `grupo` separa las tarjetas en pestañas en la página pública (empleado/independiente son
     * los que más venden y van primero; por_dias y solo_arl son productos de nicho). `selector`
     * marca las tarjetas cuyo precio varía según una opción que el visitante elige EN el
     * navegador sin ida al servidor — hoy solo 'dias' (Tiempo Parcial: 7/14/21/30, resuelto
     * contra la modalidad TP real de cada tramo). Solo ARL NO usa selector: en vez de un dropdown
     * de nivel de riesgo, `niveles` expande el plan en 5 tarjetas fijas (una por nivel I-V), cada
     * una con ejemplos de profesión — así el visitante no tiene que saber su nivel de riesgo de
     * antemano, lo reconoce por el ejemplo.
     */
    public const COPY_PUBLICO = [
        'EPS_ARL' => [
            'grupo'       => 'empleado',
            'orden'       => 1,
            'destacado'   => false,
            'nombre'      => 'Esencial',
            'descripcion' => 'Salud y riesgos laborales para empleados y trabajadores dependientes.',
        ],
        'EPS_ARL_CCF' => [
            'grupo'       => 'empleado',
            'orden'       => 2,
            'destacado'   => false,
            'nombre'      => 'Plan Familiar',
            'descripcion' => 'Salud y riesgos laborales, más acceso a subsidios y beneficios de caja de compensación.',
        ],
        'EPS_ARL_AFP' => [
            'grupo'       => 'empleado',
            'orden'       => 3,
            'destacado'   => false,
            'nombre'      => 'Protección Total',
            'descripcion' => 'Salud, riesgos laborales y pensión — tus tres aportes principales cubiertos.',
        ],
        'EPS_ARL_AFP_CCF' => [
            'grupo'       => 'empleado',
            'orden'       => 4,
            'destacado'   => true,
            'nombre'      => 'Integral',
            'descripcion' => 'Salud, riesgos laborales, pensión y caja de compensación — la cobertura más completa.',
        ],
        'SOLO_EPS' => [
            'grupo'       => 'independiente',
            'orden'       => 1,
            'destacado'   => false,
            'nombre'      => 'Salud Independiente',
            'descripcion' => 'Para quienes trabajan por cuenta propia y necesitan afiliación a salud.',
        ],
        'EPS_AFP' => [
            'grupo'       => 'independiente',
            'orden'       => 2,
            'destacado'   => false,
            'nombre'      => 'Plan Ahorro y Salud',
            'descripcion' => 'Salud y pensión para quien no ejerce una actividad laboral de riesgo.',
        ],
        'EPS_AFP_CCF' => [
            'grupo'       => 'independiente',
            'orden'       => 3,
            'destacado'   => false,
            'nombre'      => 'Plan Bienestar Independiente',
            'descripcion' => 'Salud, pensión y acceso a subsidios de caja de compensación.',
        ],
        'SOLO_AFP' => [
            'grupo'       => 'independiente',
            'orden'       => 4,
            'destacado'   => false,
            'nombre'      => 'Ahorro Pensional',
            'descripcion' => 'Sigue cotizando a tu fondo de pensión, vivas donde vivas.',
        ],
        'ARL_AFP_CCF' => [
            'grupo'       => 'por_dias',
            'orden'       => 1,
            'destacado'   => false,
            'nombre'      => 'Tiempo Parcial con Pensión',
            'descripcion' => 'Riesgos laborales, pensión y caja de compensación, pagando solo los días que trabajas.',
            'selector'    => 'dias',
        ],
        'ARL_CCF' => [
            'grupo'       => 'por_dias',
            'orden'       => 2,
            'destacado'   => false,
            'nombre'      => 'Tiempo Parcial sin Pensión',
            'descripcion' => 'Riesgos laborales y caja de compensación, pagando solo los días que trabajas.',
            'selector'    => 'dias',
        ],
        // Un solo plan físico (SOLO_ARL) expandido en 5 tarjetas — ver nota arriba.
        'SOLO_ARL' => [
            'grupo' => 'solo_arl',
            'niveles' => [
                1 => [
                    'orden'       => 1,
                    'nombre'      => 'ARL Riesgo I — Administrativo y Oficina',
                    'descripcion' => 'Para labores de bajo riesgo: personal administrativo, asesores, docentes.',
                ],
                2 => [
                    'orden'       => 2,
                    'nombre'      => 'ARL Riesgo II — Comercio y Servicios',
                    'descripcion' => 'Para ventas, comercio al por menor, telemercadeo.',
                ],
                3 => [
                    'orden'       => 3,
                    'nombre'      => 'ARL Riesgo III — Transporte y Manufactura',
                    'descripcion' => 'Para conductores de pasajeros, operarios de manufactura liviana, mantenimiento.',
                ],
                4 => [
                    'orden'       => 4,
                    'nombre'      => 'ARL Riesgo IV — Construcción Liviana y Carga',
                    'descripcion' => 'Para transporte de carga, construcción liviana, vigilancia.',
                ],
                5 => [
                    'orden'       => 5,
                    'nombre'      => 'ARL Riesgo V — Construcción Pesada y Minería',
                    'descripcion' => 'Para minería, construcción en alturas, manejo de explosivos.',
                ],
            ],
        ],
    ];

    /** Días ofrecidos en las tarjetas "por días" del grupo Tiempo Parcial. */
    private const DIAS_POR_DIAS = [7, 14, 21, 30];

    public const PLANES_DESTACADOS = [
        [
            'clave'         => 'dependiente_basico',
            'nombre'        => 'Dependiente Básico',
            'descripcion'   => 'Salud y riesgos laborales para empleados y trabajadores dependientes.',
            'componentes'   => ['incluye_eps' => true, 'incluye_arl' => true, 'incluye_pension' => false, 'incluye_caja' => false],
            'independiente' => false,
            'destacado'     => false,
        ],
        [
            'clave'         => 'dependiente_completo',
            'nombre'        => 'Dependiente Completo',
            'descripcion'   => 'Salud, riesgos laborales, pensión y caja de compensación — la cobertura más completa.',
            'componentes'   => ['incluye_eps' => true, 'incluye_arl' => true, 'incluye_pension' => true, 'incluye_caja' => true],
            'independiente' => false,
            'destacado'     => true,
        ],
        [
            'clave'         => 'independiente',
            'nombre'        => 'Independiente',
            'descripcion'   => 'Para quienes trabajan por cuenta propia y necesitan afiliación a salud.',
            'componentes'   => ['incluye_eps' => true, 'incluye_arl' => false, 'incluye_pension' => false, 'incluye_caja' => false],
            'independiente' => true,
            'destacado'     => false,
        ],
    ];

    /**
     * Calcula el valor mensual/afiliación en vivo de las 3 combinaciones destacadas para un
     * aliado. Usado por la página pública (planesDestacados) y por el generador de publicidad
     * (presets de precio real al armar una pieza de tipo "promo de precio").
     */
    public static function planesDestacadosConPrecio(int $aliadoId, bool $mostrarPrecios = true): \Illuminate\Support\Collection
    {
        $tarjetas = [];

        foreach (self::PLANES_DESTACADOS as $def) {
            [$plan, $exacto] = self::resolverPlan($def['componentes'], $def['independiente']);
            if (!$plan) {
                continue;
            }

            $modalidad = self::resolverModalidadPermitida($plan, $def['independiente']);
            if (!$modalidad) {
                continue;
            }

            $resultado = self::cotizar($plan, $modalidad, $aliadoId);

            $tarjetas[] = [
                'clave'            => $def['clave'],
                'nombre'           => $def['nombre'],
                'descripcion'      => $def['descripcion'],
                'destacado'        => $def['destacado'],
                'componentes'      => $def['componentes'],
                'independiente'    => $def['independiente'],
                'valor_mensual'           => $mostrarPrecios ? $resultado['total'] : null,
                'costo_afiliacion'        => $mostrarPrecios ? $resultado['costo_afiliacion_sugerido'] : null,
                'costo_afiliacion_normal' => $mostrarPrecios ? $resultado['costo_afiliacion_normal'] : null,
                'en_promocion'            => $resultado['en_promocion'],
                'promocion_vence'         => $resultado['promocion_vence'],
            ];
        }

        return collect($tarjetas);
    }

    /**
     * Todos los planes ofrecibles públicamente, agrupados por pestaña (`grupo` en
     * COPY_PUBLICO: empleado/independiente/por_dias/solo_arl) para el carrusel de la página
     * web. Recorre planes_contrato activos, se salta los que no tengan copy revisado en
     * COPY_PUBLICO y los que no resuelvan a ninguna modalidad pública. Cacheado 10 minutos: cada
     * variante de precio cuesta ~12 queries a SQL Server (~2.5s) por la doble pasada de
     * CotizadorService dentro de cotizar(), así que sin caché la página sería inviable.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection> Keyed por grupo.
     */
    public static function planesPublicosConPrecio(int $aliadoId, bool $mostrarPrecios = true): \Illuminate\Support\Collection
    {
        $clave = "planes_publicos:{$aliadoId}:" . ($mostrarPrecios ? 1 : 0);

        return \Illuminate\Support\Facades\Cache::remember($clave, 600, function () use ($aliadoId, $mostrarPrecios) {
            $tarjetas = [];

            $planes = PlanContrato::where('activo', true)
                ->whereIn('codigo', array_keys(self::COPY_PUBLICO))
                ->get();

            foreach ($planes as $plan) {
                $copy = self::COPY_PUBLICO[$plan->codigo] ?? null;
                if (!$copy) {
                    continue;
                }

                // Un plan físico expandido en varias tarjetas fijas (hoy: Solo ARL -> 5 niveles).
                if (isset($copy['niveles'])) {
                    array_push($tarjetas, ...self::tarjetasPorNivelArl($plan, $copy, $aliadoId, $mostrarPrecios));
                    continue;
                }

                $tarjeta = match ($copy['selector'] ?? null) {
                    'dias'  => self::tarjetaPorDias($plan, $copy, $aliadoId, $mostrarPrecios),
                    default => self::tarjetaNormal($plan, $copy, $aliadoId, $mostrarPrecios),
                };

                if ($tarjeta) {
                    $tarjetas[] = $tarjeta;
                }
            }

            usort($tarjetas, fn ($a, $b) => $a['orden'] <=> $b['orden']);

            return collect($tarjetas)->groupBy('grupo');
        });
    }

    /** Tarjeta de precio único (el caso normal: empleado/independiente). */
    private static function tarjetaNormal(PlanContrato $plan, array $copy, int $aliadoId, bool $mostrarPrecios): ?array
    {
        $esIndependiente = !$plan->incluye_arl;
        $modalidad = self::resolverModalidadPermitida($plan, $esIndependiente);
        if (!$modalidad) {
            return null;
        }

        $resultado = self::cotizar($plan, $modalidad, $aliadoId, ['sin_plan_pago' => true]);

        return self::tarjetaBase($plan, $copy) + [
            'independiente'           => $esIndependiente,
            'valor_mensual'           => $mostrarPrecios ? $resultado['total'] : null,
            'costo_afiliacion'        => $mostrarPrecios ? $resultado['costo_afiliacion_sugerido'] : null,
            'costo_afiliacion_normal' => $mostrarPrecios ? $resultado['costo_afiliacion_normal'] : null,
            'en_promocion'            => $resultado['en_promocion'],
            'promocion_vence'         => $resultado['promocion_vence'],
        ];
    }

    /**
     * "Solo ARL" no se muestra como una tarjeta con selector de riesgo: se expande en 5 tarjetas
     * FIJAS, una por nivel (I-V), cada una con su propio nombre/ejemplo de profesión (ver
     * COPY_PUBLICO['SOLO_ARL']['niveles']) — así el visitante no tiene que saber de antemano su
     * nivel de riesgo, lo reconoce por el ejemplo. El precio real es el mismo sin importar la
     * modalidad (K/Y/Independientes) con la que se cotice internamente.
     *
     * @return array[] Lista de tarjetas (una por nivel), nunca una sola.
     */
    private static function tarjetasPorNivelArl(PlanContrato $plan, array $copy, int $aliadoId, bool $mostrarPrecios): array
    {
        $tarjetas = [];
        foreach ($copy['niveles'] as $riesgo => $nivel) {
            // La modalidad (K/Y) se resuelve por nivel: no cambia el valor mensual, pero si más
            // adelante esta tarjeta expone costo_afiliacion, debe ser el de la modalidad correcta.
            $modalidad = self::resolverModalidadPermitida($plan, true, false, null, false, false, false, $riesgo);
            if (!$modalidad) {
                continue;
            }

            $resultado = self::cotizar($plan, $modalidad, $aliadoId, ['nivel_arl' => $riesgo, 'sin_plan_pago' => true]);

            $tarjetas[] = self::tarjetaBase($plan, $copy, [
                'clave'       => $plan->codigo . '_R' . $riesgo,
                'nombre'      => $nivel['nombre'],
                'descripcion' => $nivel['descripcion'],
                'orden'       => $nivel['orden'],
            ]) + [
                'valor_mensual' => $mostrarPrecios ? $resultado['total'] : null,
            ];
        }

        return $tarjetas;
    }

    /** Tarjetas "Por días" (Tiempo Parcial): un precio por cada tramo 7/14/21/30 días. */
    private static function tarjetaPorDias(PlanContrato $plan, array $copy, int $aliadoId, bool $mostrarPrecios): ?array
    {
        $precios = [];
        foreach (self::DIAS_POR_DIAS as $dias) {
            $modalidad = self::resolverModalidadPermitida($plan, false, false, $dias);
            if (!$modalidad) {
                continue;
            }
            $resultado = self::cotizar($plan, $modalidad, $aliadoId, ['dias' => $dias, 'sin_plan_pago' => true]);
            $precios[$dias] = $mostrarPrecios ? $resultado['total'] : null;
        }

        if (empty($precios)) {
            return null;
        }

        // Por defecto se muestra el precio de 30 días (mes completo) — el select de la tarjeta
        // también abre en "30 días", así el precio grande y el selector arrancan sincronizados.
        return self::tarjetaBase($plan, $copy) + [
            'valor_mensual'    => $precios[30] ?? reset($precios),
            'precios_por_dias' => $precios,
        ];
    }

    /**
     * Campos comunes a toda tarjeta pública, sin importar el tipo de selector. `$overrides`
     * reemplaza campos puntuales — usado por tarjetasPorNivelArl() para las 5 variantes de un
     * mismo plan físico, que no comparten nombre/descripcion/orden/clave con $copy directamente.
     */
    private static function tarjetaBase(PlanContrato $plan, array $copy, array $overrides = []): array
    {
        $clave = $overrides['clave'] ?? $plan->codigo;
        $rutaImagen = "planes/iconos/{$clave}.png";

        return array_merge([
            'clave'       => $clave,
            'nombre'      => $copy['nombre'] ?? null,
            'descripcion' => $copy['descripcion'] ?? null,
            'destacado'   => $copy['destacado'] ?? false,
            'grupo'       => $copy['grupo'],
            'orden'       => $copy['orden'] ?? 0,
            'selector'    => $copy['selector'] ?? null,
            // Ícono ilustrado (Gemini, flat design, fondo transparente) — ver
            // storage/app/public/planes/iconos/. La existencia se resuelve UNA VEZ aquí (dentro
            // del cache de 10 min de planesPublicosConPrecio), no en cada render del blade — así
            // un plan nuevo sin ícono generado todavía no rompe nada, solo no muestra imagen.
            'imagen' => \Illuminate\Support\Facades\Storage::disk('public')->exists($rutaImagen) ? $rutaImagen : null,
            'componentes' => [
                'incluye_eps'     => $plan->incluye_eps,
                'incluye_arl'     => $plan->incluye_arl,
                'incluye_pension' => $plan->incluye_pension,
                'incluye_caja'    => $plan->incluye_caja,
            ],
        ], $overrides);
    }

    /**
     * Busca el plan cuyos componentes coincidan EXACTAMENTE con lo pedido. Si no existe,
     * busca el plan más cercano que incluya AL MENOS lo pedido (superset con menos extras).
     *
     * "Solo EPS" (sin ARL/pensión/caja) NO es una combinación real bajo Dependiente: BRYGAR
     * afilia como dependiente mediante un esquema que exige ARL junto con la EPS. Esa
     * combinación solo aplica como Independiente (EPS sola, más cara al 12,5%) — por eso se
     * excluye de la búsqueda cuando la modalidad no es independiente, forzando el fallback a
     * "EPS + ARL" en vez de ofrecer algo que no se puede afiliar en la práctica.
     *
     * @return array{0: ?PlanContrato, 1: bool} [plan, esCoincidenciaExacta]
     */
    public static function resolverPlan(array $componentes, bool $esIndependiente): array
    {
        $query = PlanContrato::where('activo', true);
        if (!$esIndependiente) {
            $query->where(function ($q) {
                $q->where('incluye_eps', false)
                  ->orWhere('incluye_arl', true)
                  ->orWhere('incluye_pension', true)
                  ->orWhere('incluye_caja', true);
            });
        }

        $exacto = (clone $query)
            ->where('incluye_eps', $componentes['incluye_eps'])
            ->where('incluye_arl', $componentes['incluye_arl'])
            ->where('incluye_pension', $componentes['incluye_pension'])
            ->where('incluye_caja', $componentes['incluye_caja'])
            ->first();

        if ($exacto) {
            return [$exacto, true];
        }

        // Buscar planes que incluyan AL MENOS lo pedido (superset), y quedarnos con
        // el que tenga menos componentes extra (el más ajustado a lo pedido).
        $candidatos = $query->get()->filter(function ($p) use ($componentes) {
            return (!$componentes['incluye_eps']     || $p->incluye_eps)
                && (!$componentes['incluye_arl']     || $p->incluye_arl)
                && (!$componentes['incluye_pension'] || $p->incluye_pension)
                && (!$componentes['incluye_caja']    || $p->incluye_caja);
        });

        if ($candidatos->isEmpty()) {
            return [null, false];
        }

        $extras = fn ($p) => (int) $p->incluye_eps + (int) $p->incluye_arl + (int) $p->incluye_pension + (int) $p->incluye_caja;
        $mejor = $candidatos->sortBy($extras)->first();

        return [$mejor, false];
    }

    /** Modalidad usada por defecto según el perfil, para flujos que no dejan elegir la modalidad exacta (web pública). */
    public static function modalidadPorDefecto(bool $esIndependiente): ?TipoModalidad
    {
        return TipoModalidad::find($esIndependiente ? self::MODALIDAD_INDEPENDIENTE_ID : self::MODALIDAD_DEPENDIENTE_ID);
    }

    /**
     * Orden de preferencia al resolver la modalidad automáticamente. La modalidad es un
     * detalle INTERNO — el cliente solo dice si es empleado/independiente o si está en el
     * exterior; nunca se le pregunta "¿qué modalidad?". Si un plan no existe en las
     * modalidades del perfil pedido (ej. "Solo AFP" solo existe como "En el Exterior"),
     * se cae en cascada a la primera modalidad donde el plan SÍ es válido.
     */
    private const MODALIDAD_EXTERIOR_ID       = 14; // "En el Exterior"
    private const MODALIDAD_UPC_ID            = 13; // "UPC" — salud de alguien fuera del núcleo familiar
    private const MODALIDAD_INGRESO_RETIRO_ID = 12; // "Ingreso-Retiro" — estrategia de pocos días por mes
    private const PRIORIDAD_INDEPENDIENTE_IDS = [10, 13, 14];
    private const PRIORIDAD_DEPENDIENTE_IDS   = [0, 7];

    /** Días válidos de Tiempo Parcial -> id de modalidad (públicos, ofrecidos siempre por días parejos). */
    private const MODALIDAD_TP_POR_DIAS = [7 => 1, 14 => 2, 21 => 3, 30 => 4];

    /** ID de la modalidad "Gestión ARL" (solo afiliación/radicado, sin planilla mensual). */
    public const MODALIDAD_GESTION_ARL_ID = 15;

    /** Plan "Solo ARL" — el único exento de la regla de pensión obligatoria (nunca la incluye por diseño). */
    private const PLAN_SOLO_ARL_ID = 2;

    /**
     * Modalidades internas de "Solo ARL" (ver PlanContrato::descripcion del plan): "Estudiante K"
     * para riesgo 1-3, "ARL Tipo Y" para riesgo 4-5. El valor mensual no cambia entre ellas (la
     * ARL solo depende del % de riesgo, ver CotizadorService) pero el costo de afiliación sí puede
     * configurarse distinto para cada una (Configuración → Tarifas → desplegar modalidad).
     */
    private const MODALIDAD_ESTUDIANTE_K_ID = -1;
    private const MODALIDAD_ARL_TIPO_Y_ID   = 8;

    /**
     * Resuelve la modalidad correcta para un plan consultando modalidad_planes — la MISMA
     * tabla de permitidos que usa el cotizador del admin (admin/cotizaciones/create) — en vez
     * de asumir una modalidad fija. Evita cotizar combinaciones plan+modalidad que el negocio
     * no ofrece (ej. "Solo AFP" con Independiente Vencido, que no existe).
     *
     * @param ?int $tiempoParcialDias Si el cliente confirmó que quiere pagar por días (7/14/21/30),
     *   fuerza esa modalidad de Tiempo Parcial en vez de la cascada normal.
     * @param bool $incluirSoloIa Filas marcadas solo_ia=true (planes que existen pero NUNCA se
     *   ofrecen en la web pública) — solo la IA de WhatsApp debe pasar true, y solo cuando el
     *   cliente ya mostró que el precio normal es un obstáculo. La web SIEMPRE usa false.
     * @param bool $esUpc El cliente paga la salud de alguien fuera de su núcleo familiar
     *   (ej. un sobrino) → planilla UPC aparte, modalidad 13.
     * @param ?int $nivelArl Nivel de riesgo ARL (1-5). Solo se usa para "Solo ARL": decide entre
     *   modalidad Estudiante K (riesgo 1-3) o ARL Tipo Y (riesgo 4-5) — ver constantes arriba.
     */
    public static function resolverModalidadPermitida(
        PlanContrato $plan,
        bool $esIndependiente,
        bool $desdeExterior = false,
        ?int $tiempoParcialDias = null,
        bool $incluirSoloIa = false,
        bool $esUpc = false,
        bool $ingresoRetiro = false,
        ?int $nivelArl = null
    ): ?TipoModalidad {
        $filas = \Illuminate\Support\Facades\DB::table('modalidad_planes')
            ->where('plan_id', $plan->id)
            ->when(!$incluirSoloIa, fn ($q) => $q->where('solo_ia', false))
            ->get(['tipo_modalidad_id', 'solo_ia']);
        $permitidas = $filas->map(fn ($f) => (int) $f->tipo_modalidad_id)->all();

        // Plan sin filas en modalidad_planes: conservar el comportamiento histórico.
        if (empty($permitidas)) {
            return self::modalidadPorDefecto($esIndependiente);
        }

        // Mismo filtro que el cotizador admin: un plan sin EPS pero con ARL y caja
        // (ARL+AFP+CCF, ARL+CCF) es exclusivo de Tiempo Parcial — el admin lo bloquea en las
        // modalidades independientes aunque modalidad_planes tenga esas filas. Si al quitarlas
        // no queda ninguna, se devuelve null: requiere asesoría, no una modalidad inventada.
        if (!$plan->incluye_eps && $plan->incluye_arl && $plan->incluye_caja) {
            $permitidas = array_values(array_diff($permitidas, self::PRIORIDAD_INDEPENDIENTE_IDS));
            $filas = $filas->reject(fn ($f) => in_array((int) $f->tipo_modalidad_id, self::PRIORIDAD_INDEPENDIENTE_IDS, true));
            if (empty($permitidas)) {
                return null;
            }
        }

        // El plan/oferta oculta (solo_ia) se prioriza primero — si $incluirSoloIa es true, ya
        // fue una decisión deliberada de la IA (el cliente mostró que el precio normal no le
        // sirve), así que no tiene sentido caer en la cascada normal si hay una opción oculta.
        if ($incluirSoloIa) {
            $idsOcultos = $filas->where('solo_ia', true)->pluck('tipo_modalidad_id')->map(fn ($v) => (int) $v)->all();
            if (!empty($idsOcultos)) {
                $modalidad = TipoModalidad::find($idsOcultos[0]);
                if ($modalidad) {
                    return $modalidad;
                }
            }
        }

        if ($tiempoParcialDias && isset(self::MODALIDAD_TP_POR_DIAS[$tiempoParcialDias])) {
            $idTp = self::MODALIDAD_TP_POR_DIAS[$tiempoParcialDias];
            if (in_array($idTp, $permitidas, true)) {
                return TipoModalidad::find($idTp);
            }
        }

        if ($desdeExterior && in_array(self::MODALIDAD_EXTERIOR_ID, $permitidas, true)) {
            return TipoModalidad::find(self::MODALIDAD_EXTERIOR_ID);
        }

        // Paga la salud de alguien fuera de su núcleo familiar → planilla UPC aparte.
        if ($esUpc && in_array(self::MODALIDAD_UPC_ID, $permitidas, true)) {
            return TipoModalidad::find(self::MODALIDAD_UPC_ID);
        }

        // Estrategia para sostener la EPS pagando pocos días al mes (ver modalidad 12).
        if ($ingresoRetiro && in_array(self::MODALIDAD_INGRESO_RETIRO_ID, $permitidas, true)) {
            return TipoModalidad::find(self::MODALIDAD_INGRESO_RETIRO_ID);
        }

        // Solo ARL: la modalidad interna (K/Y) no cambia el valor mensual (la ARL solo depende
        // del % de riesgo, ver CotizadorService) pero sí determina qué costo de afiliación
        // configurado se usa (Estudiante K y ARL Tipo Y pueden tener valores distintos).
        if ($plan->id === self::PLAN_SOLO_ARL_ID) {
            $idPreferido = ($nivelArl ?? 1) >= 4 ? self::MODALIDAD_ARL_TIPO_Y_ID : self::MODALIDAD_ESTUDIANTE_K_ID;
            if (in_array($idPreferido, $permitidas, true)) {
                return TipoModalidad::find($idPreferido);
            }
        }

        $prioridad = $esIndependiente
            ? self::PRIORIDAD_INDEPENDIENTE_IDS
            : array_merge(self::PRIORIDAD_DEPENDIENTE_IDS, self::PRIORIDAD_INDEPENDIENTE_IDS);

        foreach ($prioridad as $id) {
            if (in_array($id, $permitidas, true)) {
                $modalidad = TipoModalidad::find($id);
                if ($modalidad) {
                    return $modalidad;
                }
            }
        }

        // El plan solo existe en modalidades especiales (SimpleP, K, Y, ...) que no se
        // ofrecen por los canales públicos — mejor no cotizar que cotizar mal.
        return null;
    }

    /**
     * ¿Este pedido de componentes (sin pensión) necesita confirmar exención antes de cotizarlo
     * así? Réplica de la regla que ya aplica el cotizador admin (ContratoController +
     * form.blade.php): con la config "AFP obligatorio" activa, solo pueden omitir pensión los
     * hombres desde 55 años, las mujeres desde 50, o quienes tengan documento CE/PT/PP/PE/PA.
     *
     * Un cliente YA PENSIONADO queda exento sin importar edad, género ni documento: en el admin
     * se reconoce porque su ficha tiene el fondo "PENSIONADO" (Pension::ID_PENSIONADO).
     *
     * Excepciones que el admin también hace:
     *   - "Solo ARL": nunca lleva pensión por diseño (y sus modalidades son independientes o
     *     especiales K/Y/Gestión ARL, donde el admin lo exceptúa explícitamente).
     *   - Modalidades UPC (13) y Exterior (14): NO están en la lista de AFP obligatorio, así que
     *     ahí cualquier cliente puede omitir pensión sin acreditar exención.
     *
     * Si no se confirma la exención, hay que cotizar CON pensión (más seguro que asumir que sí
     * califica) — nunca se le pregunta la edad/género directamente al cliente.
     *
     * @param bool $desdeExterior El cliente cotiza desde fuera del país → modalidad Exterior (14).
     * @param bool $esUpc         Está pagando la salud de alguien fuera de su núcleo familiar → UPC (13).
     */
    public static function requiereConfirmarExencionPension(
        array $componentes,
        bool $exencionConfirmada,
        bool $desdeExterior = false,
        bool $esUpc = false
    ): bool {
        if ($componentes['incluye_pension']) {
            return false; // ya pidió pensión, no aplica
        }
        if (!$componentes['incluye_eps'] && !$componentes['incluye_caja'] && $componentes['incluye_arl']) {
            return false; // patrón de "Solo ARL" — exento por diseño
        }
        if ($desdeExterior || $esUpc) {
            return false; // modalidades 14 y 13: el admin no exige AFP en ellas
        }
        if (!ConfiguracionBrynex::reglaAfpObligatorio()) {
            return false; // la regla no está activa: cualquiera puede omitir pensión
        }
        return !$exencionConfirmada;
    }

    /**
     * Precio de cierre de Gestión ARL: 25% de descuento sobre el valor normal, SOLO para esta
     * modalidad (no lleva planilla mensual, solo la afiliación/radicado) — nunca inventar este
     * descuento en otro plan. Se ofrece como "plan B" después de decir el precio normal.
     */
    public static function cotizarGestionArlConDescuento(int $aliadoId, int $nivelArl): array
    {
        $plan      = PlanContrato::find(self::PLAN_SOLO_ARL_ID);
        $modalidad = TipoModalidad::find(self::MODALIDAD_GESTION_ARL_ID);

        $normal = self::cotizar($plan, $modalidad, $aliadoId, ['nivel_arl' => $nivelArl]);

        // Configurable por aliado (Configuración → Parámetros Especiales); 25% si no se configura.
        $porcentaje = (int) (ConfiguracionAliado::paraAliado($aliadoId)?->arl_descuento_porcentaje ?? 25);

        return [
            'valor_normal'     => $normal['total'],
            'valor_descuento'  => (float) (ceil(($normal['total'] * (100 - $porcentaje) / 100) / 100) * 100),
            'porcentaje'       => $porcentaje,
        ];
    }

    /**
     * Cotiza un plan ya resuelto: valor mensual (CotizadorService, con administracion/admon_asesor
     * /seguro SIEMPRE de la config genérica del aliado — nunca de la fila por plan, ver nota en
     * ConfiguracionAliado::paraAliado) + costo de afiliación sugerido (config por plan, con
     * fallback a la genérica) + plan de pago inicial (mes 1 solo afiliación, mes 2 proporcional +
     * administración completa, mes 3 en adelante el valor mensual completo).
     *
     * Opciones: salario, nivel_arl, dias, fecha_afiliacion (string 'AAAA-MM-DD' o null = hoy),
     * sin_plan_pago (bool, default false — omite el cálculo del plan de pago inicial cuando el
     * consumidor no lo va a usar, ahorrando una segunda pasada completa por CotizadorService).
     */
    public static function cotizar(PlanContrato $plan, TipoModalidad $tipoModalidad, ?int $aliadoId, array $opciones = []): array
    {
        $salario  = (float) ($opciones['salario'] ?? ConfiguracionBrynex::salarioMinimo());
        $nivelArl = (int) ($opciones['nivel_arl'] ?? 1);
        $dias     = (int) ($opciones['dias'] ?? 30);

        $cfgGeneral = ConfiguracionAliado::paraAliado($aliadoId);
        $cfgPlan    = ConfiguracionAliado::paraAliado($aliadoId, $plan->id);

        $resultado = CotizadorService::calcular([
            'tipo_modalidad_id' => $tipoModalidad->id,
            'plan_id'           => $plan->id,
            'n_arl'             => $nivelArl,
            'salario'           => $salario,
            'administracion'    => (float) ($cfgGeneral->administracion ?? 0),
            'admon_asesor'      => (float) ($cfgGeneral->admon_asesor ?? 0),
            'seguro'            => (float) ($cfgGeneral->seguro_valor ?? 0),
            'dias'              => $dias,
        ], $aliadoId);

        // costo_afiliacion SÍ varía por plan, pero además varía contrato a contrato dentro del
        // mismo plan en la práctica (el asesor lo ajusta al afiliar) — por eso se marca como
        // "sugerido", no como un cobro garantizado.
        //
        // Si hay una promoción vigente (plan-específica primero, luego general) reemplaza el
        // costo de afiliación normal — misma cascada plan→general que el precio normal, así
        // que web, WhatsApp y marketing ven SIEMPRE el mismo número, y al vencer vuelve solo.
        $cfgConPromo = collect([$cfgPlan, $cfgGeneral])->first(fn ($c) => $c?->promocionVigente());

        $resultado['costo_afiliacion_normal']    = (float) ($cfgPlan->costo_afiliacion ?? $cfgGeneral->costo_afiliacion ?? 0);
        $resultado['en_promocion']               = (bool) $cfgConPromo;
        $resultado['promocion_vence']            = $cfgConPromo?->promocion_vencimiento?->toDateString();
        $resultado['costo_afiliacion_sugerido']  = $cfgConPromo
            ? (float) $cfgConPromo->promocion_costo_afiliacion
            : $resultado['costo_afiliacion_normal'];

        // Afiliación específica por nivel de riesgo ARL (Configuración → Tarifas de Administración
        // → desplegar modalidad): el mismo plan puede cobrar afiliación distinta según el riesgo
        // (ej. Solo ARL riesgo 5 vs riesgo 1) y según la modalidad (Independiente vs Dependiente del
        // mismo plan EPS+ARL+AFP). Si no hay fila configurada para esta combinación exacta, se sigue
        // usando el valor general/promocional de arriba — no rompe nada mientras no se configure.
        if ($plan->incluye_arl) {
            $costoPorNivel = AfiliacionArlModalidad::where('aliado_id', $aliadoId)
                ->where('plan_id', $plan->id)
                ->where('tipo_modalidad_id', $tipoModalidad->id)
                ->where('nivel_arl', $nivelArl)
                ->value('costo_afiliacion');
            if ($costoPorNivel !== null) {
                $resultado['costo_afiliacion_sugerido'] = (float) $costoPorNivel;
            }
        }

        // Sin afiliación configurada en ningún lado (ni por plan, ni general, ni por nivel ARL, ni
        // promoción), se cotiza la afiliación al valor mensual del plan. Apenas el aliado configure
        // la suya, esta manda. Una promoción o un nivel ARL en $0 sí son configuración: se respetan.
        if (!$cfgConPromo && ($costoPorNivel ?? null) === null && $resultado['costo_afiliacion_normal'] <= 0) {
            $resultado['costo_afiliacion_normal']   = (float) $resultado['total'];
            $resultado['costo_afiliacion_sugerido'] = (float) $resultado['total'];
        }

        try {
            $fechaAfiliacion = !empty($opciones['fecha_afiliacion']) ? Carbon::parse($opciones['fecha_afiliacion']) : now();
        } catch (\Throwable $e) {
            $fechaAfiliacion = now();
        }
        $resultado['fecha_afiliacion'] = $fechaAfiliacion->toDateString();

        // Plan de pago inicial (gancho de venta): replica EXACTO el esquema real de facturación
        // (CobroContratoService::calcular/calcularDias) para quien se afilia hoy —
        // mes 1 = SOLO el cobro de afiliación (sin SS ni administración); mes 2 = proporcional de
        // los días restantes del mes vencido + administración COMPLETA (nunca se prorratea); mes
        // 3 en adelante = mes completo. Solo aplica al esquema de "afiliación pura": quien paga el
        // mes actual cobra proporcional + administración + afiliación TODO junto desde el mes de
        // ingreso, sin este diferimiento. El cotizador público no ofrece esa opción —el flag
        // `paga_mes_actual` se decide al crear el contrato—, así que aquí siempre es afiliación pura.
        if (!($opciones['sin_plan_pago'] ?? false)) {
            $diaIngreso         = (int) $fechaAfiliacion->day;
            $diasProporcionales = max(1, 30 - $diaIngreso + 1);

            $resultadoMes2 = CotizadorService::calcular([
                'tipo_modalidad_id' => $tipoModalidad->id,
                'plan_id'           => $plan->id,
                'n_arl'             => $nivelArl,
                'salario'           => $salario,
                'administracion'    => (float) ($cfgGeneral->administracion ?? 0),
                'admon_asesor'      => (float) ($cfgGeneral->admon_asesor ?? 0),
                'seguro'            => (float) ($cfgGeneral->seguro_valor ?? 0),
                'dias'              => $diasProporcionales,
            ], $aliadoId);

            $resultado['plan_pago_inicial'] = [
                'mes_1_nombre'              => ucfirst($fechaAfiliacion->translatedFormat('F')),
                'mes_1_afiliacion'          => $resultado['costo_afiliacion_sugerido'],
                'mes_2_nombre'              => ucfirst($fechaAfiliacion->copy()->addMonthNoOverflow()->translatedFormat('F')),
                'mes_2_dias_proporcionales' => $diasProporcionales,
                'mes_2_valor'               => $resultadoMes2['total'],
                'mes_3_nombre'              => ucfirst($fechaAfiliacion->copy()->addMonthsNoOverflow(2)->translatedFormat('F')),
                'mes_3_en_adelante'         => $resultado['total'],
            ];
        }

        return $resultado;
    }

    /**
     * Costo mensual de afiliarse directamente como independiente (sin intermediario), sobre el
     * IBC sugerido (mismo % que usa CotizadorService::ibcSugerido) — usado por la calculadora de
     * ahorro de la página pública. Redondeo idéntico al de CotizadorService (hacia arriba al 100).
     */
    public static function costoDirectoIndependiente(float $salario): array
    {
        $r   = fn ($v) => (float) (ceil($v / 100) * 100);
        $ibc = $r($salario * ConfiguracionBrynex::pctIbcIndependienteSugerido() / 100);

        $salud   = $r($ibc * ConfiguracionBrynex::pctSaludIndependiente() / 100);
        $pension = $r($ibc * ConfiguracionBrynex::pctPensionIndependiente() / 100);

        return [
            'ibc'     => $ibc,
            'salud'   => $salud,
            'pension' => $pension,
            'total'   => $salud + $pension,
        ];
    }
}
