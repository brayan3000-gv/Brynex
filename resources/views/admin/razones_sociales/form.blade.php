@extends('layouts.app')
@section('modulo', $rs ? 'Editar Razón Social' : 'Nueva Razón Social')

@section('contenido')
<style>
.rs-wrap{max-width:960px;margin:0 auto}
.rs-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;color:#fff;padding:1rem 1.4rem;margin-bottom:.9rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.7rem}
.card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.3rem;margin-bottom:1rem}
.card-title{font-size:.82rem;font-weight:800;color:#0f172a;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.7rem}
.form-full{grid-column:1/-1}
.flb{display:block;font-size:.67rem;font-weight:700;color:#475569;margin-bottom:.18rem;text-transform:uppercase;letter-spacing:.02em}
.flb span{color:#ef4444;margin-left:.15rem}
.finp{width:100%;padding:.42rem .6rem;border:1.5px solid #cbd5e1;border-radius:7px;font-size:.84rem;box-sizing:border-box;transition:border-color .15s;background:#fff}
.finp:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.finp-disabled{background:#f8fafc;color:#94a3b8;cursor:not-allowed}
.toggle-wrap{display:flex;align-items:center;gap:.6rem;padding:.6rem .8rem;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;user-select:none;transition:all .15s}
.toggle-wrap:has(input:checked){border-color:#7c3aed;background:#faf5ff}
.toggle-lbl{font-size:.82rem;font-weight:600;color:#334155}
.toggle-sub{font-size:.65rem;color:#94a3b8;margin-top:.1rem}
.badge-helper{font-size:.62rem;background:#ede9fe;color:#7c3aed;padding:.1rem .4rem;border-radius:12px;font-weight:700;margin-left:.4rem}
.btn-save{padding:.6rem 1.8rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:9px;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3)}
.btn-save:hover{opacity:.9}
.btn-cancel{padding:.6rem 1.2rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none}
.hint{font-size:.62rem;color:#94a3b8;margin:.18rem 0 0}

/* ── Pestañas ─────────────────────────────────────────────────────── */
.rs-tabs{display:flex;gap:.3rem;border-bottom:2px solid #e2e8f0;margin-bottom:1rem;overflow-x:auto;padding-bottom:0}
.rs-tab{background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;padding:.55rem .9rem;font-size:.8rem;font-weight:700;color:#64748b;cursor:pointer;white-space:nowrap;border-radius:8px 8px 0 0;transition:all .15s}
.rs-tab:hover{background:#f8fafc;color:#334155}
.rs-tab.activa{color:#1d4ed8;border-bottom-color:#2563eb;background:#eff6ff}
.rs-tab .pill{background:#e2e8f0;color:#475569;border-radius:20px;padding:.05rem .4rem;font-size:.62rem;margin-left:.3rem}
.rs-panel{display:none}
.rs-panel.activo{display:block}
.btn-claves{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:9px;padding:.5rem .85rem;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:all .15s}
.btn-claves:hover{background:rgba(255,255,255,.2)}

/* ── Modal de claves críticas ─────────────────────────────────────── */
.mc-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:flex-start;justify-content:center;padding:2rem 1rem;overflow-y:auto}
.mc-box{background:#fff;border-radius:14px;max-width:820px;width:100%;box-shadow:0 20px 45px rgba(0,0,0,.3);overflow:hidden}
.mc-head{background:linear-gradient(135deg,#0f172a,#334155);padding:.9rem 1.1rem;display:flex;justify-content:space-between;align-items:center;gap:.6rem}
.mc-tabla{width:100%;border-collapse:collapse;font-size:.78rem;text-align:left}
.mc-tabla th{padding:.5rem .7rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase;background:#f8fafc;border-bottom:2px solid #e2e8f0}
.mc-tabla td{padding:.5rem .7rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.mc-chip{border-radius:20px;padding:.12rem .5rem;font-size:.66rem;font-weight:700;white-space:nowrap}
.mc-mini{padding:.22rem .45rem;border-radius:6px;font-size:.7rem;font-weight:700;cursor:pointer;border:1px solid}
.mc-clave{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.06em;color:#334155}
</style>

@php
    $puedeVerClaves = $rs && auth()->user()->can('credenciales_rs.ver');
    $puedeGestionarClaves = $rs && auth()->user()->can('credenciales_rs.gestionar');
    // Portales de EPS, ARL y caja: van con los permisos del módulo de claves.
    $puedeVerPortales = $rs && auth()->user()->can('claves_acceso.ver');
@endphp

<div class="rs-wrap">

{{-- Header --}}
<div class="rs-header">
    <div>
        <a href="{{ route('admin.configuracion.razones.index') }}" style="color:#94a3b8;font-size:.75rem;text-decoration:none">← Razones Sociales</a>
        <div style="font-size:1.1rem;font-weight:800;margin-top:.2rem">
            {{ $rs ? '✏️ Editar: '.Str::limit($rs->razon_social, 40) : '🏭 Nueva Razón Social' }}
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
        @if($rs)
        <div style="font-size:.75rem;color:#94a3b8">
            ID: {{ $rs->id }} &nbsp;|&nbsp; NIT: {{ $rs->nit ?? $rs->id }}
        </div>
        @endif
        {{-- Claves de portales críticos: DIAN, bancos, cámara de comercio.
             Solo el superadmin del aliado y quien tenga el permiso otorgado.
             El nombre las separa de los portales de entidades, que sí ve
             quien afilia. --}}
        @if($puedeVerClaves)
        <button type="button" class="btn-claves" onclick="abrirModalClaves()"
                title="DIAN, bancos y cámara de comercio · acceso restringido">
            🏦 Claves admon 🔒
        </button>
        @endif

        {{-- La conciliación vive en BryNex y no como pestaña: cruza la póliza
             contra los contratos de todos los aliados, no es un dato de esta
             ficha. Desde aquí se llega directo a la fila de esta empresa —solo
             para quien pueda abrir esa pantalla, que es de BryNex. --}}
        {{-- Prestar la empresa a otro aliado: crea su copia ligada a esta. --}}
        @if($rs && ($puedeHabilitar ?? false) && ! $rs->origen_id)
        <button type="button" class="btn-claves" onclick="abrirHabilitar()"
                title="Crear esta razón social en otro aliado, con los datos de la empresa sincronizados">
            ➕ Habilitar en aliado
        </button>
        @endif

        @if($rs?->arl_poliza && auth()->user()?->can('brynex_cierre.ver'))
        <a href="{{ route('brynex.conciliacion_arl') }}#empresa-{{ preg_replace('/\D/', '', (string) $rs->nit) }}"
           class="btn-claves" style="text-decoration:none;display:inline-block;"
           title="Comparar los afiliados de esta póliza en ARL Sura contra los contratos vigentes de BryNex">
            🛡️ Conciliar ARL
        </a>
        @endif
    </div>
</div>

@if($rs && ($original ?? null))
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.6rem .9rem;margin-bottom:.9rem;font-size:.78rem;color:#1e3a8a;line-height:1.45">
    🔗 <strong>Copia de {{ $origenAliado }}.</strong> Los datos de la empresa (nombre, dirección, representante,
    ARL, caja…) se cambian en la razón social de {{ $origenAliado }} y llegan solos aquí.
    En esta ficha solo se cambia lo de este aliado: <strong>sucursal</strong>, estado y observación.
</div>
@elseif($rs && ($copias ?? collect())->isNotEmpty())
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:.6rem .9rem;margin-bottom:.9rem;font-size:.78rem;color:#14532d;line-height:1.6">
    🤝 <strong>Compartida con:</strong>
    @foreach($copias as $c)
    <span style="display:inline-block;background:#fff;border:1px solid #bbf7d0;border-radius:20px;padding:.05rem .55rem;margin:0 .15rem;font-weight:700">
        {{ $c->aliado }}{{ $c->codigo_sucursal ? ' · suc. '.$c->codigo_sucursal : ' · sin sucursal' }}{{ $c->estado !== 'Activa' ? ' · '.$c->estado : '' }}
    </span>
    @endforeach
    <div style="font-size:.7rem;color:#166534">Al guardar los datos de la empresa aquí, se actualizan también en esos aliados (menos su sucursal).</div>
</div>
@endif

@if($errors->any())
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#dc2626">
    @foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach
</div>
@endif

@if(session('success') && !str_contains(session('success'), 'Sello'))
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#15803d;font-weight:700">
    ✅ {{ session('success') }}
</div>
@endif

{{-- ── Pestañas ── --}}
<div class="rs-tabs">
    <button type="button" class="rs-tab activa" data-tab="datos"     onclick="cambiarTab('datos')">📋 Datos generales</button>
    <button type="button" class="rs-tab"        data-tab="comercial" onclick="cambiarTab('comercial')">💼 Comercial y PILA</button>
    @if($rs)
    <button type="button" class="rs-tab" data-tab="archivos"   onclick="cambiarTab('archivos')">📁 Archivos</button>
    <button type="button" class="rs-tab" data-tab="operadores" onclick="cambiarTab('operadores')">🔑 Operadores API</button>
    @if($puedeVerPortales)
    <button type="button" class="rs-tab" data-tab="portales" onclick="cambiarTab('portales')">🏥 Portales de entidades</button>
    @endif
    @endif
</div>

<form method="POST"
      action="{{ $rs ? route('admin.configuracion.razones.update', $rs->id) : route('admin.configuracion.razones.store') }}">
    @csrf
    @if($rs) @method('PUT') @endif

    {{-- ══ PESTAÑA: DATOS GENERALES ══════════════════════════════════ --}}
    <div class="rs-panel activo" data-panel="datos">

        {{-- Identificación (incluye el tipo de afiliación, que antes era una
             tarjeta suelta con un solo interruptor) --}}
        <div class="card">
            <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>🔢 Identificación</span>
                @if($rs)
                <span style="font-size:.73rem;text-transform:none;font-weight:normal;color:#64748b;background:#f1f5f9;padding:.15rem .5rem;border-radius:6px;border:1px solid #e2e8f0">
                    ID Interno: <strong>{{ $rs->id }}</strong>
                </span>
                @endif
            </div>

            <div class="form-grid-3">
                {{-- NIT real --}}
                <div>
                    <label class="flb">NIT de la empresa</label>
                    <input class="finp" type="number" name="nit"
                           value="{{ old('nit', $rs?->nit) }}"
                           placeholder="Ej: 900123456">
                    <p class="hint">NIT que aparece en los formularios y documentos</p>
                </div>
                {{-- DV --}}
                <div>
                    <label class="flb">Dígito de verificación (DV)</label>
                    <input class="finp" type="number" name="dv" min="0" max="9"
                           value="{{ old('dv', $rs?->dv) }}"
                           placeholder="0-9">
                </div>
                {{-- Estado --}}
                <div>
                    <label class="flb">Estado</label>
                    <select class="finp" name="estado">
                        <option value="Activa"   {{ old('estado', $rs?->estado ?? 'Activa') === 'Activa'   ? 'selected' : '' }}>Activa</option>
                        <option value="Inactiva" {{ old('estado', $rs?->estado)              === 'Inactiva' ? 'selected' : '' }}>Inactiva</option>
                    </select>
                </div>
            </div>

            <div class="form-grid" style="margin-top:.7rem">
                {{-- Razón Social --}}
                <div class="form-full">
                    <label class="flb">Razón Social (nombre) <span>*</span></label>
                    <input class="finp" type="text" name="razon_social"
                           value="{{ old('razon_social', $rs?->razon_social) }}"
                           required placeholder="Nombre completo de la empresa" style="text-transform:uppercase"
                           oninput="this.value=this.value.toUpperCase()">
                </div>

                <div class="form-full">
                    <label class="toggle-wrap">
                        <input type="hidden"   name="es_independiente" value="0">
                        <input type="checkbox" name="es_independiente" value="1"
                               style="width:16px;height:16px;accent-color:#7c3aed"
                               {{ old('es_independiente', $rs?->es_independiente ?? false) ? 'checked' : '' }}>
                        <div>
                            <div class="toggle-lbl">Razón Social Independiente <span class="badge-helper">Indep.</span></div>
                            <div class="toggle-sub">Los contratos solo usarán modalidades independientes (I Act, I Venc, En el Exterior)</div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        {{-- ── Contacto y Ubicación ── --}}
        <div class="card">
            <div class="card-title">📍 Contacto y Ubicación</div>
            <div class="form-grid">
                <div class="form-full">
                    <label class="flb">Dirección</label>
                    <input class="finp" type="text" name="direccion"
                           value="{{ old('direccion', $rs?->direccion) }}"
                           placeholder="Ej: Calle 15 # 5-20, Cali">
                </div>
                <div>
                    <label class="flb">Teléfonos</label>
                    <input class="finp" type="text" name="telefonos"
                           value="{{ old('telefonos', $rs?->telefonos) }}"
                           placeholder="Ej: 6024567890, 3001234567">
                </div>
                <div>
                    <label class="flb">Correos electrónicos</label>
                    <input class="finp" type="text" name="correos"
                           value="{{ old('correos', $rs?->correos) }}"
                           placeholder="Ej: empresa@mail.com">
                </div>
                {{-- Teléfono y correo para formularios (PILA) --}}
                <div>
                    <label class="flb">Teléfono (formularios PILA)</label>
                    <input class="finp" type="text" name="tel_formulario"
                           value="{{ old('tel_formulario', $rs?->tel_formulario) }}"
                           placeholder="Teléfono impreso en formularios">
                </div>
                <div>
                    <label class="flb">Correo (formularios PILA)</label>
                    <input class="finp" type="email" name="correo_formulario"
                           value="{{ old('correo_formulario', $rs?->correo_formulario) }}"
                           placeholder="Correo impreso en formularios">
                </div>
            </div>
        </div>

        {{-- ── Representante Legal ── --}}
        <div class="card">
            <div class="card-title">👤 Representante Legal</div>
            <div class="form-grid">
                <div>
                    <label class="flb">Cédula del representante</label>
                    <input class="finp" type="text" name="cedula_rep"
                           value="{{ old('cedula_rep', $rs?->cedula_rep) }}"
                           placeholder="Número de cédula">
                </div>
                <div>
                    <label class="flb">Nombre del representante</label>
                    <input class="finp" type="text" name="nombre_rep"
                           value="{{ old('nombre_rep', $rs?->nombre_rep) }}"
                           placeholder="Nombre completo" style="text-transform:uppercase"
                           oninput="this.value=this.value.toUpperCase()">
                </div>
            </div>
        </div>

        {{-- ── Observaciones ── --}}
        <div class="card">
            <div class="card-title">📝 Observaciones</div>
            <textarea class="finp" name="observacion" rows="3" style="resize:vertical"
                      placeholder="Información adicional sobre esta razón social...">{{ old('observacion', $rs?->observacion) }}</textarea>
        </div>
    </div>

    {{-- ══ PESTAÑA: COMERCIAL Y PILA ═════════════════════════════════ --}}
    <div class="rs-panel" data-panel="comercial">

        {{-- ── Entidades por Defecto ── --}}
        <div class="card">
            <div class="card-title">🔗 Entidades por Defecto</div>
            <div style="font-size:.7rem;color:#64748b;margin-bottom:.75rem">
                Se pre-cargan automáticamente al crear un contrato con esta razón social.
            </div>
            <div class="form-grid">
                <div>
                    <label class="flb">ARL Predeterminada</label>
                    <select class="finp" name="arl_nit">
                        <option value="">— Sin ARL por defecto —</option>
                        @foreach($arls as $arl)
                        <option value="{{ $arl->nit }}"
                            {{ old('arl_nit', $rs?->arl_nit) == $arl->nit ? 'selected' : '' }}>
                            {{ $arl->nombre_arl }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="flb">Caja de Compensación Predeterminada</label>
                    <select class="finp" name="caja_nit">
                        <option value="">— Sin Caja por defecto —</option>
                        @foreach($cajas as $caja)
                        <option value="{{ $caja->nit }}"
                            {{ old('caja_nit', $rs?->caja_nit) == $caja->nit ? 'selected' : '' }}>
                            {{ $caja->nombre }}
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- ── Datos Comerciales ── --}}
        <div class="card">
            <div class="card-title">🏢 Datos Comerciales</div>
            <div class="form-grid">
                <div>
                    <label class="flb">Actividad Económica</label>
                    <input class="finp" type="text" name="actividad_economica"
                           value="{{ old('actividad_economica', $rs?->actividad_economica) }}"
                           placeholder="Ej: Servicios de salud">
                </div>

                <div>
                    <label class="flb">Fecha de Constitución</label>
                    <input class="finp" type="date" name="fecha_constitucion"
                           value="{{ old('fecha_constitucion', $rs?->fecha_constitucion ? \Carbon\Carbon::parse($rs->fecha_constitucion)->format('Y-m-d') : '') }}">
                </div>

                <div class="form-full">
                    <label class="flb">Objeto Social</label>
                    <textarea class="finp" name="objeto_social" rows="2" style="resize:vertical"
                               placeholder="Descripción del objeto social de la empresa">{{ old('objeto_social', $rs?->objeto_social) }}</textarea>
                </div>
            </div>
        </div>

        {{-- ── Configuración de Pagos + Sucursal PILA ── --}}
        <div class="card">
            <div class="card-title">💳 Pagos y Sucursal PILA</div>
            <div class="form-grid-3">
                <div>
                    <label class="flb">Día límite de pago</label>
                    <input class="finp" type="number" name="fecha_limite_pago" min="1" max="31"
                           value="{{ old('fecha_limite_pago', $rs?->fecha_limite_pago) }}"
                           placeholder="Ej: 10">
                </div>
                <div>
                    <label class="flb">¿Día Hábil?</label>
                    <select class="finp" name="dia_habil">
                        <option value="">— No definido —</option>
                        <option value="1" {{ old('dia_habil', $rs?->dia_habil) == 1 ? 'selected' : '' }}>Sí — día hábil</option>
                        <option value="0" {{ old('dia_habil', $rs?->dia_habil) === '0' || old('dia_habil', $rs?->dia_habil) === 0 ? 'selected' : '' }}>No — día calendario</option>
                    </select>
                </div>
                <div>
                    <label class="flb">Forma de Presentación</label>
                    <input class="finp" type="text" name="forma_presentacion"
                           value="{{ old('forma_presentacion', $rs?->forma_presentacion) }}"
                           placeholder="Ej: Electrónica, Física">
                </div>
                <div>
                    <label class="flb">Código Sucursal</label>
                    <input class="finp" type="text" name="codigo_sucursal"
                           value="{{ old('codigo_sucursal', $rs?->codigo_sucursal) }}"
                           placeholder="Código para planilla PILA">
                </div>
                <div style="grid-column:span 2">
                    <label class="flb">Nombre Sucursal</label>
                    <input class="finp" type="text" name="nombre_sucursal"
                           value="{{ old('nombre_sucursal', $rs?->nombre_sucursal) }}"
                           placeholder="Nombre de la sucursal">
                </div>
            </div>
        </div>
    </div>

    {{-- Botones: solo en las pestañas que hacen parte de este formulario --}}
    <div id="rsAcciones" style="display:flex;justify-content:flex-end;gap:.7rem;margin-bottom:1rem">
        <a href="{{ route('admin.configuracion.razones.index') }}" class="btn-cancel">Cancelar</a>
        <button type="submit" class="btn-save">
            {{ $rs ? '💾 Guardar cambios' : '✅ Crear Razón Social' }}
        </button>
    </div>

</form>

@if($rs)
{{-- ══ PESTAÑA: ARCHIVOS (sello + documentos) ═════════════════════ --}}
@php
    $nitSello   = $rs->nit ?? $rs->id;
    $rutaSello  = storage_path('app/sellos/' . $nitSello . '.png');
    $tieneSello = file_exists($rutaSello);
@endphp

<div class="rs-panel" data-panel="archivos">

    {{-- ── Sello / Firma de la empresa ── --}}
    <div class="card" id="cardSello">
        <div class="card-title">🖼️ Sello / Firma de la empresa</div>

        <div style="display: flex; gap: 1.2rem; align-items: center; flex-wrap: wrap">
            {{-- Preview actual (Izquierda) --}}
            @if($tieneSello)
            @php $selloSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($rutaSello)); @endphp
            <div id="selloPreviewWrap" style="flex: 0 0 auto; text-align: center; border: 1px solid #e2e8f0; border-radius: 10px; padding: .6rem .8rem; background: #f8fafc; box-shadow: 0 1px 4px rgba(0,0,0,.03)">
                <p style="font-size: .62rem; color: #64748b; margin: 0 0 .4rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em">Sello actual</p>
                <img id="selloImgActual"
                     src="{{ $selloSrc }}"
                     style="max-height: 70px; max-width: 180px; border-radius: 6px; padding: 2px; object-fit: contain">
            </div>
            @else
            <div style="flex: 0 0 auto; text-align: center; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 1.2rem 1.8rem; color: #94a3b8; font-size: .75rem; background: #f8fafc; font-weight: 600">
                📭 Sin Sello Registrado
            </div>
            @endif

            {{-- Zona de drop y subida (Derecha) --}}
            <div style="flex: 1; min-width: 260px">
                <div id="selloDropZone"
                     style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: .8rem; text-align: center; cursor: pointer; transition: all .15s; background: #f8fafc"
                     onclick="document.getElementById('inputSello').click()"
                     ondragover="event.preventDefault();this.style.borderColor='#3b82f6';this.style.background='#eff6ff'"
                     ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc'"
                     ondrop="handleSelloDrop(event)">
                    <div style="font-size: 1.6rem">🖼️</div>
                    <div style="font-size: .78rem; font-weight: 700; color: #475569; margin: .15rem 0 .05rem">
                        Arrastra o haz clic para subir nuevo sello
                    </div>
                    <div style="font-size: .6rem; color: #94a3b8">PNG, JPG o WEBP — máx. 5 MB</div>
                </div>

                {{-- Preview nuevo antes de subir --}}
                <div id="selloNuevoWrap" style="display:none; margin-top: .4rem; text-align: center">
                    <p style="font-size: .6rem; color: #64748b; margin: 0 0 .15rem">Nuevo sello seleccionado:</p>
                    <img id="selloImgNuevo" src="" style="max-height: 60px; max-width: 160px; border: 1px solid #3b82f6; border-radius: 8px; padding: 4px; object-fit: contain">
                </div>

                {{-- Formulario de subida --}}
                <form id="formSello" method="POST"
                      action="{{ route('admin.configuracion.razones.sello', $rs->id) }}"
                      enctype="multipart/form-data" style="margin-top: .4rem">
                    @csrf
                    <input type="file" id="inputSello" name="sello" accept=".png,.jpg,.jpeg,.webp"
                           style="display:none" onchange="previewSello(this)">
                    <button type="submit" id="btnSubirSello"
                            style="display:none; width: 100%; padding: .45rem; background: linear-gradient(135deg, #0369a1, #0284c7);
                                   color: #fff; border: none; border-radius: 6px; font-size: .78rem; font-weight: 700; cursor: pointer">
                        💾 Guardar sello
                    </button>
                </form>
            </div>
        </div>

        @if(session('success') && str_contains(session('success'), 'Sello'))
        <div style="margin-top: .6rem; font-size: .72rem; color: #15803d; font-weight: 700; text-align: center">
            ✅ {{ session('success') }}
        </div>
        @endif

        @error('sello')
        <div style="margin-top: .4rem; font-size: .72rem; color: #dc2626; text-align: center">⚠️ {{ $message }}</div>
        @enderror
    </div>

    @include('admin.razones_sociales.partials.documentos')
</div>

{{-- ══ PESTAÑA: OPERADORES API ═══════════════════════════════════════
     Credenciales de las APIs de los operadores. Permiten liquidar la
     planilla desde Brynex sin entrar al portal del operador. Los secretos
     se guardan cifrados y nunca se devuelven aquí. --}}
<div class="rs-panel" data-panel="operadores">
<div class="card" id="cardCredenciales">
    <div class="card-title">🔑 Credenciales de Operadores (APIs)</div>

    @if(session('cred_error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:9px;padding:.6rem .85rem;margin-bottom:.9rem;font-size:.78rem;color:#991b1b;line-height:1.45">
        {{ session('cred_error') }}
    </div>
    @endif

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .9rem;margin-bottom:1rem;font-size:.75rem;color:#475569;line-height:1.45">
        Con estas credenciales Brynex puede <strong>liquidar la planilla directamente en el operador</strong>
        y traer el número de planilla y el link de pago PSE, sin descargar el archivo plano.
        Los datos quedan cifrados en la base de datos.
    </div>

    @php
        // Solo se listan los operadores con integración ya construida. Los
        // demás se irán sumando y aparecerán aquí automáticamente.
        $opsConApi = ($operadoresCred ?? collect())->where('tiene_api', true);
        $opsSinApi = ($operadoresCred ?? collect())->where('tiene_api', false);
    @endphp

    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px">
        <table style="width:100%;border-collapse:collapse;font-size:.78rem;text-align:left">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
                    <th style="padding:.5rem .75rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase">Operador</th>
                    <th style="padding:.5rem .75rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase">Estado</th>
                    <th style="padding:.5rem .75rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase">Usuario</th>
                    <th style="padding:.5rem .75rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase">Clave vence</th>
                    <th style="padding:.5rem .75rem;color:#64748b;font-weight:700;font-size:.62rem;text-transform:uppercase;text-align:right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($opsConApi as $op)
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:.55rem .75rem;font-weight:700;color:#1e293b">
                        🏦 {{ $op->nombre }}
                    </td>
                    <td style="padding:.55rem .75rem">
                        @if(!$op->configurado)
                            <span style="color:#94a3b8;font-weight:600">— Sin configurar</span>
                        @elseif($op->vencida)
                            <span style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:20px;padding:.15rem .55rem;font-size:.68rem;font-weight:700">⚠️ Clave vencida</span>
                        @elseif($op->heredada)
                            <span title="Credencial del aliado: la misma cuenta sirve para todas las razones sociales que ese usuario administre en el operador."
                                  style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:.15rem .55rem;font-size:.68rem;font-weight:700">🔗 Heredada del aliado</span>
                        @else
                            <span style="background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:20px;padding:.15rem .55rem;font-size:.68rem;font-weight:700">✓ Propia de esta RS</span>
                        @endif
                    </td>
                    <td style="padding:.55rem .75rem;color:#475569">{{ $op->usuario ?: '—' }}</td>
                    <td style="padding:.55rem .75rem;color:{{ $op->vencida ? '#dc2626' : '#94a3b8' }};font-size:.72rem">
                        {{ $op->expira_at ? $op->expira_at->format('d/m/Y') : '—' }}
                    </td>
                    <td style="padding:.55rem .75rem;text-align:right;white-space:nowrap">
                        <button type="button"
                                onclick="abrirModalCredencial({{ $op->id }}, @js($op->nombre), {{ $op->tiene_api ? 'true' : 'false' }}, {{ $op->configurado ? 'true' : 'false' }}, @js($op->usuario), @js(optional($op->expira_at)->format('Y-m-d')), {{ $op->heredada ? 'true' : 'false' }})"
                                style="padding:.25rem .5rem;background:#ede9fe;border:1px solid #ddd6fe;border-radius:6px;color:#6d28d9;font-size:.7rem;font-weight:700;cursor:pointer;margin-right:4px">
                            🔑 {{ $op->configurado ? 'Editar' : 'Configurar' }}
                        </button>

                        @if($op->configurado && $op->tiene_api)
                        <form method="POST" action="{{ route('admin.configuracion.razones.credenciales.probar', [$rs->id, $op->id]) }}" style="display:inline">
                            @csrf
                            <button type="submit"
                                    style="padding:.25rem .5rem;background:#dbeafe;border:1px solid #bfdbfe;border-radius:6px;color:#1d4ed8;font-size:.7rem;font-weight:700;cursor:pointer;margin-right:4px">
                                🔌 Probar
                            </button>
                        </form>
                        @endif

                        @if($op->configurado)
                        <form method="POST" action="{{ route('admin.configuracion.razones.credenciales.destroy', [$rs->id, $op->id]) }}"
                              style="display:inline" onsubmit="return confirm('¿Eliminar las credenciales de este operador?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    style="padding:.25rem .5rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;color:#dc2626;font-size:.7rem;font-weight:700;cursor:pointer">
                                🗑️
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="padding:1.2rem;text-align:center;color:#94a3b8;font-size:.8rem">
                        No hay operadores con integración por API activos para este aliado.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($opsSinApi->isNotEmpty())
    <div style="margin-top:.8rem;font-size:.72rem;color:#94a3b8;line-height:1.45">
        ⏳ Todavía sin integración por API:
        <strong>{{ $opsSinApi->pluck('nombre')->implode(', ') }}</strong>.
        Para esos operadores se sigue descargando el archivo plano desde el módulo de planos.
    </div>
    @endif
</div>
</div>

@if($puedeVerPortales)
{{-- ══ PESTAÑA: PORTALES DE ENTIDADES ══════════════════════════════════
     Qué EPS, ARL y caja tienen clave o asesor, y cuáles faltan. La clave es
     de la empresa (por NIT): lo que se llena aquí lo ven los demás aliados
     que comparten esta razón social. Es lo mismo que muestra el panel 🔑 de
     Afiliaciones. --}}
<div class="rs-panel" data-panel="portales">
<div class="card">
    <div class="card-title">🏥 Portales de entidades</div>
    <div id="rsPortales"></div>
</div>
</div>
@include('admin.partials._portales_entidades')
@endif

{{-- Modal de credenciales de operador --}}
<div id="modalCredencial"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;box-shadow:0 20px 45px rgba(0,0,0,.25);overflow:hidden">
        <div style="background:linear-gradient(135deg,#6d28d9,#8b5cf6);padding:.9rem 1.1rem;display:flex;justify-content:space-between;align-items:center">
            <h3 style="color:#fff;font-size:.9rem;font-weight:800;margin:0">🔑 Credenciales — <span id="credOperadorNombre"></span></h3>
            <button type="button" onclick="cerrarModalCredencial()"
                    style="background:none;border:none;color:#ddd6fe;font-size:1.1rem;cursor:pointer;line-height:1">✕</button>
        </div>

        <form method="POST" action="{{ route('admin.configuracion.razones.credenciales.store', $rs->id) }}" style="padding:1.1rem">
            @csrf
            <input type="hidden" name="operador_planilla_id" id="credOperadorId">

            <div id="credAyudaEnlace"
                 style="display:none;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:9px;padding:.6rem .8rem;margin-bottom:.9rem;font-size:.72rem;color:#5b21b6;line-height:1.45">
                El <strong>usuario</strong> es la unión del tipo y el número de documento con el que se ingresa
                al portal (ej. <code>CC1234567</code>). La <strong>contraseña</strong> son 4 dígitos numéricos.
                La <strong>clave secreta</strong> se genera desde el tablero del operador y vence al año.
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Usuario *</label>
                <input type="text" name="usuario" id="credUsuario" required autocomplete="off"
                       placeholder="CC1234567"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">
                    Contraseña <span id="credObligU">*</span>
                </label>
                <input type="password" name="contrasena" id="credContrasena" autocomplete="new-password"
                       placeholder="••••"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">
                    Clave secreta <span id="credObligC">*</span>
                </label>
                <input type="password" name="clave_secreta" id="credClaveSecreta" autocomplete="new-password"
                       placeholder="Clave generada en el tablero del operador"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:1rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Vencimiento de la clave secreta</label>
                <input type="date" name="clave_secreta_expira_at" id="credExpira"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
                <div style="font-size:.66rem;color:#94a3b8;margin-top:.2rem">Por defecto, un año desde hoy.</div>
            </div>

            {{-- Alcance. Lo normal es una sola credencial por aliado: la cuenta
                 del operador sirve para todos los aportantes que ese usuario
                 administre, no hace falta repetirla en cada razón social. --}}
            <label style="display:flex;gap:.5rem;align-items:flex-start;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:9px;padding:.6rem .8rem;margin-bottom:.9rem;cursor:pointer">
                <input type="checkbox" name="todas_razones" id="credTodasRazones" value="1" checked style="margin-top:.15rem">
                <span style="font-size:.72rem;color:#5b21b6;line-height:1.45">
                    <strong>Usar en todas las razones sociales</strong><br>
                    La cuenta del operador cubre todos los aportantes que ese usuario administre.
                    Desmarque solo si esta razón social entra con una cuenta distinta.
                </span>
            </label>

            <div id="credAvisoEditar"
                 style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.55rem .75rem;margin-bottom:.9rem;font-size:.7rem;color:#64748b">
                Deje la contraseña y la clave secreta en blanco para conservar las actuales.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.6rem">
                <button type="button" onclick="cerrarModalCredencial()"
                        style="padding:.5rem .9rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;color:#475569;font-size:.8rem;font-weight:700;cursor:pointer">
                    Cancelar
                </button>
                <button type="submit"
                        style="padding:.5rem 1rem;background:linear-gradient(135deg,#6d28d9,#8b5cf6);border:none;border-radius:8px;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer">
                    💾 Guardar
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ══ MODAL: CLAVES Y ACCESOS (DIAN, bancos, cámara) ════════════════ --}}
@if($puedeVerClaves)
<div id="modalClaves" class="mc-overlay" onclick="if(event.target===this) cerrarModalClaves()">
    <div class="mc-box">
        <div class="mc-head">
            <div>
                <h3 style="color:#fff;font-size:.92rem;font-weight:800;margin:0">🏦 Claves admon 🔒</h3>
                <div style="color:#cbd5e1;font-size:.72rem;margin-top:.15rem">
                    NIT {{ $rs->nit ? number_format($rs->nit, 0, '', '.') : '—' }}{{ $rs->dv !== null && $rs->dv !== '' ? '-'.$rs->dv : '' }}
                    &nbsp;·&nbsp; {{ Str::limit($rs->razon_social, 45) }}
                </div>
            </div>
            <button type="button" onclick="cerrarModalClaves()"
                    style="background:none;border:none;color:#cbd5e1;font-size:1.2rem;cursor:pointer;line-height:1">✕</button>
        </div>

        <div style="padding:1rem 1.1rem">
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:.55rem .8rem;margin-bottom:.9rem;font-size:.71rem;color:#92400e;line-height:1.45">
                Portales de <strong>DIAN, bancos y cámara de comercio</strong>. La contraseña se guarda cifrada
                y solo se muestra al pulsar el ojo — cada vez que alguien la revela queda registrado en la bitácora.
            </div>

            {{-- Listado --}}
            <div id="clavesLista" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;overflow-x:auto">
                <div style="padding:1.2rem;text-align:center;color:#94a3b8;font-size:.8rem">Cargando…</div>
            </div>

            @if($puedeGestionarClaves)
            <div style="margin-top:.8rem">
                <button type="button" id="btnNuevaClave" onclick="nuevaClave()"
                        style="padding:.45rem .9rem;background:#0f172a;border:none;border-radius:8px;color:#fff;font-size:.78rem;font-weight:700;cursor:pointer">
                    ＋ Nueva clave
                </button>
            </div>

            {{-- Formulario --}}
            <form id="formClave" onsubmit="guardarClave(event)"
                  style="display:none;margin-top:.9rem;border-top:1.5px solid #f1f5f9;padding-top:.9rem">
                <input type="hidden" id="claveId">

                <div class="form-grid">
                    <div>
                        <label class="flb">Tipo de portal <span>*</span></label>
                        <select class="finp" id="claveTipo" required onchange="sugerirEntidad()">
                            @foreach(\App\Models\RazonSocialCredencial::TIPOS as $valor => $etiqueta)
                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="flb">Entidad <span>*</span></label>
                        <input class="finp" id="claveEntidad" list="sugerenciasEntidad" required maxlength="150"
                               placeholder="Ej: Bancolombia">
                        <datalist id="sugerenciasEntidad"></datalist>
                    </div>

                    <div class="form-full">
                        <label class="flb">Link para entrar</label>
                        <input class="finp" id="claveLink" maxlength="350" placeholder="https://…">
                    </div>

                    <div>
                        <label class="flb">Usuario</label>
                        <input class="finp" id="claveUsuario" maxlength="150" autocomplete="off"
                               placeholder="Usuario del portal">
                    </div>
                    <div>
                        <label class="flb">Contraseña</label>
                        <div style="display:flex;gap:.35rem">
                            <input class="finp" id="claveContrasena" type="password" maxlength="200" autocomplete="new-password"
                                   placeholder="••••••••">
                            <button type="button" onclick="ojoFormulario()" id="btnOjoForm"
                                    style="padding:.3rem .55rem;background:#f1f5f9;border:1.5px solid #cbd5e1;border-radius:7px;cursor:pointer;font-size:.9rem">👁️</button>
                        </div>
                        <p class="hint" id="hintContrasena" style="display:none">En blanco conserva la contraseña actual.</p>
                    </div>

                    <div class="form-full">
                        <label class="flb">Observación</label>
                        <textarea class="finp" id="claveObservacion" rows="2" maxlength="500" style="resize:vertical"
                                  placeholder="Ej: token en el celular de contabilidad, preguntas de seguridad…"></textarea>
                    </div>
                </div>

                <div id="claveError" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.5rem .75rem;margin-top:.7rem;font-size:.75rem;color:#dc2626"></div>

                <div style="display:flex;justify-content:flex-end;gap:.6rem;margin-top:.9rem">
                    <button type="button" onclick="cancelarClave()"
                            style="padding:.5rem .9rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;color:#475569;font-size:.8rem;font-weight:700;cursor:pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarClave"
                            style="padding:.5rem 1rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:8px;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer">
                        💾 Guardar
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
@endif

</div>

<script>
// ── Pestañas ────────────────────────────────────────────────────────
// La pestaña activa se recuerda por razón social: subir un documento o el
// sello recarga la página, y volver siempre a "Datos generales" obligaría a
// buscar de nuevo dónde se estaba.
const RS_TAB_KEY = 'rsTab:{{ $rs->id ?? "nueva" }}';

function cambiarTab(nombre) {
    document.querySelectorAll('.rs-tab').forEach(b => b.classList.toggle('activa', b.dataset.tab === nombre));
    document.querySelectorAll('.rs-panel').forEach(p => p.classList.toggle('activo', p.dataset.panel === nombre));

    // Los botones de guardar pertenecen al formulario de datos: no tienen
    // nada que hacer en Archivos ni en Operadores, que guardan por su cuenta.
    const acciones = document.getElementById('rsAcciones');
    if (acciones) acciones.style.display = (nombre === 'datos' || nombre === 'comercial') ? 'flex' : 'none';

    try { sessionStorage.setItem(RS_TAB_KEY, nombre); } catch (e) {}

    // Los portales se cargan la primera vez que se abre la pestaña.
    if (nombre === 'portales' && window.Portales && !cambiarTab.portales) {
        cambiarTab.portales = true;
        Portales.montar('rsPortales', {{ $rs->id ?? 0 }});
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Si la validación falló, el usuario tiene que ver los campos del form.
    const hayErrores = {{ $errors->any() ? 'true' : 'false' }};
    let inicial = 'datos';
    if (!hayErrores) {
        try { inicial = sessionStorage.getItem(RS_TAB_KEY) || 'datos'; } catch (e) {}
    }
    if (!document.querySelector(`.rs-tab[data-tab="${inicial}"]`)) inicial = 'datos';
    cambiarTab(inicial);
});

// ── Sello ───────────────────────────────────────────────────────────
function previewSello(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('selloImgNuevo').src = e.target.result;
        document.getElementById('selloNuevoWrap').style.display = 'block';
        document.getElementById('btnSubirSello').style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}
function handleSelloDrop(e) {
    e.preventDefault();
    document.getElementById('selloDropZone').style.borderColor = '#cbd5e1';
    document.getElementById('selloDropZone').style.background  = '#f8fafc';
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('inputSello');
    const dt    = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    previewSello(input);
}

// ── Credenciales de operadores (API) ────────────────────────────────
function abrirModalCredencial(operadorId, nombre, tieneApi, configurado, usuario, expira, heredada) {
    document.getElementById('credOperadorId').value      = operadorId;
    document.getElementById('credOperadorNombre').textContent = nombre;
    document.getElementById('credAyudaEnlace').style.display  = tieneApi ? 'block' : 'none';
    document.getElementById('credAvisoEditar').style.display  = configurado ? 'block' : 'none';

    // Al editar se respeta el alcance actual; al crear, por defecto todas.
    document.getElementById('credTodasRazones').checked = configurado ? !!heredada : true;

    // Al editar, los secretos son opcionales: en blanco se conservan.
    document.getElementById('credObligU').style.display = configurado ? 'none' : '';
    document.getElementById('credObligC').style.display = configurado ? 'none' : '';

    document.getElementById('credUsuario').value      = usuario || '';
    document.getElementById('credContrasena').value   = '';
    document.getElementById('credClaveSecreta').value = '';

    if (expira) {
        document.getElementById('credExpira').value = expira;
    } else {
        const d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.getElementById('credExpira').value = d.toISOString().substring(0, 10);
    }

    document.getElementById('modalCredencial').style.display = 'flex';
    document.getElementById('credUsuario').focus();
}

function cerrarModalCredencial() {
    // No dejar secretos en el DOM al cerrar.
    document.getElementById('credContrasena').value   = '';
    document.getElementById('credClaveSecreta').value = '';
    document.getElementById('modalCredencial').style.display = 'none';
}
</script>

@if($puedeVerClaves)
<script>
// ══ Claves y accesos (DIAN, bancos, cámara) ═════════════════════════
// La contraseña NUNCA viene en el listado: se pide una a una al pulsar el
// ojo. Así el inspector del navegador no la muestra por el simple hecho de
// haber abierto el modal, y cada revelación queda en la bitácora.
// El listado y el alta comparten URL; editar/eliminar/revelar cuelgan de ahí.
const CLAVES_BASE  = "{{ route('admin.configuracion.razones.claves.index', $rs->id) }}";
const CLAVES_URL   = CLAVES_BASE;
const PUEDE_GESTIONAR_CLAVES = {{ $puedeGestionarClaves ? 'true' : 'false' }};
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;

// Sugerencias por tipo. Es solo ayuda para escribir: el campo es libre.
const SUGERENCIAS = {
    DIAN:            ['DIAN — MUISCA'],
    BANCO:           ['Bancolombia', 'Banco de Bogotá', 'Nequi', 'Davivienda', 'BBVA', 'Banco Agrario', 'Daviplata'],
    CAMARA_COMERCIO: ['Cámara de Comercio de Cali', 'Cámara de Comercio de Bogotá', 'Cámara de Comercio de Medellín'],
    OTRO:            [],
};

const COLOR_TIPO = {
    DIAN:            'background:#fef3c7;color:#92400e;border:1px solid #fde68a',
    BANCO:           'background:#dcfce7;color:#166534;border:1px solid #86efac',
    CAMARA_COMERCIO: 'background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe',
    OTRO:            'background:#f1f5f9;color:#475569;border:1px solid #e2e8f0',
};

let clavesCache = [];

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function abrirModalClaves() {
    document.getElementById('modalClaves').style.display = 'flex';
    cancelarClave();
    cargarClaves();
}

function cerrarModalClaves() {
    // Que no quede ningún secreto en el DOM al cerrar.
    document.querySelectorAll('.mc-clave').forEach(el => { el.textContent = '••••••••'; el.dataset.visible = '0'; });
    cancelarClave();
    document.getElementById('modalClaves').style.display = 'none';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('modalClaves')?.style.display === 'flex') cerrarModalClaves();
});

async function cargarClaves() {
    const cont = document.getElementById('clavesLista');
    cont.innerHTML = '<div style="padding:1.2rem;text-align:center;color:#94a3b8;font-size:.8rem">Cargando…</div>';
    try {
        const r = await fetch(CLAVES_URL, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        if (!r.ok) throw new Error('No se pudo cargar');
        const data = await r.json();
        clavesCache = data.claves || [];
        pintarClaves();
    } catch (e) {
        cont.innerHTML = '<div style="padding:1.2rem;text-align:center;color:#dc2626;font-size:.8rem">No se pudieron cargar las claves.</div>';
    }
}

function pintarClaves() {
    const cont = document.getElementById('clavesLista');

    if (!clavesCache.length) {
        cont.innerHTML = '<div style="padding:1.4rem;text-align:center;color:#94a3b8;font-size:.8rem">📭 Todavía no hay claves guardadas para esta razón social.</div>';
        return;
    }

    let html = '<table class="mc-tabla"><thead><tr>' +
        '<th>Tipo</th><th>Entidad</th><th>Usuario</th><th>Contraseña</th><th style="text-align:right">Acciones</th>' +
        '</tr></thead><tbody>';

    clavesCache.forEach(c => {
        const link = c.link_acceso
            ? `<a href="${esc(c.link_acceso)}" target="_blank" rel="noopener noreferrer" title="${esc(c.link_acceso)}" style="color:#1d4ed8;text-decoration:none;font-size:.7rem">🔗 entrar</a>`
            : '';
        const obs = c.observacion
            ? `<div style="font-size:.66rem;color:#94a3b8;margin-top:.15rem">${esc(c.observacion)}</div>`
            : '';

        html += `<tr>
            <td><span class="mc-chip" style="${COLOR_TIPO[c.tipo] || COLOR_TIPO.OTRO}">${esc(c.tipo_etiqueta)}</span></td>
            <td><strong style="color:#1e293b">${esc(c.entidad)}</strong> ${link}${obs}</td>
            <td style="color:#475569">${esc(c.usuario) || '—'}</td>
            <td>
                ${c.tiene_contrasena
                    ? `<span class="mc-clave" id="clave-${c.id}" data-visible="0">••••••••</span>
                       <button type="button" class="mc-mini" style="background:#f1f5f9;border-color:#cbd5e1;margin-left:.3rem"
                               onclick="alternarOjo(${c.id})" id="ojo-${c.id}" title="Ver la contraseña">👁️</button>`
                    : '<span style="color:#94a3b8;font-size:.72rem">— sin clave —</span>'}
            </td>
            <td style="text-align:right;white-space:nowrap">
                ${PUEDE_GESTIONAR_CLAVES ? `
                <button type="button" class="mc-mini" style="background:#dbeafe;border-color:#bfdbfe;color:#1d4ed8"
                        onclick="editarClave(${c.id})">✏️</button>
                <button type="button" class="mc-mini" style="background:#fee2e2;border-color:#fca5a5;color:#dc2626"
                        onclick="eliminarClave(${c.id})">🗑️</button>` : ''}
            </td>
        </tr>`;
    });

    cont.innerHTML = html + '</tbody></table>';
}

// El ojo: pide la contraseña al servidor y la vuelve a tapar sola a los 30 s.
const temporizadores = {};
async function alternarOjo(id) {
    const span = document.getElementById('clave-' + id);
    const ojo  = document.getElementById('ojo-' + id);
    if (!span) return;

    if (span.dataset.visible === '1') {
        span.textContent = '••••••••';
        span.dataset.visible = '0';
        ojo.textContent = '👁️';
        clearTimeout(temporizadores[id]);
        return;
    }

    ojo.textContent = '⏳';
    try {
        const r = await fetch(`${CLAVES_BASE}/${id}/revelar`, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        if (!r.ok) throw new Error();
        const data = await r.json();
        span.textContent = data.contrasena || '(vacía)';
        span.dataset.visible = '1';
        ojo.textContent = '🙈';
        clearTimeout(temporizadores[id]);
        temporizadores[id] = setTimeout(() => {
            span.textContent = '••••••••';
            span.dataset.visible = '0';
            ojo.textContent = '👁️';
        }, 30000);
    } catch (e) {
        ojo.textContent = '👁️';
        alert('No se pudo obtener la contraseña.');
    }
}

@if($puedeGestionarClaves)
function sugerirEntidad() {
    const tipo = document.getElementById('claveTipo').value;
    document.getElementById('sugerenciasEntidad').innerHTML =
        (SUGERENCIAS[tipo] || []).map(s => `<option value="${esc(s)}"></option>`).join('');
}

function nuevaClave() {
    document.getElementById('claveId').value          = '';
    document.getElementById('claveTipo').value        = 'BANCO';
    document.getElementById('claveEntidad').value     = '';
    document.getElementById('claveLink').value        = '';
    document.getElementById('claveUsuario').value     = '';
    document.getElementById('claveContrasena').value  = '';
    document.getElementById('claveObservacion').value = '';
    document.getElementById('hintContrasena').style.display = 'none';
    document.getElementById('claveError').style.display = 'none';
    sugerirEntidad();

    document.getElementById('formClave').style.display    = 'block';
    document.getElementById('btnNuevaClave').style.display = 'none';
    document.getElementById('claveEntidad').focus();
}

function editarClave(id) {
    const c = clavesCache.find(x => x.id === id);
    if (!c) return;

    document.getElementById('claveId').value          = c.id;
    document.getElementById('claveTipo').value        = c.tipo;
    document.getElementById('claveEntidad').value     = c.entidad || '';
    document.getElementById('claveLink').value        = c.link_acceso || '';
    document.getElementById('claveUsuario').value     = c.usuario || '';
    document.getElementById('claveContrasena').value  = '';
    document.getElementById('claveObservacion').value = c.observacion || '';
    document.getElementById('hintContrasena').style.display = c.tiene_contrasena ? 'block' : 'none';
    document.getElementById('claveError').style.display = 'none';
    sugerirEntidad();

    document.getElementById('formClave').style.display    = 'block';
    document.getElementById('btnNuevaClave').style.display = 'none';
    document.getElementById('claveEntidad').focus();
}

function cancelarClave() {
    const form = document.getElementById('formClave');
    if (!form) return;
    document.getElementById('claveContrasena').value = '';
    document.getElementById('claveContrasena').type  = 'password';
    document.getElementById('btnOjoForm').textContent = '👁️';
    form.style.display = 'none';
    const btn = document.getElementById('btnNuevaClave');
    if (btn) btn.style.display = 'inline-block';
}

function ojoFormulario() {
    const inp = document.getElementById('claveContrasena');
    const esTexto = inp.type === 'text';
    inp.type = esTexto ? 'password' : 'text';
    document.getElementById('btnOjoForm').textContent = esTexto ? '👁️' : '🙈';
}

async function guardarClave(e) {
    e.preventDefault();
    const id  = document.getElementById('claveId').value;
    const btn = document.getElementById('btnGuardarClave');
    const err = document.getElementById('claveError');

    btn.disabled = true;
    btn.textContent = 'Guardando…';
    err.style.display = 'none';

    const cuerpo = {
        tipo:        document.getElementById('claveTipo').value,
        entidad:     document.getElementById('claveEntidad').value,
        link_acceso: document.getElementById('claveLink').value,
        usuario:     document.getElementById('claveUsuario').value,
        contrasena:  document.getElementById('claveContrasena').value,
        observacion: document.getElementById('claveObservacion').value,
    };

    try {
        const r = await fetch(id ? `${CLAVES_BASE}/${id}` : CLAVES_BASE, {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(cuerpo),
        });

        if (!r.ok) {
            const data = await r.json().catch(() => ({}));
            const msg  = data.errors
                ? Object.values(data.errors).flat().join(' ')
                : (data.message || 'No se pudo guardar.');
            err.textContent = msg;
            err.style.display = 'block';
            return;
        }

        cancelarClave();
        await cargarClaves();
    } catch (ex) {
        err.textContent = 'No se pudo guardar. Revise la conexión.';
        err.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Guardar';
    }
}

async function eliminarClave(id) {
    const c = clavesCache.find(x => x.id === id);
    if (!confirm(`¿Eliminar la clave de ${c ? c.entidad : 'esta entidad'}?`)) return;

    try {
        const r = await fetch(`${CLAVES_BASE}/${id}`, {
            method: 'DELETE',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        if (!r.ok) throw new Error();
        await cargarClaves();
    } catch (e) {
        alert('No se pudo eliminar la clave.');
    }
}
@else
// Sin permiso de gestión el formulario no existe: los botones tampoco.
function nuevaClave() {}
function cancelarClave() {}
@endif
</script>
@endif

@if($rs && ($puedeHabilitar ?? false) && ! $rs->origen_id)
{{-- ══ MODAL: HABILITAR EN ALIADO ══════════════════════════════════════ --}}
<div id="modalHabilitar" class="mc-overlay" onclick="if(event.target===this) cerrarHabilitar()">
    <div class="mc-box" style="max-width:520px">
        <div class="mc-head">
            <div>
                <h3 style="color:#fff;font-size:.92rem;font-weight:800;margin:0">➕ Habilitar en aliado</h3>
                <div style="color:#cbd5e1;font-size:.72rem;margin-top:.15rem">{{ Str::limit($rs->razon_social, 50) }} · NIT {{ $rs->nit }}</div>
            </div>
            <button type="button" onclick="cerrarHabilitar()" style="background:none;border:none;color:#cbd5e1;font-size:1.2rem;cursor:pointer;line-height:1">✕</button>
        </div>
        <div style="padding:1rem 1.1rem">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.55rem .8rem;margin-bottom:.8rem;font-size:.72rem;color:#475569;line-height:1.5">
                Se crea la razón social en el aliado con los datos de la empresa, y queda ligada a esta:
                lo que cambies aquí le llega solo. Las <strong>claves de portales</strong> las ve de inmediato (van por NIT).
                La <strong>sucursal</strong> es de cada aliado: hay que crearla en el operador y ponerla en su ficha.
            </div>
            <div id="habLista" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
                <div style="padding:1rem;text-align:center;color:#94a3b8;font-size:.8rem">Cargando…</div>
            </div>
            <div id="habMsg" style="display:none;margin-top:.7rem;border-radius:8px;padding:.5rem .7rem;font-size:.76rem"></div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:.5rem;padding:.75rem 1.1rem;border-top:1px solid #f1f5f9;background:#f8fafc">
            <button type="button" class="btn-cancel" onclick="cerrarHabilitar()">Cerrar</button>
            <button type="button" id="habBtn" class="btn-save" style="padding:.45rem 1.2rem;font-size:.82rem" onclick="habilitarAliados()">Habilitar</button>
        </div>
    </div>
</div>

<script>
const HAB_ESTADOS = {
    original:     ['Dueña', '#e0e7ff', '#3730a3'],
    copia:        ['Ya habilitada', '#dcfce7', '#15803d'],
    sin_vincular: ['La tiene sin vincular', '#fef9c3', '#854d0e'],
    no_la_tiene:  ['No la tiene', '#f1f5f9', '#475569'],
};

function abrirHabilitar() {
    document.getElementById('modalHabilitar').style.display = 'flex';
    document.getElementById('habMsg').style.display = 'none';
    const lista = document.getElementById('habLista');
    lista.innerHTML = '<div style="padding:1rem;text-align:center;color:#94a3b8;font-size:.8rem">Cargando…</div>';
    fetch(@json(route('admin.configuracion.razones.aliados', $rs->id)), { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(aliados => {
            lista.innerHTML = aliados.map(a => {
                const [txt, bg, fg] = HAB_ESTADOS[a.estado];
                const elegible = a.estado === 'no_la_tiene' || a.estado === 'sin_vincular';
                return `<label style="display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.8rem;${elegible ? 'cursor:pointer' : 'opacity:.75'}">
                    <input type="checkbox" value="${a.aliado_id}" ${elegible ? '' : 'disabled'}>
                    <span style="flex:1;font-weight:700;color:#0f172a">${a.aliado.replace(/</g, '&lt;')}</span>
                    ${a.sucursal ? `<span style="font-size:.66rem;color:#64748b">suc. ${a.sucursal}</span>` : ''}
                    <span style="background:${bg};color:${fg};border-radius:20px;padding:.08rem .5rem;font-size:.64rem;font-weight:800">${txt}</span>
                </label>`;
            }).join('');
        })
        .catch(e => { lista.innerHTML = `<div style="padding:1rem;color:#b91c1c;font-size:.8rem">No se pudo cargar (${e}).</div>`; });
}

function cerrarHabilitar() {
    document.getElementById('modalHabilitar').style.display = 'none';
}

function habilitarAliados() {
    const ids = [...document.querySelectorAll('#habLista input:checked')].map(i => i.value);
    const msg = document.getElementById('habMsg');
    if (!ids.length) {
        msg.style.cssText = 'display:block;margin-top:.7rem;border-radius:8px;padding:.5rem .7rem;font-size:.76rem;background:#fee2e2;color:#b91c1c';
        msg.textContent = 'Elige al menos un aliado.';
        return;
    }
    const btn = document.getElementById('habBtn');
    btn.disabled = true; btn.textContent = 'Habilitando…';
    fetch(@json(route('admin.configuracion.razones.habilitar', $rs->id)), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ aliados: ids }),
    })
        .then(async r => { const d = await r.json().catch(() => ({})); if (!r.ok) throw (d.message || `Error ${r.status}`); return d; })
        .then(d => {
            msg.style.cssText = 'display:block;margin-top:.7rem;border-radius:8px;padding:.5rem .7rem;font-size:.76rem;background:#dcfce7;color:#15803d';
            msg.textContent = '✅ ' + d.message;
            setTimeout(() => location.reload(), 2500);
        })
        .catch(e => {
            msg.style.cssText = 'display:block;margin-top:.7rem;border-radius:8px;padding:.5rem .7rem;font-size:.76rem;background:#fee2e2;color:#b91c1c';
            msg.textContent = e;
        })
        .finally(() => { btn.disabled = false; btn.textContent = 'Habilitar'; });
}
</script>
@endif

@if($rs && ($original ?? null))
<script>
// Copia de otro aliado: los datos de la empresa no se editan aquí (el
// servidor igual los ignora). Se dejan a la vista, sin poder cambiarlos.
document.addEventListener('DOMContentLoaded', () => {
    @json($camposEmpresa).forEach(nombre => {
        document.querySelectorAll(`form [name="${nombre}"]`).forEach(el => {
            if (el.type === 'hidden') return;
            el.readOnly = true;
            el.tabIndex = -1;
            el.style.pointerEvents = 'none';
            el.classList.add('finp-disabled');
            el.closest('label.toggle-wrap')?.style.setProperty('pointer-events', 'none');
            el.title = 'Viene de {{ $origenAliado }}: se cambia en su razón social';
        });
    });
});
</script>
@endif
@endsection
