@extends('layouts.app')
@section('modulo', 'Página Web')

@section('contenido')
<div style="max-width:900px;margin:0 auto;">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:0.5rem;">
  <div>
    <a href="{{ route('admin.configuracion.hub') }}"
       style="font-size:.73rem;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;margin-bottom:.35rem">
        ← Volver a Configuración
    </a>
    <h1 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0;">🌐 Página Web Pública</h1>
    <p style="font-size:0.78rem;color:#64748b;margin:0;">brynex.co/aliado/{{ $aliado->slug }}</p>
  </div>
  <div style="display:flex;gap:0.5rem;">
    <a href="{{ $previewUrl }}" target="_blank" rel="noopener"
       style="background:#fff;border:1px solid #cbd5e1;color:#0f172a;font-size:0.78rem;font-weight:600;padding:0.5rem 0.9rem;border-radius:8px;text-decoration:none;">
        👁️ Vista previa
    </a>
    <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
       style="background:#0f172a;color:#fff;font-size:0.78rem;font-weight:600;padding:0.5rem 0.9rem;border-radius:8px;text-decoration:none;">
        ↗ Ver página pública
    </a>
  </div>
</div>

{{-- Tabs --}}
<div style="display:flex;gap:0.4rem;margin-bottom:1rem;border-bottom:1px solid #e2e8f0;">
  <span style="font-size:0.82rem;font-weight:700;color:#0f172a;padding:0.6rem 0.9rem;border-bottom:2px solid #2563eb;">General</span>
  <a href="{{ route('admin.pagina.faqs.index') }}" style="font-size:0.82rem;font-weight:600;color:#64748b;padding:0.6rem 0.9rem;text-decoration:none;">Preguntas frecuentes</a>
  <a href="{{ route('admin.pagina.leads.index') }}" style="font-size:0.82rem;font-weight:600;color:#64748b;padding:0.6rem 0.9rem;text-decoration:none;">Leads</a>
</div>

{{-- ══ DASHBOARD DE MÉTRICAS (últimos 30 días) ══ --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.75rem;margin-bottom:1.25rem;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:0.85rem;">
    <div style="font-size:1.4rem;font-weight:800;color:#0f172a;">{{ $metricas['visitas'] }}</div>
    <div style="font-size:0.7rem;color:#64748b;">Visitas</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:0.85rem;">
    <div style="font-size:1.4rem;font-weight:800;color:#16a34a;">{{ $metricas['clics_whatsapp'] }}</div>
    <div style="font-size:0.7rem;color:#64748b;">Clics a WhatsApp</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:0.85rem;">
    <div style="font-size:1.4rem;font-weight:800;color:#2563eb;">{{ $metricas['cotizaciones_completadas'] }}</div>
    <div style="font-size:0.7rem;color:#64748b;">Cotizaciones hechas</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:0.85rem;">
    <div style="font-size:1.4rem;font-weight:800;color:#7c3aed;">{{ $metricas['leads_capturados'] }}</div>
    <div style="font-size:0.7rem;color:#64748b;">Leads capturados</div>
  </div>
</div>

@if(session('success'))
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;color:#166534;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.83rem;">✅ {{ session('success') }}</div>
@endif
@if($errors->any())
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.83rem;">
  <strong>Errores:</strong> @foreach($errors->all() as $e) · {{ $e }} @endforeach
</div>
@endif

<form method="POST" action="{{ route('admin.pagina.update') }}" enctype="multipart/form-data">
@csrf

{{-- ══ PUBLICACIÓN ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;">
    <input type="checkbox" name="activo" value="1" {{ old('activo', $config->activo) ? 'checked' : '' }}
           style="width:18px;height:18px;">
    <span style="font-size:0.9rem;font-weight:700;color:#0f172a;">Página pública activa</span>
  </label>
  <p style="font-size:0.75rem;color:#64748b;margin:0.4rem 0 0 1.6rem;">
    Si está desmarcado, <code>brynex.co/aliado/{{ $aliado->slug }}</code> muestra "no encontrado" para cualquier visitante. La "Vista previa" siempre funciona, esté activa o no.
  </p>

  <div style="margin-top:0.9rem;padding-top:0.9rem;border-top:1px solid #f1f5f9;">
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Dominio propio (opcional)</label>
    <input type="text" name="dominio_propio" maxlength="150" value="{{ old('dominio_propio', $aliado->dominio_propio) }}"
           placeholder="brygar.com"
           style="width:100%;max-width:320px;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
    <p style="font-size:0.7rem;color:#94a3b8;margin:0.4rem 0 0;">
      Sin protocolo ni "www." — solo <code>brygar.com</code>. Para que funcione, el DNS de ese dominio debe apuntar a este servidor (pide el detalle técnico a BryNex).
    </p>
  </div>
</div>

{{-- ══ ENCABEZADO (HERO) ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.85rem;">
    ✨ Encabezado principal
  </div>

  <div style="margin-bottom:0.85rem;">
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Título</label>
    <input type="text" name="hero_titulo" maxlength="150" value="{{ old('hero_titulo', $config->hero_titulo) }}"
           placeholder="Tu seguridad social, sin filas ni papeleo"
           style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
  </div>

  <div style="margin-bottom:0.85rem;">
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Subtítulo</label>
    <input type="text" name="hero_subtitulo" maxlength="255" value="{{ old('hero_subtitulo', $config->hero_subtitulo) }}"
           placeholder="{{ $aliado->nombre }} te afilia a EPS, ARL, pensión y caja de compensación de forma rápida y segura."
           style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
  </div>

  <div>
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Texto del botón principal</label>
    <input type="text" name="hero_cta_texto" maxlength="60" value="{{ old('hero_cta_texto', $config->hero_cta_texto) }}"
           placeholder="Quiero afiliarme"
           style="width:100%;max-width:320px;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
  </div>

  <div style="margin-top:0.85rem;">
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Foto del encabezado</label>
    <div style="display:flex;gap:0.85rem;align-items:flex-start;flex-wrap:wrap;">
      @if($config->hero_imagen)
        <img src="{{ asset('storage/' . $config->hero_imagen) }}" alt="" style="width:96px;height:120px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;">
      @endif
      <div>
        <input type="file" name="hero_imagen" accept="image/jpeg,image/png,image/webp" style="font-size:0.8rem;">
        <p style="font-size:0.72rem;color:#94a3b8;margin:0.35rem 0 0;">Vertical (4:5) y con la persona a un lado se ve mejor. Sin foto se muestra el logo.</p>
        @if($config->hero_imagen)
          <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;color:#b91c1c;margin-top:0.4rem;cursor:pointer;">
            <input type="checkbox" name="quitar_hero_imagen" value="1"> Quitar la foto
          </label>
        @endif
      </div>
    </div>
  </div>

  <p style="font-size:0.72rem;color:#94a3b8;margin:0.75rem 0 0;">Deja un campo vacío para usar el texto por defecto.</p>
</div>

{{-- ══ SECCIONES VISIBLES ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.85rem;">
    🧩 Secciones de la página
  </div>
  @php $secciones = $config->secciones ?: \App\Models\PaginaAliadoConfig::seccionesPorDefecto(); @endphp
  <div style="display:flex;flex-direction:column;gap:0.6rem;">
    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;">
      <input type="checkbox" name="secciones[servicios]" value="1" {{ old('secciones.servicios', $secciones['servicios'] ?? true) ? 'checked' : '' }} style="width:16px;height:16px;">
      <span style="font-size:0.85rem;color:#0f172a;">Servicios (EPS, ARL, Pensión, Caja)</span>
    </label>
    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;">
      <input type="checkbox" name="secciones[pasos]" value="1" {{ old('secciones.pasos', $secciones['pasos'] ?? true) ? 'checked' : '' }} style="width:16px;height:16px;">
      <span style="font-size:0.85rem;color:#0f172a;">Cómo funciona (3 pasos)</span>
    </label>
    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;">
      <input type="checkbox" name="secciones[faq]" value="1" {{ old('secciones.faq', $secciones['faq'] ?? true) ? 'checked' : '' }} style="width:16px;height:16px;">
      <span style="font-size:0.85rem;color:#0f172a;">Preguntas frecuentes</span>
    </label>
  </div>
  <p style="font-size:0.72rem;color:#94a3b8;margin:0.75rem 0 0;">El encabezado y la sección de contacto siempre se muestran.</p>
</div>

{{-- ══ PRECIOS ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.85rem;">
    💰 Precios (sección "Planes" y cotizador)
  </div>
  <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;margin-bottom:0.85rem;">
    <input type="checkbox" name="mostrar_precios" value="1" {{ old('mostrar_precios', $config->mostrar_precios) ? 'checked' : '' }}
           style="width:18px;height:18px;">
    <span style="font-size:0.85rem;color:#0f172a;">Mostrar los valores en pesos en la página</span>
  </label>
  <p style="font-size:0.72rem;color:#94a3b8;margin:0 0 0.85rem 1.6rem;">
    Si está desmarcado, los planes y el cotizador muestran "cotización personalizada" en vez del valor — el visitante igual puede pedir su cotización por WhatsApp.
  </p>
  <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Cómo mostrar el valor</label>
  <select name="precios_modo" style="padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
    <option value="exacto" {{ old('precios_modo', $config->precios_modo) === 'exacto' ? 'selected' : '' }}>Valor exacto ($125.400/mes)</option>
    <option value="desde" {{ old('precios_modo', $config->precios_modo) === 'desde' ? 'selected' : '' }}>Desde ($125.400/mes)</option>
  </select>
</div>

{{-- ══ WHATSAPP ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.85rem;">
    💬 Mensaje de WhatsApp
  </div>
  <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Mensaje prellenado al hacer clic en WhatsApp</label>
  <textarea name="whatsapp_mensaje_base" maxlength="500" rows="2"
            placeholder="Hola {{ $aliado->nombre }}, quiero información sobre afiliación a seguridad social."
            style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;">{{ old('whatsapp_mensaje_base', $config->whatsapp_mensaje_base) }}</textarea>
</div>

{{-- ══ SEO ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1.25rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.85rem;">
    🔍 SEO (Google y redes sociales)
  </div>
  <div style="margin-bottom:0.85rem;">
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Título para buscadores</label>
    <input type="text" name="seo_titulo" maxlength="160" value="{{ old('seo_titulo', $config->seo_titulo) }}"
           placeholder="{{ $aliado->nombre }} — Afiliación a seguridad social"
           style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
  </div>
  <div>
    <label style="display:block;font-size:0.75rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Descripción para buscadores</label>
    <textarea name="seo_descripcion" maxlength="300" rows="2"
              placeholder="Afíliate a EPS, ARL, pensión y caja de compensación con {{ $aliado->nombre }}. Gestión 100% en línea."
              style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;">{{ old('seo_descripcion', $config->seo_descripcion) }}</textarea>
  </div>
</div>

<button type="submit"
        style="background:#2563eb;color:#fff;border:none;font-size:0.88rem;font-weight:700;padding:0.7rem 1.6rem;border-radius:9px;cursor:pointer;">
    Guardar cambios
</button>

</form>
</div>
@endsection
