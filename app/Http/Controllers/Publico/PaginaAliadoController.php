<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\ConfiguracionBrynex;
use App\Models\PaginaAliadoConfig;
use App\Models\PaginaLead;
use App\Models\Publicacion;
use App\Models\WhatsappConfig;
use App\Services\CotizacionPublicaService;
use App\Services\MetricaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Página web pública de un aliado (/aliado/{slug}, o su propio dominio si tiene uno mapeado —
 * ver rutas dinámicas en routes/web.php). Sin autenticación — el aliado se resuelve por slug
 * y la visibilidad depende de aliados.activo + pagina_aliado_config.activo. Todo el contenido
 * sale de la BD/configuración real del aliado; nada se hardcodea aquí.
 */
class PaginaAliadoController extends Controller
{
    public function show(Request $request, string $slug)
    {
        // Si el aliado tiene dominio propio y la visita vino por la ruta /aliado/{slug} EN ESE
        // MISMO dominio, es redundante (brygar.com/aliado/brygar) — se redirige a la raíz
        // limpia. OJO: la ruta domain-mapped también llama a show() para "/" con el slug como
        // default — hay que revisar la ruta real de la request, no solo el dominio, o esto
        // redirige "/" a "/" en bucle infinito.
        if ($request->is('aliado/*')) {
            $dominioActual = strtolower(preg_replace('/^www\./', '', $request->getHost()));
            $dominioAliado = Aliado::where('slug', $slug)->value('dominio_propio');
            if ($dominioAliado && strtolower(preg_replace('/^www\./', '', $dominioAliado)) === $dominioActual) {
                return redirect()->to('/' . ($request->getQueryString() ? '?' . $request->getQueryString() : ''), 301);
            }
        }

        $datos = Cache::remember(self::cacheKey($slug), 600, function () use ($slug) {
            $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
            if (!$aliado) {
                return null;
            }

            $config = PaginaAliadoConfig::where('aliado_id', $aliado->id)->where('activo', true)->first();
            if (!$config) {
                return null;
            }

            return $this->armarDatos($aliado, $config);
        });

        if (!$datos) {
            abort(404);
        }

        // Fuera del cache a propósito: si la visita se contara dentro del closure de arriba,
        // solo se registraría ~1 vez cada 10 min (la duración del cache) sin importar el
        // tráfico real — aquí sí corre en cada request real.
        MetricaService::registrar($datos['aliado']->id, MetricaService::VISITA);

        $datos['urlCanonica'] = $this->urlCanonica($datos['aliado'], $request);

        return view('publico.aliado.show', $datos);
    }

    /**
     * Vista previa vía URL firmada (usada desde el CMS admin): ignora el flag `activo` de la
     * página y nunca usa cache, para que el aliado vea siempre el estado recién guardado.
     * El aliado en sí debe seguir activo — no tiene sentido previsualizar un aliado inexistente.
     * No registra métricas (no es una visita real).
     */
    public function preview(Request $request, string $slug)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            abort(404);
        }

        $config = PaginaAliadoConfig::where('aliado_id', $aliado->id)->first()
            ?? new PaginaAliadoConfig(['aliado_id' => $aliado->id]);

        $datos = $this->armarDatos($aliado, $config);
        $datos['urlCanonica'] = $this->urlCanonica($aliado, $request);

        return response()
            ->view('publico.aliado.show', $datos)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Páginas legales estáticas (política de privacidad, términos, eliminación de datos) —
     * requeridas por Meta for Developers para publicar la app de Facebook/Instagram del aliado
     * (Configuración de la app > Información básica). Contenido real, con los datos de
     * contacto reales del aliado — nunca cacheadas (son documentos legales, siempre frescos).
     */
    public function politicaPrivacidad(Request $request, string $slug)
    {
        return $this->documentoLegal($request, $slug, 'privacidad');
    }

    public function terminosServicio(Request $request, string $slug)
    {
        return $this->documentoLegal($request, $slug, 'terminos');
    }

    public function eliminacionDatos(Request $request, string $slug)
    {
        return $this->documentoLegal($request, $slug, 'eliminacion-datos');
    }

    private function documentoLegal(Request $request, string $slug, string $tipo)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            abort(404);
        }

        return view('publico.aliado.legal', [
            'aliado'          => $aliado,
            'config'          => new PaginaAliadoConfig(['aliado_id' => $aliado->id]),
            'tipo'            => $tipo,
            'whatsapp'        => $this->numeroWhatsappBot($aliado),
            'colorPrimario'   => $this->colorSeguro($aliado->color_primario),
            'textoSobreBrand' => $this->textoLegibleSobre($aliado->color_primario),
            'urlCanonica'     => $this->urlCanonica($aliado, $request),
        ]);
    }

    /**
     * Modalidades que representan una afiliación REAL como empleado dependiente (0 = "Dependiente
     * E", 7 = "EPS+ARL"). Si al resolver la modalidad de un plan para el perfil dependiente el
     * resultado cae en cualquier otra modalidad (p.ej. 10 = Independientes), es porque ese plan
     * en la práctica NO se ofrece como dependiente — el cascade de resolverModalidadPermitida()
     * solo lo encontró ahí de rebote (ver PRIORIDAD_DEPENDIENTE_IDS en CotizacionPublicaService).
     * Mostrarlo como "columna empleado" sería repetir el mismo número de la columna independiente
     * disfrazado de otra cosa — más confuso que útil.
     */
    private const MODALIDADES_DEPENDIENTE_REAL = [0, 7];

    /**
     * Cotizador "Arma tu plan": recibe las coberturas elegidas por el visitante y devuelve DOS
     * cotizaciones — empleado (dependiente) e independiente — calculadas con la MISMA lógica que
     * usa la IA (CotizacionPublicaService), nunca en JS. Si la combinación pedida no existe
     * realmente como dependiente, esa columna viene en null y el frontend explica por qué. Nunca
     * expone el desglose interno por componente ni la comisión del asesor — mismo criterio de
     * privacidad que CotizarPlanPublicoTool.
     */
    public function cotizar(Request $request, string $slug)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            abort(404);
        }
        $config = PaginaAliadoConfig::where('aliado_id', $aliado->id)->where('activo', true)->first();
        if (!$config) {
            abort(404);
        }

        $validado = $request->validate([
            'incluye_eps'     => 'required|boolean',
            'incluye_arl'     => 'required|boolean',
            'incluye_pension' => 'required|boolean',
            'incluye_caja'    => 'required|boolean',
            'nivel_arl'       => 'nullable|integer|min:1|max:5',
            'ingresos'        => 'nullable|numeric|min:0|max:999999999',
        ]);

        $componentes = [
            'incluye_eps'     => $validado['incluye_eps'],
            'incluye_arl'     => $validado['incluye_arl'],
            'incluye_pension' => $validado['incluye_pension'],
            'incluye_caja'    => $validado['incluye_caja'],
        ];

        if (!in_array(true, $componentes, true)) {
            return response()->json(['error' => 'Selecciona al menos una cobertura.'], 422);
        }

        $nivelArl       = $validado['nivel_arl'] ?? 1;
        $salarioMinimo  = ConfiguracionBrynex::salarioMinimo();
        $ingresos       = $validado['ingresos'] ?? $salarioMinimo;

        // Como empleado, el ingreso ES la base de cotización (IBC). Como independiente, la ley
        // exige cotizar sobre el 40% de los ingresos, nunca por debajo del salario mínimo — el
        // mismo % que ya usa la calculadora de ahorro (pctIbcIndependienteSugerido).
        $baseDependiente   = $ingresos;
        $baseIndependiente = max($salarioMinimo, $ingresos * ConfiguracionBrynex::pctIbcIndependienteSugerido() / 100);

        $dependiente   = $this->cotizarPerfil($componentes, false, $aliado->id, $config, $baseDependiente, $nivelArl);
        $independiente = $this->cotizarPerfil($componentes, true, $aliado->id, $config, $baseIndependiente, $nivelArl);

        if (!$dependiente && !$independiente) {
            return response()->json(['error' => 'No tenemos un plan disponible con esa combinación. Escríbenos por WhatsApp y te asesoramos.'], 422);
        }

        MetricaService::registrar($aliado->id, MetricaService::COTIZACION_COMPLETADA, [
            'plan' => ($dependiente['plan_nombre'] ?? null) ?: $independiente['plan_nombre'],
        ]);

        return response()->json([
            'ingresos'           => $ingresos,
            'base_dependiente'   => $baseDependiente,
            'base_independiente' => $baseIndependiente,
            'precios_visibles'   => (bool) $config->mostrar_precios,
            'precios_modo'       => $config->precios_modo,
            'dependiente'        => $dependiente,
            'independiente'      => $independiente,
        ]);
    }

    /**
     * Cotiza una combinación de coberturas para UN perfil (dependiente o independiente).
     * Devuelve null si ese perfil no aplica: sin plan, sin modalidad, o — para dependiente — si
     * la modalidad resuelta no es una modalidad de empleado real (ver MODALIDADES_DEPENDIENTE_REAL).
     */
    private function cotizarPerfil(
        array $componentes,
        bool $independiente,
        int $aliadoId,
        PaginaAliadoConfig $config,
        float $base,
        int $nivelArl
    ): ?array {
        [$plan, $coincidenciaExacta] = CotizacionPublicaService::resolverPlan($componentes, $independiente);
        if (!$plan) {
            return null;
        }

        $modalidad = CotizacionPublicaService::resolverModalidadPermitida(
            $plan, $independiente, false, null, false, false, false, $nivelArl
        );
        if (!$modalidad) {
            return null;
        }

        if (!$independiente && !in_array((int) $modalidad->id, self::MODALIDADES_DEPENDIENTE_REAL, true)) {
            return null;
        }

        $resultado = CotizacionPublicaService::cotizar($plan, $modalidad, $aliadoId, [
            'salario'   => $base,
            'nivel_arl' => $nivelArl,
        ]);

        $salida = [
            'plan_nombre'            => $plan->nombre,
            'coincidencia_exacta'    => $coincidenciaExacta,
            'componentes_incluidos'  => [
                'eps'     => (bool) $plan->incluye_eps,
                'arl'     => (bool) $plan->incluye_arl,
                'pension' => (bool) $plan->incluye_pension,
                'caja'    => (bool) $plan->incluye_caja,
            ],
        ];

        // Regla real (Configuración → Modalidades → "AFP obligatorio"): la web no puede
        // confirmar la exención (edad/género/extranjería) de forma natural como la IA. Se
        // respeta LITERALMENTE lo que el visitante marcó (nunca se le sube el plan en
        // silencio) y en vez de eso se avisa que el precio puede no aplicarle si no califica
        // para la exención, remitiéndolo a confirmar por WhatsApp.
        if (CotizacionPublicaService::requiereConfirmarExencionPension($componentes, false)) {
            $salida['nota_afp'] = 'Este valor NO incluye pensión. Solo puedes omitirla si estás exento: ya estás '
                . 'pensionado, eres hombre desde 55 años, mujer desde 50, o extranjero con cédula de extranjería o '
                . 'permiso temporal. Si no calificas, escríbenos por WhatsApp para confirmar el valor con pensión.';
        }

        if (!$coincidenciaExacta) {
            $salida['nota_ajuste'] = 'No existe un plan exacto con esa combinación; el más cercano disponible es "'
                . $plan->nombre . '".';
        }

        if ($config->mostrar_precios) {
            // Solo afiliación (pago único) + valor mensual — el cotizador público no muestra el
            // desglose por meses/proporcional: confunde al visitante con cifras que no pidió.
            $salida['valor_mensual_total']       = $resultado['total'];
            $salida['costo_afiliacion_sugerido'] = $resultado['costo_afiliacion_sugerido'];
        }

        return $salida;
    }

    /**
     * Captura un lead desde la página pública (cotizador o formulario de contacto). Nunca pide
     * cédula ni datos sensibles — solo nombre y celular. Protegido con throttle + honeypot.
     */
    public function lead(Request $request, string $slug)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            abort(404);
        }

        // Honeypot: campo oculto que un humano nunca llena; si viene con contenido, es un bot.
        if ($request->filled('sitio_web')) {
            return response()->json(['ok' => true]);
        }

        $validado = $request->validate([
            'nombre'                  => 'required|string|max:150',
            'celular'                  => 'required|string|max:30|regex:/^[0-9+\s\-]{7,30}$/',
            'perfil'                  => 'nullable|in:dependiente,independiente',
            'incluye_eps'             => 'nullable|boolean',
            'incluye_arl'             => 'nullable|boolean',
            'incluye_pension'         => 'nullable|boolean',
            'incluye_caja'            => 'nullable|boolean',
            'ingreso_mensual'         => 'nullable|numeric|min:0|max:999999999',
            'valor_mensual_cotizado'  => 'nullable|numeric|min:0|max:999999999',
            'plan_interes'            => 'nullable|string|max:150',
            'origen'                  => 'nullable|in:cotizador,contacto',
            'consiento_datos'         => 'required|accepted',
        ]);

        PaginaLead::create([
            'aliado_id'              => $aliado->id,
            'nombre'                 => $validado['nombre'],
            'celular'                => $validado['celular'],
            'perfil'                 => $validado['perfil'] ?? null,
            'coberturas'             => [
                'eps'     => (bool) ($validado['incluye_eps'] ?? false),
                'arl'     => (bool) ($validado['incluye_arl'] ?? false),
                'pension' => (bool) ($validado['incluye_pension'] ?? false),
                'caja'    => (bool) ($validado['incluye_caja'] ?? false),
            ],
            'ingreso_mensual'        => $validado['ingreso_mensual'] ?? null,
            'valor_mensual_cotizado' => $validado['valor_mensual_cotizado'] ?? null,
            'plan_interes'           => $validado['plan_interes'] ?? null,
            'origen'                 => $validado['origen'] ?? 'cotizador',
            'ip_hash'                => hash('sha256', $request->ip() . config('app.key')),
        ]);

        MetricaService::registrar($aliado->id, MetricaService::LEAD_CAPTURADO, ['origen' => $validado['origen'] ?? 'cotizador']);

        return response()->json(['ok' => true]);
    }

    /** Beacon liviano para eventos que solo el navegador puede ver (hoy: clic en WhatsApp). */
    public function registrarMetrica(Request $request, string $slug)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            return response()->json(['ok' => false], 404);
        }

        // Whitelist estricta: solo eventos que de verdad solo puede reportar el cliente.
        // visita/cotizacion_completada/lead_capturado se registran del lado del servidor,
        // nunca confiando en lo que reporte el navegador.
        $validado = $request->validate(['tipo' => 'required|in:clic_whatsapp']);

        MetricaService::registrar($aliado->id, $validado['tipo']);

        return response()->json(['ok' => true]);
    }

    /** Sitemap de las páginas públicas de aliados activos — misma regla de visibilidad que show(). */
    public function sitemap()
    {
        $aliados = Aliado::where('activo', true)
            ->whereHas('paginaConfig', fn ($q) => $q->where('activo', true))
            ->get(['slug', 'dominio_propio']);

        $urls = $aliados->map(fn ($a) => $a->dominio_propio
            ? 'https://' . $a->dominio_propio . '/'
            : route('publico.aliado', $a->slug)
        );

        return response()
            ->view('publico.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }

    /**
     * URL canónica de la página: si la visita llegó por el dominio propio del aliado (ej.
     * brygar.com), la canónica es la raíz de ESE dominio; si llegó por brynex.co/aliado/{slug},
     * la canónica es esa misma ruta. Evita contenido duplicado en buscadores entre ambas URLs.
     */
    private function urlCanonica(Aliado $aliado, Request $request): string
    {
        $host = strtolower($request->getHost());
        $dominioPropio = $aliado->dominio_propio ? strtolower(preg_replace('/^www\./', '', $aliado->dominio_propio)) : null;

        if ($dominioPropio && ($host === $dominioPropio || $host === 'www.' . $dominioPropio)) {
            return $request->getSchemeAndHttpHost() . '/';
        }

        return route('publico.aliado', $aliado->slug);
    }

    /**
     * Número de WhatsApp para el botón de la página pública: prioriza el número real del
     * bot (WhatsappConfig::numero_telefono, el que escucha el webhook) para que los leads
     * de la web lleguen al mismo lugar que todo lo demás — con fallback a los campos del
     * aliado solo si no tiene el bot configurado.
     */
    private function numeroWhatsappBot(Aliado $aliado): ?string
    {
        $numeroBot = WhatsappConfig::where('aliado_id', $aliado->id)->where('activo', true)->value('numero_telefono');
        return $numeroBot ?: ($aliado->whatsapp ?: $aliado->celular);
    }

    private function armarDatos(Aliado $aliado, PaginaAliadoConfig $config): array
    {
        return [
            'aliado'          => $aliado,
            'config'          => $config,
            'faqs'            => $aliado->paginaFaqs()->get(['id', 'pregunta', 'respuesta']),
            'planes'          => $this->planesPublicos($aliado, $config),
            'promos'          => $this->promosPublicadas($aliado),
            'colorPrimario'   => $this->colorSeguro($aliado->color_primario),
            'textoSobreBrand' => $this->textoLegibleSobre($aliado->color_primario),
            'whatsapp'        => $this->numeroWhatsappBot($aliado),
            'salarioMinimo'   => ConfiguracionBrynex::salarioMinimo(),
        ];
    }

    private function planesPublicos(Aliado $aliado, PaginaAliadoConfig $config): \Illuminate\Support\Collection
    {
        return CotizacionPublicaService::planesPublicosConPrecio($aliado->id, $config->mostrar_precios);
    }

    /** Últimas piezas publicadas con destino "web" (Fase 4 — generador de publicidad). */
    private function promosPublicadas(Aliado $aliado): \Illuminate\Support\Collection
    {
        return Publicacion::where('aliado_id', $aliado->id)
            ->where('estado', Publicacion::ESTADO_PUBLICADA)
            ->whereJsonContains('destinos', 'web')
            ->orderByDesc('publicada_at')
            ->limit(6)
            ->get(['id', 'titulo', 'copy', 'imagen_path', 'tipo_pieza', 'video_path', 'publicada_at']);
    }

    /**
     * Descarga el MP4 de una pieza de "Novedades" con un nombre legible, para que los asesores
     * lo compartan. Solo piezas que la página ya muestra (publicadas, destino web, del aliado
     * del slug): nunca un borrador ni el video de otro aliado.
     */
    public function descargarVideo(string $slug, int $publicacion)
    {
        $aliado = Aliado::where('slug', $slug)->where('activo', true)->first();
        if (!$aliado) {
            abort(404);
        }

        $pieza = Publicacion::where('aliado_id', $aliado->id)
            ->where('id', $publicacion)
            ->where('estado', Publicacion::ESTADO_PUBLICADA)
            ->whereJsonContains('destinos', 'web')
            ->whereNotNull('video_path')
            ->first(['id', 'titulo', 'video_path']);

        if (!$pieza || !Storage::disk('public')->exists($pieza->video_path)) {
            abort(404);
        }

        $nombre = Str::limit(Str::slug($aliado->nombre . ' ' . $pieza->titulo), 80, '') ?: "video-{$pieza->id}";

        return Storage::disk('public')->download($pieza->video_path, $nombre . '.mp4');
    }

    /** Valida que sea un HEX de 6 dígitos; si no, usa el azul por defecto de la marca BryNex. */
    private function colorSeguro(?string $hex): string
    {
        return ($hex && preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) ? $hex : '#2563eb';
    }

    /** Elige texto blanco o azul oscuro según el brillo del color de marca (heurística YIQ). */
    private function textoLegibleSobre(?string $hex): string
    {
        $hex = $this->colorSeguro($hex);
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $brillo = ($r * 299 + $g * 587 + $b * 114) / 1000;
        return $brillo > 150 ? '#0a1628' : '#ffffff';
    }

    public static function cacheKey(string $slug): string
    {
        return "pagina_aliado_{$slug}";
    }

    public static function invalidarCache(string $slug): void
    {
        Cache::forget(self::cacheKey($slug));
    }
}
