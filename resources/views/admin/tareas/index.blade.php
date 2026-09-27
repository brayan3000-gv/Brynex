@extends('layouts.app')
@section('titulo', 'Tareas')
@section('modulo', 'Gestión de Tareas')

@push('styles')
<style>
/* ── Tareas: layout de altura completa, solo tbody scrollea ── */
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

:root {
    --verde:   #10b981; --amarillo: #f59e0b;
    --rojo:    #ef4444; --azul:     #3b82f6;
    --naranja: #f97316; --gris:     #6b7280;
}
.tareas-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.tareas-title  { font-size:1.35rem; font-weight:700; color:#1e293b; }

/* Tarjetas resumen mini */
.resumen-card-mini {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-left: 3.5px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.3rem 0.6rem;
    cursor: pointer;
    transition: transform 0.1s ease, box-shadow 0.1s ease;
    box-sizing: border-box;
    font-size: 0.73rem;
}
.resumen-card-mini:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.resumen-card-mini .rc-num {
    font-size: 0.95rem;
    font-weight: 800;
}
.resumen-card-mini .rc-lbl {
    font-weight: 600;
    color: #475569;
}
.resumen-card-mini.pendiente { border-left-color: var(--amarillo); }
.resumen-card-mini.pendiente .rc-num { color: var(--amarillo); }
.resumen-card-mini.en_gestion { border-left-color: var(--azul); }
.resumen-card-mini.en_gestion .rc-num { color: var(--azul); }
.resumen-card-mini.en_espera { border-left-color: var(--naranja); }
.resumen-card-mini.en_espera .rc-num { color: var(--naranja); }
.resumen-card-mini.vencidas { border-left-color: var(--rojo); }
.resumen-card-mini.vencidas .rc-num { color: var(--rojo); }
.resumen-card-mini.cerradas { border-left-color: var(--gris); }
.resumen-card-mini.cerradas .rc-num { color: var(--gris); }

/* Filtros */
.filtros-bar { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; }
.filtros-bar select, .filtros-bar input { border:1px solid #cbd5e1; border-radius:8px; padding:.38rem .65rem; font-size:.8rem; background:#fff; color:#1e293b; }
.btn-filtrar { background:#2563eb; color:#fff; border:none; border-radius:8px; padding:.38rem .9rem; font-size:.8rem; font-weight:600; cursor:pointer; }
.btn-filtrar:hover { background:#1d4ed8; }
.btn-limpiar { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:8px; padding:.38rem .75rem; font-size:.8rem; cursor:pointer; text-decoration:none; }
.btn-nueva   { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; border-radius:9px; padding:.5rem 1.1rem; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:.4rem; box-shadow:0 2px 8px rgba(37,99,235,.3); }
.btn-nueva:hover { background:linear-gradient(135deg,#1d4ed8,#1e3a8a); }

/* Tabla */
.tabla-wrap { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow-x:auto; overflow-y:auto; flex:1; min-height:0; }
.tbl-tareas { width:100%; border-collapse:collapse; font-size:.8rem; }
.tbl-tareas thead th { background:linear-gradient(135deg,#0a1628,#0d2550); color:rgba(255,255,255,.85); padding:.65rem .75rem; white-space:nowrap; font-weight:600; font-size:.73rem; letter-spacing:.04em; }
.tbl-tareas thead th:first-child { border-radius:0; }
/* Encabezados ordenables */
.th-sort { color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; cursor:pointer; }
.th-sort:hover { color:#fff; }
.th-sort .th-arrow { font-size:.58rem; opacity:.4; }
.th-sort:hover .th-arrow { opacity:.8; }
.th-sort.activo { color:#fbbf24; }
.th-sort.activo .th-arrow { opacity:1; }
.tbl-tareas tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.tbl-tareas tbody tr:hover { background:#f8fafc; }
.tbl-tareas td { padding:.6rem .75rem; vertical-align:middle; color:#334155; }

/* Semáforo pastilla */
.semaforo { display:inline-flex; align-items:center; gap:.4rem; border-radius:99px; padding:.22rem .65rem; font-size:.72rem; font-weight:700; white-space:nowrap; }
.semaforo.verde   { background:rgba(16,185,129,.12); color:#065f46; border:1px solid rgba(16,185,129,.3); }
.semaforo.amarillo{ background:rgba(245,158,11,.12); color:#92400e; border:1px solid rgba(245,158,11,.3); }
.semaforo.rojo    { background:rgba(239,68,68,.12);  color:#991b1b; border:1px solid rgba(239,68,68,.3); }
.semaforo.azul    { background:rgba(59,130,246,.12); color:#1e40af; border:1px solid rgba(59,130,246,.3); }
.semaforo.naranja { background:rgba(249,115,22,.12); color:#9a3412; border:1px solid rgba(249,115,22,.3); }
.semaforo.gris    { background:rgba(107,114,128,.1); color:#374151; border:1px solid rgba(107,114,128,.25); }

/* Badges estado */
.badge { display:inline-block; border-radius:99px; padding:.2rem .6rem; font-size:.68rem; font-weight:600; }
.badge.pendiente  { background:#fef3c7; color:#92400e; }
.badge.en_gestion { background:#dbeafe; color:#1e40af; }
.badge.en_espera  { background:#ffedd5; color:#9a3412; }
.badge.cerrada    { background:#f0fdf4; color:#166534; }

/* Acciones tabla */
.btn-accion { width:28px; height:28px; border:none; border-radius:7px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:.85rem; transition:background .15s; }
.btn-accion.ver     { background:#eff6ff; color:#2563eb; }
.btn-accion.gestion { background:#f0fdf4; color:#16a34a; }
.btn-accion.traslado{ background:#fff7ed; color:#ea580c; }
.btn-accion.cerrar  { background:#fef2f2; color:#dc2626; }
.btn-accion:hover   { opacity:.8; transform:scale(1.08); }

/* Modales */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; display:none; align-items:center; justify-content:center; padding:1rem; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.2); width:100%; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; }
.modal-box.sm { max-width:520px; }
.modal-box.md { max-width:720px; }
.modal-box.lg { max-width:960px; }
.modal-head { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0; background:linear-gradient(135deg,#0a1628,#0d2550); }
.modal-head h3 { color:#fff; font-size:.95rem; font-weight:700; }
.btn-modal-close { background:rgba(255,255,255,.15); border:none; color:#fff; border-radius:7px; width:30px; height:30px; cursor:pointer; font-size:1.1rem; display:flex; align-items:center; justify-content:center; }
.btn-modal-close:hover { background:rgba(255,255,255,.25); }
.modal-body  { flex:1; overflow-y:auto; padding:1.25rem; }
.modal-foot  { padding:.85rem 1.25rem; border-top:1px solid #f1f5f9; display:flex; gap:.6rem; justify-content:flex-end; }

/* Formulario modal */
.form-row   { display:grid; gap:.75rem; margin-bottom:.75rem; }
.form-row.col2 { grid-template-columns:1fr 1fr; }
.form-row.col3 { grid-template-columns:1fr 1fr 1fr; }
.form-group label { display:block; font-size:.75rem; font-weight:600; color:#475569; margin-bottom:.3rem; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:.45rem .7rem;
    font-size:.82rem; color:#1e293b; font-family:inherit; transition:border .15s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1);
}
.form-group textarea { resize:vertical; min-height:80px; }

/* Sugerencias cédula */
.autocomplete-list { position:absolute; z-index:999; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; width:100%; left:0; top:calc(100% + 4px); }
.autocomplete-item { padding:.5rem .85rem; cursor:pointer; font-size:.8rem; color:#1e293b; border-bottom:1px solid #f8fafc; }
.autocomplete-item:hover { background:#f0f9ff; }
.autocomplete-wrap { position:relative; }

/* Solicitud del portal de empresas */
.sol-portal { background:#fff; border:1px solid #bfdbfe; border-radius:12px; padding:1rem 1.1rem; margin-bottom:1rem; box-shadow:0 2px 10px rgba(37,99,235,.06); }
.sp-cab { display:flex; justify-content:space-between; align-items:center; gap:.6rem; margin-bottom:.7rem; font-size:.85rem; color:#1e3a8a; }
.sp-sub { color:#64748b; font-weight:500; font-size:.75rem; }
.sp-estado { border-radius:999px; padding:.15rem .6rem; font-size:.68rem; font-weight:700; white-space:nowrap; }
.sp-warn { background:#fef3c7; color:#92400e; } .sp-ok { background:#dcfce7; color:#15803d; } .sp-err { background:#fee2e2; color:#b91c1c; }
.sp-datos { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.4rem; }
.sp-dato { background:#f8fafc; border-radius:8px; padding:.4rem .55rem; }
.sp-dato small { display:block; font-size:.62rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.03em; }
.sp-dato div { font-size:.8rem; color:#0f172a; font-weight:600; white-space:pre-wrap; overflow-wrap:anywhere; }
.sp-archivos { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.6rem; }
.sp-archivo { font-size:.72rem; font-weight:600; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; border-radius:7px; padding:.25rem .55rem; text-decoration:none; }
.sp-acciones { border-top:1px dashed #cbd5e1; margin-top:.8rem; padding-top:.8rem; }
.sp-ruaf { font-size:.78rem; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:.45rem .65rem; margin-bottom:.6rem; }
.sp-pasos { display:grid; gap:.5rem; }
.sp-pasos > div { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.sp-pasos small { color:#64748b; font-size:.7rem; }
.sp-num { width:20px; height:20px; border-radius:50%; background:#1e40af; color:#fff; font-size:.68rem; font-weight:800; display:grid; place-items:center; }
.sp-btn { display:inline-flex; align-items:center; gap:.3rem; background:#2563eb; color:#fff; border:none; border-radius:8px; padding:.4rem .8rem; font-size:.76rem; font-weight:700; cursor:pointer; text-decoration:none; transition:background .15s, transform .1s; }
.sp-btn:hover { background:#3b82f6; transform:translateY(-1px); color:#fff; }
.sp-btn:disabled { opacity:.6; cursor:wait; transform:none; }
.sp-sec { background:#f1f5f9; color:#334155; } .sp-sec:hover { background:#e2e8f0; color:#334155; }
.sp-rojo { background:#dc2626; } .sp-rojo:hover { background:#ef4444; }
.sp-fila { display:flex; align-items:flex-end; gap:.6rem; flex-wrap:wrap; font-size:.75rem; color:#475569; font-weight:600; }
.sp-fila label { display:flex; flex-direction:column; gap:.2rem; }
.sp-fila .sp-check { flex-direction:row; align-items:center; gap:.3rem; padding-bottom:.45rem; }
.sp-fila input[type=date], .sp-fila select { padding:.35rem .5rem; border:1px solid #cbd5e1; border-radius:7px; font-size:.78rem; }
.sp-nota { display:block; color:#64748b; font-size:.7rem; margin-top:.4rem; }
.sp-alerta { flex-basis:100%; font-size:.72rem; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:7px; padding:.35rem .55rem; }
.sp-rechazo { margin-top:.7rem; font-size:.75rem; }
.sp-rechazo summary { cursor:pointer; color:#b91c1c; font-weight:700; }
.sp-rechazo textarea { width:100%; min-height:55px; margin:.5rem 0; border:1px solid #cbd5e1; border-radius:7px; padding:.4rem .55rem; font-size:.78rem; box-sizing:border-box; }
.visible-empresa { align-items:center; gap:.4rem; font-size:.75rem; font-weight:600; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:.4rem .6rem; margin-top:.4rem; cursor:pointer; }
.ve-pill { display:inline-block; margin-left:.35rem; font-size:.6rem; font-weight:700; color:#1d4ed8; background:#dbeafe; border-radius:999px; padding:.05rem .45rem; vertical-align:middle; }

/* Timeline gestiones */
.timeline { padding:.5rem 0; }
.tl-item  { display:flex; gap:.85rem; margin-bottom:1rem; align-items:flex-start; }
.tl-dot   { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; margin-top:.1rem; }
.tl-dot.tramite      { background:#dbeafe; }
.tl-dot.traslado     { background:#ffedd5; }
.tl-dot.cambio_estado{ background:#f0fdf4; }
.tl-dot.nota         { background:#f8fafc; border:1px solid #e2e8f0; }
.tl-content   { flex:1; }
.tl-meta      { font-size:.7rem; color:#94a3b8; margin-bottom:.2rem; }
.tl-obs       { font-size:.8rem; color:#334155; background:#f8fafc; border-radius:8px; padding:.5rem .75rem; border-left:3px solid #e2e8f0; }
.tl-alerta    { font-size:.7rem; color:#92400e; background:#fef3c7; border-radius:6px; padding:.2rem .5rem; display:inline-block; margin-top:.3rem; }

/* Docs lista */
.docs-list { display:flex; flex-direction:column; gap:.5rem; }
.doc-item  { display:flex; align-items:center; gap:.75rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:.6rem .85rem; }
.doc-icon  { font-size:1.3rem; }
.doc-info  { flex:1; }
.doc-info .doc-name { font-size:.82rem; font-weight:600; color:#1e293b; }
.doc-info .doc-meta { font-size:.68rem; color:#94a3b8; }
.btn-download { background:#eff6ff; color:#2563eb; border:none; border-radius:7px; padding:.3rem .65rem; font-size:.75rem; cursor:pointer; text-decoration:none; font-weight:600; }

/* Botones */
.btn-primary   { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; border-radius:9px; padding:.5rem 1.1rem; font-size:.82rem; font-weight:700; cursor:pointer; }
.btn-secondary { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:9px; padding:.5rem 1rem; font-size:.82rem; cursor:pointer; }
.btn-danger    { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:9px; padding:.5rem 1rem; font-size:.82rem; cursor:pointer; font-weight:700; }
.btn-success   { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:9px; padding:.5rem 1rem; font-size:.82rem; cursor:pointer; font-weight:700; }
.btn-glass     { background:rgba(37,99,235,0.08); border:1px solid rgba(37,99,235,0.2); color:#2563eb; border-radius:8px; padding:0.35rem 0.85rem; font-size:0.75rem; font-weight:700; cursor:pointer; transition:all 0.2s ease; display:inline-flex; align-items:center; gap:0.3rem; }
.btn-glass:hover { background:rgba(37,99,235,0.15); border-color:rgba(37,99,235,0.35); color:#1d4ed8; transform:translateY(-1px); }

.toast { position:fixed; bottom:1.5rem; right:1.5rem; background:#1e293b; color:#fff; padding:.75rem 1.25rem; border-radius:10px; font-size:.82rem; z-index:9999; box-shadow:0 4px 20px rgba(0,0,0,.25); display:none; }
.toast.show { display:block; animation:slideIn .25s ease; }
@keyframes slideIn { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }

.pag-wrap { display:flex; justify-content:space-between; align-items:center; padding:.75rem 1rem; border-top:1px solid #f1f5f9; font-size:.78rem; color:#64748b; }

/* Pestañas del modal unificado */
.modal-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 1rem;
    gap: 0.25rem;
    background: #f8fafc;
    padding: 0.25rem 0.25rem 0 0.25rem;
    border-radius: 8px 8px 0 0;
}
.modal-tab {
    padding: 0.5rem 0.85rem;
    cursor: pointer;
    font-size: 0.78rem;
    font-weight: 700;
    color: #64748b;
    border: none;
    border-bottom: 3px solid transparent;
    background: transparent;
    transition: all 0.15s;
    outline: none;
    border-radius: 6px 6px 0 0;
}
.modal-tab:hover {
    color: #1e293b;
    background: #f1f5f9;
}
.modal-tab.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
    background: #fff;
}
.tab-content {
    display: none;
    animation: fadeInTab 0.2s ease;
}
.tab-content.active {
    display: block;
}
@keyframes fadeInTab {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Selector de sub-acciones */
.acciones-selector {
    display: flex;
    gap: 0.4rem;
    margin-bottom: 1rem;
    background: #f1f5f9;
    padding: 0.25rem;
    border-radius: 8px;
    align-items: center;
}
.btn-subaccion {
    flex: 1;
    border: none;
    background: transparent;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.45rem 0.5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: center;
    white-space: nowrap;
}
.btn-subaccion:hover {
    color: #1e293b;
    background: rgba(255,255,255,0.4);
}
.btn-subaccion.active {
    background: #fff;
    color: #0f172a;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.subaccion-content {
    display: none;
}
.subaccion-content.active {
    display: block;
}

/* Layout de dos columnas en pestaña Historial */
.historial-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
}
@media (min-width: 768px) {
    .historial-layout {
        grid-template-columns: 1.2fr 1fr;
    }
}
.historial-col {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
</style>
@endpush

@section('contenido')
@php
    $tipos = \App\Models\Tarea::TIPOS;
    // Filtro y edición también conocen las que abre una empresa desde su
    // portal; crear a mano sigue ofreciendo solo las de siempre ($tipos).
    $tiposTodos = $tipos + \App\Models\Tarea::TIPOS_PORTAL;
    $estados = \App\Models\Tarea::ESTADOS;
@endphp

<div class="tar-wrap" style="flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 0.4rem; overflow: hidden;">
    {{-- Fila 1: Tareas Header (KPIs a la izquierda, botones clave a la derecha) --}}
    <div class="tareas-header-row" style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:0.4rem 0.8rem;">
        {{-- KPIs a la izquierda --}}
        <div style="display:flex; align-items:center; gap:0.45rem; flex-wrap:wrap;">
            <div class="resumen-card-mini pendiente" onclick="filtrarEstado('pendiente')" title="Tareas Pendientes">
                <span class="rc-num">{{ $resumenEstados['pendiente'] ?? 0 }}</span>
                <span class="rc-lbl">⏳ Pendientes</span>
            </div>
            <div class="resumen-card-mini en_gestion" onclick="filtrarEstado('en_gestion')" title="Tareas En Gestión">
                <span class="rc-num">{{ $resumenEstados['en_gestion'] ?? 0 }}</span>
                <span class="rc-lbl">🔵 En Gestión</span>
            </div>
            <div class="resumen-card-mini en_espera" onclick="filtrarEstado('en_espera')" title="Tareas En Espera">
                <span class="rc-num">{{ $resumenEstados['en_espera'] ?? 0 }}</span>
                <span class="rc-lbl">🟠 En Espera</span>
            </div>
            <div class="resumen-card-mini vencidas" title="Tareas Vencidas">
                <span class="rc-num">{{ $vencidas }}</span>
                <span class="rc-lbl">🔴 Vencidas</span>
            </div>
            <div class="resumen-card-mini cerradas" onclick="filtrarEstado('cerrada')" title="Tareas Cerradas">
                <span class="rc-num">{{ $resumenEstados['cerrada'] ?? 0 }}</span>
                <span class="rc-lbl">✅ Cerradas</span>
            </div>
        </div>

        {{-- Botones clave a la derecha --}}
        <div style="display:flex; gap:0.4rem; align-items:center;">
            <button type="button" onclick="abrirModalClavesGlobal()" style="background:linear-gradient(135deg,#fbbf24,#f59e0b); color:#1c1917; border:none; border-radius:8px; padding:0.35rem 0.8rem; font-size:0.75rem; font-weight:800; cursor:pointer; box-shadow:0 1px 4px rgba(0,0,0,0.1); display:inline-flex; align-items:center; gap:0.25rem; height:30px; white-space:nowrap;">🔑 Claves</button>
            <a href="{{ route('admin.tareas.reporte') }}" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:8px; padding:0.35rem 0.8rem; font-size:0.75rem; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; height:30px; box-sizing:border-box;">📊 Reporte</a>
            <button class="btn-nueva" onclick="abrirModalNueva()" style="height:30px; align-items:center; box-sizing:border-box; padding:0.35rem 0.8rem; font-size:0.75rem; border-radius:8px;">＋ Nueva Tarea</button>
        </div>
    </div>

    {{-- Fila 2: Filtros --}}
    <form method="GET" id="filtrosForm" style="margin:0;">
    <div class="filtros-bar" style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:0.4rem 0.8rem; margin-bottom:0.1rem;">
        <select name="encargado_id" onchange="this.form.submit()" style="padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;">
            <option value="">👤 Todos los encargados</option>
            @foreach($trabajadores as $t)
                <option value="{{ $t->id }}" {{ request('encargado_id') == $t->id ? 'selected' : '' }}>{{ $t->nombre }}</option>
            @endforeach
        </select>
        
        <select name="tipo" onchange="this.form.submit()" style="padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;">
            <option value="">📋 Todos los tipos</option>
            @foreach($tiposTodos as $key => $label)
                @php $cnt = $resumenTipos[$key] ?? 0; @endphp
                <option value="{{ $key }}" {{ request('tipo') === $key ? 'selected' : '' }}>
                    {{ $label }} {{ $cnt > 0 ? "($cnt)" : '' }}
                </option>
            @endforeach
        </select>

        <select name="estado" onchange="this.form.submit()" style="padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;">
            <option value="">📌 Todos los estados</option>
            @foreach($estados as $k => $v)
                <option value="{{ $k }}" {{ request('estado') === $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>

        <select name="semaforo" onchange="this.form.submit()" style="padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;">
            <option value="">🚦 Todos los semáforos</option>
            <option value="urgente" {{ request('semaforo') === 'urgente' ? 'selected' : '' }}>🔴 Urgentes / Vencidas</option>
            <option value="en_espera" {{ request('semaforo') === 'en_espera' ? 'selected' : '' }}>🔵 En espera - Recordar</option>
        </select>

        <input name="cedula" value="{{ request('cedula') }}" placeholder="🔍 Cédula..." style="width:130px; padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;" onchange="this.form.submit()">

        {{-- Empresa del cliente: solo las que tienen clientes con tareas --}}
        @include('components.filtro-empresa', [
            'empresas'    => $empresasDisponibles,
            'formId'      => 'filtrosForm',
            'estiloInput' => 'padding:0.3rem 0.5rem; font-size:0.75rem; height:28px; box-sizing:border-box;',
        ])

        <input type="hidden" name="cerradas" value="{{ request('cerradas') }}" id="hiddenCerradas">
        
        <label style="font-size:.72rem; color:#64748b; display:flex; align-items:center; gap:.25rem; cursor:pointer; user-select:none; height:28px; margin:0;">
            <input type="checkbox" name="cerradas" value="1" {{ request('cerradas') ? 'checked' : '' }} onchange="this.form.submit()"> Ver cerradas
        </label>
        
        <a href="{{ route('admin.tareas.index') }}" class="btn-limpiar" style="padding:0.3rem 0.6rem; font-size:0.75rem; height:28px; box-sizing:border-box; display:inline-flex; align-items:center; justify-content:center;">✕ Limpiar</a>
    </div>
    </form>

    {{-- Tabla --}}
    <div class="tabla-wrap" style="overflow-x:auto; overflow-y:auto; border-radius:12px; border:1px solid #e2e8f0; background:#fff; flex:1; min-height:0;">
    <table class="tbl-tareas">
        @php
            // Encabezados ordenables: primer clic asc, segundo desc.
            $ordenActual = request('orden');
            $dirActual   = strtolower(request('dir')) === 'desc' ? 'desc' : 'asc';
            $thSort = function (string $clave, string $label) use ($ordenActual, $dirActual) {
                $activo    = $ordenActual === $clave;
                $siguiente = ($activo && $dirActual === 'asc') ? 'desc' : 'asc';
                $flecha    = $activo ? ($dirActual === 'asc' ? '▲' : '▼') : '⇅';
                $url = request()->fullUrlWithQuery(['orden' => $clave, 'dir' => $siguiente, 'page' => null]);
                return '<a href="' . e($url) . '" class="th-sort' . ($activo ? ' activo' : '') . '">'
                     . e($label) . '<span class="th-arrow">' . $flecha . '</span></a>';
            };
        @endphp
        <thead>
            <tr>
                <th>🚦</th>
                <th>{!! $thSort('creada', 'Creada') !!}</th>
                <th>{!! $thSort('tipo', 'Tipo') !!}</th>
                <th>{!! $thSort('cliente', 'Cliente / Cédula') !!}</th>
                <th>Tarea / Última gestión</th>
                <th>{!! $thSort('encargado', 'Encargado') !!}</th>
                <th>{!! $thSort('estado', 'Estado') !!}</th>
                <th>{!! $thSort('limite', 'Límite') !!}</th>
                <th>Días</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        @forelse($tareas as $t)
        @php
            $color = $t->colorSemaforo();
            $dias  = $t->diasRestantes();
            $diasText = $t->estado === 'cerrada' ? '—' : ($dias < 0 ? abs($dias).'d venc.' : $dias.'d');
        @endphp
        <tr style="border-left: 3.5px solid {{ $color === 'rojo' ? '#ef4444' : ($color === 'amarillo' ? '#f59e0b' : ($color === 'azul' ? '#3b82f6' : ($color === 'naranja' ? '#f97316' : 'transparent'))) }};">
            <td>
                <span class="semaforo {{ $color }}">
                    {{ $t->iconoSemaforo() }}
                    @if($color === 'rojo') Vencida
                    @elseif($color === 'amarillo') Urgente
                    @elseif($color === 'azul') Recordar
                    @elseif($color === 'naranja') Espera
                    @elseif($color === 'verde') OK
                    @else —
                    @endif
                </span>
            </td>
            <td style="font-size:.75rem;white-space:nowrap;color:#64748b;" title="{{ $t->created_at?->format('d/m/Y h:i a') }}">{{ $t->created_at?->format('d/m/y') ?? '—' }}</td>
            <td style="font-size:.73rem;font-weight:600;color:#475569;">{{ $t->tipoLabel() }}</td>
            {{-- Cliente: nombre y cédula en el mismo bloque para no gastar
                 una línea entera solo con el número --}}
            <td style="max-width:185px;">
                <div style="display:flex;align-items:flex-start;gap:.3rem;">
                    <button type="button" class="btn-ficha-cliente"
                            data-cedula="{{ $t->cedula }}"
                            data-nombre="{{ nombre_oracion($t->nombre_cliente) }}"
                            title="Ver ficha del cliente"
                            style="margin-top:.05rem;">👤</button>
                    <div style="font-size:.76rem;font-weight:600;color:#1e293b;line-height:1.25;max-width:165px;">
                        {{ nombre_oracion($t->nombre_cliente) }}
                        <span style="font-weight:500;color:#94a3b8;white-space:nowrap;">{{ $t->cliente?->tipo_doc ?: 'CC' }} {{ $t->cedula }}</span>
                    </div>
                </div>
            </td>
            <td style="max-width:340px;">
                <div style="font-size:.78rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;" title="{{ $t->tarea }}">{{ Str::limit($t->tarea, 75) }}</div>
                <div style="display:flex;align-items:center;gap:0.4rem;margin-top:0.2rem;flex-wrap:wrap;">
                    @if($t->numero_radicado)
                        <span style="font-size:.68rem;color:#64748b;background:#f1f5f9;padding:0.05rem 0.25rem;border-radius:4px;">📄 {{ $t->numero_radicado }}</span>
                    @endif
                    @if($t->documentos_count > 0)
                        <span style="font-size:0.65rem;color:#2563eb;background:#eff6ff;padding:0.05rem 0.25rem;border-radius:4px;font-weight:700;" title="{{ $t->documentos_count }} documentos adjuntos">📎 {{ $t->documentos_count }}</span>
                    @endif
                </div>
                {{-- Última gestión: reemplaza al contador 💬 N, que no decía nada --}}
                @if($t->ultimaGestion)
                    @php
                        $ug     = $t->ultimaGestion;
                        $ugText = trim($ug->observacion ?: (\App\Models\TareaGestion::TIPOS_ACCION[$ug->tipo_accion] ?? $ug->tipo_accion));
                        $ugTitle = ($ug->created_at?->format('d/m/Y h:i a') ?? '')
                                 . ' · ' . ($ug->user?->nombre ?? '?')
                                 . ' · ' . $ugText;
                    @endphp
                    <div style="display:flex;align-items:baseline;gap:.3rem;margin-top:.25rem;max-width:340px;" title="{{ $ugTitle }}">
                        <span style="font-size:.65rem;color:#16a34a;flex-shrink:0;">💬</span>
                        <span style="font-size:.68rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;">{{ $ug->created_at?->format('d/m/y') ?? '' }}</span>
                        <span style="font-size:.7rem;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $ugText }}</span>
                        @if($t->gestiones_count > 1)
                            <span style="font-size:.6rem;color:#cbd5e1;flex-shrink:0;" title="{{ $t->gestiones_count }} gestiones en total">+{{ $t->gestiones_count - 1 }}</span>
                        @endif
                    </div>
                @endif
            </td>
            <td style="font-size:.78rem;font-weight:600;">{{ $t->encargado?->nombre ?? '—' }}</td>
            <td><span class="badge {{ $t->estado }}">{{ $t->estadoLabel() }}</span></td>
            <td style="font-size:.75rem;white-space:nowrap;">{{ $t->fecha_limite?->format('d/m/y') ?? '—' }}</td>
            <td style="font-size:.75rem;font-weight:700;color:{{ $color === 'rojo' ? '#ef4444' : ($color === 'amarillo' ? '#f59e0b' : '#64748b') }};">{{ $diasText }}</td>
            <td>
                <button class="btn-accion ver" onclick="abrirModalUnico({{ $t->id }})" style="width: auto; padding: 0 .5rem; display: inline-flex; align-items: center; gap: .25rem; font-size: .75rem; font-weight: 700;" title="Gestionar Tarea">
                    ⚙️ Gestionar
                </button>
            </td>
        </tr>
        @empty
        <tr><td colspan="10" style="text-align:center;padding:2.5rem;color:#94a3b8;font-size:.85rem;">No hay tareas con los filtros actuales.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if($tareas->hasPages())
    <div class="pag-wrap">
        <span>Mostrando {{ $tareas->firstItem() }}–{{ $tareas->lastItem() }} de {{ $tareas->total() }}</span>
        <div style="display:flex;gap:.3rem;">{{ $tareas->links() }}</div>
    </div>
    @endif
    </div>
</div>

{{-- ══════════ MODAL NUEVA / EDITAR TAREA ══════════ --}}
<div class="modal-overlay" id="modalNueva">
<div class="modal-box md">
    <div class="modal-head">
        <h3 id="modalNuevaTitulo">➕ Nueva Tarea</h3>
        <button class="btn-modal-close" onclick="cerrarModal('modalNueva')">✕</button>
    </div>
    <div class="modal-body">
    <form id="formNueva" method="POST" action="{{ route('admin.tareas.store') }}">
        @csrf
        <div class="form-row col2">
            <div class="form-group">
                <label>Tipo de Tarea *</label>
                <select name="tipo" required onchange="actualizarLimiteTipo(this.value)">
                    <option value="">— Seleccionar —</option>
                    @foreach($tipos as $k => $v)
                        <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Encargado *</label>
                <select name="encargado_id" required>
                    <option value="">— Seleccionar —</option>
                    @foreach($trabajadores as $t)
                        <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-row col2">
            <div class="form-group autocomplete-wrap">
                <label>Cédula del Cliente *</label>
                <input type="text" name="cedula" id="inputCedula" placeholder="Buscar cédula..." required autocomplete="off" oninput="buscarCliente(this.value)">
                <div class="autocomplete-list" id="listaCedulas" style="display:none;"></div>
            </div>
            <div class="form-group">
                <label>Nombre del Cliente</label>
                <input type="text" id="nombreClienteDisplay" readonly placeholder="Se carga al seleccionar cédula..." style="background:#f8fafc;">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Contrato (opcional)</label>
                <select name="contrato_id" id="selectContrato" onchange="actualizarInfoContratoNueva(this)">
                    <option value="">— Sin contrato —</option>
                </select>
                <div id="nuevaContratoDetalleInfo" style="margin-top:0.4rem; font-size:0.75rem; display:none; padding:0.4rem 0.6rem; border-radius:6px; background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1; font-weight:600;"></div>
            </div>
            <input type="hidden" name="razon_social_id" value="">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Entidad donde se realiza el trámite</label>
                <input type="text" name="entidad" placeholder="Ej: Nueva EPS, Compensar, Porvenir...">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Descripción de la Tarea *</label>
                <textarea name="tarea" required placeholder="Detalle la tarea a realizar..."></textarea>
            </div>
        </div>
        {{-- Ocultar campos que no se usan --}}
        <input type="hidden" name="fecha_radicado" value="">
        <input type="hidden" name="numero_radicado" value="">
        <input type="hidden" name="correo" value="">
        <div class="form-row">
            <div class="form-group">
                <label>Observación adicional</label>
                <textarea name="observacion" placeholder="Información adicional..." style="min-height:60px;"></textarea>
            </div>
        </div>
    </form>
    </div>
    <div class="modal-foot">
        <button class="btn-secondary" onclick="cerrarModal('modalNueva')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('formNueva').submit()">💾 Crear Tarea</button>
    </div>
</div>
</div>

{{-- ══════════ MODAL UNIFICADO (DETALLES, GESTIONES Y ACCIONES) ══════════ --}}
<div class="modal-overlay" id="modalUnificado">
<div class="modal-box lg">
    <div class="modal-head">
        <h3 id="modalUnificadoTitulo">⚙️ Gestionar Tarea</h3>
        <button class="btn-modal-close" onclick="cerrarModal('modalUnificado')">✕</button>
    </div>
    
    <div class="modal-tabs" style="display: flex; justify-content: space-between; align-items: center; padding-right: 1.25rem;">
        <div style="display: flex; gap: 0.25rem;">
            <button class="modal-tab active" id="tabBtnDetalles" onclick="cambiarTab('detalles')">📝 Gestión y Detalles</button>
            <button class="modal-tab" id="tabBtnAcciones" onclick="cambiarTab('acciones')">⚡ Acciones</button>
        </div>
        <div>
            <button type="button" class="btn-glass" id="btnHabilitarEditar" onclick="toggleEdicionInline(true)" style="padding:0.25rem 0.65rem; font-size:0.72rem; display:flex; align-items:center; gap:0.25rem;">
                ✏️ Editar Datos
            </button>
        </div>
    </div>
    
    <div class="modal-body">
        
        {{-- PESTAÑA 1: GESTIÓN Y DETALLES --}}
        <div id="tab-detalles" class="tab-content active">
            
            {{-- Visualización de Detalles (Modo Solo Lectura) --}}
            <div id="contenedorDetalles" class="detalle-tarea-container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div class="form-row col3" style="margin-bottom:0.75rem; gap:1rem;">
                    <div>
                        <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.15rem;">TIPO DE TAREA</span>
                        <span id="detTipo" style="font-size:0.82rem; font-weight:700; color:#1e293b;">—</span>
                        <span id="detCreada" style="font-size:0.7rem; color:#94a3b8; display:block;">—</span>
                    </div>
                    <div>
                        <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.15rem;">ENCARGADO</span>
                        <span id="detEncargado" style="font-size:0.82rem; font-weight:700; color:#1e293b;">—</span>
                    </div>
                    <div>
                        <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.15rem;">CLIENTE</span>
                        <span style="font-size:0.82rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:0.35rem;">
                            <span id="detClienteNombre">Cargando...</span>
                            <a href="#" id="linkFichaCliente" target="_blank" title="Ver ficha del cliente" style="color:#2563eb; font-size:0.82rem; display:inline-flex; align-items:center; text-decoration:none;"><i class="fas fa-external-link-alt"></i></a>
                        </span>
                        <span id="detClienteCedula" style="font-size:0.7rem; color:#94a3b8; display:block;">—</span>
                    </div>
                </div>
                <div class="form-row col3" style="margin-bottom:0.75rem; gap:1rem;">
                    <div style="grid-column: span 2;">
                        <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.15rem;">CONTRATO / ASOCIADO</span>
                        <span id="detContrato" style="font-size:0.82rem; font-weight:600; color:#334155;">—</span>
                    </div>
                    <div style="grid-column: span 1;">
                        <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.15rem;">ENTIDAD</span>
                        <span id="detEntidad" style="font-size:0.82rem; font-weight:600; color:#334155;">—</span>
                    </div>
                </div>
                <div style="margin-bottom:0.5rem;">
                    <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.2rem;">DESCRIPCIÓN DE LA TAREA</span>
                    <div id="detTarea" style="font-size:0.82rem; color:#1e293b; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:0.5rem 0.75rem; font-family:inherit; white-space:pre-wrap; border-left: 3px solid var(--azul-btn);">—</div>
                </div>
                <div id="detObservacionRow" style="margin-top:0.75rem; display:none;">
                    <span style="font-size:0.68rem; font-weight:700; color:#64748b; display:block; letter-spacing: 0.05em; margin-bottom: 0.2rem;">OBSERVACIÓN ADICIONAL</span>
                    <div id="detObservacion" style="font-size:0.8rem; color:#475569; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:0.5rem 0.75rem; white-space:pre-wrap;">—</div>
                </div>
            </div>

            {{-- Solicitud del portal de empresas (si la tarea la abrió una empresa) --}}
            <div id="detSolicitud" class="sol-portal" style="display:none;"></div>

            {{-- Formulario de Edición (Habilitable inline) --}}
            <div id="contenedorFormEditar" class="detalle-tarea-container" style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:1.25rem; margin-bottom:1rem; display:none; box-shadow: 0 1px 4px rgba(59,130,246,0.05);">
                <form id="formEditar" method="POST" action="">
                    @csrf
                    @method('PUT')
                    <div class="form-row col2">
                        <div class="form-group">
                            <label>Tipo de Tarea *</label>
                            <select name="tipo" id="editTipo" required>
                                <option value="">— Seleccionar —</option>
                                @foreach($tiposTodos as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Encargado *</label>
                            <select name="encargado_id" id="editEncargado" required>
                                <option value="">— Seleccionar —</option>
                                @foreach($trabajadores as $t)
                                    <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-row col2">
                        <div class="form-group autocomplete-wrap">
                            <label>Cédula del Cliente *</label>
                            <input type="text" name="cedula" id="editCedula" placeholder="Buscar cédula..." required autocomplete="off" oninput="buscarClienteEdicion(this.value)">
                            <div class="autocomplete-list" id="listaCedulasEdicion" style="display:none;"></div>
                        </div>
                        <div class="form-group">
                            <label>Nombre del Cliente</label>
                            <input type="text" id="editNombreClienteDisplay" readonly placeholder="Cargando..." style="background:#f8fafc;">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Contrato (opcional)</label>
                            <select name="contrato_id" id="editContrato" onchange="actualizarInfoContratoEdicion(this)">
                                <option value="">— Sin contrato —</option>
                            </select>
                            <div id="editContratoDetalleInfo" style="margin-top:0.4rem; font-size:0.75rem; display:none; padding:0.4rem 0.6rem; border-radius:6px; background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1; font-weight:600;"></div>
                        </div>
                        <input type="hidden" name="razon_social_id" id="editRazonSocial" value="">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Entidad donde se realiza el trámite</label>
                            <input type="text" name="entidad" id="editEntidad" placeholder="Ej: Nueva EPS, Compensar, Porvenir...">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Descripción de la Tarea *</label>
                            <textarea name="tarea" id="editTarea" required placeholder="Detalle la tarea..."></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Observación adicional</label>
                            <textarea name="observacion" id="editObservacion" placeholder="Información adicional..." style="min-height:60px;"></textarea>
                        </div>
                    </div>
                    {{-- Ocultar campos que no se usan --}}
                    <input type="hidden" name="fecha_radicado" id="editFechaRadicado" value="">
                    <input type="hidden" name="numero_radicado" id="editNumeroRadicado" value="">
                    <input type="hidden" name="correo" id="editCorreo" value="">
                    
                    <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:0.5rem;">
                        <button type="button" class="btn-secondary" onclick="toggleEdicionInline(false)" style="padding:0.35rem 0.75rem; font-size:0.78rem;">Cancelar</button>
                        <button type="button" class="btn-primary" onclick="enviarEditarUnificado()" style="padding:0.35rem 0.75rem; font-size:0.78rem;">💾 Guardar Cambios</button>
                    </div>
                </form>
            </div>

            <div id="msgEdicionCerrada" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:.75rem 1rem;font-size:.8rem;font-weight:600;margin-bottom:1rem;">
                ⚠️ Esta tarea está cerrada. Los datos se muestran en modo de solo lectura.
            </div>

            {{-- ──────────────── SECCIÓN GESTIONES (TABLA Y FORM INLINE) ──────────────── --}}
            <div style="border-top:1px solid #e2e8f0; padding-top:1.25rem; margin-top:1.5rem;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; flex-wrap:wrap; gap:0.5rem;">
                    <strong style="font-size:0.85rem; color:#1e293b; display:flex; align-items:center; gap:0.35rem;">
                        📋 Gestiones Realizadas 
                        <span id="cantGestionesBadge" class="badge-info" style="font-size:0.68rem; padding:0.1rem 0.45rem; border-radius:999px;">0</span>
                    </strong>
                    <button type="button" class="btn-accion" id="btnNuevaGestionInline" onclick="toggleNuevaGestionForm(true)" style="width:auto; padding:0.35rem 0.75rem; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.3rem;">
                        ➕ Registrar Gestión
                    </button>
                </div>

                {{-- Formulario Inline Colapsable para Nueva Gestión --}}
                <div id="formNuevaGestionInline" style="display:none; background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02); animation: fadeInTab 0.2s ease;">
                    <h4 style="font-size:0.78rem; font-weight:700; color:#0369a1; margin-bottom:0.75rem; display:flex; align-items:center; gap:0.25rem;">💬 Registrar Gestión</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tipo de Acción</label>
                            <select id="uniGestionTipoAccion" onchange="actualizarEstadoGestionVisibilidad(this.value)">
                                <option value="cambio_estado" selected>🔄 Cambio de estado</option>
                                <option value="tramite_realizado">📋 Trámite realizado</option>
                                <option value="nota">📝 Nota / Observación</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row" id="uniRowNuevoEstado">
                        <div class="form-group">
                            <label>Nuevo Estado</label>
                            <select id="uniGestionNuevoEstado">
                                @foreach($estados as $k => $v)
                                    @if($k !== 'cerrada')
                                    <option value="{{ $k }}">{{ $v }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Observación / Trámite realizado *</label>
                            <textarea id="uniGestionObservacion" placeholder="Describa detalladamente el trámite o la nota..." style="min-height:75px;"></textarea>
                        </div>
                    </div>
                    <label class="visible-empresa" style="display:none;">
                        <input type="checkbox" id="uniGestionVisible"> 🌐 Que la vea <span class="ve-nombre">la empresa</span> en su portal
                    </label>
                    {{-- Ocultar campo de recordatorio a petición del usuario --}}
                    <input type="hidden" id="uniGestionRecordarDias" value="0">
                    <div style="display:flex; justify-content:flex-end; gap:0.4rem; margin-top:0.5rem;">
                        <button type="button" class="btn-secondary" onclick="toggleNuevaGestionForm(false)" style="padding:0.35rem 0.75rem; font-size:0.75rem;">Cancelar</button>
                        <button type="button" class="btn-primary" onclick="enviarGestionUnificada()" style="padding:0.35rem 0.75rem; font-size:0.75rem;">💾 Guardar Gestión</button>
                    </div>
                </div>

                {{-- Tabla de Gestiones --}}
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow-x:auto;">
                    <table class="tabla-brynex" style="width:100%; font-size:0.75rem; border-collapse:collapse; min-width:600px;" id="tablaGestiones">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                <th style="padding:0.5rem 0.75rem; text-align:left; font-weight:600; color:#475569; width:45px;">Acción</th>
                                <th style="padding:0.5rem 0.75rem; text-align:left; font-weight:600; color:#475569;">Observación / Detalle</th>
                                <th style="padding:0.5rem 0.75rem; text-align:left; font-weight:600; color:#475569; width:170px;">Usuario / Fecha</th>
                                <th style="padding:0.5rem 0.75rem; text-align:left; font-weight:600; color:#475569; width:125px;">Recordatorio</th>
                            </tr>
                        </thead>
                        <tbody id="uniTablaGestionesBody">
                            <!-- Se llena dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        {{-- PESTAÑA 2: ACCIONES --}}
        <div id="tab-acciones" class="tab-content">
            <div id="accionesDisponibles">
                <div class="acciones-selector">
                    <button class="btn-subaccion active" id="btnSubTraslado" onclick="mostrarSubAccion('traslado')">🔀 Trasladar</button>
                    <button class="btn-subaccion" id="btnSubCerrar" onclick="mostrarSubAccion('cerrar')">🏁 Cerrar Tarea</button>
                    <button class="btn-subaccion" id="btnSubDocumentos" onclick="mostrarSubAccion('documentos')">📎 Adjuntar Documentos</button>
                </div>
                
                {{-- SUB-ACCIÓN: TRASLADAR --}}
                <div id="sub-traslado" class="subaccion-content active">
                    <div style="background:#fff7ed;border-radius:8px;padding:.6rem .85rem;margin-bottom:.75rem;font-size:.75rem;border:1px solid #fed7aa;color:#9a3412;">
                        ⚠️ El traslado cambiará el encargado de la tarea y se registrará en la bitácora.
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nuevo Encargado *</label>
                            <select id="uniTrasladoEncargado">
                                @foreach($trabajadores as $t)
                                    <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Motivo del traslado *</label>
                            <textarea id="uniTrasladoObservacion" placeholder="Escriba el motivo detalladamente..." style="min-height:80px;"></textarea>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:.5rem;">
                        <button class="btn-primary" onclick="enviarTrasladoUnificado()">🔀 Trasladar Tarea</button>
                    </div>
                </div>
                
                {{-- SUB-ACCIÓN: CERRAR TAREA --}}
                <div id="sub-cerrar" class="subaccion-content">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Resultado *</label>
                            <div style="display:flex; gap:1rem; margin-top:0.4rem; max-width: 480px;">
                                <!-- Tarjeta Positivo -->
                                <div id="cardCerrarPositivo" onclick="seleccionarResultadoCierre('positivo')" style="flex:1; border:2px solid #22c55e; background:rgba(34,197,94,0.06); border-radius:10px; padding:0.6rem 0.85rem; cursor:pointer; text-align:center; transition:all 0.15s ease;">
                                    <span style="font-size:1rem; display:block; margin-bottom:0.15rem;">✅</span>
                                    <strong style="font-weight:700; font-size:0.78rem; color:#15803d; display:block; margin-bottom: 0.1rem;">Positivo</strong>
                                    <span style="font-size:0.65rem; color:#166534; display:block;">(Logrado con éxito)</span>
                                    <input type="radio" name="uniCerrarResultado" id="radioCerrarPositivo" value="positivo" checked style="display:none;">
                                </div>
                                
                                <!-- Tarjeta Negativo -->
                                <div id="cardCerrarNegativo" onclick="seleccionarResultadoCierre('negativo')" style="flex:1; border:2px solid #e2e8f0; background:#f8fafc; border-radius:10px; padding:0.6rem 0.85rem; cursor:pointer; text-align:center; transition:all 0.15s ease;">
                                    <span style="font-size:1rem; display:block; margin-bottom:0.15rem;">❌</span>
                                    <strong style="font-weight:700; font-size:0.78rem; color:#475569; display:block; margin-bottom: 0.1rem;">Negativo</strong>
                                    <span style="font-size:0.65rem; color:#64748b; display:block;">(No logrado)</span>
                                    <input type="radio" name="uniCerrarResultado" id="radioCerrarNegativo" value="negativo" style="display:none;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Observación de cierre *</label>
                            <textarea id="uniCerrarObservacion" placeholder="Describa el resultado final..." style="min-height:80px;"></textarea>
                        </div>
                    </div>
                    <label class="visible-empresa" style="display:none;">
                        <input type="checkbox" id="uniCerrarVisible" checked> 🌐 Que la vea <span class="ve-nombre">la empresa</span> en su portal
                    </label>
                    <div style="display:flex;justify-content:flex-end;margin-top:.5rem;">
                        <button class="btn-danger" onclick="enviarCerrarUnificado()">🏁 Cerrar Tarea Definitivamente</button>
                    </div>
                </div>

                {{-- SUB-ACCIÓN: DOCUMENTOS ADJUNTOS --}}
                <div id="sub-documentos" class="subaccion-content">
                    <div style="display:grid; grid-template-columns:1fr; gap:1rem;">
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem; display:flex; align-items:center; justify-content:space-between;">
                            <div>
                                <strong style="font-size:0.82rem; color:#1e293b;">Subir Archivo Adjunto</strong>
                                <span style="display:block; font-size:0.68rem; color:#64748b;">Límite de tamaño: 10MB</span>
                            </div>
                            <label style="background:var(--azul-btn); color:#fff; border-radius:8px; padding:0.45rem 1rem; font-size:0.78rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:0.25rem;">
                                ➕ Seleccionar y Subir <input type="file" style="display:none;" onchange="subirDocumentoUnificado(this)">
                            </label>
                        </div>
                        
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:1rem;">
                            <strong style="font-size:.82rem; display:block; margin-bottom:0.75rem; color:#1e293b;">📎 Lista de Archivos Adjuntos</strong>
                            <div id="uniDocsLista" class="docs-list" style="max-height:220px; overflow-y:auto; padding-right:0.25rem;">
                                <!-- Se llena dinámicamente -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="msgAccionesBloqueadas" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;border-radius:10px;padding:1.5rem;text-align:center;font-size:.85rem;font-weight:600;">
                🔒 Esta tarea está cerrada y no se pueden realizar nuevas acciones (gestiones, traslados o cierres).
            </div>
        </div>
        
    </div>
    
    <div class="modal-foot">
        <button class="btn-secondary" onclick="cerrarModal('modalUnificado')">Cerrar</button>
    </div>
</div>
</div>

<div class="toast" id="toast"></div>

{{-- @include('admin.partials._modal_claves_globales') --}}

@include('components.modal-cliente')

@endsection

@push('scripts')
<script>
let tareaIdActivo = null;

function abrirModalNueva() {
    document.getElementById('modalNueva').classList.add('open');
}
function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Selector visual de resultado de cierre
function seleccionarResultadoCierre(val) {
    const cardPos = document.getElementById('cardCerrarPositivo');
    const cardNeg = document.getElementById('cardCerrarNegativo');
    const radioPos = document.getElementById('radioCerrarPositivo');
    const radioNeg = document.getElementById('radioCerrarNegativo');
    
    if (!cardPos || !cardNeg || !radioPos || !radioNeg) return;
    
    if (val === 'positivo') {
        cardPos.style.borderColor = '#22c55e';
        cardPos.style.background = 'rgba(34,197,94,0.06)';
        cardPos.querySelector('strong').style.color = '#15803d';
        cardPos.querySelector('span:last-of-type').style.color = '#166534';
        radioPos.checked = true;
        
        cardNeg.style.borderColor = '#e2e8f0';
        cardNeg.style.background = '#f8fafc';
        cardNeg.querySelector('strong').style.color = '#475569';
        cardNeg.querySelector('span:last-of-type').style.color = '#64748b';
    } else {
        cardNeg.style.borderColor = '#ef4444';
        cardNeg.style.background = 'rgba(239,68,68,0.06)';
        cardNeg.querySelector('strong').style.color = '#b91c1c';
        cardNeg.querySelector('span:last-of-type').style.color = '#991b1b';
        radioNeg.checked = true;
        
        cardPos.style.borderColor = '#e2e8f0';
        cardPos.style.background = '#f8fafc';
        cardPos.querySelector('strong').style.color = '#475569';
        cardPos.querySelector('span:last-of-type').style.color = '#64748b';
    }
}

// Filtros rápidos
function filtrarEstado(estado) {
    const url = new URL(window.location);
    url.searchParams.set('estado', estado);
    window.location = url;
}
function filtrarTipo(tipo) {
    document.getElementById('hiddenTipo').value = tipo;
    document.getElementById('filtrosForm').submit();
}

// Autocompletado cédula
let cedulaTimer = null;
function buscarCliente(val) {
    clearTimeout(cedulaTimer);
    if (val.length < 3) { document.getElementById('listaCedulas').style.display='none'; return; }
    cedulaTimer = setTimeout(() => {
        fetch(`{{ route('admin.tareas.api.clientes') }}?cedula=${encodeURIComponent(val)}`)
            .then(r => r.json()).then(data => {
                const list = document.getElementById('listaCedulas');
                if (!data.length) { list.style.display='none'; return; }
                list.innerHTML = data.map(c =>
                    `<div class="autocomplete-item" onclick="seleccionarCliente('${c.cedula}','${c.primer_nombre} ${c.segundo_nombre??''} ${c.primer_apellido} ${c.segundo_apellido??''}')">
                        <strong>${c.cedula}</strong> — ${nombreOracion(c.primer_nombre+' '+c.primer_apellido)}
                    </div>`
                ).join('');
                list.style.display = 'block';
            });
    }, 350);
}
function seleccionarCliente(cedula, nombre) {
    document.getElementById('inputCedula').value = cedula;
    document.getElementById('nombreClienteDisplay').value = nombre.trim();
    document.getElementById('listaCedulas').style.display = 'none';
    // Cargar contratos
    fetch(`{{ route('admin.tareas.api.contratos') }}?cedula=${cedula}`)
        .then(r => r.json()).then(data => {
            const sel = document.getElementById('selectContrato');
            sel.innerHTML = '<option value="">— Sin contrato —</option>' +
                data.map(c => {
                    const est = c.estado === 'vigente' ? '🟢 Vigente' : '🔴 Retirado';
                    const rs = c.razon_social ? ` · ${c.razon_social}` : ' · Sin empresa';
                    const fIng = c.fecha_ingreso ? ` (Ingreso: ${c.fecha_ingreso})` : '';
                    return `<option value="${c.id}">ID: ${c.id} | ${est}${rs}${fIng}</option>`;
                }).join('');
            
            actualizarInfoContratoNueva(sel);
        });
}

function actualizarInfoContratoNueva(select) {
    const infoDiv = document.getElementById('nuevaContratoDetalleInfo');
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.value) {
        infoDiv.textContent = `📋 Detalle: ${selectedOption.text}`;
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
    }
}

// Autocompletado cédula Edición
let cedulaEdicionTimer = null;
function buscarClienteEdicion(val) {
    clearTimeout(cedulaEdicionTimer);
    if (val.length < 3) { document.getElementById('listaCedulasEdicion').style.display='none'; return; }
    cedulaEdicionTimer = setTimeout(() => {
        fetch(`{{ route('admin.tareas.api.clientes') }}?cedula=${encodeURIComponent(val)}`)
            .then(r => r.json()).then(data => {
                const list = document.getElementById('listaCedulasEdicion');
                if (!data.length) { list.style.display='none'; return; }
                list.innerHTML = data.map(c =>
                    `<div class="autocomplete-item" onclick="seleccionarClienteEdicion('${c.cedula}','${c.primer_nombre} ${c.segundo_nombre??''} ${c.primer_apellido} ${c.segundo_apellido??''}')">
                        <strong>${c.cedula}</strong> — ${nombreOracion(c.primer_nombre+' '+c.primer_apellido)}
                    </div>`
                ).join('');
                list.style.display = 'block';
            });
    }, 350);
}
function seleccionarClienteEdicion(cedula, nombre) {
    document.getElementById('editCedula').value = cedula;
    document.getElementById('editNombreClienteDisplay').value = nombre.trim();
    document.getElementById('listaCedulasEdicion').style.display = 'none';
    cargarContratosEdicion(cedula, null);
}

function cargarContratosEdicion(cedula, contratoIdSeleccionado) {
    fetch(`{{ route('admin.tareas.api.contratos') }}?cedula=${cedula}`)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('editContrato');
            sel.innerHTML = '<option value="">— Sin contrato —</option>' +
                data.map(c => {
                    const est = c.estado === 'vigente' ? '🟢 Vigente' : '🔴 Retirado';
                    const rs = c.razon_social ? ` · ${c.razon_social}` : ' · Sin empresa';
                    const fIng = c.fecha_ingreso ? ` (Ingreso: ${c.fecha_ingreso})` : '';
                    return `<option value="${c.id}" ${c.id == contratoIdSeleccionado ? 'selected' : ''}>ID: ${c.id} | ${est}${rs}${fIng}</option>`;
                }).join('');
            
            actualizarInfoContratoEdicion(sel);
            
            // También actualizar la visualización de solo lectura en la pestaña Detalles del modal
            const selectedOption = sel.options[sel.selectedIndex];
            const detContrato = document.getElementById('detContrato');
            if (detContrato) {
                detContrato.textContent = (selectedOption && selectedOption.value) ? selectedOption.text : '—';
            }
        });
}

function actualizarInfoContratoEdicion(select) {
    const infoDiv = document.getElementById('editContratoDetalleInfo');
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.value) {
        infoDiv.textContent = `📋 Detalle: ${selectedOption.text}`;
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
    }
}

// Toggle para edición inline
function toggleEdicionInline(editMode) {
    if (editMode) {
        document.getElementById('contenedorDetalles').style.display = 'none';
        document.getElementById('contenedorFormEditar').style.display = 'block';
        document.getElementById('btnHabilitarEditar').style.display = 'none';
    } else {
        document.getElementById('contenedorDetalles').style.display = 'block';
        document.getElementById('contenedorFormEditar').style.display = 'none';
        document.getElementById('btnHabilitarEditar').style.display = 'inline-flex';
    }
}

// Toggle para formulario inline de nueva gestión
function toggleNuevaGestionForm(show) {
    const f = document.getElementById('formNuevaGestionInline');
    if (show === undefined) {
        show = (f.style.display === 'none');
    }
    f.style.display = show ? 'block' : 'none';
    if (show) {
        document.getElementById('uniGestionTipoAccion').value = 'cambio_estado';
        actualizarEstadoGestionVisibilidad('cambio_estado');
        document.getElementById('uniGestionObservacion').value = '';
        document.getElementById('uniGestionRecordarDias').value = 0;
        document.getElementById('uniGestionFechaAlertaPreview').textContent = '';
    }
}

// Modal Único Unificado
function abrirModalUnico(id) {
    tareaIdActivo = id;
    
    // Abrir modal e ir a la primera pestaña
    document.getElementById('modalUnificado').classList.add('open');
    cambiarTab('detalles');
    toggleEdicionInline(false);
    toggleNuevaGestionForm(false);
    
    // Mostrar estado de carga
    document.getElementById('detClienteNombre').textContent = 'Cargando...';
    document.getElementById('detClienteCedula').textContent = '—';
    document.getElementById('detTipo').textContent = '—';
    document.getElementById('detCreada').textContent = '—';
    document.getElementById('detEncargado').textContent = '—';
    document.getElementById('detContrato').textContent = '—';
    document.getElementById('detEntidad').textContent = '—';
    document.getElementById('detTarea').textContent = '—';
    document.getElementById('detObservacionRow').style.display = 'none';
    
    document.getElementById('uniDocsLista').innerHTML = '<div style="text-align:center;padding:1rem;color:#94a3b8;font-size:.75rem;">⏳ Cargando documentos...</div>';
    document.getElementById('uniTablaGestionesBody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.75rem;">⏳ Cargando gestiones...</td></tr>';
    
    // Obtener datos vía AJAX
    fetch(`/admin/tareas/${id}/show`)
        .then(r => r.json())
        .then(data => {
            const t = data.tarea;
            const c = data.cliente;
            
            const clienteNombre = c ? nombreOracion(c.primer_nombre + ' ' + (c.segundo_nombre ?? '') + ' ' + c.primer_apellido + ' ' + (c.segundo_apellido ?? '')).replace(/\s+/g, ' ') : t.cedula;
            document.getElementById('modalUnificadoTitulo').innerHTML = `⚙️ Tarea: <span style="color: #fbbf24; font-weight:700;">${t.tipo}</span> <span style="font-size:0.75rem; opacity:0.85; margin-left:0.5rem; font-weight:normal;">(${clienteNombre})</span>`;
            
            // Llenar Ficha de Detalles (Solo Lectura)
            document.getElementById('detTipo').textContent = t.tipo;
            document.getElementById('detCreada').textContent = data.creada ? `Creada: ${data.creada}` : '—';
            document.getElementById('detEncargado').textContent = t.encargado ? t.encargado.nombre : '—';
            document.getElementById('detClienteNombre').textContent = clienteNombre;
            document.getElementById('detClienteCedula').textContent = `C.C. ${t.cedula}`;
            document.getElementById('detEntidad').textContent = t.entidad ? t.entidad : '—';
            document.getElementById('detTarea').textContent = t.tarea;
            
            if (t.observacion && t.observacion.trim()) {
                document.getElementById('detObservacion').textContent = t.observacion;
                document.getElementById('detObservacionRow').style.display = 'block';
            } else {
                document.getElementById('detObservacionRow').style.display = 'none';
            }
            
            // Enlace a la ficha del cliente
            const linkFicha = document.getElementById('linkFichaCliente');
            if (c && c.id) {
                linkFicha.href = `/admin/clientes/${c.id}/edit`;
                linkFicha.style.display = 'inline-flex';
            } else {
                linkFicha.style.display = 'none';
            }
            
            // Llenar Formulario de Edición
            document.getElementById('formEditar').action = `/admin/tareas/${t.id}`;
            document.getElementById('editTipo').value = t.tipo;
            document.getElementById('editEncargado').value = t.encargado_id;
            document.getElementById('editCedula').value = t.cedula;
            document.getElementById('editNombreClienteDisplay').value = clienteNombre;
            document.getElementById('editEntidad').value = t.entidad ?? '';
            document.getElementById('editTarea').value = t.tarea;
            document.getElementById('editObservacion').value = t.observacion ?? '';
            document.getElementById('editRazonSocial').value = t.razon_social_id ?? '';
            
            // Ocultar fecha, numero radicado y correo del form (se pasan en hidden vacios)
            document.getElementById('editFechaRadicado').value = '';
            document.getElementById('editNumeroRadicado').value = '';
            document.getElementById('editCorreo').value = '';
            
            cargarContratosEdicion(t.cedula, t.contrato_id);
            
            // Si la tarea está cerrada, deshabilitar inputs de edición y ocultar botones
            const esCerrada = (t.estado === 'cerrada');
            const inputs = document.getElementById('formEditar').querySelectorAll('input, select, textarea');
            inputs.forEach(el => el.disabled = esCerrada);
            
            if (esCerrada) {
                document.getElementById('msgEdicionCerrada').style.display = 'block';
                document.getElementById('btnHabilitarEditar').style.display = 'none';
                document.getElementById('btnNuevaGestionInline').style.display = 'none';
                document.getElementById('accionesDisponibles').style.display = 'none';
                document.getElementById('msgAccionesBloqueadas').style.display = 'block';
            } else {
                document.getElementById('msgEdicionCerrada').style.display = 'none';
                document.getElementById('btnHabilitarEditar').style.display = 'inline-flex';
                document.getElementById('btnNuevaGestionInline').style.display = 'inline-flex';
                document.getElementById('accionesDisponibles').style.display = 'block';
                document.getElementById('msgAccionesBloqueadas').style.display = 'none';
                
                // Configurar Valores de Acciones
                document.getElementById('uniTrasladoEncargado').value = t.encargado_id;
                document.getElementById('uniTrasladoObservacion').value = '';
                document.getElementById('uniCerrarObservacion').value = '';
                seleccionarResultadoCierre('positivo');
                
                mostrarSubAccion('traslado');
            }
            
            // Solicitud del portal y casilla «visible para la empresa»
            pintarSolicitudPortal(data.solicitud);
            document.querySelectorAll('.visible-empresa').forEach(el => {
                el.style.display = data.empresa_portal ? 'flex' : 'none';
                el.querySelector('.ve-nombre').textContent = data.empresa_portal?.nombre ?? 'la empresa';
            });
            document.getElementById('uniGestionVisible').checked = false;
            document.getElementById('uniCerrarVisible').checked = !!data.empresa_portal;

            // Llenar Documentos Adjuntos
            let docsHtml = '';
            (t.documentos ?? []).forEach(d => {
                docsHtml += `
                <div class="doc-item" style="padding: 0.45rem 0.6rem; margin-bottom:0.35rem; border-radius:8px; display:flex; align-items:center; gap:0.5rem; border: 1px solid #e2e8f0; background:#f8fafc;">
                    <div class="doc-icon" style="font-size:1.1rem; line-height:1;">📎</div>
                    <div class="doc-info" style="flex:1; min-width:0;">
                        <div class="doc-name" style="font-size:0.75rem; font-weight:600; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${esc(d.nombre)}">${esc(d.nombre)}</div>
                        <div class="doc-meta" style="font-size:0.62rem; color:#94a3b8;">Subido por ${esc(d.user?.nombre ?? '?')}</div>
                    </div>
                    <a href="/admin/tareas/documento/${d.id}" class="btn-download" style="padding:0.25rem 0.5rem; font-size:0.68rem; font-weight:700; border-radius:6px; background:rgba(37,99,235,0.1); color:#2563eb;" target="_blank">⬇ Descargar</a>
                </div>`;
            });
            if (!(t.documentos ?? []).length) {
                docsHtml = '<div style="font-size:.75rem;color:#94a3b8;padding:1.5rem;text-align:center;">Sin documentos adjuntos.</div>';
            }
            document.getElementById('uniDocsLista').innerHTML = docsHtml;
            
            // Llenar Tabla de Gestiones
            let gestionesHtml = '';
            const listGestiones = t.gestiones ?? [];
            document.getElementById('cantGestionesBadge').textContent = listGestiones.length;
            
            listGestiones.forEach(g => {
                const pastillasAccion = {
                    tramite_realizado: '<span class="badge-info" style="padding: 0.15rem 0.45rem; border-radius:6px; font-size:0.65rem; font-weight:700;">📋 Trámite</span>',
                    traslado: '<span class="badge-warn" style="padding: 0.15rem 0.45rem; border-radius:6px; font-size:0.65rem; font-weight:700; background:rgba(249,115,22,0.12); color:#ea580c; border:1px solid rgba(249,115,22,0.25);">🔀 Traslado</span>',
                    cambio_estado: '<span class="badge-ok" style="padding: 0.15rem 0.45rem; border-radius:6px; font-size:0.65rem; font-weight:700;">🔄 Estado</span>',
                    nota: '<span class="badge-info" style="padding: 0.15rem 0.45rem; border-radius:6px; font-size:0.65rem; font-weight:700; background:rgba(107,114,128,0.1); color:#4b5563; border:1px solid rgba(107,114,128,0.25);">📝 Observ.</span>'
                };
                const pastilla = pastillasAccion[g.tipo_accion] ?? '<span class="badge-info">📌 Gestión</span>';
                
                const fecha = new Date(g.created_at).toLocaleString('es-CO', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
                
                let trasladoDetalle = '';
                if (g.tipo_accion === 'traslado') {
                    trasladoDetalle = `<div style="font-size:0.68rem; margin-top:0.25rem; color:#ea580c; font-weight:600;">Asignación: ${g.encargado_anterior?.nombre ?? '?'} → ${g.encargado_nuevo?.nombre ?? '?'}</div>`;
                }
                
                let recordatorioHtml = '—';
                if (g.fecha_alerta) {
                    const fAlerta = new Date(g.fecha_alerta).toLocaleDateString('es-CO', {day:'2-digit',month:'short',year:'numeric'});
                    recordatorioHtml = `<div style="color:#d97706; font-weight:600; display:flex; align-items:center; gap:0.2rem;"><i class="fas fa-bell"></i> ${fAlerta}</div>`;
                }
                
                gestionesHtml += `
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.6rem 0.75rem; vertical-align:top;">${pastilla}</td>
                    <td style="padding:0.6rem 0.75rem; vertical-align:top; font-size:0.78rem; color:#334155; font-weight:500; word-break:break-word;">
                        ${esc(g.observacion)}
                        ${g.visible_empresa ? '<span class="ve-pill" title="La empresa la ve en su portal">🌐 Visible para la empresa</span>' : ''}
                        ${trasladoDetalle}
                    </td>
                    <td style="padding:0.6rem 0.75rem; vertical-align:top; color:#475569; font-size:0.7rem;">
                        <div style="font-weight:700; color:#1e293b;">${esc(g.user?.nombre ?? '?')}</div>
                        <div style="color:#94a3b8; font-size:0.65rem;">${fecha}</div>
                    </td>
                    <td style="padding:0.6rem 0.75rem; vertical-align:top; font-size:0.72rem;">${recordatorioHtml}</td>
                </tr>`;
            });
            
            if (!listGestiones.length) {
                gestionesHtml = '<tr><td colspan="4" style="text-align:center;padding:2rem;color:#94a3b8;font-size:.78rem;">No se han registrado gestiones en esta tarea.</td></tr>';
            }
            document.getElementById('uniTablaGestionesBody').innerHTML = gestionesHtml;
        });
}

function cambiarTab(tabName) {
    document.querySelectorAll('.modal-tab').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    if (tabName === 'detalles') {
        document.getElementById('tabBtnDetalles').classList.add('active');
        document.getElementById('tab-detalles').classList.add('active');
        // Mostrar botón Editar Datos si no se está editando y la tarea no está cerrada
        const esCerrada = document.getElementById('msgEdicionCerrada').style.display === 'block';
        const formVisible = document.getElementById('contenedorFormEditar').style.display === 'block';
        document.getElementById('btnHabilitarEditar').style.display = (esCerrada || formVisible) ? 'none' : 'inline-flex';
    } else if (tabName === 'acciones') {
        document.getElementById('tabBtnAcciones').classList.add('active');
        document.getElementById('tab-acciones').classList.add('active');
        // Ocultar botón Editar Datos en la pestaña de acciones
        document.getElementById('btnHabilitarEditar').style.display = 'none';
    }
}

function mostrarSubAccion(subName) {
    document.querySelectorAll('.btn-subaccion').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.subaccion-content').forEach(c => c.classList.remove('active'));
    
    if (subName === 'traslado') {
        document.getElementById('btnSubTraslado').classList.add('active');
        document.getElementById('sub-traslado').classList.add('active');
    } else if (subName === 'cerrar') {
        document.getElementById('btnSubCerrar').classList.add('active');
        document.getElementById('sub-cerrar').classList.add('active');
    } else if (subName === 'documentos') {
        document.getElementById('btnSubDocumentos').classList.add('active');
        document.getElementById('sub-documentos').classList.add('active');
    }
}

function actualizarEstadoGestionVisibilidad(val) {
    document.getElementById('uniRowNuevoEstado').style.display = (val === 'cambio_estado') ? '' : 'none';
}

function actualizarAlertaPreview(val) {
    const dias = parseInt(val) || 0;
    const prev = document.getElementById('uniGestionFechaAlertaPreview');
    if (dias > 0) {
        const fecha = new Date();
        fecha.setDate(fecha.getDate() + dias);
        prev.innerHTML = `<i class="fas fa-bell"></i> Recordatorio: ${fecha.toLocaleDateString('es-CO', {day:'2-digit',month:'short',year:'numeric'})}`;
    } else {
        prev.innerHTML = '';
    }
}

function enviarEditarUnificado() {
    const f = document.getElementById('formEditar');
    if (!f.reportValidity()) return;
    
    const fd = new FormData(f);
    const id = tareaIdActivo;
    
    fetch(`/admin/tareas/${id}`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            mostrarToast('✅ ' + d.message);
            // Volver a cargar para ver los cambios sin cerrar el modal de golpe
            abrirModalUnico(id);
            setTimeout(() => location.reload(), 1200);
        } else {
            mostrarToast('❌ ' + (d.message ?? 'Error al guardar cambios'));
        }
    })
    .catch(err => {
        console.error(err);
        mostrarToast('❌ Error de comunicación');
    });
}

function enviarGestionUnificada() {
    const obs = document.getElementById('uniGestionObservacion').value.trim();
    if (!obs) { mostrarToast('⚠️ Escriba la observación'); return; }
    
    const body = {
        tipo_accion: document.getElementById('uniGestionTipoAccion').value,
        observacion: obs,
        recordar_dias: document.getElementById('uniGestionRecordarDias').value,
        visible_empresa: document.getElementById('uniGestionVisible').checked ? 1 : 0,
        nuevo_estado: document.getElementById('uniGestionNuevoEstado')?.value,
        _token: '{{ csrf_token() }}'
    };
    
    fetch(`/admin/tareas/${tareaIdActivo}/gestion`, { 
        method: 'POST', 
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }, 
        body: JSON.stringify(body) 
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) { 
            mostrarToast('✅ ' + d.message); 
            toggleNuevaGestionForm(false);
            // Recargar datos del modal
            abrirModalUnico(tareaIdActivo);
            setTimeout(() => location.reload(), 1200); 
        } else {
            mostrarToast('❌ Error al guardar gestión');
        }
    })
    .catch(err => {
        console.error(err);
        mostrarToast('❌ Error de comunicación');
    });
}

function enviarTrasladoUnificado() {
    const obs = document.getElementById('uniTrasladoObservacion').value.trim();
    const enc = document.getElementById('uniTrasladoEncargado').value;
    if (!obs || !enc) { mostrarToast('⚠️ Complete todos los campos'); return; }
    
    fetch(`/admin/tareas/${tareaIdActivo}/trasladar`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ encargado_id: enc, observacion: obs })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) { 
            mostrarToast('✅ ' + d.message); 
            abrirModalUnico(tareaIdActivo);
            setTimeout(() => location.reload(), 1200); 
        } else {
            mostrarToast('❌ Error al trasladar');
        }
    })
    .catch(err => {
        console.error(err);
        mostrarToast('❌ Error de comunicación');
    });
}

function enviarCerrarUnificado() {
    const obs = document.getElementById('uniCerrarObservacion').value.trim();
    const resultado = document.querySelector('input[name="uniCerrarResultado"]:checked')?.value;
    if (!obs || !resultado) { mostrarToast('⚠️ Complete todos los campos'); return; }
    
    fetch(`/admin/tareas/${tareaIdActivo}/cerrar`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ resultado, observacion: obs, visible_empresa: document.getElementById('uniCerrarVisible').checked ? 1 : 0 })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) { 
            mostrarToast('🏁 ' + d.message); 
            abrirModalUnico(tareaIdActivo);
            setTimeout(() => location.reload(), 1200); 
        } else {
            mostrarToast('❌ Error al cerrar tarea');
        }
    })
    .catch(err => {
        console.error(err);
        mostrarToast('❌ Error de comunicación');
    });
}

function subirDocumentoUnificado(input) {
    if (!input.files.length || !tareaIdActivo) return;
    
    const nombre = prompt('Nombre del documento:', input.files[0].name.replace(/\.[^.]+$/, ''));
    if (!nombre) return;
    
    const fd = new FormData();
    fd.append('archivo', input.files[0]);
    fd.append('nombre', nombre);
    fd.append('_token', '{{ csrf_token() }}');
    
    document.getElementById('uniDocsLista').innerHTML = '<div style="text-align:center;padding:1rem;color:#94a3b8;font-size:.75rem;">⏳ Subiendo archivo...</div>';
    
    fetch(`/admin/tareas/${tareaIdActivo}/documento`, { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) { 
                mostrarToast('✅ ' + d.message); 
                abrirModalUnico(tareaIdActivo); 
            } else {
                mostrarToast('❌ Error al subir');
            }
        })
        .catch(err => {
            console.error(err);
            mostrarToast('❌ Error de red al subir archivo');
        });
}

function actualizarLimiteTipo(tipo) {
    // El backend calcula la fecha límite al crear
}

// ── Solicitudes del portal de empresas ─────────────────────────────────────
// Texto que llega del portal o de un usuario: nunca se pinta como HTML.
function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function postPortal(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify(body || {}),
    }).then(async r => {
        const d = await r.json().catch(() => ({}));
        if (!r.ok || d.ok === false) throw new Error(d.mensaje || d.message || 'No se pudo completar.');
        return d;
    });
}

function trasAccionPortal(d) {
    mostrarToast('✅ ' + (d.mensaje || 'Listo'));
    if (d.url) window.open(d.url, '_blank');
    abrirModalUnico(tareaIdActivo);
    setTimeout(() => location.reload(), 1500);
}

function pintarSolicitudPortal(sol) {
    const cont = document.getElementById('detSolicitud');
    if (!sol) { cont.style.display = 'none'; cont.innerHTML = ''; return; }

    const colorEstado = { pendiente: 'sp-warn', aprobada: 'sp-ok', rechazada: 'sp-err' }[sol.estado] || 'sp-warn';
    const datos = sol.datos.map(f => `<div class="sp-dato"><small>${esc(f.etiqueta)}</small><div>${esc(f.valor)}</div></div>`).join('');
    const archivos = sol.archivos.length
        ? sol.archivos.map(a => `<a class="sp-archivo" href="${a.url}" target="_blank" rel="noopener">📎 ${esc(a.nombre)}</a>`).join('')
        : '';

    let acciones = '';
    const a = sol.acciones;
    if (a) {
        if (sol.tipo === 'ingreso') {
            const ficha = a.ficha.existe
                ? `<a class="sp-btn sp-sec" href="${a.ficha.url}" target="_blank">👤 Abrir ficha existente</a>
                   ${a.ficha.otra_empresa ? `<div class="sp-alerta">⚠️ Esta persona ya tiene ficha y está ligada a otra empresa. Cámbiale la empresa en la ficha antes de crear el contrato, o no le saldrá a ${esc(sol.empresa)}.</div>` : ''}`
                : `<a class="sp-btn" id="spLinkFicha" href="${a.ficha.url}" target="_blank">👤 Crear ficha con estos datos</a>`;
            acciones = `
                <div class="sp-ruaf" id="spRuaf">⏳ Consultando el RUAF…</div>
                <div class="sp-pasos">
                    <div><span class="sp-num">1</span> ${ficha}</div>
                    <div><span class="sp-num">2</span> <a class="sp-btn" href="${a.contrato_url}" target="_blank">📄 Crear contrato</a>
                        <small>Al guardarlo, la solicitud queda aprobada.</small></div>
                </div>`;
            fetch(a.ruaf_url, { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(r => {
                const box = document.getElementById('spRuaf');
                if (!box) return;
                if (!r.ok) { box.textContent = '⚠️ ' + (r.mensaje || 'El RUAF no respondió.'); return; }
                if (!r.encontrado) { box.textContent = 'ℹ️ No aparece en el RUAF: no tiene afiliación vigente. EPS y fondo se escogen en la ficha.'; return; }
                box.innerHTML = `🩺 RUAF · EPS: <b>${esc(r.eps_nombre || '—')}</b> · Pensión: <b>${esc(r.pension_nombre || '—')}</b>${r.estado ? ' · ' + esc(r.estado) : ''}`;
                const link = document.getElementById('spLinkFicha');
                if (link) {
                    const u = new URL(link.href);
                    if (r.eps_id) u.searchParams.set('eps_id', r.eps_id);
                    if (r.pension_id) u.searchParams.set('pension_id', r.pension_id);
                    link.href = u.toString();
                }
            }).catch(() => {
                const box = document.getElementById('spRuaf');
                if (box) box.textContent = '⚠️ No se pudo consultar el RUAF.';
            });
        }
        if (sol.tipo === 'retiro') {
            acciones = `
                <div class="sp-fila">
                    <label>Fecha de retiro <input type="date" id="spFechaRetiro" value="${esc(a.fecha_retiro || '')}"></label>
                    <label class="sp-check"><input type="checkbox" id="spCobrarAdmon" checked> Cobrar administración del mes</label>
                    <button type="button" class="sp-btn" id="spBtnRetiro">✅ Registrar retiro</button>
                    ${a.contrato_url ? `<a class="sp-btn sp-sec" href="${a.contrato_url}" target="_blank">📄 Ver contrato</a>` : ''}
                </div>
                <small class="sp-nota">Queda como retiro pendiente del contrato y sale en la facturación de la empresa de ese mes.</small>`;
        }
        if (sol.tipo === 'incapacidad') {
            const esLaboral = sol.datos.some(f => f.etiqueta === 'Tipo' && /laboral/i.test(f.valor));
            acciones = `
                <div class="sp-fila">
                    <label>Se cobra a
                        <select id="spTipoEntidad">
                            <option value="eps" ${esLaboral ? '' : 'selected'}>EPS</option>
                            <option value="arl" ${esLaboral ? 'selected' : ''}>ARL</option>
                        </select>
                    </label>
                    <button type="button" class="sp-btn" id="spBtnIncap">🏥 Registrar incapacidad</button>
                </div>
                <small class="sp-nota">Se crea en Incapacidades con los documentos que subió la empresa. Después la completas allá (radicado, diagnóstico…).</small>`;
        }
        acciones += `
            <details class="sp-rechazo">
                <summary>✋ No se puede tramitar</summary>
                <textarea id="spMotivo" placeholder="Explícale a la empresa por qué (lo va a leer)"></textarea>
                <button type="button" class="sp-btn sp-rojo" id="spBtnRechazar">Rechazar solicitud</button>
            </details>`;
    }

    cont.innerHTML = `
        <div class="sp-cab">
            <div><b>🌐 ${esc(sol.tipo_label)}</b> <span class="sp-sub">· ${esc(sol.empresa)} · ${esc(sol.recibida)}</span></div>
            <span class="sp-estado ${colorEstado}">${esc(sol.estado_label)}</span>
        </div>
        <div class="sp-datos">${datos}</div>
        ${archivos ? `<div class="sp-archivos">${archivos}</div>` : ''}
        ${acciones ? `<div class="sp-acciones">${acciones}</div>` : ''}`;
    cont.style.display = 'block';

    const bind = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
    bind('spBtnRetiro', (e) => {
        e.target.disabled = true;
        postPortal(a.retiro_url, {
            fecha_retiro: document.getElementById('spFechaRetiro').value,
            cobrar_admon: document.getElementById('spCobrarAdmon').checked ? 1 : 0,
        }).then(trasAccionPortal).catch(err => { mostrarToast('❌ ' + err.message); e.target.disabled = false; });
    });
    bind('spBtnIncap', (e) => {
        e.target.disabled = true;
        postPortal(a.incapacidad_url, { tipo_entidad: document.getElementById('spTipoEntidad').value })
            .then(trasAccionPortal).catch(err => { mostrarToast('❌ ' + err.message); e.target.disabled = false; });
    });
    bind('spBtnRechazar', (e) => {
        const motivo = document.getElementById('spMotivo').value.trim();
        if (!motivo) { mostrarToast('⚠️ Escribe el motivo'); return; }
        e.target.disabled = true;
        postPortal(a.rechazar_url, { motivo }).then(trasAccionPortal)
            .catch(err => { mostrarToast('❌ ' + err.message); e.target.disabled = false; });
    });
}

// Enlace directo a una tarea (?tarea=ID): lo usa el aviso de solicitudes nuevas.
document.addEventListener('DOMContentLoaded', () => {
    const id = new URLSearchParams(location.search).get('tarea');
    if (id && /^\d+$/.test(id)) abrirModalUnico(parseInt(id, 10));
});

function mostrarToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

// Cerrar modal al hacer clic fuera
document.querySelectorAll('.modal-overlay').forEach(ol => {
    ol.addEventListener('click', e => { if (e.target === ol) ol.classList.remove('open'); });
});
</script>
@endpush
