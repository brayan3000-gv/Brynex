<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publico\PaginaAliadoController;
use App\Models\Aliado;
use App\Models\PaginaAliadoConfig;
use App\Models\PaginaFaq;
use App\Models\PaginaLead;
use App\Services\MetricaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * CMS ligero de la página web pública del aliado (/aliado/{slug}): textos del hero, SEO,
 * secciones visibles y preguntas frecuentes. El aliado activo sale de la sesión, igual que
 * en el resto del panel (ConfiguracionAliadoController, MarketingListaController, ...).
 */
class PaginaAliadoAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin|admin']);
    }

    public function edit()
    {
        $aliado = $this->aliadoActivo();
        $config = PaginaAliadoConfig::firstOrNew(['aliado_id' => $aliado->id]);
        $faqs   = PaginaFaq::where('aliado_id', $aliado->id)->orderBy('orden')->get();

        $previewUrl = URL::temporarySignedRoute('publico.aliado.preview', now()->addHours(24), ['slug' => $aliado->slug]);
        $publicUrl  = route('publico.aliado', $aliado->slug);
        $metricas   = MetricaService::resumen($aliado->id);

        return view('admin.pagina.index', compact('aliado', 'config', 'faqs', 'previewUrl', 'publicUrl', 'metricas'));
    }

    public function update(Request $request)
    {
        $aliado = $this->aliadoActivo();

        // Normalizar ANTES de validar: si alguien pega "https://www.Brygar.com/" en vez de
        // "brygar.com", la regex de formato de abajo lo rechazaría si se validara el valor
        // crudo — hay que limpiarlo primero y validar ya el valor normalizado.
        if ($request->filled('dominio_propio')) {
            $request->merge(['dominio_propio' => $this->normalizarDominio($request->input('dominio_propio'))]);
        }

        $validated = $request->validate([
            'hero_titulo'            => 'nullable|string|max:150',
            'hero_subtitulo'         => 'nullable|string|max:255',
            'hero_cta_texto'         => 'nullable|string|max:60',
            'seo_titulo'             => 'nullable|string|max:160',
            'seo_descripcion'        => 'nullable|string|max:300',
            'precios_modo'           => 'required|in:exacto,desde',
            'whatsapp_mensaje_base'  => 'nullable|string|max:500',
            'hero_imagen'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
            'dominio_propio'         => [
                'nullable', 'string', 'max:150',
                'regex:/^(?!www\.)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i',
                Rule::unique('aliados', 'dominio_propio')->ignore($aliado->id),
            ],
        ]);

        $dominioCambio = $validated['dominio_propio'] !== $aliado->dominio_propio;
        $aliado->update(['dominio_propio' => $validated['dominio_propio']]);
        unset($validated['dominio_propio']);

        if ($dominioCambio) {
            // Las rutas de dominio se registran leyendo la BD en routes/web.php — si el proyecto
            // usa `route:cache` en producción, el cambio no tomaría efecto hasta limpiarlo.
            Artisan::call('route:clear');
        }

        $config = PaginaAliadoConfig::firstOrNew(['aliado_id' => $aliado->id]);

        unset($validated['hero_imagen']);
        if ($request->hasFile('hero_imagen')) {
            $config->hero_imagen = self::guardarFotoHero($request->file('hero_imagen'), $aliado->id);
        } elseif ($request->boolean('quitar_hero_imagen')) {
            $config->hero_imagen = null;
        }

        // Las secciones no editadas por este formulario (hero/contacto/planes/cotizador/ahorro/
        // promos) conservan su valor actual — solo se sobrescriben las que sí tienen checkbox aquí.
        $secciones = $config->secciones ?: PaginaAliadoConfig::seccionesPorDefecto();
        foreach (PaginaAliadoConfig::seccionesEditables() as $clave) {
            $secciones[$clave] = $request->boolean("secciones.{$clave}");
        }

        // estadisticas_visibles no tiene checkbox en este formulario todavía (ninguna sección
        // lo usa aún) — se deja intacto para no resetearlo a false en cada guardado. Ver el
        // bug real de mostrar_precios en la bitácora del plan: pasó exactamente esto.
        $config->fill($validated);
        $config->aliado_id       = $aliado->id;
        $config->activo          = $request->boolean('activo');
        $config->mostrar_precios = $request->boolean('mostrar_precios');
        $config->secciones       = $secciones;
        $config->save();

        PaginaAliadoController::invalidarCache($aliado->slug);

        return redirect()->route('admin.pagina.index')->with('success', 'Configuración de la página guardada.');
    }

    /**
     * La foto del encabezado se guarda en JPEG de máximo 1000 px: llega como foto de celular o
     * imagen generada de varios MB y se pinta a ~480 px. Si GD no puede leerla, va el original.
     */
    public static function guardarFotoHero(\Illuminate\Http\UploadedFile $file, int $aliadoId): string
    {
        $dir = public_path('storage/pagina-hero');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $base = $aliadoId . '_' . time();

        $img = @imagecreatefromstring((string) @file_get_contents($file->getRealPath()));
        if (! $img) {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $file->move($dir, "{$base}.{$ext}");

            return "pagina-hero/{$base}.{$ext}";
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $escala = min(1, 1000 / max($w, $h));
        if ($escala < 1) {
            $nueva = imagecreatetruecolor((int) round($w * $escala), (int) round($h * $escala));
            imagecopyresampled($nueva, $img, 0, 0, 0, 0, imagesx($nueva), imagesy($nueva), $w, $h);
            imagedestroy($img);
            $img = $nueva;
        }
        imagejpeg($img, "{$dir}/{$base}.jpg", 84);
        imagedestroy($img);

        return "pagina-hero/{$base}.jpg";
    }

    public function faqs()
    {
        $aliado = $this->aliadoActivo();
        $faqs   = PaginaFaq::where('aliado_id', $aliado->id)->orderBy('orden')->get();

        return view('admin.pagina.faqs', compact('aliado', 'faqs'));
    }

    public function faqStore(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $validated = $request->validate([
            'pregunta'  => 'required|string|max:255',
            'respuesta' => 'required|string|max:2000',
        ]);

        $orden = (int) (PaginaFaq::where('aliado_id', $aliado->id)->max('orden') ?? 0) + 1;

        PaginaFaq::create($validated + [
            'aliado_id' => $aliado->id,
            'orden'     => $orden,
            'activo'    => true,
        ]);

        PaginaAliadoController::invalidarCache($aliado->slug);

        return redirect()->route('admin.pagina.faqs.index')->with('success', 'Pregunta agregada.');
    }

    public function faqUpdate(Request $request, int $id)
    {
        $aliado = $this->aliadoActivo();
        $faq    = PaginaFaq::where('aliado_id', $aliado->id)->findOrFail($id);

        $validated = $request->validate([
            'pregunta'  => 'required|string|max:255',
            'respuesta' => 'required|string|max:2000',
            'orden'     => 'nullable|integer|min:0|max:999',
        ]);

        $faq->update([
            'pregunta'  => $validated['pregunta'],
            'respuesta' => $validated['respuesta'],
            'orden'     => $validated['orden'] ?? $faq->orden,
            'activo'    => $request->boolean('activo'),
        ]);

        PaginaAliadoController::invalidarCache($aliado->slug);

        return redirect()->route('admin.pagina.faqs.index')->with('success', 'Pregunta actualizada.');
    }

    public function faqDestroy(int $id)
    {
        $aliado = $this->aliadoActivo();
        PaginaFaq::where('aliado_id', $aliado->id)->where('id', $id)->delete();

        PaginaAliadoController::invalidarCache($aliado->slug);

        return redirect()->route('admin.pagina.faqs.index')->with('success', 'Pregunta eliminada.');
    }

    public function leads(Request $request)
    {
        $aliado = $this->aliadoActivo();

        $query = PaginaLead::where('aliado_id', $aliado->id)->orderByDesc('created_at');
        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        $leads = $query->paginate(30)->withQueryString();
        $conteos = PaginaLead::where('aliado_id', $aliado->id)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('admin.pagina.leads', compact('aliado', 'leads', 'conteos'));
    }

    public function leadUpdateEstado(Request $request, int $id)
    {
        $aliado = $this->aliadoActivo();
        $lead   = PaginaLead::where('aliado_id', $aliado->id)->findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|in:nuevo,contactado,convertido,descartado',
        ]);

        $lead->update(['estado' => $validated['estado']]);

        return redirect()->route('admin.pagina.leads.index')->with('success', 'Estado actualizado.');
    }

    /** "https://www.Brygar.com/" -> "brygar.com" — tolera lo que un no-técnico pueda pegar. */
    private function normalizarDominio(string $valor): string
    {
        $dominio = strtolower(trim($valor));
        $dominio = preg_replace('#^https?://#', '', $dominio);
        $dominio = preg_replace('#^www\.#', '', $dominio);
        return rtrim(strtok($dominio, '/'), '/');
    }

    private function aliadoActivo(): Aliado
    {
        $aliado = Aliado::find(session('aliado_id_activo'));
        abort_if(!$aliado, 400, 'No hay un aliado activo seleccionado.');
        return $aliado;
    }
}
