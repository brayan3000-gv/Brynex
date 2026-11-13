<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Formulario {{ $tituloEntidad }} — {{ $nombreCompleto }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #0f172a; height: 100vh; display: flex; flex-direction: column; }

        /* ── Barra superior ─────── */
        .top-bar {
            background: linear-gradient(135deg, #0f172a, #1e3a5f 60%, #1e40af);
            padding: 0.65rem 1.25rem;
            display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4);
            flex-wrap: wrap;
        }
        .top-bar .info { flex: 1; min-width: 0; }
        .top-bar h1 { font-size: 0.92rem; font-weight: 700; color: #e2e8f0; }
        .top-bar p  { font-size: 0.7rem; color: #64748b; margin-top: 1px; }

        .btn { display:inline-flex; align-items:center; gap:0.35rem;
            border:none; border-radius:8px; padding:0.48rem 1rem;
            font-size:0.8rem; font-weight:700; cursor:pointer; white-space:nowrap;
            transition:filter 0.15s; text-decoration:none; }
        .btn:hover { filter:brightness(1.12); }
        .btn-primary   { background:#2563eb; color:#fff; }
        .btn-success   { background:#16a34a; color:#fff; }
        .btn-secondary { background:rgba(255,255,255,0.08); color:#94a3b8;
            border:1px solid rgba(255,255,255,0.12); }
        .btn-secondary:hover { color:#e2e8f0; }
        .btn-warning   { background:#d97706; color:#fff; }

        /* ── Iframe PDF ─────────── */
        #pdfFrame { flex:1; width:100%; border:none; background:#1e293b; }

        /* ── Modal firma ─────────── */
        .modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75);
            z-index:1000; align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal {
            background:#0f172a; border:1px solid #1e3a5f; border-radius:16px;
            padding:1.5rem; width:min(520px, 96vw); display:flex; flex-direction:column; gap:1rem;
            box-shadow:0 20px 60px rgba(0,0,0,0.5);
        }
        .modal h2 { color:#e2e8f0; font-size:1rem; font-weight:700; }

        .tab-panel { display:none; }
        .tab-panel.activo { display:block; }

        .firma-aviso { font-size:0.78rem; color:#cbd5e1; line-height:1.45; margin:0 0 0.8rem; }
        .firma-aviso strong { color:#fbbf24; }

        /* Panel canvas */
        #canvasFirma {
            width:100%; height:160px; border-radius:8px; cursor:crosshair;
            background:#fff; touch-action:none;
        }
        .canvas-tools { display:flex; gap:0.5rem; align-items:center; margin-top:0.4rem; }
        .canvas-tools label { font-size:0.72rem; color:#64748b; }
        #colorFirma { width:32px; height:28px; padding:0; border:none; border-radius:4px; cursor:pointer; }
        #grosorFirma { width:80px; accent-color:#3b82f6; }

        /* Botones modal */
        .modal-footer { display:flex; gap:0.5rem; justify-content:flex-end; margin-top:0.5rem; }

        /* Indicador de firma guardada */
        .firma-guardada { font-size:0.72rem; color:#86efac; display:none; }
        .firma-guardada.ok { display:inline; }
        /* Campos de texto libre custom */
        .custom-campos { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; width:100%; padding:0.35rem 1.25rem 0.45rem; background:rgba(30,58,95,0.4); border-top:1px solid rgba(255,255,255,0.06); }
        .custom-campo-item { display:flex; align-items:center; gap:0.35rem; }
        .custom-campo-label { font-size:0.68rem; color:#64748b; white-space:nowrap; }
        .custom-campo-input {
            background:#0f172a; border:1px solid #334155; color:#e2e8f0;
            border-radius:6px; padding:0.28rem 0.6rem; font-size:0.78rem;
            min-width:120px; max-width:220px;
            transition:border-color 0.15s;
        }
        .custom-campo-input:focus { outline:none; border-color:#3b82f6; }
        .custom-campos-hint { font-size:0.63rem; color:#475569; margin-left:auto; white-space:nowrap; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="info">
        <h1>📋 {{ $tituloEntidad }} — {{ $nombreCompleto }}</h1>
        <p>{{ $empresa }} &nbsp;·&nbsp; Ingreso: {{ $fechaIngreso }} &nbsp;·&nbsp; Salario: {{ $salario }}</p>
    </div>

    @php $qNovedad = $novedad === null ? '' : 'novedad='.($novedad ? 1 : 0); @endphp
    @if($conBeneficiarios)
    <a href="{{ $urlVista }}{{ $qNovedad ? '?'.$qNovedad : '' }}"
       class="btn btn-secondary" target="_blank">👤 Sin beneficiarios</a>
    @else
    <a href="{{ $urlVista }}?beneficiarios=1{{ $qNovedad ? '&'.$qNovedad : '' }}"
       class="btn btn-secondary" target="_blank">👨‍👩‍👧 Con beneficiarios</a>
    @endif

    {{-- Sanitas: el ingreso va como novedad 9 (cambio de empleador); se puede pasar a afiliación --}}
    @if($novedad !== null)
    <a href="{{ $urlVista }}?{{ http_build_query(['beneficiarios' => $conBeneficiarios ? 1 : 0, 'novedad' => $novedad ? 0 : 1]) }}"
       class="btn btn-secondary"
       title="{{ $novedad ? 'Hoy sale como Reporte de novedades con la casilla 9 (inicio de relación laboral)' : 'Hoy sale como Afiliación' }}">
        {{ $novedad ? '🔁 Novedad 9 · pasar a afiliación' : '📄 Afiliación · pasar a novedad' }}
    </a>
    @endif

    {{-- Botón firmar --}}
    <button class="btn btn-warning" onclick="abrirModalFirma()" id="btnFirmar">
        <span id="btnFirmarTexto">{{ $tieneFirma ? '✍️ Firmar' : '✍️ Falta la firma' }}</span>
        <span class="firma-guardada {{ $tieneFirma ? 'ok' : '' }}" id="firmaOk">✅</span>
    </button>

    <button class="btn btn-primary" onclick="document.getElementById('pdfFrame').contentWindow.print()">
        🖨️ Imprimir / Guardar PDF
    </button>
</div>

{{-- ══ CAMPOS DE TEXTO LIBRE CUSTOM ══ --}}
@if($camposCustom->isNotEmpty())
<div class="custom-campos" id="customCamposBar">
    <span style="font-size:0.7rem;font-weight:700;color:#94a3b8;white-space:nowrap;">✏️ Datos adicionales:</span>
    @foreach($camposCustom as $cc)
    <div class="custom-campo-item">
        <label class="custom-campo-label" for="custom_{{ $cc['sufijo'] }}">{{ $cc['label'] }}</label>
        <input type="text"
               class="custom-campo-input"
               id="custom_{{ $cc['sufijo'] }}"
               data-sufijo="{{ $cc['sufijo'] }}"
               placeholder="{{ $cc['label'] }}"
               oninput="actualizarPdfConCustom()">
    </div>
    @endforeach
    <span class="custom-campos-hint">💡 Escribe y el PDF se actualiza automáticamente</span>
</div>
@endif

<iframe id="pdfFrame"
    src="{{ $urlRaw }}?beneficiarios={{ $conBeneficiarios ? 1 : 0 }}{{ $qNovedad ? '&'.$qNovedad : '' }}"
    title="Formulario {{ $tituloEntidad }}">
</iframe>

{{-- ══ MODAL FIRMA ══ --}}
<div class="modal-overlay" id="modalFirma">
<div class="modal">

    <h2>✍️ Firma del contratista</h2>

    {{-- Las EPS devuelven el formulario si la firma va en letra del PDF, así que
         aquí solo se dibuja: Comfenalco Valle lo exigió por escrito el 16-sep-2026. --}}
    <p class="firma-aviso">
        Dibuja la firma de <strong>{{ $nombreCompleto }}</strong> con el mouse o el dedo.
        Las EPS <strong>rechazan</strong> los formularios cuya firma es el nombre escrito con letra del PDF:
        tiene que ser a mano alzada.
    </p>

    {{-- Canvas de dibujo --}}
    <div class="tab-panel activo" id="tabCanvas">
        <canvas id="canvasFirma" width="600" height="200"></canvas>
        <div class="canvas-tools">
            <label>Color:</label>
            <input type="color" id="colorFirma" value="#1e293b">
            <label>Grosor:</label>
            <input type="range" id="grosorFirma" min="2" max="12" value="4" step="0.5">
            <button class="btn btn-secondary" style="padding:0.25rem 0.65rem;font-size:0.72rem;" onclick="limpiarCanvas()">🗑 Limpiar</button>
        </div>
    </div>

    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="cerrarModalFirma()">Cancelar</button>
        <button class="btn btn-success"   onclick="guardarFirma()">💾 Guardar y aplicar</button>
    </div>

</div>
</div>

<script>
const FIRMA_URL  = "{{ $urlFirma }}";
const PDF_BASE   = "{{ $urlRaw }}";
const BEN_PARAM  = {{ $conBeneficiarios ? 'true' : 'false' }};
const NOVEDAD_PARAM = @json($novedad === null ? null : ($novedad ? '1' : '0'));
const CSRF       = document.querySelector('meta[name="csrf-token"]').content;
const TIENE_FIRMA = {{ $tieneFirma ? 'true' : 'false' }};

// Construye la URL del iframe con beneficiarios + params custom
function buildPdfUrl() {
    const params = new URLSearchParams();
    params.set('beneficiarios', BEN_PARAM ? '1' : '0');
    if (NOVEDAD_PARAM !== null) params.set('novedad', NOVEDAD_PARAM);
    // Recopilar inputs custom
    document.querySelectorAll('.custom-campo-input').forEach(inp => {
        const val = inp.value.trim();
        if (val) params.set('custom[' + inp.dataset.sufijo + ']', val);
    });
    return PDF_BASE + '?' + params.toString();
}

// Timer para debounce (espera 600ms después de que el usuario deja de escribir)
let customTimer = null;
function actualizarPdfConCustom() {
    clearTimeout(customTimer);
    customTimer = setTimeout(() => {
        document.getElementById('pdfFrame').src = buildPdfUrl() + '&_t=' + Date.now();
    }, 600);
}

// URL fija para PDF_RAW (compatibilidad con guardarFirma)
const PDF_RAW = buildPdfUrl();

// ── Modal ────────────────────────────────────────────
function abrirModalFirma() {
    document.getElementById('modalFirma').classList.add('open');
    initCanvas();
}
function cerrarModalFirma() {
    document.getElementById('modalFirma').classList.remove('open');
}

// Sin firma guardada el formulario sale con el espacio en blanco: se pide de
// entrada en vez de dejar que alguien lo imprima así.
if (!TIENE_FIRMA) {
    window.addEventListener('DOMContentLoaded', abrirModalFirma);
}

// ── Guardar firma ─────────────────────────────────────
async function guardarFirma() {
    const dataUrl = capturarCanvas();
    if (!dataUrl) return;

    // Enviar como FormData para evitar límites de JSON
    const form = new FormData();
    form.append('_token', CSRF);
    form.append('firma', dataUrl);

    const res = await fetch(FIRMA_URL, { method: 'POST', body: form });
    const txt = await res.text();

    if (res.ok) {
        try {
            const j = JSON.parse(txt);
            if (j.ok) {
                document.getElementById('firmaOk').classList.add('ok');
                document.getElementById('btnFirmarTexto').textContent = '✍️ Firmar';
                cerrarModalFirma();
                // Recargar iframe con todos los params actuales
                document.getElementById('pdfFrame').src = buildPdfUrl() + '&_t=' + Date.now();
                return;
            }
        } catch(e) {}
    }
    console.error('Firma error:', txt);
    alert('Error al guardar firma:\n' + txt.slice(0, 300));
}

// ── Canvas dibujo ─────────────────────────────────────
let canvasInicializado = false;
let hayTrazo = false;
let dibujando = false;
let lastX = 0, lastY = 0;

function initCanvas() {
    if (canvasInicializado) return;
    canvasInicializado = true;
    const c   = document.getElementById('canvasFirma');
    const ctx = c.getContext('2d');
    // Sin relleno blanco = fondo transparente
    ctx.lineJoin = ctx.lineCap = 'round';

    const pos = (e) => {
        const r = c.getBoundingClientRect();
        const t = e.touches?.[0] ?? e;
        return [(t.clientX - r.left) * (c.width / r.width),
                (t.clientY - r.top)  * (c.height / r.height)];
    };

    const start = (e) => {
        dibujando = true;
        hayTrazo = true;
        [lastX, lastY] = pos(e);
    };
    const draw = (e) => {
        if (!dibujando) return;
        e.preventDefault();
        const [x, y] = pos(e);
        ctx.strokeStyle = document.getElementById('colorFirma').value;
        ctx.lineWidth   = parseFloat(document.getElementById('grosorFirma').value);
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(x, y);
        ctx.stroke();
        [lastX, lastY] = [x, y];
    };
    const stop = () => dibujando = false;

    c.addEventListener('mousedown', start);
    c.addEventListener('mousemove', draw);
    c.addEventListener('mouseup',   stop);
    c.addEventListener('mouseleave',stop);
    c.addEventListener('touchstart', start, { passive: false });
    c.addEventListener('touchmove',  draw,  { passive: false });
    c.addEventListener('touchend',   stop);
}

function limpiarCanvas() {
    const c = document.getElementById('canvasFirma');
    const ctx = c.getContext('2d');
    ctx.clearRect(0, 0, c.width, c.height); // clearRect = transparente
    hayTrazo = false;
}

function capturarCanvas() {
    // Guardar un canvas en blanco deja el formulario sin firma pero con el
    // ✅ puesto, que es peor que no tener nada.
    if (!hayTrazo) {
        alert('Dibuja la firma antes de guardar.');
        return null;
    }
    return document.getElementById('canvasFirma').toDataURL('image/png');
}
</script>
</body>
</html>
