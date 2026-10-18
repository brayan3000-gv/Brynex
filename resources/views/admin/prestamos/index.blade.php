@extends('layouts.app')
@section('modulo', 'Préstamos')

@php
$fmt = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
@endphp

@section('contenido')
<style>
/* ── Layout ── */
.prest-wrap { display:flex; flex-direction:column; gap:.8rem; }

/* ── Header ── */
.prest-header {
    background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#1e40af 100%);
    border-radius:14px; padding:1rem 1.4rem; color:#fff;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.7rem;
}
.prest-title { font-size:1.3rem; font-weight:800; }
.prest-sub   { font-size:.77rem; color:#94a3b8; margin-top:.15rem; }

/* ── Chips resumen (antes cards sueltas: ahora viven dentro de la barra) ── */
.chips-row { display:flex; gap:.4rem; flex-wrap:wrap; margin-left:auto; }
.chip {
    display:flex; align-items:center; gap:.45rem; background:#f8fafc;
    border:1px solid #e2e8f0; border-left:3px solid #2563eb;
    border-radius:9px; padding:.3rem .6rem; line-height:1.1;
}
.chip-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
.chip-val { font-size:.95rem; font-weight:800; font-family:monospace; color:#0f172a; }
.chip-danger { border-left-color:#dc2626; } .chip-danger .chip-val { color:#dc2626; }
.chip-warn   { border-left-color:#d97706; } .chip-warn   .chip-val { color:#d97706; }
.chip-info   { border-left-color:#2563eb; } .chip-info   .chip-val { color:#2563eb; }
.chip-ok     { border-left-color:#16a34a; } .chip-ok     .chip-val { color:#16a34a; }
@media(max-width:900px){ .chips-row{ margin-left:0; width:100%; } }

/* ── Tabs ── */
.tabs-bar { display:flex; gap:.45rem; align-items:center; }
.tab-link {
    padding:.38rem 1rem; border-radius:8px; font-size:.82rem; font-weight:700;
    text-decoration:none; border:1.5px solid #e2e8f0; background:#fff; color:#64748b;
    transition:.15s;
}
.tab-link.active { background:#1e40af; border-color:#1e40af; color:#fff; }

/* ── Barra única: tabs + buscador + chips ── */
.barra-top {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:.55rem .8rem; display:flex; flex-wrap:wrap; gap:.55rem; align-items:center;
}
.filtros { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin:0; }
.filtros input { padding:.38rem .7rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.82rem; outline:none; }
.filtros input:focus { border-color:#3b82f6; }
.btn-filtrar { padding:.38rem .95rem; background:#1e40af; color:#fff; border:none; border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; }
.btn-limpiar { padding:.38rem .8rem; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; }

/* ── Tabla ── */
.tbl-wrap { overflow-x:auto; border-radius:12px; border:1px solid #e2e8f0; background:#fff; }
.tbl-prest { width:100%; border-collapse:collapse; font-size:.8rem; white-space:nowrap; }
.tbl-prest thead th { background:#0f172a; color:#fff; padding:.5rem .7rem; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
.tbl-prest thead th.sortable { cursor:pointer; user-select:none; }
.tbl-prest thead th.sortable:hover { background:#1e293b; }
.sort-ind { margin-left:.25rem; font-size:.6rem; opacity:.35; }
.tbl-prest thead th.sorted .sort-ind { opacity:1; color:#93c5fd; }
.tbl-prest tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.tbl-prest tbody tr:hover { background:#f8fafc; }
.tbl-prest td { padding:.5rem .7rem; vertical-align:middle; color:#1e293b; }

/* ── Semáforo ── */
.sem { display:inline-block; width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.sem-gris     { background:#94a3b8; }
.sem-verde    { background:#16a34a; }
.sem-amarillo { background:#d97706; }
.sem-rojo     { background:#dc2626; box-shadow:0 0 5px #dc262688; }

/* ── Montos ── */
.monto-deuda { font-weight:700; color:#dc2626; font-family:monospace; }
.monto-abono { font-weight:600; color:#16a34a; font-family:monospace; }

/* ── Botones acción ── */
.btn-sm { padding:.25rem .65rem; border-radius:6px; font-size:.73rem; font-weight:700; cursor:pointer; border:none; text-decoration:none; display:inline-flex; align-items:center; gap:.2rem; }
.btn-ver   { background:#dbeafe; color:#1d4ed8; }
.btn-abono { background:#dcfce7; color:#15803d; }
.btn-gestion { background:#f3e8ff; color:#7c3aed; }

/* ── Pagados ── */
.monto-pagado { font-weight:700; color:#16a34a; font-family:monospace; }
.tag-tipo { padding:.15rem .5rem; border-radius:20px; font-size:.68rem; font-weight:700; }
.tag-ind { background:#e0e7ff; color:#4338ca; }
.tag-emp { background:#e0f2fe; color:#0369a1; }
.tag-estado { padding:.15rem .5rem; border-radius:20px; font-size:.68rem; font-weight:700; }
.tag-pagado    { background:#dcfce7; color:#15803d; }
.tag-condonado { background:#fef3c7; color:#b45309; }

/* ── Empty ── */
.empty-state { text-align:center; padding:3rem; color:#94a3b8; }

/* ── Modales ── */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; padding:1.5rem; width:min(440px,96vw); max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-title { font-size:.97rem; font-weight:800; color:#0f172a; margin-bottom:1rem; padding-bottom:.55rem; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; }
.modal-close { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8; }
.modal-close:hover { color:#ef4444; }
.form-grp { display:flex; flex-direction:column; gap:.2rem; margin-bottom:.75rem; }
.form-grp label { font-size:.7rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.form-grp input, .form-grp select, .form-grp textarea {
    padding:.45rem .65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.85rem; outline:none;
}
.form-grp input:focus, .form-grp select:focus, .form-grp textarea:focus { border-color:#3b82f6; }
.form-grp textarea { resize:vertical; min-height:70px; }
.btn-save { background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff; border:none; border-radius:10px; padding:.55rem 1.4rem; font-size:.88rem; font-weight:700; cursor:pointer; width:100%; }
</style>

<div class="prest-wrap">

{{-- ══ HEADER ══ --}}
<div class="prest-header">
    <div>
        <div class="prest-title">📋 Préstamos / Cartera</div>
        <div class="prest-sub">Gestión de servicios prestados pendientes de cobro</div>
    </div>
</div>

{{-- ══ BARRA: filtros + buscador + resumen, todo en una sola fila ══ --}}
<div class="barra-top">
    <div class="tabs-bar">
        <a href="?tab=individuales{{ $buscar ? '&buscar='.urlencode($buscar) : '' }}"
           class="tab-link {{ $tab === 'individuales' ? 'active' : '' }}">
            👤 Individuales ({{ $individuales->count() }})
        </a>
        <a href="?tab=empresas{{ $buscar ? '&buscar='.urlencode($buscar) : '' }}"
           class="tab-link {{ $tab === 'empresas' ? 'active' : '' }}">
            🏢 Empresas ({{ $empresasAgrupadas->count() }})
        </a>
        <a href="?tab=pagados{{ $buscar ? '&buscar='.urlencode($buscar) : '' }}"
           class="tab-link {{ $tab === 'pagados' ? 'active' : '' }}">
            ✅ Pagados ({{ $cantPagados }})
        </a>
    </div>

    <form method="GET" class="filtros">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="text" name="buscar" value="{{ $buscar }}" placeholder="🔍 Nombre, cédula o empresa..." style="min-width:200px;">
        <button type="submit" class="btn-filtrar">Buscar</button>
        @if($buscar)
        <a href="?tab={{ $tab }}" class="btn-limpiar">✕ Limpiar</a>
        @endif
    </form>

    <div class="chips-row">
        <div class="chip chip-info" title="Préstamos activos">
            <span class="chip-lbl">📋 Activos</span>
            <span class="chip-val">{{ $totalPrestamos }}</span>
        </div>
        <div class="chip chip-danger" title="Total deuda">
            <span class="chip-lbl">💸 Deuda</span>
            <span class="chip-val">{{ $fmt($totalDeudaInd + $totalDeudaEmp) }}</span>
        </div>
        <div class="chip chip-warn" title="Deuda de empresas">
            <span class="chip-lbl">🏢 Empresas</span>
            <span class="chip-val">{{ $fmt($totalDeudaEmp) }}</span>
        </div>
        <div class="chip {{ $sinGestion > 0 ? 'chip-danger' : 'chip-info' }}" title="Sin gestión reciente">
            <span class="chip-lbl">🔴 Sin gestión</span>
            <span class="chip-val">{{ $sinGestion }}</span>
        </div>
        @if($tab === 'pagados')
        <div class="chip chip-ok" title="Valor de los préstamos ya saldados">
            <span class="chip-lbl">✅ Recuperado</span>
            <span class="chip-val">{{ $fmt($totalPagado) }}</span>
        </div>
        @endif
    </div>
</div>

{{-- ══ TAB INDIVIDUALES ══ --}}
@if($tab === 'individuales')
<div class="tbl-wrap">
    @if($individuales->isEmpty())
    <div class="empty-state">
        <div style="font-size:2.5rem;">✅</div>
        <div style="font-size:1rem;font-weight:700;margin-top:.5rem;color:#0f172a;">Sin préstamos individuales pendientes</div>
    </div>
    @else
    <table class="tbl-prest">
        <thead>
            <tr>
                <th data-sort="num" title="Semáforo de gestión — ordena por riesgo"></th>
                <th data-sort="texto">Cliente</th>
                <th data-sort="num">Cédula</th>
                <th data-sort="texto">Asesor</th>
                <th data-sort="num">Período</th>
                <th data-sort="num" class="text-right">Valor original</th>
                <th data-sort="num" class="text-right">Abonado</th>
                <th data-sort="num" class="text-right">Saldo deuda</th>
                <th data-sort="num">Última gestión</th>
                <th style="text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
        @foreach($individuales as $f)
        @php
        $nombre = trim(
            ($f->contrato?->cliente?->primer_nombre ?? '') . ' ' .
            ($f->contrato?->cliente?->primer_apellido ?? '')
        );
        $nombre = nombre_oracion($nombre);
        $sem = $f->semaforo;
        $semTip = match($sem) {
            'verde'    => 'Gestionado recientemente',
            'amarillo' => 'Hace 3–7 días sin gestión',
            'rojo'     => 'Más de 7 días sin gestión',
            default    => 'Sin gestiones registradas',
        };
        // Peso para ordenar por la columna del semáforo: primero lo más urgente.
        $riesgo = match($sem) { 'rojo' => 3, 'gris' => 2, 'amarillo' => 1, default => 0 };
        @endphp
        <tr>
            <td style="text-align:center;" data-v="{{ $riesgo }}">
                <span class="sem sem-{{ $sem }}" title="{{ $semTip }}"></span>
            </td>
            <td data-v="{{ $nombre }}">
                <div style="font-weight:700;color:#1e3a5f;">{{ $nombre ?: '—' }}</div>
            </td>
            <td style="font-family:monospace;color:#64748b;font-size:.78rem;" data-v="{{ (int)$f->cedula }}">
                {{ $f->cedula }}
            </td>
            <td style="font-size:.75rem;color:#64748b;" data-v="{{ $f->contrato?->asesor?->nombre ?? '' }}">{{ $f->contrato?->asesor?->nombre ?? '—' }}</td>
            <td data-v="{{ (int)$f->anio * 100 + (int)$f->mes }}">
                <span style="background:#dbeafe;color:#1d4ed8;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;">
                    {{ $meses[$f->mes] }} {{ $f->anio }}
                </span>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:600;" data-v="{{ (int)$f->total }}">{{ $fmt($f->total) }}</td>
            <td style="text-align:right;" class="monto-abono" data-v="{{ (int)$f->total_abonado }}">{{ $fmt($f->total_abonado) }}</td>
            <td style="text-align:right;" class="monto-deuda" data-v="{{ (int)$f->saldo_pendiente_prestamo }}">{{ $fmt($f->saldo_pendiente_prestamo) }}</td>
            <td style="font-size:.73rem;" data-v="{{ $f->ultima_gestion?->fecha_llamada?->timestamp ?? 0 }}">
                @if($f->ultima_gestion)
                    <div style="font-weight:600;color:#334155;">
                        {{ \App\Models\BitacoraCobro::RESULTADOS[$f->ultima_gestion->resultado] ?? $f->ultima_gestion->resultado }}
                    </div>
                    <div style="color:#94a3b8;font-size:.68rem;">
                        {{ $f->ultima_gestion->fecha_llamada->format('d/m/Y') }}
                    </div>
                @else
                    <span style="color:#cbd5e1;">Sin gestiones</span>
                @endif
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:.3rem;justify-content:center;flex-wrap:wrap;">
                    <a href="{{ route('admin.prestamos.show', $f->id) }}" class="btn-sm btn-ver">👁 Ver</a>
                    <button onclick="abrirAbonar({{ $f->id }}, '{{ addslashes($nombre) }}', {{ $f->saldo_pendiente_prestamo }})"
                            class="btn-sm btn-abono">💰 Abonar</button>
                    <button onclick="abrirGestion({{ $f->id }})"
                            class="btn-sm btn-gestion">📞 Gestión</button>
                </div>
            </td>
        </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr style="background:#0f172a;color:#fff;font-weight:700;">
            <td colspan="5" style="padding:.5rem .7rem;font-size:.72rem;">TOTALES ({{ $individuales->count() }} préstamos)</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;">{{ $fmt($individuales->sum('total')) }}</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;color:#86efac;">{{ $fmt($individuales->sum('total_abonado')) }}</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;color:#fca5a5;">{{ $fmt($totalDeudaInd) }}</td>
            <td colspan="2"></td>
        </tr>
        </tfoot>
    </table>
    @endif
</div>
@endif

{{-- ══ TAB EMPRESAS ══ --}}
@if($tab === 'empresas')
<div class="tbl-wrap">
    @if($empresasAgrupadas->isEmpty())
    <div class="empty-state">
        <div style="font-size:2.5rem;">✅</div>
        <div style="font-size:1rem;font-weight:700;margin-top:.5rem;color:#0f172a;">Sin préstamos de empresas pendientes</div>
    </div>
    @else
    <table class="tbl-prest">
        <thead>
            <tr>
                <th data-sort="num" title="Semáforo de gestión — ordena por riesgo"></th>
                <th data-sort="texto">Empresa</th>
                <th data-sort="num" style="text-align:center;">Préstamos</th>
                <th data-sort="num" style="text-align:right;">Total original</th>
                <th data-sort="num" style="text-align:right;">Abonado</th>
                <th data-sort="num" style="text-align:right;">Saldo deuda</th>
                <th data-sort="num">Última gestión</th>
                <th style="text-align:center;">Ver facturas</th>
            </tr>
        </thead>
        <tbody>
        @foreach($empresasAgrupadas as $grupo)
        @php
        $nombreEmp = $grupo->empresa?->empresa ?? 'Empresa #'.$grupo->empresa?->id;
        $riesgoEmp = match($grupo->semaforo) { 'rojo' => 3, 'gris' => 2, 'amarillo' => 1, default => 0 };
        @endphp
        <tr>
            <td style="text-align:center;" data-v="{{ $riesgoEmp }}">
                <span class="sem sem-{{ $grupo->semaforo }}"></span>
            </td>
            <td style="font-weight:700;color:#1e3a5f;" data-v="{{ $nombreEmp }}">
                {{ $nombreEmp }}
            </td>
            <td style="text-align:center;" data-v="{{ (int)$grupo->cant_facturas }}">
                <span style="background:#e0f2fe;color:#0369a1;padding:.15rem .5rem;border-radius:20px;font-size:.72rem;font-weight:700;">
                    {{ $grupo->cant_facturas }}
                </span>
            </td>
            <td style="text-align:right;font-family:monospace;" data-v="{{ (int)$grupo->total_original }}">{{ $fmt($grupo->total_original) }}</td>
            <td style="text-align:right;" class="monto-abono" data-v="{{ (int)$grupo->total_abonado }}">{{ $fmt($grupo->total_abonado) }}</td>
            <td style="text-align:right;" class="monto-deuda" data-v="{{ (int)$grupo->total_deuda }}">{{ $fmt($grupo->total_deuda) }}</td>
            <td style="font-size:.73rem;" data-v="{{ $grupo->ultima_gestion?->fecha_llamada?->timestamp ?? 0 }}">
                @if($grupo->ultima_gestion)
                    <div style="font-weight:600;color:#334155;">
                        {{ \App\Models\BitacoraCobro::RESULTADOS[$grupo->ultima_gestion->resultado] ?? $grupo->ultima_gestion->resultado }}
                    </div>
                    <div style="color:#94a3b8;font-size:.68rem;">{{ $grupo->ultima_gestion->fecha_llamada->format('d/m/Y') }}</div>
                @else
                    <span style="color:#cbd5e1;">Sin gestiones</span>
                @endif
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:.25rem;justify-content:center;flex-wrap:wrap;">
                @foreach($grupo->lotes as $lote)
                    <a href="{{ route('admin.prestamos.show', $lote->factura_id) }}"
                       class="btn-sm btn-ver"
                       style="font-size:.68rem;"
                       title="Factura #{{ $lote->numero_factura }} &mdash; {{ $lote->facturas->count() }} cliente(s)">
                        #{{ $lote->numero_factura }}&nbsp;{{ $meses[$lote->mes] }}/{{ $lote->anio }}
                    </a>
                @endforeach
                </div>
            </td>
        </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr style="background:#0f172a;color:#fff;font-weight:700;">
            <td colspan="3" style="padding:.5rem .7rem;font-size:.72rem;">TOTALES ({{ $empresasAgrupadas->count() }} empresas)</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;">{{ $fmt($empresasAgrupadas->sum('total_original')) }}</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;color:#86efac;">{{ $fmt($empresasAgrupadas->sum('total_abonado')) }}</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;color:#fca5a5;">{{ $fmt($totalDeudaEmp) }}</td>
            <td colspan="2"></td>
        </tr>
        </tfoot>
    </table>
    @endif
</div>
@endif

{{-- ══ TAB PAGADOS ══ --}}
@if($tab === 'pagados')
<div class="tbl-wrap">
    @if($pagados->isEmpty())
    <div class="empty-state">
        <div style="font-size:2.5rem;">📭</div>
        <div style="font-size:1rem;font-weight:700;margin-top:.5rem;color:#0f172a;">
            {{ $buscar ? 'Ningún préstamo saldado coincide con la búsqueda' : 'Todavía no hay préstamos saldados' }}
        </div>
    </div>
    @else
    <table class="tbl-prest">
        <thead>
            <tr>
                <th data-sort="texto">Tipo</th>
                <th data-sort="texto">Cliente / Empresa</th>
                <th data-sort="texto">Cédula / Clientes</th>
                <th data-sort="texto">Asesor</th>
                <th data-sort="num">Período</th>
                <th data-sort="num" style="text-align:right;">Valor del préstamo</th>
                <th data-sort="num" style="text-align:right;">Abonado</th>
                <th data-sort="num">Saldado el</th>
                <th data-sort="texto" style="text-align:center;">Cómo</th>
                <th style="text-align:center;">Acciones</th>
            </tr>
        </thead>
        <tbody>
        @foreach($pagados as $p)
        <tr>
            <td data-v="{{ $p->tipo }}">
                <span class="tag-tipo {{ $p->tipo === 'empresa' ? 'tag-emp' : 'tag-ind' }}">
                    {{ $p->tipo === 'empresa' ? '🏢 Empresa' : '👤 Individual' }}
                </span>
            </td>
            <td style="font-weight:700;color:#1e3a5f;" data-v="{{ $p->nombre }}">{{ $p->tipo === 'empresa' ? $p->nombre : nombre_oracion($p->nombre) }}</td>
            <td style="font-family:monospace;color:#64748b;font-size:.78rem;" data-v="{{ $p->detalle }}">{{ $p->detalle }}</td>
            <td style="font-size:.75rem;color:#64748b;" data-v="{{ $p->asesor ?? '' }}">{{ $p->asesor ?? '—' }}</td>
            <td data-v="{{ (int)$p->anio * 100 + (int)$p->mes }}">
                <span style="background:#dbeafe;color:#1d4ed8;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;">
                    {{ $meses[$p->mes] ?? '' }} {{ $p->anio }}
                </span>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:600;" data-v="{{ (int)$p->valor }}">{{ $fmt($p->valor) }}</td>
            <td style="text-align:right;" class="monto-pagado" data-v="{{ (int)$p->abonado }}">{{ $fmt($p->abonado) }}</td>
            <td style="font-size:.75rem;color:#334155;" data-v="{{ $p->fecha_pago?->timestamp ?? 0 }}">
                {{ $p->fecha_pago?->format('d/m/Y') ?? '—' }}
            </td>
            <td style="text-align:center;" data-v="{{ $p->condonado ? 'condonado' : 'pagado' }}">
                @if($p->condonado)
                    <span class="tag-estado tag-condonado" title="Se dio por saldado sin recibir la plata">🤝 Condonado</span>
                @else
                    <span class="tag-estado tag-pagado">✅ Pagado</span>
                @endif
            </td>
            <td style="text-align:center;">
                <a href="{{ route('admin.prestamos.show', $p->factura_id) }}" class="btn-sm btn-ver"
                   title="Factura #{{ $p->numero_factura }}">👁 Ver</a>
            </td>
        </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr style="background:#0f172a;color:#fff;font-weight:700;">
            <td colspan="5" style="padding:.5rem .7rem;font-size:.72rem;">TOTALES ({{ $pagados->count() }} préstamos saldados)</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;">{{ $fmt($pagados->sum('valor')) }}</td>
            <td style="text-align:right;padding:.5rem .7rem;font-family:monospace;color:#86efac;">{{ $fmt($pagados->sum('abonado')) }}</td>
            <td colspan="3"></td>
        </tr>
        </tfoot>
    </table>
    @endif
</div>
@endif

</div>{{-- /prest-wrap --}}

{{-- ══ MODAL ABONAR ══ --}}
<div class="modal-bg" id="modalAbonar">
<div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-title">
        <span>💰 Registrar Abono</span>
        <button class="modal-close" onclick="cerrarModal('modalAbonar')">✕</button>
    </div>
    <div id="ab-info" style="background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.55rem .85rem;margin-bottom:.85rem;font-size:.82rem;color:#15803d;font-weight:600;"></div>
    <form id="formAbonar">
        <input type="hidden" id="ab-id">
        <div class="form-grp">
            <label>Valor del abono *</label>
            <input type="number" id="ab-valor" min="1" required>
        </div>
        <div class="form-grp">
            <label>Forma de pago *</label>
            <select id="ab-forma" onchange="toggleAbonoForma()">
                <option value="efectivo">💵 Efectivo</option>
                <option value="consignacion">🏦 Consignación</option>
                <option value="mixto">🔀 Mixto</option>
            </select>
        </div>
        {{-- El desglose solo se pregunta en un pago mixto: en efectivo o
             consignación puros es el valor del abono completo, y pedirlo dos
             veces solo servía para guardar una consignación con la plata
             anotada en la casilla de efectivo. --}}
        <div id="ab-mix-row" style="display:none;">
            <div class="form-grp">
                <label>Valor efectivo *</label>
                <input type="number" id="ab-ef" min="0">
            </div>
            <div class="form-grp">
                <label>Valor consignado *</label>
                <input type="number" id="ab-cs" min="0">
                <div style="font-size:.7rem;color:#94a3b8;margin-top:.15rem;">Los dos tienen que sumar el valor del abono.</div>
            </div>
        </div>
        {{-- A qué cuenta entró la plata. Sin esto no hay forma de saber qué
             recaudo le corresponde a cada razón social, y la facturación
             electrónica —que emite lo que entra a la cuenta de la emisora— se
             queda sin ver los pagos de préstamos. --}}
        <div id="ab-banco-row" class="form-grp" style="display:none;">
            <label>🏦 Cuenta que recibió el pago *</label>
            <select id="ab-banco">
                <option value="">— Elige la cuenta —</option>
                @foreach($bancos as $b)
                    <option value="{{ $b->id }}">{{ $b->banco }} — {{ $b->nombre }} ({{ $b->numero_cuenta }})</option>
                @endforeach
            </select>
        </div>
        <div id="ab-sop-row" class="form-grp">
            <label id="ab-sop-lbl">📎 Certificado de la consignación</label>
            <input type="file" id="ab-sop" accept="image/jpeg,image/png,application/pdf">
            <div style="font-size:.7rem;color:#94a3b8;margin-top:.15rem;">JPG, PNG o PDF — máximo 10 MB.</div>
        </div>
        <div class="form-grp">
            <label>Observación</label>
            <textarea id="ab-obs" rows="2"></textarea>
        </div>
        <button type="submit" class="btn-save">💰 Registrar Abono</button>
    </form>
</div>
</div>

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
            <label>Observación — ¿Qué dijo el cliente? *</label>
            <textarea id="g-obs" rows="3" placeholder="Ej: Dijo que consigna el viernes..."></textarea>
        </div>
        <button type="submit" class="btn-save">📞 Guardar Gestión</button>
    </form>
</div>
</div>

@endsection

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
const fmt  = v => '$' + parseInt(v||0).toLocaleString('es-CO');

// ── Orden por encabezado ──────────────────────────────────────
// El listado no está paginado (llega completo del controlador), así que el
// orden se resuelve aquí sin recargar. El valor real de cada celda viene en
// data-v; el texto pintado ($ con puntos, "Enero 2026") no sirve para comparar.
function valorCelda(td, tipo) {
    if (!td) return tipo === 'texto' ? '' : 0;
    const crudo = td.dataset.v !== undefined ? td.dataset.v : td.textContent.trim();
    if (tipo === 'texto') return crudo.toLowerCase();
    if (td.dataset.v !== undefined) return parseFloat(crudo) || 0;
    return parseFloat(crudo.replace(/[^\d-]/g, '')) || 0;
}

document.querySelectorAll('table.tbl-prest').forEach(tabla => {
    const ths = [...tabla.querySelectorAll('thead th[data-sort]')];
    ths.forEach(th => {
        const col = [...th.parentNode.children].indexOf(th);
        th.classList.add('sortable');
        th.insertAdjacentHTML('beforeend', '<span class="sort-ind">⇅</span>');
        th.addEventListener('click', () => {
            const tipo = th.dataset.sort;
            // Primer clic: los textos de la A a la Z, los números de mayor a
            // menor (que es lo que se quiere ver en una cartera). Siguientes
            // clics invierten.
            const asc = th.classList.contains('sorted')
                ? th.dataset.dir !== 'asc'
                : tipo === 'texto';

            ths.forEach(o => {
                o.classList.remove('sorted');
                o.querySelector('.sort-ind').textContent = '⇅';
            });
            th.classList.add('sorted');
            th.dataset.dir = asc ? 'asc' : 'desc';
            th.querySelector('.sort-ind').textContent = asc ? '▲' : '▼';

            const tbody = tabla.tBodies[0];
            [...tbody.rows]
                .sort((a, b) => {
                    const va = valorCelda(a.cells[col], tipo);
                    const vb = valorCelda(b.cells[col], tipo);
                    if (va < vb) return asc ? -1 : 1;
                    if (va > vb) return asc ? 1 : -1;
                    return 0;
                })
                .forEach(fila => tbody.appendChild(fila));
        });
    });
});

// ── Modales ───────────────────────────────────────────────────
function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Modal Abonar ──────────────────────────────────────────────
function abrirAbonar(id, nombre, saldo) {
    document.getElementById('ab-id').value = id;
    document.getElementById('ab-info').textContent = '👤 ' + nombre + ' — Saldo pendiente: ' + fmt(saldo);
    document.getElementById('ab-valor').value = saldo;
    document.getElementById('ab-ef').value = '';
    document.getElementById('ab-cs').value = '';
    document.getElementById('ab-obs').value = '';
    document.getElementById('ab-sop').value = '';
    document.getElementById('ab-banco').value = '';
    toggleAbonoForma();
    document.getElementById('modalAbonar').classList.add('open');
}
function toggleAbonoForma() {
    const f = document.getElementById('ab-forma').value;
    document.getElementById('ab-mix-row').style.display = (f==='mixto') ? '' : 'none';
    // El certificado y la cuenta solo se exigen cuando hay plata entrando por
    // el banco: en un abono en efectivo no hay cuenta que registrar.
    const exige = (f==='consignacion'||f==='mixto');
    document.getElementById('ab-banco-row').style.display = exige ? '' : 'none';
    document.getElementById('ab-banco').required = exige;
    document.getElementById('ab-sop').required = exige;
    document.getElementById('ab-sop-lbl').textContent = exige
        ? '📎 Certificado de la consignación *'
        : '📎 Certificado / soporte (opcional)';
}
document.getElementById('formAbonar').addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('ab-id').value;
    const fd = new FormData();
    // El desglose se deduce de la forma de pago; solo el mixto lo trae escrito.
    const valor = parseInt(document.getElementById('ab-valor').value) || 0;
    const forma = document.getElementById('ab-forma').value;
    const ef = forma === 'efectivo' ? valor : (forma === 'mixto' ? (parseInt(document.getElementById('ab-ef').value) || 0) : 0);
    const cs = forma === 'consignacion' ? valor : (forma === 'mixto' ? (parseInt(document.getElementById('ab-cs').value) || 0) : 0);
    if (forma === 'mixto' && ef + cs !== valor) {
        alert('El efectivo y la consignación tienen que sumar ' + fmt(valor) + '.');
        return;
    }
    fd.append('valor',            valor);
    fd.append('forma_pago',       forma);
    fd.append('valor_efectivo',   ef);
    fd.append('valor_consignado', cs);
    fd.append('banco_cuenta_id',  document.getElementById('ab-banco').value || '');
    fd.append('observacion',      document.getElementById('ab-obs').value);
    const sop = document.getElementById('ab-sop').files[0];
    if (sop) fd.append('soporte', sop);

    const r   = await fetch(`/admin/prestamos/${id}/abonar`, {
        method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'Accept':'application/json'}, body: fd
    });
    const res = await r.json();
    if (!r.ok) {
        alert(res.message || Object.values(res.errors || {}).flat().join('\n') || 'Error al registrar el abono');
        return;
    }
    alert(res.mensaje || 'Abono registrado');
    if (res.recibo_url) window.open(res.recibo_url, '_blank');
    if (res.ok) location.reload();
});

// ── Modal Gestión ─────────────────────────────────────────────
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
        body: JSON.stringify({
            resultado:   document.getElementById('g-resultado').value,
            observacion: document.getElementById('g-obs').value,
        })
    });
    const res = await r.json();
    if (res.ok) { alert('✅ Gestión registrada.'); location.reload(); }
    else alert('Error al registrar gestión');
});
</script>
@endpush
