@extends('layouts.app')
@section('modulo', $titulo)

@section('contenido')
<style>
/* ── Layout ──────────────────────────────────────────── */
.fmap-wrap   { display:grid;grid-template-columns:285px 1fr;gap:1rem;height:calc(100vh - 85px); }
.fmap-panel  { background:#0f172a;border-radius:12px;padding:0.9rem;display:flex;flex-direction:column;gap:0.5rem;overflow:hidden; }
.fmap-viewer { background:#334155;border-radius:12px;overflow:hidden;display:flex;flex-direction:column; }

/* ── Panel izquierdo ────────────────────────────────── */
.fmap-title  { color:#fff;font-size:0.9rem;font-weight:800; }
.fmap-sub    { color:#475569;font-size:0.68rem;line-height:1.4; }
.eps-select  { width:100%;background:#1e3a5f;color:#e2e8f0;border:1px solid #334155;border-radius:8px;padding:0.4rem 0.6rem;font-size:0.8rem;font-weight:700; }

.upload-zone { border:1.5px dashed #334155;border-radius:8px;padding:0.5rem;text-align:center;
               font-size:0.7rem;color:#64748b;cursor:pointer;transition:all .15s; }
.upload-zone:hover { border-color:#3b82f6;color:#3b82f6; }
.upload-zone input { display:none; }

.campo-list  { flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:2px;min-height:0; }
.campo-item  { border-radius:6px;padding:0.3rem 0.5rem;font-size:0.7rem;cursor:pointer;
               display:flex;align-items:center;gap:0.4rem;border:1.5px solid transparent;
               transition:all .12s;background:#1e293b;color:#94a3b8;user-select:none; }
.campo-item:hover  { background:#1e3a5f;color:#e2e8f0; }
.campo-item.activo { background:#1e40af;color:#fff;border-color:#3b82f6;font-weight:700; }
.campo-item.mapeado { border-color:#16a34a;color:#86efac; }
.campo-item.mapeado.activo { background:#14532d;border-color:#4ade80; }
.campo-item.es-x { border-color:#f59e0b;color:#fcd34d; }
.campo-item.es-x.activo { background:#78350f;border-color:#fbbf24; }

/* ── Botón Agregar X ─────────────────────────────────── */
.btn-add-x { width:100%;background:linear-gradient(135deg,#b45309,#d97706);color:#fff;
             border:none;border-radius:8px;padding:0.45rem;font-size:0.78rem;font-weight:700;
             cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.4rem; }
.btn-add-x:hover { filter:brightness(1.12); }
.campo-dot   { width:8px;height:8px;border-radius:50%;background:currentColor;flex-shrink:0; }

/* ── Botón Repetir / Custom ───────────────────────────── */
.btn-add-rep    { width:100%;background:linear-gradient(135deg,#065f46,#059669);color:#fff;
                  border:none;border-radius:8px;padding:0.45rem;font-size:0.78rem;font-weight:700;
                  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.4rem; }
.btn-add-rep:hover { filter:brightness(1.12); }
.btn-add-custom { width:100%;background:linear-gradient(135deg,#3730a3,#4f46e5);color:#fff;
                  border:none;border-radius:8px;padding:0.45rem;font-size:0.78rem;font-weight:700;
                  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.4rem; }
.btn-add-custom:hover { filter:brightness(1.12); }
.campo-item.es-rep    { border-color:#059669;color:#6ee7b7; }
.campo-item.es-rep.activo    { background:#064e3b;border-color:#34d399; }
.campo-item.es-custom { border-color:#6366f1;color:#a5b4fc; }
.campo-item.es-custom.activo { background:#312e81;border-color:#818cf8; }

/* ── Toolbar visor ───────────────────────────────────── */
.fmap-toolbar { background:#1e293b;padding:0.4rem 0.8rem;display:flex;align-items:center;gap:0.5rem;flex-shrink:0; }
.fmap-toolbar button { background:#334155;color:#e2e8f0;border:none;border-radius:6px;padding:0.25rem 0.6rem;font-size:0.72rem;cursor:pointer; }
.fmap-toolbar button:hover { background:#475569; }
.page-info   { font-size:0.72rem;color:#94a3b8; }
.fmap-status { font-size:0.7rem;color:#fbbf24;font-weight:700;padding:0.2rem 0.6rem;background:#422006;border-radius:6px;flex:1;text-align:center;min-width:0; }

/* ── Canvas ────────────────────────────────────────── */
.canvas-wrap { flex:1;overflow:auto;position:relative;cursor:crosshair; }
#pdfCanvas   { display:block; }

/* ── Rectángulos mapeados ────────────────────────────── */
.rect-overlay { position:absolute;border:2px solid;border-radius:2px;pointer-events:auto;
               box-sizing:border-box;overflow:hidden;display:flex;flex-direction:column;
               align-items:center;justify-content:flex-end; }
.rect-overlay:hover { cursor:grab; }
.rect-overlay.moviendo { cursor:grabbing !important; }
.rect-preview-text { font-size:7.5px;color:rgba(255,255,255,0.95);font-weight:700;
                     text-align:center;pointer-events:none;line-height:1;
                     padding:1px 2px 2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;
                     width:100%;text-shadow:0 1px 3px rgba(0,0,0,.8); }
.rect-label   { position:absolute;top:-1.2em;left:0;font-size:0.55rem;font-weight:700;
                color:#fff;padding:0 3px;border-radius:2px;white-space:nowrap; }
/* Rectángulo de preview mientras arrastra */
#rectPreview  { position:absolute;border:2px dashed #facc15;pointer-events:none;display:none;box-sizing:border-box; }

/* ── Config del campo ────────────────────────────────── */
.campo-config { background:#1e293b;padding:0.55rem 0.8rem;border-top:1px solid #334155;
                display:none;gap:0.5rem;align-items:center;flex-wrap:wrap;flex-shrink:0; }
.campo-config.visible { display:flex; }
.campo-config label { font-size:0.68rem;color:#94a3b8; }
.campo-config input[type="number"] { width:58px;background:#0f172a;border:1px solid #334155;
                                     color:#e2e8f0;border-radius:5px;padding:0.18rem 0.35rem;font-size:0.7rem; }
.campo-config select { background:#0f172a;border:1px solid #334155;color:#e2e8f0;
                       border-radius:5px;padding:0.18rem 0.35rem;font-size:0.7rem; }
.campo-config input[type="text"] { background:#0f172a;border:1px solid #334155;color:#e2e8f0;
                                   border-radius:5px;padding:0.18rem 0.35rem;font-size:0.7rem;min-width:110px; }
.btn-del-campo { background:#7f1d1d;color:#fca5a5;border:none;border-radius:6px;
                 padding:0.22rem 0.55rem;font-size:0.68rem;cursor:pointer; }

/* ── Botón guardar ───────────────────────────────────── */
.btn-guardar { width:100%;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;
               border:none;border-radius:8px;padding:0.55rem;font-size:0.8rem;font-weight:700;cursor:pointer; }
.btn-guardar:hover { filter:brightness(1.1); }

/* ── Toast ──────────────────────────────────────────── */
.toast { position:fixed;bottom:1.5rem;right:1.5rem;background:#15803d;color:#fff;
         padding:0.65rem 1rem;border-radius:10px;font-weight:700;font-size:0.82rem;
         box-shadow:0 4px 20px rgba(0,0,0,.25);transform:translateY(80px);opacity:0;
         transition:all .3s;z-index:9999; }
.toast.show { transform:translateY(0);opacity:1; }
</style>

<div class="fmap-wrap">

{{-- ══ PANEL IZQUIERDO ══ --}}
<div class="fmap-panel">
    <div class="fmap-title">🗺️ {{ $titulo }}</div>
    <div class="fmap-sub">Selecciona {{ $subtitulo }}, elige un campo y arrastra en el PDF para definir el cuadro donde se escribirá el dato.</div>

    <select class="eps-select" id="selectorEps" onchange="cambiarEntidad()">
        <optgroup label="✅ Con formulario PDF">
            @foreach($lista->where('formulario_pdf','!=',null)->sortBy('nombre') as $e)
            <option value="{{ $e->id }}" {{ $e->id == $entidad->id ? 'selected' : '' }}
                style="color:#86efac;">
                ✅ {{ $e->nombre }}
            </option>
            @endforeach
        </optgroup>
        <optgroup label="⬜ Sin formulario">
            @foreach($lista->whereNull('formulario_pdf')->sortBy('nombre') as $e)
            <option value="{{ $e->id }}" {{ $e->id == $entidad->id ? 'selected' : '' }}
                style="color:#94a3b8;">
                {{ $e->nombre }}
            </option>
            @endforeach
        </optgroup>
    </select>

    <form id="formPdf" action="{{ $urlSubirPdf }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="upload-zone" onclick="document.getElementById('inputPdf').click()">
            📁 {{ $entidad->formulario_pdf ? 'Cambiar PDF' : 'Subir PDF del formulario' }}
            <input type="file" id="inputPdf" name="pdf" accept=".pdf" onchange="this.form.submit()">
        </div>
        @if(session('success'))
        <div style="font-size:0.68rem;color:#86efac;margin-top:0.25rem;text-align:center;">✅ {{ session('success') }}</div>
        @endif
    </form>

    <hr style="border-color:#1e3a5f;margin:0.1rem 0;">
    <div style="font-size:0.62rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
        Campos — {{ count($mapeados) }} / {{ count($campos) }} mapeados
    </div>

    <div class="campo-list" id="listaCampos">
        @foreach($campos as $clave => $etiqueta)
        @php $mapeado = collect($mapeados)->firstWhere('dato', $clave); @endphp
        <div class="campo-item {{ $mapeado ? 'mapeado' : '' }}"
             data-clave="{{ $clave }}" data-label="{{ $etiqueta }}"
             onclick="seleccionarCampo('{{ $clave }}', '{{ addslashes($etiqueta) }}')"
             id="ci-{{ str_replace('.', '-', $clave) }}">
            <div class="campo-dot"></div>
            <span>{{ $etiqueta }}</span>
        </div>
        @endforeach
    </div>

    <button class="btn-add-x" onclick="agregarX()" id="btnAgregarX">✖ Agregar X al formulario</button>
    <button class="btn-add-x" onclick="agregarFirmaExtra()"
        style="background:linear-gradient(135deg,#1e3a8a,#2563eb);margin-top:2px;">
        ✍️ Agregar firma adicional
    </button>
    <button class="btn-add-rep" onclick="agregarInstanciaExtra()" id="btnRepetirCampo" style="margin-top:2px;">
        🔁 Repetir campo seleccionado
    </button>
    <button class="btn-add-custom" onclick="agregarCampoCustom()" style="margin-top:2px;">
        📝 Agregar texto editable
    </button>

    <button class="btn-guardar" onclick="guardarMapeo()" style="margin-top:auto">💾 Guardar mapeo</button>
    <button class="btn-guardar" onclick="limpiarObsoletos()"
        style="background:linear-gradient(135deg,#7f1d1d,#991b1b);margin-top:0.25rem;">
        🧹 Limpiar campos obsoletos ({{ collect($mapeados)->filter(fn($m) => !array_key_exists($m['dato'] ?? '', $campos))->count() }})
    </button>
</div>

{{-- ══ VISOR PDF ══ --}}
<div class="fmap-viewer">
    <div class="fmap-toolbar">
        <button onclick="cambiarPagina(-1)">◀</button>
        <span class="page-info" id="pageInfo">— / —</span>
        <button onclick="cambiarPagina(1)">▶</button>
        <button onclick="cambiarZoom(-0.15)">–</button>
        <span id="zoomInfo" style="font-size:0.7rem;color:#94a3b8;">100%</span>
        <button onclick="cambiarZoom(0.15)">+</button>
        <span id="statusCampo" class="fmap-status">
            @if($entidad->formulario_pdf) 👆 Selecciona un campo y arrastra en el PDF
            @else ⚠️ Sube el PDF primero
            @endif
        </span>
    </div>

    <div class="canvas-wrap" id="canvasWrap">
        <canvas id="pdfCanvas"></canvas>
        <div id="pinLayer" style="position:absolute;top:0;left:0;pointer-events:none;"></div>
        <div id="rectPreview"></div>
    </div>

    <div class="campo-config" id="campoConfig">
        <label>Tamaño</label>
        <input type="number" id="cfgFontSize" value="9" min="5" max="18" step="0.5">
        <label>Estilo</label>
        <select id="cfgStyle">
            <option value="">Normal</option>
            <option value="B">Negrita</option>
            <option value="I">Itálica</option>
        </select>
        <label>Alineación</label>
        <select id="cfgAlign">
            <option value="L">Izq.</option>
            <option value="C">Centro</option>
            <option value="R">Der.</option>
        </select>
        {{-- Label editable para campos custom --}}
        <span id="cfgLabelWrap" style="display:none;align-items:center;gap:0.3rem;">
            <label>Etiqueta</label>
            <input type="text" id="cfgLabel" placeholder="Nombre del campo" oninput="actualizarLabelCustom()">
        </span>
        <button class="btn-del-campo" onclick="eliminarCampoActivo()">🗑 Quitar campo</button>
    </div>
</div>

</div>{{-- fin fmap-wrap --}}

<form id="formMapeo" method="POST" action="{{ $urlGuardar }}">
    @csrf
    <input type="hidden" name="formulario_campos" id="inputMapeoJson">
</form>

<div class="toast" id="toast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

// ── Estado ───────────────────────────────────────────────────
let pdfDoc      = null;
let pageNum     = 1;
let zoom        = 1.3;
let campoActivo = null;
let mapeo       = {!! json_encode($mapeados ?: []) !!};

const camposKeys = @json(array_keys($campos));
const COLORES = ['#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#3b82f6',
                 '#8b5cf6','#ec4899','#06b6d4','#84cc16','#10b981','#6366f1',
                 '#e11d48','#0ea5e9','#65a30d','#7c3aed','#be185d','#0369a1',
                 '#15803d','#c2410c','#1d4ed8','#6d28d9','#9f1239','#047857',
                 '#92400e','#1e3a8a','#0c4a6e'];

// ── Textos de muestra por campo ─────────────────────────────
const PREVIEWS = {
    'cliente.primer_apellido'        : 'GARCÍA',
    'cliente.segundo_apellido'       : 'LÓPEZ',
    'cliente.primer_nombre'          : 'CARLOS',
    'cliente.segundo_nombre'         : 'ANDRÉS',
    'cliente.tipo_doc'               : 'CC',
    'cliente.cedula'                 : '1234567890',
    'cliente.genero'                 : 'M',
    'cliente.genero_m'               : 'X',
    'cliente.genero_f'               : '',
    'cliente.firma'                  : '✍ firma',
    'cliente.fecha_nacimiento'       : '05/02/1990',
    'cliente.fecha_nacimiento_d_esp' : '0 5',
    'cliente.fecha_nacimiento_m_esp' : '0 2',
    'cliente.fecha_nacimiento_a_esp' : '1 9 9 0',
    'cliente.fecha_nacimiento_d1'    : '0',
    'cliente.fecha_nacimiento_d2'    : '5',
    'cliente.fecha_nacimiento_m1'    : '0',
    'cliente.fecha_nacimiento_m2'    : '2',
    'cliente.fecha_nacimiento_a1'    : '1',
    'cliente.fecha_nacimiento_a2'    : '9',
    'cliente.fecha_nacimiento_a3'    : '9',
    'cliente.fecha_nacimiento_a4'    : '0',
    'cliente.direccion'              : 'CRA 10 # 25-30',
    'cliente.telefono'               : '6023456789',
    'cliente.celular'                : '3001234567',
    'cliente.correo'                 : 'cliente@email.com',
    'cliente.departamento'           : 'VALLE DEL CAUCA',
    'cliente.municipio'              : 'CALI',
    'cliente.barrio'                 : 'SAN FERNANDO',
    'cliente.sisben'                 : 'A1',
    'cliente.ips'                    : 'IPS SALUD TOTAL',
    'cliente.ocupacion'              : 'OPERARIO',
    'static.COLOMBIANA'              : 'COLOMBIANA',
    'tramite.afiliacion_x'           : 'X',
    'tramite.novedad_x'              : 'X',
    'novedad.inicio_laboral_x'       : 'X',
    'arl.nombre'                     : 'SURA ARL',
    'pension.nombre'                 : 'PORVENIR',
    'caja.nombre'                    : 'COMFABOY',
    'contrato.salario'               : '1.300.000',
    'empresa.razon_social'           : 'EMPRESA S.A.S.',
    'empresa.tipo_doc'               : 'NIT',
    'empresa.nit'                    : '900123456',
    'empresa.nit_dv'                 : '900123456-1',
    'empresa.direccion'              : 'CLL 15 # 5-20',
    'empresa.telefono'               : '6024567890',
    'empresa.correo'                 : 'empresa@mail.com',
    'empresa.departamento'           : 'VALLE DEL CAUCA',
    'empresa.municipio'              : 'CALI',
    'empresa.sello'                  : '🖼 sello',
    'contrato.cargo'                 : 'AUXILIAR ADMIN.',
    'contrato.fecha_ingreso'         : '01/03/2026',
    'contrato.fecha_ingreso_d_esp'   : '0 1',
    'contrato.fecha_ingreso_m_esp'   : '0 3',
    'contrato.fecha_ingreso_a_esp'   : '2 0 2 6',
    'contrato.fecha_ingreso_d1'      : '0',
    'contrato.fecha_ingreso_d2'      : '1',
    'contrato.fecha_ingreso_m1'      : '0',
    'contrato.fecha_ingreso_m2'      : '3',
    'contrato.fecha_ingreso_a1'      : '2',
    'contrato.fecha_ingreso_a2'      : '0',
    'contrato.fecha_ingreso_a3'      : '2',
    'contrato.fecha_ingreso_a4'      : '6',
};
function previewDe(clave) {
    if (clave.startsWith('static.X_'))     return 'X';
    if (clave.startsWith('cliente.firma_')) return '✍ firma';
    // Campos repetidos: obtener preview del campo base
    const matchRep = clave.match(/^(.+)__\d+$/);
    if (matchRep) return PREVIEWS[matchRep[1]] ?? matchRep[1];
    // Campos custom: mostrar etiqueta o placeholder
    if (clave.startsWith('custom.')) {
        const m = mapeo.find(r => r.dato === clave);
        return m?.label ? `[${m.label}]` : `[${clave.replace('custom.','')}]`;
    }
    return PREVIEWS[clave] ?? '';
}

function colorDe(clave) {
    // Para instancias extra, usar el color del campo base
    const matchRep = clave.match(/^(.+)__\d+$/);
    const claveBase = matchRep ? matchRep[1] : clave;
    // Campos custom: color fijo violeta
    if (clave.startsWith('custom.')) return '#6366f1';
    const idx = camposKeys.indexOf(claveBase);
    return COLORES[idx % COLORES.length] ?? '#64748b';
}

// ── Cargar PDF ───────────────────────────────────────────────
@if($entidad->formulario_pdf)
cargarPdf('{{ $urlVerPdf }}');
@endif

function cargarPdf(url) {
    pdfjsLib.getDocument(url).promise.then(doc => {
        pdfDoc = doc;
        pageNum = 1;
        renderizarPagina();
    }).catch(err => {
        document.getElementById('statusCampo').textContent = '❌ Error PDF: ' + err.message;
    });
}

function renderizarPagina() {
    if (!pdfDoc) return;
    pdfDoc.getPage(pageNum).then(page => {
        const vp     = page.getViewport({ scale: zoom });
        const canvas = document.getElementById('pdfCanvas');
        const ctx    = canvas.getContext('2d');
        canvas.width  = vp.width;
        canvas.height = vp.height;

        const layer = document.getElementById('pinLayer');
        layer.style.width  = vp.width  + 'px';
        layer.style.height = vp.height + 'px';

        page.render({ canvasContext: ctx, viewport: vp }).promise.then(() => {
            document.getElementById('pageInfo').textContent = `${pageNum} / ${pdfDoc.numPages}`;
            document.getElementById('zoomInfo').textContent = Math.round(zoom * 100 / 1.3) + '%';
            dibujarRectangulos();
        });
    });
}

function cambiarPagina(d) {
    if (!pdfDoc) return;
    const n = pageNum + d;
    if (n < 1 || n > pdfDoc.numPages) return;
    pageNum = n;
    renderizarPagina();
}
function cambiarZoom(d) {
    zoom = Math.max(0.4, Math.min(3.5, zoom + d));
    renderizarPagina();
}

// ── Drag-to-draw / drag-to-move ─────────────────────────────
let drag = { active: false, startX: 0, startY: 0, mode: 'draw', movingDato: null, offX: 0, offY: 0 };

const canvasWrap = document.getElementById('canvasWrap');
const preview    = document.getElementById('rectPreview');

/** Detecta si (px, py) está dentro de un rectángulo del mapeo en la página actual */
function rectEnPunto(px, py) {
    return mapeo.find(m =>
        m.pagina === pageNum &&
        px >= m.x * zoom && px <= (m.x + m.width)  * zoom &&
        py >= m.y * zoom && py <= (m.y + m.height) * zoom
    ) ?? null;
}

// Cursor: detectar si el mouse está sobre un rect para mostrar manito
canvasWrap.addEventListener('mousemove', e => {
    const wr = canvasWrap.getBoundingClientRect();
    const mx = e.clientX - wr.left + canvasWrap.scrollLeft;
    const my = e.clientY - wr.top  + canvasWrap.scrollTop;

    if (drag.active) {
        if (drag.mode === 'draw') {
            const x = Math.min(drag.startX, mx), y = Math.min(drag.startY, my);
            preview.style.left   = x + 'px'; preview.style.top    = y + 'px';
            preview.style.width  = Math.abs(mx - drag.startX) + 'px';
            preview.style.height = Math.abs(my - drag.startY) + 'px';
        } else {
            // mover rect
            const m = mapeo.find(r => r.dato === drag.movingDato);
            if (m) {
                m.x = Math.max(0, Math.round(((mx - drag.offX) / zoom) * 10) / 10);
                m.y = Math.max(0, Math.round(((my - drag.offY) / zoom) * 10) / 10);
                dibujarRectangulos();
            }
        }
        return;
    }
    // Sin arrastre: cambiar cursor si hay rect debajo
    const bajo = rectEnPunto(mx, my);
    canvasWrap.style.cursor = bajo ? 'grab' : (campoActivo ? 'crosshair' : 'default');
});

canvasWrap.addEventListener('mousedown', e => {
    if (!pdfDoc) return;
    e.preventDefault();
    const wr = canvasWrap.getBoundingClientRect();
    const mx = e.clientX - wr.left + canvasWrap.scrollLeft;
    const my = e.clientY - wr.top  + canvasWrap.scrollTop;

    const bajo = rectEnPunto(mx, my);
    if (bajo) {
        // ── modo mover ──
        drag.active      = true;
        drag.mode        = 'move';
        drag.movingDato  = bajo.dato;
        drag.offX        = mx - bajo.x * zoom;
        drag.offY        = my - bajo.y * zoom;
        canvasWrap.style.cursor = 'grabbing';
        // También seleccionar el campo
        seleccionarCampo(bajo.dato, previewDe(bajo.dato));
        return;
    }
    if (!campoActivo) return;
    // ── modo dibujar ──
    drag.active = true;
    drag.mode   = 'draw';
    drag.startX = mx; drag.startY = my;
    preview.style.left = mx + 'px'; preview.style.top = my + 'px';
    preview.style.width = '0'; preview.style.height = '0';
    preview.style.display = 'block';
});

canvasWrap.addEventListener('mouseup', e => {
    if (!drag.active) return;
    drag.active = false;
    canvasWrap.style.cursor = campoActivo ? 'crosshair' : 'default';

    if (drag.mode === 'move') {
        // Guardar nueva posición (ya se actualizó en mousemove)
        renderizarPanelX(); renderizarPanelFirmas(); renderizarPanelRepetidos(); renderizarPanelCustom();
        return;
    }

    // ── fin de dibujo ──
    preview.style.display = 'none';
    const wr   = canvasWrap.getBoundingClientRect();
    const curX = e.clientX - wr.left + canvasWrap.scrollLeft;
    const curY = e.clientY - wr.top  + canvasWrap.scrollTop;
    const pxX  = Math.min(drag.startX, curX);
    const pxY  = Math.min(drag.startY, curY);
    const pxW  = Math.abs(curX - drag.startX);
    const pxH  = Math.abs(curY - drag.startY);
    if (pxW < 5 || pxH < 5) return;

    const ptX = Math.round((pxX / zoom) * 10) / 10;
    const ptY = Math.round((pxY / zoom) * 10) / 10;
    const ptW = Math.round((pxW / zoom) * 10) / 10;
    const ptH = Math.round((pxH / zoom) * 10) / 10;

    const esImagen = (campoActivo === 'empresa.sello' || campoActivo === 'cliente.firma'
                    || campoActivo.startsWith('cliente.firma_'));

    const obj = {
        dato      : campoActivo,
        pagina    : pageNum,
        x         : ptX, y : ptY, width : ptW, height : ptH,
        font_size : parseFloat(document.getElementById('cfgFontSize').value) || 9,
        style     : document.getElementById('cfgStyle').value,
        align     : document.getElementById('cfgAlign').value,
        tipo      : esImagen ? 'imagen' : 'texto',
    };

    // Para campos custom, guardar también el label
    if (campoActivo.startsWith('custom.')) {
        const labelInput = document.getElementById('cfgLabel');
        obj.label = labelInput?.value?.trim() || campoActivo.replace('custom.', '');
    }

    // Todos los tipos de campo usan findIndex por su clave exacta.
    // - Campos normales (cliente.cedula): solo 1 instancia, se reemplaza.
    // - Campos con clave única (__N, custom.*, X_N, firma_N): se reemplaza si ya existe, se agrega si es nuevo.
    const idx = mapeo.findIndex(m => m.dato === campoActivo);
    if (idx >= 0) mapeo[idx] = obj;  // Actualizar posición existente
    else          mapeo.push(obj);   // Primera vez que se pone este campo

    marcarMapeado(campoActivo);
    dibujarRectangulos();
    actualizarContador();
    renderizarPanelX();
    renderizarPanelFirmas();
    renderizarPanelRepetidos();
    renderizarPanelCustom();
});

// Cancelar drag si sale del área
canvasWrap.addEventListener('mouseleave', () => {
    if (drag.active) {
        drag.active = false;
        preview.style.display = 'none';
        canvasWrap.style.cursor = 'default';
        if (drag.mode === 'move') { renderizarPanelX(); renderizarPanelFirmas(); renderizarPanelRepetidos(); renderizarPanelCustom(); }
    }
});

// ── Selección de campo ───────────────────────────────────────
function seleccionarCampo(clave, etiqueta) {
    document.querySelectorAll('.campo-item').forEach(el => el.classList.remove('activo'));
    if (campoActivo === clave) {
        campoActivo = null;
        document.getElementById('statusCampo').textContent = '👆 Selecciona un campo y arrastra en el PDF';
        document.getElementById('campoConfig').classList.remove('visible');
        return;
    }
    campoActivo = clave;
    // IDs de items dinámicos usan guiones en lugar de puntos y underscores
    const elId = 'ci-' + clave.replace(/\./g, '-').replace(/_/g, '-').replace(/__/g, '--');
    const elActivo = document.getElementById(elId);
    if (elActivo) elActivo.classList.add('activo');

    const esImagen = (clave === 'empresa.sello' || clave === 'cliente.firma'
                    || clave.startsWith('cliente.firma_'));
    // Ocultar/mostrar controles de fuente para campos imagen
    document.querySelectorAll('#campoConfig label:not(:last-of-type), #cfgFontSize, #cfgStyle, #cfgAlign')
        .forEach(el => el.style.display = esImagen ? 'none' : '');

    // Mostrar/ocultar input de etiqueta para campos custom
    const cfgLabelWrap = document.getElementById('cfgLabelWrap');
    if (cfgLabelWrap) {
        const esCustom = clave.startsWith('custom.');
        cfgLabelWrap.style.display = esCustom ? 'inline-flex' : 'none';
        if (esCustom) {
            const existente = mapeo.find(m => m.dato === clave);
            document.getElementById('cfgLabel').value = existente?.label ?? clave.replace('custom.', '');
        }
    }

    document.getElementById('statusCampo').textContent = esImagen
        ? `🖼️ Arrastrando zona de imagen: ${etiqueta}`
        : `✏️ Arrastrando: ${etiqueta}`;
    document.getElementById('campoConfig').classList.add('visible');

    const existente = mapeo.find(m => m.dato === clave);
    if (existente) {
        document.getElementById('cfgFontSize').value = existente.font_size ?? 9;
        document.getElementById('cfgStyle').value    = existente.style ?? '';
        document.getElementById('cfgAlign').value    = existente.align ?? 'L';
        if (existente.pagina !== pageNum) { pageNum = existente.pagina; renderizarPagina(); }
    }
}

function actualizarLabelCustom() {
    if (!campoActivo || !campoActivo.startsWith('custom.')) return;
    const m = mapeo.find(r => r.dato === campoActivo);
    if (m) {
        m.label = document.getElementById('cfgLabel').value.trim();
        dibujarRectangulos();
        renderizarPanelCustom();
    }
}

function eliminarCampoActivo() {
    if (!campoActivo) return;
    mapeo = mapeo.filter(m => m.dato !== campoActivo);
    const el = document.getElementById('ci-' + campoActivo.replace(/\./g, '-').replace(/_/g, '-').replace(/__/g, '--'));
    if (el) el.classList.remove('mapeado');
    // Actualizar paneles dinámicos
    if (campoActivo.startsWith('static.X_'))       renderizarPanelX();
    if (campoActivo.startsWith('cliente.firma_'))   renderizarPanelFirmas();
    if (campoActivo.match(/^(.+)__\d+$/))           renderizarPanelRepetidos();
    if (campoActivo.startsWith('custom.'))          renderizarPanelCustom();
    campoActivo = null;
    document.getElementById('campoConfig').classList.remove('visible');
    document.getElementById('statusCampo').textContent = '👆 Selecciona un campo y arrastra en el PDF';
    dibujarRectangulos();
    actualizarContador();
}

function marcarMapeado(clave) {
    const el = document.getElementById('ci-' + clave.replace('.', '-'));
    if (el) el.classList.add('mapeado');
}

// ── Dibujar rectángulos en el PDF ────────────────────────────
function dibujarRectangulos() {
    const layer = document.getElementById('pinLayer');
    layer.innerHTML = '';

    mapeo.filter(m => m.pagina === pageNum).forEach(m => {
        const esRepetido = m.dato.match(/^(.+)__\d+$/);
        const esCustom   = m.dato.startsWith('custom.');
        const esObsoleto = !camposKeys.includes(m.dato)
                         && !m.dato.startsWith('static.X_')
                         && !m.dato.startsWith('cliente.firma_')
                         && !esRepetido
                         && !esCustom;
        const color  = esObsoleto ? '#ef4444' : colorDe(m.dato);
        let label;
        if (esObsoleto)        label = `⚠️ OBSOLETO: ${m.dato}`;
        else if (esCustom)     label = `📝 ${m.label ?? m.dato}`;
        else if (esRepetido)   label = (@json($campos)[esRepetido[1]] ?? esRepetido[1]) + ' (copia)';
        else                   label = @json($campos)[m.dato] ?? m.dato;
        const sample = previewDe(m.dato);

        const div = document.createElement('div');
        div.className = 'rect-overlay';
        div.dataset.dato = m.dato;
        div.style.left        = (m.x * zoom) + 'px';
        div.style.top         = (m.y * zoom) + 'px';
        div.style.width       = (m.width  * zoom) + 'px';
        div.style.height      = (m.height * zoom) + 'px';
        div.style.borderColor = color;
        div.style.background  = color + (esObsoleto ? '44' : '33');
        if (esObsoleto) div.style.borderStyle = 'dashed';

        // Etiqueta (nombre del campo)
        const lbl = document.createElement('div');
        lbl.className   = 'rect-label';
        lbl.textContent = label.length > 28 ? label.slice(0, 28) + '…' : label;
        lbl.style.background = color;
        div.appendChild(lbl);

        // Texto de muestra centrado dentro del rect
        if (sample) {
            const prev = document.createElement('div');
            prev.className   = 'rect-preview-text';
            prev.textContent = sample;
            // Escalar fuente según alto del rect para que quepa
            const pxH = m.height * zoom;
            prev.style.fontSize = Math.min(Math.max(pxH * 0.55, 7), 13) + 'px';
            div.appendChild(prev);
        }

        layer.appendChild(div);
    });
}

// ── Guardar ──────────────────────────────────────────────────
function guardarMapeo() {
    document.getElementById('inputMapeoJson').value = JSON.stringify(mapeo);
    document.getElementById('formMapeo').submit();
}

// ── Cambiar de entidad (EPS o fondo de pensión) ──────────────
function cambiarEntidad() {
    const id = document.getElementById('selectorEps').value;
    window.location = `{{ $urlBase }}/${id}/formulario`;
}

// ── Contador ─────────────────────────────────────────────────
function actualizarContador() {
    const total  = {{ count($campos) }};
    const header = document.querySelector('[style*="font-size:0.62rem"]');
    if (header) header.textContent = `Campos — ${mapeo.length} / ${total} mapeados`;
}

// Inicializar mapeados
mapeo.forEach(m => marcarMapeado(m.dato));

// Contar obsoletos al cargar (excluir X estáticas, claves __N y custom que son válidas)
const obsoletos = mapeo.filter(m =>
    !camposKeys.includes(m.dato) &&
    !m.dato.startsWith('static.X_') &&
    !m.dato.startsWith('cliente.firma_') &&
    !m.dato.match(/^(.+)__\d+$/) &&
    !m.dato.startsWith('custom.')
);
if (obsoletos.length > 0) {
    document.getElementById('statusCampo').textContent =
        `⚠️ ${obsoletos.length} campo(s) obsoleto(s) en el mapeo — usa 🧹 Limpiar`;
}

// Renderizar elementos dinámicos existentes
renderizarPanelX();
renderizarPanelFirmas();
renderizarPanelRepetidos();
renderizarPanelCustom();

// ── Funciones para X estáticas ───────────────────────────────
function agregarX() {
    if (!pdfDoc) { alert('Sube el PDF primero.'); return; }
    const existentesX = mapeo.filter(m => m.dato.startsWith('static.X_'));
    const nums = existentesX.map(m => parseInt(m.dato.split('_')[1])).filter(n => !isNaN(n));
    const siguiente = nums.length ? Math.max(...nums) + 1 : 1;
    const clave = `static.X_${siguiente}`;
    seleccionarCampo(clave, `✖ X #${siguiente}`);
    document.getElementById('statusCampo').textContent = `✖ Arrastra en el PDF para colocar X #${siguiente}`;
}

function agregarFirmaExtra() {
    if (!pdfDoc) { alert('Sube el PDF primero.'); return; }
    const existentes = mapeo.filter(m => m.dato.startsWith('cliente.firma_'));
    const nums = existentes.map(m => parseInt(m.dato.split('_').pop())).filter(n => !isNaN(n));
    const siguiente = nums.length ? Math.max(...nums) + 1 : 2;
    const clave = `cliente.firma_${siguiente}`;
    seleccionarCampo(clave, `✍️ Firma #${siguiente}`);
    document.getElementById('statusCampo').textContent = `✍️ Arrastra en el PDF para colocar Firma #${siguiente}`;
}

function agregarInstanciaExtra() {
    if (!campoActivo || campoActivo.startsWith('static.') || campoActivo.startsWith('custom.')) {
        alert('Selecciona un campo normal para repetir.'); return;
    }
    const baseClave = campoActivo.split('__')[0];
    const existentes = mapeo.filter(m => m.dato.startsWith(baseClave + '__'));
    const nums = existentes.map(m => parseInt(m.dato.split('__').pop())).filter(n => !isNaN(n));
    const siguiente = nums.length ? Math.max(...nums) + 1 : 1;
    const clave = `${baseClave}__${siguiente}`;
    seleccionarCampo(clave, `🔁 ${baseClave} copia ${siguiente}`);
}

function agregarCampoCustom() {
    if (!pdfDoc) { alert('Sube el PDF primero.'); return; }
    const existentes = mapeo.filter(m => m.dato.startsWith('custom.'));
    const nums = existentes.map(m => parseInt(m.dato.split('.').pop())).filter(n => !isNaN(n));
    const siguiente = nums.length ? Math.max(...nums) + 1 : 1;
    const clave = `custom.${siguiente}`;
    seleccionarCampo(clave, `📝 Campo ${siguiente}`);
}

function renderizarPanelX() {
    document.querySelectorAll('.campo-item-x-dinamico').forEach(el => el.remove());
    const lista = document.getElementById('listaCampos');
    mapeo.filter(m => m.dato.startsWith('static.X_')).forEach(m => {
        const num = m.dato.split('_')[1];
        const div = document.createElement('div');
        div.className = `campo-item es-x campo-item-x-dinamico ${campoActivo === m.dato ? 'activo' : ''}`;
        div.dataset.clave = m.dato;
        div.id = 'ci-' + m.dato.replace(/\./g, '-').replace(/_/g, '-');
        div.innerHTML = `<div class="campo-dot"></div><span>✖ X #${num} (pág. ${m.pagina})</span>`;
        div.onclick = () => seleccionarCampo(m.dato, `✖ X #${num}`);
        lista.appendChild(div);
    });
}

function renderizarPanelFirmas() {
    document.querySelectorAll('.campo-item-firma-dinamico').forEach(el => el.remove());
    const lista = document.getElementById('listaCampos');
    mapeo.filter(m => m.dato.startsWith('cliente.firma_')).forEach(m => {
        const num = m.dato.split('_').pop();
        const div = document.createElement('div');
        div.className = `campo-item es-x campo-item-firma-dinamico ${campoActivo === m.dato ? 'activo' : ''}`;
        div.style.borderColor = '#2563eb'; div.style.color = '#93c5fd';
        div.dataset.clave = m.dato;
        div.id = 'ci-' + m.dato.replace(/\./g, '-').replace(/_/g, '-');
        div.innerHTML = `<div class="campo-dot"></div><span>✍️ Firma #${num} (pág. ${m.pagina})</span>`;
        div.onclick = () => seleccionarCampo(m.dato, `✍️ Firma #${num}`);
        lista.appendChild(div);
    });
}

// ── Panel de campos repetidos (__N) ────────────────────────────────
function renderizarPanelRepetidos() {
    document.querySelectorAll('.campo-item-rep-dinamico').forEach(el => el.remove());
    const lista = document.getElementById('listaCampos');
    mapeo.filter(m => m.dato.match(/^(.+)__\d+$/)).forEach(m => {
        const match    = m.dato.match(/^(.+)__(\d+)$/);
        const claveBase = match[1];
        const num      = match[2];
        const labelBase = @json($campos)[claveBase] ?? claveBase;
        const div = document.createElement('div');
        div.className = `campo-item es-rep campo-item-rep-dinamico ${campoActivo === m.dato ? 'activo' : ''}`;
        div.dataset.clave = m.dato;
        div.id = 'ci-' + m.dato.replace(/\./g, '-').replace(/__/g, '--').replace(/_/g, '-');
        div.innerHTML = `<div class="campo-dot"></div><span>🔁 ${labelBase} (copia ${num}, pág. ${m.pagina})</span>`;
        div.onclick = () => seleccionarCampo(m.dato, `${labelBase} copia ${num}`);
        lista.appendChild(div);
    });
}

// ── Panel de campos custom (texto libre) ───────────────────────────
function renderizarPanelCustom() {
    document.querySelectorAll('.campo-item-custom-dinamico').forEach(el => el.remove());
    const lista = document.getElementById('listaCampos');
    mapeo.filter(m => m.dato.startsWith('custom.')).forEach(m => {
        const etiqueta = m.label || m.dato.replace('custom.', 'Campo ');
        const div = document.createElement('div');
        div.className = `campo-item es-custom campo-item-custom-dinamico ${campoActivo === m.dato ? 'activo' : ''}`;
        div.dataset.clave = m.dato;
        div.id = 'ci-' + m.dato.replace(/\./g, '-').replace(/_/g, '-');
        div.innerHTML = `<div class="campo-dot"></div><span>📝 ${etiqueta} (pág. ${m.pagina})</span>`;
        div.onclick = () => seleccionarCampo(m.dato, etiqueta);
        lista.appendChild(div);
    });
}

// Limpiar campos que ya no existen en la lista (conservar X estáticas, __N y custom.*)
function limpiarObsoletos() {
    const antes = mapeo.length;
    mapeo = mapeo.filter(m =>
        camposKeys.includes(m.dato) ||
        m.dato.startsWith('static.X_') ||
        m.dato.startsWith('cliente.firma_') ||
        m.dato.match(/^(.+)__\d+$/) ||
        m.dato.startsWith('custom.')
    );
    const eliminados = antes - mapeo.length;
    document.querySelectorAll('.campo-item.mapeado').forEach(el => {
        const clave = el.dataset.clave;
        if (!mapeo.find(m => m.dato === clave)) el.classList.remove('mapeado');
    });
    renderizarPanelX();
    renderizarPanelFirmas();
    renderizarPanelRepetidos();
    renderizarPanelCustom();
    dibujarRectangulos();
    actualizarContador();
    // Guardar inmediatamente
    document.getElementById('inputMapeoJson').value = JSON.stringify(mapeo);
    document.getElementById('formMapeo').submit();
}
</script>
@endsection
