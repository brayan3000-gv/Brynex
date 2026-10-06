@extends('layouts.app')
@section('modulo', isset($aliado->id) ? 'Editar Aliado' : 'Nuevo Aliado')

@section('contenido')
<div style="max-width:720px;margin:0 auto;">
<div style="background:#fff;border-radius:14px;padding:1.75rem 2rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo' => isset($aliado->id) ? '✏️ Editar Aliado' : '🏢 Nuevo Aliado',
    ])

@if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.83rem;">
            <strong>Corrige los siguientes errores:</strong>
            <ul style="margin:0.4rem 0 0 1rem;">
                @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ isset($aliado->id) ? route('admin.aliados.update', $aliado) : route('admin.aliados.store') }}"
          enctype="multipart/form-data">
        @csrf
        @if(isset($aliado->id)) @method('PUT') @endif

        {{-- Fila 1 --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Nombre o razón social *</label>
                <input type="text" name="nombre" value="{{ old('nombre', $aliado->nombre) }}" required
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">NIT</label>
                <input type="text" name="nit" value="{{ old('nit', $aliado->nit) }}"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
        </div>

        {{-- Eslogan: la frase que va debajo del nombre --}}
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Eslogan</label>
            <input type="text" name="eslogan" maxlength="120" value="{{ old('eslogan', $aliado->eslogan ?? '') }}"
                placeholder="Ej: Seguridad social sin complicaciones"
                style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            <div style="font-size:0.7rem;color:#64748b;margin-top:0.3rem;">Va debajo del nombre en el encabezado del sistema y en los flyers. Si se deja vacío, no se muestra.</div>
        </div>

        {{-- Fila contacto --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:1rem;margin-bottom:1rem;">
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Contacto</label>
                <input type="text" name="contacto" value="{{ old('contacto', $aliado->contacto) }}"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Teléfono</label>
                <input type="text" name="telefono" value="{{ old('telefono', $aliado->telefono) }}"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Celular</label>
                <input type="text" name="celular" value="{{ old('celular', $aliado->celular) }}"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">WhatsApp</label>
                <input type="text" name="whatsapp" value="{{ old('whatsapp', $aliado->whatsapp) }}" placeholder="Ej: 573001234567"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
        </div>

        {{-- Correo + ubicación: departamento y municipio de las listas, como en la ficha del cliente --}}
        @php
            $deptoActual = old('departamento_id', $aliado->departamento_id);
            $munActual   = old('municipio_id', $aliado->municipio_id);
        @endphp
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:1rem;margin-bottom:1rem;">
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Correo</label>
                <input type="email" name="correo" value="{{ old('correo', $aliado->correo) }}"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                    onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Departamento</label>
                <select name="departamento_id" id="selDepto" style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;background:#fff;">
                    <option value="">— Seleccionar —</option>
                    @foreach($departamentos as $id => $nombre)
                        <option value="{{ $id }}" {{ $deptoActual == $id ? 'selected' : '' }}>{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Municipio</label>
                <select name="municipio_id" id="selMunicipio" style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;background:#fff;">
                    <option value="">— Seleccionar —</option>
                </select>
                @if($aliado->ciudad && ! $aliado->municipio_id)
                    <div style="font-size:0.7rem;color:#b45309;margin-top:0.3rem;">Antes decía «{{ $aliado->ciudad }}»: escoge el municipio.</div>
                @endif
            </div>
        </div>

        {{-- Dirección --}}
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Dirección</label>
            <input type="text" name="direccion" value="{{ old('direccion', $aliado->direccion) }}"
                style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
        </div>

        {{-- Caja unificada de Identidad Visual --}}
        <div style="background:#f8fafc;padding:1.5rem;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:1.5rem;display:flex;flex-direction:column;gap:1.25rem;">
            
            <div style="font-size:0.8rem;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;padding-bottom:0.5rem;margin-bottom:0.25rem;">
                🎨 Identidad Visual del Aliado
            </div>

            {{-- 1. Logo de la Aplicación --}}
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.4rem;text-transform:uppercase;letter-spacing:0.04em;">Logo de la Aplicación (PNG/JPG)</label>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    @if(isset($aliado->id) && $aliado->logo)
                        <div style="background:#fff;padding:4px;border:1px solid #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;height:54px;width:54px;flex-shrink:0;">
                            <img id="logo-preview"
                                 src="{{ asset('storage/'.$aliado->logo) }}"
                                 style="max-height:100%;max-width:100%;object-fit:contain;"
                                 onerror="this.style.display='none';document.getElementById('logo-error').style.display='flex';"
                            >
                            <span id="logo-error" style="display:none;font-size:0.65rem;color:#dc2626;text-align:center;">⚠️ N/A</span>
                        </div>
                    @endif
                    <input type="file" name="logo" accept="image/*"
                        style="flex:1;min-width:200px;padding:0.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;font-family:inherit;background:#fff;">
                </div>
                <div style="font-size:0.7rem;color:#64748b;margin-top:0.35rem;">Ícono cuadrado — se usa en el panel, selector de aliado, facturación y la web pública.</div>
            </div>

            {{-- 3. Color Primario y Estado --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:1rem;">
                {{-- Color --}}
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.15rem;">Color Primario</label>
                        <span style="font-size:0.75rem;color:#64748b;font-family:monospace;">{{ old('color_primario', $aliado->color_primario ?? '#2563eb') }}</span>
                    </div>
                    <input type="color" name="color_primario" value="{{ old('color_primario', $aliado->color_primario ?? '#2563eb') }}"
                        style="width:50px;height:34px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;padding:2px;background:#fff;">
                </div>

                {{-- Estado --}}
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.15rem;">Estado del Aliado</label>
                        <span style="font-size:0.75rem;color:#64748b;">¿El aliado se encuentra activo?</span>
                    </div>
                    <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
                        <input type="checkbox" name="activo" value="1" {{ old('activo', $aliado->activo ?? true) ? 'checked' : '' }}
                            style="width:18px;height:18px;cursor:pointer;">
                        <span style="font-size:0.85rem;font-weight:600;color:#334155;">Activo</span>
                    </label>
                </div>
            </div>

        </div>

        {{-- Logo de marca (marketing/watermark) — separado del ícono cuadrado de arriba --}}
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.2rem;">🖼️ Logo de marca (marketing)</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-bottom:0.85rem;">Ícono + nombre + eslogan completo — se usa como marca de agua en las imágenes generadas. Distinto del ícono cuadrado de arriba.</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Logo — fondos claros</label>
                    @if(isset($aliado->id) && $aliado->logo_marca_claro)
                        <div style="margin-bottom:0.4rem;">
                            <img src="{{ asset('storage/'.$aliado->logo_marca_claro) }}" style="height:48px;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain;">
                        </div>
                    @endif
                    <input type="file" name="logo_marca_claro" accept="image/*"
                        style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Logo — fondos oscuros</label>
                    @if(isset($aliado->id) && $aliado->logo_oscuro)
                        <div style="margin-bottom:0.4rem;background:#0f172a;padding:0.4rem;border-radius:6px;width:fit-content;">
                            <img src="{{ asset('storage/'.$aliado->logo_oscuro) }}" style="height:48px;object-fit:contain;">
                        </div>
                    @endif
                    <input type="file" name="logo_oscuro" accept="image/*"
                        style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;">
                </div>
            </div>
            <div style="font-size:0.68rem;color:#94a3b8;margin-top:0.6rem;">El sistema elige la variante correcta según qué tan oscura sea la esquina de cada foto. Si solo subes una, se usa siempre esa.</div>

            @php
                $recorte = old('logo_marca_recorte', $aliado->logo_marca_recorte ?? []);
            @endphp
            <details style="margin-top:0.85rem;">
                <summary style="font-size:0.72rem;font-weight:600;color:#7c3aed;cursor:pointer;">⚙️ Avanzado: recortar el eslogan del watermark (opcional)</summary>
                <div style="margin-top:0.6rem;padding:0.75rem;background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
                    <div style="font-size:0.7rem;color:#94a3b8;margin-bottom:0.6rem;">
                        Si tu logo trae <strong>ícono arriba + nombre debajo</strong> (con eslogan o subtítulo al final), ese texto final se vuelve ilegible al achicarlo. Indica qué % del alto ocupan ícono + nombre (sin el eslogan) — el watermark corta el resto. Déjalo vacío para usar el logo completo tal cual.
                    </div>
                    <div style="max-width:220px;margin-bottom:0.85rem;">
                        <label style="display:block;font-size:0.68rem;font-weight:600;color:#475569;margin-bottom:0.25rem;">% alto útil (ícono + nombre)</label>
                        <input type="number" step="0.1" min="0" max="100" name="logo_marca_recorte[alto_util_pct]"
                            value="{{ $recorte['alto_util_pct'] ?? '' }}" placeholder="Ej: 81.5"
                            style="width:100%;padding:0.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;">
                    </div>
                    <div style="font-size:0.7rem;color:#94a3b8;margin-bottom:0.6rem;">
                        Si en cambio tu logo trae <strong>ícono a la izquierda + nombre y eslogan a la derecha</strong>, configura estos tres en su lugar (no combines ambos):
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                        <div>
                            <label style="display:block;font-size:0.68rem;font-weight:600;color:#475569;margin-bottom:0.25rem;">% ancho del ícono</label>
                            <input type="number" step="0.1" min="0" max="100" name="logo_marca_recorte[icono_ancho_pct]"
                                value="{{ $recorte['icono_ancho_pct'] ?? '' }}" placeholder="Ej: 41.6"
                                style="width:100%;padding:0.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.68rem;font-weight:600;color:#475569;margin-bottom:0.25rem;">% donde inicia el nombre</label>
                            <input type="number" step="0.1" min="0" max="100" name="logo_marca_recorte[wordmark_y_inicio_pct]"
                                value="{{ $recorte['wordmark_y_inicio_pct'] ?? '' }}" placeholder="Ej: 15.6"
                                style="width:100%;padding:0.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.68rem;font-weight:600;color:#475569;margin-bottom:0.25rem;">% donde termina el nombre</label>
                            <input type="number" step="0.1" min="0" max="100" name="logo_marca_recorte[wordmark_y_fin_pct]"
                                value="{{ $recorte['wordmark_y_fin_pct'] ?? '' }}" placeholder="Ej: 45.2"
                                style="width:100%;padding:0.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;">
                        </div>
                    </div>
                </div>
            </details>
        </div>

        {{-- Imagen de la tabla de planes — el Asistente IA la envía por WhatsApp cuando el
             cliente quiere ver las opciones escritas/de un vistazo. Es solo referencia visual:
             el valor exacto que se ofrece siempre sale de cotizar_plan, no de esta imagen. --}}
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.2rem;">📋 Imagen de planes (WhatsApp)</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-bottom:0.85rem;">Tabla de planes/precios que el Asistente IA envía cuando el cliente quiere ver las opciones escritas o de un vistazo. Es una referencia visual — el valor exacto siempre lo calcula el cotizador, así que si cambian las tarifas hay que actualizar esta imagen a mano.</div>
            @if(isset($aliado->id) && $aliado->imagen_planes)
                <div style="margin-bottom:0.4rem;">
                    <img src="{{ asset('storage/'.$aliado->imagen_planes) }}" style="max-height:220px;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain;">
                </div>
            @endif
            <input type="file" name="imagen_planes" accept="image/*"
                style="width:100%;max-width:420px;padding:0.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;">
        </div>

        {{-- ── Sucursal ante el operador: es del aliado y vale para todas sus razones sociales ── --}}
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">
                🏬 Sucursal ante el operador de planilla
            </div>
            <p style="font-size:0.72rem;color:#64748b;margin:0 0 0.8rem;">
                La que tiene este aliado en Enlace (01 Brygar, 02 Fecop, 03 Luis López…). Los planos la usan en <strong>todas</strong> las razones sociales del aliado; si se deja vacía, usan la de cada razón social. No aplica a independientes.
            </p>
            <div style="display:grid;grid-template-columns:140px 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Código</label>
                    <input type="text" name="codigo_sucursal" maxlength="10" value="{{ old('codigo_sucursal', $aliado->codigo_sucursal ?? '') }}" placeholder="Ej: 02"
                        style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;"
                        onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">Nombre</label>
                    <input type="text" name="nombre_sucursal" maxlength="40" value="{{ old('nombre_sucursal', $aliado->nombre_sucursal ?? '') }}" placeholder="Ej: FECOP"
                        style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;text-transform:uppercase;"
                        onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
        </div>

        {{-- ── Afiliaciones BryNex ── --}}
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.8rem;">
                📋 Gestión de Afiliaciones
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;">
                <div>
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" name="afiliaciones_brynex" value="1"
                            {{ old('afiliaciones_brynex', $aliado->afiliaciones_brynex ?? false) ? 'checked' : '' }}
                            style="width:18px;height:18px;cursor:pointer;" id="chkAfiliacionesBrynex"
                            onchange="toggleEncargado(this.checked)">
                        <span style="font-size:0.85rem;font-weight:600;color:#0369a1;">BryNex gestiona las afiliaciones</span>
                    </label>
                    <p style="font-size:0.72rem;color:#64748b;margin:0.3rem 0 0 1.7rem;">
                        Activa si BryNex es responsable de tramitar las afiliaciones de los cotizantes de este aliado.
                    </p>
                </div>
                <div id="divEncargadoAfil" style="{{ old('afiliaciones_brynex', $aliado->afiliaciones_brynex ?? false) ? '' : 'opacity:0.4;pointer-events:none;' }}">
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.04em;">
                        Encargado de Afiliación (BryNex)
                    </label>
                    <select name="encargado_afil_id"
                        style="width:100%;padding:0.6rem 0.85rem;border:1px solid #bae6fd;border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;background:#fff;">
                        <option value="">— Sin asignar —</option>
                        @foreach($usuariosBrynex ?? [] as $ub)
                        <option value="{{ $ub->id }}" {{ old('encargado_afil_id', $aliado->encargado_afil_id) == $ub->id ? 'selected' : '' }}>
                            {{ $ub->nombre }}
                        </option>
                        @endforeach
                    </select>
                    <p style="font-size:0.72rem;color:#64748b;margin:0.25rem 0 0;">
                        Usuario BryNex asignado por defecto al crear contratos de este aliado.
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Módulos BryNex contratados ── --}}
        @php
            $todosModulos = $todosModulos ?? \App\Models\BrynexModulo::orderBy('orden')->get();
            $modulosContratados = $modulosContratados ?? (isset($aliado->id)
                ? \App\Models\BrynexModuloAliado::where('aliado_id', $aliado->id)->pluck('activo', 'modulo_id')->toArray()
                : []);
        @endphp
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.8rem;">
                🔵 Módulos BryNex Contratados (Cobros por Uso)
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:0.75rem;">
                @foreach($todosModulos as $mod)
                    @php
                        $moduloActivo = !isset($aliado->id) && $mod->id == 1;
                        if (isset($aliado->id)) {
                            $moduloActivo = isset($modulosContratados[$mod->id]) && $modulosContratados[$mod->id] == 1;
                        }
                    @endphp
                    <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;background:#fff;padding:0.4rem 0.6rem;border-radius:6px;border:1px solid #cbd5e1;">
                        <input type="checkbox" name="modulos[{{ $mod->id }}]" value="1" {{ $moduloActivo ? 'checked' : '' }} style="width:16px;height:16px;cursor:pointer;">
                        <div>
                            <span style="font-size:0.8rem;font-weight:600;color:#334155;">{{ $mod->nombre }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
            <p style="font-size:0.7rem;color:#64748b;margin:0.5rem 0 0 0;">
                Nota: Las tarifas personalizadas para cada módulo contratado pueden gestionarse desde el menú BryNex → Consumo y Cobros.
            </p>
        </div>

        {{-- Botones --}}
        <div style="display:flex;gap:0.75rem;justify-content:flex-end;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
            <a href="{{ route('admin.aliados.index') }}"
                style="padding:0.6rem 1.25rem;border:1px solid #cbd5e1;border-radius:8px;color:#475569;text-decoration:none;font-size:0.85rem;font-weight:500;">
                Cancelar
            </a>
            <button type="submit"
                style="padding:0.6rem 1.5rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:8px;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;box-shadow:0 3px 10px rgba(37,99,235,0.35);">
                💾 {{ isset($aliado->id) ? 'Actualizar' : 'Crear Aliado' }}
            </button>
        </div>

    </form>

</div>

{{-- ── Razones sociales prestadas: lo mismo que «Habilitar en aliado» de la ficha de la razón social, visto desde el aliado ── --}}
@if(isset($aliado->id) && \App\Services\RazonSocialCompartida::puedeHabilitar(auth()->user()))
<div x-data="razonesPrestadas()" x-init="cargar()"
     style="background:#fff;border-radius:14px;padding:1.5rem 2rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);margin-top:1.25rem;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.9rem;">
        <div>
            <div style="font-size:0.95rem;font-weight:800;color:#0f172a;">🤝 Razones sociales prestadas</div>
            <div style="font-size:0.72rem;color:#64748b;margin-top:0.2rem;max-width:480px;">
                Empresas de Brygar que {{ $aliado->nombre }} usa para afiliar. Los datos de la empresa se cambian en la original y pasan solos; la sucursal, la planilla y el estado son de este aliado.
            </div>
        </div>
        <button type="button" x-show="!agregando" @click="agregando = true"
            style="padding:0.5rem 1rem;background:#2563eb;border:none;border-radius:8px;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;">
            ➕ Prestar razones sociales
        </button>
    </div>

    <div x-show="cargando" style="font-size:0.8rem;color:#64748b;padding:0.5rem 0;">Cargando…</div>
    <div x-show="error" x-text="error" x-cloak
         style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.6rem 0.9rem;font-size:0.8rem;margin-bottom:0.75rem;"></div>
    <div x-show="mensaje" x-text="mensaje" x-cloak
         style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;color:#166534;padding:0.6rem 0.9rem;font-size:0.8rem;margin-bottom:0.75rem;"></div>

    {{-- Las que ya tiene --}}
    <template x-if="!cargando && !prestadas.length">
        <div style="font-size:0.8rem;color:#94a3b8;padding:0.75rem;border:1px dashed #cbd5e1;border-radius:8px;text-align:center;">
            Todavía no tiene ninguna razón social prestada.
        </div>
    </template>
    <div style="display:flex;flex-direction:column;gap:0.4rem;">
        <template x-for="r in prestadas" :key="r.id">
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;padding:0.55rem 0.75rem;border:1px solid #e2e8f0;border-radius:8px;"
                 :style="{ opacity: r.estado !== 'Activa' ? .55 : 1 }">
                <div style="flex:1;min-width:200px;">
                    <div style="font-size:0.83rem;font-weight:700;color:#1e293b;" x-text="r.razon_social"></div>
                    <div style="font-size:0.7rem;color:#64748b;">
                        NIT <span x-text="r.nit"></span> · de <span x-text="r.dueno"></span>
                        · <span x-text="r.vigentes + (r.vigentes === 1 ? ' afiliado vigente' : ' afiliados vigentes')"></span>
                        <span x-show="r.estado !== 'Activa'" x-text="' · ' + r.estado"></span>
                    </div>
                </div>
                <span x-show="r.sucursal" x-text="'Sucursal ' + r.sucursal"
                      style="font-size:0.7rem;font-weight:600;color:#1e40af;background:#dbeafe;border-radius:999px;padding:0.15rem 0.55rem;"></span>
                <span x-show="!r.sucursal" title="Falta crearle la sucursal en el operador"
                      style="font-size:0.7rem;font-weight:600;color:#92400e;background:#fef3c7;border-radius:999px;padding:0.15rem 0.55rem;">⚠️ Sin sucursal</span>
                <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.75rem;color:#475569;cursor:pointer;"
                       :title="r.ve_claves ? 'Ve las claves de portales' : 'No ve las claves: se las maneja BryNex'">
                    <input type="checkbox" :checked="r.ve_claves" @change="cambiarClaves(r, $event.target.checked)"> Ve claves
                </label>
            </div>
        </template>
    </div>

    {{-- Elegir cuáles prestarle --}}
    <div x-show="agregando" x-cloak style="margin-top:1rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-bottom:0.75rem;">
            <select x-model.number="dueno" x-show="duenos.length > 1"
                style="padding:0.45rem 0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;background:#fff;">
                <template x-for="d in duenos" :key="d.id">
                    <option :value="d.id" x-text="'Empresas de ' + d.nombre + ' (' + d.n + ')'" :selected="d.id === dueno"></option>
                </template>
            </select>
            <input type="search" x-model="buscar" placeholder="Buscar por nombre o NIT…"
                style="flex:1;min-width:180px;padding:0.45rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;">
        </div>

        <div style="max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:0.3rem;">
            <template x-for="c in visibles" :key="c.id">
                <label style="display:flex;align-items:center;gap:0.6rem;padding:0.45rem 0.6rem;background:#fff;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;"
                       :style="elegidas.includes(c.id) ? { borderColor: '#2563eb', background: '#eff6ff' } : {}">
                    <input type="checkbox" :value="c.id" x-model.number="elegidas" style="width:16px;height:16px;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.8rem;font-weight:600;color:#1e293b;" x-text="c.razon_social"></div>
                        <div style="font-size:0.68rem;color:#64748b;">
                            NIT <span x-text="c.nit"></span> · <span x-text="c.vigentes + (c.vigentes === 1 ? ' afiliado vigente' : ' afiliados vigentes')"></span>
                        </div>
                    </div>
                    <span x-show="c.ya_la_tiene" title="Este aliado ya tiene una razón social con ese NIT: se liga a la original en vez de crear otra"
                          style="font-size:0.66rem;font-weight:600;color:#6b21a8;background:#f3e8ff;border-radius:999px;padding:0.1rem 0.5rem;white-space:nowrap;">Ya la tiene: se liga</span>
                </label>
            </template>
            <div x-show="!visibles.length" style="font-size:0.78rem;color:#94a3b8;text-align:center;padding:0.75rem;">No hay más empresas para prestar.</div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-top:0.85rem;">
            <label style="display:flex;align-items:center;gap:0.35rem;font-size:0.78rem;color:#475569;cursor:pointer;">
                <input type="checkbox" x-model="veClaves"> Que vea las claves de portales
            </label>
            <div style="display:flex;gap:0.5rem;">
                <button type="button" @click="agregando = false; elegidas = []"
                    style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.8rem;cursor:pointer;">Cancelar</button>
                <button type="button" @click="prestar()" :disabled="!elegidas.length || guardando"
                    :style="(!elegidas.length || guardando) ? { opacity: .5, cursor: 'not-allowed' } : {}"
                    style="padding:0.5rem 1.1rem;background:#16a34a;border:none;border-radius:8px;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;"
                    x-text="guardando ? 'Prestando…' : 'Prestar (' + elegidas.length + ')'"></button>
            </div>
        </div>
    </div>
</div>
@endif

</div>
<script>
// Municipios del departamento escogido, como en la ficha del cliente.
(function () {
    var ciudades  = @json($ciudades);
    var munActual = {{ $munActual ?: 'null' }};
    var selDepto  = document.getElementById('selDepto');
    var selMun    = document.getElementById('selMunicipio');

    function filtrar() {
        var depto = parseInt(selDepto.value);
        selMun.innerHTML = '<option value="">— Seleccionar —</option>';
        if (!depto) return;
        ciudades.forEach(function (c) {
            if (parseInt(c.departamento_id) !== depto) return;
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nombre;
            if (munActual && parseInt(c.id) === parseInt(munActual)) opt.selected = true;
            selMun.appendChild(opt);
        });
    }

    selDepto.addEventListener('change', function () { munActual = null; filtrar(); });
    filtrar();
})();
</script>
@endsection

@push('scripts')
<script>
function toggleEncargado(checked) {
    const div = document.getElementById('divEncargadoAfil');
    div.style.opacity = checked ? '1' : '0.4';
    div.style.pointerEvents = checked ? 'auto' : 'none';
}

@if(isset($aliado->id))
function razonesPrestadas() {
    const url = @json(route('admin.aliados.razones-prestadas', $aliado));
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    const BRYGAR = 2; // las empresas que se prestan son de Brygar (ver AlidoController::DUENO_PRESTADAS)

    return {
        cargando: true, guardando: false, agregando: false,
        error: '', mensaje: '',
        prestadas: [], candidatas: [],
        dueno: null, buscar: '', elegidas: [], veClaves: false,

        get duenos() {
            const m = new Map();
            this.candidatas.forEach(c => {
                const d = m.get(c.dueno_id) || { id: c.dueno_id, nombre: c.dueno, n: 0 };
                d.n++; m.set(c.dueno_id, d);
            });
            return [...m.values()].sort((a, b) => a.nombre.localeCompare(b.nombre));
        },
        get visibles() {
            const q = this.buscar.trim().toLowerCase();
            return this.candidatas.filter(c => c.dueno_id === this.dueno
                && (!q || (c.razon_social || '').toLowerCase().includes(q) || (c.nit || '').includes(q)));
        },

        async cargar() {
            this.cargando = true;
            try {
                const r = await fetch(url, { headers });
                if (!r.ok) throw new Error('No se pudieron cargar las razones sociales (' + r.status + ').');
                const d = await r.json();
                this.prestadas = d.prestadas;
                this.candidatas = d.candidatas;
                if (!this.duenos.some(x => x.id === this.dueno)) {
                    this.dueno = this.duenos.some(x => x.id === BRYGAR) ? BRYGAR : (this.duenos[0]?.id ?? null);
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.cargando = false;
            }
        },

        async prestar() {
            const nombres = this.candidatas.filter(c => this.elegidas.includes(c.id)).map(c => '• ' + c.razon_social).join('\n');
            if (!confirm('¿Prestar estas razones sociales a ' + @json($aliado->nombre) + '?\n\n' + nombres)) return;

            this.guardando = true; this.error = ''; this.mensaje = '';
            try {
                const r = await fetch(url, {
                    method: 'POST', headers,
                    body: JSON.stringify({ razones: this.elegidas, ve_claves: this.veClaves }),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(d.message || 'No se pudo prestar (' + r.status + ').');
                this.mensaje = d.message;
                this.elegidas = []; this.agregando = false;
                await this.cargar();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.guardando = false;
            }
        },

        async cambiarClaves(fila, ve) {
            this.error = '';
            try {
                const r = await fetch(url + '/' + fila.id + '/claves', {
                    method: 'PATCH', headers, body: JSON.stringify({ ve_claves: ve }),
                });
                if (!r.ok) throw new Error('No se pudo cambiar el permiso de claves (' + r.status + ').');
                fila.ve_claves = ve;
            } catch (e) {
                this.error = e.message;
                fila.ve_claves = !ve;
                await this.cargar();
            }
        },
    };
}
@endif
</script>
@endpush
