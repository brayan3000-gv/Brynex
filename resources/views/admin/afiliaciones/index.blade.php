@extends('layouts.app')
@section('modulo', 'Afiliaciones')

@push('styles')
<style>
/* ── Afiliaciones: layout de altura completa, solo tbody scrollea ── */
html, body {
    height: 100%;
    overflow: hidden;
}
body {
    display: flex;
    flex-direction: column;
}
.header {
    flex-shrink: 0;
}
.contenido {
    flex: 1 !important;
    min-height: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0.75rem 1rem !important;
    gap: 0.5rem;
}
/* Los flash messages no deben comprimir la tabla */
.contenido > .flash {
    flex-shrink: 0;
}
</style>
@endpush

@section('contenido')
<style>
/* ── Layout ── */
.afil-header { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:0.8rem 1.2rem;border-radius:12px;color:#fff;margin-bottom:0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;flex-shrink:0;transition:background .25s; }
/* Mirando los aliados que gestiona BryNex, el card entero se pone morado: se
   ve de un vistazo que no son solo los del aliado activo, sin gastar una
   etiqueta en decirlo. */
.afil-header.brynex { background:linear-gradient(135deg,#2e1065 0%,#6d28d9 55%,#8b5cf6 100%);box-shadow:0 2px 14px rgba(124,58,237,0.35); }
.afil-header.brynex select, .afil-header.brynex input[type=text] { background:rgba(255,255,255,0.14);border-color:rgba(255,255,255,0.35);color:#fff; }
/* Sobre el morado el gris de siempre no se leía: el texto va en blanco y la
   ayuda del buscador, en blanco translúcido. */
.afil-header.brynex input[type=text]::placeholder { color:rgba(255,255,255,0.8); }
.afil-header.brynex select option { background:#2e1065;color:#fff; }
/* Los títulos acompañan al card. */
.tbl-afil.brynex thead th { background:linear-gradient(135deg,#2e1065 0%,#4c1d95 100%); }
.tbl-afil.brynex thead th a { color:#ddd6fe; }
.tbl-afil.brynex thead th a:hover { color:#fff; }
.afil-title  { font-size:1.3rem;font-weight:800;letter-spacing:0.02em; }
.afil-sub    { font-size:0.78rem;color:#94a3b8;margin-top:0.15rem; }

/* ── Filtros ── */
.filtros { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:0.9rem 1.2rem;margin-bottom:0.8rem;display:flex;flex-wrap:wrap;gap:0.6rem;align-items:center; }
.filtros select, .filtros input { padding:0.42rem 0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;outline:none;background:#fff; }
.filtros select:focus, .filtros input:focus { border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,0.12); }
.btn-filtrar { padding:0.42rem 1rem;background:#1e40af;color:#fff;border:none;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer;transition:background .15s; }
.btn-filtrar:hover { background:#1d4ed8; }
.btn-export  { padding:0.38rem 0.6rem;background:#15803d;color:#fff;border:none;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:0.35rem; }
.filtros-sep { width:100%;height:0;border-bottom:1px dashed #e2e8f0;margin:0.2rem 0; }

/* ── Tabla ── */
.tbl-wrap { overflow-x:auto;overflow-y:auto;border-radius:12px;border:1px solid #e2e8f0;background:#fff;flex:1;min-height:0; }
.tbl-afil { width:100%;border-collapse:collapse;font-size:0.78rem;white-space:nowrap; }
.tbl-afil thead th { background:#0f172a;color:#fff;padding:0.55rem 0.6rem;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;position:sticky;top:0;z-index:2; }
.tbl-afil thead th a { color:#cbd5e1;text-decoration:none;display:flex;align-items:center;gap:0.2rem;justify-content:center; }
.tbl-afil thead th a:hover { color:#fff; }
.tbl-afil thead th a.sort-asc::after  { content:'\2191';color:#3b82f6;margin-left:0.15rem; }
.tbl-afil thead th a.sort-desc::after { content:'\2193';color:#3b82f6;margin-left:0.15rem; }
/* Select integrado en th */
.th-select { width:100%;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,0.15);color:#fff;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;padding:0.22rem 0.2rem;cursor:pointer;outline:none;appearance:auto;-webkit-appearance:auto; }
.th-sub { font-size:0.66rem;color:#fbbf24;border-bottom:none; }
/* El estado flota bajo su columna: no suma altura al encabezado ni empuja la tabla.
   Sale al hacer clic en el encabezado (foco dentro de la columna) y se queda
   a la vista mientras haya un estado elegido. */
/* Entidad y estado van en el MISMO desplegable, separados por grupos: antes el
   de estado era un segundo control que, flotando, tapaba la primera fila, y
   puesto debajo le robaba una línea al encabezado. */
.th-select optgroup { background:#0f172a;color:#94a3b8;font-style:normal;font-size:0.7rem;font-weight:700; }
.th-select:hover { border-bottom-color:rgba(255,255,255,0.5); }
.th-select:focus { border-bottom-color:#3b82f6;outline:none; }
.th-select option { background:#0f172a;color:#fff;font-weight:600;text-transform:none; }
.th-select.activo { border-bottom-color:#3b82f6;color:#93c5fd; }
.tbl-afil tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s; }
.tbl-afil tbody tr:hover { background:#f8fafc; }
.tbl-afil td { padding:0.45rem 0.55rem;vertical-align:middle; }
.razon-badge { font-weight:700;font-size:0.75rem;padding:0.2rem 0.6rem;border-radius:6px;background:#dbeafe;color:#1e40af;display:inline-block;min-width:130px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.cedula-link { color:#3b82f6;text-decoration:none;font-weight:600; }
.cedula-link:hover { text-decoration:underline; }

/* ── Badges estado ── */
.badge-estado { display:inline-flex;align-items:center;gap:0.2rem;padding:0.2rem 0.4rem;border-radius:20px;font-size:0.65rem;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid transparent;min-width:58px;justify-content:center;white-space:nowrap; }
.badge-estado:hover { transform:scale(1.05);box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.badge-inactivo { background:#f1f5f9;color:#94a3b8;border-color:#e2e8f0;cursor:default;opacity:0.5; }
/* Pendiente en naranja fuerte: lee como alerta y no se confunde con el
   traslado (naranja pálido) ni con el error (rojo). */
.badge-pendiente { background:#f97316;color:#fff;border-color:#ea580c; }
.badge-tramite   { background:#dbeafe;color:#1e40af;border-color:#93c5fd; }
.badge-traslado  { background:#fed7aa;color:#c2410c;border-color:#fb923c; }
.badge-error     { background:#fee2e2;color:#b91c1c;border-color:#fca5a5; }
.badge-ok        { background:#dcfce7;color:#15803d;border-color:#86efac; }
/* OK confirmado por la entidad (portal/API): verde fuerte. El OK a mano queda en verde claro. */
.badge-ok-confirmado { background:#15803d;color:#fff;border-color:#166534; }

.badge-programado { background:#f3e8ff;color:#6b21a8;border-color:#c084fc; }

/* Alerta días en trámite */
.alert-dias { background:#fef2f2;color:#b91c1c;border-radius:4px;padding:0.1rem 0.35rem;font-size:0.6rem;font-weight:700;margin-left:0.2rem;border:1px solid #fca5a5; }

/* Celda de ARL: el nombre se recorta si no cabe, pero el nivel siempre se ve */
.arl-celda  { display:inline-flex;align-items:center;max-width:100%;min-width:0; }
.arl-nombre { overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0; }

/* Badge redondo para el nivel de ARL */
.arl-nivel-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    flex: none;
    border-radius: 50%;
    background: #cbd5e1;
    color: #334155;
    font-size: 0.72rem;
    font-weight: 800;
    margin-left: 0.2rem;
    vertical-align: middle;
}

/* ── Factura badge ── */
.fact-badge { background:#f0fdf4;color:#15803d;font-size:0.68rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:6px;border:1px solid #86efac; }
.fact-none  { color:#cbd5e1;font-size:0.68rem; }

/* ── Botones acción ── */
.btn-docs { background:#6366f1;color:#fff;border:none;border-radius:6px;padding:0.22rem 0.5rem;font-size:0.65rem;cursor:pointer;transition:background .15s; }
.btn-docs:hover { background:#4f46e5; }
.btn-historial { background:#f97316;color:#fff;border:none;border-radius:6px;padding:0.22rem 0.5rem;font-size:0.65rem;cursor:pointer;transition:background .15s; }
.btn-historial:hover { background:#ea580c; }
.btn-enviado-ok  { color:#15803d;font-size:0.85rem; }
.btn-enviado-no  { color:#cbd5e1;font-size:0.85rem;cursor:pointer; }

/* ── Modales ── */
.modal-bg { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal-box { background:#fff;border-radius:16px;padding:1.5rem;max-width:520px;width:94%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:modalIn .2s ease; }
.modal-box.wide { max-width:680px; }
@keyframes modalIn { from{transform:translateY(-20px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-title { font-size:1rem;font-weight:800;color:#0f172a;margin-bottom:1rem;padding-bottom:0.6rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between; }
.modal-close { background:none;border:none;font-size:1.2rem;cursor:pointer;color:#94a3b8;padding:0;line-height:1; }
.modal-close:hover { color:#ef4444; }

/* ── Conciliar: el modal tiene ocho entidades y cada una su explicación,
      así que se arma como una página chica y no como un cuadro de diálogo. ── */
.ceps-box { padding:0; max-width:900px; overflow:hidden; display:flex; flex-direction:column; background:#f8fafc; }
.ceps-head {
    background:linear-gradient(135deg,#0a1628 0%,#0d2550 60%,#1e40af 100%);
    padding:1rem 1.25rem; flex-shrink:0;
}
.ceps-head-fila { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
.ceps-head h3 { font-size:0.95rem; font-weight:800; color:#fff; margin:0; }
.ceps-head .modal-close { color:rgba(255,255,255,0.65); font-size:1.15rem; }
.ceps-head .modal-close:hover { color:#fca5a5; }
.ceps-ext-btn {
    background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.35); color:#93c5fd;
    border-radius:8px; padding:0.3rem 0.7rem; font-size:0.72rem; font-weight:600;
    font-family:inherit; cursor:pointer; white-space:nowrap; transition:background .12s;
}
.ceps-ext-btn:hover { background:rgba(59,130,246,0.3); }
.ceps-ext-btn.falta { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.4); color:#fca5a5; }
.ceps-ext-btn.vieja { background:rgba(234,179,8,0.15); border-color:rgba(234,179,8,0.4); color:#fde047; }
.ceps-head label { display:block; font-size:0.68rem; font-weight:700; color:#93c5fd; margin:0.7rem 0 0.25rem; }
.ceps-head select {
    width:100%; background:rgba(255,255,255,0.08); border:1px solid rgba(59,130,246,0.35);
    color:#e2e8f0; border-radius:8px; padding:0.38rem 0.55rem; font-size:0.78rem; font-family:inherit;
}
.ceps-head select option { background:#0d2550; color:#e2e8f0; }
.ceps-head .ceps-nota { font-size:0.68rem; color:rgba(226,232,240,0.6); margin-top:0.3rem; }

.ceps-tabs { display:flex; flex-wrap:wrap; gap:0.35rem; padding:0.7rem 1.25rem 0; background:#f8fafc; }
.ceps-tab {
    padding:0.32rem 0.75rem; border-radius:7px; border:1px solid #e2e8f0; background:#fff;
    color:#475569; font-size:0.74rem; font-weight:600; font-family:inherit; cursor:pointer;
    transition:background .12s, color .12s, border-color .12s;
}
.ceps-tab:hover { border-color:var(--acento,#3b82f6); color:#1e40af; }
.ceps-tab.activo { background:var(--azul-btn,#2563eb); border-color:var(--azul-btn,#2563eb); color:#fff; }

.ceps-cuerpo { padding:0.9rem 1.25rem 1.25rem; background:#f8fafc; overflow-y:auto; flex:1; }
.ceps-panel {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:0.85rem 1rem;
    font-size:0.76rem; color:#475569; line-height:1.5; margin-bottom:0.8rem;
    box-shadow:0 2px 8px rgba(0,0,0,0.04);
}
.ceps-panel strong { color:#1e293b; }
.ceps-sub { margin-top:0.7rem; padding-top:0.65rem; border-top:1px solid #f1f5f9; }
.ceps-check { display:flex; align-items:center; gap:0.4rem; margin-top:0.5rem; font-weight:600; color:#334155; cursor:pointer; }

/* Barra de la extensión, al pie: sirve a S.O.S., Sanitas y las dos cajas. */
.ceps-ext {
    background:#0f172a; border-radius:12px; padding:0.7rem 0.9rem; margin-bottom:0.8rem;
    display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;
}
.ceps-ext-txt { flex:1; min-width:200px; font-size:0.72rem; color:rgba(226,232,240,0.75); line-height:1.4; }
.ceps-ext-txt b { color:#e2e8f0; font-size:0.78rem; }
.ceps-btn {
    border:none; border-radius:8px; padding:0.35rem 0.8rem; font-size:0.74rem; font-weight:600;
    font-family:inherit; cursor:pointer; color:#fff; text-decoration:none;
    display:inline-flex; align-items:center; gap:0.3rem; transition:filter .12s;
}
.ceps-btn:hover { filter:brightness(1.12); }
.ceps-btn.azul  { background:var(--azul-btn,#2563eb); }
.ceps-btn.gris  { background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.18); }
.ceps-btn.ghost { background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.35); color:#93c5fd; }
.ceps-pasos { margin:0.7rem 0 0; padding:0.75rem 0.9rem; background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.1); border-radius:10px; width:100%; font-size:0.73rem;
    color:rgba(226,232,240,0.8); line-height:1.65; }
.ceps-pasos ol { margin:0.35rem 0 0; padding-left:1.1rem; }
.ceps-pasos li { margin-bottom:0.3rem; }
.ceps-pasos code { background:rgba(255,255,255,0.12); padding:0.05rem 0.35rem; border-radius:4px;
    font-size:0.7rem; color:#93c5fd; }
.ceps-pasos b { color:#e2e8f0; }
.form-row { display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;margin-bottom:0.8rem; }
.form-group { display:flex;flex-direction:column;gap:0.25rem; }
.form-group label { font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.04em; }
.form-group select, .form-group textarea, .form-group input { padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;outline:none;font-family:inherit; }
.form-group select:focus, .form-group textarea:focus, .form-group input:focus { border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,0.1); }
.form-group textarea { resize:vertical;min-height:80px; }
.btn-save { background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;border:none;border-radius:10px;padding:0.6rem 1.5rem;font-size:0.88rem;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(37,99,235,0.3);transition:all .15s;width:100%; }
.btn-save:hover { transform:translateY(-1px);box-shadow:0 5px 15px rgba(37,99,235,0.4); }

/* ── Timeline bitácora ── */
.timeline { position:relative;padding-left:1.5rem; }
.timeline::before { content:'';position:absolute;left:0.5rem;top:0;bottom:0;width:2px;background:#e2e8f0; }
.tl-item { position:relative;margin-bottom:1rem; }
.tl-item::before { content:'';position:absolute;left:-1.1rem;top:0.3rem;width:10px;height:10px;border-radius:50%;border:2px solid #3b82f6;background:#fff; }
.tl-date  { font-size:0.68rem;color:#94a3b8;margin-bottom:0.15rem; }
.tl-user  { font-size:0.7rem;font-weight:700;color:#1e40af; }
.tl-obs   { font-size:0.8rem;color:#334155;margin-top:0.2rem; }
.tl-estados { display:flex;align-items:center;gap:0.4rem;margin:0.15rem 0; }
.tl-dias  { font-size:0.65rem;background:#f1f5f9;color:#64748b;padding:0.1rem 0.4rem;border-radius:4px;margin-top:0.2rem;display:inline-block; }

/* ── Documentos ── */
.doc-section { margin-bottom:1rem; }
.doc-section h4 { font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.25rem; }
.doc-item { display:flex;align-items:center;justify-content:space-between;padding:0.4rem 0.6rem;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:0.3rem;font-size:0.78rem; }
.doc-item-left { display:flex;align-items:center;gap:0.5rem; }
.doc-tipo { font-weight:600;color:#1e40af; }
.doc-arch { color:#64748b; }
.btn-dl   { background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:6px;padding:0.2rem 0.5rem;font-size:0.65rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s; }
.btn-dl:hover { background:#e0f2fe; }
.empty-docs { text-align:center;color:#94a3b8;font-size:0.8rem;padding:1rem; }

/* ── PDF upload ── */
.pdf-upload-section { margin-top:0.8rem;padding-top:0.8rem;border-top:1px dashed #e2e8f0; }
.pdf-info { font-size:0.72rem;color:#94a3b8;margin-top:0.3rem; }
.pdf-link { color:#1e40af;font-weight:600;font-size:0.75rem;text-decoration:none; }
.pdf-link:hover { text-decoration:underline; }

/* ── Enviado al cliente ── */
.enviado-section { margin-top:0.8rem;padding:0.7rem;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0; }
.enviado-section h5 { font-size:0.72rem;font-weight:700;color:#15803d;margin-bottom:0.5rem;text-transform:uppercase; }

/* ── Responsive ── */
@media(max-width:768px) {
    .form-row { grid-template-columns:1fr; }
    .filtros { flex-direction:column;align-items:stretch; }
}
</style>

@php
    $totalFuturas = 0;
    $totalErrores = 0;
    $totalPendientes = 0;
    $totalTraslados = 0;
    $totalTramites = 0;
    $totalOks = 0;

    foreach($contratos as $c) {
        $rads = $c->radicados->keyBy('tipo');
        $plan = $c->plan;
        $esFuturo = $c->fecha_ingreso && now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($c->fecha_ingreso)->startOfDay(), false) > 1;

        if ($esFuturo) {
            $totalFuturas++;
            continue;
        }

        $estados = [];

        if ($plan?->incluye_eps) {
            $r = $rads->get('eps');
            $estados[] = $r ? $r->estado : 'pendiente';
        }
        if ($plan?->incluye_arl) {
            $r = $rads->get('arl');
            $estados[] = $r ? $r->estado : 'pendiente';
        }
        if ($plan?->incluye_caja) {
            $r = $rads->get('caja');
            $estados[] = $r ? $r->estado : 'pendiente';
        }
        if ($plan?->incluye_pension) {
            $r = $rads->get('pension');
            $estados[] = $r ? $r->estado : 'pendiente';
        }

        if (in_array('error', $estados)) {
            $totalErrores++;
        } elseif (in_array('pendiente', $estados)) {
            $totalPendientes++;
        } elseif (in_array('traslado', $estados)) {
            $totalTraslados++;
        } elseif (in_array('tramite', $estados)) {
            $totalTramites++;
        } else {
            $totalOks++;
        }
    }
@endphp

{{-- ══ HEADER + FILTROS UNIFICADOS ══ --}}
<form method="GET" action="{{ route('admin.afiliaciones.index') }}" id="formFiltros" style="flex-shrink:0;">
{{-- El modo «todos los aliados que gestiona BryNex» no es un filtro visible:
     se conserva al cambiar cualquier otro. --}}
@if($gestionados)<input type="hidden" name="gestionados" value="1">@endif
<div class="afil-header {{ $gestionados ? 'brynex' : '' }}" style="flex-wrap:wrap;gap:0.5rem;padding:0.6rem 1.2rem;">
    <div style="display:flex;align-items:center;gap:0.5rem;">
        <div class="afil-title" style="white-space:nowrap;margin:0;">📋 Afiliaciones</div>
        <span style="background:rgba(255,255,255,0.15);color:#fff;font-size:0.75rem;font-weight:800;padding:0.2rem 0.55rem;border-radius:20px;white-space:nowrap;letter-spacing:0.02em;">
            [{{ $contratos->count() }}] registros
        </span>
    </div>

    <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;margin-left:auto;">
        {{-- Buscador --}}
        <div style="display:inline-flex;align-items:center;position:relative;">
            <input type="text" name="buscar" value="{{ request('buscar') }}" placeholder="🔍 Buscar..." style="font-size:0.78rem;padding:0.3rem 1.4rem 0.3rem 0.5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;width:130px;" title="Buscar por parte del nombre o número de documento">
            @if(request('buscar'))
            <a href="{{ route('admin.afiliaciones.index', request()->except(['buscar', 'page'])) }}" style="position:absolute;right:6px;color:#f87171;font-size:0.75rem;text-decoration:none;font-weight:bold;" title="Limpiar búsqueda">✕</a>
            @endif
        </div>
        <span style="color:#4b6a8b;font-size:0.9rem;">|</span>

        {{-- Período --}}
        <select name="mes" onchange="this.form.submit()" style="font-size:0.8rem;padding:0.3rem 0.5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;">
            @foreach(['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'] as $i => $m)
            @if($i) <option value="{{ $i }}" {{ $mes == $i ? 'selected' : '' }}>{{ $m }}</option> @endif
            @endforeach
        </select>
        <select name="anio" onchange="this.form.submit()" style="font-size:0.8rem;padding:0.3rem 0.5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;">
            @for($y = date('Y'); $y >= 2023; $y--)
            <option value="{{ $y }}" {{ $anio == $y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
        <span style="color:#4b6a8b;font-size:0.9rem;">|</span>

        {{-- Aliado (SOLO BryNex). Viendo los gestionados no se escoge uno: la
             lista los trae todos y el selector solo confundiría. --}}
        @if($gestionados)
        @elseif($user->es_brynex && count($alidosDisponibles) > 1)
        <select name="aliado_id" onchange="this.form.submit()" style="font-size:0.78rem;padding:0.3rem 0.5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;font-weight:700;">
            @foreach($alidosDisponibles as $al)
            <option value="{{ $al->id }}" {{ $alidoId == $al->id ? 'selected' : '' }}>{{ $al->nombre }}</option>
            @endforeach
        </select>
        <span style="color:#4b6a8b;font-size:0.9rem;">|</span>
        @endif

        {{-- Encargado --}}
        <select name="encargado_id" onchange="this.form.submit()" title="Encargado de la afiliación" style="font-size:0.78rem;padding:0.3rem 0.35rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;max-width:135px;">
            <option value="">— Todos —</option>
            @foreach($encargados as $enc)
            <option value="{{ $enc->id }}" {{ $encId == $enc->id ? 'selected' : '' }}>{{ $enc->nombre }}</option>
            @endforeach
        </select>

        {{-- Estado del radicado --}}
        <select name="estado_rad" onchange="this.form.submit()" style="font-size:0.78rem;padding:0.3rem 0.5rem;border:1px solid #334155;border-radius:6px;cursor:pointer;
            @if($estadoRad === 'pendiente')   background:#f97316;color:#fff;
            @elseif($estadoRad === 'tramite') background:#1e40af;color:#fff;
            @elseif($estadoRad === 'traslado') background:#c2410c;color:#fff;
            @elseif($estadoRad === 'error')   background:#b91c1c;color:#fff;
            @elseif($estadoRad === 'ok')      background:#15803d;color:#fff;
            @else background:#1e3a5f;color:#e2e8f0;
            @endif">
            <option value="">⚡ Estado</option>
            <option value="pendiente"  {{ $estadoRad === 'pendiente'  ? 'selected' : '' }}>⏳ Pendiente</option>
            <option value="tramite"    {{ $estadoRad === 'tramite'    ? 'selected' : '' }}>🔵 Trámite</option>
            <option value="traslado"   {{ $estadoRad === 'traslado'   ? 'selected' : '' }}>🟠 Traslado</option>
            <option value="error"      {{ $estadoRad === 'error'      ? 'selected' : '' }}>🔴 Error</option>
            <option value="ok"         {{ $estadoRad === 'ok'         ? 'selected' : '' }}>✅ OK</option>
        </select>

        {{-- El filtro de vigentes/retirados se quitó de aquí por pedido: la vista
             muestra todas las afiliaciones del mes. El parámetro sigue
             funcionando si llega por la dirección. --}}

        @if($user->es_brynex)
        {{-- Todas las afiliaciones que gestiona BryNex, de todos los aliados que
             tienen contratado el módulo «Gestión de Afiliaciones». --}}
        <a href="{{ route('admin.afiliaciones.index', $gestionados ? request()->except(['gestionados','page']) : request()->except('page') + ['gestionados' => 1]) }}"
           class="btn-export"
           style="background:{{ $gestionados ? '#7c3aed' : '#1e3a5f' }};border:1px solid {{ $gestionados ? '#a78bfa' : '#334155' }};text-decoration:none;"
           title="{{ $gestionados ? 'Volviendo al aliado activo' : 'Ver las afiliaciones de todos los aliados que gestiona BryNex' }}">🏢</a>
        @endif
        <button type="button" onclick="abrirModalClavesGlobal()" class="btn-export" style="background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1c1917;border:none;font-weight:800;cursor:pointer;">🔑 Claves</button>
        <a href="{{ route('admin.gestion-arl.index') }}" class="btn-export" style="background:#f97316;">🛡️ ARL</a>
        @can('automatizar-portales')
        <button type="button" onclick="abrirConciliacionEpsSura()" class="btn-export" style="background:#0033a0;cursor:pointer;" title="Pone al día los radicados de EPS (SURA y Nueva EPS) con lo que dicen los portales">🩺 Conciliar</button>
        <a href="{{ route('admin.afiliaciones.buzon', $gestionados ? ['gestionados' => 1] : []) }}" class="btn-export" style="background:#4338ca;text-decoration:none;" title="Respuestas de los asesores y correos de las entidades que revisa el agente del buzón">📬 Buzón</a>
        @endcan
    </div>
</div>
</form>

{{-- ══ TABLA PRINCIPAL ══ --}}
@php
    // Con la tabla vacía igual se dibujan los encabezados (y sus selectores):
    // así se puede cambiar de estado o de EPS sin volver atrás.
    $filtrosDeTabla = ['razon_social_id','tipo_modalidad_id','eps_id','arl_id','caja_id','pension_id','empresa_id','eps_estado','arl_estado','caja_estado','pension_estado','estado_rad','estado_contrato'];
    $hayFiltros = collect($filtrosDeTabla)->contains(fn ($k) => request()->filled($k));
@endphp
@php
function sortUrl($col, $currSort, $currDir) {
    $newDir = ($currSort === $col && $currDir === 'asc') ? 'desc' : 'asc';
    $q = request()->except(['sort','dir']);
    $q['sort'] = $col;
    $q['dir']  = $newDir;
    return url()->current() . '?' . http_build_query($q);
}
function sortClass($col, $currSort, $currDir) {
    if ($currSort !== $col) return '';
    return $currDir === 'asc' ? 'sort-asc' : 'sort-desc';
}
@endphp
@php
// Macro: genera el bloque select+título para cada th filtrable
// Usamos una función en blade con include sintetizado directamente
@endphp
<div class="tbl-wrap">
<table class="tbl-afil {{ $gestionados ? 'brynex' : '' }}">
    <thead>
        <tr>
            {{-- Razón Social --}}
            <th style="max-width:110px;width:110px;">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['razon_social_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="razon_social_id" onchange="this.form.submit()" class="th-select {{ $rsId ? 'activo' : '' }}" style="max-width:105px;">
                        <option value="">↓ Razón Social</option>
                        @foreach($razonesDisponibles as $rs)<option value="{{ $rs->id }}" {{ in_array((int) $rsId, $rs->ids, true) ? 'selected' : '' }}>{{ $rs->razon_social }} ({{ $rs->n }})</option>@endforeach
                    </select>
                </form>
            </th>

            {{-- Día --}}
            <th><a href="{{ sortUrl('fecha_ingreso', $sort, $dir) }}" class="{{ sortClass('fecha_ingreso', $sort, $dir) }}">Día</a></th>

            {{-- Fact. --}}
            <th>Fact.</th>

            {{-- Cédula --}}
            <th><a href="{{ sortUrl('cedula', $sort, $dir) }}" class="{{ sortClass('cedula', $sort, $dir) }}">Cédula</a></th>

            {{-- Nombres --}}
            <th>Nombres</th>

            {{-- Tipo Modalidad --}}
            <th style="white-space:nowrap;max-width:120px;">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['tipo_modalidad_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="tipo_modalidad_id" onchange="this.form.submit()" class="th-select {{ $tipoModId ? 'activo' : '' }}" style="max-width:115px;">
                        <option value="">↓ Modalidad</option>
                        @foreach($tiposModalidad as $tm)<option value="{{ $tm->id }}" {{ $tipoModId == $tm->id ? 'selected' : '' }}>{{ $tm->tipo_modalidad }} ({{ $conteoModalidad[(string) $tm->id] ?? 0 }})</option>@endforeach
                    </select>
                </form>
            </th>


            {{-- EPS --}}
            <th colspan="2">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['eps_id','eps_estado','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <input type="hidden" name="eps_id" value="{{ $epsF }}">
                    <input type="hidden" name="eps_estado" value="{{ $radColF['eps'] ?? '' }}">
                    <select onchange="filtrarColumna(this, 'eps')" class="th-select {{ ($epsF || ! empty($radColF['eps'])) ? 'activo' : '' }}" title="Filtra esta columna por entidad o por estado del radicado">
                        <option value="">↓ EPS</option>
                        <optgroup label="Entidad">@foreach($epsDisponibles as $e)<option value="ent:{{ $e->id }}" {{ $epsF == $e->id ? 'selected' : '' }}>{{ $e->nombre }} ({{ $conteoEps[(string) $e->id] ?? 0 }})</option>@endforeach</optgroup>
                        <optgroup label="Estado del radicado">@foreach(\App\Models\Radicado::todosEstados() as $val => $lbl)<option value="est:{{ $val }}" {{ (! $epsF && ($radColF['eps'] ?? '') === $val) ? 'selected' : '' }}>{{ ($radColF['eps'] ?? '') === $val ? '✓ ' : '' }}{{ $lbl }}</option>@endforeach</optgroup>
                        @if($epsF || ! empty($radColF['eps']))<optgroup label=" "><option value="nada">✖ Quitar filtro</option></optgroup>@endif
                    </select>
                </form>
            </th>

            {{-- ARL --}}
            <th colspan="2">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['arl_id','arl_estado','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <input type="hidden" name="arl_id" value="{{ $arlF }}">
                    <input type="hidden" name="arl_estado" value="{{ $radColF['arl'] ?? '' }}">
                    <select onchange="filtrarColumna(this, 'arl')" class="th-select {{ ($arlF || ! empty($radColF['arl'])) ? 'activo' : '' }}" title="Filtra esta columna por entidad o por estado del radicado">
                        <option value="">↓ ARL</option>
                        <optgroup label="Entidad">@foreach($arlDisponibles as $a)<option value="ent:{{ $a->id }}" {{ $arlF == $a->id ? 'selected' : '' }}>{{ $a->nombre_arl }} ({{ $conteoArl[(string) $a->id] ?? 0 }})</option>@endforeach</optgroup>
                        <optgroup label="Estado del radicado">@foreach(\App\Models\Radicado::todosEstados() as $val => $lbl)<option value="est:{{ $val }}" {{ (! $arlF && ($radColF['arl'] ?? '') === $val) ? 'selected' : '' }}>{{ ($radColF['arl'] ?? '') === $val ? '✓ ' : '' }}{{ $lbl }}</option>@endforeach</optgroup>
                        @if($arlF || ! empty($radColF['arl']))<optgroup label=" "><option value="nada">✖ Quitar filtro</option></optgroup>@endif
                    </select>
                </form>
            </th>

            {{-- Caja --}}
            <th colspan="2">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['caja_id','caja_estado','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <input type="hidden" name="caja_id" value="{{ $cajaF }}">
                    <input type="hidden" name="caja_estado" value="{{ $radColF['caja'] ?? '' }}">
                    <select onchange="filtrarColumna(this, 'caja')" class="th-select {{ ($cajaF || ! empty($radColF['caja'])) ? 'activo' : '' }}" title="Filtra esta columna por entidad o por estado del radicado">
                        <option value="">↓ Caja</option>
                        <optgroup label="Entidad">@foreach($cajaDisponibles as $ca)<option value="ent:{{ $ca->id }}" {{ $cajaF == $ca->id ? 'selected' : '' }}>{{ $ca->nombre }} ({{ $conteoCaja[(string) $ca->id] ?? 0 }})</option>@endforeach</optgroup>
                        <optgroup label="Estado del radicado">@foreach(\App\Models\Radicado::todosEstados() as $val => $lbl)<option value="est:{{ $val }}" {{ (! $cajaF && ($radColF['caja'] ?? '') === $val) ? 'selected' : '' }}>{{ ($radColF['caja'] ?? '') === $val ? '✓ ' : '' }}{{ $lbl }}</option>@endforeach</optgroup>
                        @if($cajaF || ! empty($radColF['caja']))<optgroup label=" "><option value="nada">✖ Quitar filtro</option></optgroup>@endif
                    </select>
                </form>
            </th>

            {{-- Pensión --}}
            <th colspan="2">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['pension_id','pension_estado','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <input type="hidden" name="pension_id" value="{{ $pensionF }}">
                    <input type="hidden" name="pension_estado" value="{{ $radColF['pension'] ?? '' }}">
                    <select onchange="filtrarColumna(this, 'pension')" class="th-select {{ ($pensionF || ! empty($radColF['pension'])) ? 'activo' : '' }}" title="Filtra esta columna por entidad o por estado del radicado">
                        <option value="">↓ Pensión</option>
                        <optgroup label="Entidad">@foreach($pensionDisponibles as $p)<option value="ent:{{ $p->id }}" {{ $pensionF == $p->id ? 'selected' : '' }}>{{ $p->razon_social }} ({{ $conteoPension[(string) $p->id] ?? 0 }})</option>@endforeach</optgroup>
                        <optgroup label="Estado del radicado">@foreach(\App\Models\Radicado::todosEstados() as $val => $lbl)<option value="est:{{ $val }}" {{ (! $pensionF && ($radColF['pension'] ?? '') === $val) ? 'selected' : '' }}>{{ ($radColF['pension'] ?? '') === $val ? '✓ ' : '' }}{{ $lbl }}</option>@endforeach</optgroup>
                        @if($pensionF || ! empty($radColF['pension']))<optgroup label=" "><option value="nada">✖ Quitar filtro</option></optgroup>@endif
                    </select>
                </form>
            </th>

            {{-- Empresa --}}
            <th style="max-width:150px;width:150px;">
                <form method="GET" action="{{ route('admin.afiliaciones.index') }}" style="margin:0;">
                    @foreach(request()->except(['empresa_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="empresa_id" onchange="this.form.submit()" class="th-select {{ $empresaF ? 'activo' : '' }}" style="max-width:145px;">
                        <option value="">↓ Empresa</option>
                        @foreach($empresasDisponibles as $emp)<option value="{{ $emp->id }}" {{ $empresaF == $emp->id ? 'selected' : '' }}>{{ (int) $emp->id === 1 ? 'Individual' : ($emp->empresa ?: "Empresa #{$emp->id}") }}</option>@endforeach
                    </select>
                </form>
            </th>

            {{-- Docs --}}
            <th>Docs</th>
        </tr>
    </thead>


    <tbody>
    @php ob_start(); @endphp
    @foreach($contratos as $c)
    @php
        $radicados         = $c->radicados->keyBy('tipo');
        $plan              = $c->plan;
        $ctxNombre         = trim(collect([$c->cliente?->primer_nombre,$c->cliente?->segundo_nombre,$c->cliente?->primer_apellido,$c->cliente?->segundo_apellido])->filter()->implode(' '));
        $ctxNombre = nombre_oracion($ctxNombre);
        $ctxNombreCorto    = trim($c->cliente?->primer_nombre . ' ' . $c->cliente?->primer_apellido);
        $ctxNombreCorto = nombre_oracion($ctxNombreCorto);
        $ctxRazonSocial    = $c->razonSocial?->razon_social ?? '—';
        $ctxNit            = $c->razonSocial?->nit ?? '—';
        $ctxTipoModalidad  = $c->tipo_modalidad_label ?? ($c->es_dependiente ? 'Dependiente' : 'Independiente');
        $ctxEmpresaCliente = $c->aliado?->nombre ?? '—';
        $ctxCedula         = $c->cedula ?? '—';
        $ctxTipoDoc        = strtoupper(trim($c->cliente?->tipo_doc ?? ''));
        $ctxArl            = $c->arl_efectiva_nombre ?? ($c->cliente?->arl?->nombre_arl ?? '—');
        $ctxPension        = $c->pension?->razon_social ?? ($c->cliente?->pension?->razon_social ?? '—');
        $ctxEps            = $c->eps?->nombre ?? ($c->cliente?->eps?->nombre ?? '—');
        $ctxCaja           = $c->caja?->nombre ?? '';
        $ctxSalario        = $c->salario ?? '';
        $ctxFechaIngreso   = ((int)$c->tipo_modalidad_id === 15)
            ? ($c->fecha_arl ? $c->fecha_arl->format('d/m/Y') : '—')
            : ($c->fecha_ingreso ? $c->fecha_ingreso->format('d/m/Y') : '—');
        $ctxCargo          = $c->cargo ?? '';
        $ctxDireccion      = $c->cliente?->direccion_vivienda ?? '';
        $ctxBarrio         = $c->cliente?->barrio ?? '';
        $ctxCiudadNombre   = $c->cliente?->municipio?->nombre ?? '';
        $ctxDeptNombre     = $c->cliente?->municipio?->departamento?->nombre ?? '';
        $ctxCiudad         = $ctxCiudadNombre ? ($ctxDeptNombre ? $ctxCiudadNombre . ' - ' . $ctxDeptNombre : $ctxCiudadNombre) : '';
        $ctxCelular        = $c->cliente?->celular ?? '';
        $ctxCorreo         = $c->cliente?->correo ?? '';
        $contexto          = json_encode([
            'id'              => $c->id,
            'nombre'          => $ctxNombreCorto,
            'nombre_completo' => $ctxNombre,
            'razon_social'    => $ctxRazonSocial,
            'nit'             => $ctxNit,
            'tipo_modalidad'  => $ctxTipoModalidad,
            'empresa_cliente' => $ctxEmpresaCliente,
            'cedula'          => $ctxCedula,
            'tipo_doc'        => $ctxTipoDoc,
            'arl'             => $ctxArl,
            'pension'         => $ctxPension,
            'eps'             => $ctxEps,
            'caja'            => $ctxCaja,
            'salario'         => $ctxSalario,
            'fecha_ingreso'   => $ctxFechaIngreso,
            'fecha_arl'       => $c->fecha_arl?->toDateString(),
            'cargo'           => $ctxCargo,
            'direccion'       => $ctxDireccion,
            'barrio'          => $ctxBarrio,
            'ciudad'          => $ctxCiudad,
            'celular'         => $ctxCelular,
            'correo'          => $ctxCorreo,
        ]);
        $esFuturo = $c->fecha_ingreso && now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($c->fecha_ingreso)->startOfDay(), false) > 1;
        $esRetirado = $c->estado === 'retirado';
    @endphp
    {{-- El contexto del contrato va una sola vez aquí, en la fila, y no en cada
         botón de radicado: eran hasta cuatro copias idénticas por fila, el 26% de
         la página (859 KB en el aliado 7). El JS lo busca en el <tr>. --}}
    <tr data-ctx='{{ $contexto }}' @if($esRetirado) style="background:#fef2f2;" title="Contrato retirado — la afiliación sí ocurrió en este período" @endif>
        {{-- Empresa --}}
        <td>
            @if($gestionados)
            {{-- De qué aliado es la fila. El logo va delante de la razón social
                 para no robarle alto a la fila con otro renglón. --}}
            @if($c->aliado?->logo)
            <img src="{{ asset('storage/'.$c->aliado->logo) }}" alt="{{ $c->aliado->nombre }}" title="{{ $c->aliado->nombre }}"
                 style="height:17px;width:17px;object-fit:contain;border-radius:4px;background:#fff;vertical-align:middle;margin-right:.2rem;">
            @else
            <span title="{{ $c->aliado?->nombre }}"
                  style="display:inline-block;width:17px;height:17px;line-height:17px;text-align:center;border-radius:4px;background:#ede9fe;color:#7c3aed;font-size:.62rem;font-weight:800;vertical-align:middle;margin-right:.2rem;">{{ mb_strtoupper(mb_substr($c->aliado?->nombre ?? '?', 0, 1)) }}</span>
            @endif
            @endif
            @if($c->razonSocial)
            <span class="razon-badge razon-badge-link"
                  title="Ver claves de {{ $c->razonSocial->razon_social }}"
                  onclick="abrirClavesRS({{ $c->razonSocial->id }}, '{{ addslashes($c->razonSocial->razon_social) }}', {eps: {{ (int) $c->eps_id }}, arl: {{ (int) $c->arl_id }}, caja: {{ (int) $c->caja_id }}})"
                  style="cursor:pointer;">
                {{ $c->razonSocial->razon_social }}
            </span>
            @else
            <span class="razon-badge">—</span>
            @endif
        </td>

        {{-- Día ingreso --}}
        <td style="text-align:center;font-weight:700;color:#1e40af;">
            @if((int)$c->tipo_modalidad_id === 15)
                {{ $c->fecha_arl?->format('d') ?? '—' }}
            @else
                {{ $c->fecha_ingreso?->format('d') ?? '—' }}
            @endif
        </td>

        {{-- Factura --}}
        <td style="text-align:center;">
            @if($c->numero_factura_mes)
            <span class="fact-badge">{{ $c->numero_factura_mes }}</span>
            @else
            <span class="fact-none">–</span>
            @endif
        </td>

        {{-- Cédula --}}
        <td>
            <button type="button"
                class="btn-afil-contrato"
                data-contrato-id="{{ $c->id }}"
                data-nombre="{{ $ctxNombre }}"
                data-row-id="{{ $c->id }}"
                @if($gestionados) data-aliado-id="{{ $c->aliado_id }}" @endif
                title="{{ trim($ctxTipoDoc.' '.$ctxCedula) }} · clic para abrir contrato"
                style="background:none;border:none;padding:0;font-family:monospace;font-size:.77rem;font-weight:700;color:#3b82f6;cursor:pointer;text-decoration:underline dotted;">
                @if($ctxTipoDoc)<span style="color:#94a3b8;font-weight:600;">{{ $ctxTipoDoc }}</span> @endif{{ $c->cedula }}
            </button>
        </td>

        {{-- Nombres --}}
        <td style="font-weight:600;color:#1e3a5f;max-width:130px;overflow:hidden;text-overflow:ellipsis;" title="{{ nombre_oracion($c->cliente?->primer_nombre) }} {{ nombre_oracion($c->cliente?->segundo_nombre) }} {{ nombre_oracion($c->cliente?->primer_apellido) }} {{ nombre_oracion($c->cliente?->segundo_apellido) }}">
            @if($c->cliente?->id)
            <button type="button"
                class="btn-afil-cliente"
                data-cliente-id="{{ $c->cliente->id }}"
                data-nombre="{{ $ctxNombre }}"
                data-row-id="{{ $c->id }}"
                @if($gestionados) data-aliado-id="{{ $c->aliado_id }}" @endif
                title="Clic para editar cliente"
                style="background:none;border:none;padding:0;font:inherit;font-weight:600;color:#1e3a5f;cursor:pointer;text-decoration:underline dotted;text-align:left;max-width:128px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">
                {{ nombre_oracion($c->cliente?->primer_nombre) }} {{ nombre_oracion($c->cliente?->primer_apellido) }}
            </button>
            @else
            {{ nombre_oracion($c->cliente?->primer_nombre) }} {{ nombre_oracion($c->cliente?->primer_apellido) }}
            @endif
            @if($esRetirado)
            <span style="display:inline-block;background:#fee2e2;color:#b91c1c;font-size:0.6rem;font-weight:800;padding:0.05rem 0.3rem;border-radius:4px;letter-spacing:0.02em;margin-top:0.1rem;"
                  title="Retirado{{ $c->fecha_retiro ? ' el ' . $c->fecha_retiro->format('d/m/Y') : '' }}">RETIRADO</span>
            @endif
        </td>

        {{-- Tipo Modalidad --}}
        <td style="font-size:0.68rem;color:#475569;white-space:nowrap;" title="{{ $c->tipoModalidad?->nombre ?? '' }}">
            <button type="button"
                class="btn-afil-contrato"
                data-contrato-id="{{ $c->id }}"
                data-nombre="{{ $ctxNombre }}"
                data-row-id="{{ $c->id }}"
                @if($gestionados) data-aliado-id="{{ $c->aliado_id }}" @endif
                title="Clic para editar contrato"
                style="background:#f1f5f9;color:#334155;padding:0.12rem 0.4rem;border-radius:5px;font-weight:700;font-size:0.67rem;border:none;cursor:pointer;transition:background .15s;"
                onmouseover="this.style.background='#dbeafe';this.style.color='#1e40af'"
                onmouseout="this.style.background='#f1f5f9';this.style.color='#334155'"
                id="modal-label-{{ $c->id }}">
                {{ $c->tipoModalidad?->tipo_modalidad ?? '—' }}
            </button>
        </td>


        {{-- EPS --}}
        @php $rEps = $radicados->get('eps'); @endphp
        <td style="font-size:0.7rem;color:#475569;max-width:60px;overflow:hidden;text-overflow:ellipsis;padding-right:0;" title="{{ $c->eps?->nombre }}">{{ $plan?->incluye_eps ? ($c->eps?->nombre ?? '[Ninguna]') : '—' }}</td>
        <td style="padding-left:2px;">
            @if($plan?->incluye_eps && $rEps)
            <button class="badge-estado badge-{{ $rEps->estadoClaseEfectiva() }} btn-rad"
                @if($rEps->esConfirmadoPorEntidad()) title="{{ $rEps->textoConfirmacion() }}" @endif
                data-rad-id="{{ $rEps->id }}"
                data-contrato-id="{{ $c->id }}"
                data-eps-formulario="{{ $c->eps?->formulario_pdf ? '1' : '0' }}"
                data-rad='{{ json_encode(['id'=>$rEps->id,'tipo'=>$rEps->tipo,'estado'=>$rEps->estado,'numero_radicado'=>$rEps->numero_radicado,'canal_envio'=>$rEps->canal_envio,'canal_envio_cliente'=>$rEps->canal_envio_cliente,'enviado_al_cliente'=>$rEps->enviado_al_cliente,'ruta_pdf'=>$rEps->ruta_pdf,'observacion'=>$rEps->observacion]) }}'>
                {{ $rEps->estadoTextoEfectivo() }}
                @if($rEps->tieneAlertaDias())<span class="alert-dias">{{ $rEps->diasEnEstado() }}d</span>@endif
            </button>
            @elseif($plan?->incluye_eps)
                @if($esFuturo)
                <span class="badge-estado badge-programado">📅 F</span>
                @else
                <button class="badge-estado badge-pendiente btn-rad-crear"
                    data-contrato-id="{{ $c->id }}" data-tipo="eps"
                    data-eps-formulario="{{ $c->eps?->formulario_pdf ? '1' : '0' }}"
                    title="Sin radicado registrado — clic para abrir el trámite">⏳ P</button>
                @endif
            @else
            <span class="badge-estado badge-inactivo">–</span>
            @endif
        </td>

        {{-- ARL — efectiva según razón social --}}
        @php $rArl = $radicados->get('arl'); @endphp
        <td style="font-size:0.7rem;color:#475569;max-width:130px;padding-right:0;white-space:nowrap;" title="{{ $c->arl_efectiva_nombre }}{{ $c->n_arl ? " ({$c->n_arl})" : '' }}">
            @if($plan?->incluye_arl)
                <span class="arl-celda">
                    <span class="arl-nombre">{{ $c->arl_efectiva_nombre }}</span>
                    @if($c->n_arl)
                        <span class="arl-nivel-badge" title="Nivel de Riesgo {{ $c->n_arl }}">{{ $c->n_arl }}</span>
                    @endif
                </span>
            @else
                —
            @endif
        </td>
        <td style="padding-left:2px;">
            @if($plan?->incluye_arl && $rArl)
            <button class="badge-estado badge-{{ $rArl->estadoClaseEfectiva() }} btn-rad"
                @if($rArl->esConfirmadoPorEntidad()) title="{{ $rArl->textoConfirmacion() }}" @endif
                data-rad-id="{{ $rArl->id }}"
                data-rad='{{ json_encode(['id'=>$rArl->id,'tipo'=>$rArl->tipo,'estado'=>$rArl->estado,'numero_radicado'=>$rArl->numero_radicado,'canal_envio'=>$rArl->canal_envio,'canal_envio_cliente'=>$rArl->canal_envio_cliente,'enviado_al_cliente'=>$rArl->enviado_al_cliente,'ruta_pdf'=>$rArl->ruta_pdf,'observacion'=>$rArl->observacion]) }}'>
                {{ $rArl->estadoTextoEfectivo() }}
                @if($rArl->tieneAlertaDias())<span class="alert-dias">{{ $rArl->diasEnEstado() }}d</span>@endif
            </button>
            @elseif($plan?->incluye_arl)
                @if($esFuturo)
                <span class="badge-estado badge-programado">📅 F</span>
                @else
                <button class="badge-estado badge-pendiente btn-rad-crear"
                    data-contrato-id="{{ $c->id }}" data-tipo="arl"
                    title="Sin radicado registrado — clic para abrir el trámite">⏳ P</button>
                @endif
            @else
            <span class="badge-estado badge-inactivo">–</span>
            @endif
        </td>

        {{-- Caja --}}
        @php $rCaja = $radicados->get('caja'); @endphp
        <td style="font-size:0.7rem;color:#475569;max-width:60px;overflow:hidden;text-overflow:ellipsis;padding-right:0;" title="{{ $c->caja?->nombre }}">{{ $plan?->incluye_caja ? ($c->caja?->nombre ?? '[Ninguna]') : '—' }}</td>
        <td style="padding-left:2px;">
            @if($plan?->incluye_caja && $rCaja)
            <button class="badge-estado badge-{{ $rCaja->estadoClaseEfectiva() }} btn-rad"
                @if($rCaja->esConfirmadoPorEntidad()) title="{{ $rCaja->textoConfirmacion() }}" @endif
                data-rad-id="{{ $rCaja->id }}"
                data-rad='{{ json_encode(['id'=>$rCaja->id,'tipo'=>$rCaja->tipo,'estado'=>$rCaja->estado,'numero_radicado'=>$rCaja->numero_radicado,'canal_envio'=>$rCaja->canal_envio,'canal_envio_cliente'=>$rCaja->canal_envio_cliente,'enviado_al_cliente'=>$rCaja->enviado_al_cliente,'ruta_pdf'=>$rCaja->ruta_pdf,'observacion'=>$rCaja->observacion]) }}'>
                {{ $rCaja->estadoTextoEfectivo() }}
                @if($rCaja->tieneAlertaDias())<span class="alert-dias">{{ $rCaja->diasEnEstado() }}d</span>@endif
            </button>
            @elseif($plan?->incluye_caja)
                @if($esFuturo)
                <span class="badge-estado badge-programado">📅 F</span>
                @else
                <button class="badge-estado badge-pendiente btn-rad-crear"
                    data-contrato-id="{{ $c->id }}" data-tipo="caja"
                    title="Sin radicado registrado — clic para abrir el trámite">⏳ P</button>
                @endif
            @else
            <span class="badge-estado badge-inactivo">–</span>
            @endif
        </td>

        {{-- Pensón --}}
        @php $rPen = $radicados->get('pension'); @endphp
        <td style="font-size:0.7rem;color:#475569;max-width:60px;overflow:hidden;text-overflow:ellipsis;padding-right:0;" title="{{ $c->pension?->razon_social }}">{{ $plan?->incluye_pension ? ($c->pension?->razon_social ?? '[Ninguna]') : '—' }}</td>
        <td style="padding-left:2px;">
            @if($plan?->incluye_pension && $rPen)
            <button class="badge-estado badge-{{ $rPen->estadoClaseEfectiva() }} btn-rad"
                @if($rPen->esConfirmadoPorEntidad()) title="{{ $rPen->textoConfirmacion() }}" @endif
                data-rad-id="{{ $rPen->id }}"
                data-contrato-id="{{ $c->id }}"
                data-pension-formulario="{{ $c->pension?->formulario_pdf ? '1' : '0' }}"
                data-rad='{{ json_encode(['id'=>$rPen->id,'tipo'=>$rPen->tipo,'estado'=>$rPen->estado,'numero_radicado'=>$rPen->numero_radicado,'canal_envio'=>$rPen->canal_envio,'canal_envio_cliente'=>$rPen->canal_envio_cliente,'enviado_al_cliente'=>$rPen->enviado_al_cliente,'ruta_pdf'=>$rPen->ruta_pdf,'observacion'=>$rPen->observacion]) }}'>
                {{ $rPen->estadoTextoEfectivo() }}
                @if($rPen->tieneAlertaDias())<span class="alert-dias">{{ $rPen->diasEnEstado() }}d</span>@endif
            </button>
            @elseif($plan?->incluye_pension)
                @if($esFuturo)
                <span class="badge-estado badge-programado">📅 F</span>
                @else
                <button class="badge-estado badge-pendiente btn-rad-crear"
                    data-contrato-id="{{ $c->id }}" data-tipo="pension"
                    data-pension-formulario="{{ $c->pension?->formulario_pdf ? '1' : '0' }}"
                    title="Sin radicado registrado — clic para abrir el trámite">⏳ P</button>
                @endif
            @else
            <span class="badge-estado badge-inactivo">–</span>
            @endif
        </td>

        {{-- Empresa --}}
        @php
            $labelEmpresa = $c->cliente?->empresa?->label ?? $c->cliente?->empresa?->empresa ?? '—';
        @endphp
        <td style="font-size:0.65rem;color:#475569;max-width:145px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $labelEmpresa }}">
            {{ $labelEmpresa ?: '—' }}
        </td>

        {{-- Docs --}}
        <td style="text-align:center;white-space:nowrap;">
            @php $radIdDocs = $rEps?->id ?? $rArl?->id ?? $rCaja?->id ?? $rPen?->id ?? 0; @endphp
            <button class="btn-docs btn-docs-open"
                data-rad-id="{{ $radIdDocs }}"
                data-cedula="{{ $c->cedula }}"
                data-aliado-id="{{ $c->aliado_id }}"
                data-nombre="{{ nombre_oracion($c->cliente?->primer_nombre) }} {{ nombre_oracion($c->cliente?->primer_apellido) }}"
                title="Ver/subir documentos">📁</button>
            <button class="btn-historial"
                onclick="abrirHistorialAfiliacion({{ $c->id }})"
                title="Ver historial completo de la afiliación"
                style="background:#f97316;color:#fff;border:none;border-radius:6px;padding:0.22rem 0.5rem;font-size:0.65rem;cursor:pointer;transition:background .15s;margin-left:0.2rem;">📜</button>
        </td>
    </tr>
    @endforeach
    @php
        // La sangría del Blade viajaba entera en cada fila: 22% de la página.
        // Solo se colapsa el espacio ENTRE etiquetas (nunca dentro de atributos
        // ni de textos), que el navegador de todos modos reduce a uno. En estas
        // filas no hay <pre>, <textarea> ni white-space: pre, donde sí importaría.
        echo preg_replace('/>\s+</', ">\n<", ob_get_clean());
    @endphp
    @if($contratos->isEmpty())
    <tr><td colspan="13" style="text-align:center;padding:2.5rem 1rem;color:#94a3b8;background:#fff;">
        <div style="font-size:2.2rem;">📋</div>
        <div style="font-size:1rem;font-weight:600;margin-top:0.4rem;">{{ $hayFiltros ? 'Ningún contrato cumple los filtros' : 'Sin contratos para este período' }}</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">{{ $hayFiltros ? 'Prueba con otro estado en los títulos de la tabla o quita los filtros.' : 'No hay ingresos en el mes/año seleccionado.' }}</div>
        @if($hayFiltros)
        <a href="{{ route('admin.afiliaciones.index', request()->except(array_merge($filtrosDeTabla, ['page']))) }}"
           style="display:inline-block;margin-top:0.9rem;padding:0.4rem 1rem;background:#1e3a5f;color:#fff;border-radius:8px;font-size:0.8rem;font-weight:700;text-decoration:none;">✖ Quitar filtros</a>
        @endif
    </td></tr>
    @endif
    </tbody>
</table>
</div>
<div style="margin-top:0.15rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;flex-shrink:0;">
    {{-- Card resumen de estados horizontal y compacto (abajo a la izquierda) --}}
    <div style="display:flex;align-items:center;gap:0.3rem;background:#f8fafc;padding:0.2rem 0.5rem;border-radius:8px;border:1px solid #e2e8f0;flex-wrap:wrap;">
        <span style="color:#6b21a8;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#f3e8ff;border:1px solid #c084fc;" title="Afiliaciones Futuras">📅 Futuras: <strong>{{ $totalFuturas }}</strong></span>
        <span style="color:#b91c1c;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#fee2e2;border:1px solid #fca5a5;" title="Radicados con Error">🔴 Errores: <strong>{{ $totalErrores }}</strong></span>
        <span style="color:#fff;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#f97316;border:1px solid #ea580c;" title="Radicados Pendientes">⏳ Pendientes: <strong>{{ $totalPendientes }}</strong></span>
        <span style="color:#c2410c;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#fed7aa;border:1px solid #fb923c;" title="Radicados en Traslado">🔄 Traslados: <strong>{{ $totalTraslados }}</strong></span>
        <span style="color:#1e40af;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#dbeafe;border:1px solid #93c5fd;" title="Radicados en Trámite">🔵 Trámite: <strong>{{ $totalTramites }}</strong></span>
        <span style="color:#15803d;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:4px;background:#dcfce7;border:1px solid #86efac;" title="Radicados OK">✅ OK: <strong>{{ $totalOks }}</strong></span>
    </div>

    <a href="{{ route('admin.afiliaciones.exportar', request()->query()) }}" class="btn-export" style="background:#15803d;padding:0.35rem 1rem;font-size:0.8rem;display:inline-flex;align-items:center;gap:0.4rem;border-radius:8px;text-decoration:none;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(21,128,61,0.2);transition:all 0.15s;" onmouseover="this.style.transform='scale(1.02)';this.style.boxShadow='0 4px 12px rgba(21,128,61,0.3)';" onmouseout="this.style.transform='none';this.style.boxShadow='0 2px 8px rgba(21,128,61,0.2)';">
        📥 Descargar Excel de Afiliaciones
    </a>
</div>

{{-- ══ MODAL GESTIÓN RADICADO ══ --}}
<div class="modal-bg" id="modalRadicado">
    <div class="modal-box">
        <div class="modal-title">
            <span id="mrad-titulo">📝 Gestionar Radicado</span>
            <button class="modal-close" onclick="cerrarModal('modalRadicado')">✕</button>
        </div>

        {{-- Contexto: cotizante y empresa --}}
        <div style="background:#f0f9ff;border-radius:8px;padding:0.5rem 0.75rem;margin-bottom:0.75rem;display:flex;gap:0.8rem;flex-wrap:wrap;font-size:0.75rem;">
            <div><span style="color:#64748b;">👤</span> <strong id="mrad-cotizante"></strong></div>
            <div style="border-left:1px solid #bae6fd;padding-left:0.8rem;"><span style="color:#64748b;">Razón Social:</span> <strong id="mrad-empresa"></strong></div>
            <div style="border-left:1px solid #bae6fd;padding-left:0.8rem;"><span style="color:#64748b;">Modalidad:</span> <span id="mrad-modalidad" style="font-weight:600;"></span></div>
            <div style="border-left:1px solid #bae6fd;padding-left:0.8rem;"><span style="color:#64748b;">Empresa cliente:</span> <strong id="mrad-empresa-cliente"></strong></div>
        </div>

        {{-- Alerta Fecha Futura --}}
        <div id="mrad-alerta-futura" style="display:none; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:0.5rem 0.75rem; margin-bottom:0.75rem; color:#b45309; font-size:0.75rem; font-weight:600; align-items:center; gap:0.4rem;">
            ⚠️ <span id="mrad-alerta-futura-texto">Advertencia: La fecha de ingreso de este contrato es futura.</span>
        </div>

        {{-- Info del radicado --}}
        <div style="display:flex;gap:0.6rem;margin-bottom:0.8rem;flex-wrap:wrap;align-items:center;">
            <span style="font-size:0.75rem;background:#f1f5f9;padding:0.2rem 0.6rem;border-radius:6px;font-weight:600;" id="mrad-tipo"></span>
            <span style="font-size:0.75rem;background:#f0f9ff;padding:0.2rem 0.6rem;border-radius:6px;" id="mrad-num-rad"></span>
            {{-- Botones: Formulario PDF + Ver Datos (siempre juntos a la derecha) --}}
            <div id="seccionFormularioEps" style="display:flex;margin-left:auto;align-items:center;gap:0.4rem;">
                <a id="btnFormularioPdf"
                   href="#" target="_blank"
                   style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.7rem;background:#7c3aed;color:#fff;border-radius:7px;font-size:0.75rem;font-weight:700;text-decoration:none;">
                    📄 Formulario
                </a>
                {{-- Solo para radicados de ARL Sura: afilia por API y deja este
                     mismo radicado en OK con el certificado adjunto. --}}
                <button id="btnAfiliarApi" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#047857,#10b981);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(16,185,129,0.3);"
                    onclick="afiliarApiDesdeRadicado()">
                    🚀 Afiliar por API
                </button>
                {{-- Radicados de EPS en Nueva EPS: reingreso por el portal --}}
                <button id="btnReingresoNuevaEps" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#be123c,#e11d48);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(225,29,72,0.3);"
                    onclick="reingresoNuevaEpsDesdeRadicado()">
                    🏥 Reingreso Nueva EPS
                </button>
                {{-- Radicados de EPS en SURA: reingreso por el portal de empleadores --}}
                <button id="btnReingresoEpsSura" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#0033a0,#2563eb);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(37,99,235,0.3);"
                    onclick="reingresoEpsSuraDesdeRadicado()">
                    🏥 Reingreso EPS SURA
                </button>
                {{-- Radicados de EPS en Salud Total: novedad de inicio laboral por el portal --}}
                <button id="btnNovedadSaludTotal" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#15803d,#22c55e);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(34,197,94,0.3);"
                    onclick="novedadSaludTotalDesdeRadicado()">
                    🏥 Novedad Salud Total
                </button>
                {{-- Radicados de EPS en S.O.S.: novedad de inicio laboral (login con captcha asistido) --}}
                <button id="btnNovedadSos" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(37,99,235,0.3);"
                    onclick="novedadSosDesdeRadicado()">
                    🏥 Novedad S.O.S.
                </button>
                {{-- Radicados de EPS en Sanitas: cambio de empleador por el formulario web --}}
                <button id="btnNovedadSanitas" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(8,145,178,0.3);"
                    onclick="novedadSanitasDesdeRadicado()">
                    🏥 Radicar Sanitas
                </button>
                {{-- Caja Comfenalco Valle: afiliación por su Sucursal Virtual con la extensión --}}
                <button id="btnCajaComfenalco" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#047857,#059669);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(5,150,105,0.3);"
                    onclick="cajaComfenalcoDesdeRadicado()">
                    🏢 Afiliar a la caja
                </button>
                {{-- Caja Comfandi: afiliación por su Sucursal Virtual Empresas con la extensión --}}
                <button id="btnCajaComfandi" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(37,99,235,0.3);"
                    onclick="cajaComfandiDesdeRadicado()">
                    🏢 Afiliar a Comfandi
                </button>
                {{-- Portal Boxalud (Emssanar): Ingreso de afiliación con la extensión --}}
                <button id="btnBoxalud" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#4d7c0f,#65a30d);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(101,163,13,0.3);"
                    onclick="boxaludDesdeRadicado()">
                    🏥 Radicar en portal
                </button>
                {{-- EPS sin portal de empleador (Comfenalco Valle): afiliación por correo al asesor --}}
                <button id="btnCorreoEps" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#15803d,#16a34a);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(22,163,74,0.3);"
                    onclick="correoEpsDesdeRadicado()">
                    📧 Afiliar por correo
                </button>
                {{-- ARL Colmena: afiliación por su API, y anulación mientras esté en plazo --}}
                <button id="btnAfiliarColmena" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#b45309,#f59e0b);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(245,158,11,0.3);"
                    onclick="afiliarColmenaDesdeRadicado()">
                    🐝 Afiliar en Colmena
                </button>
                <button id="btnAnularColmena" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:#fef3c7;color:#b45309;border:1px solid #fcd34d;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;"
                    onclick="anularColmenaDesdeRadicado()">
                    ↩️ Anular ingreso Colmena
                </button>
                {{-- Cuando ya está afiliado: deshacer, solo dentro de los 30 días --}}
                <button id="btnAnularApi" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;"
                    onclick="anularApiDesdeRadicado()">
                    ↩️ Anular afiliación
                </button>
                <button id="btnVerDatosCotizante" type="button"
                    style="display:none;align-items:center;gap:0.35rem;padding:0.3rem 0.85rem;background:linear-gradient(135deg,#0f172a,#1e40af);color:#fff;border:none;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(30,64,175,0.25);"
                    onclick="abrirVerDatos(this._ctx, this._tipo)">
                    📋 Ver Datos
                </button>
            </div>
        </div>

        <form id="formRadicado" onsubmit="guardarRadicado(event)">
            <input type="hidden" id="mrad-id">

            <div class="form-row">
                <div class="form-group">
                    <label>Estado *</label>
                    <select id="mrad-estado" onchange="actualizarColorEstadoSelect(this)" style="font-weight:700;border-radius:8px;transition:background .2s,color .2s;">
                        <option value="pendiente">⏳ Pendiente</option>
                        <option value="tramite">🔵 En Trámite</option>
                        <option value="traslado">🔄 Traslado</option>
                        <option value="error">❌ Error</option>
                        <option value="ok">✅ OK - Finalizado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Canal de trámite</label>
                    <select id="mrad-canal">
                        <option value="">— Sin especificar —</option>
                        <option value="portal">🌐 Portal</option>
                        <option value="correo">📧 Correo</option>
                        <option value="asesor">👤 Asesor</option>
                        <option value="presencial">🏢 Presencial</option>
                        <option value="otro">📌 Otro</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0.8rem;" id="seccionNumRadicado">
                <label>N° Radicado (asignado por la entidad)</label>
                <input type="text" id="mrad-numero" placeholder="Ej: EPS-2026-001234">
            </div>

            <div class="form-group" style="margin-bottom:0.8rem;">
                <label>Observación</label>
                <textarea id="mrad-observacion" placeholder="Describe la acción realizada. Ej: Se envió correo al asesor de EPS, esperando respuesta..."></textarea>
            </div>

            {{-- Sección PDF (solo si estado = ok o trámite).
                 Con documento cargado se muestra una tarjeta y el subidor queda
                 plegado: reemplazarlo es la excepción, no lo habitual. --}}
            <div class="pdf-upload-section" id="seccionPdf" style="display:none;">
                <div id="pdfActual"></div>

                <div id="pdfSubir">
                    <div style="font-size:0.75rem;font-weight:700;color:#15803d;margin-bottom:0.4rem;">📎 PDF del Radicado</div>
                    <input type="file" id="mrad-pdf" accept=".pdf"
                           style="border:1px dashed #cbd5e1;border-radius:8px;padding:0.4rem;width:100%;font-size:0.78rem;">
                    <div class="pdf-info" style="margin-top:0.25rem;">Solo PDF, máximo 3MB.</div>
                </div>
            </div>

            {{-- Sección enviado al cliente --}}
            <div class="enviado-section" id="seccionEnviado">
                <h5>📤 Envío al Cliente</h5>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    {{-- Checkbox --}}
                    <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.78rem;font-weight:600;color:#15803d;cursor:pointer;white-space:nowrap;flex-shrink:0;">
                        <input type="checkbox" id="mrad-enviado" style="width:15px;height:15px;cursor:pointer;accent-color:#15803d;">
                        Radicado enviado al cliente
                    </label>
                    {{-- Canal --}}
                    <div style="display:flex;align-items:center;gap:0.5rem;flex:1;min-width:160px;">
                        <span style="font-size:0.7rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;">Canal</span>
                        <select id="mrad-canal-cliente" style="flex:1;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:7px;font-size:0.82rem;">
                            <option value="">— —</option>
                            <option value="correo">📧 Correo</option>
                            <option value="whatsapp">💬 WhatsApp</option>
                            <option value="fisica">📄 Física</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <button type="submit" class="btn-save" id="btnGuardarRadicado">💾 Guardar Cambios</button>
            </div>
        </form>

        {{-- Bitácora rápida (últimos 3 movimientos) --}}
        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #f1f5f9;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;">Últimos movimientos</span>
                <button onclick="abrirBitacora()" style="background:none;border:none;color:#3b82f6;font-size:0.72rem;cursor:pointer;font-weight:600;">Ver todos →</button>
            </div>
            <div id="minibitacora" style="font-size:0.75rem;color:#64748b;">Cargando...</div>
        </div>
    </div>
</div>

{{-- ══ MODAL VER DATOS DEL COTIZANTE ══ --}}
<div class="modal-bg" id="modalVerDatos">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-title">
            <span id="mvd-titulo">📋 Datos del Cotizante</span>
            <button class="modal-close" onclick="cerrarModal('modalVerDatos')">✕</button>
        </div>
        <div id="mvd-contenido" style="font-size:0.82rem;line-height:1.8;">
            {{-- Se llena por JS --}}
        </div>
        <div style="margin-top:1rem;display:flex;gap:0.6rem;justify-content:flex-end;">
            <button onclick="cerrarModal('modalVerDatos')" style="padding:0.4rem 1rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#475569;font-size:0.82rem;font-weight:500;cursor:pointer;">Cerrar</button>
            <button id="btnCopiarDatos" onclick="copiarDatosCotizante()" style="padding:0.4rem 1.1rem;background:linear-gradient(135deg,#0f172a,#1e40af);color:#fff;border:none;border-radius:8px;font-size:0.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:0.4rem;">📋 Copiar</button>
        </div>
    </div>
</div>

{{-- ══ MODAL BITÁCORA COMPLETA ══ --}}
<div class="modal-bg" id="modalBitacora">
    <div class="modal-box wide">
        <div class="modal-title">
            <span>📜 Bitácora de Radicado — <span id="mbit-tipo"></span></span>
            <button class="modal-close" onclick="cerrarModal('modalBitacora')">✕</button>
        </div>
        <div id="mbit-resumen" style="background:#f8fafc;border-radius:8px;padding:0.6rem;margin-bottom:0.8rem;font-size:0.78rem;"></div>
        <div class="timeline" id="mbit-timeline">Cargando...</div>
    </div>
</div>

{{-- ══ MODAL HISTORIAL COMPLETO DE LA AFILIACIÓN ══ --}}
<div class="modal-bg" id="modalHistorialAfiliacion">
    <div class="modal-box wide">
        <div class="modal-title">
            <span>📜 Historial de Afiliación — <span id="mhist-cotizante"></span></span>
            <button class="modal-close" onclick="cerrarModal('modalHistorialAfiliacion')">✕</button>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:0.6rem 0.8rem;margin-bottom:0.8rem;font-size:0.78rem;">
            <strong>Cédula:</strong> <span id="mhist-cedula"></span>
        </div>
        <div class="timeline" id="mhist-timeline" style="max-height: 60vh; overflow-y: auto;">Cargando...</div>
    </div>
</div>

{{-- ══ MODAL DOCUMENTOS ══ --}}
<div class="modal-bg" id="modalDocs">
    <div class="modal-box wide">
        <div class="modal-title">
            <span>📁 Documentos de <span id="mdocs-nombre"></span></span>
            <button class="modal-close" onclick="cerrarModal('modalDocs')">✕</button>
        </div>
        <div id="mdocs-content">Cargando...</div>

        {{-- Subir nuevo documento --}}
        <div style="margin-top:1rem;padding-top:0.8rem;border-top:1px dashed #e2e8f0;">
            <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:0.5rem;">📎 Subir Documento</div>
            <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;">
                <div style="flex:1;min-width:130px;">
                    <label style="font-size:0.68rem;color:#64748b;display:block;margin-bottom:0.2rem;">¿Para quién?</label>
                    <select id="mdocs-para" style="width:100%;padding:0.42rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;">
                        <option value="">Cotizante</option>
                        {{-- Opciones de beneficiarios se llenan dinámicamente --}}
                    </select>
                </div>
                <div style="flex:1;min-width:130px;">
                    <label style="font-size:0.68rem;color:#64748b;display:block;margin-bottom:0.2rem;">Tipo de documento</label>
                    <select id="mdocs-tipo" style="width:100%;padding:0.42rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;">
                        <option value="cedula">Cédula</option>
                        <option value="carta_laboral">Carta Laboral</option>
                        <option value="registro_civil">Registro Civil</option>
                        <option value="tarjeta_identidad">Tarjeta Identidad</option>
                        <option value="decl_juramentada">Decl. Juramentada</option>
                        <option value="acta_matrimonio">Acta Matrimonio</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
                <div style="flex:2;min-width:160px;">
                    <label style="font-size:0.68rem;color:#64748b;display:block;margin-bottom:0.2rem;">Archivo</label>
                    <input type="file" id="mdocs-archivo" accept=".pdf,.jpg,.jpeg,.png,.webp"
                        style="width:100%;padding:0.35rem 0.5rem;border:1px dashed #cbd5e1;border-radius:8px;font-size:0.75rem;">
                </div>
                <button id="btnSubirDoc" onclick="subirDocumento()"
                    style="padding:0.42rem 1rem;background:#1e40af;color:#fff;border:none;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer;white-space:nowrap;align-self:flex-end;">⬆ Subir</button>
            </div>
            <div style="font-size:0.68rem;color:#94a3b8;margin-top:0.25rem;">PDF, JPG o PNG · máx. 3MB</div>
        </div>
    </div>
</div>

{{-- ══ MODAL CONCILIACIÓN EPS SURA ══ --}}
<div class="modal-bg" id="modalConciliacionEps">
    <div class="modal-box ceps-box">
        <div class="ceps-head">
            <div class="ceps-head-fila">
                <h3 id="ceps-titulo">🩺 Conciliar radicados con el portal</h3>
                <div style="display:flex;align-items:center;gap:0.6rem;">
                    {{-- La extensión estorba en pantalla cuando ya está puesta: aquí solo
                         queda el botón, que avisa con su color si falta o está vieja. --}}
                    <button type="button" id="ceps-ext-btn" class="ceps-ext-btn" onclick="verTarjetaExtension()">
                        🧩 Extensión
                    </button>
                    <button class="modal-close" onclick="cerrarModal('modalConciliacionEps')">✕</button>
                </div>
            </div>

            <label for="ceps-razon">Razón social a validar ({{ $alidoActivo->nombre ?? 'aliado activo' }})</label>
            <select id="ceps-razon" onchange="cambiarRazonConciliacion()">
                <option value="">— Todas las que encuentre el portal —</option>
                @foreach($razonesConciliar as $rs)
                    <option value="{{ $rs->nit }}" data-nombre="{{ $rs->razon_social }}">{{ $rs->razon_social }} — NIT {{ $rs->nit }}</option>
                @endforeach
            </select>
            <div id="ceps-razon-aviso" class="ceps-nota">
                Escoge con cuál empresa vas a conciliar: BryNex comprueba que la sesión abierta en el portal sea esa.
            </div>
        </div>

        {{-- Con ocho entidades ya no caben en una línea del modal. --}}
        <div class="ceps-tabs">
            <button type="button" class="ceps-tab" data-entidad="sura" onclick="elegirEntidadConciliacion('sura')">EPS SURA</button>
            <button type="button" class="ceps-tab" data-entidad="nueva_eps" onclick="elegirEntidadConciliacion('nueva_eps')">Nueva EPS</button>
            <button type="button" class="ceps-tab" data-entidad="salud_total" onclick="elegirEntidadConciliacion('salud_total')">Salud Total</button>
            <button type="button" class="ceps-tab" data-entidad="sos" onclick="elegirEntidadConciliacion('sos')">S.O.S.</button>
            <button type="button" class="ceps-tab" data-entidad="sanitas" onclick="elegirEntidadConciliacion('sanitas')">Sanitas</button>
            <button type="button" class="ceps-tab" data-entidad="caja_comfenalco" onclick="elegirEntidadConciliacion('caja_comfenalco')">Caja Comfenalco</button>
            <button type="button" class="ceps-tab" data-entidad="caja_comfandi" onclick="elegirEntidadConciliacion('caja_comfandi')">Caja Comfandi</button>
            <button type="button" class="ceps-tab" data-entidad="pension" onclick="elegirEntidadConciliacion('pension')">Pensión (RUAF)</button>
        </div>

        <div class="ceps-cuerpo">
        {{-- Extensión BryNex Portales: S.O.S., Sanitas y las dos cajas no se pueden
             conciliar sin ella, así que su estado va a la vista en el mismo modal. --}}
        <div class="ceps-ext" id="ceps-ext-card" style="display:none;">
            <div class="ceps-ext-txt">
                <b>🧩 Extensión BryNex Portales</b>
                <div id="ceps-ext-estado">Comprobando si está instalada en este navegador…</div>
            </div>
            <a href="{{ route('admin.afiliaciones.extension-portales') }}" class="ceps-btn azul">⬇️ Descargar</a>
            <button type="button" class="ceps-btn gris" onclick="recargarExtensionPortales()"
                    title="Cuando BryNex publica una versión nueva, esto la pone al día sin ir a chrome://extensions">
                🔄 Actualizar
            </button>
            <button type="button" class="ceps-btn ghost" onclick="verPasosExtension()">❓ Cómo se instala</button>

            <div class="ceps-pasos" id="ceps-ext-pasos">
                Chrome solo instala de un clic lo que está en su tienda, y esta extensión no puede estar ahí:
                opera los portales con <b>la sesión que abre la persona</b>. Se instala a mano, una vez por equipo,
                y son dos minutos.
                <ol>
                    <li><b>Descarga</b> el archivo con el botón de arriba y <b>descomprímelo</b>
                        (clic derecho → Extraer todo). Queda una carpeta <code>brynex-portales</code>.</li>
                    <li>Déjala en un sitio fijo del equipo —por ejemplo <code>Documentos</code>—:
                        si la borras o la mueves, Chrome apaga la extensión.</li>
                    <li>Abre <code>chrome://extensions</code> (cópialo en la barra de direcciones).</li>
                    <li>Arriba a la derecha, activa el <b>Modo de desarrollador</b>.</li>
                    <li>Pulsa <b>Cargar descomprimida</b> y elige la carpeta <code>brynex-portales</code>.</li>
                    <li>Vuelve a BryNex y <b>recarga la página</b>. Aquí arriba debe decir «instalada».</li>
                </ol>
                Para <b>actualizarla</b> no repitas todo: basta el botón <b>🔄 Actualizar</b>.
                Si BryNex cambió archivos de la extensión, descarga otra vez, reemplaza la carpeta
                con la nueva y pulsa <b>Actualizar</b>.
            </div>
        </div>

        <div id="ceps-descripcion-sura" class="ceps-panel">
            Consulta en el portal de empleadores de EPS SURA los radicados de EPS <strong>pendientes, en trámite o con error</strong>
            de contratos vigentes de dependientes. Si Sura confirma que ya es cotizante con derecho a cobertura en esa empresa
            y el apellido coincide, el radicado pasa a <strong>OK</strong>. En Sura solo se consulta: no se afilia a nadie.
            <br>Tarda alrededor de un minuto por empresa.
        </div>
        <div id="ceps-descripcion-nueva_eps" class="ceps-panel" style="display:none;">
            Busca en Nueva EPS los reingresos de cada empresa y pone al día los radicados de EPS <strong>pendientes, en trámite o con error</strong>:
            si Nueva EPS ya lo <strong>procesó</strong> pasa a <strong>OK</strong>; si solo está <strong>radicado</strong> queda en trámite con su número.
            En ambos casos se adjunta el certificado del portal. Los que no tienen reingreso se tramitan desde el radicado (🏥 Reingreso Nueva EPS).
            <br>Tarda alrededor de un minuto por empresa.
        </div>
        <div id="ceps-descripcion-salud_total" class="ceps-panel" style="display:none;">
            Busca en el seguimiento de novedades de inicio laboral de Salud Total y pone al día los radicados de EPS <strong>pendientes, en trámite o con error</strong>:
            <strong>aprobada</strong> pasa a <strong>OK</strong> con el certificado; <strong>en validación</strong> queda en trámite con el número de formulario y el PDF;
            con <strong>inconsistencias</strong> queda en error con el motivo. Si no hay novedad pero ya está activo con la empresa, también pasa a OK.
            Los que faltan se tramitan desde el radicado (🏥 Novedad Salud Total).
            <br>Tarda unos segundos por empresa.
        </div>

        <div id="ceps-descripcion-sos" class="ceps-panel" style="display:none;">
            Busca en <strong>Novedades → Consultas y Envío/Firma</strong> de S.O.S., con la sesión abierta en este navegador
            (extensión BryNex Portales), solo a la gente que tiene el radicado de EPS <strong>abierto</strong>:
            <strong>Aprobado</strong> pasa a <strong>OK</strong>; lo que sigue en trámite queda con su número;
            lo que S.O.S. <strong>devolvió</strong> queda en error <strong>y abre una tarea con el motivo del portal</strong>
            —es el hallazgo que nadie ve, porque una devolución no avisa y la persona se queda sin EPS—.
            A quien no tenga ninguna novedad se le marca que falta radicarla.
            <br><strong>Escoge la empresa</strong>: el portal solo muestra las novedades de la que tiene la sesión abierta.
            <div id="ceps-sos-peticion" style="display:none;margin-top:0.5rem;background:#fffbeb;border:1px solid #fcd34d;border-radius:7px;padding:0.4rem 0.6rem;color:#92400e;"></div>
            <div id="ceps-sos-sesion" style="margin-top:0.45rem;"></div>
        </div>

        <div id="ceps-descripcion-sanitas" class="ceps-panel" style="display:none;">
            Baja el <strong>Estado de Afiliación</strong> de la Oficina Virtual de Empleadores de Sanitas con la sesión abierta en este navegador
            (extensión BryNex Portales) y lo cruza con los radicados de EPS de esa empresa: quien está <strong>HABILITADO</strong> y coincide el apellido
            pasa a <strong>OK confirmado</strong>; quien no aparece <strong>falta radicar</strong> (cambio de empleador). También lista a los habilitados
            en Sanitas que BryNex no tiene como contrato vigente con Sanitas.
            <div id="ceps-sanitas-sesion" style="margin-top:0.45rem;"></div>
        </div>

        <div id="ceps-descripcion-caja_comfenalco" class="ceps-panel" style="display:none;">
            Baja la lista de <strong>Trabajadores por Empresa</strong> de la Sucursal Virtual de la caja Comfenalco Valle con la sesión
            abierta en este navegador (extensión BryNex Portales) y la cruza con los <strong>radicados de caja</strong> de esa empresa:
            quien ya aparece afiliado y coincide el apellido pasa a <strong>OK confirmado</strong>; quien no aparece <strong>falta afiliar</strong>
            (🏢 Afiliar a la caja). También lista a los afiliados de la caja que BryNex no tiene como contrato vigente con esa caja.
            <div id="ceps-caja-sesion" style="margin-top:0.45rem;"></div>
            <div style="margin-top:0.5rem;">
                <button type="button" onclick="bajarFamiliasComfenalco()" class="btn-export" style="background:#0f766e;cursor:pointer;">
                    👪 Bajar beneficiarios del grupo familiar
                </button>
                <span style="font-size:0.7rem;color:#64748b;margin-left:0.4rem;">
                    Consulta el grupo familiar de cada trabajador, uno por uno, y guarda en BryNex los que falten. Tarda un rato.
                </span>
                <div id="ceps-caja-familias" style="font-size:0.72rem;color:#475569;margin-top:0.3rem;"></div>
            </div>

            <div class="ceps-sub">
                <strong>💰 Subsidios retenidos.</strong> Pregunta a Comfenalco por los trabajadores
                <strong>morosos y con inexactitud</strong> de la empresa y abre una <strong>tarea</strong> por cada uno,
                que se cierra sola cuando la caja deja de reportarlo. Aquí basta una consulta para toda la empresa,
                así que es cuestión de segundos. Va en la misma pasada de los botones de abajo.
                <div style="margin-top:0.5rem;display:flex;gap:0.4rem;flex-wrap:wrap;">
                    <button type="button" onclick="revisarSubsidiosComfenalco(true)" class="btn-export" style="background:#64748b;cursor:pointer;">
                        🔎 Solo consultar
                    </button>
                    <button type="button" onclick="revisarSubsidiosComfenalco(false)" class="btn-export" style="background:#b45309;cursor:pointer;">
                        💰 Revisar subsidios y crear tareas
                    </button>
                </div>
                <div id="ceps-caja-subsidios-estado" style="margin-top:0.4rem;font-weight:700;color:#92400e;"></div>
            </div>
        </div>

        <div id="ceps-descripcion-caja_comfandi" class="ceps-panel" style="display:none;">
            Pide al portal de Comfandi el <strong>Listado de trabajadores</strong> en Excel —que trae la empresa completa y
            <strong>los beneficiarios de cada trabajador</strong>, y que se guardan en BryNex— y lee la pestaña <strong>Radicados</strong>,
            con la sesión abierta en este navegador (extensión BryNex Portales). Los cruza con los <strong>radicados de caja</strong> de esa empresa:
            quien ya aparece afiliado y coincide el apellido pasa a <strong>OK confirmado</strong>. A los que todavía no aparecen los explica
            el radicado del portal: <strong>en proceso</strong> queda en trámite con su número, <strong>rechazado</strong> se marca para revisar
            el motivo antes de volver a radicar, y sin radicado <strong>falta afiliar</strong> (🏢 Afiliar a Comfandi).
            También lista a los afiliados de la caja que BryNex no tiene como contrato vigente con Comfandi.
            <div id="ceps-comfandi-sesion" style="margin-top:0.45rem;"></div>

            <div class="ceps-sub">
                <strong>💰 Subsidios bloqueados.</strong> Consulta en el portal, trabajador por trabajador, los
                <strong>bloqueos de subsidio monetario</strong> de esta empresa y abre una <strong>tarea</strong> por cada uno:
                de tipo <em>Subsidios</em> cuando la caja espera los aportes, y de <em>Solicitud de documentos</em> cuando pide
                el certificado escolar o papeles del beneficiario. La tarea queda con el encargado del contrato y
                <strong>se cierra sola</strong> cuando la caja deja de reportar el bloqueo.
                Va en la misma pasada de los botones de abajo: con <strong>Solo consultar</strong> se ve qué tareas se abrirían
                y con <strong>Actualizar radicados y tareas</strong> se crean. Se revisan los sospechosos del día —pago con mora,
                tarea abierta o afiliado nuevo—, y una vez al mes conviene marcar el barrido completo.
                Tarda unos 10 segundos por persona.
                <label class="ceps-check">
                    <input type="checkbox" id="ceps-comfandi-subsidios-todos">
                    Revisar a todos los afiliados de la empresa, no solo a los sospechosos (barrido mensual)
                </label>
                <div id="ceps-comfandi-subsidios-estado" style="margin-top:0.4rem;font-weight:700;color:#92400e;"></div>
            </div>
        </div>

        <div id="ceps-descripcion-pension" class="ceps-panel" style="display:none;">
            En pensión el vínculo es <strong>persona ↔ fondo</strong>, no persona ↔ empresa: el empleador solo cotiza. Por eso no hay
            portal de empleador y la fuente oficial es el <strong>RUAF</strong>, que se consulta por el operador de planilla
            (el mismo de la consulta de clientes). Si el RUAF confirma que la persona está en el <strong>mismo fondo</strong> del contrato,
            el radicado pasa a <strong>OK confirmado</strong>; si figura en <strong>otro fondo</strong> queda para revisar el traslado
            —se está cotizando al fondo equivocado—; y si <strong>no tiene fondo</strong>, falta tramitar la vinculación.
            <label class="ceps-check">
                <input type="checkbox" id="ceps-pension-ok" checked>
                Revisar también los que ya están en OK (detecta traslados de fondo)
            </label>
            <div style="margin-top:0.3rem;color:#64748b;">Tarda unos 2 segundos por persona: con toda la cartera vigente, unos minutos.</div>
        </div>

        <div id="ceps-acciones" style="display:flex;gap:0.5rem;margin-bottom:0.8rem;">
            <button type="button" onclick="iniciarConciliacionEpsSura(true)" class="btn-export" style="background:#475569;cursor:pointer;">🔎 Solo consultar</button>
            <button type="button" onclick="iniciarConciliacionEpsSura(false)" class="btn-export" style="background:var(--azul-btn,#2563eb);cursor:pointer;">✅ Consultar y actualizar radicados</button>
        </div>

        <div id="ceps-estado" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:0.55rem 0.75rem;font-size:0.8rem;color:#0c4a6e;margin-bottom:0.8rem;"></div>

        <div id="ceps-resumen" style="display:none;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.6rem;font-size:0.75rem;"></div>

        <div style="overflow-x:auto;">
            <table id="ceps-tabla" style="display:none;width:100%;border-collapse:collapse;font-size:0.74rem;">
                <thead>
                    <tr style="background:#f8fafc;color:#475569;text-align:left;">
                        <th style="padding:0.35rem 0.5rem;">Cédula</th>
                        <th style="padding:0.35rem 0.5rem;">Nombre</th>
                        <th style="padding:0.35rem 0.5rem;">Empresa</th>
                        <th style="padding:0.35rem 0.5rem;">Resultado</th>
                        <th style="padding:0.35rem 0.5rem;">Detalle</th>
                    </tr>
                </thead>
                <tbody id="ceps-filas"></tbody>
            </table>
        </div>
        <div id="ceps-sobran" style="display:none;margin-top:0.8rem;font-size:0.74rem;"></div>
        </div>
    </div>
</div>

{{-- ══ DRAWER CLAVES RAZÓN SOCIAL ══ --}}
<div id="rs-claves-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:1050;backdrop-filter:blur(2px);"
     onclick="cerrarClavesRS()"></div>

<div id="rs-claves-panel"
     style="display:none;position:fixed;top:0;right:0;width:860px;max-width:97vw;height:100vh;
            background:#f8fafc;box-shadow:-8px 0 32px rgba(0,0,0,0.18);z-index:1051;
            flex-direction:column;transform:translateX(100%);transition:transform 0.28s cubic-bezier(.4,0,0.2,1);">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1.4rem;">🔑</span>
            <div>
                <div style="font-size:0.95rem;font-weight:800;color:#1c1917;">Claves y Accesos</div>
                <div id="rs-claves-subtitulo" style="font-size:0.72rem;color:rgba(28,25,23,0.7);font-weight:500;">Razón Social</div>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <button onclick="abrirModalClaveRS()" id="rs-ca-btn-nueva"
                    style="display:inline-flex;align-items:center;gap:0.35rem;background:#fff;color:#92400e;
                           border:none;border-radius:8px;padding:0.4rem 0.9rem;font-size:0.8rem;font-weight:700;cursor:pointer;
                           box-shadow:0 2px 6px rgba(0,0,0,0.15);transition:background 0.15s;"
                    onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='#fff'">
                ➕ Nueva Clave
            </button>
            <button onclick="cerrarClavesRS()"
                    style="background:rgba(255,255,255,0.2);border:none;border-radius:8px;width:34px;height:34px;color:#1c1917;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700;">✕</button>
        </div>
    </div>

    {{-- Notif --}}
    <div id="rs-claves-notif" style="display:none;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;"></div>

    {{-- Loading --}}
    <div id="rs-claves-loading" style="display:none;text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">⏳ Cargando claves...</div>

    {{-- Tabla --}}
    <div style="flex:1;overflow-y:auto;padding:1rem 1.25rem;" id="rs-claves-body">
        {{-- Portales de EPS, ARL y caja de la empresa, con la del contrato
             resaltada: lo mismo que la pestaña de la razón social. --}}
        <div style="font-size:0.85rem;font-weight:800;color:#1c1917;margin-bottom:0.5rem;">🏥 Portales de entidades</div>
        <div id="rs-portales" style="margin-bottom:1.4rem;"></div>

        <div style="font-size:0.85rem;font-weight:800;color:#1c1917;margin-bottom:0.5rem;">🗂️ Todas las claves de esta razón social</div>
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead>
                <tr style="background:#fef9c3;border-bottom:2px solid #fde68a;">
                    <th class="ca-th">Tipo</th>
                    <th class="ca-th">Entidad / Portal</th>
                    <th class="ca-th">Usuario</th>
                    <th class="ca-th">Contraseña</th>
                    <th class="ca-th" style="text-align:center;">Link</th>
                    <th class="ca-th">Correo</th>
                    <th class="ca-th">Observación</th>
                    <th class="ca-th" style="text-align:center;">Estado</th>
                    <th class="ca-th" style="text-align:center;">Acciones</th>
                </tr>
            </thead>
            <tbody id="rs-claves-tbody">
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">Selecciona una razón social.</td></tr>
            </tbody>
        </table>
    </div>
</div>

@include('admin.partials._portales_entidades')

{{-- ═══ MODAL: Crear / Editar Clave Razón Social ═══════════════════════════════ --}}
<div id="rs-ca-modal-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1100;
            align-items:center;justify-content:center;backdrop-filter:blur(2px);"
     onclick="if(event.target===this) cerrarModalClaveRS()">
    <div style="background:#fff;border-radius:16px;padding:0;width:560px;max-width:96vw;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
        {{-- Modal header --}}
        <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:0.9rem;font-weight:800;color:#1c1917;" id="rs-ca-modal-titulo">🔑 Nueva Clave</div>
            <button onclick="cerrarModalClaveRS()" style="background:rgba(255,255,255,0.25);border:none;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:0.9rem;font-weight:700;color:#1c1917;">✕</button>
        </div>
        {{-- Modal body --}}
        <div style="padding:1.25rem;">
            <input type="hidden" id="rs-ca-modal-id">
            <input type="hidden" id="rs-ca-modal-rs-id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                {{-- Tipo --}}
                <div>
                    <label class="ca-lbl">Tipo *</label>
                    <select id="rs-ca-f-tipo" class="ca-inp">
                        <option value="Portal">Portal Web</option>
                        <option value="Correo">Correo Electrónico</option>
                        <option value="EPS">EPS</option>
                        <option value="ARL">ARL</option>
                        <option value="AFP">AFP / Pensión</option>
                        <option value="CAJA">Caja de Compensación</option>
                        <option value="DIAN">DIAN</option>
                        <option value="MinTrabajo">Min. Trabajo (PILA)</option>
                        <option value="Banco">Banco / Entidad Financiera</option>
                        <option value="Operadores">Operadores</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                {{-- Entidad --}}
                <div>
                    <label class="ca-lbl">Entidad / Portal *</label>
                    <input type="text" id="rs-ca-f-entidad" class="ca-inp" placeholder="Ej: Portal EPS Sura, Gmail...">
                </div>
                {{-- Usuario --}}
                <div>
                    <label class="ca-lbl">Usuario / Login</label>
                    <input type="text" id="rs-ca-f-usuario" class="ca-inp" placeholder="Nombre de usuario o email">
                </div>
                {{-- Contraseña --}}
                <div>
                    <label class="ca-lbl">Contraseña</label>
                    <div style="display:flex;gap:0.3rem;align-items:center;">
                        <input type="password" id="rs-ca-f-contrasena" class="ca-inp" placeholder="••••••••" style="flex:1;">
                        <button type="button" onclick="togglePassClaveRS()"
                                style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:0.35rem 0.5rem;cursor:pointer;font-size:0.8rem;flex-shrink:0;"
                                title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                {{-- Link --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Link / URL de acceso</label>
                    <input type="text" id="rs-ca-f-link" class="ca-inp" placeholder="https://...">
                </div>
                {{-- Correo entidad --}}
                <div>
                    <label class="ca-lbl">Correo de la entidad</label>
                    <input type="text" id="rs-ca-f-correo" class="ca-inp" placeholder="contacto@entidad.com">
                </div>
                {{-- Activo --}}
                <div style="display:flex;align-items:center;gap:0.5rem;padding-top:1.2rem;">
                    <input type="checkbox" id="rs-ca-f-activo" style="width:16px;height:16px;cursor:pointer;" checked>
                    <label for="rs-ca-f-activo" style="font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">Activo</label>
                </div>
                {{-- Observación --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Observación</label>
                    <textarea id="rs-ca-f-obs" class="ca-inp" rows="2" placeholder="Notas adicionales..." style="resize:vertical;"></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1rem;">
                <button onclick="cerrarModalClaveRS()"
                        style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.82rem;font-weight:500;cursor:pointer;">
                    Cancelar
                </button>
                <button onclick="guardarClaveRS()"
                        style="padding:0.45rem 1.2rem;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:8px;
                               color:#1c1917;font-size:0.84rem;font-weight:800;cursor:pointer;box-shadow:0 2px 8px rgba(245,158,11,0.4);">
                    💾 Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.razon-badge-link:hover { background:#bfdbfe !important; color:#1e3a8a !important; box-shadow:0 2px 8px rgba(37,99,235,0.15); transition:all .15s; }
.ca-th { padding:0.5rem 0.65rem;font-size:0.72rem;font-weight:700;color:#92400e;white-space:nowrap;text-align:left; }
.ca-td { padding:0.38rem 0.65rem;color:#1c1917;vertical-align:middle; }
.ca-lbl { display:block;font-size:0.7rem;font-weight:700;color:#475569;margin-bottom:0.18rem;text-transform:uppercase;letter-spacing:0.02em; }
.ca-inp { width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#0f172a;box-sizing:border-box; }
.ca-inp:focus { outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,0.2); }
</style>

{{-- ═══ Modal iframe: Cliente ═══ --}}
<div id="modalClienteOverlay" style="
    display:none; position:fixed; inset:0; z-index:3000;
    background:rgba(10,10,20,.7); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; padding:.75rem;
" onclick="if(event.target===this)cerrarModalCliente()">
    <div style="
        background:#fff; border-radius:16px; width:min(1140px,97vw);
        height:94vh; display:flex; flex-direction:column;
        box-shadow:0 32px 100px rgba(0,0,0,.5); overflow:hidden;
    ">
        <div style="background:linear-gradient(135deg,#1e3a5f 0%,#1e40af 100%);padding:.65rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;">👤</span>
                <div>
                    <div style="font-size:.9rem;font-weight:800;color:#fff;" id="iframeClienteTitulo">Cliente</div>
                    <div style="font-size:.62rem;color:rgba(255,255,255,.5);">Editar datos personales del cotizante</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <a id="iframeClienteLink" href="#" target="_blank"
                   style="font-size:.72rem;font-weight:600;color:rgba(255,255,255,.6);text-decoration:none;padding:.3rem .7rem;border:1px solid rgba(255,255,255,.2);border-radius:6px;transition:all .15s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.6)'">
                   &#x2197; Abrir pestaña
                </a>
                <button onclick="cerrarModalCliente()" style="width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);font-size:1rem;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='rgba(255,255,255,.22)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">✕</button>
            </div>
        </div>
        <div style="position:relative;flex:1;overflow:hidden;">
            <div id="iframeClienteLoading" style="position:absolute;inset:0;background:#f8fafc;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;z-index:10;">
                <div style="width:44px;height:44px;border-radius:50%;border:4px solid #e2e8f0;border-top-color:#3b82f6;animation:spinIframe .7s linear infinite;"></div>
                <div style="font-size:.82rem;color:#64748b;font-weight:600;">Cargando cliente...</div>
            </div>
            <iframe id="iframeCliente" src=""
                style="width:100%;height:100%;border:none;display:block;"
                onload="document.getElementById('iframeClienteLoading').style.display='none'">
            </iframe>
        </div>
    </div>
</div>

{{-- ═══ Modal iframe: Contrato ═══ --}}
<div id="modalContratoOverlay" style="
    display:none; position:fixed; inset:0; z-index:3000;
    background:rgba(10,10,20,.7); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; padding:.75rem;
" onclick="if(event.target===this)cerrarModalContrato()">
    <div style="
        background:#fff; border-radius:16px; width:min(1180px,97vw);
        height:94vh; display:flex; flex-direction:column;
        box-shadow:0 32px 100px rgba(0,0,0,.5); overflow:hidden;
    ">
        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:.65rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;">📋</span>
                <div>
                    <div style="font-size:.9rem;font-weight:800;color:#fff;" id="iframeContratoTitulo">Contrato</div>
                    <div style="font-size:.62rem;color:rgba(255,255,255,.5);">Editar datos del contrato y modalidad</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <a id="iframeContratoLink" href="#" target="_blank"
                   style="font-size:.72rem;font-weight:600;color:rgba(255,255,255,.6);text-decoration:none;padding:.3rem .7rem;border:1px solid rgba(255,255,255,.2);border-radius:6px;transition:all .15s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.6)'">
                   &#x2197; Abrir pestaña
                </a>
                <button onclick="cerrarModalContrato()" style="width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);font-size:1rem;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='rgba(255,255,255,.22)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">✕</button>
            </div>
        </div>
        <div style="position:relative;flex:1;overflow:hidden;">
            <div id="iframeContratoLoading" style="position:absolute;inset:0;background:#f8fafc;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;z-index:10;">
                <div style="width:44px;height:44px;border-radius:50%;border:4px solid #e2e8f0;border-top-color:#3b82f6;animation:spinIframe .7s linear infinite;"></div>
                <div style="font-size:.82rem;color:#64748b;font-weight:600;">Cargando contrato...</div>
            </div>
            <iframe id="iframeContrato" src=""
                style="width:100%;height:100%;border:none;display:block;"
                onload="document.getElementById('iframeContratoLoading').style.display='none'">
            </iframe>
        </div>
    </div>
</div>

<style>
@keyframes spinIframe { to { transform: rotate(360deg); } }
</style>

@push('scripts')
<script>
/**
 * Cada columna tiene un solo desplegable con dos grupos —las entidades y los
 * estados del radicado—, así que lo elegido se reparte al filtro que toca
 * antes de enviar: `ent:` a la entidad, `est:` al estado.
 */
function filtrarColumna(selector, columna) {
    const formulario = selector.form;
    const entidad = formulario.querySelector('[name="' + columna + '_id"]');
    const estado = formulario.querySelector('[name="' + columna + '_estado"]');
    const elegido = selector.value;

    if (elegido === '' || elegido === 'nada') { entidad.value = ''; estado.value = ''; }
    else if (elegido.startsWith('ent:')) entidad.value = elegido.slice(4);
    else if (elegido.startsWith('est:')) estado.value = elegido.slice(4);

    formulario.submit();
}

// ── Variables globales ──
let radicadoActivo = null;
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
// Automatización de portales: usuarios BryNex o aliado autorizado por BryNex.
const PUEDE_AUTOMATIZAR = @json(Gate::allows('automatizar-portales'));
// ARL por API (Sura y Colmena) va aparte: un aliado puede tenerla sin los portales de EPS.
const PUEDE_ARL = @json(Gate::allows('automatizar-arl'));
// EPS sin portal de empleador que se afilian por correo al asesor (config afiliaciones_correo.asesores).
const CORREO_EPS = [{ entidad: 'comfenalco', nombre: 'Comfenalco Valle', patron: /COMFENALCO\s*VALLE|DELAGENTE/i }];
// EPS con portal Boxalud (config/boxalud.php); su correo de plan B va dentro del modal.
const BOXALUD_EPS = [{ eps: 'emssanar', nombre: 'Emssanar', patron: /EMSSANAR/i }];

// ── Helpers ──
function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if(e.target === m) m.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if(e.key === 'Escape') {
        cerrarModalCliente();
        cerrarModalContrato();
        document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open'));
    }
});

// ═══════════════════════════════════════════════════════
// ── Modal iframe: CLIENTE ──────────────────────────────
// ═══════════════════════════════════════════════════════
const BASE_CLIENTE  = '{{ url("admin/clientes") }}';
const BASE_CONTRATO = '{{ url("admin/contratos") }}';

let _clienteActivo  = null; // { clienteId, rowId }
let _contratoActivo = null; // { contratoId, rowId }

function cerrarModalCliente() {
    const ov = document.getElementById('modalClienteOverlay');
    const fr = document.getElementById('iframeCliente');
    ov.style.display = 'none';
    fr.src = '';
    _clienteActivo = null;
}

function cerrarModalContrato() {
    const ov = document.getElementById('modalContratoOverlay');
    const fr = document.getElementById('iframeContrato');
    ov.style.display = 'none';
    fr.src = '';
    _contratoActivo = null;
}

// Click en nombre → abre modal cliente
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-afil-cliente');
    if (!btn) return;

    const clienteId = btn.dataset.clienteId;
    const nombre    = btn.dataset.nombre || 'Cliente';
    const rowId     = btn.dataset.rowId;

    _clienteActivo = { clienteId, rowId };

    const deOtroAliado = btn.dataset.aliadoId ? `&aliado=${btn.dataset.aliadoId}` : '';
    const fullUrl = `${BASE_CLIENTE}/${clienteId}/edit`;
    const url     = `${fullUrl}?iframe=1${deOtroAliado}`;

    document.getElementById('iframeClienteTitulo').textContent = nombre;
    document.getElementById('iframeClienteLink').href          = fullUrl;
    document.getElementById('iframeClienteLoading').style.display = 'flex';
    document.getElementById('iframeCliente').src               = url;
    document.getElementById('modalClienteOverlay').style.display = 'flex';
});

// Click en modalidad → abre modal contrato
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-afil-contrato');
    if (!btn) return;

    const contratoId = btn.dataset.contratoId;
    const nombre     = btn.dataset.nombre || 'Contrato';
    const rowId      = btn.dataset.rowId;

    _contratoActivo = { contratoId, rowId };

    // Viendo varios aliados a la vez, la ficha es de otro aliado y el
    // controlador la busca en el activo: `?aliado=` lo cambia al abrirla
    // (SetAlidoContext solo lo permite a usuarios BryNex con acceso).
    const deOtroAliado = btn.dataset.aliadoId ? `&aliado=${btn.dataset.aliadoId}` : '';
    const fullUrl = `${BASE_CONTRATO}/${contratoId}/edit`;
    const url     = `${fullUrl}?iframe=1${deOtroAliado}`;

    document.getElementById('iframeContratoTitulo').textContent = nombre;
    document.getElementById('iframeContratoLink').href          = fullUrl;
    document.getElementById('iframeContratoLoading').style.display = 'flex';
    document.getElementById('iframeContrato').src               = url;
    document.getElementById('modalContratoOverlay').style.display = 'flex';
});

// ── Escuchar postMessage de ambos iframes ──────────────
window.addEventListener('message', function(e) {
    if (!e.data || !e.data.type) return;

    // Contrato actualizado (igual que cobros: brynex:iframe_done)
    if (e.data.type === 'brynex:iframe_done') {
        const ctx = _contratoActivo;
        cerrarModalContrato();
        if (!ctx) return;

        // Actualizar label de modalidad en la celda si viene el mensaje
        // (el contrato enviará el accion y mensaje, pero no siempre la modalidad nueva)
        // Simplemente mostrar toast — el reload es opcional si solo cambia la modalidad
        mostrarToast('✅ ' + (e.data.mensaje || 'Contrato actualizado'), 'success');
    }

    // Cliente actualizado (brynex:cliente_updated)
    if (e.data.type === 'brynex:cliente_updated') {
        const ctx = _clienteActivo;
        cerrarModalCliente();
        if (!ctx) return;

        // Actualizar el botón de nombre en la fila
        const nombreNuevo = e.data.nombre || '';
        if (nombreNuevo) {
            // Buscar el botón en la fila del contrato
            const btnNombre = document.querySelector(`.btn-afil-cliente[data-row-id="${ctx.rowId}"]`);
            if (btnNombre) {
                btnNombre.textContent = nombreNuevo;
                btnNombre.dataset.nombre = nombreNuevo;
                // Micro-animación
                btnNombre.style.transition = 'background .3s';
                btnNombre.style.background = '#dcfce7';
                btnNombre.style.borderRadius = '4px';
                setTimeout(() => { btnNombre.style.background = 'none'; }, 1200);
            }
        }
        mostrarToast('✅ Cliente actualizado correctamente', 'success');
    }
});


// ── Event delegation para badges de radicado ──
let docContextCedula  = null;
let docContextAlidoId = null;

// El contexto del contrato vive una sola vez en su fila (<tr data-ctx>). Se lee
// primero del botón por si algún día vuelve a traerlo, y si no, de la fila.
function ctxDelRadicado(btn) {
    const crudo = btn.dataset.ctx || btn.closest('tr')?.dataset.ctx;
    return crudo ? JSON.parse(crudo) : {};
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-rad');
    if(btn) {
        const radId         = btn.dataset.radId;
        const radData       = JSON.parse(btn.dataset.rad);
        const ctx           = ctxDelRadicado(btn);
        const contratoId    = btn.dataset.contratoId || null;
        // El formulario mapeado puede ser el de la EPS o el del fondo de pensión
        const tieneFormulario = btn.dataset.epsFormulario === '1' || btn.dataset.pensionFormulario === '1';
        abrirModalRadicado(radId, radData, ctx, contratoId, tieneFormulario);
        return;
    }
    // Contrato sin radicado en BD (típico de los migrados desde el legacy):
    // se crea en 'pendiente' al vuelo y se abre el modal como siempre.
    const crearBtn = e.target.closest('.btn-rad-crear');
    if(crearBtn) {
        crearRadicadoPendiente(crearBtn);
        return;
    }
    const docBtn = e.target.closest('.btn-docs-open');
    if(docBtn) {
        docContextCedula  = docBtn.dataset.cedula;
        docContextAlidoId = docBtn.dataset.aliadoId;   // el documento se guarda en el aliado del contrato
        abrirDocs(docBtn.dataset.radId, docBtn.dataset.nombre);
    }
});

async function crearRadicadoPendiente(btn) {
    if (btn.dataset.creando === '1') return;
    btn.dataset.creando = '1';
    const textoOriginal = btn.textContent;
    btn.textContent = '…';

    try {
        const resp = await fetch('{{ route('admin.radicados.crear') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({
                contrato_id: btn.dataset.contratoId,
                tipo:        btn.dataset.tipo,
            }),
        });
        const data = await resp.json();
        if (!resp.ok || !data.ok) {
            btn.textContent = textoOriginal;
            btn.dataset.creando = '';
            alert(data.message || 'No se pudo abrir el trámite.');
            return;
        }

        // El badge pasa a ser uno normal: los siguientes clics ya van directo
        // al modal sin volver a pegarle al endpoint.
        btn.classList.remove('btn-rad-crear');
        btn.classList.add('btn-rad');
        btn.dataset.radId = data.radicado.id;
        btn.dataset.rad   = JSON.stringify(data.radicado);
        btn.textContent   = textoOriginal;
        delete btn.dataset.creando;

        abrirModalRadicado(
            data.radicado.id,
            data.radicado,
            ctxDelRadicado(btn),
            btn.dataset.contratoId || null,
            btn.dataset.epsFormulario === '1' || btn.dataset.pensionFormulario === '1'
        );
    } catch (err) {
        btn.textContent = textoOriginal;
        btn.dataset.creando = '';
        alert('No se pudo abrir el trámite.');
    }
}

// ── Modal Radicado ──
function abrirModalRadicado(radId, radData, ctx = {}, contratoId = null, tieneFormulario = false) {
    radicadoActivo = radData;
    document.getElementById('mrad-id').value = radId;
    document.getElementById('mrad-titulo').textContent = '📝 ' + (radData.tipo?.toUpperCase() || 'Radicado');
    document.getElementById('mrad-tipo').textContent = (radData.tipo || '').toUpperCase();
    document.getElementById('mrad-num-rad').textContent = radData.numero_radicado ? 'N°: ' + radData.numero_radicado : 'Sin número asignado';

    // Afiliar por API: solo en ARL, solo si es Sura y solo si aún no está en OK.
    const btnApi   = document.getElementById('btnAfiliarApi');
    const btnAnular = document.getElementById('btnAnularApi');
    const esArlSura = (radData.tipo === 'arl') && /SURA/i.test(ctx.arl || '');

    btnApi.style.display    = (PUEDE_ARL && esArlSura && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnAnular.style.display = (PUEDE_ARL && esArlSura && radData.estado === 'ok') ? 'inline-flex' : 'none';
    // El badge de un radicado existente no trae data-contrato-id (solo el de
    // crear), así que el id sale del contexto.
    btnApi._contratoId = btnAnular._contratoId = contratoId || ctx.id || null;

    // ARL Colmena: afiliar mientras no esté en OK, anular cuando ya lo está.
    const btnColmena = document.getElementById('btnAfiliarColmena');
    const btnColmenaAnular = document.getElementById('btnAnularColmena');
    const esArlColmena = (radData.tipo === 'arl') && /COLMENA/i.test(ctx.arl || '');
    btnColmena.style.display = (PUEDE_ARL && esArlColmena && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    // Colmena solo anula hasta un día calendario después de que empieza la
    // vigencia; pasado eso el botón solo estorba y lo que toca es retirar.
    const dentroDelPlazoColmena = (() => {
        if (!ctx.fecha_arl) return true; // sin fecha no se puede saber: que decida el servidor
        const limite = new Date(ctx.fecha_arl + 'T00:00:00');
        limite.setDate(limite.getDate() + 1);
        const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        return hoy <= limite;
    })();
    btnColmenaAnular.style.display = (PUEDE_ARL && esArlColmena && radData.estado === 'ok' && dentroDelPlazoColmena) ? 'inline-flex' : 'none';
    btnColmena._contratoId = btnColmenaAnular._contratoId = contratoId || ctx.id || null;

    // Reingreso por el portal: radicados de EPS de Nueva EPS que aún no están en OK.
    const btnNuevaEps = document.getElementById('btnReingresoNuevaEps');
    const esNuevaEps  = (radData.tipo === 'eps') && /NUEVA\s*EPS/i.test(ctx.eps || '');
    btnNuevaEps.style.display = (PUEDE_AUTOMATIZAR && esNuevaEps && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnNuevaEps._contratoId = contratoId || ctx.id || null;

    // Reingreso por el portal: radicados de EPS de SURA que aún no están en OK.
    // Ojo: "SURA" a secas también es la ARL, por eso se mira el tipo del radicado.
    const btnEpsSura = document.getElementById('btnReingresoEpsSura');
    const esEpsSura  = (radData.tipo === 'eps') && /\bSURA\b/i.test(ctx.eps || '');
    btnEpsSura.style.display = (PUEDE_AUTOMATIZAR && esEpsSura && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnEpsSura._contratoId = contratoId || ctx.id || null;

    // Novedad de inicio laboral: radicados de EPS de Salud Total que aún no están en OK.
    const btnSaludTotal = document.getElementById('btnNovedadSaludTotal');
    const esSaludTotal  = (radData.tipo === 'eps') && /SALUD\s*TOTAL/i.test(ctx.eps || '');
    btnSaludTotal.style.display = (PUEDE_AUTOMATIZAR && esSaludTotal && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnSaludTotal._contratoId = contratoId || ctx.id || null;

    // Novedad de inicio laboral: radicados de EPS de S.O.S. que aún no están en OK.
    const btnSos = document.getElementById('btnNovedadSos');
    const esSos  = (radData.tipo === 'eps') && /^\s*S\.?\s*O\.?\s*S\.?\s*$/i.test(ctx.eps || '');
    btnSos.style.display = (PUEDE_AUTOMATIZAR && esSos && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnSos._contratoId = contratoId || ctx.id || null;

    // Cambio de empleador en Sanitas: radicados de EPS de Sanitas que aún no están en OK.
    const btnSanitas = document.getElementById('btnNovedadSanitas');
    const esSanitas  = (radData.tipo === 'eps') && /SANITAS/i.test(ctx.eps || '');
    btnSanitas.style.display = (PUEDE_AUTOMATIZAR && esSanitas && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnSanitas._contratoId = contratoId || ctx.id || null;

    // Caja Comfenalco Valle: radicados de caja que aún no están en OK.
    const btnCaja = document.getElementById('btnCajaComfenalco');
    const esCajaComf = (radData.tipo === 'caja') && /COMFENALCO\s*VALLE/i.test(ctx.caja || '');
    btnCaja.style.display = (PUEDE_AUTOMATIZAR && esCajaComf && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnCaja._contratoId = contratoId || ctx.id || null;

    // Caja Comfandi: radicados de caja que aún no están en OK.
    const btnCajaCfd = document.getElementById('btnCajaComfandi');
    const esComfandi = (radData.tipo === 'caja') && /COMFANDI/i.test(ctx.caja || '');
    btnCajaCfd.style.display = (PUEDE_AUTOMATIZAR && esComfandi && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnCajaCfd._contratoId = contratoId || ctx.id || null;

    // Portal Boxalud (Emssanar), mientras no esté en OK. El correo queda como plan B dentro del modal.
    const btnBox = document.getElementById('btnBoxalud');
    const boxEps = (radData.tipo === 'eps') ? BOXALUD_EPS.find(c => c.patron.test(ctx.eps || '')) : null;
    btnBox.style.display = (PUEDE_AUTOMATIZAR && boxEps && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnBox._contratoId = contratoId || ctx.id || null;
    btnBox._eps = boxEps?.eps || null;
    if (boxEps) btnBox.textContent = `🏥 Radicar ${boxEps.nombre}`;

    // EPS que se afilian por correo al asesor (hoy Comfenalco Valle), mientras no esté en OK.
    const btnCorreo = document.getElementById('btnCorreoEps');
    const correoEps = (radData.tipo === 'eps' && !boxEps) ? CORREO_EPS.find(c => c.patron.test(ctx.eps || '')) : null;
    btnCorreo.style.display = (PUEDE_AUTOMATIZAR && correoEps && radData.estado !== 'ok') ? 'inline-flex' : 'none';
    btnCorreo._contratoId = contratoId || ctx.id || null;
    btnCorreo._entidad = correoEps?.entidad || null;
    if (correoEps) btnCorreo.textContent = `📧 Afiliar ${correoEps.nombre} por correo`;
    // Contexto del contrato
    document.getElementById('mrad-cotizante').textContent        = ctx.nombre         || '—';
    document.getElementById('mrad-empresa').textContent          = ctx.razon_social    || '—';
    document.getElementById('mrad-modalidad').textContent        = ctx.tipo_modalidad  || '—';
    document.getElementById('mrad-empresa-cliente').textContent  = ctx.empresa_cliente || '—';
    document.getElementById('mrad-estado').value = radData.estado || 'pendiente';

    // ARL: sin número de radicado, canal forzado a 'portal'
    const esArl = (radData.tipo === 'arl');
    document.getElementById('seccionNumRadicado').style.display = esArl ? 'none' : '';
    document.getElementById('mrad-canal').value = esArl ? 'portal' : (radData.canal_envio || '');
    document.getElementById('mrad-numero').value = esArl ? '' : (radData.numero_radicado || '');
    // Precargar la observación guardada (viene del legacy en los contratos migrados).
    // Antes se dejaba vacía siempre, así que nunca se veía lo ya registrado.
    document.getElementById('mrad-observacion').value = radData.observacion || '';
    document.getElementById('mrad-enviado').checked = !!radData.enviado_al_cliente;
    document.getElementById('mrad-canal-cliente').value = radData.canal_envio_cliente || '';

    // Alerta de fecha futura
    const alertaFutura = document.getElementById('mrad-alerta-futura');
    if (alertaFutura) alertaFutura.style.display = 'none';

    if (ctx.fecha_ingreso && ctx.fecha_ingreso !== '—') {
        const parts = ctx.fecha_ingreso.split('/');
        if (parts.length === 3) {
            const dateIngreso = new Date(parseInt(parts[2]), parseInt(parts[1]) - 1, parseInt(parts[0]));
            const hoy = new Date();
            hoy.setHours(0,0,0,0);
            dateIngreso.setHours(0,0,0,0);

            if (dateIngreso > hoy) {
                if (alertaFutura) {
                    document.getElementById('mrad-alerta-futura-texto').textContent = 'Advertencia: La fecha de ingreso de este contrato es futura (' + ctx.fecha_ingreso + ').';
                    alertaFutura.style.display = 'flex';
                }
            }
        }
    }

    // ── Botón PDF Formulario (EPS o Pensión) / Ver Datos ──
    const secFormulario = document.getElementById('seccionFormularioEps');
    const btnPdf        = document.getElementById('btnFormularioPdf');
    const btnVerDatos   = document.getElementById('btnVerDatosCotizante');
    // Solo EPS y pensión tienen formulario de afiliación mapeado
    const tipoFormulario = ['eps', 'pension'].includes(radData.tipo) ? radData.tipo : null;

    // Botón Formulario PDF: visible solo si la entidad tiene formulario configurado
    if (btnPdf) {
        if (tipoFormulario && tieneFormulario && contratoId) {
            btnPdf.href = `/admin/afiliaciones/${contratoId}/formulario/${tipoFormulario}`;
            btnPdf.style.display = 'inline-flex';
        } else {
            btnPdf.style.display = 'none';
        }
    }

    // Botón "Ver Datos" siempre visible
    if (btnVerDatos) {
        btnVerDatos.style.display = 'inline-flex';
        btnVerDatos._ctx = ctx;
        btnVerDatos._tipo = (radData.tipo || 'eps').toUpperCase();
    }

    // Colorear select de estado
    actualizarColorEstadoSelect(document.getElementById('mrad-estado'));

    togglePdfSection(radData.estado);
    document.getElementById('mrad-estado').addEventListener('change', function() {
        togglePdfSection(this.value);
    });

    cargarMiniBitacora(radId);
    document.getElementById('modalRadicado').classList.add('open');
}

// ── Colores para el select de estado (igual que la vista) ──
function actualizarColorEstadoSelect(sel) {
    const paleta = {
        pendiente : { bg:'#b45309', color:'#fff' },
        tramite   : { bg:'#1e40af', color:'#fff' },
        traslado  : { bg:'#c2410c', color:'#fff' },
        error     : { bg:'#b91c1c', color:'#fff' },
        ok        : { bg:'#15803d', color:'#fff' },
    };
    const p = paleta[sel.value] || { bg:'#f1f5f9', color:'#334155' };
    sel.style.background = p.bg;
    sel.style.color      = p.color;
    sel.style.borderColor = p.bg;
}

// Afiliar por API desde el radicado: al terminar, el propio flujo deja este
// radicado en OK con el certificado adjunto, así que basta con recargar.
function afiliarApiDesdeRadicado() {
    const contratoId = document.getElementById('btnAfiliarApi')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirAfiliarSura(contratoId);
}

function afiliarColmenaDesdeRadicado() {
    const contratoId = document.getElementById('btnAfiliarColmena')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirAfiliarColmena(contratoId);
}

function anularColmenaDesdeRadicado() {
    const btn = document.getElementById('btnAnularColmena');
    if (!btn._contratoId) { alert('No se pudo identificar el contrato.'); return; }
    anularColmena(btn._contratoId, btn);
}

function reingresoNuevaEpsDesdeRadicado() {
    const contratoId = document.getElementById('btnReingresoNuevaEps')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirReingresoNuevaEps(contratoId);
}

/**
 * Pone al día la pastilla de un radicado en el listado, sin recargar la página.
 *
 * Recibe lo que devuelve `Radicado::paraLaLista()`: el estado, el texto y el
 * color los arma BryNex con los mismos métodos del modelo que usa la vista, así
 * que lo que se ve aquí es lo mismo que saldría al recargar.
 */
function pintarRadicadoEnLista(r, contratoId = null) {
    if (!r?.id) return false;

    // Un contrato sin radicado en la base traía el botón de «crear»: el trámite
    // acaba de crearlo, así que esa casilla pasa a ser la pastilla de siempre.
    const btn = document.querySelector(`.btn-rad[data-rad-id="${r.id}"]`)
        || (contratoId && r.datos?.tipo
            ? document.querySelector(`.btn-rad-crear[data-contrato-id="${contratoId}"][data-tipo="${r.datos.tipo}"]`)
            : null);
    if (!btn) return false;

    btn.classList.remove('btn-rad-crear');
    btn.dataset.radId = r.id;
    if (contratoId) btn.dataset.contratoId = contratoId;

    btn.className = `badge-estado badge-${r.clase} btn-rad`;
    btn.textContent = r.texto;
    if (r.titulo) btn.title = r.titulo; else btn.removeAttribute('title');

    // El modal de gestión del radicado lee de aquí: sin esto seguiría enseñando
    // el radicado sin número hasta recargar.
    if (r.datos) btn.dataset.rad = JSON.stringify(r.datos);
    return true;
}

function reingresoEpsSuraDesdeRadicado() {
    const contratoId = document.getElementById('btnReingresoEpsSura')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirReingresoEpsSura(contratoId);
}

function novedadSaludTotalDesdeRadicado() {
    const contratoId = document.getElementById('btnNovedadSaludTotal')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirNovedadSaludTotal(contratoId);
}

function novedadSosDesdeRadicado() {
    const contratoId = document.getElementById('btnNovedadSos')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirNovedadSos(contratoId);
}

function cajaComfenalcoDesdeRadicado() {
    const btn = document.getElementById('btnCajaComfenalco');
    if (!btn._contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirCajaComfenalco(btn._contratoId);
}

function cajaComfandiDesdeRadicado() {
    const btn = document.getElementById('btnCajaComfandi');
    if (!btn._contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirCajaComfandi(btn._contratoId);
}

function boxaludDesdeRadicado() {
    const btn = document.getElementById('btnBoxalud');
    if (!btn._contratoId || !btn._eps) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirNovedadBoxalud(btn._contratoId, btn._eps);
}

function correoEpsDesdeRadicado() {
    const btn = document.getElementById('btnCorreoEps');
    if (!btn._contratoId || !btn._entidad) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirCorreoEps(btn._contratoId, btn._entidad);
}

function novedadSanitasDesdeRadicado() {
    const contratoId = document.getElementById('btnNovedadSanitas')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }
    cerrarModal('modalRadicado');
    abrirNovedadSanitas(contratoId);
}

// Anular la afiliación. Es irreversible en el sentido contrario: la cobertura
// desaparece del portal, así que se pregunta antes con todas las letras.
async function anularApiDesdeRadicado() {
    const contratoId = document.getElementById('btnAnularApi')._contratoId;
    if (!contratoId) { alert('No se pudo identificar el contrato.'); return; }

    if (!confirm('¿Anular la afiliación en ARL Sura?\n\nLa cobertura desaparece del portal, como si nunca hubiera existido, ' +
                 'y el radicado vuelve a pendiente.\n\nSi el trabajador ya estuvo cubierto, lo correcto es retirar, no anular.')) return;

    const btn = document.getElementById('btnAnularApi');
    btn.disabled = true; btn.textContent = '⏳ Anulando...';

    let data;
    try {
        const res = await fetch(`/admin/gestion-arl/${contratoId}/anular`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        });
        data = await res.json();
    } catch (e) {
        data = { ok: false, mensaje: 'No se pudo conectar con el servidor.' };
    }

    btn.disabled = false; btn.textContent = '↩️ Anular afiliación';

    if (data.ok) {
        alert(data.mensaje);
        location.reload();
    } else {
        alert(data.mensaje || 'No se pudo anular.');
    }
}

// ── Modal Ver Datos Cotizante ──
let _datosCotizanteTexto = '';

function abrirVerDatos(ctx, tipoEntidad) {
    const entidad = {
        eps     : ctx.eps,
        arl     : ctx.arl,
        pension : ctx.pension,
        caja    : '',
    };
    const nombreEntidad = entidad[tipoEntidad?.toLowerCase()] || '';
    const tituloEntidad = tipoEntidad ? `AFILIACIÓN ${tipoEntidad.toUpperCase()}` + (nombreEntidad ? ` "${nombreEntidad}"` : '') : 'DATOS DEL COTIZANTE';

    document.getElementById('mvd-titulo').textContent = '📋 ' + tituloEntidad;

    const salarioFmt = ctx.salario ? '$ ' + Number(ctx.salario).toLocaleString('es-CO') : '—';

    const filas = [
        { lbl: 'RAZÓN SOCIAL', val: ctx.razon_social },
        { lbl: 'NIT',          val: ctx.nit },
        { lbl: 'NOMBRE',       val: ctx.nombre_completo || ctx.nombre },
        { lbl: 'CÉDULA',       val: (ctx.tipo_doc ? ctx.tipo_doc + ' ' : '') + (ctx.cedula || '—') },
        { lbl: 'ARL',          val: ctx.arl },
        { lbl: 'PENSIÓN',      val: ctx.pension },
        { lbl: 'SALARIO',      val: salarioFmt },
        { lbl: 'FECHA_INGRESO',val: ctx.fecha_ingreso },
        { lbl: 'CARGO',        val: ctx.cargo || 'x' },
        { lbl: 'DIRECCIÓN',    val: ctx.direccion },
        { lbl: 'BARRIO',       val: ctx.barrio },
        { lbl: 'CIUDAD',       val: ctx.ciudad },
        { lbl: 'CELULAR',      val: ctx.celular },
        { lbl: 'CORREO',       val: ctx.correo },
    ];

    let html = `<div style="background:#f0f9ff;border-radius:8px;padding:0.6rem 0.85rem;margin-bottom:0.75rem;font-size:0.78rem;font-weight:700;color:#1e40af;letter-spacing:0.03em;">${tituloEntidad}</div>`;
    html += '<table style="width:100%;border-collapse:collapse;">';
    filas.forEach(f => {
        html += `<tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:0.28rem 0.5rem;font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;white-space:nowrap;width:38%;">${f.lbl}</td>
            <td style="padding:0.28rem 0.5rem;font-size:0.82rem;color:#0f172a;font-weight:600;">${f.val || '<span style="color:#cbd5e1;">—</span>'}</td>
        </tr>`;
    });
    html += '</table>';

    document.getElementById('mvd-contenido').innerHTML = html;

    // Texto plano para copiar
    _datosCotizanteTexto = tituloEntidad + '\n\n' +
        filas.map(f => `${f.lbl}: ${f.val || ''}`).join('\n');

    document.getElementById('modalVerDatos').classList.add('open');
}

function copiarDatosCotizante() {
    if (!_datosCotizanteTexto) return;
    navigator.clipboard.writeText(_datosCotizanteTexto).then(() => {
        const btn = document.getElementById('btnCopiarDatos');
        const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copiado';
        btn.style.background = '#15803d';
        setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; }, 1800);
    }).catch(() => {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = _datosCotizanteTexto;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        const btn = document.getElementById('btnCopiarDatos');
        const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copiado';
        setTimeout(() => { btn.innerHTML = orig; }, 1800);
    });
}

function togglePdfSection(estado) {
    const sec        = document.getElementById('seccionPdf');
    const permitePdf = (estado === 'ok' || estado === 'tramite');
    sec.style.display = permitePdf ? 'block' : 'none';

    const cajaActual = document.getElementById('pdfActual');
    const cajaSubir  = document.getElementById('pdfSubir');
    if (!permitePdf) { cajaActual.innerHTML = ''; return; }

    if (radicadoActivo?.ruta_pdf) {
        // Ya hay soporte: se ve de un vistazo y el subidor se pliega.
        const url = '{{ route("admin.radicados.pdf.download", ":id") }}'.replace(':id', radicadoActivo.id);
        cajaActual.innerHTML =
            '<div style="display:flex;align-items:center;gap:0.6rem;background:#f0fdf4;border:1px solid #86efac;' +
            'border-radius:9px;padding:0.55rem 0.75rem;">' +
              '<span style="font-size:1.1rem;">📄</span>' +
              '<div style="flex:1;min-width:0;">' +
                '<div style="font-size:0.78rem;font-weight:700;color:#166534;">Soporte cargado</div>' +
                '<div style="font-size:0.68rem;color:#15803d;">El certificado de la ARL está guardado en este radicado.</div>' +
              '</div>' +
              '<a href="' + url + '" target="_blank" style="background:#16a34a;color:#fff;border-radius:7px;' +
                'padding:0.28rem 0.7rem;font-size:0.72rem;font-weight:700;text-decoration:none;white-space:nowrap;">Ver PDF</a>' +
              '<button type="button" onclick="mostrarSubirPdf()" style="background:none;border:none;color:#15803d;' +
                'font-size:0.7rem;font-weight:600;cursor:pointer;text-decoration:underline;white-space:nowrap;">Reemplazar</button>' +
            '</div>';
        cajaSubir.style.display = 'none';
    } else {
        cajaActual.innerHTML = '';
        cajaSubir.style.display = 'block';
    }
}

/** Despliega el subidor cuando de verdad se quiere cambiar el documento. */
function mostrarSubirPdf() {
    const c = document.getElementById('pdfSubir');
    c.style.display = 'block';
    document.getElementById('mrad-pdf')?.focus();
}

async function guardarRadicado(e) {
    e.preventDefault();
    const id       = document.getElementById('mrad-id').value;
    const estado   = document.getElementById('mrad-estado').value;
    const obs      = document.getElementById('mrad-observacion').value;
    const canal    = document.getElementById('mrad-canal').value;
    const numRad   = document.getElementById('mrad-numero').value;
    const enviado  = document.getElementById('mrad-enviado').checked;
    const canalCli = document.getElementById('mrad-canal-cliente').value;
    const btn      = document.getElementById('btnGuardarRadicado');

    // Observación ya no es obligatoria

    // Confirmación si la fecha de ingreso es futura y el estado es 'ok' (Finalizado)
    const btnVerDatos = document.getElementById('btnVerDatosCotizante');
    if (btnVerDatos && btnVerDatos._ctx && btnVerDatos._ctx.fecha_ingreso && btnVerDatos._ctx.fecha_ingreso !== '—' && estado === 'ok') {
        const parts = btnVerDatos._ctx.fecha_ingreso.split('/');
        if (parts.length === 3) {
            const dateIngreso = new Date(parseInt(parts[2]), parseInt(parts[1]) - 1, parseInt(parts[0]));
            const hoy = new Date();
            hoy.setHours(0,0,0,0);
            dateIngreso.setHours(0,0,0,0);

            if (dateIngreso > hoy) {
                if (!confirm('⚠️ Advertencia: La fecha de ingreso de este contrato es futura (' + btnVerDatos._ctx.fecha_ingreso + '). ¿Estás seguro de que deseas finalizar (OK) este radicado de todas formas?')) {
                    return;
                }
            }
        }
    }

    btn.disabled = true;
    btn.textContent = 'Guardando...';

    try {
        // 1. Actualizar estado
        const r = await fetch(`/admin/radicados/${id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ estado, observacion: obs, canal_envio: canal, numero_radicado: numRad })
        });
        const data = await r.json();
        if(!data.ok) throw new Error('Error al guardar estado');

        // 2. Subir PDF si hay archivo
        const pdfFile = document.getElementById('mrad-pdf')?.files?.[0];
        if(pdfFile) {
            const fd = new FormData();
            fd.append('pdf', pdfFile);
            fd.append('_token', CSRF);
            const rPdf = await fetch(`/admin/radicados/${id}/pdf`, { method: 'POST', body: fd });
            if(!rPdf.ok) throw new Error('el estado se guardó, pero el PDF no se pudo subir');
        }

        // 3. Marcar enviado si cambió
        if(enviado) {
            // Sin mirar la respuesta, un 500 aquí seguía derecho hasta el toast
            // verde: así pasó inadvertido que la bitácora del envío no se
            // estaba escribiendo.
            const rEnv = await fetch(`/admin/radicados/${id}/enviado`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ enviado_al_cliente: true, canal_envio_cliente: canalCli })
            });
            if(!rEnv.ok) throw new Error('el estado se guardó, pero no se pudo marcar como enviado al cliente');
        }

        // Actualizar badge en tabla sin recargar
        actualizarBadgesEnTabla(id, data);
        cerrarModal('modalRadicado');
        mostrarToast('✅ Radicado actualizado correctamente', 'success');
    } catch(err) {
        mostrarToast('❌ Error al guardar: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Guardar Cambios';
    }
}

function actualizarBadgesEnTabla(radId, data) {
    // Buscar todos los botones con este radicadoId
    document.querySelectorAll('[onclick*="abrirModalRadicado(' + radId + '"]').forEach(btn => {
        // Actualizar data y clase
        const clases = ['badge-pendiente','badge-tramite','badge-traslado','badge-error','badge-ok','badge-ok-confirmado'];
        clases.forEach(c => btn.classList.remove(c));
        btn.classList.add('badge-' + data.estado);
        btn.textContent = data.icono + ' ' + data.estado.toUpperCase();
    });
    // Recargar la página para reflejar todos los cambios
    setTimeout(() => location.reload(), 800);
}

// ── Mini bitácora ──
async function cargarMiniBitacora(radId) {
    const el = document.getElementById('minibitacora');
    try {
        const r = await fetch(`/admin/radicados/${radId}/bitacora`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
        });
        const data = await r.json();
        const movs = data.movimientos?.slice(0, 3) || [];
        if(!movs.length) { el.textContent = 'Sin movimientos aún.'; return; }
        el.innerHTML = movs.map(m =>
            `<div style="padding:0.3rem 0;border-bottom:1px solid #f1f5f9;color:#475569;">
                <span style="color:#94a3b8;font-size:0.65rem;">${m.fecha_rel}</span> —
                <strong>${m.usuario}</strong>: ${m.estado_anterior ?? '?'} → <strong>${m.estado_nuevo}</strong>
                ${m.observacion ? `<div style="font-size:0.7rem;color:#64748b;margin-top:0.1rem;">${m.observacion}</div>` : ''}
            </div>`
        ).join('');
    } catch { el.textContent = 'No se pudo cargar.'; }
}

// ── Modal Bitácora completa ──
function abrirBitacora() {
    const id = document.getElementById('mrad-id').value;
    const tipo = document.getElementById('mrad-tipo').textContent;
    document.getElementById('mbit-tipo').textContent = tipo;
    cerrarModal('modalRadicado');
    document.getElementById('mbit-timeline').innerHTML = '<div style="text-align:center;padding:1rem;color:#94a3b8;">⏳ Cargando...</div>';
    document.getElementById('modalBitacora').classList.add('open');

    fetch(`/admin/radicados/${id}/bitacora`, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            const rad = data.radicado;
            document.getElementById('mbit-resumen').innerHTML =
                `<strong>Estado actual:</strong> ${rad.estado?.toUpperCase()} &nbsp;|&nbsp;
                 <strong>Días en estado:</strong> ${rad.dias_actual} días &nbsp;|&nbsp;
                 <strong>PDF:</strong> ${rad.tiene_pdf ? '✅ Disponible' : '❌ No subido'}`;

            const movs = data.movimientos || [];
            if(!movs.length) { document.getElementById('mbit-timeline').innerHTML = '<div style="color:#94a3b8;text-align:center;padding:1rem;">Sin movimientos registrados.</div>'; return; }

            document.getElementById('mbit-timeline').innerHTML = movs.map((m, i) => `
                <div class="tl-item">
                    <div class="tl-date">${m.fecha} &nbsp;<span style="color:#94a3b8;">(${m.fecha_rel})</span></div>
                    <div class="tl-user">👤 ${m.usuario}</div>
                    <div class="tl-estados">
                        <span class="badge-estado badge-${m.estado_anterior ?? 'pendiente'}" style="cursor:default;font-size:0.6rem;">${(m.estado_anterior ?? '?').toUpperCase()}</span>
                        <span style="color:#94a3b8;">→</span>
                        <span class="badge-estado badge-${m.estado_nuevo}" style="cursor:default;font-size:0.6rem;">${m.estado_nuevo.toUpperCase()}</span>
                    </div>
                    ${m.observacion ? `<div class="tl-obs">${m.observacion}</div>` : ''}
                    ${m.dias_desde_prev > 0 ? `<div class="tl-dias">⏱ ${m.dias_desde_prev} día(s) desde el movimiento anterior</div>` : ''}
                </div>
            `).join('');
        })
        .catch(() => { document.getElementById('mbit-timeline').innerHTML = '<div style="color:#ef4444;text-align:center;">Error al cargar la bitácora.</div>'; });
}

// ── Modal Historial Completo de Afiliación ──
function abrirHistorialAfiliacion(contratoId) {
    document.getElementById('mhist-cotizante').textContent = 'Cargando...';
    document.getElementById('mhist-cedula').textContent = '';
    document.getElementById('mhist-timeline').innerHTML = '<div style="text-align:center;padding:1rem;color:#94a3b8;">⏳ Cargando historial...</div>';
    document.getElementById('modalHistorialAfiliacion').classList.add('open');

    fetch(`/admin/afiliaciones/${contratoId}/historial`, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            document.getElementById('mhist-cotizante').textContent = data.cotizante;
            document.getElementById('mhist-cedula').textContent = data.cedula;

            const hist = data.historial || [];
            if(!hist.length) {
                document.getElementById('mhist-timeline').innerHTML = '<div style="color:#94a3b8;text-align:center;padding:1rem;">Sin historial registrado.</div>';
                return;
            }

            document.getElementById('mhist-timeline').innerHTML = `
            <div style="overflow-x: auto;">
                <table class="tbl-afil" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.78rem;">
                    <thead>
                        <tr style="background: #0f172a; color: #fff;">
                            <th style="padding: 0.5rem; font-weight: 700;">FECHA</th>
                            <th style="padding: 0.5rem; font-weight: 700;">HORA</th>
                            <th style="padding: 0.5rem; font-weight: 700;">USUARIO</th>
                            <th style="padding: 0.5rem; font-weight: 700;">DESCRIPCIÓN</th>
                            <th style="padding: 0.5rem; font-weight: 700; text-align: center;">ESTADO</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${hist.map(h => {
                            let badgeClass = 'badge-pendiente';
                            if (h.estado_raw === 'ok') badgeClass = 'badge-ok';
                            else if (h.estado_raw === 'tramite') badgeClass = 'badge-tramite';
                            else if (h.estado_raw === 'traslado') badgeClass = 'badge-traslado';
                            else if (h.estado_raw === 'error') badgeClass = 'badge-error';

                            return `
                            <tr style="border-bottom: 1px solid #f1f5f9; background: #fff; transition: background .12s;">
                                <td style="padding: 0.45rem 0.5rem; font-weight: 700; color: #1e40af; white-space: nowrap;">${h.fecha}</td>
                                <td style="padding: 0.45rem 0.5rem; color: #475569; white-space: nowrap;">${h.hora}</td>
                                <td style="padding: 0.45rem 0.5rem; font-weight: 600; color: #1e3a5f;">${h.usuario}</td>
                                <td style="padding: 0.45rem 0.5rem; color: #334155; white-space: normal; min-width: 200px;">${h.descripcion}</td>
                                <td style="padding: 0.45rem 0.5rem; text-align: center; white-space: nowrap;">
                                    <span class="badge-estado ${badgeClass}" style="cursor: default; font-size: 0.65rem; padding: 0.15rem 0.4rem; min-width: 75px; display: inline-block;">
                                        ${h.estado}
                                    </span>
                                </td>
                            </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
            `;
        })
        .catch(() => {
            document.getElementById('mhist-timeline').innerHTML = '<div style="color:#ef4444;text-align:center;padding:1rem;">Error al cargar el historial.</div>';
        });
}

// ── Modal Documentos ──
function abrirDocs(radId, nombre) {
    document.getElementById('mdocs-nombre').textContent = nombre;
    document.getElementById('mdocs-content').innerHTML = '<div style="text-align:center;padding:1rem;color:#94a3b8;">⏳ Cargando...</div>';
    document.getElementById('modalDocs').classList.add('open');

    if(!radId) {
        document.getElementById('mdocs-content').innerHTML = '<div class="empty-docs">No hay radicado disponible para este contrato.</div>';
        return;
    }

    fetch(`/admin/radicados/${radId}/documentos`, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            let html = '';

            // Cotizante
            html += '<div class="doc-section"><h4>👤 Documentos del Cotizante</h4>';
            if(!data.cotizante?.length) html += '<div class="empty-docs">Sin documentos del cotizante.</div>';
            else data.cotizante.forEach(d => {
                html += `<div class="doc-item">
                    <div class="doc-item-left">
                        <span>📄</span>
                        <div><div class="doc-tipo">${d.tipo}</div><div class="doc-arch">${d.archivo}</div></div>
                    </div>
                    <a href="${d.url_dl}" class="btn-dl" target="_blank">⬇ Descargar</a>
                </div>`;
            });
            html += '</div>';

            // Beneficiarios
            if(data.lista_benef?.length) {
                html += '<div class="doc-section"><h4>👨‍👩‍👧 Documentos de Beneficiarios</h4>';
                if(!data.beneficiarios?.length) html += '<div class="empty-docs">Sin documentos de beneficiarios.</div>';
                else data.beneficiarios.forEach(d => {
                    html += `<div class="doc-item">
                        <div class="doc-item-left">
                            <span>📄</span>
                            <div><div class="doc-tipo">${d.tipo}</div><div class="doc-arch">${d.para} — ${d.archivo}</div></div>
                        </div>
                        <a href="${d.url_dl}" class="btn-dl" target="_blank">⬇ Descargar</a>
                    </div>`;
                });
                html += '</div>';

                // Lista de beneficiarios registrados
                html += '<div class="doc-section"><h4>📋 Beneficiarios Registrados</h4>';
                data.lista_benef.forEach(b => {
                    html += `<div style="font-size:0.75rem;padding:0.2rem 0.6rem;background:#f8fafc;border-radius:6px;margin-bottom:0.25rem;">
                        <strong>${b.nombres}</strong> — ${b.parentesco ?? 'Sin parentesco'} — Doc: ${b.n_documento ?? '—'}
                    </div>`;
                });
                html += '</div>';
            }

            // Rellenar select "¿Para quién?" con beneficiarios
            const selPara = document.getElementById('mdocs-para');
            selPara.innerHTML = '<option value="">Cotizante</option>';
            if(data.lista_benef?.length) {
                data.lista_benef.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b.n_documento;  // doc_beneficiario = n_documento del benef.
                    opt.textContent = `${b.nombres} (${b.parentesco ?? 'Benef.'} · ${b.n_documento})`;
                    selPara.appendChild(opt);
                });
            }

            document.getElementById('mdocs-content').innerHTML = html || '<div class="empty-docs">Sin documentos.</div>';
        })
        .catch(() => { document.getElementById('mdocs-content').innerHTML = '<div style="color:#ef4444;text-align:center;">Error al cargar documentos.</div>'; });
}

// ── Subir documento desde modal documentos ──
async function subirDocumento() {
    const tipo    = document.getElementById('mdocs-tipo').value;
    const archivo = document.getElementById('mdocs-archivo').files[0];
    if(!archivo) { mostrarToast('Selecciona un archivo primero', 'error'); return; }
    if(archivo.size > 3 * 1024 * 1024) { mostrarToast('El archivo supera 3MB', 'error'); return; }
    if(!docContextCedula) { mostrarToast('Error: sin cédula de contexto', 'error'); return; }

    const btn = document.getElementById('btnSubirDoc');
    btn.disabled = true; btn.textContent = 'Subiendo...';

    const fd = new FormData();
    fd.append('_method', 'POST');
    fd.append('archivo', archivo);
    fd.append('tipo_documento', tipo);
    fd.append('_token', CSRF);
    const para = document.getElementById('mdocs-para').value;
    if(para) fd.append('doc_beneficiario', para);  // vacío = cotizante

    try {
        if (docContextAlidoId) fd.append('aliado_id', docContextAlidoId);
        const r = await fetch(`/admin/clientes/${docContextCedula}/documentos`, { method: 'POST', body: fd });
        const data = await r.json();
        if(r.ok || data.ok) {
            mostrarToast('Documento subido correctamente', 'success');
            document.getElementById('mdocs-archivo').value = '';
            // Recargar la lista de documentos
            const radId = document.getElementById('mdocs-nombre').dataset?.radId
                ?? document.querySelector('.btn-docs-open[data-cedula="' + docContextCedula + '"]')?.dataset?.radId;
            if(radId) abrirDocs(radId, document.getElementById('mdocs-nombre').textContent);
        } else {
            mostrarToast(data.message ?? 'Error al subir el documento', 'error');
        }
    } catch(e) {
        mostrarToast('Error de conexión al subir', 'error');
    } finally {
        btn.disabled = false; btn.textContent = '⬆ Subir';
    }
}

// ══ CLAVES RAZÓN SOCIAL ══
let rsIdActivo = null;
let rsNombreActivo = null;
let clavesCargadas = [];

let rsContratoActivo = {};

function abrirClavesRS(rsId, rsNombre, delContrato) {
    // Al reabrir tras guardar una clave se conserva el contrato de antes.
    rsContratoActivo = delContrato || (rsId === rsIdActivo ? rsContratoActivo : {});
    rsIdActivo = rsId;
    if (window.Portales) Portales.montar('rs-portales', rsId, rsContratoActivo);
    rsNombreActivo = rsNombre;
    var panel   = document.getElementById('rs-claves-panel');
    var overlay = document.getElementById('rs-claves-overlay');
    document.getElementById('rs-claves-subtitulo').textContent = rsNombre;
    document.getElementById('rs-claves-notif').style.display = 'none';
    overlay.style.display = 'block';
    panel.style.display   = 'flex';
    setTimeout(function(){ panel.style.transform = 'translateX(0)'; }, 10);

    // Cargar claves
    var loading = document.getElementById('rs-claves-loading');
    var body    = document.getElementById('rs-claves-body');
    var tbody   = document.getElementById('rs-claves-tbody');
    loading.style.display = 'block';
    body.style.display    = 'none';

    fetch('/admin/clave-accesos/razon-social/' + rsId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }
    })
    .then(function(r){ return r.json(); })
    .then(function(claves) {
        loading.style.display = 'none';
        body.style.display    = 'block';
        tbody.innerHTML = '';
        clavesCargadas = claves || [];
        if (clavesCargadas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">No hay claves registradas para esta razón social. Use ➕ Nueva Clave para agregar.</td></tr>';
            return;
        }
        var colores = {
            'Portal':['#eff6ff','#1d4ed8'],'Correo':['#fef3c7','#92400e'],
            'EPS':['#dcfce7','#15803d'],'ARL':['#fce7f3','#9d174d'],
            'AFP':['#e0e7ff','#3730a3'],'CAJA':['#fff7ed','#c2410c'],
            'DIAN':['#fef9c3','#713f12'],'MinTrabajo':['#f0fdf4','#166534'],
            'Banco':['#f5f3ff','#6d28d9'],'Operadores':['#f3e8ff','#7e22ce'],'Otro':['#f1f5f9','#475569']
        };
        clavesCargadas.forEach(function(c) {
            var col = colores[c.tipo] || ['#f1f5f9','#475569'];
            var tipoBadge = '<span style="background:'+col[0]+';color:'+col[1]+';padding:0.15rem 0.5rem;border-radius:999px;font-size:0.68rem;font-weight:700;">'+(c.tipo||'—')+'</span>';
            var linkBtn = c.link_acceso
                ? '<a href="'+c.link_acceso+'" target="_blank" style="background:#eff6ff;color:#2563eb;padding:0.18rem 0.5rem;border-radius:5px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;text-decoration:none;">🔗 Abrir</a>'
                : '<span style="color:#cbd5e1;">—</span>';
            var estadoBadge = c.activo
                ? '<span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>'
                : '<span style="background:#fee2e2;color:#dc2626;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">INACTIVO</span>';
            var masked = c.contrasena ? '•'.repeat(Math.min(c.contrasena.length,8))+' 👁' : '<span style="color:#cbd5e1;">—</span>';
            var passHtml = c.contrasena
                ? '<span style="font-family:monospace;font-size:0.77rem;cursor:pointer;" onclick="verPassClaveRS(this, '+c.id+', \''+btoa(unescape(encodeURIComponent(c.contrasena)))+'\')" title="Click para revelar">'+masked+'</span>'
                : masked;
            var tr = document.createElement('tr');
            tr.style.cssText = 'border-bottom:1px solid #fef3c7;';
            tr.onmouseover = function(){ this.style.background='#fffbeb'; };
            tr.onmouseout  = function(){ this.style.background='transparent'; };
            tr.innerHTML =
                '<td style="padding:0.38rem 0.65rem;">'+tipoBadge+'</td>'+
                '<td style="padding:0.38rem 0.65rem;font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(c.entidad||'')+'">'+(c.entidad||'—')+'</td>'+
                '<td style="padding:0.38rem 0.65rem;font-family:monospace;font-size:0.77rem;">'+(c.usuario||'<span style="color:#cbd5e1;">—</span>')+'</td>'+
                '<td style="padding:0.38rem 0.65rem;">'+passHtml+'</td>'+
                '<td style="padding:0.38rem 0.65rem;text-align:center;">'+linkBtn+'</td>'+
                '<td style="padding:0.38rem 0.65rem;font-size:0.75rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(c.correo_entidad||'')+'">'+(c.correo_entidad||'<span style="color:#cbd5e1;">—</span>')+'</td>'+
                '<td style="padding:0.38rem 0.65rem;font-size:0.73rem;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(c.observacion||'')+'">'+(c.observacion||'')+'</td>'+
                '<td style="padding:0.38rem 0.65rem;text-align:center;">'+estadoBadge+'</td>'+
                '<td style="padding:0.38rem 0.65rem;text-align:center;white-space:nowrap;">' +
                    '<button onclick="abrirModalClaveRS(' + c.id + ')" style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;" title="Editar">✏️</button> ' +
                    '<button onclick="eliminarClaveRS(' + c.id + ')" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#dc2626;" title="Eliminar">🗑</button>' +
                '</td>';
            tbody.appendChild(tr);
        });
    })
    .catch(function() {
        loading.style.display = 'none';
        body.style.display    = 'block';
        mostrarNotifClavesRS('Error al cargar las claves.', 'error');
    });
}

function cerrarClavesRS() {
    var panel   = document.getElementById('rs-claves-panel');
    var overlay = document.getElementById('rs-claves-overlay');
    panel.style.transform = 'translateX(100%)';
    setTimeout(function(){
        panel.style.display   = 'none';
        overlay.style.display = 'none';
    }, 300);
}

function abrirModalClaveRS(id = null) {
    var modal = document.getElementById('rs-ca-modal-overlay');
    modal.style.display = 'flex';

    if (id) {
        var clave = clavesCargadas.find(item => item.id === id);
        if (clave) {
            document.getElementById('rs-ca-modal-titulo').textContent  = '✏️ Editar Clave #' + clave.id;
            document.getElementById('rs-ca-modal-id').value            = clave.id;
            document.getElementById('rs-ca-modal-rs-id').value         = clave.razon_social_id || rsIdActivo;
            document.getElementById('rs-ca-f-tipo').value              = clave.tipo || 'Portal';
            document.getElementById('rs-ca-f-entidad').value           = clave.entidad || '';
            document.getElementById('rs-ca-f-usuario').value           = clave.usuario || '';
            document.getElementById('rs-ca-f-contrasena').value        = clave.contrasena || '';
            document.getElementById('rs-ca-f-link').value              = clave.link_acceso || '';
            document.getElementById('rs-ca-f-correo').value            = clave.correo_entidad || '';
            document.getElementById('rs-ca-f-obs').value               = clave.observacion || '';
            document.getElementById('rs-ca-f-activo').checked          = clave.activo == 1 || clave.activo === true;
        }
    } else {
        document.getElementById('rs-ca-modal-titulo').textContent = '🔑 Nueva Clave';
        document.getElementById('rs-ca-modal-id').value           = '';
        document.getElementById('rs-ca-modal-rs-id').value         = rsIdActivo;
        document.getElementById('rs-ca-f-tipo').value             = 'Portal';
        document.getElementById('rs-ca-f-entidad').value          = '';
        document.getElementById('rs-ca-f-usuario').value          = '';
        document.getElementById('rs-ca-f-contrasena').value       = '';
        document.getElementById('rs-ca-f-link').value             = '';
        document.getElementById('rs-ca-f-correo').value           = '';
        document.getElementById('rs-ca-f-obs').value              = '';
        document.getElementById('rs-ca-f-activo').checked         = true;
    }
}

function cerrarModalClaveRS() {
    document.getElementById('rs-ca-modal-overlay').style.display = 'none';
}

function togglePassClaveRS() {
    var inp = document.getElementById('rs-ca-f-contrasena');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

function verPassClaveRS(el, id, b64) {
    var actual = el.dataset.visible === '1';
    if (actual) {
        el.textContent = '•'.repeat(8) + ' 👁';
        el.dataset.visible = '0';
    } else {
        try { el.textContent = decodeURIComponent(escape(atob(b64))) + ' 👁'; } catch(e){ el.textContent = atob(b64) + ' 👁'; }
        el.dataset.visible = '1';
    }
}

function guardarClaveRS() {
    var id      = document.getElementById('rs-ca-modal-id').value;
    var rsId    = document.getElementById('rs-ca-modal-rs-id').value;
    var entidad = document.getElementById('rs-ca-f-entidad').value.trim();
    var tipo    = document.getElementById('rs-ca-f-tipo').value;

    if (!entidad) {
        mostrarNotifClavesRS('Ingresa el nombre de la entidad o portal.', 'error');
        return;
    }

    var body = {
        _token:          CSRF,
        tipo:            tipo,
        entidad:         entidad,
        usuario:         document.getElementById('rs-ca-f-usuario').value.trim(),
        contrasena:      document.getElementById('rs-ca-f-contrasena').value,
        link_acceso:     document.getElementById('rs-ca-f-link').value.trim(),
        correo_entidad:  document.getElementById('rs-ca-f-correo').value.trim(),
        observacion:     document.getElementById('rs-ca-f-obs').value.trim(),
        activo:          document.getElementById('rs-ca-f-activo').checked ? 1 : 0,
        razon_social_id: rsId
    };

    var url    = id ? '/admin/clave-accesos/' + id : '/admin/clave-accesos';
    var method = id ? 'PUT' : 'POST';

    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': CSRF
        },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(function(res) {
        if (res.success) {
            cerrarModalClaveRS();
            mostrarNotifClavesRS(res.message || 'Guardado correctamente.', 'success');
            abrirClavesRS(rsIdActivo, rsNombreActivo);
        } else {
            mostrarNotifClavesRS('Error: ' + (res.message || 'Error al guardar.'), 'error');
        }
    })
    .catch(function() {
        mostrarNotifClavesRS('Error de conexión al guardar.', 'error');
    });
}

function eliminarClaveRS(id) {
    if (!confirm('¿Eliminar esta clave de acceso? Esta acción no se puede deshacer.')) return;

    fetch('/admin/clave-accesos/' + id, {
        method: 'DELETE',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': CSRF
        }
    })
    .then(r => r.json())
    .then(function(res) {
        if (res.success) {
            mostrarNotifClavesRS(res.message || 'Eliminada correctamente.', 'success');
            abrirClavesRS(rsIdActivo, rsNombreActivo);
        } else {
            mostrarNotifClavesRS('Error al eliminar.', 'error');
        }
    })
    .catch(() => mostrarNotifClavesRS('Error de conexión.', 'error'));
}

function mostrarNotifClavesRS(msg, tipo) {
    var el = document.getElementById('rs-claves-notif');
    el.style.display = 'block';
    if (tipo === 'success') {
        el.style.cssText = 'display:block;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#065f46;';
        el.textContent = '✅ ' + msg;
    } else {
        el.style.cssText = 'display:block;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;';
        el.textContent = '❌ ' + msg;
    }
    setTimeout(function(){ el.style.display = 'none'; }, 4000);
}

// ═══════════════════════════════════════════════════════
// ── Conciliación de radicados de EPS SURA ──────────────
// ═══════════════════════════════════════════════════════
// El comando corre en segundo plano; aquí solo se lanza y se consulta su
// progreso cada pocos segundos mientras el modal esté abierto.
const CEPS_URL_INICIAR = '{{ route("admin.afiliaciones.conciliar-eps-sura") }}';
const CEPS_URL_ESTADO  = '{{ route("admin.afiliaciones.conciliar-eps-sura.estado") }}';
const CEPS_ACCIONES = {
    cerrado:  ['✅ Cerrado', '#dcfce7', '#166534'],
    cerraria: ['✅ Se cerraría', '#dcfce7', '#166534'],
    tramite:  ['🔵 En trámite', '#dbeafe', '#1e40af'],
    sin_cambio: ['🔵 En trámite (sin cambios)', '#dbeafe', '#1e40af'],
    falta:   ['⏳ Falta en la EPS', '#fef3c7', '#92400e'],
    confirmado: ['✔ Ya confirmado', '#f1f5f9', '#334155'],
    revisar:  ['👀 Revisar', '#e0e7ff', '#3730a3'],
    error:    ['❌ Error', '#fee2e2', '#991b1b'],
};
const CEPS_NOMBRES = { sura: 'EPS SURA', nueva_eps: 'Nueva EPS', salud_total: 'Salud Total', sos: 'S.O.S.', sanitas: 'Sanitas', caja_comfenalco: 'Caja Comfenalco Valle', caja_comfandi: 'Caja Comfandi', pension: 'el RUAF' };
let _cepsTimer = null;
let _cepsCorria = false;
let _cepsEntidad = 'sura';

// Versión que publica BryNex; se compara con la instalada para avisar cuando
// la del navegador se quedó atrás.
const EXT_URL_VERSION = @json(route('admin.afiliaciones.extension-portales.version'));

function verPasosExtension() {
    const c = document.getElementById('ceps-ext-pasos');
    if (c) c.style.display = c.style.display === 'none' ? 'block' : 'none';
}

/** El botón de la cabecera muestra y esconde la tarjeta. */
function verTarjetaExtension() {
    const c = document.getElementById('ceps-ext-card');
    if (!c) return;
    const abrir = c.style.display === 'none';
    c.style.display = abrir ? 'flex' : 'none';
    if (abrir) c.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

async function revisarExtensionPortales() {
    const caja = document.getElementById('ceps-ext-estado');
    const btn  = document.getElementById('ceps-ext-btn');
    if (!caja) return;

    // El botón lleva el estado a la cabecera para no ocupar sitio con la
    // tarjeta cuando la extensión ya está puesta y al día.
    const marcar = (clase, texto) => {
        if (!btn) return;
        btn.classList.remove('falta', 'vieja');
        if (clase) btn.classList.add(clase);
        btn.textContent = texto;
    };

    // puente.js la escribe en el <html> apenas carga la página.
    const instalada = document.documentElement.dataset.brynexPortales || null;

    if (!instalada) {
        marcar('falta', '🧩 Falta la extensión');
        caja.innerHTML = '<span style="color:#fca5a5;font-weight:700;">No está instalada en este navegador.</span> '
            + 'Sin ella no se pueden conciliar S.O.S., Sanitas ni las cajas: descárgala y sigue los pasos.';
        const pasos = document.getElementById('ceps-ext-pasos');
        if (pasos) pasos.style.display = 'block';
        return;
    }

    marcar(null, '🧩 Extensión ' + instalada);
    caja.innerHTML = '<span style="color:#4ade80;font-weight:700;">Instalada</span> · versión ' + instalada;

    try {
        const { version } = await (await fetch(EXT_URL_VERSION, { headers: { Accept: 'application/json' } })).json();
        if (version && version !== instalada) {
            marcar('vieja', '🧩 Extensión ' + instalada + ' → ' + version);
            caja.innerHTML = '<span style="color:#fde047;font-weight:700;">Hay una versión más nueva.</span> '
                + 'Tienes la ' + instalada + ' y BryNex publica la ' + version
                + ': pulsa 🔄 Actualizar, y si sigue igual descarga y reemplaza la carpeta.';
        }
    } catch (e) { /* sin red, basta con saber que está instalada */ }
}

function abrirConciliacionEpsSura() {
    document.getElementById('modalConciliacionEps').classList.add('open');
    revisarExtensionPortales();
    elegirEntidadConciliacion(_cepsEntidad);
}

function elegirEntidadConciliacion(entidad) {
    _cepsEntidad = entidad;
    _cepsCorria = false;
    document.querySelectorAll('.ceps-tab').forEach(b =>
        b.classList.toggle('activo', b.dataset.entidad === entidad));
    const t = document.getElementById('ceps-titulo');
    if (t) t.textContent = entidad === 'pension'
        ? '🏦 Conciliar radicados de pensión con el RUAF'
        : (['caja_comfenalco', 'caja_comfandi'].includes(entidad) ? '🏢 Conciliar radicados de caja con ' : '🩺 Conciliar radicados de EPS con ') + (CEPS_NOMBRES[entidad] || 'el portal');
    // En Comfandi el botón no solo mueve radicados: también abre y cierra las
    // tareas de subsidio bloqueado.
    const btnAplicar = document.querySelector('#ceps-acciones button:last-child');
    if (btnAplicar) {
        btnAplicar.textContent = entidad === 'caja_comfandi'
            ? '✅ Actualizar radicados y tareas'
            : '✅ Consultar y actualizar radicados';
    }
    Object.keys(CEPS_NOMBRES).forEach(k => {
        document.getElementById('ceps-descripcion-' + k).style.display = k === entidad ? 'block' : 'none';
    });
    // El selector de razón social existe para comprobar la sesión del portal;
    // el RUAF se consulta por cédula, así que ahí no pinta nada.
    const razon = document.getElementById('ceps-razon');
    if (razon) razon.closest('div').style.display = entidad === 'pension' ? 'none' : 'block';
    consultarConciliacionEpsSura();
}

async function iniciarConciliacionEpsSura(simular) {
    if (_cepsEntidad === 'sos') return conciliarSos(simular);
    if (_cepsEntidad === 'sanitas') return conciliarSanitas(simular);
    if (_cepsEntidad === 'caja_comfenalco') return conciliarCajaComfenalco(simular);
    if (_cepsEntidad === 'caja_comfandi') return conciliarCajaComfandi(simular);
    if (!simular && !confirm(_cepsEntidad === 'pension'
        ? 'Se consultará el RUAF por cada persona y se cerrarán en BryNex los radicados de pensión que confirme. ¿Continuar?'
        : `Se consultará el portal de ${CEPS_NOMBRES[_cepsEntidad]} y se actualizarán en BryNex los radicados que el portal confirme. ¿Continuar?`)) return;
    try {
        const res = await fetch(CEPS_URL_INICIAR, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ simular, entidad: _cepsEntidad, incluir_ok: !!document.getElementById('ceps-pension-ok')?.checked }),
        });
        const data = await res.json();
        if (!data.ok) { mostrarToast(data.mensaje || 'No se pudo iniciar.', 'error'); }
    } catch (e) {
        mostrarToast('Error de conexión al iniciar la conciliación.', 'error');
    }
    consultarConciliacionEpsSura();
}

async function consultarConciliacionEpsSura() {
    clearTimeout(_cepsTimer);
    let data;
    try {
        if (_cepsEntidad === 'sos') revisarSesionSosConciliacion();
        if (_cepsEntidad === 'sanitas') revisarSesionSanitas();
        if (_cepsEntidad === 'caja_comfenalco') revisarSesionCajaConciliacion();
        if (_cepsEntidad === 'caja_comfandi') revisarSesionComfandiConciliacion().then(s => { if (s) revisarSubsidiosSiFalta(); });
        const url = _cepsEntidad === 'sos' ? SOSC_URL_ESTADO
            : _cepsEntidad === 'sanitas' ? SANITAS_URL_ESTADO
            : (_cepsEntidad === 'caja_comfenalco' ? CAJA_URL_ESTADO
                : (_cepsEntidad === 'caja_comfandi' ? COMFANDI_URL_ESTADO : CEPS_URL_ESTADO + '?entidad=' + _cepsEntidad));
        data = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
    } catch (e) {
        return;
    }
    pintarConciliacionEpsSura(data);
    pintarPeticionSos(data.peticion);

    const abierto = document.getElementById('modalConciliacionEps').classList.contains('open');
    if (data.corriendo && abierto) {
        _cepsCorria = true;
        _cepsTimer = setTimeout(consultarConciliacionEpsSura, 3000);
    } else if (!data.corriendo && _cepsCorria) {
        _cepsCorria = false;
        if (data.cerrados > 0 && !data.simulado) {
            mostrarToast(`${data.cerrados} radicados de ${CEPS_NOMBRES[_cepsEntidad]} cerrados. Recarga para verlos.`, 'success');
        }
    }
}

function pintarConciliacionEpsSura(data) {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const estado  = document.getElementById('ceps-estado');
    const resumen = document.getElementById('ceps-resumen');
    const tabla   = document.getElementById('ceps-tabla');
    document.getElementById('ceps-acciones').style.display = data.corriendo ? 'none' : 'flex';

    if (data.vacio) {
        estado.style.display = resumen.style.display = tabla.style.display = 'none';
        return;
    }

    const cuando = data.fin ? new Date(data.fin).toLocaleString('es-CO') : '';
    estado.style.display = 'block';
    estado.innerHTML = data.corriendo
        ? `⏳ ${esc(data.mensaje)}${data.simulado ? ' <em>(solo consulta)</em>' : ''}`
        : (data.error
            ? `❌ Falló: ${esc(data.error)}`
            : `Última corrida ${data.simulado ? '<strong>(solo consulta)</strong> ' : ''}terminó ${esc(cuando)}.`);

    if (!data.corriendo && data.total !== undefined) {
        resumen.style.display = 'flex';
        resumen.innerHTML = [
            [data.simulado ? 'Se cerrarían' : 'Cerrados', data.cerrados, '#dcfce7', '#166534'],
            ...(data.tramite !== undefined ? [['En trámite', data.tramite, '#dbeafe', '#1e40af']] : []),
            ...(data.sin_cambio ? [['Ya confirmados', data.sin_cambio, '#f1f5f9', '#334155']] : []),
            [_cepsEntidad === 'pension' ? 'Sin fondo en RUAF' : 'Faltan en la EPS', data.faltan, '#fef3c7', '#92400e'],
            ['Revisar', data.revisar, '#e0e7ff', '#3730a3'],
            ['Errores', data.errores, '#fee2e2', '#991b1b'],
        ].map(([t, n, bg, fg]) => `<span style="background:${bg};color:${fg};padding:0.2rem 0.6rem;border-radius:6px;font-weight:700;">${t}: ${n}</span>`).join('')
          + `<span style="color:#64748b;padding:0.2rem 0;">de ${data.total}</span>`;
    } else {
        resumen.style.display = 'none';
    }

    const sobran = data.sobran || [];
    const cajaSobran = document.getElementById('ceps-sobran');
    cajaSobran.style.display = (['sanitas', 'caja_comfenalco', 'caja_comfandi'].includes(_cepsEntidad) && sobran.length) ? 'block' : 'none';
    cajaSobran.innerHTML = sobran.length ? `<strong>${['caja_comfenalco', 'caja_comfandi'].includes(_cepsEntidad) ? 'Afiliados a la caja sin contrato vigente con esa caja en BryNex' : 'Habilitados en Sanitas sin contrato vigente con Sanitas en BryNex'} (${sobran.length})</strong>` +
        `<table style="width:100%;border-collapse:collapse;margin-top:0.35rem;"><tbody>` +
        sobran.map(x => `<tr style="border-top:1px solid #f1f5f9;"><td style="padding:0.3rem 0.5rem;white-space:nowrap;">${esc(x.cedula)}</td><td style="padding:0.3rem 0.5rem;">${esc(x.nombre)}</td><td style="padding:0.3rem 0.5rem;white-space:nowrap;">desde ${esc(x.desde || '—')}</td><td style="padding:0.3rem 0.5rem;color:#475569;">${esc(x.motivo)}</td></tr>`).join('') +
        `</tbody></table>` : '';

    const filas = data.detalle || [];
    tabla.style.display = filas.length ? 'table' : 'none';
    document.getElementById('ceps-filas').innerHTML = filas.map(f => {
        const [txt, bg, fg] = CEPS_ACCIONES[f.accion] || [f.accion, '#f1f5f9', '#334155'];
        return `<tr style="border-top:1px solid #f1f5f9;">
            <td style="padding:0.35rem 0.5rem;white-space:nowrap;">${esc(f.cedula)}</td>
            <td style="padding:0.35rem 0.5rem;">${esc(f.nombre)}</td>
            <td style="padding:0.35rem 0.5rem;">${esc(f.empresa)}</td>
            <td style="padding:0.35rem 0.5rem;white-space:nowrap;"><span style="background:${bg};color:${fg};padding:0.1rem 0.45rem;border-radius:5px;font-weight:700;">${txt}</span></td>
            <td style="padding:0.35rem 0.5rem;color:#475569;">${esc(f.mensaje)}</td>
        </tr>`;
    }).join('');
}

// ── Caja Comfenalco Valle: conciliación con la extensión BryNex Portales ──
const CAJA_URL_CONCILIAR = @json(route('admin.afiliaciones.caja-comfenalco.conciliar'));
const CAJA_URL_ESTADO = @json(route('admin.afiliaciones.caja-comfenalco.conciliar.estado'));

function cajaExt(accion, datos = {}, limiteSeg = 120) {
    return new Promise((resolve) => {
        if (!document.documentElement.dataset.brynexPortales) { resolve({ ok: false, sinExtension: true }); return; }
        const id = Date.now() + '-' + Math.random().toString(36).slice(2);
        const oyente = (ev) => {
            if (ev.source !== window || ev.data?.canal !== 'brynex-portales' || ev.data.tipo !== 'respuesta' || ev.data.id !== id) return;
            window.removeEventListener('message', oyente); clearTimeout(alarma);
            resolve(ev.data.respuesta || { ok: false, error: 'Respuesta vacía de la extensión.' });
        };
        window.addEventListener('message', oyente);
        const alarma = setTimeout(() => { window.removeEventListener('message', oyente); resolve({ ok: false, error: 'La extensión no respondió a tiempo.' }); }, limiteSeg * 1000);
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'ccfcv', accion, datos }, window.location.origin);
    });
}

async function revisarSesionCajaConciliacion() {
    const caja = document.getElementById('ceps-caja-sesion');
    const e = await cajaExt('ccfEstado', {}, 25);
    const abrir = `<button type="button" onclick="cajaExt('ccfAbrir')" class="btn-export" style="background:#047857;cursor:pointer;margin-left:0.4rem;">🌐 Abrir la Sucursal Virtual</button>`;
    if (e.sinExtension) { caja.innerHTML = '🧩 Instala o recarga la extensión BryNex Portales (1.6.0) y recarga esta página.'; return null; }
    if (!e.abierta || !e.sesion) { caja.innerHTML = '⚠️ Inicia sesión en la Sucursal Virtual con el usuario de la empresa.' + abrir; return null; }
    caja.innerHTML = `✅ Portal abierto${e.empresa ? ' con <strong>' + e.empresa + '</strong>' : ''}.` + abrir;
    return e;
}

const CAJA_URL_BENEFICIARIOS = @json(route('admin.afiliaciones.caja-comfenalco.beneficiarios'));

/**
 * Baja los beneficiarios de Comfenalco. Va aparte de la conciliación porque
 * hay que preguntar trabajador por trabajador: con 51 personas son 51
 * consultas, y no tiene sentido repetirlo en cada cruce.
 */
async function bajarFamiliasComfenalco() {
    const caja = document.getElementById('ceps-caja-familias');
    const sesion = await revisarSesionCajaConciliacion();
    if (!sesion) { alert('Primero inicia sesión en la Sucursal Virtual de Comfenalco.'); return; }

    caja.innerHTML = '⏳ Bajando la lista de trabajadores afiliados...';
    const rep = await cajaExt('ccfTrabajadores', {}, 150);
    if (!rep.ok) { caja.innerHTML = '❌ ' + (rep.error || 'No se pudo bajar la lista.'); return; }
    if (!coincideRazon(rep.nit, rep.empresa)) { caja.innerHTML = 'Cancelado: la empresa del portal no es la que escogiste.'; return; }

    const documentos = (rep.filas || []).map(f => String(f[0] || '').replace(/\D/g, '')).filter(Boolean);
    if (!documentos.length) { caja.innerHTML = '❌ La empresa no tiene trabajadores afiliados.'; return; }

    caja.innerHTML = `⏳ Consultando el grupo familiar de ${documentos.length} trabajadores (unos segundos cada uno)...`;
    const fam = await cajaExt('ccfGrupoFamiliar', { documentos }, 900);
    if (!fam.ok) { caja.innerHTML = '❌ ' + (fam.error || 'No se pudo consultar el grupo familiar.'); return; }

    const res = await fetch(CAJA_URL_BENEFICIARIOS, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ nit: rep.nit, familias: fam.familias }),
    });
    const data = await res.json();
    if (!data.ok) { caja.innerHTML = '❌ ' + (data.mensaje || 'No se pudieron guardar.'); return; }

    caja.innerHTML = `✅ ${data.nuevos} beneficiarios nuevos guardados, de ${data.personas} trabajadores`
        + ` (se consultaron ${fam.consultados}${fam.fallos ? `, ${fam.fallos} sin respuesta` : ''}).`;
}

async function conciliarCajaComfenalco(simular) {
    const estado = document.getElementById('ceps-estado');
    if (!await revisarSesionCajaConciliacion()) { alert('Primero inicia sesión en la Sucursal Virtual de Comfenalco.'); return; }
    if (!simular && !confirm('Se bajará la lista de trabajadores afiliados de la empresa y se pondrán en OK los radicados de caja que la caja confirme. ¿Continuar?')) return;

    document.getElementById('ceps-acciones').style.display = 'none';
    estado.style.display = 'block';
    estado.innerHTML = '⏳ Bajando los trabajadores afiliados de la empresa...';
    try {
        const rep = await cajaExt('ccfTrabajadores', {}, 150);
        if (!rep.ok) throw new Error(rep.error || 'No se pudo bajar la lista del portal.');
        if (!coincideRazon(rep.nit, rep.empresa)) { estado.innerHTML = 'Cancelado: la empresa del portal no es la que escogiste.'; return; }
        estado.innerHTML = `⏳ Cruzando ${rep.filas.length} afiliados de ${rep.empresa} con BryNex...`;
        const res = await fetch(CAJA_URL_CONCILIAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ nit: rep.nit, filas: rep.filas, simular }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.mensaje || 'No se pudo conciliar.');
        pintarConciliacionEpsSura(data);
        estado.innerHTML = `${simular ? '<strong>(solo consulta)</strong> ' : ''}${data.empresa}${(data.aliados || []).length > 1 ? ` (aliados: ${data.aliados.join(', ')})` : ''}: ${data.afiliados_caja} afiliados en la caja` +
            (data.confirmados_ok ? ` · ${data.confirmados_ok} que ya estaban en OK quedan confirmados` : '') + '.';
        if (!simular && data.cerrados > 0) mostrarToast(`${data.cerrados} radicados de caja pasaron a OK. Recarga para verlos.`, 'success');
    } catch (err) {
        estado.innerHTML = '❌ ' + err.message;
    } finally {
        document.getElementById('ceps-acciones').style.display = 'flex';

        // Los subsidios van en la misma pasada, y también si la conciliación
        // falla: que no se pueda bajar la lista de afiliados no dice nada de
        // los morosos, y dejarlos sin mirar obligaría a repetir el recorrido.
        await revisarSubsidiosComfenalco(simular);
    }
}

/**
 * Los subsidios que Comfenalco tiene retenidos, convertidos en tareas.
 *
 * Su consulta de morosos e inexactos es **por empresa**, así que con una
 * pantalla se cubre la nómina entera: no hace falta ni la lista de candidatos
 * para saber a quién preguntar, solo para saber de quién responde BryNex.
 */
async function revisarSubsidiosComfenalco(simular = false) {
    const est = document.getElementById('ceps-caja-subsidios-estado');
    const pinta = (t) => { if (est) est.textContent = t; };

    pinta('Consultando morosos e inexactos en Comfenalco…');

    const leido = await cajaExt('ccfMorosos', {}, 200).catch(e => ({ error: String(e?.message || e) }));

    if (!leido?.ok) { pinta('El portal no respondió: ' + (leido?.error || 'sin detalle')); return; }
    if (!leido.nit) { pinta('El portal no mostró el NIT de la empresa de la sesión.'); return; }

    const url = COMFANDI_URL_SUBSIDIOS_CANDIDATOS + '?nit=' + encodeURIComponent(leido.nit) + '&alcance=candidatos&caja=COMFENALCO';
    const cand = await fetch(url, { headers: { 'Accept': 'application/json' } }).then(r => r.json()).catch(() => null);

    if (!cand?.ok) { pinta('No se pudo consultar la lista de candidatos.'); return; }

    const grupo = Object.values(cand.por_empresa || {})[0] || [];
    if (!grupo.length) { pinta('✅ ' + (leido.empresa || leido.nit) + ': nadie por revisar hoy en Comfenalco.'); return; }

    const res = await fetch(COMFANDI_URL_SUBSIDIOS, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({
            nit: leido.nit,
            caja: 'COMFENALCO',
            alcance: 'candidatos',
            simular,
            movimientos: leido.movimientos || [],
            // La consulta es de la empresa entera: quien no salió en las tablas
            // también quedó mirado, y por eso su tarea se puede cerrar.
            revisados: grupo.map(c => c.cedula),
        }),
    }).then(r => r.json()).catch(() => null);

    if (!res?.ok) { pinta('No se pudo guardar: ' + (res?.mensaje || res?.message || 'error')); return; }

    pinta((simular ? '🔎 (solo consulta) ' : '✅ ') + grupo.length + ' revisados · ' + res.bloqueos + ' retenidos · '
        + res.nuevas + (simular ? ' tarea(s) se abrirían' : ' tarea(s) nueva(s)') + ' · ' + res.cerradas + (simular ? ' se cerrarían' : ' cerrada(s)')
        + (res.sin_contrato ? ' · ' + res.sin_contrato + ' sin contrato' : ''));
}

// ── Razón social elegida para conciliar ───────────────────────────────
// El portal manda la empresa de su sesión; esto sirve para que BryNex avise
// cuando no es la que se quería validar. Sin esta comprobación se puede correr
// una empresa creyendo que se corrió otra, que ya nos pasó.
function razonConciliacion() {
    const s = document.getElementById('ceps-razon');
    if (!s || !s.value) return null;
    return { nit: s.value, nombre: s.selectedOptions[0]?.dataset.nombre || '' };
}

/** Solo dígitos, y sin el dígito de verificación que algunos portales pegan. */
function cepsNitCorto(nit) {
    const n = String(nit || '').split('-')[0].replace(/\D/g, '');
    return n.length >= 10 ? n.slice(0, -1) : n;
}

/**
 * Comprueba que la empresa abierta en el portal sea la elegida.
 * Devuelve true si se puede seguir (coinciden, o el usuario acepta seguir igual).
 */
function coincideRazon(nitDelPortal, empresaDelPortal) {
    const r = razonConciliacion();
    if (!r || !nitDelPortal) return true;
    if (cepsNitCorto(nitDelPortal) === cepsNitCorto(r.nit)) return true;
    return confirm(`Escogiste ${r.nombre} (NIT ${r.nit}) pero en el portal está abierta `
        + `${empresaDelPortal || 'otra empresa'} (NIT ${nitDelPortal}).\n\n`
        + 'Se conciliaría la del portal. ¿Sigues igual?');
}

function cambiarRazonConciliacion() {
    const r = razonConciliacion();
    const aviso = document.getElementById('ceps-razon-aviso');
    aviso.innerHTML = r
        ? `Se validará <strong>${r.nombre}</strong> (NIT ${r.nit}). BryNex comprueba que sea la abierta en el portal.`
        : 'Escoge con cuál empresa vas a conciliar: BryNex comprueba que la sesión abierta en el portal sea esa.';
}

// ── S.O.S.: conciliación con la extensión BryNex Portales ──
// El login de S.O.S. pide reCAPTCHA, así que la sesión la abre la persona en
// este navegador y la extensión consulta dentro de ella. `sosnExt` es el mismo
// puente del modal de novedad (partial _novedad_sos).
const SOSC_URL_PENDIENTES = @json(route('admin.afiliaciones.sos.conciliar.pendientes'));
const SOSC_URL_CONCILIAR = @json(route('admin.afiliaciones.sos.conciliar'));
const SOSC_URL_ESTADO = @json(route('admin.afiliaciones.sos.conciliar.estado'));

/**
 * S.O.S. no dice el NIT de la empresa en sesión, solo su nombre, así que se
 * comparan los nombres sin la forma jurídica. Conciliar con la empresa
 * equivocada marcaría "falta radicar" a gente que sí está afiliada en otra.
 */
function coincideEmpresaSos(empresaDelPortal) {
    const r = razonConciliacion();
    if (!r) return false;
    const limpio = t => String(t || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/\b(SOCIEDAD POR ACCIONES SIMPLIFICADA|S\.?A\.?S\.?|SAS|LTDA|S\.?A\.?)\b/g, '').replace(/[^A-Z0-9]/g, '');
    const mio = limpio(r.nombre), suyo = limpio(empresaDelPortal);
    if (!suyo || mio === suyo || mio.includes(suyo) || suyo.includes(mio)) return true;
    return confirm(`Escogiste ${r.nombre} pero en S.O.S. está abierta ${empresaDelPortal}.\n\n`
        + 'Se conciliaría con la sesión que está abierta. ¿Sigues igual?');
}

/** El aviso de que S.O.S. está esperando a que alguien entre. */
function pintarPeticionSos(peticion) {
    const caja = document.getElementById('ceps-sos-peticion');
    if (!caja) return;
    const muestra = _cepsEntidad === 'sos' && peticion;
    caja.style.display = muestra ? 'block' : 'none';
    if (!muestra) return;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    caja.innerHTML = `📣 <strong>S.O.S. está esperando desde el ${esc(peticion.desde)}:</strong> ${esc(peticion.motivo)}.`
        + ' Esta revisión cierra el pedido.';
}

async function revisarSesionSosConciliacion() {
    const caja = document.getElementById('ceps-sos-sesion');
    if (!caja) return null;
    const e = await sosnExt('estado', {}, 25);
    const abrir = `<button type="button" onclick="sosnExt('abrir').then(() => revisarSesionSosConciliacion())" class="btn-export" style="background:#b45309;cursor:pointer;margin-left:0.4rem;">🌐 Abrir S.O.S.</button>`;
    if (e.sinExtension) { caja.innerHTML = '🧩 Instala o recarga la extensión BryNex Portales (1.18.0) y recarga esta página.'; return null; }
    if (!e.abierta || !e.sesion) { caja.innerHTML = '🔐 Abre S.O.S. en otra pestaña e inicia sesión con el usuario de la empresa (el captcha lo resuelves tú).' + abrir; return null; }
    caja.innerHTML = `✅ S.O.S. abierto${e.empresa ? ' con <strong>' + e.empresa + '</strong>' : ''}.` + abrir;
    return e;
}

async function conciliarSos(simular) {
    const estado = document.getElementById('ceps-estado');
    const razon = razonConciliacion();
    if (!razon) { alert('Escoge primero la razón social: S.O.S. solo muestra las novedades de la empresa que tenga la sesión abierta.'); return; }

    const sesion = await revisarSesionSosConciliacion();
    if (!sesion) { alert('Primero inicia sesión en S.O.S. en otra pestaña de este navegador.'); return; }
    if (!coincideEmpresaSos(sesion.empresa)) { return; }
    if (!simular && !confirm(`Se consultará en S.O.S. a la gente de ${razon.nombre} con radicado abierto y se actualizarán sus radicados. ¿Continuar?`)) return;

    document.getElementById('ceps-acciones').style.display = 'none';
    estado.style.display = 'block';
    estado.innerHTML = '⏳ Preguntando a BryNex a quién hay que consultar...';
    try {
        const pend = await (await fetch(SOSC_URL_PENDIENTES + '?nit=' + encodeURIComponent(razon.nit), { headers: { 'Accept': 'application/json' } })).json();
        const documentos = pend.documentos || [];
        if (!documentos.length) { estado.innerHTML = `✅ ${razon.nombre} no tiene radicados de S.O.S. abiertos: nada que conciliar.`; return; }

        estado.innerHTML = `⏳ Consultando ${documentos.length} cédula(s) en S.O.S. (unos segundos cada una)...`;
        const rep = await sosnExt('consultas', { documentos, desde: pend.desde, hasta: pend.hasta }, 60 + 40 * documentos.length);
        if (!rep.ok) throw new Error(rep.error || 'No se pudo consultar el portal.');

        estado.innerHTML = `⏳ Cruzando ${rep.consultados} respuesta(s) de S.O.S. con BryNex...`;
        const res = await fetch(SOSC_URL_CONCILIAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ nit: razon.nit, resultados: rep.resultados, simular }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.mensaje || 'No se pudo conciliar.');

        pintarConciliacionEpsSura(data);
        const fallaron = Object.keys(rep.fallos || {}).length;
        estado.innerHTML = `${simular ? '<strong>(solo consulta)</strong> ' : ''}${razon.nombre}: ${data.total} radicado(s) revisados`
            + (data.tareas ? ` · ${data.tareas} tarea(s) por devolución de S.O.S.` : '')
            + (fallaron ? ` · ⚠️ ${fallaron} cédula(s) sin respuesta del portal` : '') + '.';
        if (!simular && data.cerrados > 0) mostrarToast(`${data.cerrados} radicados de S.O.S. pasaron a OK. Recarga para verlos.`, 'success');
    } catch (err) {
        estado.innerHTML = '❌ ' + err.message;
    } finally {
        document.getElementById('ceps-acciones').style.display = 'flex';
    }
}

// ── Caja Comfandi: conciliación con la extensión BryNex Portales ──
const COMFANDI_URL_CONCILIAR = @json(route('admin.afiliaciones.caja-comfandi.conciliar'));
const COMFANDI_URL_ESTADO = @json(route('admin.afiliaciones.caja-comfandi.conciliar.estado'));
const COMFANDI_URL_SUBSIDIOS_CANDIDATOS = @json(route('admin.afiliaciones.caja-comfandi.subsidios.candidatos'));
const COMFANDI_URL_SUBSIDIOS = @json(route('admin.afiliaciones.caja-comfandi.subsidios'));

function comfandiExt(accion, datos = {}, limiteSeg = 120) {
    return brynexExt('cfd', accion, datos, limiteSeg);
}

async function abrirPortalComfandiConciliacion() {
    const r = await comfandiExt('cfdAbrir', {}, 60);
    if (r?.avisoTipo) document.getElementById('ceps-comfandi-sesion').innerHTML += `<br>⚠️ ${r.avisoTipo}`;
}

async function revisarSesionComfandiConciliacion(reintentos = 2) {
    const caja = document.getElementById('ceps-comfandi-sesion');
    let e = await comfandiExt('cfdEstado', {}, 25);

    // El letrero "actualmente estás en" desaparece mientras el portal navega,
    // y entonces parecía que la sesión se había caído en plena gestión.
    for (let i = 0; i < reintentos && e?.abierta && !e?.sesion; i++) {
        await new Promise(r => setTimeout(r, 1500));
        e = await comfandiExt('cfdEstado', {}, 25);
    }
    const abrir = `<button type="button" onclick="abrirPortalComfandiConciliacion()" class="btn-export" style="background:#1e3a8a;cursor:pointer;margin-left:0.4rem;">🌐 Abrir la Sucursal Virtual</button>`;
    if (e.sinExtension) { caja.innerHTML = '🧩 Instala o recarga la extensión BryNex Portales (1.8.0) y recarga esta página.'; return null; }
    if (!e.abierta || !e.sesion) {
        caja.innerHTML = '⚠️ Inicia sesión con el NIT de la empresa y selecciona la empresa (si ofrece la verificación en dos pasos, «Omitir por ahora»).' + abrir;
        return null;
    }
    caja.innerHTML = `✅ Portal abierto${e.empresa ? ' con <strong>' + e.empresa + '</strong>' : ''}.` + abrir;
    return e;
}

/**
 * Revisión de subsidios bloqueados de la empresa abierta en el portal.
 *
 * El portal responde de a un trabajador, así que BryNex dice a quién preguntarle
 * —los sospechosos del día o, con `completa`, todos los vigentes— y la extensión
 * los recorre. Lo que vuelve se convierte en tareas: las nuevas se abren y las
 * que la caja ya no reporta se cierran solas.
 *
 * Solo se manda la cédula de gente de ESA empresa: buscar en el portal a alguien
 * de otra no devuelve nada, y BryNex lo leería como "ya no tiene bloqueo".
 */
/**
 * Dispara la revisión del día la primera vez que alguien abre la pestaña con la
 * sesión del portal lista, y no más: es el "una vez al día" del proceso.
 *
 * La marca de que ya se hizo vive en BryNex (`caja_revisiones`), no en el
 * navegador, así que da igual quién la haya corrido o desde qué equipo.
 */
let _subsidiosDisparados = false;

async function revisarSubsidiosSiFalta() {
    if (_subsidiosDisparados) return;
    _subsidiosDisparados = true;

    const est = document.getElementById('ceps-comfandi-subsidios-estado');
    try {
        // El candado es por empresa, así que primero hay que saber cuál está
        // abierta: preguntarlo sin NIT respondería "no se ha hecho" siempre y
        // el portal se recorrería entero cada vez que alguien abre la pestaña.
        const emp = await comfandiExt('cfdEmpresa', {}, 40).catch(() => null);
        if (!emp?.nit) return;

        const cand = await fetch(COMFANDI_URL_SUBSIDIOS_CANDIDATOS + '?nit=' + encodeURIComponent(emp.nit) + '&alcance=candidatos',
            { headers: { 'Accept': 'application/json' } }).then(r => r.json());

        if (!cand?.ok) return;

        if (cand.ya_revisado_hoy) {
            if (est) est.textContent = '✅ Los subsidios de ' + (emp.empresa || emp.nit) + ' ya se revisaron hoy.';
            return;
        }

        if (!cand.total) {
            if (est) est.textContent = '✅ Hoy no hay nadie por revisar en ' + (emp.empresa || emp.nit) + '.';
            return;
        }

        await revisarSubsidiosDe('candidatos', false, emp,
            (t) => { if (est) est.textContent = t; });
    } catch (e) {
        if (est) est.textContent = '';
    }
}

/**
 * Pone la extensión al día sin ir a chrome://extensions.
 *
 * Los cambios en la extensión no llegan solos: hay que recargarla, y esa página
 * de Chrome no se puede automatizar. La extensión sabe recargarse a sí misma,
 * así que basta pedírselo desde aquí.
 */
async function recargarExtensionPortales() {
    // El aviso va en la barra de la extensión, que se ve desde cualquier pestaña.
    const est = document.getElementById('ceps-ext-estado');
    const antes = document.documentElement.dataset.brynexPortales || '—';

    const card = document.getElementById('ceps-ext-card');
    if (card) card.style.display = 'flex';

    if (!document.documentElement.dataset.brynexPortales) {
        if (est) est.innerHTML = '<span style="color:#fca5a5;font-weight:700;">No está instalada en este navegador.</span> Descárgala primero.';
        const pasos = document.getElementById('ceps-ext-pasos');
        if (pasos) pasos.style.display = 'block';
        return;
    }

    if (est) est.textContent = 'Actualizando la extensión…';
    const r = await brynexExt('sys', 'recargar', {}, 20);

    if (!r?.ok) {
        if (est) est.innerHTML = 'Esta versión todavía no sabe actualizarse sola: hazlo una última vez en <code>chrome://extensions</code> (botón Actualizar).';
        return;
    }

    // Tras el reload la página necesita recargarse para volver a enlazar con
    // ella: el puente viejo quedó desconectado.
    if (est) est.textContent = 'Extensión recargada (estaba en ' + antes + '). Recargando la página…';
    setTimeout(() => location.reload(), 1500);
}

async function revisarSubsidiosComfandi(alcance, simular = false, nitConocido = null) {
    const est = document.getElementById('ceps-comfandi-subsidios-estado');
    const pinta = (t) => { if (est) est.textContent = t; };

    // Cuando la llama la conciliación, la empresa ya está comprobada y su NIT
    // leído: no hay que volver a preguntárselo al portal.
    if (nitConocido) return revisarSubsidiosDe(alcance, simular, { nit: nitConocido }, pinta);

    if (!await revisarSesionComfandiConciliacion()) { alert('Primero inicia sesión en la Sucursal Virtual Empresas de Comfandi.'); return; }

    pinta('Leyendo la empresa abierta…');
    const emp = await comfandiExt('cfdEmpresa', {}, 40).catch(e => ({ error: String(e?.message || e) }));

    // La acción es de la 1.13.0: con la extensión sin recargar, el portal
    // responde "acción desconocida" y sin este aviso parecería un fallo del
    // portal.
    if (/desconocida/i.test(emp?.error || '')) {
        pinta('🧩 Recarga la extensión BryNex Portales en chrome://extensions (debe quedar en 1.13.0) y vuelve a intentar.');
        return;
    }

    if (!emp?.nit) { pinta('No se pudo leer el NIT de la empresa abierta en el portal' + (emp?.error ? ': ' + emp.error : '.')); return; }

    return revisarSubsidiosDe(alcance, simular, emp, pinta);
}

/** El recorrido en sí, ya sabiendo de qué empresa se trata. */
async function revisarSubsidiosDe(alcance, simular, emp, pinta) {
    pinta('Pidiendo a quién consultar…');
    const url = COMFANDI_URL_SUBSIDIOS_CANDIDATOS + '?nit=' + encodeURIComponent(emp.nit) + '&alcance=' + alcance;
    const cand = await fetch(url, { headers: { 'Accept': 'application/json' } }).then(r => r.json()).catch(() => null);
    if (!cand?.ok) { pinta('No se pudo consultar la lista de candidatos.'); return; }

    const grupo = Object.values(cand.por_empresa || {})[0] || [];
    if (!grupo.length) { pinta('✅ ' + (emp.empresa || emp.nit) + ': nadie por revisar hoy.'); return; }

    // El candado del día frena al disparo automático, no a quien pulsa el botón:
    // si alguien lo pide a mano es porque quiere mirar otra vez.

    const documentos = grupo.map(c => c.cedula);
    pinta('Consultando ' + documentos.length + ' trabajador(es) en el portal… (unos ' + Math.ceil(documentos.length * 10 / 60) + ' min)');

    const leido = await comfandiExt('cfdSubsidios', { documentos, meses: 4 }, 60 * documentos.length + 120);
    if (!leido?.ok) { pinta('El portal no respondió: ' + (leido?.error || 'sin detalle')); return; }

    // Si no se pudo abrir la pantalla de ninguno, el motivo está en los errores
    // de la extensión, no en BryNex: decirlo aquí evita el "no se pudo guardar"
    // a secas, que no señalaba a ninguna parte.
    if (!(leido.revisados || []).length) {
        pinta('⚠️ El portal no dejó abrir el subsidio monetario de ninguno de los ' + documentos.length
            + ': ' + ((leido.errores || [])[0]?.error || 'sin detalle')
            + ' Recarga la extensión BryNex Portales y vuelve a intentar.');
        return;
    }

    pinta('Guardando en BryNex…');
    const res = await fetch(COMFANDI_URL_SUBSIDIOS, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({
            nit: emp.nit,
            alcance,
            simular,
            movimientos: leido.movimientos || [],
            revisados: leido.revisados || [],
        }),
    }).then(r => r.json()).catch(() => null);

    if (!res?.ok) { pinta('No se pudo guardar: ' + (res?.mensaje || res?.message || 'error')); return; }

    const fallos = (leido.errores || []).length;
    pinta((simular ? '🔎 (solo consulta) ' : '✅ ') + (leido.revisados || []).length + ' revisados · ' + res.bloqueos + ' bloqueos · '
        + res.nuevas + (simular ? ' tarea(s) se abrirían' : ' tarea(s) nueva(s)') + ' · ' + res.cerradas + (simular ? ' se cerrarían' : ' cerrada(s)')
        + (res.sin_contrato ? ' · ' + res.sin_contrato + ' sin contrato' : '')
        + (fallos ? ' · ' + fallos + ' no se pudieron consultar' : ''));
}

async function conciliarCajaComfandi(simular) {
    const estado = document.getElementById('ceps-estado');
    if (!await revisarSesionComfandiConciliacion()) { alert('Primero inicia sesión en la Sucursal Virtual Empresas de Comfandi.'); return; }
    if (!simular && !confirm('Se bajarán el listado de trabajadores y los radicados de la empresa, y se pondrán en OK los radicados de caja que Comfandi confirme. ¿Continuar?')) return;

    document.getElementById('ceps-acciones').style.display = 'none';
    estado.style.display = 'block';
    estado.innerHTML = '⏳ Pidiendo el listado de trabajadores al portal (lo genera como un radicado, tarda unos segundos)...';
    try {
        // El Excel del portal trae la empresa entera y los beneficiarios; la
        // tabla, que pagina de a 5, solo se usa si no se pudo bajar el archivo.
        const lista = await comfandiExt('cfdListado', {}, 300);
        estado.innerHTML = '⏳ Leyendo los radicados del portal...';
        const rep = await comfandiExt('cfdTrabajadores', {}, 300);

        if (!lista.ok && !rep.ok) throw new Error(lista.error || rep.error || 'No se pudo bajar la información del portal.');

        const nitPortal = lista.ok ? lista.nit : rep.nit;
        if (!coincideRazon(nitPortal, lista.empresa || rep.empresa)) {
            estado.innerHTML = 'Cancelado: la empresa del portal no es la que escogiste.';
            return;
        }

        const cuerpo = {
            nit: lista.ok ? lista.nit : rep.nit,
            radicados: rep.ok ? rep.radicados : [],
            radicados_ok: rep.ok ? rep.radicadosOk !== false : false,
            simular,
        };
        if (lista.ok) {
            cuerpo.archivo_url = lista.archivoUrl;
            estado.innerHTML = `⏳ Cruzando el listado ${lista.radicadoListado} de ${lista.empresa || lista.nit} con BryNex...`;
        } else {
            cuerpo.filas = rep.filas;
            estado.innerHTML = `⚠️ No se pudo bajar el Excel (${lista.error}); se usa la tabla. Cruzando ${rep.filas.length} afiliados...`;
        }

        const res = await fetch(COMFANDI_URL_CONCILIAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify(cuerpo),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.mensaje || 'No se pudo conciliar.');
        pintarConciliacionEpsSura(data);
        estado.innerHTML = `${simular ? '<strong>(solo consulta)</strong> ' : ''}${data.empresa}${(data.aliados || []).length > 1 ? ` (aliados: ${data.aliados.join(', ')})` : ''}: ` +
            `${data.afiliados_caja} afiliados en la caja y ${data.radicados_portal} radicados en el portal` +
            (data.radicados_leidos === false ? ' <strong style="color:#b45309">⚠️ no se pudo leer la pestaña Radicados: los que no aparecen afiliados quedaron para revisar, no como pendientes</strong>' : '') +
            (data.beneficiarios_nuevos ? ` · <strong>${data.beneficiarios_nuevos} beneficiarios</strong> de ${data.beneficiarios_personas} trabajadores ${simular ? 'se guardarían' : 'guardados'} en BryNex` : '') +
            (data.confirmados_ok ? ` · ${data.confirmados_ok} que ya estaban en OK quedan confirmados` : '') + '.';
        if (!simular && data.cerrados > 0) mostrarToast(`${data.cerrados} radicados de caja pasaron a OK. Recarga para verlos.`, 'success');

    } catch (err) {
        estado.innerHTML = '❌ ' + err.message;
    } finally {
        document.getElementById('ceps-acciones').style.display = 'flex';

        // Los subsidios van en la misma pasada, y también cuando la
        // conciliación falla: que no se pueda generar el Excel del listado no
        // tiene nada que ver con los bloqueos, y dejarlos sin revisar por eso
        // obligaba a repetir todo el recorrido.
        await revisarSubsidiosComfandi(
            document.getElementById('ceps-comfandi-subsidios-todos')?.checked ? 'completa' : 'candidatos',
            simular
        );
    }
}

// ── Sanitas: conciliación con la extensión BryNex Portales ──
const SANITAS_URL_CONCILIAR = @json(route('admin.afiliaciones.sanitas.conciliar'));
const SANITAS_URL_ESTADO = @json(route('admin.afiliaciones.sanitas.conciliar.estado'));

function brynexExt(portal, accion, datos = {}, limiteSeg = 120) {
    return new Promise((resolve) => {
        if (!document.documentElement.dataset.brynexPortales) {
            resolve({ ok: false, sinExtension: true, error: 'La extensión BryNex Portales no está instalada en este navegador.' });
            return;
        }
        const id = Date.now() + '-' + Math.random().toString(36).slice(2);
        const oyente = (ev) => {
            if (ev.source !== window || ev.data?.canal !== 'brynex-portales' || ev.data.tipo !== 'respuesta' || ev.data.id !== id) return;
            window.removeEventListener('message', oyente); clearTimeout(alarma);
            resolve(ev.data.respuesta || { ok: false, error: 'Respuesta vacía de la extensión.' });
        };
        window.addEventListener('message', oyente);
        const alarma = setTimeout(() => { window.removeEventListener('message', oyente); resolve({ ok: false, error: 'La extensión no respondió a tiempo.' }); }, limiteSeg * 1000);
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal, accion, datos }, window.location.origin);
    });
}

async function revisarSesionSanitas() {
    const caja = document.getElementById('ceps-sanitas-sesion');
    const e = await brynexExt('sanitas', 'estado', {}, 30);
    const botonAbrir = `<button type="button" onclick="brynexExt('sanitas','abrir')" class="btn-export" style="background:#0e7490;cursor:pointer;margin-left:0.4rem;">🌐 Abrir Sanitas</button>`;
    if (e.sinExtension) {
        caja.innerHTML = '🧩 Falta la extensión <strong>BryNex Portales</strong>. Se descarga desde Afiliaciones → 🩺 Conciliar → botón 🧩 Extensión; después recarga esta página.';
    } else if (!e.ok) {
        caja.innerHTML = '⚠️ ' + e.error;
    } else if (!e.sesion) {
        caja.innerHTML = '🔐 Abre la Oficina Virtual de Empleadores de Sanitas en otra pestaña e inicia sesión (captcha y código al correo); luego vuelve aquí.' + botonAbrir +
            (e.captcha ? '<br>⚠️ Sanitas está pidiendo el captcha anti-robots en esa pestaña: resuélvelo allá.' : '');
    } else {
        caja.innerHTML = `✅ Sesión de Sanitas abierta: <strong>${e.empresa}</strong> (NIT ${e.nit}). Se concilian los radicados de esa empresa.`;
    }
    return e;
}

async function conciliarSanitas(simular) {
    const e = await revisarSesionSanitas();
    if (!e.ok || !e.sesion) { mostrarToast('Primero inicia sesión en la Oficina Virtual de Sanitas.', 'error'); return; }
    if (!simular && !confirm(`Se cruzará el Estado de Afiliación de Sanitas de ${e.empresa} y los radicados que estén HABILITADOS pasarán a OK confirmado. ¿Continuar?`)) return;

    const estado = document.getElementById('ceps-estado');
    estado.style.display = 'block';
    estado.innerHTML = `⏳ Bajando el Estado de Afiliación de ${e.empresa}…`;
    document.getElementById('ceps-acciones').style.display = 'none';

    try {
        const rep = await brynexExt('sanitas', 'estadoAfiliacion', {}, 120);
        if (!rep.ok) throw new Error(rep.error || 'No se pudo bajar el Estado de Afiliación.');
        estado.innerHTML = '⏳ Cruzando con los radicados de BryNex…';
        const res = await fetch(SANITAS_URL_CONCILIAR, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ nit: rep.nit, txt: rep.txt, simular }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.mensaje || 'No se pudo conciliar.');
        pintarConciliacionEpsSura(data);
        estado.innerHTML = `${simular ? '<strong>(solo consulta)</strong> ' : ''}${data.empresa}${(data.aliados || []).length > 1 ? ` (aliados: ${data.aliados.join(', ')})` : ''}: ${data.afiliados_sanitas} afiliados en Sanitas (${data.habilitados_sanitas} habilitados)` +
            (data.confirmados_ok ? ` · ${data.confirmados_ok} que ya estaban en OK quedan confirmados` : '') + '.';
        if (!simular && data.cerrados > 0) mostrarToast(`${data.cerrados} radicados de Sanitas pasaron a OK. Recarga para verlos.`, 'success');
    } catch (err) {
        estado.innerHTML = '❌ ' + err.message;
    } finally {
        document.getElementById('ceps-acciones').style.display = 'flex';
    }
}

// ── Toast ──
function mostrarToast(msg, tipo) {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:1.5rem;right:1.5rem;background:${tipo==='success'?'#15803d':'#b91c1c'};color:#fff;padding:0.7rem 1.2rem;border-radius:10px;font-size:0.85rem;font-weight:600;z-index:9999;box-shadow:0 4px 15px rgba(0,0,0,0.2);animation:modalIn .2s ease;`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
@endpush
@include('admin.partials._modal_claves_globales')


@can('automatizar-arl')
@include('admin.partials._afiliar_arl_sura')
@include('admin.partials._afiliar_arl_colmena')
@endcan
@can('automatizar-portales')
@include('admin.partials._reingreso_nueva_eps')
@include('admin.partials._reingreso_eps_sura')
@include('admin.partials._novedad_salud_total')
@include('admin.partials._novedad_sos')
@include('admin.partials._novedad_sanitas')
@include('admin.partials._correo_eps')
@include('admin.partials._novedad_boxalud')
@include('admin.partials._afiliar_caja_comfenalco')
@include('admin.partials._afiliar_caja_comfandi')
@endcan

@endsection
