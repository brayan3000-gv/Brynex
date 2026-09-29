@extends('layouts.app')
@section('modulo','Gestión de Gastos')
@section('contenido')
@php
    $fmt  = fn($v) => '$ '.number_format($v,0,',','.');
    $meses= ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $tipos= \App\Models\Gasto::TIPOS;
@endphp
<style>
:root{--blue:#2563eb;--red:#ef4444;--green:#10b981;--gray:#64748b;--bg:#f1f5f9}
body{background:var(--bg);font-family:'Inter',sans-serif}
.page-header{background:#fff;border-bottom:1px solid #e2e8f0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.page-title{font-size:1.1rem;font-weight:700;color:#0f172a}
.btn{padding:.45rem .9rem;border-radius:8px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;transition:.15s}
.btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#dc2626}
.btn-warning{background:#f59e0b;color:#fff}
.btn-sm{padding:.3rem .6rem;font-size:.75rem}
.btn-ghost{background:transparent;border:1px solid #e2e8f0;color:var(--gray)}.btn-ghost:hover{background:#f8fafc}
.filters{background:#fff;padding:.85rem 1.5rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.filters select,.filters input{padding:.4rem .7rem;border:1px solid #e2e8f0;border-radius:7px;font-size:.82rem;color:#334155}
.tabs{display:flex;gap:0;background:#fff;border-bottom:2px solid #e2e8f0;padding:0 1.5rem}
.tab-btn{padding:.7rem 1.2rem;font-size:.83rem;font-weight:600;border:none;background:none;cursor:pointer;color:var(--gray);border-bottom:3px solid transparent;margin-bottom:-2px;transition:.15s}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue)}
.content{padding:1.25rem 1.5rem;max-width:1400px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden}
.total-bar{padding:.6rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.82rem;font-weight:600;color:#334155}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead th{background:#f8fafc;padding:.6rem .8rem;text-align:left;font-weight:600;color:var(--gray);border-bottom:1px solid #e2e8f0;white-space:nowrap}
tbody tr{border-bottom:1px solid #f1f5f9;transition:.1s}
tbody tr:hover{background:#f8fafc}
td{padding:.55rem .8rem;color:#334155;vertical-align:middle}
.badge{display:inline-block;padding:.2rem .55rem;border-radius:20px;font-size:.7rem;font-weight:600}
.badge-efectivo{background:#f0fdf4;color:#16a34a}
.badge-banco{background:#eff6ff;color:#2563eb}
.badge-planilla{background:#fdf4ff;color:#9333ea}
.valor-col{text-align:right;font-weight:700;color:#0f172a}
.grupo-rs{background:#eff6ff;padding:.5rem .8rem;font-weight:700;color:#1e40af;font-size:.82rem;display:flex;justify-content:space-between}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;padding:1.5rem;width:min(680px,96vw);max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{margin:0 0 1.2rem;font-size:1rem;font-weight:700;color:#0f172a}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem}
.form-row.full{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:.3rem}
.form-group label{font-size:.75rem;font-weight:600;color:var(--gray)}
.form-group input,.form-group select,.form-group textarea{padding:.45rem .7rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;color:#334155;font-family:inherit}
.form-group textarea{resize:vertical;min-height:70px}
/* Zona imagen */
.paste-zone{border:2px dashed #cbd5e1;border-radius:10px;padding:1rem;text-align:center;cursor:pointer;transition:.2s;background:#f8fafc;min-height:90px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem}
.paste-zone:hover,.paste-zone.drag-over{border-color:var(--blue);background:#eff6ff}
.paste-zone.has-image{border-color:var(--green);background:#f0fdf4}
.paste-zone img{max-height:120px;max-width:100%;border-radius:6px;margin-top:.5rem}
.paste-zone p{font-size:.78rem;color:var(--gray);margin:0}
/* Lightbox */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:2000;align-items:center;justify-content:center}
.lightbox.open{display:flex}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:8px}
.lightbox-close{position:fixed;top:1rem;right:1.25rem;color:#fff;font-size:2rem;cursor:pointer;font-weight:700;line-height:1}
.alert{padding:.65rem 1rem;border-radius:8px;font-size:.82rem;margin-bottom:.75rem}
.alert-success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
@media(max-width:640px){.form-row{grid-template-columns:1fr}}
</style>

{{-- Header --}}
<div class="page-header">
    <div>
        <div class="page-title">💸 Gestión de Gastos</div>
        <div style="font-size:.75rem;color:var(--gray)">{{ $meses[$mes] }} {{ $anio }}</div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <a href="{{ route('admin.informes.financiero', ['mes'=>$mes,'anio'=>$anio]) }}" class="btn btn-ghost btn-sm">← Financiero</a>
        @if($esSuperAdmin)
        <button class="btn btn-primary" onclick="abrirModalNuevo()">➕ Nuevo Gasto</button>
        @endif
    </div>
</div>

{{-- Filtros --}}
<div class="filters">
    <form method="GET" action="{{ route('admin.informes.gastos.index') }}" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <select name="mes" onchange="this.form.submit()">
            @foreach($mesesNombres as $i=>$nm) @if($i>0)
            <option value="{{ $i }}" @selected($i==$mes)>{{ $nm }}</option>
            @endif @endforeach
        </select>
        <select name="anio" onchange="this.form.submit()">
            @foreach(range(now()->year, 2024) as $y)
            <option value="{{ $y }}" @selected($y==$anio)>{{ $y }}</option>
            @endforeach
        </select>
    </form>
    <div style="margin-left:auto;font-size:.8rem;color:var(--gray)">
        Total mes: <strong>{{ $fmt($totalGeneral + $totalPlanilla) }}</strong>
    </div>
</div>

{{-- Tabs --}}
<div class="tabs">
    <a href="{{ route('admin.informes.gastos.index', ['mes'=>$mes,'anio'=>$anio,'tab'=>'general']) }}"
       class="tab-btn {{ $tab=='general' ? 'active' : '' }}">
        📋 Gastos Generales <span style="background:#e2e8f0;border-radius:10px;padding:.1rem .45rem;font-size:.7rem;margin-left:.3rem">{{ $generales->count() }}</span>
    </a>
    <a href="{{ route('admin.informes.gastos.index', ['mes'=>$mes,'anio'=>$anio,'tab'=>'planilla']) }}"
       class="tab-btn {{ $tab=='planilla' ? 'active' : '' }}">
        🏢 Pagos Planilla <span style="background:#e2e8f0;border-radius:10px;padding:.1rem .45rem;font-size:.7rem;margin-left:.3rem">{{ $planillas->sum(fn($g)=>$g->count()) }}</span>
    </a>
</div>

<div class="content">
    {{-- Alertas --}}
@if(session('error'))
    <div class="alert alert-error">❌ {{ session('error') }}</div>
    @endif

    {{-- ── TAB GENERALES ────────────────────────────────────────────── --}}
    @if($tab == 'general')
    <div class="card">
        <div class="total-bar">
            Gastos generales del mes: <span style="color:var(--red)">{{ $fmt($totalGeneral) }}</span>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>⏱️ Hora</th>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th>Pagado a</th>
                    <th>Forma Pago / Banco</th>
                    <th>Usuario</th>
                    <th style="text-align:right">Valor</th>
                    <th>Soporte</th>
                    @if($esSuperAdmin)<th>Acciones</th>@endif
                </tr>
            </thead>
            <tbody>
            @forelse($generales as $g)
            <tr>
                <td style="white-space:nowrap">{{ sqldate($g->fecha, 'd/m/y') }}</td>
                <td style="white-space:nowrap;font-size:.75rem;color:#64748b">
                    {{ $g->created_at ? sqldate($g->created_at, 'H:i') : '—' }}
                </td>
                <td>
                    <span class="badge {{ in_array($g->forma_pago,['transferencia_bancaria','banco_banco']) ? 'badge-banco' : 'badge-efectivo' }}">
                        {{ $tipos[$g->tipo] ?? $g->tipo }}
                    </span>
                </td>
                <td style="max-width:220px">{{ $g->descripcion }}</td>
                <td>{{ $g->pagado_a ?? '—' }}</td>
                <td>
                    @if($g->forma_pago=='efectivo')
                        💵 Efectivo
                    @elseif($g->banco_nombre)
                        🏦 {{ $g->banco_nombre }} — {{ $g->banco_titular }}
                    @else
                        {{ $g->forma_pago }}
                    @endif
                </td>
                <td style="white-space:nowrap">{{ $g->usuario_nombre ?? '—' }}</td>
                <td class="valor-col">{{ $fmt($g->valor) }}</td>
                <td>
                    @if($g->imagen_path)
                        <button class="btn btn-ghost btn-sm" onclick="verImagen('{{ Storage::url($g->imagen_path) }}')">🖼️</button>
                    @else
                        @if($esSuperAdmin)
                        <button class="btn btn-ghost btn-sm" onclick="abrirSubirImagen({{ $g->id }})">📎</button>
                        @else
                        —
                        @endif
                    @endif
                </td>
                @if($esSuperAdmin)
                <td>
                    <div style="display:flex;gap:.3rem">
                        <button class="btn btn-warning btn-sm" onclick="abrirModalEditar({{ json_encode($g) }})">✏️</button>
                        <form method="POST" action="{{ route('admin.informes.gastos.destroy', $g->id) }}" onsubmit="return confirm('¿Eliminar este gasto?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="mes" value="{{ $mes }}">
                            <input type="hidden" name="anio" value="{{ $anio }}">
                            <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
                        </form>
                    </div>
                </td>
                @endif
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--gray)">Sin gastos generales en este período</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
    @endif

    {{-- ── TAB PLANILLAS ─────────────────────────────────────────────── --}}
    @if($tab == 'planilla')
    <div class="card">
        <div class="total-bar">
            Total pagos planilla del mes: <span style="color:var(--red)">{{ $fmt($totalPlanilla) }}</span>
        </div>
        <div style="overflow-x:auto">
        @forelse($planillas as $rs => $items)
        <div class="grupo-rs">
            <span>🏢 {{ $rs ?: 'Sin razón social' }}</span>
            <span>{{ $fmt($items->sum('valor')) }}</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>⏱️ Hora</th>
                    <th>N° Planilla</th>
                    <th>Descripción</th>
                    <th>Pagado a</th>
                    <th>Banco</th>
                    <th>Usuario</th>
                    <th style="text-align:right">Valor</th>
                    <th>Soporte</th>
                    @if($esSuperAdmin)<th>Acciones</th>@endif
                </tr>
            </thead>
            <tbody>
            @foreach($items as $g)
            <tr>
                <td style="white-space:nowrap">{{ sqldate($g->fecha, 'd/m/y') }}</td>
                <td style="white-space:nowrap;font-size:.75rem;color:#64748b">
                    {{ $g->created_at ? sqldate($g->created_at, 'H:i') : '—' }}
                </td>
                <td><span style="font-family:monospace;font-size:.78rem">{{ $g->numero_planilla ?? '—' }}</span></td>
                <td style="max-width:200px">{{ $g->descripcion }}</td>
                <td>{{ $g->pagado_a ?? '—' }}</td>
                <td>{{ $g->banco_nombre ? $g->banco_nombre.' — '.$g->banco_titular : '—' }}</td>
                <td>{{ $g->usuario_nombre ?? '—' }}</td>
                <td class="valor-col">{{ $fmt($g->valor) }}</td>
                <td>
                    @if($g->imagen_path)
                        <button class="btn btn-ghost btn-sm" onclick="verImagen('{{ Storage::url($g->imagen_path) }}')">🖼️</button>
                    @else
                        @if($esSuperAdmin)<button class="btn btn-ghost btn-sm" onclick="abrirSubirImagen({{ $g->id }})">📎</button>@else —@endif
                    @endif
                </td>
                @if($esSuperAdmin)
                <td>
                    <div style="display:flex;gap:.3rem">
                        <button class="btn btn-warning btn-sm" onclick="abrirModalEditar({{ json_encode($g) }})">✏️</button>
                        <button class="btn btn-danger btn-sm" type="button"
                                onclick="abrirModalEliminarPlanilla({{ $g->id }}, @js($g->numero_planilla))">🗑️</button>
                    </div>
                </td>
                @endif
            </tr>
            @endforeach
            </tbody>
        </table>
        @empty
        <div style="text-align:center;padding:2rem;color:var(--gray)">Sin pagos de planilla en este período</div>
        @endforelse
        </div>
    </div>
    @endif
</div>

{{-- ── MODAL NUEVO GASTO (partial compartido) ──────────────────── --}}
@if($esSuperAdmin)
@include('admin.partials.modal_gasto', [
    'formAction'  => route('admin.informes.gastos.store'),
    'bancos'      => $bancos,
    'esAdmin'     => true,
    'modalId'     => 'modal-gasto',
    'imagenPaste' => true,
])

{{-- Modal subir imagen a gasto existente --}}
<div class="modal-overlay" id="modalImagen">
<div class="modal" style="max-width:420px">
    <h3>📎 Subir comprobante</h3>
    <form id="formImagen" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="imagen" accept="image/*,application/pdf" required style="width:100%;padding:.5rem;border:1px solid #e2e8f0;border-radius:8px">
        <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalImagen').classList.remove('open')">Cancelar</button>
            <button type="submit" class="btn btn-primary">Subir</button>
        </div>
    </form>
</div>
</div>
@endif

{{-- Modal confirmar eliminación de un pago de planilla --}}
@if($esSuperAdmin)
<div class="modal-overlay" id="modalEliminarPlanilla">
<div class="modal" style="max-width:560px">
    <h3>🗑️ Eliminar pago de planilla</h3>
    <div id="epContenido" style="font-size:.85rem;color:#334155;line-height:1.5">
        <p style="color:var(--gray)">Consultando los planos afectados…</p>
    </div>
    <form id="epForm" method="POST" style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1.2rem">
        @csrf @method('DELETE')
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('modalEliminarPlanilla').classList.remove('open')">Cancelar</button>
        <button type="submit" class="btn btn-danger" id="epConfirmar" disabled>Sí, eliminar</button>
    </form>
</div>
</div>
@endif

{{-- Lightbox --}}
<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
    <span class="lightbox-close">✕</span>
    <img id="lightboxImg" src="" alt="Comprobante">
</div>

<script>
function abrirModalNuevo(){
    const form = document.getElementById('modal-gasto-form');
    if(form){
        form.reset();
        const mp = form.querySelector('input[name="_method"]');
        if(mp) mp.remove();
        form.action = "{{ route('admin.informes.gastos.store') }}";
        // Restaurar select tipo si fue ocultado
        const selTipo = form.querySelector('select[name="tipo"]');
        if(selTipo){ selTipo.style.display = ''; selTipo.setAttribute('required',''); }
        const htipo = form.querySelector('input[name="tipo"][type="hidden"]');
        if(htipo) htipo.remove();
        const badge = form.querySelector('#badge-tipo-planilla');
        if(badge) badge.remove();
        quitarFormaPagoPropia(form);
    }
    const hdr = document.querySelector('#modal-gasto [style*="background:#0f172a"] span');
    if(hdr) hdr.textContent = '💼 Registrar Gasto';
    const btn = document.querySelector('#modal-gasto-form button[type="submit"]');
    if(btn) btn.textContent = '✅ Registrar Gasto';
    document.getElementById('modal-gasto').style.display = 'flex';
}

// Planos SS guarda los pagos de planilla con forma_pago='transferencia', que el
// select del modal no ofrece: sin opción propia abría vacío y obligaba a cambiarla.
const FORMAS_PAGO_PROPIAS = { transferencia: '🏦 Transferencia (Planos SS)' };

function quitarFormaPagoPropia(form){
    form.querySelectorAll('select[name="forma_pago"] option[data-propia]').forEach(o => o.remove());
}

function asegurarFormaPago(form, valor){
    quitarFormaPagoPropia(form);
    const sel = form.querySelector('select[name="forma_pago"]');
    if(!sel || !valor || sel.querySelector(`option[value="${CSS.escape(valor)}"]`)) return;
    const opt = document.createElement('option');
    opt.value = valor;
    opt.textContent = FORMAS_PAGO_PROPIAS[valor] ?? valor;
    opt.dataset.propia = '1';
    sel.appendChild(opt);
}

function abrirModalEditar(g){
    const modal = document.getElementById('modal-gasto');
    const form  = document.getElementById('modal-gasto-form');
    if(!modal || !form) return;

    // Título y botón
    const hdr = modal.querySelector('[style*="background:#0f172a"] span');
    if(hdr) hdr.textContent = '✏️ Editar Gasto #' + (g.id || '');
    const btn = form.querySelector('button[type="submit"]');
    if(btn) btn.textContent = '💾 Guardar Cambios';

    // _method PUT
    let mp = form.querySelector('input[name="_method"]');
    if(!mp){ mp = document.createElement('input'); mp.type='hidden'; mp.name='_method'; form.appendChild(mp); }
    mp.value = 'PUT';

    // Action
    form.action = `/admin/informes/gastos/${g.id}`;

    // ── Manejar tipo pago_planilla ────────────────────────────────────
    const selTipo = form.querySelector('select[name="tipo"]');
    // Limpiar estado anterior
    const prevHidden = form.querySelector('input[name="tipo"][type="hidden"]');
    if(prevHidden) prevHidden.remove();
    const prevBadge = form.querySelector('#badge-tipo-planilla');
    if(prevBadge) prevBadge.remove();

    if(g.tipo === 'pago_planilla'){
        // Ocultar select y quitar required
        if(selTipo){ selTipo.style.display = 'none'; selTipo.removeAttribute('required'); }
        // Inyectar hidden con tipo real
        const ht = document.createElement('input');
        ht.type = 'hidden'; ht.name = 'tipo'; ht.value = 'pago_planilla';
        ht.id = 'hidden-tipo-planilla';
        if(selTipo) selTipo.parentNode.appendChild(ht);
        // Badge informativo
        const badge = document.createElement('div');
        badge.id = 'badge-tipo-planilla';
        badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:.4rem;background:#f0fdf4;border:1px solid #86efac;color:#15803d;border-radius:7px;padding:.35rem .7rem;font-size:.78rem;font-weight:600;">🏢 Pago Planilla <span style="color:#6b7280;font-weight:400;font-size:.72rem;">(tipo fijo — administrar desde Planos)</span></span>';
        if(selTipo) selTipo.parentNode.insertBefore(badge, selTipo);
    } else {
        // Restaurar select normal
        if(selTipo){ selTipo.style.display = ''; selTipo.setAttribute('required',''); }
    }
    // ─────────────────────────────────────────────────────────────────

    // Rellenar campos (excluye hidden para no pisar el tipo inyectado)
    const set = (name, val) => {
        const el = form.querySelector(`[name="${name}"]:not([type="hidden"])`);
        if(el) el.value = val ?? '';
    };
    set('fecha',            g.fecha ? g.fecha.substring(0,10) : '');
    if(g.tipo !== 'pago_planilla') set('tipo', g.tipo ?? '');
    set('descripcion',      g.descripcion ?? '');
    set('pagado_a',         g.pagado_a    ?? '');
    set('valor',            g.valor       ?? '');
    asegurarFormaPago(form, g.forma_pago);
    set('forma_pago',       g.forma_pago  ?? 'efectivo');
    set('banco_origen_id',  g.banco_origen_id  ?? '');
    set('banco_destino_id', g.banco_destino_id ?? '');
    set('recibo_caja',      g.recibo_caja ?? '');
    set('observacion',      g.observacion ?? '');

    // Visibilidad de bancos
    if(g.tipo !== 'pago_planilla') gasto_onTipoChange('modal-gasto');
    gasto_actualizarBancos('modal-gasto');

    modal.style.display = 'flex';
}

function abrirModalEliminarPlanilla(id, numero){
    const modal = document.getElementById('modalEliminarPlanilla');
    const cont  = document.getElementById('epContenido');
    const form  = document.getElementById('epForm');
    const btn   = document.getElementById('epConfirmar');

    form.action = `/admin/informes/gastos/${id}`;
    btn.disabled = true;
    cont.innerHTML = `<p style="color:var(--gray)">Consultando los planos de la planilla N° ${numero ?? ''}…</p>`;
    modal.classList.add('open');

    fetch(`/admin/informes/gastos/${id}/impacto-planilla`, {headers:{'Accept':'application/json'}})
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(d => {
            if(!d.es_planilla){
                cont.innerHTML = '<p>Se eliminará este gasto. No hay planos marcados con esta planilla.</p>';
                btn.disabled = false;
                return;
            }
            const meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            const filas = (d.planos || []).map(p => `
                <tr>
                    <td>${p.razon_social ?? '—'}</td>
                    <td style="white-space:nowrap">Plano ${p.n_plano} · ${meses[p.mes_plano] ?? p.mes_plano} ${p.anio_plano}</td>
                    <td style="text-align:right">${p.registros}</td>
                </tr>`).join('');

            cont.innerHTML = `
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:.7rem .85rem;margin-bottom:.9rem">
                    <strong>La planilla N° ${d.numero_planilla} quedará sin pago.</strong><br>
                    Se le quitará el número de planilla a <strong>${d.total_registros}</strong> registro(s) de plano
                    y volverán a quedar <strong>pendientes de pago</strong>, para poder confirmarlos de nuevo.
                    <br><span style="color:var(--gray);font-size:.78rem">Los planos NO se eliminan.</span>
                </div>
                ${filas ? `<table style="font-size:.8rem">
                    <thead><tr><th>Razón social</th><th>Plano</th><th style="text-align:right">Registros</th></tr></thead>
                    <tbody>${filas}</tbody>
                </table>` : '<p style="color:var(--gray)">No hay planos marcados con esta planilla.</p>'}`;
            btn.disabled = false;
        })
        .catch(() => {
            cont.innerHTML = '<p style="color:var(--red)">No se pudo consultar el impacto. Intenta de nuevo.</p>';
        });
}

function abrirSubirImagen(id){
    document.getElementById('formImagen').action = `/admin/informes/gastos/${id}/imagen`;
    document.getElementById('modalImagen').classList.add('open');
}
function verImagen(url){
    document.getElementById('lightboxImg').src = url;
    document.getElementById('lightbox').classList.add('open');
}
</script>
@endsection

