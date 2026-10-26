<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\IaConfiguracionAliado;
use App\Models\PautaConfig;
use App\Models\Publicacion;
use App\Models\PublicidadVideoIa;
use App\Models\RedSocialConfig;
use App\Services\CotizacionPublicaService;
use App\Services\Publicidad\CopiaIaGenerator;
use App\Services\Publicidad\GeminiImagenGenerator;
use App\Services\Publicidad\PublicacionPublisher;
use App\Services\Publicidad\VeoVideoGenerator;
use App\Services\RedesSociales\MetaAdsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Generador de publicidad: piezas por plantilla (canvas, en el navegador del admin) o por IA
 * (copy con la clave de asistente del aliado; imagen opcional con Gemini). Flujo de aprobación
 * (aliado o superadmin BryNex, cualquiera con acceso al panel) y publicación a web + redes.
 */
class PublicidadController extends Controller
{
    public function __construct()
    {
        // Quién entra lo decide el `permiso:` de cada ruta (routes/web.php). El
        // `role:` que había aquí bloqueaba antes, aunque el permiso estuviera otorgado.
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $query = Publicacion::where('aliado_id', $aliado->id)->orderByDesc('created_at');
        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        $publicaciones = $query->paginate(20)->withQueryString();
        $pendientes    = Publicacion::where('aliado_id', $aliado->id)->pendientes()->count();
        $tieneGemini   = IaConfiguracionAliado::paraAliado($aliado->id)->tieneGemini();

        // TRM en vivo (mismo servicio de Finanzas) para mostrar el costo estimado en COP.
        $trmCop = app(\App\Services\Finanzas\CriptoApiService::class)->getPrecioUsdt()['precio_cop'];

        return view('admin.publicidad.index', compact('aliado', 'publicaciones', 'pendientes', 'tieneGemini', 'trmCop'));
    }

    public function create(Request $request)
    {
        $aliado    = $this->aliadoActivo();
        $iaConfig  = IaConfiguracionAliado::paraAliado($aliado->id);
        $redesActivas = RedSocialConfig::where('aliado_id', $aliado->id)->where('activo', true)->pluck('red');
        $planes = CotizacionPublicaService::planesDestacadosConPrecio($aliado->id, true);

        return view('admin.publicidad.create', [
            'aliado'       => $aliado,
            'tieneIaTexto' => !empty($iaConfig->credencialesEfectivas()['api_key']),
            'tieneGemini'  => $iaConfig->tieneGemini(),
            'redesActivas' => $redesActivas,
            'planes'       => $planes,
            'videoIdPreseleccionado' => $request->integer('video_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $validated = $request->validate([
            'titulo'          => 'required|string|max:150',
            'copy'            => 'nullable|string|max:2000',
            'imagen'          => 'required_without:imagen_path_generado|nullable|image|max:5120',
            'imagen_path_generado' => 'required_without:imagen|nullable|string|max:255',
            'video_path_generado'  => 'nullable|string|max:255',
            'video_modelo'    => 'nullable|string|max:40',
            'video_ia_id'     => 'nullable|integer',
            'origen'          => 'required|in:plantilla,ia,subida',
            'plantilla_usada' => 'nullable|string|max:60',
            'estilo_imagen'   => 'nullable|in:ilustracion,fotorrealista',
            'destinos'        => 'required|array|min:1',
            'destinos.*'      => 'in:' . implode(',', Publicacion::DESTINOS_DISPONIBLES),
            'programada_at'   => 'nullable|date|after_or_equal:now',
        ]);

        if ($request->hasFile('imagen')) {
            $rutaImagen = $request->file('imagen')->store('publicidad', 'public');
        } else {
            // Viene de una generación previa (IA) ya guardada en storage — solo confirmamos que exista.
            // Para video, este mismo campo trae el poster/primer frame (ver pestaña Video IA).
            $rutaImagen = $validated['imagen_path_generado'];
            if (!Storage::disk('public')->exists($rutaImagen)) {
                return back()->withErrors(['imagen' => 'La imagen generada ya no está disponible, genera de nuevo.'])->withInput();
            }
        }

        $esVideo = !empty($validated['video_path_generado']);
        if ($esVideo && !Storage::disk('public')->exists($validated['video_path_generado'])) {
            return back()->withErrors(['video' => 'El video generado ya no está disponible, genera de nuevo.'])->withInput();
        }

        $costoEstimado = null;
        if ($esVideo && !empty($validated['video_ia_id'])) {
            $costoEstimado = PublicidadVideoIa::where('aliado_id', $aliado->id)->find($validated['video_ia_id'])?->costo_estimado_usd;
        } elseif (!$esVideo && $validated['origen'] === 'ia' && !empty($validated['estilo_imagen'])) {
            $modeloImagen = $validated['estilo_imagen'] === 'fotorrealista'
                ? GeminiImagenGenerator::MODELO_FOTORREALISTA
                : GeminiImagenGenerator::MODELO_ILUSTRACION;
            $costoEstimado = GeminiImagenGenerator::costoEstimadoUsd($modeloImagen);
        }

        Publicacion::create([
            'aliado_id'       => $aliado->id,
            'titulo'          => $validated['titulo'],
            'copy'            => $validated['copy'] ?? null,
            'imagen_path'     => $rutaImagen,
            'tipo_pieza'      => $esVideo ? 'video' : 'imagen',
            'video_path'      => $esVideo ? $validated['video_path_generado'] : null,
            'video_modelo'    => $esVideo ? ($validated['video_modelo'] ?? null) : null,
            'costo_estimado_usd' => $costoEstimado,
            'origen'          => $validated['origen'],
            'plantilla_usada' => $validated['plantilla_usada'] ?? null,
            'estilo_imagen'   => $validated['estilo_imagen'] ?? null,
            'destinos'        => $validated['destinos'],
            'programada_at'   => $validated['programada_at'] ?? null,
            'estado'          => Publicacion::ESTADO_PENDIENTE,
            'creado_por'      => Auth::id(),
        ]);

        return redirect()->route('admin.publicidad.index')->with('success', 'Pieza creada y enviada a aprobación.');
    }

    public function show(int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->with(['creador', 'aprobador', 'metricas'])->findOrFail($id);

        $conversacionesWa = \App\Models\WhatsappConversacion::where('origen_publicacion_id', $publicacion->id)
            ->orderByDesc('created_at')
            ->get(['id', 'nombre_contacto', 'wa_contact_id', 'created_at']);

        // TRM en vivo (mismo servicio de Finanzas — USDT/COP de Binance, con la TRM oficial
        // como respaldo, caché de 15 min) — no hay que actualizarla a mano.
        $trmCop = app(\App\Services\Finanzas\CriptoApiService::class)->getPrecioUsdt()['precio_cop'];

        return view('admin.publicidad.show', compact('aliado', 'publicacion', 'conversacionesWa', 'trmCop'));
    }

    public function aprobar(int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        if ($publicacion->estado !== Publicacion::ESTADO_PENDIENTE) {
            return back()->withErrors(['estado' => 'Solo se pueden aprobar piezas pendientes.']);
        }

        $publicacion->update([
            'estado'       => Publicacion::ESTADO_APROBADA,
            'aprobado_por' => Auth::id(),
        ]);

        if ($publicacion->listaParaPublicar()) {
            PublicacionPublisher::publicar($publicacion);
            return redirect()->route('admin.publicidad.show', $publicacion->id)->with('success', 'Pieza aprobada y publicada.');
        }

        return redirect()->route('admin.publicidad.show', $publicacion->id)
            ->with('success', 'Pieza aprobada — se publicará automáticamente el ' . $publicacion->programada_at->format('d/m/Y H:i') . '.');
    }

    public function rechazar(Request $request, int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        $validated = $request->validate(['motivo_rechazo' => 'required|string|max:500']);

        $publicacion->update([
            'estado'         => Publicacion::ESTADO_RECHAZADA,
            'aprobado_por'   => Auth::id(),
            'motivo_rechazo' => $validated['motivo_rechazo'],
        ]);

        return redirect()->route('admin.publicidad.index')->with('success', 'Pieza rechazada.');
    }

    public function reintentar(int $id, string $red)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        abort_unless($publicacion->estado === Publicacion::ESTADO_PUBLICADA, 400);
        abort_unless(in_array($red, Publicacion::DESTINOS_DISPONIBLES, true), 404);

        $resultado = PublicacionPublisher::reintentar($publicacion, $red);

        return back()->with($resultado['ok'] ? 'success' : 'error', $resultado['mensaje']);
    }

    public function destroy(int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        abort_unless(in_array($publicacion->estado, [Publicacion::ESTADO_BORRADOR, Publicacion::ESTADO_RECHAZADA], true), 400);

        Storage::disk('public')->delete($publicacion->imagen_path);
        $publicacion->delete();

        return redirect()->route('admin.publicidad.index')->with('success', 'Pieza eliminada.');
    }

    // ── Piloto automático (community manager IA) ──────────────────────────

    public function autopilot()
    {
        $aliado   = $this->aliadoActivo();
        $config   = \App\Models\AutopilotConfig::paraAliado($aliado->id);
        $iaConfig = IaConfiguracionAliado::paraAliado($aliado->id);

        $piezas = Publicacion::where('aliado_id', $aliado->id)
            ->where('origen', 'ia_auto')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        // Ranking de piezas publicadas por interacciones + conversaciones de WhatsApp
        // atribuidas (real) — para el card de rendimiento.
        $ranking = Publicacion::where('aliado_id', $aliado->id)
            ->publicadas()
            ->with('metricas')
            ->get()
            ->filter(fn ($p) => $p->metricas->isNotEmpty())
            ->map(fn ($p) => [
                'pieza'         => $p,
                'interacciones' => $p->metricas->sum(fn ($m) => $m->interacciones()),
                'alcance'       => $p->metricas->sum('alcance'),
                'conversaciones_wa' => \App\Models\WhatsappConversacion::where('origen_publicacion_id', $p->id)->count(),
            ])
            ->sortByDesc(fn ($f) => $f['conversaciones_wa'] * 3 + $f['interacciones'])
            ->take(10)
            ->values();

        return view('admin.publicidad.autopilot', [
            'aliado'       => $aliado,
            'config'       => $config,
            'tieneIaTexto' => !empty($iaConfig->credencialesEfectivas()['api_key']),
            'tieneGemini'  => $iaConfig->tieneGemini(),
            'piezas'       => $piezas,
            'ranking'      => $ranking,
            'planesPromocionables' => collect(\App\Services\Publicidad\CatalogoPlanesPromocion::todos())
                ->map(fn ($def) => $def['nombre']),
        ]);
    }

    public function autopilotUpdate(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $validated = $request->validate([
            'activo'         => 'required|boolean',
            'modo'           => 'required|in:aprobar,auto',
            'hora'           => 'required|date_format:H:i',
            'dias'           => 'nullable|array',
            'dias.*'         => 'integer|between:1,7',
            'dias_flyer'     => 'nullable|array',
            'dias_flyer.*'   => 'integer|between:1,7',
            'estilo_imagen'  => 'required|in:ilustracion,fotorrealista,alternar',
            'formato'        => 'required|in:reel,imagen',
            'video_nivel'    => 'required|in:lite,standard',
            'video_duracion' => 'required|in:8,16,24',
        ]);

        $config = \App\Models\AutopilotConfig::paraAliado($aliado->id);
        $config->update([
            'activo'         => $validated['activo'],
            'modo'           => $validated['modo'],
            'hora'           => $validated['hora'],
            'dias'           => !empty($validated['dias']) && count($validated['dias']) < 7 ? array_values($validated['dias']) : null,
            'dias_flyer'     => !empty($validated['dias_flyer']) ? array_values($validated['dias_flyer']) : null,
            'estilo_imagen'  => $validated['estilo_imagen'],
            'formato'        => $validated['formato'],
            'video_nivel'    => $validated['video_nivel'],
            'video_duracion' => (int) $validated['video_duracion'],
        ]);

        return redirect()->route('admin.publicidad.autopilot')->with('success', 'Piloto automático actualizado.');
    }

    /** Genera la pieza de hoy ya mismo, sin esperar a la hora programada — usa el modo/estilo ya guardados. */
    public function autopilotGenerarAhora()
    {
        $aliado = $this->aliadoActivo();
        $config = \App\Models\AutopilotConfig::paraAliado($aliado->id);

        $resultado = \App\Services\Publicidad\AutopilotGenerator::generarPiezaDelDia($aliado, $config);

        if (!$resultado['ok']) {
            return redirect()->route('admin.publicidad.autopilot')->with('error', $resultado['error']);
        }

        // Un Reel todavía no tiene pieza: Veo tarda 1-3 min y `videos:procesar` la crea al
        // terminar. Se avisa así en vez de asumir que siempre hay una Publicacion.
        if ($p = $resultado['publicacion'] ?? null) {
            return redirect()->route('admin.publicidad.autopilot')
                ->with('success', "Pieza #{$p->id} generada — [{$p->tema}] {$p->titulo} ({$p->etiquetaEstado()}).");
        }

        $v = $resultado['video'] ?? null;
        return redirect()->route('admin.publicidad.autopilot')->with(
            'success',
            $v
                ? "🎬 Reel en generación ({$v->duracion_seg}s, ~USD {$v->costo_estimado_usd}). Aparecerá en la lista en 1-3 minutos, cuando el video termine."
                : 'Pieza generada.'
        );
    }

    /** Genera un flyer promocional de un plan ya mismo (para probar la plantilla o adelantar la pieza). */
    public function flyerGenerarAhora(Request $request)
    {
        $aliado = $this->aliadoActivo();
        $config = \App\Models\AutopilotConfig::paraAliado($aliado->id);

        $validated = $request->validate([
            'plan'      => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Publicidad\CatalogoPlanesPromocion::todos()))],
            'nivel_arl' => 'nullable|integer|between:1,5',
            'estilo'    => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Publicidad\FlyerPlanBuilder::ESTILOS))],
        ]);

        $resultado = \App\Services\Publicidad\FlyerPlanGenerator::generar(
            $aliado,
            $config,
            $validated['plan'] ?? null,
            $validated['nivel_arl'] ?? null,
            $validated['estilo'] ?? null
        );

        if (!$resultado['ok']) {
            return redirect()->route('admin.publicidad.autopilot')->with('error', $resultado['error']);
        }

        $p = $resultado['publicacion'];
        return redirect()->route('admin.publicidad.show', $p->id)->with('success', "Flyer #{$p->id} generado — {$p->titulo} ({$p->etiquetaEstado()}).");
    }

    // ── Generación asistida (AJAX) ────────────────────────────────────────

    public function generarCopia(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $validated = $request->validate([
            'tipo_pieza' => 'required|string|max:100',
            'contexto'   => 'required|string|max:500',
        ]);

        $resultado = CopiaIaGenerator::generarVariantes($aliado->id, $aliado->nombre, $validated['tipo_pieza'], $validated['contexto']);

        return response()->json($resultado);
    }

    public function generarImagen(Request $request)
    {
        $aliado = $this->aliadoActivo();
        $iaConfig = IaConfiguracionAliado::paraAliado($aliado->id);

        if (!$iaConfig->tieneGemini()) {
            return response()->json(['ok' => false, 'rutas' => [], 'error' => 'No hay una clave de Gemini configurada (ver Asistente Virtual).']);
        }

        $validated = $request->validate([
            'prompt' => 'required|string|max:1000',
            'estilo' => 'nullable|in:ilustracion,fotorrealista',
        ]);

        $modelo = $validated['estilo'] === 'fotorrealista'
            ? GeminiImagenGenerator::MODELO_FOTORREALISTA
            : GeminiImagenGenerator::MODELO_ILUSTRACION;

        $logoParaReferencia = $aliado->logo_marca_claro ?: $aliado->logo;
        $rutaLogo = $logoParaReferencia ? Storage::disk('public')->path($logoParaReferencia) : null;

        $instruccionTipografia = ' Compórtate como un director creativo experto en publicidad para redes sociales (Meta Ads): compón la pieza para llamar la atención, verse profesional y generar clics — no como una simple foto o ilustración de escena sin texto. '
            . 'Decide tú, en español, todo el contenido: un titular principal corto y muy impactante (máximo 5 palabras, que genere curiosidad o resuelva un problema), un subtítulo que explique el beneficio principal, entre 3 y 5 beneficios clave (cada uno con un ícono relacionado + texto corto), y un llamado a la acción claro sobre afiliarse ya, agendar una asesoría o pedir una consulta (ej. "Afíliate ya", "Agenda tu asesoría", "Consulta sin costo") — NUNCA uses frases de urgencia o escasez falsa como "cupos limitados", "inscripciones abiertas" o "solo hoy": la afiliación está siempre disponible, no es una oferta por tiempo limitado. '
            . 'Usa tipografía bold, alto contraste, degradados modernos, sombras y buena jerarquía visual — estilo campaña premium, no plano. '
            . 'Si aporta, agrega elementos de conversión como una garantía o el proceso sin trámites — pero NUNCA generes un código QR (los que dibuja un modelo de imagen no funcionan, no escanean de verdad), y NUNCA inventes datos concretos que no te haya dado (teléfonos, precios, fechas exactas, nombres de personas, cupos, plazos) — mantén esas menciones genéricas. '
            . 'Cuida la ortografía y las tildes del español. '
            . "NUNCA dibujes tú mismo ningún logo, isotipo, ícono de marca ni el nombre \"{$aliado->nombre}\" a modo de logo en NINGUNA parte de la imagen — ni en la esquina inferior derecha ni en ninguna otra — eso lo agrega el sistema después, por separado, con el logo real. "
            . 'Deja la esquina inferior derecha libre de texto e íconos, pero SIN dibujar ahí ningún recuadro, marco o bloque de color sólido — debe verse como parte natural del fondo/escena, nunca como un espacio en blanco marcado; ahí se superpone el logo real de la marca después, por separado.';

        $prompt = $validated['prompt'] . $instruccionTipografia . ($rutaLogo
            ? ' Te adjunto el logo de la marca SOLO como referencia de color: usa tonos inspirados en su paleta para la escena. NO intentes dibujar ni reproducir el logo, ni ningún otro logo propio, dentro de la imagen — eso se agrega después por separado.'
            : '');

        $resultado = GeminiImagenGenerator::generarVariantes($iaConfig->gemini_api_key, $prompt, 2, $modelo, $rutaLogo);

        if ($resultado['ok']) {
            foreach ($resultado['rutas'] as $ruta) {
                \App\Services\Publicidad\LogoWatermarker::aplicar($ruta, $aliado->logo_marca_claro, $aliado->logo_marca_recorte);
            }
            $resultado['urls'] = array_map(fn ($r) => asset('storage/' . $r) . '?v=' . time(), $resultado['rutas']);
        }

        return response()->json($resultado);
    }

    /**
     * Inicia un video publicitario con IA (Veo): la IA redacta sola el prompt de la escena y
     * las frases del texto animado (el admin solo elige Lite/Standard y opcionalmente un
     * tema) — responde de inmediato con el id para hacer polling, la generación real (1-3
     * min) la avanza el comando `videos:procesar` en segundo plano.
     */
    public function generarVideo(Request $request)
    {
        $aliado = $this->aliadoActivo();
        $iaConfig = IaConfiguracionAliado::paraAliado($aliado->id);

        if (!$iaConfig->tieneGemini()) {
            return response()->json(['ok' => false, 'error' => 'No hay una clave de Gemini configurada (ver Asistente Virtual).']);
        }

        $validated = $request->validate([
            'tema'     => 'nullable|string|max:300',
            'nivel'    => 'required|in:lite,standard',
            'duracion' => 'nullable|in:4,6,8,16,24',
        ]);

        $contexto = $validated['tema'] ?: 'cotizar seguridad social (EPS, ARL, pensión, caja de compensación) en Colombia';
        $modelo = $validated['nivel'] === 'standard' ? VeoVideoGenerator::MODELO_STANDARD : VeoVideoGenerator::MODELO_LITE;
        $duracion = (int) ($validated['duracion'] ?? 8);

        $frasesResultado = CopiaIaGenerator::generarFrasesVideo($aliado->id, $aliado->nombre, $contexto);
        $frases = $frasesResultado['ok'] ? array_slice($frasesResultado['frases'], 0, 3) : [];

        if ($duracion > 8) {
            // Pieza de varias escenas (16s = 2 clips, 24s = 3 clips) unidas con corte simple —
            // ver ProcesarVideosIa y VideoOverlayFfmpeg::concatenar.
            $numEscenas = (int) ($duracion / 8);

            $promptsResultado = CopiaIaGenerator::generarPromptsMultiEscena($aliado->id, $aliado->nombre, $contexto, $numEscenas);
            if (!$promptsResultado['ok']) {
                return response()->json(['ok' => false, 'error' => $promptsResultado['error']]);
            }

            $escenas = [];
            foreach ($promptsResultado['prompts'] as $orden => $promptEscena) {
                $inicioEscena = VeoVideoGenerator::iniciar($iaConfig->gemini_api_key, $promptEscena, $modelo, '9:16', '720p', 8);
                if (!$inicioEscena['ok']) {
                    return response()->json(['ok' => false, 'error' => "Escena " . ($orden + 1) . ": " . $inicioEscena['error']]);
                }

                $escenas[] = [
                    'orden'            => $orden,
                    'prompt'           => $promptEscena,
                    'operation_name'   => $inicioEscena['operationName'],
                    'estado'           => 'generando',
                    'video_bruto_path' => null,
                ];
            }

            $video = PublicidadVideoIa::create([
                'aliado_id'          => $aliado->id,
                'prompt_video'       => implode(' / ', $promptsResultado['prompts']),
                'frases_texto'       => $frases,
                'modelo'             => $modelo,
                'duracion_seg'       => $duracion,
                'costo_estimado_usd' => VeoVideoGenerator::costoEstimadoUsd($modelo, $duracion),
                'escenas'            => $escenas,
                'creado_por'         => Auth::id(),
            ]);

            return response()->json(['ok' => true, 'id' => $video->id]);
        }

        $promptResultado = CopiaIaGenerator::generarPromptVideo($aliado->id, $aliado->nombre, $contexto);
        if (!$promptResultado['ok']) {
            return response()->json(['ok' => false, 'error' => $promptResultado['error']]);
        }

        $inicio = VeoVideoGenerator::iniciar($iaConfig->gemini_api_key, $promptResultado['prompt'], $modelo, '9:16', '720p', $duracion);
        if (!$inicio['ok']) {
            return response()->json(['ok' => false, 'error' => $inicio['error']]);
        }

        $video = PublicidadVideoIa::create([
            'aliado_id'          => $aliado->id,
            'prompt_video'       => $promptResultado['prompt'],
            'frases_texto'       => $frases,
            'modelo'             => $modelo,
            'duracion_seg'       => $duracion,
            'costo_estimado_usd' => VeoVideoGenerator::costoEstimadoUsd($modelo, $duracion),
            'operation_name' => $inicio['operationName'],
            'creado_por'     => Auth::id(),
        ]);

        return response()->json(['ok' => true, 'id' => $video->id]);
    }

    /** Polling de estado del video en generación — ver pestaña Video IA en create.blade.php. */
    public function estadoVideo(int $id)
    {
        $aliado = $this->aliadoActivo();
        $video = PublicidadVideoIa::where('aliado_id', $aliado->id)->findOrFail($id);

        $listo = $video->estado === PublicidadVideoIa::ESTADO_LISTA;

        $progreso = null;
        if ($video->escenas && $video->estado === PublicidadVideoIa::ESTADO_GENERANDO) {
            $total = count($video->escenas);
            $listas = count(array_filter($video->escenas, fn ($e) => $e['estado'] === 'lista'));
            $progreso = "Escena {$listas}/{$total} lista...";
        }

        return response()->json([
            'ok'                 => true,
            'estado'             => $video->estado,
            'error'              => $video->error_mensaje,
            'progreso'           => $progreso,
            'video_url'          => $listo ? asset('storage/' . $video->video_path) . '?v=' . time() : null,
            'video_path'         => $listo ? $video->video_path : null,
            'poster_url'         => $video->imagen_poster_path ? asset('storage/' . $video->imagen_poster_path) . '?v=' . time() : null,
            'imagen_poster_path' => $video->imagen_poster_path,
            'modelo'             => $video->modelo,
        ]);
    }

    /** Sube a storage el PNG generado por canvas en el navegador (dataURL -> archivo) y devuelve su path. */
    public function subirCanvas(Request $request)
    {
        $this->aliadoActivo();

        $validated = $request->validate(['imagen' => 'required|image|max:5120']);
        $ruta = $request->file('imagen')->store('publicidad', 'public');

        return response()->json(['ok' => true, 'path' => $ruta, 'url' => asset('storage/' . $ruta)]);
    }

    // ── Pauta pagada (Meta Marketing API) ──────────────────────────────────

    public function pautaConfig()
    {
        $aliado = $this->aliadoActivo();
        $config = PautaConfig::paraAliado($aliado->id);

        return view('admin.publicidad.pauta-config', compact('aliado', 'config'));
    }

    public function pautaConfigUpdate(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $validated = $request->validate([
            'activo'                          => 'required|boolean',
            'ad_account_id'                    => 'nullable|string|max:30',
            'limite_mensual_cop'                => 'nullable|numeric|min:0',
            'presupuesto_diario_default_cop'    => 'required|numeric|min:1000',
        ]);

        PautaConfig::paraAliado($aliado->id)->update($validated);

        return redirect()->route('admin.publicidad.pauta.config')->with('success', 'Configuración de pauta actualizada.');
    }

    public function pautaCrear(int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);
        $config = PautaConfig::paraAliado($aliado->id);

        $presupuesto = MetaAdsService::sugerirPresupuesto($publicacion, $config);
        $resultado = MetaAdsService::crearBorrador($publicacion, $config, $presupuesto);

        return back()->with($resultado['ok'] ? 'success' : 'error', $resultado['mensaje']);
    }

    public function pautaActivar(Request $request, int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        $dias = $request->integer('dias') ?: null;
        $resultado = MetaAdsService::activar($publicacion, $dias);

        return back()->with($resultado['ok'] ? 'success' : 'error', $resultado['mensaje']);
    }

    public function pautaPausar(int $id)
    {
        $aliado = $this->aliadoActivo();
        $publicacion = Publicacion::where('aliado_id', $aliado->id)->findOrFail($id);

        $resultado = MetaAdsService::pausar($publicacion);

        return back()->with($resultado['ok'] ? 'success' : 'error', $resultado['mensaje']);
    }

    private function aliadoActivo(): Aliado
    {
        $aliado = Aliado::find(session('aliado_id_activo'));
        abort_if(!$aliado, 400, 'No hay un aliado activo seleccionado.');
        return $aliado;
    }
}
