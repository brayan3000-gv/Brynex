@extends('layouts.app')
@section('modulo', 'Préstamos')

@section('contenido')
@php
$fmt   = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
@endphp
<style>
.show-wrap  { display:flex; flex-direction:column; gap:.9rem; }
.back-link  { display:inline-flex; align-items:center; gap:.35rem; text-decoration:none; color:#475569; font-size:.82rem; font-weight:600; }
.back-link:hover { color:#1d4ed8; }

/* Header */
.show-header {
    background:linear-gradient(135deg,#0f172a,#1e3a5f);
    border-radius:14px; padding:1rem 1.4rem; color:#fff;
    display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
}
.show-title { font-size:1.15rem; font-weight:800; }
.show-sub   { font-size:.78rem; color:#94a3b8; margin-top:.15rem; }
.badge-estado { display:inline-block; padding:.2rem .65rem; border-radius:999px; font-size:.72rem; font-weight:700; margin-left:.5rem; vertical-align:middle; }
.badge-prestamo { background:#7c3aed; color:#fff; }
.badge-pagada   { background:#16a34a; color:#fff; }

/* Grid 3 cols */
.show-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.9rem; }
@media(max-width:900px){ .show-grid { grid-template-columns:1fr; } }

/* Panel */
.panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
.panel-hdr { background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:.55rem .9rem; font-size:.68rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.07em; }
.panel-body { padding:.9rem; }

/* Info rows */
.info-row { display:flex; justify-content:space-between; align-items:center; padding:.32rem 0; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.info-row:last-child { border:none; }
.info-lbl { color:#64748b; }
.info-val { font-weight:600; color:#0f172a; text-align:right; }

/* Monto grande */
.monto-big { font-size:1.8rem; font-weight:900; color:#dc2626; font-family:monospace; text-align:center; padding:.65rem 0; }
.monto-sub { text-align:center; font-size:.72rem; color:#94a3b8; margin-bottom:.75rem; }

/* Botones */
.btn-abonar {
    display:block; width:100%; padding:.6rem; border-radius:9px; font-size:.88rem;
    font-weight:700; cursor:pointer; border:none;
    background:linear-gradient(135deg,#15803d,#16a34a); color:#fff; margin-top:.65rem;
}
.btn-condonar {
    display:block; width:100%; padding:.48rem; border-radius:9px; font-size:.8rem;
    font-weight:600; cursor:pointer; margin-top:.45rem;
    background:#fee2e2; color:#dc2626; border:1.5px solid #fca5a5;
}
.saldado-box {
    text-align:center; margin-top:.65rem; padding:.6rem; background:#f0fdf4;
    border:1px solid #86efac; border-radius:9px; color:#15803d; font-size:.85rem; font-weight:700;
}

/* Abono item */
.abono-item { padding:.55rem 0; border-bottom:1px solid #f1f5f9; }
.abono-item:last-child { border:none; }
.abono-row { display:flex; justify-content:space-between; align-items:center; }
.abono-val  { font-weight:800; color:#15803d; font-size:.88rem; font-family:monospace; }
.abono-meta { font-size:.72rem; color:#94a3b8; margin-top:.1rem; }

/* Gestión timeline */
.tl { position:relative; padding-left:1.4rem; }
.tl::before { content:''; position:absolute; left:.45rem; top:0; bottom:0; width:2px; background:#e2e8f0; }
.tl-item { position:relative; margin-bottom:.9rem; }
.tl-item::before { content:''; position:absolute; left:-1.05rem; top:.28rem; width:9px; height:9px; border-radius:50%; border:2px solid #3b82f6; background:#fff; }
.tl-date { font-size:.66rem; color:#94a3b8; }
.tl-user { font-size:.7rem; font-weight:700; color:#1e40af; }
.tl-res  { font-size:.7rem; font-weight:700; padding:.1rem .4rem; border-radius:5px; background:#dbeafe; color:#1d4ed8; display:inline-block; margin:.15rem 0; }
.tl-obs  { font-size:.77rem; color:#334155; margin-top:.1rem; }
.btn-gestion {
    display:block; width:100%; padding:.5rem; border-radius:9px; font-size:.82rem; font-weight:700;
    cursor:pointer; margin-top:.6rem; background:#ede9fe; color:#6d28d9; border:1.5px solid #ddd6fe;
}
.empty-list { text-align:center; padding:1.5rem; color:#94a3b8; font-size:.83rem; }

/* Modales */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; padding:1.5rem; width:min(440px,96vw); max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-title { font-size:.97rem; font-weight:800; color:#0f172a; margin-bottom:1rem; padding-bottom:.55rem; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; }
.modal-close { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8; }
.form-grp { display:flex; flex-direction:column; gap:.2rem; margin-bottom:.7rem; }
.form-grp label { font-size:.7rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.form-grp input, .form-grp select, .form-grp textarea {
    padding:.45rem .65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.85rem; outline:none;
}
.form-grp input:focus, .form-grp select:focus, .form-grp textarea:focus { border-color:#3b82f6; }
.form-grp textarea { resize:vertical; min-height:70px; }
.btn-save { border:none; border-radius:10px; padding:.55rem 1.4rem; font-size:.88rem; font-weight:700; cursor:pointer; width:100%; }
.btn-save-green { background:linear-gradient(135deg,#15803d,#16a34a); color:#fff; }
.btn-save-blue  { background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff; }
.btn-save-red   { background:#fee2e2; color:#dc2626; border:1.5px solid #fca5a5; }
</style>

<div class="show-wrap">

{{-- Back --}}
<a href="{{ route('admin.prestamos.index') }}?tab={{ $esLoteEmpresa ? 'empresas' : 'individuales' }}" class="back-link">← Volver a Préstamos</a>

@php
    // Condonado: se dio por saldado sin recibir la plata (lo marca condonar()).
    $condonado = ($esLoteEmpresa ? $lote : collect([$factura]))
        ->contains(fn ($f) => $f->estado !== 'prestamo' && str_contains((string) $f->observacion, 'CONDONADO:'));
@endphp
@if($esLoteEmpresa)
{{-- ══ HEADER LOTE EMPRESA ══ --}}
<div class="show-header">
    <div>
        <div class="show-title">
            🏢 {{ $factura->empresa->empresa }} — Factura #{{ $factura->numero_factura }}
            <span class="badge-estado {{ $lote_estado === 'prestamo' ? 'badge-prestamo' : 'badge-pagada' }}">
                {{ $lote_estado === 'prestamo' ? 'Préstamo pendiente' : ($condonado ? 'Condonado' : 'Saldado') }}
            </span>
        </div>
        <div class="show-sub">
            {{ $lote->count() }} cliente(s) — {{ \Carbon\Carbon::createFromDate($factura->anio, $factura->mes, 1)->translatedFormat('F Y') }}
        </div>
    </div>
</div>

{{-- ══ TABLA DE CLIENTES DEL LOTE ══ --}}
<div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr">👥 Clientes incluidos en esta factura ({{ $lote->count() }})</div>
    <div class="panel-body" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:.45rem .7rem;text-align:left;color:#64748b;font-size:.68rem;text-transform:uppercase;">Cliente</th>
                    <th style="padding:.45rem .7rem;text-align:left;color:#64748b;font-size:.68rem;text-transform:uppercase;">Cédula</th>
                    <th style="padding:.45rem .7rem;text-align:right;color:#64748b;font-size:.68rem;text-transform:uppercase;">Valor</th>
                    <th style="padding:.45rem .7rem;text-align:right;color:#64748b;font-size:.68rem;text-transform:uppercase;">Asesor</th>
                </tr>
            </thead>
            <tbody>
            @foreach($lote as $lf)
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:.45rem .7rem;font-weight:600;color:#1e3a5f;">
                    {{ nombre_oracion($lf->contrato?->cliente?->primer_nombre) }} {{ nombre_oracion($lf->contrato?->cliente?->primer_apellido) }}
                </td>
                <td style="padding:.45rem .7rem;font-family:monospace;color:#64748b;">
                    {{ $lf->cedula }}
                </td>
                <td style="padding:.45rem .7rem;text-align:right;font-family:monospace;font-weight:700;">
                    {{ $fmt($lf->total) }}
                </td>
                <td style="padding:.45rem .7rem;text-align:right;color:#64748b;font-size:.75rem;">
                    {{ $lf->contrato?->asesor?->nombre ?? '—' }}
                </td>
            </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#0f172a;color:#fff;">
                    <td colspan="2" style="padding:.45rem .7rem;font-size:.72rem;font-weight:700;">TOTAL LOTE</td>
                    <td style="padding:.45rem .7rem;text-align:right;font-family:monospace;font-weight:800;">{{ $fmt($lote_total) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- ══ GRID 3 COL PARA LOTE ══ --}}
<div class="show-grid">
    {{-- Panel estado lote --}}
    <div class="panel">
        <div class="panel-hdr">💸 Estado del Lote</div>
        <div class="panel-body">
            <div class="monto-big">{{ $fmt($lote_saldo_pendiente) }}</div>
            <div class="monto-sub">Saldo pendiente total del lote</div>
            <div class="info-row">
                <span class="info-lbl">Valor original lote</span>
                <span class="info-val">{{ $fmt($lote_total) }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Total abonado</span>
                <span class="info-val" style="color:#15803d;">{{ $fmt($lote_total_abonado) }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Clientes</span>
                <span class="info-val">{{ $lote->count() }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Empresa</span>
                <span class="info-val">{{ $factura->empresa->empresa }}</span>
            </div>
            @if($lote_estado === 'prestamo')
                <button class="btn-abonar" onclick="abrirAbonar({{ $factura->id }}, {{ $lote_saldo_pendiente }}, @js('🏢 ' . $factura->empresa->empresa))">
                    💰 Registrar Abono al Lote
                </button>
                @role('superadmin')
                <button class="btn-condonar" onclick="abrirCondonar({{ $factura->id }})">
                    🗑 Condonar Deuda
                </button>
                @endrole
            @else
                <div class="saldado-box">{{ $condonado ? '🤝 Deuda condonada' : '✅ Lote completamente saldado' }}</div>
            @endif
        </div>
    </div>

@else
{{-- ══ HEADER INDIVIDUAL ══ --}}
<div class="show-header">
    <div>
        <div class="show-title">
            📋 Factura #{{ $factura->numero_factura }}
            <span class="badge-estado {{ $factura->estado === 'prestamo' ? 'badge-prestamo' : 'badge-pagada' }}">
                {{ $factura->etiqueta_estado }}
            </span>
        </div>
        <div class="show-sub">
            {{ nombre_oracion($factura->contrato?->cliente?->primer_nombre) }} {{ nombre_oracion($factura->contrato?->cliente?->primer_apellido) }}
            @if($factura->empresa) — {{ $factura->empresa->empresa }} @endif
        </div>
    </div>
</div>

{{-- Grid 3 col --}}
<div class="show-grid">

    {{-- ══ Panel 1: Estado del préstamo ══ --}}
    <div class="panel">
        <div class="panel-hdr">💸 Estado del Préstamo</div>
        <div class="panel-body">
            <div class="monto-big">{{ $fmt($factura->saldo_pendiente_prestamo) }}</div>
            <div class="monto-sub">Saldo pendiente por cobrar</div>

            <div class="info-row">
                <span class="info-lbl">Período</span>
                <span class="info-val">{{ \Carbon\Carbon::createFromDate($factura->anio, $factura->mes, 1)->translatedFormat('F Y') }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Tipo</span>
                <span class="info-val">{{ match($factura->tipo) { 'planilla'=>'Planilla SS', 'afiliacion'=>'Afiliación', 'otro_ingreso'=>'Otro ingreso', default=>ucfirst($factura->tipo??'—') } }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Valor original</span>
                <span class="info-val">{{ $fmt($factura->total) }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Total abonado</span>
                <span class="info-val" style="color:#15803d;">{{ $fmt($factura->total_abonado) }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Cédula</span>
                <span class="info-val">{{ $factura->cedula }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Asesor</span>
                <span class="info-val">{{ $factura->contrato?->asesor?->nombre ?? '—' }}</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">Facturado por</span>
                <span class="info-val">{{ $factura->usuario?->nombre ?? '—' }}</span>
            </div>

            @if($factura->estado === 'prestamo')
                <button class="btn-abonar" onclick="abrirAbonar({{ $factura->id }}, {{ $factura->saldo_pendiente_prestamo }}, @js(trim(nombre_oracion($factura->contrato?->cliente?->primer_nombre) . ' ' . nombre_oracion($factura->contrato?->cliente?->primer_apellido))))">
                    💰 Registrar Abono
                </button>
                @role('superadmin')
                <button class="btn-condonar" onclick="abrirCondonar({{ $factura->id }})">
                    🗑 Condonar Deuda
                </button>
                @endrole
            @else
                <div class="saldado-box">{{ $condonado ? '🤝 Deuda condonada' : '✅ Préstamo completamente saldado' }}</div>
            @endif
        </div>
    </div>
@endif

    {{-- ══ Panel 2: Historial de abonos ══ --}}
    <div class="panel">
        @php $abonos_display = $esLoteEmpresa ? $lote_abonos : $factura->abonos->sortByDesc('fecha'); @endphp
        <div class="panel-hdr">📥 Historial de Abonos ({{ $abonos_display->count() }})</div>
        <div class="panel-body">
            @forelse($abonos_display as $abono)
            <div class="abono-item">
                <div class="abono-row">
                    <span class="abono-val">{{ $fmt($abono->valor) }}</span>
                    <span style="font-size:.75rem;color:#64748b;">{{ sqldate($abono->fecha)?->format('d/m/Y') }}</span>
                </div>
                <div class="abono-meta">
                    {{ ucfirst($abono->forma_pago) }}
                    @if($abono->valor_efectivo > 0) · Efec: {{ $fmt($abono->valor_efectivo) }} @endif
                    @if($abono->valor_consignado > 0) · Consig: {{ $fmt($abono->valor_consignado) }} @endif
                    · {{ $abono->usuario?->nombre ?? '—' }}
                </div>
                @if($abono->observacion)
                <div class="abono-meta" style="color:#475569;margin-top:.1rem;">{{ $abono->observacion }}</div>
                @endif
                <div style="margin-top:.25rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;" id="sop-box-{{ $abono->id }}">
                    @if($abono->soporte_path)
                    <a href="{{ route('admin.prestamos.abono.soporte', $abono->id) }}"
                       style="font-size:.72rem;font-weight:700;color:#2563eb;text-decoration:none;">
                        📎 Ver certificado{{ $abono->soporte_nombre ? ' — ' . \Illuminate\Support\Str::limit($abono->soporte_nombre, 32) : '' }}
                    </a>
                    <button type="button" onclick="adjuntarSoporte({{ $abono->id }}, true)"
                            style="background:none;border:none;padding:0;cursor:pointer;font-size:.7rem;color:#94a3b8;text-decoration:underline;">
                        cambiar
                    </button>
                    @else
                    <button type="button" onclick="adjuntarSoporte({{ $abono->id }}, false)"
                            style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.15rem .5rem;cursor:pointer;font-size:.7rem;font-weight:700;color:#2563eb;">
                        📎 Adjuntar certificado
                    </button>
                    @endif
                </div>
            </div>
            @empty
            <div class="empty-list">Sin abonos registrados aún</div>
            @endforelse
        </div>
    </div>

    {{-- ══ Panel 3: Gestiones de cobro ══ --}}
    <div class="panel">
        <div class="panel-hdr">📞 Gestiones de Cobro ({{ $gestiones->count() }})</div>
        <div class="panel-body">
            @if($gestiones->isNotEmpty())
            <div class="tl">
                @foreach($gestiones as $g)
                <div class="tl-item">
                    <div class="tl-date">
                        {{ $g->fecha_llamada->format('d/m/Y H:i') }}
                        &nbsp;<span class="tl-user">{{ $g->usuario?->nombre ?? '—' }}</span>
                    </div>
                    <div class="tl-res">{{ \App\Models\BitacoraCobro::RESULTADOS[$g->resultado] ?? $g->resultado }}</div>
                    @if($g->observacion)
                    <div class="tl-obs">{{ $g->observacion }}</div>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="empty-list">Sin gestiones registradas</div>
            @endif

            @php $estadoActivo = $esLoteEmpresa ? $lote_estado : $factura->estado; @endphp
            @if($estadoActivo === 'prestamo')
            <button class="btn-gestion" onclick="abrirGestion({{ $factura->id }})">
                📞 Registrar Gestión
            </button>
            @endif
        </div>
    </div>

</div>{{-- /show-grid --}}
</div>{{-- /show-wrap --}}

{{-- ══ MODAL ABONAR ══ --}}
@include('admin.prestamos._modal_abono')

{{-- ══ MODAL GESTIÓN ══ --}}
<div class="modal-bg" id="modalGestion">
<div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-title">
        <span>📞 Registrar Gestión de Cobro</span>
        <button class="modal-close" onclick="cerrarModal('modalGestion')">✕</button>
    </div>
    <form id="formGestion">
        <input type="hidden" id="g-id">
        <div class="form-grp">
            <label>Resultado *</label>
            <select id="g-resultado">
                <option value="no_contesta">📵 No contesta</option>
                <option value="promesa_pago">🤝 Promesa de pago</option>
                <option value="pagado">✅ Ya pagó / Pagará hoy</option>
                <option value="numero_errado">❌ Número errado</option>
                <option value="otro">📝 Otro</option>
            </select>
        </div>
        <div class="form-grp">
            <label>Observación *</label>
            <textarea id="g-obs" rows="3" placeholder="¿Qué dijo el cliente?"></textarea>
        </div>
        <button type="submit" class="btn-save btn-save-blue">📞 Guardar Gestión</button>
    </form>
</div>
</div>

{{-- ══ MODAL CONDONAR (superadmin) ══ --}}
@role('superadmin')
<div class="modal-bg" id="modalCondonar">
<div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-title">
        <span style="color:#dc2626;">⚠️ Condonar Deuda</span>
        <button class="modal-close" onclick="cerrarModal('modalCondonar')">✕</button>
    </div>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.55rem .75rem;margin-bottom:.8rem;font-size:.82rem;color:#991b1b;">
        Esta acción perdonará la deuda y marcará el préstamo como pagado. No se puede deshacer.
    </div>
    <form id="formCondonar">
        <input type="hidden" id="con-id">
        <div class="form-grp">
            <label>Motivo de condonación *</label>
            <textarea id="con-motivo" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn-save btn-save-red">🗑 Confirmar Condonación</button>
    </form>
</div>
</div>
@endrole

@endsection

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
const fmt  = v => '$' + parseInt(v||0).toLocaleString('es-CO');

function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Gestión ──────────────────────────────────────────────────
function abrirGestion(id) {
    document.getElementById('g-id').value = id;
    document.getElementById('g-obs').value = '';
    document.getElementById('modalGestion').classList.add('open');
}
document.getElementById('formGestion').addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('g-id').value;
    const r  = await fetch(`/admin/prestamos/${id}/gestion`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({ resultado: document.getElementById('g-resultado').value, observacion: document.getElementById('g-obs').value })
    });
    const res = await r.json();
    if (res.ok) { alert('✅ Gestión registrada.'); location.reload(); }
    else alert('Error al registrar gestión');
});

// ── Adjuntar certificado a un abono ya registrado ────────────
function adjuntarSoporte(abonoId, tieneYa) {
    if (tieneYa && !confirm('Ya hay un certificado adjunto. ¿Reemplazarlo?')) return;

    const inp = document.createElement('input');
    inp.type   = 'file';
    inp.accept = 'image/jpeg,image/png,application/pdf';
    inp.onchange = async () => {
        const f = inp.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('soporte', f);

        const r   = await fetch(`/admin/prestamos/abono/${abonoId}/soporte`, {
            method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'Accept':'application/json'}, body: fd
        });
        const res = await r.json();
        if (!r.ok) {
            alert(res.message || Object.values(res.errors || {}).flat().join('\n') || 'No se pudo adjuntar el certificado');
            return;
        }
        const box = document.getElementById('sop-box-' + abonoId);
        if (box) {
            const nombre = (res.nombre || '').length > 32 ? res.nombre.slice(0, 32) + '…' : (res.nombre || '');
            box.innerHTML =
                `<a href="${res.url}" style="font-size:.72rem;font-weight:700;color:#2563eb;text-decoration:none;">📎 Ver certificado${nombre ? ' — ' + nombre : ''}</a>` +
                `<button type="button" onclick="adjuntarSoporte(${abonoId}, true)" style="background:none;border:none;padding:0;cursor:pointer;font-size:.7rem;color:#94a3b8;text-decoration:underline;">cambiar</button>`;
        }
        alert(res.mensaje || '📎 Certificado adjuntado.');
    };
    inp.click();
}

// ── Condonar ─────────────────────────────────────────────────
function abrirCondonar(id) {
    document.getElementById('con-id').value = id;
    document.getElementById('con-motivo').value = '';
    document.getElementById('modalCondonar').classList.add('open');
}
const formCon = document.getElementById('formCondonar');
if (formCon) {
    formCon.addEventListener('submit', async e => {
        e.preventDefault();
        const id = document.getElementById('con-id').value;
        const r  = await fetch(`/admin/prestamos/${id}/condonar`, {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({ motivo: document.getElementById('con-motivo').value })
        });
        const res = await r.json();
        alert(res.mensaje || (res.ok ? 'Condonado.' : 'Error'));
        // Condonado ya no está por cobrar: de vuelta al listado.
        if (res.ok) location.href = @js(route('admin.prestamos.index', ['tab' => $esLoteEmpresa ? 'empresas' : 'individuales']));
    });
}
</script>
@endpush
