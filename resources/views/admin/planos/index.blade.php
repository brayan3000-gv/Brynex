@extends('layouts.app')

@section('titulo', 'Planos SS')
@section('modulo', 'Pago Planillas Seguridad Social')

@push('styles')
<style>
/* ── Variables ─────────────────────────────────────────────────────── */
:root {
    --azul-oscuro:#0a1628; --azul-medio:#0d2550; --azul-vivo:#1e40af;
    --acento:#3b82f6; --verde:#10b981; --rojo:#ef4444; --amarillo:#f59e0b;
}

/* ── Cabecera del módulo ─────────────────────────────────────────────── */
.modulo-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:1rem; flex-wrap:wrap; gap:.5rem;
}
.modulo-titulo {
    font-size:1.15rem; font-weight:700; color:var(--azul-oscuro);
    display:flex; align-items:center; gap:.5rem;
}
.badge-plano {
    background:var(--amarillo); color:#fff; font-size:.72rem;
    font-weight:700; padding:.15rem .55rem; border-radius:999px;
    display:inline-flex; align-items:center; gap:.3rem;
}

/* ── Panel de filtros ─────────────────────────────────────────────────── */
.filtros-panel {
    background:#fff; border-radius:12px;
    border:1px solid #e2e8f0; padding:.5rem .85rem;
    margin-bottom:.75rem;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
}
.filtros-grid {
    display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;
}
/* Grupo inline: label pegado al control */
.filtro-inline {
    display:flex; align-items:center; gap:.3rem;
}
.filtro-inline .fi-label {
    font-size:.68rem; font-weight:700; color:#64748b;
    text-transform:uppercase; letter-spacing:.04em;
    white-space:nowrap;
}
.filtro-inline select,
.filtro-inline .multiselect-trigger {
    border:1px solid #cbd5e1; border-radius:7px;
    padding:.28rem .5rem; font-size:.8rem;
    background:#f8fafc; color:#1e293b;
    outline:none; transition:border .15s;
    text-align:center;
}
.filtro-inline select:focus { border-color:var(--acento); background:#fff; }
/* Separador vertical */
.filtro-sep { width:1px; height:24px; background:#e2e8f0; margin:0 .1rem; flex-shrink:0; }
/* Mantener compatibilidad con .filtro-grupo para el resto de la app */
.filtro-grupo { display:flex; flex-direction:column; gap:.25rem; }
.filtro-grupo label {
    font-size:.7rem; font-weight:600; color:#64748b;
    text-transform:uppercase; letter-spacing:.04em;
}
.filtro-grupo select,
.filtro-grupo input[type=number],
.filtro-grupo input[type=text] {
    border:1px solid #cbd5e1; border-radius:7px;
    padding:.32rem .6rem; font-size:.82rem;
    background:#f8fafc; color:#1e293b;
    outline:none; transition:border .15s;
    min-width:80px;
}
.filtro-grupo select:focus,
.filtro-grupo input:focus { border-color:var(--acento); background:#fff; }

/* N_plano con botón + */
.nplano-wrap {
    display:flex; align-items:center; gap:.3rem;
}
.nplano-wrap input { width:60px; text-align:center; }
.btn-plus {
    width:26px; height:26px; border-radius:6px;
    background:var(--acento); color:#fff; border:none;
    cursor:pointer; font-size:1rem; display:flex;
    align-items:center; justify-content:center;
    transition:background .15s;
}
.btn-plus:hover { background:var(--azul-vivo); }

/* Multiselect tipos_modalidad */
.multiselect-wrap { position:relative; }
.multiselect-trigger {
    border:1px solid #cbd5e1; border-radius:7px;
    padding:.32rem .6rem; font-size:.82rem;
    background:#f8fafc; color:#1e293b;
    cursor:pointer; min-width:140px;
    display:flex; align-items:center; justify-content:space-between;
    gap:.4rem; white-space:nowrap;
    user-select:none;
}
.multiselect-trigger:hover { border-color:var(--acento); }
.multiselect-dropdown {
    position:absolute; top:calc(100% + 4px); left:0; z-index:200;
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    box-shadow:0 8px 24px rgba(0,0,0,.12);
    padding:.4rem; min-width:220px;
    display:none; max-height:240px; overflow-y:auto;
}
.multiselect-wrap.open .multiselect-dropdown { display:block; }
.ms-item {
    display:flex; align-items:center; gap:.5rem;
    padding:.3rem .5rem; border-radius:6px;
    cursor:pointer; font-size:.8rem; color:#334155;
}
.ms-item:hover { background:#f1f5f9; }
.ms-item input[type=checkbox] { accent-color:var(--acento); }
.ms-select-all {
    font-size:.7rem; font-weight:600; color:var(--acento);
    cursor:pointer; padding:.2rem .5rem;
    border-bottom:1px solid #f1f5f9; margin-bottom:.2rem;
    display:block;
}

/* ── Botones de acción ───────────────────────────────────────────────── */
.btn-accion {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.38rem .85rem; border-radius:8px; font-size:.8rem;
    font-weight:600; cursor:pointer; border:none;
    text-decoration:none; transition:all .15s;
}
.btn-descargar {
    background:linear-gradient(135deg,#0d9488,#0f766e);
    color:#fff;
    box-shadow:0 2px 6px rgba(13,148,136,.3);
}
.btn-descargar:hover { background:linear-gradient(135deg,#0f766e,#134e4a); }
.btn-asopagos {
    background:linear-gradient(135deg,#7c3aed,#5b21b6);
    color:#fff; padding:.32rem .75rem; font-size:.78rem;
    border-radius:8px; border:none; cursor:pointer;
    font-weight:700; display:flex; align-items:center; gap:.35rem;
    transition:all .2s; white-space:nowrap;
}
.btn-asopagos:hover { background:linear-gradient(135deg,#6d28d9,#4c1d95); }
.btn-pagar {
    background:linear-gradient(135deg,#1d4ed8,#1e40af);
    color:#fff;
    box-shadow:0 2px 6px rgba(29,78,216,.3);
}
.btn-pagar:hover { background:linear-gradient(135deg,#1e40af,#1e3a8a); }
.btn-consultar {
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 2px 6px rgba(124,58,237,.3);
}
.btn-consultar:hover { background:linear-gradient(135deg,#6d28d9,#5b21b6); }
.btn-cancelar {
    background:#f1f5f9; color:#64748b;
    border:1px solid #e2e8f0;
}
.btn-cancelar:hover { background:#e2e8f0; }

/* ── Tabla ───────────────────────────────────────────────────────────── */
.tabla-wrap {
    background:#fff; border-radius:12px;
    border:1px solid #e2e8f0;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    overflow:auto;
    max-height: calc(100vh - 240px); /* Altura optimizada para extenderse hasta el tope de la pantalla */
}
.tabla-planos {
    width:100%; border-collapse:separate; border-spacing:0; font-size:.76rem;
}
.tabla-planos thead tr {
    background:linear-gradient(135deg,var(--azul-oscuro),var(--azul-medio));
    color:#e2e8f0;
}
.tabla-planos thead th {
    padding:.5rem .45rem; text-align:left;
    font-weight:600; font-size:.68rem;
    text-transform:uppercase; letter-spacing:.04em;
    white-space:nowrap; border-right:1px solid rgba(255,255,255,.07);
    position: sticky !important;
    top: 0;
    z-index: 10;
    background: linear-gradient(135deg,var(--azul-oscuro),var(--azul-medio)) !important;
}
.tabla-planos tbody tr { border-bottom:1px solid #f1f5f9; }
/* Sin pagar: hover gris. Pagado: verde claro siempre, verde más fuerte en hover.
   El :hover de ya-pago va después y también con !important, si no lo pisa el gris. */
.tabla-planos tbody tr:hover td { background:#e2e8f0 !important; }
.tabla-planos tbody tr.ya-pago td { background:#dcfce7 !important; }
.tabla-planos tbody tr.ya-pago:hover td { background:#86efac !important; }
.tabla-planos tbody td {
    padding:.4rem .45rem; color:#334155;
    white-space:nowrap; overflow:hidden;
    max-width:140px; text-overflow:ellipsis;
    border-bottom:1px solid #f1f5f9; /* Línea divisoria en celdas para border-collapse:separate */
}
.tabla-planos tbody td.td-nombre { max-width:130px; }
/* Fechas ingreso/retiro unificadas: verde arriba (ingreso), rojo abajo (retiro) */
.tabla-planos tbody td.td-fechas { max-width:none; padding-top:.3rem; padding-bottom:.3rem; text-align:center; }
.tabla-planos tbody td.td-fechas span {
    display:block; line-height:1.15; font-size:.72rem; font-weight:600; letter-spacing:.01em;
}
.tabla-planos tbody td.td-fechas .fec-ing { color:#15803d; }
.tabla-planos tbody td.td-fechas .fec-ret { color:#dc2626; }
.tabla-planos tbody td.td-fechas b { font-weight:800; opacity:.75; margin-right:.1rem; }
.tabla-planos tbody td.td-empresa { max-width:90px; }
.tabla-planos tbody td.td-envio { max-width:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* Pago (independientes): PSE y Pagar siempre en la misma línea, sin recortarse */
.tabla-planos tbody td.td-pago { max-width:none; overflow:visible; white-space:nowrap; }
/* Operador del contratista */
.tabla-planos tbody td.td-operador { font-size:.72rem; color:#374151; white-space:nowrap; max-width:none; }
.op-chip {
    display:inline-flex; align-items:center; gap:.2rem;
    background:#f0f9ff; color:#0284c7; border:1px solid #bae6fd;
    border-radius:6px; padding:.1rem .4rem; font-size:.67rem; font-weight:600;
}
.sel-operador {
    max-width:110px; padding:.12rem .2rem; border-radius:6px;
    border:1px dashed #94a3b8; background:#f8fafc; color:#475569;
    font-size:.66rem; font-weight:600; cursor:pointer;
}
.sel-operador:hover:not(:disabled) { border-color:#0284c7; color:#0284c7; background:#f0f9ff; }
.sel-operador:disabled { opacity:.6; cursor:wait; }
.tabla-planos tfoot tr {
    background:linear-gradient(135deg,#0a1628,#0d2550);
    color:#e2e8f0;
}
.tabla-planos tfoot td {
    padding:.5rem .45rem; font-weight:700;
    font-size:.78rem; white-space:nowrap;
    position: sticky;
    bottom: 0;
    z-index: 10;
    background: linear-gradient(135deg,#0a1628,#0d2550) !important;
    box-shadow: 0 -2px 5px rgba(0,0,0,0.15);
}
.chip-tipo {
    display:inline-block; padding:.1rem .4rem;
    border-radius:4px; font-size:.65rem; font-weight:700;
    background:#e0f2fe; color:#0369a1;
}
.chip-tipo.e   { background:#dcfce7; color:#15803d; }
.chip-tipo.i   { background:#fef9c3; color:#a16207; }
.chip-tipo.k   { background:#f3e8ff; color:#7e22ce; }
.chip-tipo.tp  { background:#ffe4e6; color:#be123c; }
/* Cotiza el mes en curso: en una misma planilla conviven los dos períodos y sin
   esta marca no hay forma de saber por qué esta fila cubre un mes distinto. */
.chip-mesact {
    display:inline-block; margin-left:.2rem; padding:.1rem .3rem;
    border-radius:4px; font-size:.58rem; font-weight:800; letter-spacing:.02em;
    background:#dbeafe; color:#1d4ed8; cursor:help;
}

/* ── Pie: resumen ────────────────────────────────────────────────────── */
.resumen-pie {
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
    padding:.6rem 1rem; margin-top:.75rem;
    display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;
    font-size:.82rem;
}
.resumen-item { display:flex; flex-direction:column; }
.resumen-item .ri-label {
    font-size:.65rem; font-weight:600; color:#94a3b8;
    text-transform:uppercase; letter-spacing:.04em;
}
.resumen-item .ri-valor {
    font-size:.9rem; font-weight:700; color:var(--azul-oscuro);
}

/* ── Mora ──────────────────────────────────────────────────────────── */
.mora-bloque {
    display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
    background:linear-gradient(135deg,#fff7ed,#fef3c7);
    border:1px solid #fde68a; border-radius:10px;
    padding:.55rem 1rem; margin-top:.6rem;
    font-size:.8rem; animation:moraPulse 2.5s ease-in-out infinite;
}
@keyframes moraPulse {
    0%,100% { box-shadow:0 0 0 0 rgba(245,158,11,.15); }
    50%      { box-shadow:0 0 0 6px rgba(245,158,11,.0); }
}
.mora-item { display:flex; flex-direction:column; }
.mora-item .ml { font-size:.62rem; font-weight:700; color:#92400e;
    text-transform:uppercase; letter-spacing:.05em; }
.mora-item .mv { font-size:.9rem; font-weight:800; }
.mora-item .mv.rojo  { color:#dc2626; }
.mora-item .mv.azul  { color:var(--azul-vivo); }
.mora-item .mv.verde { color:#059669; }
.mora-sep { width:1px; height:32px; background:#fde68a; flex-shrink:0; }
.mora-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    background:#fef3c7; border:1px solid #fde68a;
    border-radius:20px; padding:.2rem .7rem;
    font-size:.72rem; font-weight:700; color:#92400e;
}
.mora-info {
    font-size:.68rem; color:#b45309; line-height:1.4;
    border-left:3px solid #fcd34d; padding-left:.5rem;
}

/* ── Modales ─────────────────────────────────────────────────────────── */
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(10,22,40,.6); z-index:1000;
    align-items:center; justify-content:center;
    backdrop-filter:blur(3px);
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff; border-radius:16px; width:100%;
    box-shadow:0 20px 60px rgba(0,0,0,.3);
    animation:modalIn .2s ease;
    overflow-y:auto;
}
.modal-box.md { max-width:480px; max-height:90vh; }
.modal-box.lg { max-width:640px; max-height:90vh; }
@keyframes modalIn {
    from { opacity:0; transform:translateY(-16px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.modal-head {
    padding:1rem 1.25rem .75rem;
    border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between;
}
.modal-head h3 { font-size:.95rem; font-weight:700; color:var(--azul-oscuro); }
.modal-close {
    background:none; border:none; cursor:pointer;
    font-size:1.2rem; color:#94a3b8;
    transition:color .15s;
}
.modal-close:hover { color:var(--rojo); }
.modal-body { padding:1.1rem 1.25rem; }
.modal-foot {
    padding:.75rem 1.25rem 1rem;
    border-top:1px solid #f1f5f9;
    display:flex; gap:.5rem; justify-content:flex-end; flex-wrap:wrap;
}

/* Campos del modal */
.form-row { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:.75rem; }
.form-grupo { display:flex; flex-direction:column; gap:.25rem; flex:1; min-width:120px; }
.form-grupo label {
    font-size:.7rem; font-weight:600; color:#64748b;
    text-transform:uppercase; letter-spacing:.04em;
}
.form-grupo input,
.form-grupo select,
.form-grupo textarea {
    border:1px solid #cbd5e1; border-radius:8px;
    padding:.4rem .65rem; font-size:.83rem;
    color:#1e293b; outline:none;
    transition:border .15s; background:#f8fafc;
}
.form-grupo input:focus,
.form-grupo select:focus,
.form-grupo textarea:focus {
    border-color:var(--acento); background:#fff;
}
.form-grupo textarea { resize:vertical; min-height:70px; }

.aviso-modal {
    background:#fef3c7; border:1px solid #fde68a;
    border-radius:8px; padding:.65rem .9rem;
    font-size:.78rem; color:#92400e; margin-bottom:.9rem;
    line-height:1.5;
}
.aviso-modal strong { display:block; margin-bottom:.2rem; }

.info-chip {
    background:#eff6ff; border:1px solid #bfdbfe;
    border-radius:6px; padding:.3rem .65rem;
    font-size:.75rem; color:#1d4ed8; font-weight:600;
    display:inline-block; margin-bottom:.75rem;
}

/* Toast */
.toast {
    position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999;
    background:#0f172a; color:#e2e8f0; border-radius:10px;
    padding:.65rem 1.1rem; font-size:.82rem; font-weight:500;
    box-shadow:0 8px 24px rgba(0,0,0,.3);
    transform:translateY(60px); opacity:0;
    transition:all .3s ease;
    max-width:340px;
}
.toast.show { transform:none; opacity:1; }
.toast.success { border-left:4px solid var(--verde); }
.toast.error   { border-left:4px solid var(--rojo); }

/* ── Vacío ───────────────────────────────────────────────────────────── */
.empty-state {
    text-align:center; padding:3rem 1rem;
    color:#94a3b8;
}
.empty-state .es-icon { font-size:2.5rem; margin-bottom:.5rem; }
.empty-state p { font-size:.85rem; }

/* ── Chip con el número de planilla ya confirmada (clic = copiar) ─── */
/* Fondo blanco: la fila pagada ya es verde, el chip tiene que despegarse de ella */
.chip-planilla {
    display:inline-flex; align-items:center; gap:.25rem;
    background:#fff; color:#15803d; border:1px solid #4ade80;
    border-radius:20px; padding:.15rem .5rem;
    font-size:.66rem; font-weight:700; font-family:ui-monospace,monospace;
    white-space:nowrap; cursor:pointer; transition:background .15s;
}
.chip-planilla:hover { background:#f0fdf4; }
/* Lo que dijo el operador del número confirmado (planillas_verificacion_operador) */
.chip-planilla.no-cruza    { color:#b91c1c; border-color:#f87171; background:#fef2f2; }
.chip-planilla.sin-acceso  { color:#92400e; border-color:#fbbf24; background:#fffbeb; }
.chip-planilla.verificando { color:#475569; border-color:#cbd5e1; }
.acc-planilla-num {
    font-family:monospace; font-size:1.05rem; font-weight:800; color:#0f172a;
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:9px;
    padding:.5rem .8rem; text-align:center; letter-spacing:.02em; word-break:break-all;
}
.acc-planilla-btn {
    display:flex; align-items:center; justify-content:center; gap:.4rem;
    width:100%; padding:.55rem .9rem; border-radius:9px; border:1px solid #e2e8f0;
    background:#fff; color:#334155; font-size:.82rem; font-weight:700; cursor:pointer;
    transition:background .12s, border-color .12s;
}
.acc-planilla-btn:hover { background:#f8fafc; border-color:#cbd5e1; }
.acc-planilla-btn.editar { border-color:#bfdbfe; background:#eff6ff; color:#1d4ed8; }
.acc-planilla-btn.editar:hover { background:#dbeafe; }

/* ── Custom RS Dropdown ────────────────────────────────────────────── */
.rs-wrap { position:relative; }
.rs-btn {
    display:flex; align-items:center; gap:.4rem;
    min-width:280px; padding:.28rem .6rem;
    border:1px solid #cbd5e1; border-radius:7px;
    background:#f8fafc; color:#1e293b;
    font-size:.8rem; cursor:pointer; text-align:left;
    white-space:nowrap; overflow:hidden; transition:border .15s;
}
.rs-btn:hover { border-color:var(--acento); }
.rs-btn-txt { flex:1; overflow:hidden; text-overflow:ellipsis; }
.rs-btn-arr { font-size:.6rem; color:#94a3b8; flex-shrink:0; transition:transform .2s; }
.rs-wrap.open .rs-btn-arr { transform:rotate(180deg); }
.rs-panel {
    position:absolute; top:calc(100% + 5px); left:0; z-index:400;
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    box-shadow:0 10px 30px rgba(0,0,0,.15);
    width:440px; max-height:380px;
    flex-direction:column; display:none;
}
.rs-wrap.open .rs-panel { display:flex; }
.rs-search-box { padding:.4rem .5rem; border-bottom:1px solid #f1f5f9; flex-shrink:0; }
.rs-search-box input {
    width:100%; padding:.3rem .65rem; border:1px solid #e2e8f0;
    border-radius:6px; font-size:.8rem; outline:none; background:#f8fafc;
    transition:border .15s;
}
.rs-search-box input:focus { border-color:var(--acento); background:#fff; }
.rs-list { overflow-y:auto; flex:1; padding:.15rem 0; }
.rs-glabel {
    padding:.3rem .85rem .15rem; font-size:.62rem; font-weight:700;
    color:#94a3b8; text-transform:uppercase; letter-spacing:.06em;
    background:#fff; position:sticky; top:0;
}
.rs-row {
    display:grid;
    grid-template-columns:18px 1fr 38px 58px;
    align-items:center; gap:0 6px;
    padding:.3rem .85rem; cursor:pointer;
    font-size:.79rem; color:#334155; transition:background .1s;
}
.rs-row:hover { background:#f1f5f9; }
.rs-row.sel { background:#eff6ff; }
.rs-row .ri { font-size:.75rem; text-align:center; }
.rs-row .ri.g { color:#16a34a; }
.rs-row .ri.s { color:#94a3b8; }
.rs-row .rn { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:500; }
.rs-row.sel .rn { color:#1d4ed8; font-weight:700; }
.rs-row .rp { text-align:right; font-size:.71rem; color:#64748b; font-weight:600; white-space:nowrap; }
.rs-row.sel .rp { color:#1d4ed8; }
.rs-row .rc { text-align:right; font-size:.68rem; color:#16a34a; white-space:nowrap; }
.rs-row.sel .rc { color:#1d4ed8; }

/* Nuevos botones de descarga en modal */
.btn-descarga-principal {
    width: 100%;
    justify-content: center;
    padding: .75rem 1rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: .92rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    transition: all .2s ease-in-out;
}
.btn-descarga-principal:hover:not(:disabled) {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
}
.btn-descarga-principal:active:not(:disabled) {
    transform: translateY(0);
}
.btn-descarga-principal:disabled {
    background: #cbd5e1;
    color: #94a3b8;
    box-shadow: none;
    cursor: not-allowed;
    filter: grayscale(.8);
    opacity: .6;
}

.contenedor-descargas-secundarias {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: .75rem;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: .5rem;
}

.btn-descarga-secundario {
    width: 100%;
    justify-content: center;
    padding: .5rem .75rem;
    background: #fff;
    color: #334155;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    cursor: pointer;
    font-size: .8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all .15s ease-in-out;
}
.btn-descarga-secundario:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
    transform: translateY(-0.5px);
}
.btn-descarga-secundario:active:not(:disabled) {
    transform: translateY(0);
}
.btn-descarga-secundario:disabled {
    background: #cbd5e1;
    color: #94a3b8;
    border-color: #cbd5e1;
    box-shadow: none;
    cursor: not-allowed;
    filter: grayscale(.8);
    opacity: .6;
}
/* El último botón ocupa las dos columnas solo si queda impar; con los cuatro
   formatos de Excel la grilla es 2x2 y no debe estirarse ninguno. */
.btn-descarga-secundario:last-child:nth-child(odd) {
    grid-column: span 2;
}

/* Descargas manuales colapsables (colapsadas cuando hay liquidación en línea) */
#detalle-descargas > summary {
    list-style:none; cursor:pointer; display:flex; align-items:center; gap:.4rem;
    font-size:.78rem; font-weight:700; color:#475569;
    padding:.55rem .7rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
    user-select:none;
}
#detalle-descargas > summary::-webkit-details-marker { display:none; }
#detalle-descargas > summary::after {
    content:'▾'; margin-left:auto; color:#94a3b8; transition:transform .15s;
}
#detalle-descargas[open] > summary::after { transform:rotate(180deg); }
#detalle-descargas > summary:hover { background:#f1f5f9; }

/* Icono "Liquidar en línea" por independiente (junto a Pagar) */
.btn-pse-icon {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:30px; height:22px; padding:0 .3rem; border-radius:6px;
    background:linear-gradient(135deg,#1d4ed8,#1e3a8a);
    color:#fff; font-size:.56rem; font-weight:800; letter-spacing:.01em;
    border:none; cursor:pointer; box-shadow:0 1px 3px rgba(29,78,216,.35);
    transition:transform .12s, opacity .12s; flex-shrink:0;
}
.btn-pse-icon:hover:not(:disabled) { transform:scale(1.06); }
.btn-pse-icon:disabled {
    background:#e2e8f0; color:#94a3b8; cursor:not-allowed;
    box-shadow:none; filter:none;
}
.btn-pse-icon.cargando { opacity:.6; cursor:wait; }
.chip-liquidado-api {
    display:inline-flex; align-items:center; gap:.25rem;
    background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
    border-radius:20px; padding:.1rem .5rem; font-size:.64rem; font-weight:700;
    font-family:monospace; white-space:nowrap; flex-shrink:0;
}
.chip-liquidado-api a { color:#1d4ed8; text-decoration:underline; font-family:sans-serif; margin-left:.15rem; }

/* ── Ordenamiento de Tabla ───────────────────────────────────────────── */
.tabla-planos thead th.sortable {
    cursor: pointer;
    user-select: none;
    padding-right: 1.25rem !important;
}
.tabla-planos thead th.sortable:hover {
    background: rgba(255, 255, 255, 0.15) !important;
}
.tabla-planos thead th.sortable::after {
    content: ' ↕';
    font-size: 0.65rem;
    opacity: 0.4;
    position: absolute;
    right: 0.35rem;
    top: 50%;
    transform: translateY(-50%);
}
.tabla-planos thead th.sortable.asc::after {
    content: ' ▲';
    opacity: 0.9;
    color: #10b981;
}
.tabla-planos thead th.sortable.desc::after {
    content: ' ▼';
    opacity: 0.9;
    color: #10b981;
}
</style>
@endpush

@section('contenido')
<div class="modulo-header">
    <div class="modulo-titulo">
        📄 Planos de Seguridad Social – Pago al Operador
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
        {{-- Filtros de Año y Mes arriba al lado del Resumen --}}
        <div class="filtro-inline" style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:.2rem .45rem;display:flex;align-items:center;gap:.3rem;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <span style="font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em">Año</span>
            <select name="anio" form="frm-filtros" onchange="autoSubmit()" style="border:none;background:transparent;font-size:.78rem;font-weight:700;color:#1e293b;outline:none;cursor:pointer;padding:0 .1rem">
                @for($y = now()->year; $y >= now()->year - 3; $y--)
                <option value="{{ $y }}" {{ $anio == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>

        <div class="filtro-inline" style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:.2rem .45rem;display:flex;align-items:center;gap:.3rem;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <span style="font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em">Mes</span>
            <select name="mes" form="frm-filtros" onchange="autoSubmit()" style="border:none;background:transparent;font-size:.78rem;font-weight:700;color:#1e293b;outline:none;cursor:pointer;padding:0 .1rem">
                @php
                $meses = ['','Ene','Feb','Mar','Abr','May','Jun',
                          'Jul','Ago','Sep','Oct','Nov','Dic'];
                @endphp
                @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $mes == $m ? 'selected' : '' }}>{{ $meses[$m] }}</option>
                @endfor
            </select>
        </div>

        {{-- Opción de Selección Masiva (al lado derecho de mes) --}}
        <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .8rem;
                      border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;
                      color:#475569;font-size:.78rem;font-weight:700;cursor:pointer;
                      transition:all .15s;user-select:none"
               onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
            <input type="checkbox" id="chk-seleccion-masiva" onchange="toggleSeleccionMasiva(this.checked)" style="width:.9rem;height:.9rem;cursor:pointer">
            📦 Selección Masiva
        </label>

        @if($rsSeleccionada)
        <span class="badge-plano">
            N_PLANO actual:
            <span id="badge-nplano-val">{{ $nPlanoActual }}</span>
        </span>
        @endif
        <button type="button" id="btn-resumen-planos"
            onclick="abrirResumenPlanos()"
            style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .8rem;
                   border-radius:8px;border:1px solid #bfdbfe;background:#eff6ff;
                   color:#1d4ed8;font-size:.78rem;font-weight:700;cursor:pointer;
                   transition:all .15s"
            onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
            📊 Ver Resumen
        </button>
    </div>
</div>

{{-- ── Panel de filtros (toolbar compacto) ───────────── --}}
<div class="filtros-panel">
    <form method="GET" action="{{ route('admin.planos.index') }}" id="frm-filtros">
        <div class="filtros-grid" style="display:flex;align-items:center;gap:.5rem">

            {{-- Razon Social — Custom Dropdown --}}
            <div class="filtro-inline">
                <span class="fi-label">RS</span>
                <input type="hidden" name="razon_social_id" id="sel-rs-val" value="{{ $razonSocialId ?? '' }}">
                <div class="rs-wrap" id="rs-wrap">
                    <button type="button" class="rs-btn" onclick="toggleRs()">
                        <span class="rs-btn-txt" id="rs-btn-txt">{{ $rsSeleccionada ? mb_strtoupper($rsSeleccionada->razon_social) : '— Todas —' }}</span>
                        <span class="rs-btn-arr">▼</span>
                    </button>
                    <div class="rs-panel">
                        <div class="rs-search-box">
                            <input type="text" id="rs-search" placeholder="🔍 Buscar..." oninput="filtrarRs(this.value)" autocomplete="off">
                        </div>
                        <div class="rs-list" id="rs-list">
                            <div class="rs-row {{ !$razonSocialId ? 'sel':'' }}" data-lbl="" onclick="selRs('','','— Todas —')">
                                <span class="ri s">—</span>
                                <span class="rn" style="color:#64748b">— Todas —</span>
                                <span class="rp"></span><span class="rc"></span>
                            </div>
                            @php
                                // "Con planos" = RS con al menos 1 plano pendiente en el periodo
                                $rsConPlanos = $razonesSociales->filter(fn($r) => isset($cantPorRs[$r->id]) && $cantPorRs[$r->id] > 0);
                                // "Sin planos" = RS sin ningún pendiente en el periodo
                                $rsSinPlanos = $razonesSociales->filter(fn($r) => !isset($cantPorRs[$r->id]) || $cantPorRs[$r->id] == 0);
                            @endphp
                            @if($rsConPlanos->count())
                            <div class="rs-glabel">● Con planos — {{ $rsConPlanos->count() }} RS</div>
                            @foreach($rsConPlanos as $rs)
                            @php $cant = $cantPorRs[$rs->id] ?? 0; $nom = mb_strtoupper($rs->razon_social); @endphp
                            <div class="rs-row {{ $razonSocialId == $rs->id ? 'sel':'' }}"
                                 data-lbl="{{ strtolower($rs->razon_social) }}"
                                 onclick="selRs('{{ $rs->id }}','{{ $rs->n_plano }}','{{ addslashes($nom) }}')">
                                <span class="ri g">●</span>
                                <span class="rn">{{ $nom }}</span>
                                <span class="rp">P{{ $rs->n_plano }}</span>
                                <span class="rc">{{ $cant }} p.</span>
                            </div>
                            @endforeach
                            @endif
                            @if($rsSinPlanos->count())
                            <div class="rs-glabel" style="margin-top:.2rem">○ Sin planos — {{ $rsSinPlanos->count() }} RS</div>
                            @foreach($rsSinPlanos as $rs)
                            @php $nom = mb_strtoupper($rs->razon_social); @endphp
                            <div class="rs-row {{ $razonSocialId == $rs->id ? 'sel':'' }}"
                                 data-lbl="{{ strtolower($rs->razon_social) }}"
                                 onclick="selRs('{{ $rs->id }}','{{ $rs->n_plano }}','{{ addslashes($nom) }}')">
                                <span class="ri s">○</span>
                                <span class="rn" style="color:#64748b">{{ $nom }}</span>
                                <span class="rp">P{{ $rs->n_plano }}</span>
                                <span class="rc"></span>
                            </div>
                            @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="filtro-sep"></div>


            {{-- N° Plano --}}
            <div class="filtro-inline">
                <span class="fi-label">Plano</span>
                <select name="n_plano" id="sel-nplano" onchange="checkOtroPlano(this)" style="width:95px">
                    <option value="">Todos</option>
                    @php
                        $maxNp = 40;
                        $nPlanoFiltroInt = ($nPlanoFiltro !== null && $nPlanoFiltro !== '') ? (int)$nPlanoFiltro : null;
                    @endphp
                    @for($np = 1; $np <= $maxNp; $np++)
                    <option value="{{ $np }}"
                        {{ (string)$nPlanoFiltro === (string)$np ? 'selected' : '' }}>
                        P{{ $np }}{{ ($rsSeleccionada && $rsSeleccionada->n_plano == $np) ? ' ⭐' : '' }}
                    </option>
                    @endfor
                    @if($nPlanoFiltroInt && $nPlanoFiltroInt !== 100 && ($nPlanoFiltroInt < 1 || $nPlanoFiltroInt > $maxNp))
                    <option value="{{ $nPlanoFiltroInt }}" selected>
                        P{{ $nPlanoFiltroInt }}{{ ($rsSeleccionada && $rsSeleccionada->n_plano == $nPlanoFiltroInt) ? ' ⭐' : '' }}
                    </option>
                    @endif
                    <option value="100" {{ (string)$nPlanoFiltro === '100' ? 'selected' : '' }}
                        style="color:#92400e;font-weight:700;background:#fefce8">
                        P100 — IR
                    </option>
                    <option value="otro" style="color:#2563eb; font-weight:600; background:#eff6ff;">✍️ Otro...</option>
                </select>
            </div>

            <div class="filtro-sep"></div>

            {{-- Modalidad (multiselect) --}}
            <div class="filtro-inline">
                <span class="fi-label">Modal.</span>
                <div class="multiselect-wrap" id="ms-wrap">
                    <div class="multiselect-trigger" id="ms-trigger" onclick="toggleMs()" style="min-width:80px">
                        <span id="ms-label">{{ count($modalidadesIds) ? count($modalidadesIds).' sel.' : 'Todos' }}</span>
                        <span style="font-size:.7rem">▼</span>
                    </div>
                    <div class="multiselect-dropdown" id="ms-dropdown">
                        <span class="ms-select-all" onclick="toggleAllMs()">&#9745; Todos</span>
                        @php
                            // Si hay RS seleccionada mostramos solo las modalidades presentes en el periodo+RS;
                            // si no hay RS, mostramos todas las activas.
                            $modalidadesParaFiltro = $razonSocialId && $modalidadesDispon->count()
                                ? $modalidadesDispon
                                : $tiposModalidad;
                        @endphp
                        @foreach($modalidadesParaFiltro as $tm)
                        <label class="ms-item">
                            <input type="checkbox" name="tipos_modalidad[]"
                                   value="{{ $tm->id }}"
                                   {{ in_array($tm->id, $modalidadesIds) ? 'checked' : '' }}
                                   onchange="updateMsLabel(); autoSubmit()">
                            {{ $tm->tipo_modalidad }}
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="filtro-sep"></div>

            {{-- Estado de Pago --}}
            <div class="filtro-inline">
                <span class="fi-label">Pago</span>
                <select name="estado_pago" onchange="autoSubmit()"
                    style="padding-right:.9rem;min-width:105px;
                           {{ $estadoPago === 'pendientes' ? 'border-color:#f59e0b;background:#fffbeb;color:#92400e;font-weight:700' :
                              ($estadoPago === 'pagadas'    ? 'border-color:#10b981;background:#f0fdf4;color:#065f46;font-weight:700' :
                              ($estadoPago === 'no_cruzan'  ? 'border-color:#f87171;background:#fef2f2;color:#b91c1c;font-weight:700' : '')) }}">
                    <option value="todas"      {{ $estadoPago === 'todas'      ? 'selected' : '' }}>🔘 Todas</option>
                    <option value="pendientes" {{ $estadoPago === 'pendientes' ? 'selected' : '' }}>⏳ Pendientes</option>
                    <option value="pagadas"    {{ $estadoPago === 'pagadas'    ? 'selected' : '' }}>✅ Pagadas</option>
                    <option value="no_cruzan"  {{ $estadoPago === 'no_cruzan'  ? 'selected' : '' }}>⚠️ No cruzan con el operador</option>
                </select>
            </div>

            {{-- Botones --}}
            <div style="margin-left:auto;display:flex;gap:.4rem;align-items:center">
                @if(auth()->user()->hasRole(['admin', 'superadmin']))
                <a href="{{ route('admin.planos.envio_planillas') }}" class="btn-accion" style="background: #25d366; color: #fff; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 6px; box-shadow: 0 2px 5px rgba(37, 211, 102, 0.2);">
                    <i class="fab fa-whatsapp"></i> Enviar WhatsApp
                </a>
                @endif
                <button type="button" class="btn-accion btn-descargar" onclick="validarCompatibilidadYAbrir('descarga')"
                    @if($planos->count()==0) disabled style="opacity:.4;cursor:not-allowed" @endif>
                    📥 Descargar Plano
                </button>
                @if(!$esIndependiente)
                <button type="button" class="btn-accion btn-pagar" onclick="validarCompatibilidadYAbrir('pago')"
                    @if($planos->count()==0) disabled style="opacity:.4;cursor:not-allowed" @endif>
                    ✅ Confirmar Pago
                </button>
                @endif
            </div>

        </div>
    </form>
</div>

{{-- ── Tabla ───────────────────────────────────────────────────────────── --}}
<div class="tabla-wrap">
@if($planos->count() > 0)
<table class="tabla-planos">
    <thead>
        <tr>
            <th class="sortable" id="th-numero-plano">#</th>
            <th class="sortable">Tipo</th>
            <th class="sortable">No. ID</th>
            <th class="sortable">Nombre</th>
            <th class="sortable" style="text-align:center" title="Fecha de ingreso (verde) / fecha de retiro (rojo)">Fechas</th>
            <th class="sortable">Días</th>
            <th class="sortable">EPS</th>
            <th class="sortable" title="Valor EPS">V.EPS</th>
            <th class="sortable">ARL</th>
            <th class="sortable" title="Valor ARL">V.ARL</th>
            <th class="sortable">CAJA</th>
            <th class="sortable" title="Valor Caja">V.CAJA</th>
            <th class="sortable">PENSION</th>
            <th class="sortable" title="Valor Pensión">V.AFP</th>
            <th class="sortable" title="Total Seguridad Social"><b>TOTAL SS</b></th>
            @if(!$esIndependiente)<th class="sortable">Planilla</th>@endif
            @if(!$esIndependiente)<th class="sortable">Empresa</th>@endif
            @if($esIndependiente)<th class="sortable">Operador</th><th class="sortable">Pago</th>@endif
            <th class="sortable">Envío</th>
        </tr>
    </thead>
    <tbody>
        @php
            $i = 1;
            // Nombres cortos de operador para que la columna no se estire
            $operadorCorto = function ($nombre) {
                $mapa = [
                    'arus enlace'      => 'Enlace',
                    'enlace'           => 'Enlace',
                    'aportes en línea' => 'Aportes',
                    'aportes en linea' => 'Aportes',
                    'mi planilla'      => 'MiPlanilla',
                ];
                $clave = mb_strtolower(trim($nombre ?? ''));
                return $mapa[$clave] ?? \Illuminate\Support\Str::limit($nombre, 10, '…');
            };
        @endphp
        @foreach($planos as $p)
        @php
            $tipoClass = match(strtoupper(substr($p->tipo_modal_nombre ?? '', 0, 1))) {
                'E' => 'e', 'I' => 'i', 'K' => 'k', 'T' => 'tp', default => ''
            };
            $clienteNombre = trim(($p->primer_nombre ?? '').' '.($p->primer_ape ?? ''));
            $clienteNombre = nombre_oracion($clienteNombre);
            $nombreCompletoPlano = nombre_oracion(preg_replace('/\s+/', ' ', trim(($p->primer_nombre ?? '').' '.($p->segundo_nombre ?? '').' '.($p->primer_ape ?? '').' '.($p->segundo_ape ?? ''))));

            // Período que cubre esta fila, para explicar la marca de mes actual.
            $mesesCortos = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',
                            7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
            $periodoFila = ($mesesCortos[(int) $p->mes_plano] ?? $p->mes_plano).'-'.$p->anio_plano;

            // Fechas ingreso/retiro en una sola columna
            $fIng = $p->fecha_ing ? sqldate($p->fecha_ing) : null;
            $fRet = $p->fecha_ret ? sqldate($p->fecha_ret) : null;
            $fecCorta = fn($d) => $d->format('d-').strtolower($d->locale('es')->isoFormat('MMM'));
            $fecTitulo = trim(
                ($fIng ? 'Ingreso: '.$fIng->format('d/m/Y') : '')
                .($fIng && $fRet ? ' · ' : '')
                .($fRet ? 'Retiro: '.$fRet->format('d/m/Y') : '')
            ) ?: 'Sin novedad de ingreso ni retiro';
        @endphp
        {{-- data-operador-id: lo leen los botones PSE/Pagar, así al asignar el operador
             desde la columna Operador no hay que reescribir sus onclick --}}
        <tr id="fila-plano-{{ $p->id }}" class="{{ $p->numero_planilla ? 'ya-pago' : '' }}"
            data-operador-id="{{ $p->operador_cliente_id ?? '' }}">
            <td class="td-numero-plano" style="color:#1d4ed8;cursor:pointer;font-weight:600" data-order="{{ $i }}" data-numero="{{ $i }}" data-id="{{ $p->id }}" data-nplano="{{ $p->n_plano }}" onclick="manejarClicCeldaNumero(this, event)" title="Clic para mover este registro a otro plano (Plano actual: P{{ $p->n_plano }})">{{ $i++ }}</td>
            <td data-order="{{ $p->tipo_modal_nombre ?? $p->tipo_p }}">
                @if($p->contrato_id ?? null)
                <a href="{{ url('/admin/contratos/'.$p->contrato_id.'/edit') }}" style="text-decoration:none" title="Ver contrato">
                    <span class="chip-tipo {{ $tipoClass }}">{{ $p->tipo_modal_nombre ?? $p->tipo_p }}</span>
                @if((int) $p->tipo_p === \App\Services\CorreccionNovedadesService::TIPO_P)
                    <span class="chip-tipo" style="background:#e0e7ff;color:#3730a3" title="Corrección: va en planilla N sobre una planilla ya pagada">N</span>
                @endif
                </a>
                @else
                <span class="chip-tipo {{ $tipoClass }}">{{ $p->tipo_modal_nombre ?? $p->tipo_p }}</span>
                @if((int) $p->tipo_p === \App\Services\CorreccionNovedadesService::TIPO_P)
                    <span class="chip-tipo" style="background:#e0e7ff;color:#3730a3" title="Corrección: va en planilla N sobre una planilla ya pagada">N</span>
                @endif
                @endif
                @if($p->paga_mes_actual ?? false)
                <span class="chip-mesact" title="Cotiza el mes en curso — esta fila cubre {{ $periodoFila }}">MES ACT</span>
                @endif
            </td>
            <td style="white-space:nowrap" data-order="{{ $p->no_identifi }}">
                @if($p->tipo_doc)
                <span style="display:inline-block;background:#e0f2fe;color:#0369a1;font-size:.62rem;font-weight:700;padding:.05rem .3rem;border-radius:3px;margin-right:.25rem;letter-spacing:.03em">{{ $p->tipo_doc }}</span>
                @endif
                {{ $p->no_identifi }}
            </td>
            <td class="td-nombre" title="{{ $nombreCompletoPlano }}" data-order="{{ nombre_oracion($p->nombre_completo ?? $clienteNombre) }}">
                <a href="{{ ($p->cliente_id ?? null) ? url('/admin/clientes/'.$p->cliente_id.'/edit') : '#' }}"
                   style="color:#1d4ed8;text-decoration:none;font-weight:600"
                   title="{{ $nombreCompletoPlano }}">
                    {{ nombre_con_inicial($p->primer_nombre, $p->segundo_nombre ?? null, $p->primer_ape) }}
                </a>
            </td>
            <td class="td-fechas" title="{{ $fecTitulo }}" data-order="{{ $p->fecha_ing ?: ($p->fecha_ret ?: '') }}">
                @if($fIng)<span class="fec-ing"><b>Ing:</b>{{ $fecCorta($fIng) }}</span>@endif
                @if($fRet)<span class="fec-ret"><b>Ret:</b>{{ $fecCorta($fRet) }}</span>@endif
                @if(!$fIng && !$fRet)<span style="color:#cbd5e1">—</span>@endif
            </td>
            <td data-order="{{ $p->num_dias }}">{{ $p->num_dias }}</td>
            <td title="{{ $p->nombre_eps ?? $p->cod_eps }}" style="font-size:.72rem;white-space:nowrap" data-order="{{ $p->nombre_eps ?? $p->cod_eps ?? '' }}">
                {{ $p->nombre_eps ? \Illuminate\Support\Str::limit($p->nombre_eps, 9, '…') : ($p->cod_eps ?? '—') }}
            </td>
            <td data-order="{{ $p->v_eps ?? 0 }}">{{ number_format($p->v_eps ?? 0,0,',','.') }}</td>
            <td title="{{ $p->nombre_arl ?? $p->cod_arl }}" style="font-size:.72rem;white-space:nowrap" data-order="{{ $p->nombre_arl ?? $p->cod_arl ?? '' }}">
                {{ $p->nombre_arl ? \Illuminate\Support\Str::limit($p->nombre_arl, 9, '…') : ($p->cod_arl ?? '—') }}
            </td>
            <td data-order="{{ $p->v_arl ?? 0 }}">{{ number_format($p->v_arl ?? 0,0,',','.') }}</td>
            <td title="{{ $p->nombre_caja ?? $p->cod_caja }}" style="font-size:.72rem;white-space:nowrap" data-order="{{ $p->nombre_caja ?? $p->cod_caja ?? '' }}">
                {{ $p->nombre_caja ? \Illuminate\Support\Str::limit($p->nombre_caja, 9, '…') : ($p->cod_caja ? \Illuminate\Support\Str::limit($p->cod_caja,9,'…') : '—') }}
            </td>
            <td data-order="{{ $p->v_caja ?? 0 }}">{{ number_format($p->v_caja ?? 0,0,',','.') }}</td>
            <td title="{{ $p->nombre_afp ?? $p->cod_afp }}" style="font-size:.72rem;white-space:nowrap" data-order="{{ $p->nombre_afp ?? $p->cod_afp ?? '' }}">
                {{ $p->nombre_afp ? \Illuminate\Support\Str::limit($p->nombre_afp, 9, '…') : ($p->cod_afp ?? '—') }}
            </td>
            <td data-order="{{ $p->v_afp ?? 0 }}">{{ number_format($p->v_afp ?? 0,0,',','.') }}</td>
            <td style="font-weight:700;color:var(--azul-vivo)" data-order="{{ $p->total_ss ?? 0 }}">
                {{ number_format($p->total_ss ?? 0,0,',','.') }}
            </td>
            @if(!$esIndependiente)
            <td id="planilla-{{ $p->id }}" style="text-align:center" data-order="{{ $p->numero_planilla ?? '' }}">
                @if($p->numero_planilla)
                @include('admin.planos._chip_planilla', ['p' => $p])
                @else
                <span style="color:#cbd5e1">—</span>
                @endif
            </td>
            @endif
            @if(!$esIndependiente)<td class="td-empresa" title="{{ $p->nombre_empresa }}" data-order="{{ $p->nombre_empresa ?? '' }}">{{ $p->nombre_empresa ? \Illuminate\Support\Str::limit($p->nombre_empresa,14,'…') : '—' }}</td>@endif
            @if($esIndependiente)
            {{-- Columna Operador: chip si ya lo tiene, select para asignarlo si no --}}
            <td class="td-operador" data-cliente-id="{{ $p->cliente_id ?? '' }}"
                title="{{ $p->operador_cliente_nombre ?? 'Sin operador asignado' }}"
                data-order="{{ $p->operador_cliente_nombre ?? '' }}">
                @if($p->operador_cliente_nombre ?? null)
                <span class="op-chip">🏦 {{ $operadorCorto($p->operador_cliente_nombre) }}</span>
                @elseif($p->cliente_id ?? null)
                <select class="sel-operador" onchange="asignarOperadorCliente(this)"
                        title="Asignar operador de planilla a este contratista">
                    <option value="">— asignar —</option>
                    @foreach($operadores as $op)
                    <option value="{{ $op->id }}" data-corto="{{ $operadorCorto($op->nombre) }}">{{ $op->nombre }}</option>
                    @endforeach
                </select>
                @else
                <span style="color:#cbd5e1;font-size:.67rem">— sin contratista —</span>
                @endif
            </td>
            {{-- Columna Acción: Liquidar (PSE) / Pagar / Pagado --}}
            <td class="td-pago" id="accion-{{ $p->id }}" data-order="{{ $p->numero_planilla ?? '' }}">
                @if($p->numero_planilla)
                @include('admin.planos._chip_planilla', ['p' => $p])
                @else
                <div style="display:flex;align-items:center;gap:.3rem;flex-wrap:nowrap">
                    @if($p->numero_planilla_api)
                    {{-- Ya liquidada en el operador: falta confirmar el pago manualmente --}}
                    <span class="chip-liquidado-api" title="Liquidada en {{ $p->operador_cliente_nombre }}: {{ $p->numero_planilla_api }}">
                        ⚡ {{ $p->numero_planilla_api }}
                        @if($p->url_pago_api)
                        <a href="{{ $p->url_pago_api }}" target="_blank" rel="noopener">PSE →</a>
                        @endif
                    </span>
                    @else
                    @php
                        $puedeLiquidarApi = in_array((int) ($p->operador_cliente_id ?? 0), $operadoresApiIds ?? []);
                        $tituloIcono = $puedeLiquidarApi
                            ? 'Liquidar en '.$p->operador_cliente_nombre.' y generar el link de pago PSE'
                            : ($p->operador_cliente_nombre
                                ? 'Este contratista usa '.$p->operador_cliente_nombre.', que no tiene integración por API.'
                                : 'Este contratista no tiene operador asignado.');
                    @endphp
                    <button type="button"
                        id="btn-pse-{{ $p->id }}"
                        class="btn-pse-icon"
                        onclick="liquidarIndependienteRow({{ $p->id }}, Number(this.closest('tr').dataset.operadorId) || null, this)"
                        @if(!$puedeLiquidarApi) disabled @endif
                        title="{{ $tituloIcono }}">PSE</button>
                    @endif

                    <button type="button"
                        onclick="abrirModalPagoIndividual({{ $p->id }}, {{ $p->total_ss ?? 0 }}, '{{ addslashes($clienteNombre) }}', Number(this.closest('tr').dataset.operadorId) || null)"
                        style="padding:.2rem .55rem;border-radius:6px;font-size:.68rem;font-weight:700;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all .15s"
                        onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                        💳 Pagar
                    </button>
                </div>
                @endif
            </td>
            @endif
            <td class="td-envio" title="{{ $p->envio_planilla }}" data-order="{{ $p->envio_planilla ? 1 : 0 }}">{{ $p->envio_planilla ? 'Sí' : 'No' }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" style="text-align:right">TOTALES &rarr;</td>
            <td></td>
            {{-- EPS: ceil(v_eps/100)*100 por cotizante, luego sumar --}}
            <td>{{ number_format($planos->sum(fn($p) => (int)(ceil(($p->v_eps??0)/100)*100)),0,',','.') }}</td>
            <td></td>
            {{-- ARL --}}
            <td>{{ number_format($planos->sum(fn($p) => (int)(ceil(($p->v_arl??0)/100)*100)),0,',','.') }}</td>
            <td></td>
            {{-- CCF --}}
            <td>{{ number_format($planos->sum(fn($p) => (int)(ceil(($p->v_caja??0)/100)*100)),0,',','.') }}</td>
            <td></td>
            {{-- AFP --}}
            <td>{{ number_format($planos->sum(fn($p) => (int)(ceil(($p->v_afp??0)/100)*100)),0,',','.') }}</td>
            <td></td>{{-- TOTAL SS: se muestra en el resumen inferior --}}
            {{-- Empresas: Planilla + Empresa + Envío · Independientes: Operador + Pago + Envío --}}
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
@else
<div class="empty-state">
    <div class="es-icon">📄</div>
    <p>No hay planos de planilla para el período y filtros seleccionados.</p>
    <p style="margin-top:.35rem;font-size:.75rem">Filtre por período, razón social y/o tipo de modalidad.</p>
</div>
@endif
</div>

{{-- ── Resumen unificado: siempre visible cuando hay planos ──────── --}}
@if($planos->count() > 0)
<div class="mora-bloque" id="mora-bloque"
     style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-color:#7dd3fc;animation:none;margin-top:.75rem">
    {{-- Datos fijos del servidor --}}
    @if($rsSeleccionada)
    <div class="mora-item">
        <span class="ml">N° Plano</span>
        <span class="mv" style="color:var(--amarillo)">{{ $nPlanoActual }}</span>
    </div>
    <div class="mora-sep" style="background:#7dd3fc"></div>
    @endif
    <div class="mora-item">
        <span class="ml">Personas</span>
        <span class="mv" style="color:var(--azul-oscuro)">{{ $totalPersonas }}</span>
    </div>
    <div class="mora-sep" style="background:#7dd3fc"></div>
    <div class="mora-item">
        <span class="ml">Total SS</span>
        <span class="mv azul" id="total-ss-display">$ {{ number_format($totalSS,0,',','.') }}</span>
    </div>

    {{-- Sección mora: se inyecta por JS cuando aplica --}}
    @if(!$planoPagado && $rsSeleccionada)
    <div id="mora-extra" style="display:contents">
        {{-- Los siguientes nodos se muestran/ocultan por JS --}}
        <div class="mora-sep" style="background:#7dd3fc" id="mora-sep1" hidden></div>
        <div class="mora-item" id="mora-item-vence" hidden>
            <span class="ml">⚠️ Vencimiento PILA</span>
            <span class="mv azul" id="mora-fecha-vence">—</span>
        </div>
        <div class="mora-sep" style="background:#fde68a" id="mora-sep2" hidden></div>
        <div class="mora-item" id="mora-item-dias" hidden>
            <span class="ml">Días mora</span>
            <span class="mv rojo" id="mora-dias">—</span>
        </div>
        <div class="mora-sep" style="background:#fde68a" id="mora-sep3" hidden></div>
        <div class="mora-item" id="mora-item-valor" hidden>
            <span class="ml">Mora estimada</span>
            <span class="mv rojo" id="mora-valor">—</span>
        </div>
        <div class="mora-sep" style="background:#c4b5fd" id="mora-sep4" hidden></div>
        <div class="mora-item" id="mora-item-total" hidden>
            <span class="ml">Total a pagar</span>
            <span class="mv" style="color:#7c3aed" id="mora-total">—</span>
        </div>
        <div class="mora-info" id="mora-info-txt" style="display:none"></div>
    </div>
    @elseif($planoPagado && $rsSeleccionada)
    @php
        // ssBase PILA = suma de ceil(v_x/100)*100 por cotizante
        $ssBasePila = $planos->sum(fn($p) =>
            (int)(ceil(($p->v_eps ??0)/100)*100) +
            (int)(ceil(($p->v_afp ??0)/100)*100) +
            (int)(ceil(($p->v_arl ??0)/100)*100) +
            (int)(ceil(($p->v_caja??0)/100)*100)
        );
        // Dos pasos: el total es la suma de las dos planillas.
        $totalPagado  = $valorPagado ? $valorPagado + (int) ($pagoPaso1->valor ?? 0) : null;
        $moraPagada   = ($totalPagado && $totalPagado > $ssBasePila) ? ($totalPagado - $ssBasePila) : null;
    @endphp
    <div class="mora-sep" style="background:#86efac"></div>
    <div class="mora-item">
        <span class="ml">Estado</span>
        <span class="mv verde">✅ Pagado</span>
    </div>
    @if($moraPagada !== null && $moraPagada > 0)
    <div class="mora-sep" style="background:#fde68a"></div>
    <div class="mora-item">
        <span class="ml">Mora pagada</span>
        <span class="mv rojo">$ {{ number_format($moraPagada,0,',','.') }}</span>
    </div>
    @endif
    @if($totalPagado)
    <div class="mora-sep" style="background:#c4b5fd"></div>
    <div class="mora-item">
        <span class="ml">Total pagado</span>
        <span class="mv" style="color:#7c3aed">$ {{ number_format($totalPagado,0,',','.') }}</span>
    </div>
    @endif
    @endif
</div>
@endif

{{-- ── Tabla desglose por entidad (igual al operador PILA) ─────────── --}}
@if($planos->count() > 0 && $rsSeleccionada)
<div style="margin-top: .75rem; text-align: center;">
    <button type="button" id="btn-toggle-desglose" onclick="toggleDesgloseEntidades()" class="btn-glass" style="display: inline-flex; align-items: center; gap: .4rem; background: rgba(30,58,138,0.06); border-color: rgba(30,58,138,0.2); color: #1e3a8a; padding: 0.4rem 1.1rem; font-size: 0.76rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='rgba(30,58,138,0.12)'" onmouseout="this.style.background='rgba(30,58,138,0.06)'">
        <span>📊</span> <span id="btn-toggle-desglose-text">Mostrar Desglose por Administradora</span>
    </button>
</div>

<div id="desglose-entidades-wrap" style="display:none;margin-top:.75rem">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;background:linear-gradient(135deg,#1e3a8a,#1e40af);color:#fff">
            <div style="font-size:.8rem;font-weight:700;letter-spacing:.02em">📊 Desglose por Administradora</div>
            <div style="font-size:.7rem;opacity:.85">Comparar con operador PILA</div>
        </div>
        <div style="overflow-x:auto">
        <table id="tabla-desglose-entidades" style="width:100%;border-collapse:collapse;font-size:.75rem">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
                    <th style="padding:.45rem .6rem;text-align:left;font-weight:700;color:#475569;white-space:nowrap">NIT</th>
                    <th style="padding:.45rem .6rem;text-align:left;font-weight:700;color:#475569;white-space:nowrap">Tipo</th>
                    <th style="padding:.45rem .6rem;text-align:left;font-weight:700;color:#475569">Administradora</th>
                    <th style="padding:.45rem .6rem;text-align:right;font-weight:700;color:#475569">Afiliados</th>
                    <th style="padding:.45rem .6rem;text-align:right;font-weight:700;color:#475569;white-space:nowrap">Valor sin mora</th>
                    <th style="padding:.45rem .6rem;text-align:right;font-weight:700;color:#475569">Mora</th>
                    <th style="padding:.45rem .6rem;text-align:right;font-weight:700;color:#475569">Total</th>
                </tr>
            </thead>
            <tbody id="tbody-desglose-entidades">
                <tr><td colspan="7" style="padding:.8rem;text-align:center;color:#94a3b8">Calculando...</td></tr>
            </tbody>
            <tfoot id="tfoot-desglose-entidades" style="display:none">
                <tr style="background:#f0f9ff;border-top:2px solid #7dd3fc;font-weight:700">
                    <td colspan="3" style="padding:.45rem .6rem;color:#1e40af">Subtotales</td>
                    <td style="padding:.45rem .6rem;text-align:right;color:#1e40af" id="pie-afiliados">—</td>
                    <td style="padding:.45rem .6rem;text-align:right;color:#1e40af" id="pie-sin-mora">—</td>
                    <td style="padding:.45rem .6rem;text-align:right;color:#dc2626" id="pie-mora">—</td>
                    <td style="padding:.45rem .6rem;text-align:right;color:#7c3aed" id="pie-total">—</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL 1: Descargar Plano (TXT o XLSX) + pregunta N_PLANO
═══════════════════════════════════════════════════════════════════════ --}}
<div class="modal-overlay" id="modal-descarga">
    <div class="modal-box md">
        <div class="modal-head">
            <h3>📥 Descargar Plano</h3>
            <button class="modal-close" onclick="cerrarModal('modal-descarga')">✕</button>
        </div>
        <div class="modal-body">

            {{-- ── Info del plano (periodo, clientes, valor SS) ──────── --}}
            @if($rsSeleccionada)
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .7rem;text-align:center">
                    <div style="font-size:.62rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Período</div>
                    <div style="font-size:.88rem;font-weight:800;color:#0f172a;margin-top:.15rem">
                        {{ \Carbon\Carbon::createFromDate(null, $mes, 1)->locale('es')->monthName }} {{ $anio }}
                    </div>
                </div>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.5rem .7rem;text-align:center">
                    <div style="font-size:.62rem;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Clientes en plano</div>
                    <div style="font-size:.88rem;font-weight:800;color:#1d4ed8;margin-top:.15rem">{{ $totalPersonas }}</div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.5rem .7rem;text-align:center">
                    <div style="font-size:.62rem;color:#15803d;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Valor Plano</div>
                    <div style="font-size:.82rem;font-weight:800;color:#15803d;margin-top:.15rem"
                         id="modal-descarga-valor-plano"
                         data-pagado="{{ $valorPagado ?? 0 }}"
                         data-ss="{{ $totalSS }}">
                        $ {{ number_format($totalSS, 0, ',', '.') }}
                    </div>
                </div>
            </div>
            @endif

            {{-- ── Aviso plano YA PAGADO ─────────────────────────────── --}}
            @if($planoPagado)
            <div style="display:flex;align-items:flex-start;gap:.6rem;background:#fef2f2;border:1px solid #fca5a5;
                        border-radius:10px;padding:.65rem .85rem;margin-bottom:1rem">
                <span style="font-size:1.1rem;line-height:1">🔒</span>
                <div style="flex:1">
                    <div style="font-size:.78rem;font-weight:700;color:#991b1b">Este plano ya fue confirmado como pagado</div>
                    <div style="font-size:.72rem;color:#b91c1c;margin-top:.2rem">
                        @if($pagoPaso1)
                        Pagado en dos planillas: paso 1 <strong>{{ $pagoPaso1->numero_planilla }}</strong>
                        ($ {{ number_format((int) $pagoPaso1->valor,0,',','.') }}) y corrección
                        <strong>{{ $numeroPlanillaPagado }}</strong> ($ {{ number_format((int) $valorPagado,0,',','.') }}).
                        @else
                        N° Planilla: <strong>{{ $numeroPlanillaPagado }}</strong>.
                        @endif
                        Las descargas están inhabilitadas para evitar modificaciones accidentales.
                    </div>
                </div>
                @if($puedeCorregirPago)
                {{-- El número se digita a mano al confirmar: si salió mal hay que
                     poder arreglarlo sin borrar el gasto y volver a empezar. --}}
                <button type="button"
                        onclick="abrirCorregirPago({{ Illuminate\Support\Js::from($numeroPlanillaPagado) }}, {{ (int) ($valorPagado ?? 0) }}, {{ (int) $gastoPagoId }}, '')"
                        style="flex-shrink:0;background:#fff;border:1px solid #fca5a5;color:#b91c1c;border-radius:8px;
                               padding:.35rem .7rem;font-size:.73rem;font-weight:700;cursor:pointer;white-space:nowrap"
                        title="Corregir el número de planilla o el valor pagado">
                    ✏️ Corregir
                </button>
                @endif
            </div>
            @endif

            {{-- ── Operadores activos del aliado (Oculto a petición del usuario) ──
            @if($operadores->count())
            <div style="margin-bottom:1rem">
                <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem">🏦 Operadores activos del aliado</div>
                <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                    @foreach($operadores as $op)
                    <span style="display:inline-flex;align-items:center;gap:.3rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:.2rem .65rem;font-size:.75rem;font-weight:600">
                        🏦 {{ $op->nombre }}
                    </span>
                    @endforeach
                </div>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:.35rem">Descargue el formato Excel correspondiente al operador con el que realizará el pago.</div>
            </div>
            @endif
            --}}

            {{-- ── Botones de descarga (deshabilitados si pagado) ────── --}}
            @php $pagado = $planoPagado; @endphp

            <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: .85rem;">

                {{-- ── Liquidación directa por API (Enlace Operativo) — opción
                     prioritaria: sin descargar ni subir nada a mano. Se revela
                     desde JS solo si hay credenciales configuradas para el
                     aliado. Ver PlanillaApiController::estado(). --}}
                <div id="bloque-enlace-api" style="display:none;background:linear-gradient(135deg,#faf5ff,#f5f3ff);border:1.5px solid #ddd6fe;border-radius:12px;padding:.9rem 1rem">
                    <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.5rem">
                        <span style="font-size:.72rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em">⚡ Liquidar en línea</span>
                        <span style="background:#7c3aed;color:#fff;font-size:.6rem;font-weight:800;letter-spacing:.04em;padding:.15rem .5rem;border-radius:20px">RECOMENDADO</span>
                    </div>

                    {{-- Un botón por operador con credenciales cargadas --}}
                    <div id="enlace-botones" style="display:flex;flex-direction:column;gap:.5rem"></div>

                    <div style="font-size:.7rem;color:#6b21a8;margin-top:.4rem;text-align:center;line-height:1.3">
                        Envía el plano directamente al operador y devuelve el <strong>número de planilla</strong> y el <strong>link de pago PSE</strong>, sin descargar ni subir nada a mano.
                    </div>

                    {{-- Resultado de la última liquidación de este periodo --}}
                    <div id="enlace-ultima" style="display:none;margin-top:.6rem"></div>

                    {{-- Cuadre del período: vigentes que aún no entran a ninguna
                         planilla. Informativo — mientras el mes corre es normal
                         que haya tandas por liquidar. --}}
                    <div id="enlace-pendientes" style="display:none;margin-top:.6rem"></div>
                </div>

                {{-- Descargas manuales: colapsadas por defecto en cuanto se
                     confirma que hay liquidación en línea disponible (ver
                     cargarEstadoEnlace). Si no hay operador configurado
                     quedan abiertas, porque son la única opción. --}}
                <details id="detalle-descargas" open>
                    <summary><span>📥</span> Descargar el archivo manualmente</summary>

                    <div style="margin-top:.85rem;display:flex;flex-direction:column;gap:1rem">
                        {{-- Formato Universal --}}
                        <div>
                            <div style="font-size: .72rem; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .4rem; display: flex; align-items: center; gap: .3rem;">
                                <span>📄</span> Formato Universal (TXT)
                            </div>
                            <button class="btn-descarga-principal"
                                    onclick="ejecutarDescargaMiPlanilla()"
                                    @if($pagado) disabled @endif>
                                📄 Descargar Txt para todos los operadores
                            </button>
                            <div style="font-size: .7rem; color: #64748b; margin-top: .35rem; text-align: center; line-height: 1.3;">
                                Este archivo plano (PILA) sirve para <strong>cualquier operador</strong> (MiPlanilla, Aportes en Línea, Enlace, Asopagos, etc.).
                            </div>
                        </div>

                        {{-- Separador Visual --}}
                        <div style="display: flex; align-items: center; text-align: center; margin: .2rem 0;">
                            <div style="flex-grow: 1; border-top: 1px solid #e2e8f0;"></div>
                            <span style="padding: 0 .75rem; font-size: .68rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em;">Otras opciones en Excel</span>
                            <div style="flex-grow: 1; border-top: 1px solid #e2e8f0;"></div>
                        </div>

                        {{-- Formatos Alternativos en contenedor agrupado --}}
                        <div class="contenedor-descargas-secundarias">
                            <button class="btn-descarga-secundario"
                                    onclick="ejecutarDescarga('xlsx')"
                                    @if($pagado) disabled @endif>
                                📊 Excel Simple (Enlace)
                            </button>

                            <button class="btn-descarga-secundario"
                                    onclick="ejecutarDescargaAsopagos()"
                                    @if($pagado) disabled @endif>
                                📌 Excel Asopagos
                            </button>

                            <button class="btn-descarga-secundario"
                                    id="btn-aportes-en-linea"
                                    onclick="ejecutarDescargaAportesEnLinea()"
                                    @if($pagado) disabled @endif>
                                📈 Excel Aportes en Línea
                            </button>

                            <button class="btn-descarga-secundario"
                                    id="btn-aportes-en-linea-2"
                                    onclick="ejecutarDescargaAportesEnLinea(2)"
                                    title="Mismos datos, sobre la plantilla que descarga el portal (con sus listas desplegables)"
                                    @if($pagado) disabled @endif>
                                📈 Excel Aportes en Línea 2
                            </button>
                        </div>
                    </div>
                </details>

            </div>

            <div style="border-top:1px solid #f1f5f9;padding-top:1rem;margin-top:1rem">
                <div class="aviso-modal">
                    <strong>⚠️ Actualizar N° Plano</strong>
                    Si realizará el pago con este plano, actualice el N_PLANO de la razón social para que los pagos siguientes queden en un nuevo número separado.
                </div>

                @if($rsSeleccionada)
                <div class="info-chip">
                    NIT: {{ $rsSeleccionada->id }} · {{ $rsSeleccionada->razon_social }}
                </div>
                @endif

                <div class="form-row">
                    {{-- N_PLANO actual (solo lectura) --}}
                    <div class="form-grupo" style="max-width:110px">
                        <label>N_PLANO actual</label>
                        <input type="number" value="{{ $nPlanoActual ?? 0 }}" readonly
                               style="background:#f1f5f9;color:#64748b;font-weight:700;text-align:center">
                    </div>
                    {{-- N_PLANO nuevo --}}
                    <div class="form-grupo" style="max-width:110px">
                        <label>N_PLANO nuevo</label>
                        <div class="nplano-wrap">
                            <input type="number" id="inp-nplano-modal" min="1"
                                   value="{{ ($nPlanoActual ?? 0) + 1 }}" style="width:70px;text-align:center">
                            <button type="button" class="btn-plus"
                                    onclick="document.getElementById('inp-nplano-modal').stepUp()">+</button>
                        </div>
                    </div>
                </div>
                <button class="btn-accion btn-pagar" style="width:100%;justify-content:center" onclick="guardarNPlano()"
                        @if(!$rsSeleccionada) disabled @endif>
                    💾 Guardar N_PLANO
                </button>
            </div>
        </div>
    </div>
</div>
{{-- ══════════════════════════════════════════════════════════════════════
     MODAL: Mover registro a otro n_plano
════════════════════════════════════════════════════════════════════════ --}}
<div class="modal-overlay" id="modal-mover">
    <div class="modal-box" style="max-width:360px">
        <div class="modal-head">
            <h3>🔄 Mover a otro Plano</h3>
            <button class="modal-close" onclick="cerrarModal('modal-mover')">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="mover-plano-id">
            <p style="font-size:.82rem;color:#64748b;margin-bottom:.75rem">
                Cambia el número de plano de este registro. El registro se moverá al nuevo plano al guardar.
            </p>
            <div class="form-row">
                <div class="form-grupo" style="max-width:110px">
                    <label>Plano actual</label>
                    <input type="number" id="mover-plano-actual" readonly
                           style="background:#f1f5f9;color:#64748b;font-weight:700;text-align:center">
                </div>
                <div class="form-grupo" style="max-width:110px">
                    <label>Nuevo plano</label>
                    <div class="nplano-wrap">
                        <input type="number" id="mover-plano-nuevo" min="1"
                               style="width:70px;text-align:center">
                        <button type="button" class="btn-plus"
                                onclick="document.getElementById('mover-plano-nuevo').stepUp()">+</button>
                    </div>
                </div>
            </div>
            <button class="btn-accion btn-pagar" style="width:100%;justify-content:center;margin-top:.5rem"
                    onclick="guardarMover()">
                💾 Mover Plano
            </button>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL 2: Confirmar Pago al Operador
═══════════════════════════════════════════════════════════════════════ --}}
<div class="modal-overlay" id="modal-pago">
    <div class="modal-box lg">
        <div class="modal-head">
            <h3 id="modal-pago-titulo">✅ Confirmar Pago de Planilla al Operador</h3>
            <button class="modal-close" onclick="cerrarModal('modal-pago')">✕</button>
        </div>
        <div class="modal-body">
            <div class="aviso-modal" id="modal-pago-aviso">
                <strong>CONFIRMAR PAGO CON EL NÚMERO DE PLANILLA EXPEDIDO POR EL OPERADOR</strong>
                Al confirmar, <strong>todas las personas incluidas en este filtro</strong> quedarán marcadas con el número de planilla asignado. Si alguna persona no entró en este pago, cámbiela de número de plano antes de confirmar.
            </div>
            <div id="modal-pago-dos-pasos" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                        padding:.55rem .8rem;margin-bottom:.75rem;font-size:.78rem;color:#92400e"></div>

            <div class="form-row">
                <div class="form-grupo">
                    <label>Operador</label>
                    <select id="pago-operador" required>
                        <option value="">— Seleccione —</option>
                        @foreach($operadores as $op)
                        <option value="{{ $op->nombre }}" data-op-id="{{ $op->id }}">{{ $op->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Número de Planilla</label>
                    <input type="text" id="pago-numero" placeholder="Ej: SOI-2026-00123" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-grupo">
                    <label>Valor Pagado <span style="color:#ef4444">*</span></label>
                    <input type="number" id="pago-valor"
                           value="{{ $totalSS }}" min="1" step="100">
                </div>
                <div class="form-grupo">
                    <label>Cuenta Bancaria <span style="color:#ef4444">*</span></label>
                    <select id="pago-banco">
                        <option value="">— Seleccione banco —</option>
                        @foreach($bancos as $b)
                        <option value="{{ $b->id }}">
                            {{ $b->banco ? $b->banco . ' ' : '' }}{{ $b->nombre }}{{ $b->numero_cuenta ? ' #' . $b->numero_cuenta : '' }}
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-grupo">
                    <label>Fecha del Pago</label>
                    <input type="date" id="pago-fecha" value="{{ now()->format('Y-m-d') }}"
                           max="{{ now()->format('Y-m-d') }}">
                    <small style="color:#64748b;font-size:.7rem">
                        La del soporte. Solo cámbiela si está confirmando un pago de otro día.
                    </small>
                </div>
                <div class="form-grupo">
                    <label>Observación</label>
                    <textarea id="pago-obs" placeholder="Observaciones del pago..."></textarea>
                </div>
            </div>

            {{-- Soporte del pago: layout 2 columnas --}}
            <div class="form-row">
                <div class="form-grupo">
                    <label>📎 Soporte del Pago <span style="font-weight:400;color:#94a3b8">(imagen o PDF, máx. 5 MB)</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;align-items:start">

                        {{-- Columna izquierda: acciones --}}
                        <div style="display:flex;flex-direction:column;gap:.5rem">

                            {{-- Botón pegar --}}
                            <button type="button" id="btn-pegar-soporte"
                                onclick="pegarSoportePortapapeles()"
                                style="width:100%;padding:.6rem .8rem;border:2px dashed #93c5fd;border-radius:10px;
                                       background:#eff6ff;color:#1d4ed8;font-size:.82rem;font-weight:600;
                                       cursor:pointer;display:flex;align-items:center;justify-content:center;
                                       gap:.45rem;transition:all .2s"
                                onmouseover="this.style.background='#dbeafe';this.style.borderColor='#3b82f6'"
                                onmouseout="this.style.background='#eff6ff';this.style.borderColor='#93c5fd'">
                                📋 Pegar del portapapeles
                            </button>

                            {{-- Estado (vacío inicialmente, se llena por JS) --}}
                            <div id="soporte-estado" style="display:none;border:2px dashed #86efac;border-radius:10px;
                                 background:#f0fdf4;padding:.5rem .75rem;font-size:.78rem;font-weight:600;
                                 color:#15803d;display:none;align-items:center;gap:.4rem">
                            </div>

                            {{-- Botón seleccionar archivo --}}
                            <button type="button"
                                onclick="document.getElementById('pago-soporte').click()"
                                style="width:100%;padding:.5rem .8rem;border:1.5px solid #e2e8f0;border-radius:10px;
                                       background:#f8fafc;color:#475569;font-size:.78rem;font-weight:500;
                                       cursor:pointer;display:flex;align-items:center;justify-content:center;
                                       gap:.45rem;transition:all .2s"
                                onmouseover="this.style.background='#f1f5f9'"
                                onmouseout="this.style.background='#f8fafc'">
                                📂 Seleccionar archivo
                            </button>

                            <input type="file" id="pago-soporte" accept="image/*,.pdf" style="display:none"
                                   onchange="previewSoporte(this.files[0])">
                        </div>

                        {{-- Columna derecha: preview --}}
                        <div id="soporte-panel-img"
                             style="border:2px dashed #e2e8f0;border-radius:10px;background:#f8fafc;
                                    min-height:110px;display:flex;align-items:center;justify-content:center;
                                    overflow:hidden;transition:border-color .2s"
                             ondragover="event.preventDefault();this.style.borderColor='var(--acento)'"
                             ondragleave="this.style.borderColor='#e2e8f0'"
                             ondrop="handleSoporteDrop(event)">
                            <span id="soporte-placeholder" style="font-size:.75rem;color:#94a3b8;text-align:center;padding:.5rem">
                                🖼️ La imagen aparece aquí
                            </span>
                        </div>

                    </div>
                </div>
            </div>

            <div id="pago-resultado" style="display:none;margin-top:.5rem"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-accion btn-cancelar" onclick="cerrarModal('modal-pago')">Cancelar</button>
            <button class="btn-accion btn-pagar" id="btn-confirmar-pago" onclick="ejecutarConfirmarPago()">
                ✅ CONFIRMAR PAGO PLANILLA
            </button>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL: Opciones de una planilla (clic en el chip)
     Antes el clic copiaba directo y para corregir hacía falta un lápiz en cada
     fila, que llenaba la tabla de iconos. Ahora el chip abre esto.
═══════════════════════════════════════════════════════════════════════ --}}
<div class="modal-overlay" id="modal-acciones-planilla">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head">
            <h3>🧾 Planilla</h3>
            <button class="modal-close" onclick="cerrarModal('modal-acciones-planilla')">✕</button>
        </div>
        <div class="modal-body">
            <div class="acc-planilla-num" id="acc-planilla-num"></div>
            <div id="acc-planilla-quien" style="display:none;font-size:.78rem;color:#475569;margin-top:.5rem;text-align:center"></div>
            <div id="acc-planilla-estado" style="display:none;font-size:.73rem;color:#64748b;margin-top:.45rem;text-align:center"></div>

            <div style="display:flex;flex-direction:column;gap:.45rem;margin-top:.9rem">
                <button type="button" class="acc-planilla-btn" onclick="copiarPlanillaActual()">📋 Copiar número</button>
                <button type="button" class="acc-planilla-btn editar" id="acc-planilla-editar" onclick="corregirPlanillaActual()">✏️ Corregir número o valor</button>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL: Corregir un pago ya confirmado
     El número de planilla vive en el gasto y en cada registro del plano; el
     valor solo en el gasto. Se corrigen juntos para que no queden desfasados.
═══════════════════════════════════════════════════════════════════════ --}}
@if($planos->count())
<div class="modal-overlay" id="modal-corregir-pago">
    <div class="modal-box">
        <div class="modal-head">
            <h3>✏️ Corregir pago de planilla</h3>
            <button class="modal-close" onclick="cerrarModal('modal-corregir-pago')">✕</button>
        </div>
        <div class="modal-body">
            <div class="aviso-modal">
                <strong>SE CAMBIA EN EL GASTO Y EN EL PLANO A LA VEZ</strong>
                El número nuevo reemplaza al actual en el gasto y en todos los registros que lleven esa
                planilla. El valor solo vive en el gasto, así que corregirlo mueve la caja o el banco de
                la fecha del pago.
            </div>

            <div id="corr-quien" style="display:none;font-size:.75rem;color:#475569;margin-bottom:.5rem"></div>

            <div class="form-row">
                <div class="form-grupo">
                    <label>Número de Planilla <span id="corr-numero-viejo" style="color:#94a3b8;font-weight:400"></span></label>
                    <input type="text" id="corr-numero" required>
                </div>
                <div class="form-grupo">
                    <label>Valor Pagado</label>
                    <input type="number" id="corr-valor" min="1" required>
                </div>
            </div>

            <div class="form-grupo">
                <label>Motivo de la corrección</label>
                <input type="text" id="corr-motivo" maxlength="300" placeholder="Ej: número mal digitado al confirmar">
            </div>

            <div id="corr-resultado" style="display:none;margin-top:.6rem"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-accion btn-cancelar" onclick="cerrarModal('modal-corregir-pago')">Cancelar</button>
            <button class="btn-accion btn-pagar" id="btn-corregir-pago" onclick="ejecutarCorregirPago()">
                💾 GUARDAR CORRECCIÓN
            </button>
        </div>
    </div>
</div>
@endif

{{-- Toast global --}}
<div class="toast" id="toast-msg"></div>

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL: Incompatibilidad de Modalidades
═══════════════════════════════════════════════════════════════════════ --}}
{{-- ── Detalle de errores devueltos por Enlace Operativo ────────────── --}}
<div class="modal-overlay" id="modal-enlace-errores">
    <div class="modal-box" style="max-width:820px">
        <div class="modal-head" style="background:linear-gradient(135deg,#92400e,#b45309);border-radius:16px 16px 0 0">
            <h3 style="color:#fff;display:flex;align-items:center;gap:.5rem">⚠️ Errores reportados por el operador</h3>
            <button class="modal-close" onclick="cerrarModal('modal-enlace-errores')" style="color:#fde68a">✕</button>
        </div>
        <div class="modal-body">
            <div id="enlace-errores-resumen" style="font-size:.78rem;font-weight:700;color:#92400e;margin-bottom:.75rem"></div>

            {{-- Errores que Enlace marca como autocorregibles: se muestra a quién
                 y qué se le va a cambiar antes de aceptar. --}}
            <div id="enlace-autocorreccion" style="display:none;margin-bottom:.85rem;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px;padding:.75rem">
                <div style="font-size:.78rem;font-weight:800;color:#1e40af;margin-bottom:.5rem">
                    🔁 Enlace puede corregir esto automáticamente
                </div>
                <div id="enlace-autocorreccion-lista" style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.6rem"></div>
                <div style="font-size:.72rem;color:#1e3a8a;margin-bottom:.6rem">
                    El mismo cambio se guarda en Brynex (plano del período, contratos vigentes y ficha del
                    cliente), para que no se repita el mes entrante ni en el próximo contrato.
                </div>
                <button type="button" id="btn-autocorregir" onclick="autocorregirEnlace()"
                        style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.45rem .85rem;font-size:.76rem;font-weight:700;cursor:pointer">
                    Autocorregir en Enlace y en Brynex
                </button>
            </div>

            <div style="max-height:52vh;overflow:auto;border:1px solid #e2e8f0;border-radius:10px">
                <table style="width:100%;border-collapse:collapse;font-size:.76rem">
                    <thead>
                        <tr style="background:#f8fafc;position:sticky;top:0">
                            <th style="padding:.5rem;text-align:left;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Tipo</th>
                            <th style="padding:.5rem;text-align:left;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Cotizante</th>
                            <th style="padding:.5rem;text-align:left;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Descripción</th>
                        </tr>
                    </thead>
                    <tbody id="enlace-errores-cuerpo"></tbody>
                </table>
            </div>

            <div class="aviso-modal" style="margin-top:.85rem">
                <strong>ℹ️ Nota</strong>
                La validación solo lista las primeras 100 líneas con error. Corrija los datos en Brynex y vuelva a liquidar.
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-incompatibilidad">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-head" style="background:linear-gradient(135deg,#7f1d1d,#991b1b);border-radius:16px 16px 0 0">
            <h3 style="color:#fff;display:flex;align-items:center;gap:.5rem">⚠️ Modalidades Incompatibles en el Filtro</h3>
            <button class="modal-close" onclick="cerrarModal('modal-incompatibilidad')" style="color:#fca5a5">✕</button>
        </div>
        <div class="modal-body">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem">
                <p style="font-size:.82rem;color:#991b1b;font-weight:600;margin-bottom:.4rem">El filtro actual contiene tipos de modalidad que <strong>no son compatibles entre sí</strong> y no pueden descargarse ni pagarse juntos.</p>
                <p style="font-size:.78rem;color:#b91c1c">Debe filtrar por modalidades compatibles antes de continuar.</p>
            </div>

            {{-- Tipos incompatibles detectados --}}
            <div style="margin-bottom:1rem">
                <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">⚡ Tipos detectados en el filtro actual</div>
                <div id="incompat-tipos-detectados" style="display:flex;flex-wrap:wrap;gap:.35rem"></div>
            </div>

            {{-- Reglas de compatibilidad --}}
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem 1rem">
                <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.65rem">📋 Reglas de Compatibilidad</div>
                <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.78rem">
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">✅</span>
                        <div><strong style="color:#15803d">Grupo A — Dependientes + Tiempo Parcial estándar:</strong> <span style="color:#374151">Tipos <code>E(0), TP7(1), TP14(2), TP21(3), TP30(4), EPS+ARL(12)</code> pueden ir juntos.</span></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">✅</span>
                        <div><strong style="color:#15803d">Grupo B — Tiempo Parcial combinado:</strong> <span style="color:#374151">Tipos <code>TP(7-14)(-6), TP(7-21)(-7), TP(14-21)(-8)</code> pueden ir juntos.</span></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#fef9c3;border:1px solid #fde68a;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">🔒</span>
                        <div><strong style="color:#92400e">Grupo C — Estudiante K (-1):</strong> <span style="color:#374151">Debe ir <strong>solo</strong>, no puede mezclarse con otros grupos.</span></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#fef9c3;border:1px solid #fde68a;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">🔒</span>
                        <div><strong style="color:#92400e">Grupo D — ARL Planilla Y (8):</strong> <span style="color:#374151">Debe ir <strong>solo</strong>, no puede mezclarse con otros grupos.</span></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#fef9c3;border:1px solid #fde68a;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">🔒</span>
                        <div><strong style="color:#92400e">Grupo E — Tipo 13:</strong> <span style="color:#374151">Debe ir <strong>solo</strong>, no puede mezclarse con otros grupos.</span></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.45rem .6rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px">
                        <span style="font-size:1rem;flex-shrink:0">💳</span>
                        <div><strong style="color:#1d4ed8">Grupo F — Independientes (10, 11, 14):</strong> <span style="color:#374151">Se pagan de forma <strong>individual</strong> por persona, no como planilla grupal.</span></div>
                    </div>
                </div>
            </div>

            <div style="margin-top:1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.65rem .9rem;font-size:.78rem;color:#1e40af">
                💡 <strong>¿Cómo solucionarlo?</strong> Use el filtro <strong>Modalidad</strong> para seleccionar solo los tipos compatibles del mismo grupo antes de descargar o confirmar el pago.
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-accion btn-cancelar" onclick="cerrarModal('modal-incompatibilidad')">Entendido</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let desgloseVisible = false;
// ── Datos del contexto Blade ──────────────────────────────────────────
const CTX = {
    razonSocialId : {{ $razonSocialId ?? 'null' }},
    nPlanoFiltro  : {{ $nPlanoFiltro  ?? 'null' }},
    mes           : {{ $mes }},
    anio          : {{ $anio }},
    modalidadesIds: {!! json_encode(array_map('intval', $modalidadesIds)) !!},
    totalSS          : {{ $totalSS }},
    totalSSPendiente  : {{ $planos->filter(fn($p) => empty($p->numero_planilla))->sum('total_ss') }},
    // ssBaseOperador: suma de aportes individuales con redondeo PILA ceil(x/100)*100 por cotizante
    // El operador redondea cada aporte (v_eps, v_afp, v_arl, v_caja) al siguiente múltiplo de 100
    // ANTES de sumar por entidad. Los retiros con IBC parcial generan aportes no redondeados.
    ssBaseOperador    : {{ $planos->sum(fn($p) =>
        (int)(ceil(($p->v_eps  ??0)/100)*100) +
        (int)(ceil(($p->v_afp  ??0)/100)*100) +
        (int)(ceil(($p->v_arl  ??0)/100)*100) +
        (int)(ceil(($p->v_caja ??0)/100)*100)
    ) }},
    pendienteEPS      : {{ $planos->filter(fn($p) => empty($p->numero_planilla))->sum('v_eps') }},
    pendienteAFP      : {{ $planos->filter(fn($p) => empty($p->numero_planilla))->sum('v_afp') }},
    pendienteARL      : {{ $planos->filter(fn($p) => empty($p->numero_planilla))->sum('v_arl') }},
    pendienteCCF      : {{ $planos->filter(fn($p) => empty($p->numero_planilla))->sum('v_caja') }},
    tasaMora          : {{ \App\Models\ConfiguracionBrynex::obtener('tasa_mora_pila', 26.17) }},
    horaCorte         : '{{ \App\Services\MoraClienteService::HORA_CORTE }}',
    // Último cambio de los planos de la tanda. Si es posterior a la fecha en
    // que se liquidó, la planilla del operador ya no representa este archivo
    // y la diferencia contra los aportes no es mora — ver aplicarTotalDelOperador.
    planosUltimoCambio: '{{ substr((string) $planos->pluck('updated_at')->filter()->max(), 0, 16) }}',
    // Totales por entidad (para mora exacta igual al operador PILA)
    // PILA redondea cada aporte al siguiente múltiplo de 100 POR COTIZANTE antes de agrupar.
    // Así PORVENIR-AFP suma ceil(v_afp/100)*100 de cada persona individualmente.
    porEntidad        : {!! json_encode(
        $planos
               ->flatMap(fn($p) => [
                    // EPS: ceil(v_eps/100)*100 por cotizante
                    ($p->v_eps ?? 0) > 0
                        ? ['cod'    => (!empty($p->cod_eps) && trim((string)$p->cod_eps) !== '0' ? $p->cod_eps : '0'),
                           'tipo'   => 'EPS',
                           'nombre' => (!empty($p->cod_eps) && trim((string)$p->cod_eps) !== '0'
                                           ? ($p->nombre_eps ?? $p->cod_eps)
                                           : 'Sin EPS / NINGUNA'),
                           'valor'  => (float)((int)(ceil(($p->v_eps??0)/100)*100))]
                        : null,
                    // AFP: ceil(v_afp/100)*100 por cotizante
                    ($p->v_afp ?? 0) > 0
                        ? ['cod'    => (!empty($p->cod_afp) && trim((string)$p->cod_afp) !== '0' ? $p->cod_afp : '0'),
                           'tipo'   => 'AFP',
                           'nombre' => (!empty($p->cod_afp) && trim((string)$p->cod_afp) !== '0'
                                           ? ($p->nombre_afp ?? $p->cod_afp)
                                           : 'Sin AFP / NINGUNA'),
                           'valor'  => (float)((int)(ceil(($p->v_afp??0)/100)*100))]
                        : null,
                    // ARL: ceil(v_arl/100)*100 por cotizante
                    ($p->v_arl ?? 0) > 0
                        ? ['cod'    => (!empty($p->cod_arl) && trim((string)$p->cod_arl) !== '0' ? $p->cod_arl : '0'),
                           'tipo'   => 'ARL',
                           'nombre' => (!empty($p->cod_arl) && trim((string)$p->cod_arl) !== '0'
                                           ? ($p->nombre_arl ?? $p->cod_arl)
                                           : 'Sin ARL / NINGUNA'),
                           'valor'  => (float)((int)(ceil(($p->v_arl??0)/100)*100))]
                        : null,
                    // CCF: con caja propia (cod_caja presente)
                    // CCF68: sin caja propia (cod_caja vacío o '0', v_caja=$100 fijo)
                    ($p->v_caja ?? 0) > 0
                        ? ['cod'    => (!empty($p->cod_caja) && trim((string)$p->cod_caja) !== '0' ? $p->cod_caja : 'CCF68'),
                           'tipo'   => 'CCF',
                           'nombre' => (!empty($p->cod_caja) && trim((string)$p->cod_caja) !== '0'
                                           ? ($p->nombre_caja ?? $p->cod_caja)
                                           : 'Sin Compensación (CCF68)'),
                           'valor'  => (float)((int)(ceil(($p->v_caja??0)/100)*100))]
                        : null,
               ])
               ->filter()
               ->groupBy(fn($e) => $e['tipo'] . '|' . $e['cod'])
               ->map(fn($g) => [
                    'tipo'      => $g->first()['tipo'],
                    'cod'       => $g->first()['cod'],
                    'nombre'    => $g->first()['nombre'] ?? $g->first()['cod'],
                    'afiliados' => $g->count(),   // personas con esta entidad
                    'total'     => $g->sum('valor'),   // suma de valores ya redondeados a 100
               ])
               ->values()
    ) !!},
    esIndependiente: {{ $esIndependiente ? 'true' : 'false' }},
    planoPagado   : {{ $planoPagado ? 'true' : 'false' }},
    // Lo pendiente de la tanda son solo correcciones N (se pueden confirmar en $0).
    esCorreccionN : {{ ($pendientesTanda = $planos->filter(fn ($p) => trim((string) $p->numero_planilla) === ''))->isNotEmpty()
        && $pendientesTanda->every(fn ($p) => (int) $p->tipo_p === \App\Services\CorreccionNovedadesService::TIPO_P) ? 'true' : 'false' }},
    rsNit         : {{ $rsNit ?? 'null' }},
    rsDiaHabil    : {{ $rsDiaHabil ?? 'null' }},
    csrfToken     : '{{ csrf_token() }}',
    gastoPagoId   : {{ $gastoPagoId ?? 'null' }},
    routes: {
        descargar        : '{{ route('admin.planos.descargar') }}',
        descargarAsopagos  : '{{ route('admin.planos.descargar_asopagos') }}',
        descargarMiPlanilla: '{{ route('admin.planos.descargar_miplanilla') }}',
        descargarAportesEnLinea: '{{ route('admin.planos.descargar_aportes_en_linea') }}',
        descargarAportesEnLinea2: '{{ route('admin.planos.descargar_aportes_en_linea_2') }}',
        nPlanoUpdate : '{{ route('admin.planos.n_plano.update') }}',
        asignarOperador: '{{ route('admin.planos.operador_cliente.asignar') }}',
        confirmarPago: '{{ route('admin.planos.confirmar_pago') }}',
        corregirPago : '{{ route('admin.planos.corregir_pago') }}',
        apiRazon     : '/admin/planos/api/razon/',
        enlaceEstado   : '{{ route('admin.planos.api_operador.estado') }}',
        enlaceLiquidar : '{{ route('admin.planos.api_operador.liquidar') }}',
        enlaceLiquidarIndependiente: '{{ route('admin.planos.api_operador.liquidar_independiente') }}',
        enlaceAutocorregir: '{{ route('admin.planos.api_operador.autocorregir') }}',
    },
    // Tipos de modalidad presentes en los planos cargados actualmente
    // Array de objetos: { id: int, nombre: string }
    tiposEnPlanos: {!! json_encode(
        $planos->map(fn($p) => ['id' => (int)$p->tipo_modalidad_id, 'nombre' => $p->tipo_modal_nombre ?? ('Tipo '.$p->tipo_modalidad_id)])
               ->unique('id')
               ->values()
    ) !!},
    // Cuántos cotizan el mes en curso y cuántos el vencido: el archivo declara
    // un solo período, así que los dos grupos no pueden descargarse juntos.
    esquemasEnPlanos: {!! json_encode([
        'mes_actual' => $planos->filter(fn($p) => (bool) ($p->paga_mes_actual ?? false))->count(),
        'vencido'    => $planos->filter(fn($p) => ! ($p->paga_mes_actual ?? false))->count(),
    ]) !!},
};
window.CTX_TOTAL_PAGAR = CTX.totalSS;

// ── Cálculo de Mora PILA Colombia ────────────────────────────────────
// Decreto 1990/2016 | Art. 635 ET | Tasa usura Superfinanciera
(function calcularMora() {
    // Siempre actualizar Total SS con el valor PILA redondeado (ceil por cotizante)
    // independiente de si el plano está pagado o no
    const elSS = document.getElementById('total-ss-display');
    if (elSS && CTX.ssBaseOperador > 0) elSS.textContent = '$ ' + fmtNum(CTX.ssBaseOperador);

    if (CTX.planoPagado || !CTX.rsNit || CTX.totalSS <= 0) return;

    // 1) Tabla legal: últimos 2 dígitos NIT → día hábil de vencimiento
    const TABLA = [
        [0,  7,  2],  // 00-07 → 2.° día hábil
        [8,  14, 3],
        [15, 21, 4],
        [22, 28, 5],
        [29, 35, 6],
        [36, 42, 7],
        [43, 49, 8],
        [50, 56, 9],
        [57, 63, 10],
        [64, 69, 11],
        [70, 75, 12],
        [76, 81, 13],
        [82, 87, 14],
        [88, 93, 15],
        [94, 99, 16],
    ];

    // Día hábil: preferir el guardado en la RS (dia_habil), si no → calcular por tabla + NIT
    let diaHabil = CTX.rsDiaHabil || null;
    let ultDos   = null;

    if (!diaHabil) {
        // Usar los últimos 2 dígitos del NIT real (columna nit de razones_sociales)
        const nit = Math.abs(CTX.rsNit);
        ultDos    = nit % 100;
        for (const [desde, hasta, dia] of TABLA) {
            if (ultDos >= desde && ultDos <= hasta) { diaHabil = dia; break; }
        }
        if (!diaHabil) diaHabil = 2; // fallback
    }

    // 2) Calcular fecha de vencimiento en el mes de PAGO (mes del filtro UI)
    const mesPago  = CTX.mes;
    const anioPago = CTX.anio;
    const festivosCo = getFestivosColombia(anioPago);
    const fechaVence = getNthDiaHabil(anioPago, mesPago, diaHabil, festivosCo);

    if (!fechaVence) return;

    // 3) Días calendario de mora: la mora no se cuenta hasta hoy sino hasta el
    //    día en que el pago realmente se abona, que es lo que liquida el
    //    operador. Dos cosas lo mueven:
    //
    //    a) Si hoy no es día hábil, se abona el próximo que lo sea.
    //    b) Pasada la hora de corte bancaria ya no alcanza a abonarse hoy,
    //       así que se va al siguiente día hábil aunque hoy sí lo sea.
    //
    //    Faltaba (b), y por eso una planilla del viernes por la noche salía
    //    con "0 días" mientras Enlace cobraba 4: el pago caía el martes 18
    //    porque el sábado y domingo no cuentan y el lunes 17 es festivo.
    //    La hora de corte sale de MoraClienteService, que es quien calcula la
    //    mora en cobros y facturación: una sola definición para todo.
    const [corteH, corteM] = (CTX.horaCorte || '16:30').split(':').map(Number);

    const ahora = new Date();
    const hoy = new Date();
    hoy.setHours(0,0,0,0);

    const fechaPago = new Date(hoy);
    if (ahora.getHours() > corteH || (ahora.getHours() === corteH && ahora.getMinutes() >= corteM)) {
        fechaPago.setDate(fechaPago.getDate() + 1);
    }
    while (true) {
        const dow = fechaPago.getDay();
        const key = `${fechaPago.getFullYear()}-${String(fechaPago.getMonth()+1).padStart(2,'0')}-${String(fechaPago.getDate()).padStart(2,'0')}`;
        if (dow !== 0 && dow !== 6 && !festivosCo.has(key)) break;
        fechaPago.setDate(fechaPago.getDate() + 1);
    }
    // Se comparte: la nota del bloque explica los días de mora del operador
    // con esta misma fecha, que es hasta cuándo cuenta.
    window.CTX_FECHA_ABONO = new Date(fechaPago);

    const venceMs  = fechaVence.getTime();
    const pagoMs   = fechaPago.getTime();
    const diasMora = Math.max(0, Math.floor((pagoMs - venceMs) / 86400000));

    // ── Mostrar sección mora en el bloque unificado ───────────────────
    function mostrarNodo(id) { const el = document.getElementById(id); if (el) el.hidden = false; }
    function mostrarEl(id)   { const el = document.getElementById(id); if (el) el.style.display = ''; }

    // Siempre mostrar al menos la fecha de vencimiento
    mostrarNodo('mora-sep1');
    mostrarNodo('mora-item-vence');
    document.getElementById('mora-fecha-vence').textContent =
        fechaVence.toLocaleDateString('es-CO', {day:'2-digit',month:'short',year:'numeric'});

    const infoSufijo = CTX.rsDiaHabil
        ? `Día hábil ${diaHabil} (configurado en RS)`
        : `Día hábil ${diaHabil} · NIT termina en ${String(ultDos ?? '??').padStart(2,'0')}`;

    if (diasMora <= 0) {
        // Sin mora: bloque verde — mostrar todos los campos con valores 0
        document.getElementById('mora-bloque').style.background = 'linear-gradient(135deg,#f0fdf4,#dcfce7)';
        document.getElementById('mora-bloque').style.borderColor = '#86efac';
        document.getElementById('mora-fecha-vence').className = 'mv verde';

        mostrarNodo('mora-sep2'); mostrarNodo('mora-item-dias');
        mostrarNodo('mora-sep3'); mostrarNodo('mora-item-valor');
        mostrarNodo('mora-sep4'); mostrarNodo('mora-item-total');

        document.getElementById('mora-dias').textContent  = '0 días';
        document.getElementById('mora-dias').className    = 'mv verde';
        document.getElementById('mora-valor').textContent = '$ 0';
        document.getElementById('mora-valor').className   = 'mv verde';
        document.getElementById('mora-total').textContent = '$ ' + fmtNum(CTX.totalSS);
        window.CTX_TOTAL_PAGAR = CTX.totalSS;

        mostrarEl('mora-info-txt');
        document.getElementById('mora-info-txt').textContent =
            `${infoSufijo} · Sin mora si paga hasta ${fechaVence.toLocaleDateString('es-CO',{weekday:'long',day:'2-digit',month:'long'})}`
            + ` antes de las 4:30 p.m.`;

        // Tabla desglose sin mora
        renderDesglose(0, {});
        return;
    }

    // Con mora: fondo ámbar
    document.getElementById('mora-bloque').style.background = 'linear-gradient(135deg,#fff7ed,#fef3c7)';
    document.getElementById('mora-bloque').style.borderColor = '#fde68a';

    // 4) Mora POR ENTIDAD (igual al operador PILA): ceil(valor × factor / 100) × 100 por cada administradora
    // El operador NO agrupa EPS/AFP/ARL/CCF juntos — cada administradora (ej: Porvenir, Colpensiones)
    // recibe su propio ceil. Esto replica exactamente la tabla del operador.
    const tasaAnual = CTX.tasaMora / 100;
    const bisiesto  = (anioPago % 4 === 0 && (anioPago % 100 !== 0 || anioPago % 400 === 0));
    const diasAnio  = bisiesto ? 366 : 365;
    const factor    = tasaAnual / diasAnio * diasMora;
    const ceil100   = v => Math.ceil(v * factor / 100) * 100;

    let mora = 0;
    const detalleMora = {};
    for (const ent of CTX.porEntidad) {
        const m = ceil100(ent.total);
        mora += m;
        detalleMora[ent.tipo] = (detalleMora[ent.tipo] || 0) + m;
    }
    // Fallback: si porEntidad vacío (plano ya pagado / sin datos), usar cálculo por subsistema
    if (!CTX.porEntidad.length) {
        mora = ceil100(CTX.pendienteEPS) + ceil100(CTX.pendienteAFP)
             + ceil100(CTX.pendienteARL) + ceil100(CTX.pendienteCCF);
        detalleMora['EPS'] = ceil100(CTX.pendienteEPS);
        detalleMora['AFP'] = ceil100(CTX.pendienteAFP);
        detalleMora['ARL'] = ceil100(CTX.pendienteARL);
        detalleMora['CCF'] = ceil100(CTX.pendienteCCF);
    }
    // SS base: igual al operador = suma aportes individuales de TODOS los planos
    // (NO solo pendientes — el operador incluye todos los 31 afiliados)
    const ssBase = CTX.ssBaseOperador;
    const total  = ssBase + mora;

    // Guardar total final para el modal de pago (SS + mora)
    window.CTX_TOTAL_PAGAR = total;

    mostrarNodo('mora-sep2'); mostrarNodo('mora-item-dias');
    mostrarNodo('mora-sep3'); mostrarNodo('mora-item-valor');
    mostrarNodo('mora-sep4'); mostrarNodo('mora-item-total');

    document.getElementById('mora-dias').textContent  = diasMora + ' días';
    document.getElementById('mora-valor').textContent = '$ ' + fmtNum(mora);
    document.getElementById('mora-total').textContent = '$ ' + fmtNum(total);

    mostrarEl('mora-info-txt');
    const detalleStr = Object.entries(detalleMora).map(([k,v]) => `${k} $${fmtNum(v)}`).join(' + ');
    document.getElementById('mora-info-txt').textContent =
        `${infoSufijo} · Tasa: ${CTX.tasaMora}% E.A. · ${detalleStr}`;

    // Tabla desglose por entidad con mora
    renderDesglose(factor, detalleMora);
})();

function toggleDesgloseEntidades() {
    const wrap = document.getElementById('desglose-entidades-wrap');
    const text = document.getElementById('btn-toggle-desglose-text');
    if (!wrap) return;

    if (desgloseVisible) {
        wrap.style.display = 'none';
        if (text) text.textContent = 'Mostrar Desglose por Administradora';
        desgloseVisible = false;
    } else {
        wrap.style.display = 'block';
        if (text) text.textContent = 'Ocultar Desglose por Administradora';
        desgloseVisible = true;
    }
}

/**
 * renderDesglose(factor, detalleMoraEnt)
 * Rellena la tabla #tabla-desglose-entidades con los mismos datos que el operador PILA:
 * NIT | Tipo | Administradora | Afiliados | Valor sin mora | Mora | Total
 *
 * @param {number} factor          - factor de mora (tasaAnual/diasAnio*diasMora). 0 = sin mora.
 * @param {object} detalleMoraEnt  - IGNORADO (se recalcula internamente por entidad para precisión).
 */
function renderDesglose(factor, detalleMoraEnt) {
    const wrap  = document.getElementById('desglose-entidades-wrap');
    const tbody = document.getElementById('tbody-desglose-entidades');
    const tfoot = document.getElementById('tfoot-desglose-entidades');
    if (!wrap || !tbody) return;
    if (!CTX.porEntidad || !CTX.porEntidad.length) { wrap.style.display = 'none'; return; }

    // Colores por tipo
    const tipoColor = { EPS:'#0369a1', AFP:'#7c3aed', ARL:'#b45309', CCF:'#0f766e' };
    const tipoBg    = { EPS:'#e0f2fe', AFP:'#ede9fe', ARL:'#fef3c7', CCF:'#ccfbf1' };

    const ceil100 = v => Math.ceil(v * factor / 100) * 100;

    let rows = '';
    let sumSinMora = 0, sumMora = 0, sumAfil = 0;
    // Ordenar: EPS, AFP, ARL, CCF
    const orden = ['EPS','AFP','ARL','CCF'];
    const entidades = [...CTX.porEntidad].sort((a,b) => orden.indexOf(a.tipo) - orden.indexOf(b.tipo));

    for (const ent of entidades) {
        const sinMora = ent.total;
        const mEnt    = ceil100(sinMora);
        const tot     = sinMora + mEnt;
        sumSinMora += sinMora;
        sumMora    += mEnt;
        sumAfil    += ent.afiliados || 0;

        const color = tipoColor[ent.tipo] || '#475569';
        const bg    = tipoBg[ent.tipo]   || '#f8fafc';
        const esOk  = mEnt === 0;
        rows += `<tr style="border-bottom:1px solid #f1f5f9">
            <td style="padding:.4rem .6rem;font-family:monospace;font-size:.7rem;color:#64748b">${ent.cod}</td>
            <td style="padding:.4rem .6rem">
                <span style="display:inline-block;padding:.1rem .4rem;border-radius:4px;font-size:.65rem;font-weight:700;color:${color};background:${bg}">${ent.tipo}</span>
            </td>
            <td style="padding:.4rem .6rem;font-weight:600;color:#0f172a">${ent.nombre || ent.cod}</td>
            <td style="padding:.4rem .6rem;text-align:right;color:#475569">${ent.afiliados || 0}</td>
            <td style="padding:.4rem .6rem;text-align:right;color:#1e40af;font-weight:600">$${fmtNum(sinMora)}</td>
            <td style="padding:.4rem .6rem;text-align:right;color:${esOk?'#059669':'#dc2626'};font-weight:${esOk?'500':'700'}">${esOk?'$0':'$'+fmtNum(mEnt)}</td>
            <td style="padding:.4rem .6rem;text-align:right;color:#7c3aed;font-weight:700">$${fmtNum(tot)}</td>
        </tr>`;
    }

    tbody.innerHTML = rows;

    // Pie de tabla
    if (tfoot) {
        tfoot.style.display = '';
        const pAfil    = document.getElementById('pie-afiliados');
        const pSinMora = document.getElementById('pie-sin-mora');
        const pMora    = document.getElementById('pie-mora');
        const pTotal   = document.getElementById('pie-total');
        if (pAfil)    pAfil.textContent    = sumAfil;
        if (pSinMora) pSinMora.textContent = '$' + fmtNum(sumSinMora);
        if (pMora)    pMora.textContent    = sumMora > 0 ? '$' + fmtNum(sumMora) : '$0';
        if (pTotal)   pTotal.textContent   = '$' + fmtNum(sumSinMora + sumMora);
    }

    if (desgloseVisible) {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
}

function getNthDiaHabil(anio, mes, n, festivos) {
    const fecha = new Date(anio, mes - 1, 1);
    let cont = 0;
    while (true) {
        const dow = fecha.getDay(); // 0=dom,6=sáb
        const key = `${fecha.getFullYear()}-${String(fecha.getMonth()+1).padStart(2,'0')}-${String(fecha.getDate()).padStart(2,'0')}`;
        if (dow !== 0 && dow !== 6 && !festivos.has(key)) {
            cont++;
            if (cont === n) return new Date(fecha);
        }
        fecha.setDate(fecha.getDate() + 1);
        if (fecha.getMonth() !== mes - 1) break; // pasó el mes
    }
    return null;
}

// Festivos Colombia fijos + móviles dinámicos (Ley Emiliani & Pascua)
function getFestivosColombia(anio) {
    const list = [];
    
    // Fijos (Fixed)
    const fijos = [
        new Date(anio, 0, 1),   // Año Nuevo
        new Date(anio, 4, 1),   // Día del Trabajo
        new Date(anio, 6, 20),  // Grito de Independencia
        new Date(anio, 7, 7),   // Batalla de Boyacá
        new Date(anio, 11, 8),  // Inmaculada Concepción
        new Date(anio, 11, 25), // Navidad
    ];
    
    // Ley Emiliani (Moved to next Monday)
    const emiliani = [
        new Date(anio, 0, 6),   // Reyes Magos
        new Date(anio, 2, 19),  // San José
        new Date(anio, 5, 29),  // San Pedro y San Pablo
        new Date(anio, 7, 15),  // Asunción de la Virgen
        new Date(anio, 9, 12),  // Día de la Raza
        new Date(anio, 10, 1),  // Todos los Santos
        new Date(anio, 10, 11), // Independencia de Cartagena
    ];

    // Ley 2578 del 1-jun-2026: el 9 de julio (Virgen de Chiquinquirá) es festivo
    // nacional, tambien sujeto a la Ley Emiliani. No es retroactivo — mismo
    // criterio que MoraClienteService::festivosColombia().
    if (anio >= 2026) {
        emiliani.push(new Date(anio, 6, 9));
    }
    
    // Algoritmo Meeus/Jones/Butcher para Domingo de Pascua (Easter Sunday)
    const a = anio % 19;
    const b = Math.floor(anio / 100);
    const c = anio % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    const pascua = new Date(anio, month - 1, day);
    
    // Jueves Santo: pascua - 3
    const juevesSanto = new Date(pascua);
    juevesSanto.setDate(pascua.getDate() - 3);
    
    // Viernes Santo: pascua - 2
    const viernesSanto = new Date(pascua);
    viernesSanto.setDate(pascua.getDate() - 2);
    
    // Ascensión del Señor: pascua + 43
    const ascension = new Date(pascua);
    ascension.setDate(pascua.getDate() + 43);
    
    // Corpus Christi: pascua + 64
    const corpus = new Date(pascua);
    corpus.setDate(pascua.getDate() + 64);
    
    // Sagrado Corazón de Jesús: pascua + 71
    const sagradoCorazon = new Date(pascua);
    sagradoCorazon.setDate(pascua.getDate() + 71);
    
    const moveToMonday = (dt) => {
        const dow = dt.getDay(); // 0=Sun, 1=Mon, ...
        if (dow === 1) return dt;
        const diff = (8 - dow) % 7;
        const res = new Date(dt);
        res.setDate(dt.getDate() + (diff === 0 ? 7 : diff));
        return res;
    };
    
    const formatDate = (dt) => {
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };
    
    fijos.forEach(dt => list.push(formatDate(dt)));
    emiliani.forEach(dt => list.push(formatDate(moveToMonday(dt))));
    list.push(formatDate(juevesSanto));
    list.push(formatDate(viernesSanto));
    list.push(formatDate(ascension));
    list.push(formatDate(corpus));
    list.push(formatDate(sagradoCorazon));
    
    return new Set(list);
}

function fmtNum(n) {
    return Math.round(n).toLocaleString('es-CO');
}
let _planoIdActual    = null;
let _operadorClienteId = null;

// ── Multiselect tipos modalidad ──────────────────────────────────────
function toggleMs() {
    document.getElementById('ms-wrap').classList.toggle('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#ms-wrap'))  document.getElementById('ms-wrap')?.classList.remove('open');
    if (!e.target.closest('#rs-wrap'))  document.getElementById('rs-wrap')?.classList.remove('open');
});
function updateMsLabel() {
    const checked = document.querySelectorAll('#ms-dropdown input[type=checkbox]:checked');
    document.getElementById('ms-label').textContent =
        checked.length ? checked.length + ' sel.' : 'Todos';
}
function toggleAllMs() {
    const boxes = document.querySelectorAll('#ms-dropdown input[type=checkbox]');
    const allChecked = [...boxes].every(b => b.checked);
    boxes.forEach(b => b.checked = !allChecked);
    updateMsLabel();
}

// ── RS Custom Dropdown ────────────────────────────────────────────────
function toggleRs() {
    const w = document.getElementById('rs-wrap');
    w.classList.toggle('open');
    if (w.classList.contains('open')) setTimeout(() => document.getElementById('rs-search')?.focus(), 40);
}
function selRs(val, nplano, label) {
    document.getElementById('sel-rs-val').value = val;
    document.getElementById('rs-btn-txt').textContent = label || '— Todas —';
    document.getElementById('rs-wrap').classList.remove('open');
    document.querySelectorAll('#rs-list .rs-row').forEach(r => r.classList.remove('sel'));
    const hit = [...document.querySelectorAll('#rs-list .rs-row')].find(r => r.getAttribute('onclick')?.includes("'" + val + "'"));
    if (hit) hit.classList.add('sel'); else document.querySelector('#rs-list .rs-row')?.classList.add('sel');
    // Al seleccionar una RS, preseleccionar su n_plano en el select de plano.
    // Si se elige "— Todas —" (val vacío), dejar el selector en "Todos" (vacío).
    const selNplano = document.getElementById('sel-nplano');
    if (selNplano) {
        if (val && nplano) {
            let nStr = String(nplano);
            let existe = false;
            for (let i = 0; i < selNplano.options.length; i++) {
                if (selNplano.options[i].value === nStr) {
                    existe = true;
                    break;
                }
            }
            if (!existe && nStr !== '100' && nStr !== '0') {
                let opt = document.createElement('option');
                opt.value = nStr;
                opt.textContent = 'P' + nStr + ' ⭐';
                selNplano.insertBefore(opt, selNplano.options[selNplano.options.length - 1]);
            }
            selNplano.value = nStr;
        } else {
            selNplano.value = '';
        }
    }
    autoSubmit();
}

function checkOtroPlano(el) {
    if (el.value === 'otro') {
        let val = prompt("Ingrese el número de plano deseado (ej. 30, 45, etc.):");
        if (val) {
            val = val.trim();
            let num = parseInt(val, 10);
            if (!isNaN(num) && num > 0) {
                let existe = false;
                for (let i = 0; i < el.options.length; i++) {
                    if (el.options[i].value == String(num)) {
                        el.value = String(num);
                        existe = true;
                        break;
                    }
                }
                if (!existe) {
                    let opt = document.createElement('option');
                    opt.value = String(num);
                    opt.textContent = 'P' + num;
                    el.insertBefore(opt, el.options[el.options.length - 1]);
                    el.value = String(num);
                }
                autoSubmit();
            } else {
                mostrarToast('El número de plano debe ser un entero positivo.', 'error');
                el.value = '';
            }
        } else {
            el.value = '';
        }
    } else {
        autoSubmit();
    }
}
function filtrarRs(q) {
    q = q.trim().toLowerCase();
    let grp = null, grpVis = false;
    document.querySelectorAll('#rs-list .rs-row, #rs-list .rs-glabel').forEach(el => {
        if (el.classList.contains('rs-glabel')) {
            if (grp) grp.style.display = grpVis ? '' : 'none';
            grp = el; grpVis = false;
        } else {
            const show = !q || (el.dataset.lbl || '').includes(q);
            el.style.display = show ? '' : 'none';
            if (show) grpVis = true;
        }
    });
    if (grp) grp.style.display = grpVis ? '' : 'none';
}

// Auto-submit en cambio de cualquier filtro
function autoSubmit() {
    document.getElementById('frm-filtros').submit();
}

// ══════════════════════════════════════════════════════════════════════
// VALIDACIÓN DE COMPATIBILIDAD DE MODALIDADES
// ══════════════════════════════════════════════════════════════════════

/**
 * Grupos de compatibilidad:
 * A: [0,1,2,3,4,12]  → Dependientes + Tiempo Parcial estándar (van juntos)
 * B: [-6,-7,-8]       → Tiempo Parcial combinado (van juntos entre sí)
 * C: [-1]             → Estudiante K (solo)
 * D: [8]              → ARL Planilla Y (solo)
 * E: [13]             → Tipo 13 (solo)
 * F: [10,11,14]       → Independientes (se pagan individual)
 */
const GRUPOS_COMPATIBILIDAD = [
    { id: 'A', nombre: 'Dependientes + TP estándar',    tipos: [0, 1, 2, 3, 4, 12],  modo: 'grupo' },
    { id: 'B', nombre: 'Tiempo Parcial combinado',       tipos: [-6, -7, -8],          modo: 'grupo' },
    { id: 'C', nombre: 'Estudiante K',                  tipos: [-1],                  modo: 'solo'  },
    { id: 'D', nombre: 'ARL Planilla Y',                tipos: [8],                   modo: 'solo'  },
    { id: 'E', nombre: 'Tipo 13',                       tipos: [13],                  modo: 'solo'  },
    { id: 'F', nombre: 'Independientes (pago individual)', tipos: [10, 11, 14],       modo: 'individual' },
];

/**
 * Dado un tipo_modalidad_id, devuelve el grupo al que pertenece.
 * Retorna null si no está mapeado (tipo desconocido, se permite pasar).
 */
function obtenerGrupo(tipoId) {
    for (const g of GRUPOS_COMPATIBILIDAD) {
        if (g.tipos.includes(tipoId)) return g;
    }
    return null;
}

/**
 * Valida que los tipos de modalidad en los planos actuales
 * sean todos del mismo grupo de compatibilidad.
 *
 * Retorna: { ok: true } si compatible
 *          { ok: false, tiposIncompat: [{id, nombre, grupo}], gruposDistintos: [grupoid] } si no
 */
function validarCompatibilidadModalidades() {
    const tipos = CTX.tiposEnPlanos; // [{id, nombre}, ...]
    if (!tipos || tipos.length === 0) return { ok: true };

    const gruposEncontrados = new Map(); // grupoId → grupo
    const tiposConGrupo = [];

    for (const t of tipos) {
        const g = obtenerGrupo(t.id);
        if (g) {
            gruposEncontrados.set(g.id, g);
            tiposConGrupo.push({ ...t, grupo: g });
        }
        // tipos sin grupo definido: no se bloquean
    }

    if (gruposEncontrados.size <= 1) return { ok: true }; // todos del mismo grupo o ninguno

    return {
        ok: false,
        tiposConGrupo,
        gruposDistintos: [...gruposEncontrados.values()],
    };
}

/**
 * Punto de entrada: valida compatibilidad y, si hay problema, muestra el
 * modal de error. Si no hay problema, abre el modal solicitado.
 * @param {'descarga'|'pago'} accion
 */
function validarCompatibilidadYAbrir(accion) {
    // Mes actual y mes vencido conviven en la misma tanda de pago pero cotizan
    // meses distintos, y el archivo declara un solo período: van por separado.
    const esq = CTX.esquemasEnPlanos || {};
    if ((esq.mes_actual || 0) > 0 && (esq.vencido || 0) > 0) {
        mostrarAvisoEsquemasMezclados(esq);
        return;
    }

    const result = validarCompatibilidadModalidades();

    if (!result.ok) {
        // Rellenar el panel de tipos detectados
        const contenedor = document.getElementById('incompat-tipos-detectados');
        if (contenedor) {
            const coloresGrupo = { A:'#dcfce7|#15803d', B:'#e0f2fe|#0369a1', C:'#fef9c3|#92400e', D:'#ffe4e6|#be123c', E:'#f3e8ff|#7e22ce', F:'#dbeafe|#1d4ed8' };
            contenedor.innerHTML = result.tiposConGrupo.map(t => {
                const [bg, fg] = (coloresGrupo[t.grupo.id] || '#f1f5f9|#475569').split('|');
                const modoLabel = t.grupo.modo === 'solo' ? '🔒 Solo' : (t.grupo.modo === 'individual' ? '💳 Individual' : '✅ Grupo ' + t.grupo.id);
                return `<span style="display:inline-flex;align-items:center;gap:.3rem;background:${bg};color:${fg};border:1px solid ${fg}40;border-radius:20px;padding:.2rem .7rem;font-size:.75rem;font-weight:600">${modoLabel}: <code style="font-weight:700">${t.nombre}</code></span>`;
            }).join('');
        }
        document.getElementById('modal-incompatibilidad').classList.add('open');
        return;
    }

    // Compatible: abrir el modal correspondiente
    if (accion === 'descarga') {
        abrirModalDescarga();
    } else {
        abrirModalPago();
    }
}

/**
 * Aviso cuando la selección junta los dos esquemas de período. No es un
 * problema de modalidades —pueden ser todos independientes— sino de qué mes
 * cotiza cada uno: el registro tipo 1 del archivo lleva un único período.
 */
function mostrarAvisoEsquemasMezclados(esq) {
    const titulo = document.querySelector('#modal-incompatibilidad .modal-head h3');
    if (titulo) titulo.innerHTML = '⚠️ Mes actual y mes vencido en el mismo filtro';

    const cuerpo = document.getElementById('incompat-tipos-detectados');
    if (cuerpo) {
        cuerpo.innerHTML =
            `<span style="display:inline-flex;align-items:center;gap:.3rem;background:#dbeafe;color:#1d4ed8;border:1px solid #1d4ed840;border-radius:20px;padding:.2rem .7rem;font-size:.75rem;font-weight:600">📅 Mes actual: <code style="font-weight:700">${esq.mes_actual}</code></span>`
          + `<span style="display:inline-flex;align-items:center;gap:.3rem;background:#f1f5f9;color:#475569;border:1px solid #47556940;border-radius:20px;padding:.2rem .7rem;font-size:.75rem;font-weight:600">🗓️ Mes vencido: <code style="font-weight:700">${esq.vencido}</code></span>`;
    }

    const texto = document.querySelector('#modal-incompatibilidad .modal-body > div:first-child');
    if (texto) {
        texto.innerHTML =
            '<p style="font-size:.82rem;color:#991b1b;font-weight:600;margin-bottom:.4rem">Esta selección junta gente que cotiza <strong>el mes en curso</strong> con gente que cotiza <strong>el mes vencido</strong>, y cada grupo cotiza un mes distinto.</p>'
          + '<p style="font-size:.78rem;color:#b91c1c">El archivo declara un solo período de cotización, así que la mitad quedaría reportada en el mes que no es. Descárguelos por separado usando el filtro de modalidad.</p>';
    }

    document.getElementById('modal-incompatibilidad').classList.add('open');
}

// ── Modales ───────────────────────────────────────────────────────────
function abrirModalDescarga() {
    // ── Inyectar "Valor Plano" ────────────────────────────────────────
    const chip = document.getElementById('modal-descarga-valor-plano');
    if (chip) {
        const pagado    = parseInt(chip.dataset.pagado || '0', 10);
        const ssBase    = parseInt(chip.dataset.ss     || '0', 10);
        const ctxTotal  = (typeof window.CTX_TOTAL_PAGAR === 'number' && window.CTX_TOTAL_PAGAR > 0)
                          ? window.CTX_TOTAL_PAGAR : 0;

        let total;
        if (CTX.planoPagado && pagado > 0) {
            // Plano pagado: mostrar lo que realmente se pagó (SS + mora del gasto)
            total = pagado;
        } else if (ctxTotal > 0) {
            // No pagado: SS + mora calculada en vivo
            total = ctxTotal;
        } else {
            // Fallback: solo SS
            total = ssBase;
        }
        chip.textContent = '$ ' + fmtNum(total);
    }
    document.getElementById('modal-descarga').classList.add('open');
    cargarEstadoEnlace();
}
function resetModalPago() {
    // Vuelve a dejar editables los campos que fija el pago 2 de 2.
    ['pago-operador', 'pago-numero', 'pago-valor'].forEach(id => {
        const el = document.getElementById(id);
        el.disabled = false; el.readOnly = false; el.style.background = '';
    });
    document.getElementById('modal-pago-dos-pasos').style.display = 'none';
    document.getElementById('pago-numero').value   = '';
    document.getElementById('pago-obs').value      = '';
    document.getElementById('pago-banco').value    = '';
    document.getElementById('pago-operador').value = '';
    document.getElementById('pago-resultado').style.display = 'none';
    limpiarSoporte();
}
function abrirModalPago() {
    _planoIdActual    = null;
    _operadorClienteId = null;
    document.getElementById('modal-pago-titulo').textContent = '✅ Confirmar Pago de Planilla al Operador';
    document.getElementById('modal-pago-aviso').style.display = '';
    document.getElementById('pago-valor').value = window.CTX_TOTAL_PAGAR || CTX.ssBaseOperador;
    resetModalPago();

    // Dos pasos con el paso 1 ya pagado: lo único que queda por confirmar es
    // la corrección, en el mismo operador. Se fija todo para que no se
    // confirme otra vez la planilla 1 ni un número equivocado.
    const dp = window._pagoDosPasos;
    if (dp) {
        if (!dp.numero) {
            mostrarToast(`La planilla ${dp.paso1} (paso 1) ya está pagada. Falta liquidar la corrección en ${dp.operador} `
                + 'desde «Descargar Plano» antes de confirmar el segundo pago.', 'error');
            return;
        }
        const op = document.getElementById('pago-operador');
        op.value = dp.operador;
        document.getElementById('pago-numero').value = dp.numero;
        // Si el robot no alcanzó a leer el total, el valor se escribe a mano.
        const conValor = Math.round(dp.valor || 0) > 0;
        document.getElementById('pago-valor').value  = conValor ? Math.round(dp.valor) : '';
        ['pago-operador', 'pago-numero'].concat(conValor ? ['pago-valor'] : []).forEach(id => {
            const el = document.getElementById(id);
            if (el.tagName === 'SELECT') el.disabled = true; else el.readOnly = true;
            el.style.background = '#f1f5f9';
        });
        const nota = document.getElementById('modal-pago-dos-pasos');
        nota.innerHTML = `🟡 <strong>Pago 2 de 2.</strong> La planilla del paso 1 (${dp.paso1}) ya está pagada. `
            + `Aquí se confirma la corrección <strong>${dp.numero}</strong> en ${dp.operador}; con ella el plano queda pagado.`
            + (conValor ? '' : '<br>Escriba el valor que cobró el operador por la corrección.');
        nota.style.display = '';
    }

    const btn = document.getElementById('btn-confirmar-pago');
    btn.disabled = false; btn.textContent = '✅ CONFIRMAR PAGO PLANILLA';
    document.getElementById('modal-pago').classList.add('open');
}
function abrirModalPagoIndividual(planoId, totalSS, clienteNombre, operadorId) {
    _planoIdActual    = planoId;
    _operadorClienteId = operadorId;
    document.getElementById('modal-pago-titulo').textContent = '💳 Pagar: ' + clienteNombre;
    document.getElementById('modal-pago-aviso').style.display = 'none';
    document.getElementById('pago-valor').value = totalSS;
    resetModalPago();
    const btn = document.getElementById('btn-confirmar-pago');
    btn.disabled = false; btn.textContent = '✅ CONFIRMAR PAGO';
    // Pre-seleccionar operador del cliente
    const sel = document.getElementById('pago-operador');
    if (operadorId) {
        let found = false;
        for (let opt of sel.options) {
            if (parseInt(opt.dataset.opId) === operadorId) { opt.selected = true; found = true; break; }
        }
        if (!found) sel.value = '';
    } else {
        sel.value = '';
    }
    document.getElementById('modal-pago').classList.add('open');
}
function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
}
// Cerrar con ESC
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});

// ── Descargar archivo ─────────────────────────────────────────────────
function ejecutarDescarga(formato) {
    const params = new URLSearchParams({
        formato,
        razon_social_id: CTX.razonSocialId ?? '',
        mes   : CTX.mes,
        anio  : CTX.anio,
        n_plano: CTX.nPlanoFiltro ?? '',
    });
    // Agregar tipos_modalidad[] como params repetidos (URLSearchParams no soporta arrays nativamente)
    CTX.modalidadesIds.forEach(id => params.append('tipos_modalidad[]', id));
    window.location.href = CTX.routes.descargar + '?' + params.toString();
}

// ── Descargar formato Asopagos ─────────────────────────────────────────
function ejecutarDescargaAsopagos() {
    if (!CTX.razonSocialId) {
        mostrarToast('Seleccione una Razón Social primero.', 'error');
        return;
    }
    const params = new URLSearchParams({
        razon_social_id: CTX.razonSocialId,
        mes    : CTX.mes,
        anio   : CTX.anio,
        n_plano: CTX.nPlanoFiltro ?? '',
    });
    CTX.modalidadesIds.forEach(id => params.append('tipos_modalidad[]', id));
    window.location.href = CTX.routes.descargarAsopagos + '?' + params.toString();
}

function ejecutarDescargaMiPlanilla() {
    if (!CTX.razonSocialId) {
        mostrarToast('Seleccione una Razón Social primero.', 'error');
        return;
    }
    const params = new URLSearchParams({
        razon_social_id: CTX.razonSocialId,
        mes    : CTX.mes,
        anio   : CTX.anio,
        n_plano: CTX.nPlanoFiltro ?? '',
    });
    CTX.modalidadesIds.forEach(id => params.append('tipos_modalidad[]', id));
    window.location.href = CTX.routes.descargarMiPlanilla + '?' + params.toString();
}

// ── Liquidación directa por API (Enlace Operativo) ──────────────────
// Reemplaza el paso manual de bajar el TXT y subirlo al portal del operador.

function paramsEnlace() {
    return {
        razon_social_id: CTX.razonSocialId,
        mes            : CTX.mes,
        anio           : CTX.anio,
        n_plano        : CTX.nPlanoFiltro || 1,
    };
}

// Revela el bloque de liquidación y pinta un botón por operador configurado.
async function cargarEstadoEnlace() {
    const bloque   = document.getElementById('bloque-enlace-api');
    const detalles = document.getElementById('detalle-descargas');
    if (!bloque || !CTX.razonSocialId) return;

    bloque.style.display = 'none';
    document.getElementById('enlace-ultima').style.display = 'none';
    if (detalles) detalles.open = true; // por defecto abiertas hasta confirmar que hay liquidación en línea

    try {
        // El filtro de modalidades va también aquí: la planilla de los K y la
        // de los E son distintas aunque compartan tanda, y sin esto el bloque
        // mostraba el número y el valor de la que no era.
        const qs = new URLSearchParams(paramsEnlace());
        CTX.modalidadesIds.forEach(id => qs.append('tipos_modalidad[]', id));

        const resp = await fetch(CTX.routes.enlaceEstado + '?' + qs.toString(), {
            headers: { 'Accept': 'application/json' }
        });
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.disponible || !(data.operadores || []).length) return;

        bloque.style.display = '';
        if (detalles) detalles.open = false; // liquidación en línea disponible: colapsar descargas manuales

        const cont = document.getElementById('enlace-botones');
        cont.innerHTML = '';

        // Tanda que mezcla las modalidades extraordinarias (Tipo E - Caja /
        // Pensión) con las normales: no se puede liquidar así, porque en el
        // paso 1 esas van sin salud y su corrección se busca después por el
        // mismo filtro de modalidades. Se avisa aquí y el backend además lo
        // rechaza. Ver PlanillaDosPasosService::tandaMezclada.
        if (data.mezcla_extraordinaria) {
            cont.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e',
                '⚠️ Esta tanda mezcla modalidades <strong>extraordinarias</strong> (Tipo E - Caja / Pensión) '
                + 'con las normales, y esas van en su propio archivo. Marque arriba, en el filtro de '
                + 'modalidades, solo las extraordinarias y liquídelas aparte; después las demás.');
            return;
        }

        // Dos pasos: pagado el paso 1, la tanda queda atada a ese operador.
        // La corrección solo existe sobre esa planilla, y liquidar el paso 1
        // otra vez —allá o en otro— sería pagar dos veces el mismo mes.
        const opPagado = data.operadores.find(o => o.e1 && o.e1.pago_confirmado);
        const corr = opPagado && opPagado.e1.paso2 && opPagado.e1.paso2.estado === 'validada' ? opPagado.e1.paso2 : null;
        window._pagoDosPasos = (opPagado && !CTX.planoPagado && !(corr && corr.pago_confirmado)) ? {
            operador: opPagado.nombre,
            paso1   : opPagado.e1.paso1 ? opPagado.e1.paso1.numero_planilla : '',
            numero  : corr ? corr.numero_planilla : null,
            valor   : corr ? corr.valor_total : null,
        } : null;

        // Tanda de correcciones de novedades (retiros del traslado de razón
        // social): planilla N sobre una planilla ya pagada, y solo en el
        // operador donde se pagó. Ver CorreccionNovedadesService.
        const corrN = data.correccion;
        if (corrN) {
            cont.insertAdjacentHTML('beforeend', corrN.error
                ? avisoEnlace('#fef2f2', '#fecaca', '#991b1b', '⚠️ <strong>Planilla N</strong> · ' + corrN.error)
                : avisoEnlace('#eef2ff', '#c7d2fe', '#3730a3',
                    `🧾 <strong>Planilla N (corrección)</strong> · ${corrN.cantidad} novedad(es) sobre la planilla `
                    + `<strong>${corrN.numero}</strong>, pagada el ${corrN.fecha_pago}`
                    + (corrN.operador ? ` en <strong>${corrN.operador}</strong>` : '') + '. '
                    + 'Solo va esta gente; quienes ya pagaron esa planilla no se tocan.'));
        }

        data.operadores.forEach(op => {
            // Motivos por los que no se puede liquidar con ese operador.
            let bloqueo = null;
            if (CTX.planoPagado)   bloqueo = 'Este plano ya fue confirmado como pagado.';
            else if (corrN && corrN.error) bloqueo = corrN.error;
            else if (corrN && corrN.operador_planilla_id && op.id !== corrN.operador_planilla_id)
                bloqueo = `La planilla ${corrN.numero} se pagó en ${corrN.operador}: su corrección solo se puede liquidar allá.`;
            else if (opPagado && op.id !== opPagado.id)
                bloqueo = `El paso 1 se pagó en ${opPagado.nombre}: la corrección solo se puede hacer allá.`;
            else if (op.clave_vencida) bloqueo = `La clave secreta de ${op.nombre} venció. Genere una nueva desde el tablero del operador.`;
            else if (op.sin_codigo_ni)  bloqueo = `Falta el código PILA de ${op.nombre}. Configúrelo en Configuración → Operadores de planilla.`;

            const faltaPaso2 = !!(op.e1 && !(op.e1.paso2 && op.e1.paso2.estado === 'validada'));

            const crearBoton = (etiqueta, paso, bloqueoPropio) => {
                const btn = document.createElement('button');
                btn.className = 'btn-descarga-principal';
                btn.style.cssText = 'background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 4px 12px rgba(139,92,246,.2)';
                btn.id = 'btn-liquidar-' + op.id + (paso === 2 ? '-p2' : '');
                btn.innerHTML = etiqueta;

                const motivo = bloqueo || bloqueoPropio;

                if (motivo) {
                    btn.disabled = true;
                    btn.title    = motivo;
                    btn.style.opacity = '.55';
                    btn.style.cursor  = 'not-allowed';
                } else {
                    btn.onclick = () => liquidarEnEnlace(op.id, op.nombre, paso);
                }

                cont.appendChild(btn);
                return btn;
            };

            if (op.e1) {
                // El mes se paga en DOS planillas y el plano sigue pendiente
                // hasta confirmar las dos. Sin este aviso, quien vea el pago
                // del paso 1 da el mes por cubierto cuando todavía falta la
                // corrección —que es la que lleva la salud o la caja—.
                const paso2Pagado = !!(op.e1.paso2 && op.e1.paso2.pago_confirmado);
                if (op.e1.pago_confirmado && !paso2Pagado) {
                    const entidad = (op.e1.planes || []).join(' / ').replace('SOLO_EPS', 'la EPS')
                        .replace('EPS_ARL', 'la EPS y la ARL').replace('SOLO_AFP', 'el fondo de pensión')
                        .replace(/SOLO_CCF_\d+/, 'la caja');
                    const falta = faltaPaso2
                        ? '<strong>falta la corrección</strong>: hasta que se liquide y se pague'
                        : `la corrección <strong>${op.e1.paso2.numero_planilla}</strong> ya está liquidada, `
                          + '<strong>falta pagarla y confirmar el pago</strong>: hasta entonces';
                    const pendiente = document.createElement('div');
                    pendiente.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e',
                        '🟡 <strong>Pago 1 de 2.</strong> La planilla del paso 1 está pagada, pero '
                        + falta + ', el mes no queda cubierto ante ' + entidad + '.');
                    cont.appendChild(pendiente);
                }

                // Modalidad E-1: son dos liquidaciones encadenadas. La segunda
                // no puede salir hasta que la primera esté pagada, porque el
                // operador solo acepta corregir una planilla ya pagada y el
                // archivo tiene que decir en qué fecha se pagó.
                crearBoton(
                    (op.e1.paso1_liquidado ? '✅ ' : '1️⃣ ') + `Paso 1 · Planilla de 1 día en ${op.nombre}`
                        + (op.e1.pago_confirmado ? ` · ${op.e1.paso1.numero_planilla} pagada` : ''),
                    1, op.e1.pago_confirmado
                        ? `La planilla del paso 1 (${op.e1.paso1.numero_planilla}) ya está pagada. Solo falta la corrección.`
                        : null
                );

                let bloqueoP2 = null;
                if (!op.e1.paso1_liquidado) {
                    bloqueoP2 = 'Primero hay que liquidar la planilla del paso 1.';
                } else if (op.e1.por_portal && !op.e1.pago_confirmado) {
                    // La corrección de salud se hace en el portal del operador,
                    // que exige la planilla del paso 1 ya pagada.
                    bloqueoP2 = 'La planilla del paso 1 todavía no tiene el pago confirmado. '
                              + 'El portal del operador solo deja corregir una planilla pagada.';
                } else if (!op.e1.automatico && !op.e1.pago_confirmado) {
                    // En Solo Caja (`automatico`) la fecha de pago la revela el
                    // operador al primer intento, así que la corrección puede
                    // salir sin esperar la confirmación manual del pago.
                    bloqueoP2 = 'La planilla del paso 1 todavía no tiene el pago confirmado. '
                              + 'El operador rechaza una corrección sobre una planilla sin pagar.';
                }

                const p2 = op.e1.paso2 && op.e1.paso2.estado === 'validada' ? op.e1.paso2 : null;
                crearBoton(
                    (p2 ? '✅ ' : '2️⃣ ')
                        + 'Paso 2 · ' + (op.e1.etiqueta_paso2 || 'Corrección')
                        + (op.e1.por_portal ? ' (por el portal)' : '')
                        + (p2 && !p2.pago_confirmado
                            ? ` · ${p2.numero_planilla}`
                              + (Math.round(p2.valor_total || 0) > 0 ? ` · $ ${fmtNum(Math.round(p2.valor_total))}` : '')
                              + ' — pendiente de pago'
                            : ''),
                    2, bloqueoP2
                );

                if (op.e1.por_portal && !bloqueoP2 && !op.e1.paso2) {
                    // Es el mismo botón, pero por dentro hace otra cosa: la
                    // salud no pasa por archivo plano y el robot la sube al
                    // portal del operador. Tarda minutos, no segundos.
                    const aviso = document.createElement('div');
                    aviso.innerHTML = avisoEnlace('#eff6ff', '#bfdbfe', '#1e40af',
                        '🤖 Esta corrección la hace BryNex <strong>en el portal del operador</strong>, '
                        + 'porque el archivo plano no la acepta. Puede tardar un par de minutos; '
                        + 'al terminar queda aquí con su enlace de pago.');
                    cont.appendChild(aviso);
                }

                if (bloqueoP2 && !bloqueo) {
                    const aviso = document.createElement('div');
                    aviso.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e', '⏳ ' + bloqueoP2);
                    cont.appendChild(aviso);
                } else if (op.e1.pago_confirmado && !op.e1.paso2) {
                    const aviso = document.createElement('div');
                    aviso.innerHTML = avisoEnlace('#f0fdf4', '#bbf7d0', '#166534',
                        `✅ Pago del paso 1 confirmado el <strong>${op.e1.fecha_pago}</strong>. Ya se puede enviar la corrección.`);
                    cont.appendChild(aviso);
                } else if (op.e1.automatico && op.e1.paso1_liquidado && !op.e1.paso2) {
                    const aviso = document.createElement('div');
                    aviso.innerHTML = avisoEnlace('#eff6ff', '#bfdbfe', '#1e40af',
                        '💡 La corrección se puede enviar en cuanto el paso 1 esté pagado en el operador. '
                        + 'La fecha del pago la averigua BryNex sola: no hay que confirmarla aquí.');
                    cont.appendChild(aviso);
                }
            } else {
                crearBoton(corrN ? `🚀 Liquidar corrección N en ${op.nombre}` : `🚀 Liquidar en ${op.nombre}`, 1, null);
            }

            if (bloqueo && !CTX.planoPagado) {
                const aviso = document.createElement('div');
                aviso.innerHTML = avisoEnlace('#fef2f2', '#fecaca', '#991b1b', '⚠️ ' + bloqueo);
                cont.appendChild(aviso);
            }
        });

        // Si ya se liquidó este periodo, mostrarlo en vez de arrancar en blanco.
        const yaLiquidado = data.operadores.find(o => o.planilla);
        if (yaLiquidado) {
            // En la E-1 `planilla` es el paso 1. Si ya está pagado y la
            // corrección espera pago, lo que hay que mostrar —total y enlace
            // PSE— es la corrección, no la planilla que ya se pagó.
            const p2 = yaLiquidado.e1 && yaLiquidado.e1.paso2;
            const mostrar = (p2 && p2.estado === 'validada' && !p2.pago_confirmado)
                ? p2 : yaLiquidado.planilla;
            renderEstadoEnlace(mostrar, yaLiquidado.nombre);
        } else {
            // No hay planilla para ESTE filtro, pero sí para la tanda con otro.
            // Callarlo hace creer que nunca se liquidó, y basta marcar una
            // modalidad de más —aunque no aporte a nadie— para llegar aquí.
            const otra = data.operadores.find(o => (o.planillas_tanda || []).length);
            if (otra) {
                const filas = otra.planillas_tanda.map(p =>
                    `<li><strong>${p.numero_planilla}</strong> · $ ${fmtNum(Math.round(p.valor_total))} · ` +
                    `<em>${p.modalidades}</em> · ${p.fecha || ''}` +
                    (p.url_pago ? ` · <a href="${p.url_pago}" target="_blank" rel="noopener" style="color:#92400e;font-weight:700;text-decoration:underline">pagar en PSE →</a>` : '') +
                    `</li>`).join('');

                const cont = document.getElementById('enlace-ultima');
                cont.style.display = '';
                cont.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e',
                    `<strong>ℹ️ Esta tanda ya tiene ${otra.planillas_tanda.length} planilla(s) liquidada(s) en ${otra.nombre},</strong> ` +
                    `pero ninguna con el filtro de Modalidad que tiene puesto:` +
                    `<ul style="margin:.35rem 0 0 1rem;padding:0">${filas}</ul>` +
                    `<div style="margin-top:.35rem;font-size:.72rem">Por eso los totales de arriba no las incluyen. ` +
                    `Ajuste el filtro para ver una de ellas, o liquide aparte lo que falte — pero revise antes que esa gente no esté ya en las de arriba.</div>`);
            }
        }

        pintarPendientesCierre(data.pendientes);
    } catch (e) {
        // Silencioso: la integración es opcional, las descargas siguen sirviendo.
        console.warn('No se pudo consultar el estado de los operadores:', e);
    }
}

// Fondos de pensión que Brynex corrigió solo antes de mandar el archivo:
// gente que iba sin AFP aunque su factura sí le cobró el aporte. Se muestra
// siempre —no es un detalle técnico—: cambió el contrato y la ficha de esa
// persona, y el total de la planilla sube por ese aporte.
function avisoPensionCorregida(correcciones) {
    if (!correcciones || !correcciones.length) return '';

    const filas = correcciones.map(c =>
        `<li><strong>${c.nombre || c.documento}</strong> (${c.documento}): ${c.de} → <strong>${c.a}</strong></li>`
    ).join('');

    return avisoEnlace('#eff6ff', '#bfdbfe', '#1e40af',
        `<strong>🔧 Se corrigió el fondo de pensión de ${correcciones.length} cotizante(s) antes de liquidar.</strong>` +
        `<ul style="margin:.35rem 0 0 1rem;padding:0">${filas}</ul>` +
        `<div style="margin-top:.35rem;font-size:.72rem">Iban sin AFP aunque la factura sí les cobró pensión. ` +
        `El cambio quedó también en el contrato y en la ficha del cliente.</div>`);
}

// Cuando el operador ya liquidó la tanda, manda su número y no la estimación.
//
// La mora que calcula Brynex es una proyección: sale de la tabla legal del
// Decreto 1990/2016 (últimos dos dígitos del NIT → día hábil) y de la tasa
// configurada. Sirve para saber a qué atenerse ANTES de liquidar, pero no
// siempre coincide con lo que el operador termina cobrando — en la planilla
// 87590315 de ELITES CREACIONES, Brynex proyectaba $0 y Enlace cobró $4.800.
// Una vez existe planilla, lo que hay que pagar es lo que dice el operador.
// A cuántos días de atraso equivale la mora que cobró el operador.
//
// El operador no dice los días, solo el valor. Pero la mora se arma con una
// fórmula conocida —tasa × días sobre el aporte de cada administradora,
// redondeando cada una al centenar— así que se invierte: se prueban los días
// hasta dar con el valor que cobró. Es la misma fórmula de calcularMora(),
// leída al revés.
//
// Sirve porque el vencimiento que calcula Brynex no siempre coincide con el
// que aplica el operador: en el plano 5 de ELITES CREACIONES la tabla del
// Decreto 1990/2016 daba "vence hoy, 0 días" y Enlace cobraba 4 días.
// Mientras eso no se aclare, los días que valen son los que explican el cobro.
function diasSegunMoraDelOperador(mora) {
    if (mora <= 0) return 0;

    const entidades = (CTX.porEntidad || []).length
        ? CTX.porEntidad.map(e => e.total)
        : [CTX.pendienteEPS, CTX.pendienteAFP, CTX.pendienteARL, CTX.pendienteCCF];

    if (!entidades.some(v => v > 0)) return null;

    const anio     = CTX.anio;
    const diasAnio = (anio % 4 === 0 && anio % 100 !== 0) || anio % 400 === 0 ? 366 : 365;

    let mejor = null;
    for (let d = 1; d <= 400; d++) {
        const factor = (CTX.tasaMora / 100) / diasAnio * d;
        const valor  = entidades.reduce((a, v) => a + Math.ceil(v * factor / 100) * 100, 0);
        const brecha = Math.abs(valor - mora);
        if (!mejor || brecha < mejor.brecha) mejor = { dias: d, brecha };
        if (valor > mora) break;   // ya se pasó: el mínimo está en este entorno
    }
    return mejor ? mejor.dias : null;
}

// Hasta cuándo cuenta la mora: el día en que el pago se abona, que es lo que
// calcula calcularMora() y lo que explica los días que cobró el operador.
function fechaHastaLaQueCuentaLaMora() {
    const f = window.CTX_FECHA_ABONO;
    return (f instanceof Date && !isNaN(f))
        ? f.toLocaleDateString('es-CO', { weekday: 'long', day: '2-digit', month: 'long' })
        : null;
}

function aplicarTotalDelOperador(valorTotal, numeroPlanilla, fechaLiquidacion) {
    const total = Math.round(Number(valorTotal) || 0);
    if (!total) return;

    const ssBase = CTX.ssBaseOperador || CTX.totalSS || 0;
    const dif    = total - ssBase;

    // La diferencia entre lo liquidado y los aportes es mora... siempre que la
    // planilla siga representando este archivo. Si algún plano de la tanda se
    // tocó después de liquidar —se corrigió un contrato, entró gente nueva—,
    // esa diferencia no es mora sino un archivo desactualizado, y llamarla
    // mora manda a pagar un valor que ya no corresponde.
    const desactualizada = fechaLiquidacion && CTX.planosUltimoCambio
                        && CTX.planosUltimoCambio > fechaLiquidacion;

    // Segundo filtro, por tamaño: a 26,17% E.A. la mora es ~0,072% por día,
    // así que ni dos meses de atraso llegan al 10% de los aportes.
    const esMoraPlausible = !desactualizada && dif >= 0 && dif <= ssBase * 0.10;

    const set = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
    const ver = (id) => { const el = document.getElementById(id); if (el) el.hidden = false; };

    ['mora-sep2','mora-item-dias','mora-sep3','mora-item-valor','mora-sep4','mora-item-total'].forEach(ver);
    set('mora-valor', esMoraPlausible ? '$ ' + fmtNum(dif) : '—');
    set('mora-total', '$ ' + fmtNum(total));

    // Los días también salen del operador: mostrar "0 días" al lado de una mora
    // cobrada es la contradicción que hacía dudar del número.
    const dias = esMoraPlausible ? diasSegunMoraDelOperador(dif) : null;
    if (esMoraPlausible) {
        set('mora-dias', dias === null ? '—' : (dias === 0 ? '0 días' : `≈ ${dias} día(s)`));
    } else {
        set('mora-dias', '—');
    }

    const bloque = document.getElementById('mora-bloque');
    if (bloque) {
        const alerta = !esMoraPlausible;
        const conMora = esMoraPlausible && dif > 0;
        bloque.style.background  = alerta  ? 'linear-gradient(135deg,#fef2f2,#fee2e2)'
                                 : conMora ? 'linear-gradient(135deg,#fff7ed,#fef3c7)'
                                           : 'linear-gradient(135deg,#f0fdf4,#dcfce7)';
        bloque.style.borderColor = alerta ? '#fecaca' : (conMora ? '#fde68a' : '#86efac');
    }

    const info = document.getElementById('mora-info-txt');
    if (info) {
        info.style.display = '';
        info.textContent = esMoraPlausible
            ? `Valores de la planilla ${numeroPlanilla} liquidada en el operador`
              + (dif > 0
                  ? ` · incluye $${fmtNum(dif)} de mora`
                    + (dias
                        ? ` ≈ ${dias} día(s) al ${CTX.tasaMora}% E.A.`
                          // El operador cuenta hasta que el pago se abona, no
                          // hasta hoy: por eso una planilla del viernes por la
                          // noche ya trae la mora del próximo día hábil.
                          + (fechaHastaLaQueCuentaLaMora()
                              ? `, contados hasta el ${fechaHastaLaQueCuentaLaMora()}, que es cuando se abona el pago`
                              : '')
                        : '')
                  : ' · sin mora')
            : `⚠️ La planilla ${numeroPlanilla} quedó en $${fmtNum(total)} y los aportes de esta `
              + `tanda suman $${fmtNum(ssBase)}. `
              + (desactualizada
                  ? `El archivo cambió después de liquidarla (último cambio ${CTX.planosUltimoCambio}, `
                    + `liquidada ${fechaLiquidacion}). `
                  : '')
              + `Vuelva a liquidar antes de pagar.`;
    }

    // El modal de pago debe proponer lo que se va a pagar de verdad.
    window.CTX_TOTAL_PAGAR = total;
}

function avisoEnlace(bg, borde, color, html) {
    return `<div style="background:${bg};border:1px solid ${borde};border-radius:10px;padding:.7rem .85rem;font-size:.76rem;color:${color};line-height:1.4">${html}</div>`;
}

// Cuadre del período: cuántos contratos vigentes de esta razón social todavía
// no entran a ninguna planilla. Es lo que Enlace reclama con la advertencia
// "no se reportó novedad de retiro y no se encuentra reportado en esta
// planilla". Informativo: al liquidar la última tanda debería quedar en cero.
function pintarPendientesCierre(p) {
    const cont = document.getElementById('enlace-pendientes');
    if (!cont || !p) return;

    if (!p.total) {
        cont.style.display = '';
        cont.innerHTML = avisoEnlace('#f0fdf4', '#bbf7d0', '#166534',
            '✅ <strong>Sin pendientes.</strong> Todos los contratos vigentes de esta razón social ya están en una planilla del período.');
        return;
    }

    cont.style.display = '';
    cont.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e',
        `📋 Quedan <strong>${p.total}</strong> contrato(s) vigente(s) sin planilla en este período.` +
        ` Si todavía faltan tandas por facturar es normal; si esta era la última, son retiros sin registrar.` +
        (p.url ? `<br><a href="${p.url}" target="_blank" rel="noopener" style="color:#92400e;font-weight:700;text-decoration:underline">Ver quiénes son →</a>` : ''));
}

// Pinta el resultado guardado de una liquidación anterior.
function renderEstadoEnlace(p, operadorNombre) {
    const cont = document.getElementById('enlace-ultima');
    cont.style.display = '';
    const enOperador = operadorNombre ? ` en ${operadorNombre}` : '';

    if (p.estado === 'validada' && p.numero_planilla) {
        aplicarTotalDelOperador(p.valor_total, p.numero_planilla, p.fecha);
        cont.innerHTML = avisoEnlace('#f0fdf4', '#bbf7d0', '#166534',
            `<strong>✅ Planilla ${p.numero_planilla}</strong> liquidada${enOperador} el ${p.fecha || ''}.` +
            (p.valor_total ? `<br>Total a pagar: <strong>$ ${fmtNum(Math.round(p.valor_total))}</strong>` : '') +
            (p.url_pago ? `<br><a href="${p.url_pago}" target="_blank" rel="noopener" style="color:#15803d;font-weight:700;text-decoration:underline">Ir a pagar en PSE →</a>` : ''));
    } else if (p.estado === 'con_errores') {
        cont.innerHTML = avisoEnlace('#fffbeb', '#fde68a', '#92400e',
            `<strong>⚠️ Último intento con errores.</strong><br>${p.mensaje_error || ''}`);
    } else if (p.estado === 'error') {
        cont.innerHTML = avisoEnlace('#fef2f2', '#fecaca', '#991b1b',
            `<strong>✗ Último intento falló.</strong><br>${p.mensaje_error || ''}`);
    } else {
        cont.style.display = 'none';
    }
}

async function liquidarEnEnlace(operadorId, operadorNombre, paso = 1) {
    if (!CTX.razonSocialId) {
        mostrarToast('Seleccione una Razón Social primero.', 'error');
        return;
    }

    const btn  = document.getElementById('btn-liquidar-' + operadorId + (paso === 2 ? '-p2' : ''));
    const orig = btn.innerHTML;

    btn.disabled  = true;
    btn.innerHTML = '⏳ Enviando al operador…';

    const cont = document.getElementById('enlace-ultima');
    cont.style.display = '';
    cont.innerHTML = avisoEnlace('#eff6ff', '#bfdbfe', '#1d4ed8',
        `Generando el archivo plano y enviándolo a ${operadorNombre}. Esto puede tardar hasta un minuto.`);

    try {
        const body = {
            ...paramsEnlace(),
            operador_planilla_id: operadorId,
            tipos_modalidad     : CTX.modalidadesIds,
            paso                : paso,
        };

        const enviar = () => fetch(CTX.routes.enlaceLiquidar, {
            method : 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept'      : 'application/json',
                'X-CSRF-TOKEN': CTX.csrfToken,
            },
            body: JSON.stringify(body),
        });

        let resp = await enviar();
        let data = await resp.json();

        // 409: la tanda ya tiene planilla liquidada. Volver a liquidar
        // reemplaza ese número, así que se pregunta antes de insistir.
        if (resp.status === 409 && data.requiere_confirmacion) {
            const total = data.valor_total ? ` por $ ${fmtNum(Math.round(data.valor_total))}` : '';
            const riesgo = data.reemplaza
                ? 'Si continúa, ese número se reemplaza por el de la nueva liquidación y Brynex deja de tener la referencia anterior.'
                : 'Esa planilla se liquidó con otro filtro. Si la gente de este archivo ya está incluida ahí, quedaría pagada dos veces en el operador.';

            const ok = paso === 2
                ? confirm(
                    `Ya existe la corrección ${data.numero_planilla}${total}, liquidada el ${data.fecha || ''} y pendiente de pago.\n\n` +
                    `Si continúa, se crea OTRA corrección en ${operadorNombre} y hay que pagar la nueva, no la anterior.\n\n` +
                    `¿Crear otra corrección?`)
                : confirm(
                `Esta tanda ya tiene la planilla ${data.numero_planilla}${total}, liquidada el ${data.fecha || ''}.\n\n` +
                `${riesgo}\n\n` +
                `¿Liquidar de nuevo?`);

            if (!ok) {
                cont.innerHTML = avisoEnlace('#f8fafc', '#e2e8f0', '#475569',
                    `Se mantiene la planilla <strong>${data.numero_planilla}</strong>. No se liquidó nada nuevo.`);
                return;
            }

            body.reliquidar = true;
            resp = await enviar();
            data = await resp.json();
        }

        if (!resp.ok || !data.success) {
            // La corrección de una E-1 rebotada por falta de pago no es un
            // error del archivo: es que todavía no es su turno.
            const estilo = data.falta_pago
                ? ['#fffbeb', '#fde68a', '#92400e', '⏳ Todavía no se puede enviar la corrección.']
                : ['#fef2f2', '#fecaca', '#991b1b', '✗ No se pudo liquidar.'];

            cont.innerHTML = avisoEnlace(estilo[0], estilo[1], estilo[2],
                `<strong>${estilo[3]}</strong><br>${data.message || 'Error desconocido.'}`);
            mostrarToast(data.message || 'No se pudo liquidar la planilla.', 'error');
            return;
        }

        if (!data.liquidada) {
            cont.innerHTML = avisoPensionCorregida(data.pension_corregida) +
                avisoEnlace('#fffbeb', '#fde68a', '#92400e',
                `<strong>⚠️ El archivo tiene ${data.total_errores} error(es).</strong> ` +
                `Enlace no generó número de planilla.<br>` +
                `<button type="button" onclick="verErroresEnlace()" style="margin-top:.4rem;background:#f59e0b;color:#fff;border:none;border-radius:8px;padding:.35rem .7rem;font-size:.74rem;font-weight:700;cursor:pointer">Ver detalle de errores</button>`);
            window._enlaceErrores = data;
            mostrarToast(`La planilla tiene ${data.total_errores} error(es).`, 'error');
            return;
        }

        aplicarTotalDelOperador(data.valor_total, data.numero_planilla, null);

        const titulo = paso === 2
            ? `✅ Corrección ${data.numero_planilla} liquidada en ${operadorNombre}.`
            : `✅ Planilla ${data.numero_planilla} liquidada en ${operadorNombre}.`;

        cont.innerHTML = avisoPensionCorregida(data.pension_corregida) +
            avisoEnlace('#f0fdf4', '#bbf7d0', '#166534',
            `<strong>${titulo}</strong>` +
            (data.valor_total ? `<br>Total a pagar: <strong>$ ${fmtNum(Math.round(data.valor_total))}</strong>` : '') +
            (data.valor_mora ? ` (mora: $ ${fmtNum(Math.round(data.valor_mora))})` : '') +
            (data.fecha_limite ? `<br>Fecha límite: ${String(data.fecha_limite).substring(0, 10)}` : '') +
            (data.url_pago ? `<br><a href="${data.url_pago}" target="_blank" rel="noopener" style="color:#15803d;font-weight:700;text-decoration:underline">Ir a pagar en PSE →</a>` : ''));

        pintarPendientesCierre(data.pendientes);

        mostrarToast(`Planilla ${data.numero_planilla} liquidada en ${operadorNombre}.`, 'success');

        // El paso 1 acaba de generar el número que habilita la corrección
        // (una vez se confirme su pago): hay que repintar los botones.
        cargarEstadoEnlace();
    } catch (e) {
        cont.innerHTML = avisoEnlace('#fef2f2', '#fecaca', '#991b1b',
            `<strong>✗ Error de conexión.</strong><br>${e.message}`);
        mostrarToast('Error de conexión al liquidar.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = orig;
    }
}

// ── Asignar operador de planilla al contratista desde la tabla ─────────
// Guarda en clientes.operador_planilla_id y actualiza TODAS las filas de ese
// contratista (puede tener varias en el mismo plano), incluido el botón PSE.
async function asignarOperadorCliente(sel) {
    const operadorId = parseInt(sel.value);
    if (!operadorId) return;

    const td        = sel.closest('.td-operador');
    const clienteId = td.dataset.clienteId;
    const corto     = sel.options[sel.selectedIndex].dataset.corto || sel.options[sel.selectedIndex].textContent.trim();

    sel.disabled = true;

    try {
        const resp = await fetch(CTX.routes.asignarOperador, {
            method : 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept'      : 'application/json',
                'X-CSRF-TOKEN': CTX.csrfToken,
            },
            body: JSON.stringify({ cliente_id: parseInt(clienteId), operador_planilla_id: operadorId }),
        });

        const data = await resp.json();

        if (!resp.ok || !data.ok) {
            mostrarToast(data.mensaje || 'No se pudo asignar el operador.', 'error');
            sel.disabled = false;
            sel.value    = '';
            return;
        }

        document.querySelectorAll(`.td-operador[data-cliente-id="${clienteId}"]`).forEach(celda => {
            celda.innerHTML = `<span class="op-chip">🏦 ${corto}</span>`;
            celda.title     = data.operador.nombre;
            celda.setAttribute('data-order', data.operador.nombre);

            const fila = celda.closest('tr');
            if (!fila) return;
            fila.dataset.operadorId = data.operador.id;

            // El botón PSE se habilita solo si el operador tiene integración por API
            const btnPse = fila.querySelector('.btn-pse-icon');
            if (btnPse) {
                btnPse.disabled = !data.operador.api;
                btnPse.title    = data.operador.api
                    ? `Liquidar en ${data.operador.nombre} y generar el link de pago PSE`
                    : `Este contratista usa ${data.operador.nombre}, que no tiene integración por API.`;
            }
        });

        mostrarToast(data.mensaje, 'success');
    } catch (e) {
        mostrarToast('Error de conexión al asignar el operador.', 'error');
        sel.disabled = false;
        sel.value    = '';
    }
}

// ── Liquidar en línea UN contratista independiente puntual ─────────────
async function liquidarIndependienteRow(planoId, operadorId, btnEl) {
    if (!operadorId) {
        mostrarToast('Este contratista no tiene operador asignado.', 'error');
        return;
    }

    const celda = document.getElementById('accion-' + planoId);
    const orig  = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.classList.add('cargando');
    btnEl.innerHTML = '…';

    try {
        const resp = await fetch(CTX.routes.enlaceLiquidarIndependiente, {
            method : 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept'      : 'application/json',
                'X-CSRF-TOKEN': CTX.csrfToken,
            },
            body: JSON.stringify({ plano_id: planoId, operador_planilla_id: operadorId }),
        });

        const data = await resp.json();

        if (!resp.ok || !data.success) {
            mostrarToast(data.message || 'No se pudo liquidar la planilla.', 'error');
            return;
        }

        if (!data.liquidada) {
            mostrarToast(`La planilla tiene ${data.total_errores} error(es).`, 'error');
            window._enlaceErrores = data;
            verErroresEnlace();
            return;
        }

        // Reemplaza el botón por el chip de "ya liquidado" con el link PSE.
        if (celda) {
            const btnPagar = celda.querySelector('button[onclick^="abrirModalPagoIndividual"]');
            const chip = document.createElement('span');
            chip.className = 'chip-liquidado-api';
            chip.title = `Liquidada: ${data.numero_planilla}`;
            chip.innerHTML = `⚡ ${data.numero_planilla}` +
                (data.url_pago ? ` <a href="${data.url_pago}" target="_blank" rel="noopener">PSE →</a>` : '');
            btnEl.replaceWith(chip);
        }

        mostrarToast(`Planilla ${data.numero_planilla} liquidada.`, 'success');
    } catch (e) {
        mostrarToast('Error de conexión al liquidar.', 'error');
    } finally {
        if (document.body.contains(btnEl)) {
            btnEl.disabled = false;
            btnEl.classList.remove('cargando');
            btnEl.innerHTML = orig;
        }
    }
}

// Detalle de errores devuelto por la validación (máximo 100 líneas).
function verErroresEnlace() {
    const data = window._enlaceErrores;
    if (!data) return;

    const filas = [];

    (data.errores_empresa || []).forEach(e => {
        filas.push(['Empresa', '', e.descripcion || e.mensaje || JSON.stringify(e)]);
    });

    // Enlace identifica al cotizante en `identificacion` ("CC1062304870"),
    // no en campos separados de tipo y número.
    const refCotizante = e => e.identificacion || (e.linea ? 'Línea ' + e.linea : '');

    // Errores de Enlace que necesitan una explicación para saber qué hacer.
    const explicaciones = {
        // Fondo de Solidaridad: no está inscrito en el PSAP, o lo sacaron del
        // programa (por ejemplo, por seis meses seguidos sin pagar).
        'eo.val.2.504': 'La persona no está inscrita en el Fondo de Solidaridad, o la retiraron del programa. '
            + 'Revise el certificado en el Fondo; si ya no es beneficiaria, hay que pasarla a Independiente.',
    };

    (data.errores_cotizante || []).forEach(e => {
        const desc = e.descripcion || e.mensaje || JSON.stringify(e);
        const extra = explicaciones[e.idRegla];
        filas.push(['Cotizante', refCotizante(e), extra ? `${desc}<br><b style="color:#1d4ed8">→ ${extra}</b>` : desc]);
    });

    (data.advertencias || []).forEach(e => {
        filas.push(['Advertencia', refCotizante(e), e.descripcion || e.mensaje || JSON.stringify(e)]);
    });

    pintarAutocorreccion(data);

    document.getElementById('enlace-errores-cuerpo').innerHTML = filas.length
        ? filas.map(([tipo, ref, desc]) => `
            <tr>
                <td style="padding:.4rem .5rem;border-bottom:1px solid #f1f5f9;font-weight:700;color:${tipo === 'Advertencia' ? '#92400e' : '#991b1b'};white-space:nowrap">${tipo}</td>
                <td style="padding:.4rem .5rem;border-bottom:1px solid #f1f5f9;color:#475569;white-space:nowrap">${ref}</td>
                <td style="padding:.4rem .5rem;border-bottom:1px solid #f1f5f9;color:#374151">${desc}</td>
            </tr>`).join('')
        : '<tr><td colspan="3" style="padding:.8rem;color:#64748b">Enlace no devolvió el detalle. Consulte la planilla en el portal.</td></tr>';

    document.getElementById('enlace-errores-resumen').textContent =
        `Código de planilla ${data.codigo_planilla} · ${data.total_errores} error(es)`;

    document.getElementById('modal-enlace-errores').classList.add('open');
}

// Errores que Enlace marca como autocorregibles: se listan a quién y qué
// se le cambia, con el botón para aceptarlo.
function pintarAutocorreccion(data) {
    const caja  = document.getElementById('enlace-autocorreccion');
    const lista = document.getElementById('enlace-autocorreccion-lista');
    const correcciones = data.correcciones || [];

    if (!correcciones.length || !data.codigo_planilla) {
        caja.style.display = 'none';
        return;
    }

    lista.innerHTML = correcciones.map(c => {
        const quien = [c.nombre, c.identificacion].filter(Boolean).join(' · ') || 'Cotizante sin identificar';

        const cambio = c.aplicable
            ? `<span style="color:#334155">${c.etiqueta}: </span>` +
              `<span style="text-decoration:line-through;color:#94a3b8">${c.actual?.nombre ?? '—'}</span>` +
              `<span style="color:#334155"> → </span><strong style="color:#1d4ed8">${c.nueva?.nombre ?? '—'}</strong>`
            : `<span style="color:#92400e">${c.motivo || 'Enlace lo corrige, pero hay que ajustarlo a mano en Brynex.'}</span>`;

        return `<div style="font-size:.75rem;line-height:1.35">
                    <strong style="color:#0f172a">${quien}</strong><br>${cambio}
                </div>`;
    }).join('');

    const aplicables = correcciones.filter(c => c.aplicable).length;
    document.getElementById('btn-autocorregir').textContent = aplicables === correcciones.length
        ? 'Autocorregir en Enlace y en Brynex'
        : `Autocorregir en Enlace (${aplicables} de ${correcciones.length} se guardan en Brynex)`;

    caja.dataset.codigoPlanilla = data.codigo_planilla;
    caja.style.display = 'block';
}

// Acepta la autocorrección: Enlace corrige la planilla y Brynex guarda el
// mismo cambio en el plano y en los contratos vigentes del cotizante.
async function autocorregirEnlace() {
    const caja = document.getElementById('enlace-autocorreccion');
    const btn  = document.getElementById('btn-autocorregir');
    const codigoPlanilla = caja.dataset.codigoPlanilla;

    if (!codigoPlanilla) return;

    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Corrigiendo…';

    try {
        const resp = await fetch(CTX.routes.enlaceAutocorregir, {
            method : 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept'      : 'application/json',
                'X-CSRF-TOKEN': CTX.csrfToken,
            },
            body: JSON.stringify({ codigo_planilla: Number(codigoPlanilla) }),
        });

        const data = await resp.json();

        if (!resp.ok || !data.success) {
            mostrarToast(data.message || 'No se pudo autocorregir la planilla.', 'error');
            return;
        }

        const ap = data.aplicado || {};
        if (ap.planos || ap.contratos || ap.clientes) {
            mostrarToast(
                `Brynex actualizado: ${ap.planos} plano(s), ${ap.contratos} contrato(s) y ${ap.clientes} cliente(s).`,
                'success');
        }

        if (!data.liquidada) {
            // Quedan errores que Enlace no puede corregir: se repinta el modal.
            window._enlaceErrores = data;
            verErroresEnlace();
            mostrarToast(data.message, 'error');
            return;
        }

        cerrarModal('modal-enlace-errores');
        mostrarToast(`Planilla ${data.numero_planilla} liquidada.`, 'success');

        const cont = document.getElementById('enlace-ultima');
        if (cont) {
            cont.innerHTML = avisoEnlace('#f0fdf4', '#bbf7d0', '#166534',
                `<strong>✅ Planilla ${data.numero_planilla} liquidada tras la autocorrección.</strong>` +
                (data.valor_total ? `<br>Total a pagar: <strong>$ ${fmtNum(Math.round(data.valor_total))}</strong>` : '') +
                (data.valor_mora ? ` (mora: $ ${fmtNum(Math.round(data.valor_mora))})` : '') +
                (data.fecha_limite ? `<br>Fecha límite: ${String(data.fecha_limite).substring(0, 10)}` : '') +
                (data.url_pago ? `<br><a href="${data.url_pago}" target="_blank" rel="noopener" style="color:#15803d;font-weight:700;text-decoration:underline">Ir a pagar en PSE →</a>` : ''));
        }
    } catch (e) {
        mostrarToast('Error de conexión al autocorregir.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = orig;
    }
}

// ── Descargar Aportes en Línea (Excel AEL) ──────────────────────────
// version 2 = mismos datos sobre la plantilla del portal.
function ejecutarDescargaAportesEnLinea(version = 1) {
    if (!CTX.razonSocialId) {
        mostrarToast('Seleccione una Razón Social primero.', 'error');
        return;
    }
    const params = new URLSearchParams({
        razon_social_id: CTX.razonSocialId,
        mes    : CTX.mes,
        anio   : CTX.anio,
        n_plano: CTX.nPlanoFiltro ?? '',
    });
    CTX.modalidadesIds.forEach(id => params.append('tipos_modalidad[]', id));
    const ruta = version === 2 ? CTX.routes.descargarAportesEnLinea2 : CTX.routes.descargarAportesEnLinea;
    window.location.href = ruta + '?' + params.toString();
}

// ── Guardar N_PLANO ────────────────────────────────────────────────────
async function guardarNPlano() {
    const nPlano = parseInt(document.getElementById('inp-nplano-modal').value);
    if (!nPlano || nPlano < 1) { mostrarToast('Ingrese un N_PLANO válido.','error'); return; }
    if (!CTX.razonSocialId)    { mostrarToast('Seleccione una razón social.','error'); return; }

    try {
        const resp = await fetch(CTX.routes.nPlanoUpdate, {
            method : 'PATCH',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': CTX.csrfToken },
            body   : JSON.stringify({ razon_social_id: CTX.razonSocialId, n_plano: nPlano }),
        });
        const data = await resp.json();
        if (data.ok) {
            mostrarToast(data.mensaje, 'success');
            // Actualizar badge visible en la cabecera
            if (document.getElementById('badge-nplano-val'))
                document.getElementById('badge-nplano-val').textContent = nPlano;
            // Cerrar el modal al guardar exitosamente
            cerrarModal('modal-descarga');
        } else {
            mostrarToast(data.mensaje || 'Error al guardar.', 'error');
        }
    } catch(e) {
        mostrarToast('Error de conexión.', 'error');
    }
}

// ── Mover registro a otro n_plano ─────────────────────────────────────────
function abrirModalMover(id, nPlanoActual) {
    document.getElementById('mover-plano-id').value      = id;
    document.getElementById('mover-plano-actual').value  = nPlanoActual;
    document.getElementById('mover-plano-nuevo').value   = nPlanoActual + 1;
    document.getElementById('modal-mover').classList.add('open');
}
async function guardarMover() {
    const id     = document.getElementById('mover-plano-id').value;
    const nPlano = parseInt(document.getElementById('mover-plano-nuevo').value);
    if (!nPlano || nPlano < 1) { mostrarToast('N_PLANO inválido.', 'error'); return; }
    try {
        const resp = await fetch(`/admin/planos/${id}/mover`, {
            method : 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CTX.csrfToken },
            body   : JSON.stringify({ n_plano: nPlano }),
        });
        const data = await resp.json();
        if (data.ok) {
            mostrarToast(data.mensaje, 'success');
            cerrarModal('modal-mover');
            setTimeout(() => location.reload(), 700);
        } else {
            mostrarToast(data.mensaje || 'Error al mover.', 'error');
        }
    } catch(e) {
        mostrarToast('Error de conexión.', 'error');
    }
}

// ── Copiar número de planilla al portapapeles ────────────────────────
// ── Menú de una planilla: copiar o corregir ───────────────────────────
let _accPlanilla = { numero: '', valor: 0, gastoId: null, nombre: '' };

function abrirAccionesPlanilla(numero, valor, gastoId, nombre, detalle) {
    _accPlanilla = { numero, valor, gastoId, nombre };

    document.getElementById('acc-planilla-num').textContent = numero;

    const quien = document.getElementById('acc-planilla-quien');
    quien.style.display = nombre ? 'block' : 'none';
    quien.innerHTML = nombre ? '👤 <strong>' + nombre + '</strong>' : '';

    const estado = document.getElementById('acc-planilla-estado');
    estado.style.display = detalle ? 'block' : 'none';
    estado.textContent = detalle || '';

    // Sin gasto detrás (o sin permiso) no hay nada que corregir: queda solo copiar.
    document.getElementById('acc-planilla-editar').style.display = gastoId ? 'flex' : 'none';

    document.getElementById('modal-acciones-planilla').classList.add('open');
}

function copiarPlanillaActual() {
    copiarTexto(_accPlanilla.numero);
    cerrarModal('modal-acciones-planilla');
}

function corregirPlanillaActual() {
    cerrarModal('modal-acciones-planilla');
    abrirCorregirPago(_accPlanilla.numero, _accPlanilla.valor, _accPlanilla.gastoId, _accPlanilla.nombre);
}

function copiarPlanilla(el) {
    copiarTexto(el.dataset.num);
}

function copiarTexto(num) {
    navigator.clipboard.writeText(num)
        .then(() => mostrarToast('📋 Planilla ' + num + ' copiada.', 'success'))
        .catch(() => {
            // Fallback para navegadores sin clipboard API
            const ta = document.createElement('textarea');
            ta.value = num; document.body.appendChild(ta);
            ta.select(); document.execCommand('copy');
            document.body.removeChild(ta);
            mostrarToast('📋 Planilla ' + num + ' copiada.', 'success');
        });
}


function previewSoporte(file) {
    if (!file) return;
    const panel   = document.getElementById('soporte-panel-img');
    const estado  = document.getElementById('soporte-estado');
    const ph      = document.getElementById('soporte-placeholder');

    // Estado confirmado (izquierda)
    if (estado) {
        estado.style.display = 'flex';
        estado.innerHTML = '✅ ' + file.name + ' <span style="font-weight:400;color:#16a34a;margin-left:.3rem">(' + (file.size/1024).toFixed(0) + ' KB)</span>';
    }

    // Preview (derecha)
    if (ph) ph.style.display = 'none';
    if (panel) {
        panel.style.borderColor = '#86efac';
        panel.style.background  = '#f0fdf4';
        if (file.type === 'application/pdf') {
            panel.innerHTML = `<div style="padding:.75rem;text-align:center;font-size:.78rem;color:#92400e">
                <div style="font-size:2rem">📄</div>
                <strong>${file.name}</strong><br>
                <span style="color:#94a3b8">${(file.size/1024).toFixed(0)} KB</span>
            </div>`;
        } else {
            const url = URL.createObjectURL(file);
            panel.innerHTML = `<img src="${url}" alt="preview soporte"
                style="max-height:160px;max-width:100%;border-radius:8px;object-fit:contain;display:block;cursor:zoom-in"
                onclick="window.open(this.src,'_blank')" title="Clic para ampliar">`;
        }
    }
}
function handleSoporteDrop(e) {
    e.preventDefault();
    const panel = document.getElementById('soporte-panel-img');
    if (panel) panel.style.borderColor = '#e2e8f0';
    const file = e.dataTransfer.files[0];
    if (file) {
        document.getElementById('pago-soporte').files = e.dataTransfer.files;
        previewSoporte(file);
    }
}
function limpiarSoporte() {
    document.getElementById('pago-soporte').value = '';
    const estado = document.getElementById('soporte-estado');
    if (estado) { estado.style.display = 'none'; estado.innerHTML = ''; }
    const panel  = document.getElementById('soporte-panel-img');
    if (panel) {
        panel.style.borderColor = '#e2e8f0';
        panel.style.background  = '#f8fafc';
        panel.innerHTML = '<span id="soporte-placeholder" style="font-size:.75rem;color:#94a3b8;text-align:center;padding:.5rem">🖼️ La imagen aparece aquí</span>';
    }
    window._soportePasteBlob = null;
}

// ── Paste de imagen desde el portapapeles ─────────────────────────────
// Botón explícito: usa Clipboard API (más confiable que el evento paste)
async function pegarSoportePortapapeles() {
    const btn = document.getElementById('btn-pegar-soporte');
    const textoOrig = btn ? btn.innerHTML : '';
    try {
        if (!navigator.clipboard || !navigator.clipboard.read) {
            mostrarToast('Tu navegador no soporta lectura del portapapeles. Usa Ctrl+V dentro de la zona o arrastra la imagen.', 'error');
            return;
        }
        if (btn) { btn.innerHTML = '⏳ Leyendo portapapeles...'; btn.disabled = true; }
        const items = await navigator.clipboard.read();
        let encontrada = false;
        for (const item of items) {
            const imgType = item.types.find(t => t.startsWith('image/'));
            if (imgType) {
                const blob = await item.getType(imgType);
                const file = new File([blob], 'comprobante.' + imgType.split('/')[1], { type: imgType });
                // Inyectar en input file
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    document.getElementById('pago-soporte').files = dt.files;
                } catch (_) {
                    window._soportePasteBlob = file;
                }
                previewSoporte(file);
                mostrarToast('✅ Imagen pegada correctamente.', 'success');
                encontrada = true;
                break;
            }
        }
        if (!encontrada) {
            mostrarToast('No se encontró una imagen en el portapapeles. Copia una imagen primero.', 'error');
        }
    } catch (err) {
        // El usuario negó el permiso o el navegador lo bloqueó
        mostrarToast('No se pudo leer el portapapeles: ' + (err.message || err), 'error');
    } finally {
        if (btn) { btn.innerHTML = textoOrig; btn.disabled = false; }
    }
}

// Listener Ctrl+V como alternativa (requiere foco dentro del modal)
document.addEventListener('paste', function(e) {
    const modal = document.getElementById('modal-pago');
    if (!modal || !modal.classList.contains('open')) return;
    const items = e.clipboardData?.items;
    if (!items) return;
    for (let item of items) {
        if (item.type.startsWith('image/')) {
            const file = item.getAsFile();
            if (!file) break;
            try {
                const dt = new DataTransfer();
                dt.items.add(file);
                document.getElementById('pago-soporte').files = dt.files;
            } catch (_) {
                window._soportePasteBlob = file;
            }
            previewSoporte(file);
            mostrarToast('✅ Imagen pegada (Ctrl+V).', 'success');
            break;
        }
    }
});


async function ejecutarConfirmarPago() {
    const operador = document.getElementById('pago-operador').value;
    const numero   = document.getElementById('pago-numero').value.trim();
    const valor    = parseInt(document.getElementById('pago-valor').value);
    const banco    = document.getElementById('pago-banco').value;
    const obs      = document.getElementById('pago-obs').value.trim();
    const fecha    = document.getElementById('pago-fecha')?.value || '';
    const soporteInput = document.getElementById('pago-soporte');
    const soporteFile  = soporteInput.files[0] || window._soportePasteBlob || null;

    // Validaciones obligatorias
    if (!operador) { resaltarError('pago-operador', 'Seleccione el operador.'); return; }
    if (!numero)   { resaltarError('pago-numero',   'Ingrese el número de planilla.'); return; }
    // Una planilla N de corrección se envía en $0: se confirma sin valor ni banco.
    const correccionEnCero = CTX.esCorreccionN && valor === 0;
    if (!correccionEnCero && (!valor || valor < 1)) { resaltarError('pago-valor', 'Ingrese un valor pagado válido.'); return; }
    if (!banco && !correccionEnCero) { resaltarError('pago-banco', 'Seleccione la cuenta bancaria.'); return; }
    if (!_planoIdActual && !CTX.razonSocialId) { mostrarToast('Seleccione una razón social.','error'); return; }

    const btn = document.getElementById('btn-confirmar-pago');
    btn.disabled = true;
    btn.textContent = '⏳ Procesando...';

    // Usar FormData para soportar el archivo adjunto (multipart/form-data)
    const fd = new FormData();
    fd.append('_token', CTX.csrfToken);
    fd.append('operador', operador);
    fd.append('numero_planilla', numero);
    fd.append('valor', valor);
    fd.append('forma_pago', 'transferencia');
    fd.append('banco_id', banco);
    fd.append('observacion', obs);
    if (fecha) fd.append('fecha_pago', fecha);
    if (soporteFile) fd.append('soporte', soporteFile);

    if (_planoIdActual) {
        fd.append('plano_id', _planoIdActual);
        // En modo individual se requiere también razon_social_id, mes, anio, n_plano (el controlador los necesita si no hay plano_id masivo)
        // El controlador en modo individual no los usa, pero los valida como required
        // → usamos valores del contexto
        fd.append('razon_social_id', CTX.razonSocialId);
        fd.append('mes_plano', CTX.mes);
        fd.append('anio_plano', CTX.anio);
        fd.append('n_plano', CTX.nPlanoFiltro ?? 1);
    } else {
        fd.append('razon_social_id', CTX.razonSocialId);
        fd.append('mes_plano', CTX.mes);
        fd.append('anio_plano', CTX.anio);
        fd.append('n_plano', CTX.nPlanoFiltro ?? 1);
        CTX.modalidadesIds.forEach(id => fd.append('tipos_modalidad[]', id));
    }

    try {
        const resp = await fetch(CTX.routes.confirmarPago, {
            method : 'POST',
            headers: { 'X-CSRF-TOKEN': CTX.csrfToken }, // SIN Content-Type: el browser lo pone con boundary
            body   : fd,
        });
        const data = await resp.json();
        const res = document.getElementById('pago-resultado');
        res.style.display = 'block';

        if (data.ok) {
            let soporteHtml = '';
            if (data.soporte_url) {
                soporteHtml = ` <a href="${data.soporte_url}" target="_blank" style="font-size:.75rem;color:#1d4ed8;text-decoration:underline">Ver soporte 📎</a>`;
            }
            res.innerHTML = `<div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:8px;padding:.65rem .9rem;color:#15803d;font-size:.82rem">✅ ${data.mensaje}${soporteHtml}</div>`;
            mostrarToast(data.mensaje, 'success');
            if (data.pago_parcial) {
                // Pago 1 de 2: el plano sigue pendiente, no se pinta nada.
                setTimeout(() => location.reload(), 2500);
            } else if (_planoIdActual) {
                // Modo individual: actualizar visualmente la fila sin recargar
                // Mismo chip que pinta el partial: el clic abre el menú de la
                // planilla, con el gasto que acaba de crearse por si hay que
                // corregir el número sin recargar.
                const chip = `<span class="chip-planilla" data-num="${numero}"`
                    + ` onclick="abrirAccionesPlanilla(${JSON.stringify(numero)}, ${valor}, ${data.gasto_id ?? 'null'}, '', '')"`
                    + ` title="Planilla: ${numero} (clic para ver opciones)">✅ ${numero}</span>`;
                const tdPlanilla = document.getElementById('planilla-' + _planoIdActual);
                const tdAccion   = document.getElementById('accion-'   + _planoIdActual);
                if (tdPlanilla) tdPlanilla.innerHTML = chip;
                if (tdAccion)   tdAccion.innerHTML   = chip;
                
                const fila = document.getElementById('fila-plano-' + _planoIdActual);
                if (fila) fila.classList.add('ya-pago');
                
                setTimeout(() => cerrarModal('modal-pago'), 1800);
            } else {
                setTimeout(() => location.reload(), 2500);
            }
        } else {
            res.innerHTML = `<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.65rem .9rem;color:#b91c1c;font-size:.82rem">❌ ${data.mensaje}</div>`;
            btn.disabled = false;
            btn.textContent = _planoIdActual ? '✅ CONFIRMAR PAGO' : '✅ CONFIRMAR PAGO PLANILLA';
        }
    } catch(e) {
        mostrarToast('Error de conexión. Intente de nuevo.','error');
        btn.disabled = false;
        btn.textContent = _planoIdActual ? '✅ CONFIRMAR PAGO' : '✅ CONFIRMAR PAGO PLANILLA';
    }
}

// ── Toast ─────────────────────────────────────────────────────────────
// ── Corregir un pago ya confirmado ────────────────────────────────────
// El número de planilla quedó escrito en el gasto y en cada registro del
// plano; el valor solo en el gasto. El backend los mueve juntos.
let _corrGastoId = null;

function abrirCorregirPago(numero, valor, gastoId, nombre) {
    _corrGastoId = gastoId;

    document.getElementById('corr-numero').value = numero ?? '';
    document.getElementById('corr-valor').value  = valor ?? 0;
    document.getElementById('corr-motivo').value = '';
    document.getElementById('corr-numero-viejo').textContent = numero ? '(hoy: ' + numero + ')' : '';

    // En una RS independiente el pago es de una persona: decir de quién evita
    // corregirle el número al de al lado.
    const quien = document.getElementById('corr-quien');
    if (nombre) {
        quien.style.display = 'block';
        quien.innerHTML = '👤 Pago de <strong>' + nombre + '</strong>';
    } else {
        quien.style.display = 'none';
        quien.innerHTML = '';
    }

    const res = document.getElementById('corr-resultado');
    if (res) { res.style.display = 'none'; res.innerHTML = ''; }

    const btn = document.getElementById('btn-corregir-pago');
    btn.disabled = false;
    btn.textContent = '💾 GUARDAR CORRECCIÓN';

    document.getElementById('modal-corregir-pago').classList.add('open');
}

async function ejecutarCorregirPago() {
    const numero = (document.getElementById('corr-numero').value || '').trim();
    const valor  = parseInt(document.getElementById('corr-valor').value || 0, 10);
    const motivo = (document.getElementById('corr-motivo').value || '').trim();
    const res    = document.getElementById('corr-resultado');
    const btn    = document.getElementById('btn-corregir-pago');

    if (!numero)        { mostrarToast('Escribe el número de planilla.', 'error'); return; }
    if (!(valor > 0))   { mostrarToast('El valor pagado tiene que ser mayor que cero.', 'error'); return; }

    const pintar = (html) => { res.style.display = 'block'; res.innerHTML = html; };

    btn.disabled = true;
    btn.textContent = '⏳ Guardando…';

    const fd = new FormData();
    fd.append('gasto_id', _corrGastoId);
    fd.append('numero_planilla', numero);
    fd.append('valor', valor);
    if (motivo) fd.append('motivo', motivo);

    try {
        const resp = await fetch(CTX.routes.corregirPago, {
            method : 'POST',
            headers: { 'X-CSRF-TOKEN': CTX.csrfToken },
            body   : fd,
        });
        const data = await resp.json();

        if (data.ok) {
            pintar(`<div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:8px;padding:.65rem .9rem;color:#15803d;font-size:.82rem">✅ ${data.mensaje}</div>`);
            mostrarToast(data.mensaje, 'success');
            setTimeout(() => location.reload(), 2200);
        } else {
            pintar(`<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.65rem .9rem;color:#b91c1c;font-size:.82rem">❌ ${data.mensaje}</div>`);
            btn.disabled = false;
            btn.textContent = '💾 GUARDAR CORRECCIÓN';
        }
    } catch (e) {
        mostrarToast('Error de conexión. Intente de nuevo.', 'error');
        btn.disabled = false;
        btn.textContent = '💾 GUARDAR CORRECCIÓN';
    }
}

function mostrarToast(msg, tipo='success') {
    const t = document.getElementById('toast-msg');
    t.textContent = msg;
    t.className = `toast ${tipo} show`;
    clearTimeout(t._tmr);
    t._tmr = setTimeout(() => t.classList.remove('show'), 4000);
}
// ── Resaltar campo con error ──────────────────────────────────────────
function resaltarError(fieldId, msg) {
    const el = document.getElementById(fieldId);
    if (el) {
        el.style.borderColor = '#ef4444';
        el.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.18)';
        const limpiar = () => {
            el.style.borderColor = '';
            el.style.boxShadow   = '';
            el.removeEventListener('change', limpiar);
            el.removeEventListener('input',  limpiar);
        };
        el.addEventListener('change', limpiar);
        el.addEventListener('input',  limpiar);
        el.focus();
    }
    mostrarToast(msg, 'error');
}
</script>
@endpush

{{-- ══════════════════════════════════════════════════════════════════════
     MODAL: Resumen de Planos por RS y N_PLANO  (diseño mejorado)
═══════════════════════════════════════════════════════════════════════ --}}
<style>
/* ── Resumen modal ─────────────────────────────────────────────────── */
#modal-resumen-planos .modal-box {
    display:flex; flex-direction:column;
    max-width:720px; width:96vw; max-height:90vh;
    border-radius:14px; overflow:hidden;
}
#modal-resumen-planos .res-head {
    flex-shrink:0;
    background:linear-gradient(135deg,#0a1628 0%,#0d2550 100%);
    color:#e2e8f0; padding:.8rem 1.1rem;
    display:flex; align-items:center; justify-content:space-between;
    gap:.5rem;
}
#modal-resumen-planos .res-head h3 {
    margin:0; font-size:.95rem; font-weight:700; letter-spacing:.01em;
}
#modal-resumen-planos .res-head .res-periodo {
    font-size:.78rem; color:#93c5fd; font-weight:600; margin-left:.4rem;
}
#modal-resumen-planos .res-close {
    background:rgba(255,255,255,.1); border:none; color:#e2e8f0;
    border-radius:6px; width:28px; height:28px; cursor:pointer;
    font-size:.9rem; display:flex; align-items:center; justify-content:center;
    transition:background .15s;
}
#modal-resumen-planos .res-close:hover { background:rgba(255,255,255,.22); }

/* leyenda + aviso: fija debajo del header */
#modal-resumen-planos .res-legend {
    flex-shrink:0;
    padding:.45rem 1rem; background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; font-size:.7rem; color:#475569;
}
#modal-resumen-planos .res-legend .leg-dot {
    width:9px; height:9px; border-radius:50%; display:inline-block; margin-right:.2rem;
}

/* tabla: cabecera sticky dentro del scroll */
#modal-resumen-planos .res-scroll {
    flex:1; overflow-y:auto; overflow-x:hidden;
}
#modal-resumen-planos .res-table {
    width:100%; border-collapse:collapse; font-size:.75rem;
}
#modal-resumen-planos .res-table thead th {
    position:sticky; top:0; z-index:2;
    background:#1e3a5f; color:#cbd5e1;
    padding:.4rem .55rem; font-size:.65rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.04em; white-space:nowrap;
}
#modal-resumen-planos .res-table thead th:first-child { text-align:left; padding-left:.8rem; }
#modal-resumen-planos .res-table thead th:not(:first-child) { text-align:center; }

/* separador de RS */
#modal-resumen-planos .res-table tr.rs-header td {
    padding:.35rem .8rem .2rem;
    background:#f1f5f9;
    border-top:2px solid #cbd5e1;
    font-weight:700; font-size:.72rem; color:#1e293b;
    letter-spacing:.02em;
}
#modal-resumen-planos .res-table tr.rs-header td span.rs-badge {
    margin-left:.4rem; padding:.05rem .35rem; border-radius:4px;
    font-size:.63rem; font-weight:700; vertical-align:middle;
}

/* filas de plano */
#modal-resumen-planos .res-table tr.plano-row td {
    padding:.28rem .55rem; border-bottom:1px solid #f1f5f9;
    vertical-align:middle;
}
#modal-resumen-planos .res-table tr.plano-row td:first-child { padding-left:1.4rem; }
#modal-resumen-planos .res-table tr.plano-row td:not(:first-child) { text-align:center; }
#modal-resumen-planos .res-table tr.plano-row.ok   { background:#f0fdf4; }
#modal-resumen-planos .res-table tr.plano-row.warn { background:#fef2f2; }
#modal-resumen-planos .res-table tr.plano-row.ir   { background:#fefce8; }

/* fila totales global */
#modal-resumen-planos .res-foot {
    flex-shrink:0;
    border-top:2px solid #e2e8f0;
    padding:.55rem 1rem;
    background:#f8fafc;
    display:flex; gap:1.2rem; align-items:center; flex-wrap:wrap; font-size:.78rem;
}
#modal-resumen-planos .res-foot .tot-item { display:flex; flex-direction:column; align-items:center; }
#modal-resumen-planos .res-foot .tot-item .tv { font-size:1.1rem; font-weight:800; line-height:1.1; }
#modal-resumen-planos .res-foot .tot-item .tl { font-size:.63rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
#modal-resumen-planos .res-foot .tot-sep { width:1px; height:32px; background:#e2e8f0; }
#modal-resumen-planos .res-foot .btn-cerrar-res {
    margin-left:auto; padding:.3rem .9rem; border-radius:7px;
    background:#1e3a5f; color:#fff; border:none; font-size:.75rem;
    font-weight:600; cursor:pointer; transition:background .15s;
}
#modal-resumen-planos .res-foot .btn-cerrar-res:hover { background:#0d2550; }
</style>

<div class="modal-overlay" id="modal-resumen-planos">
    <div class="modal-box" style="padding:0;border-radius:14px">

        {{-- CABECERA FIJA --}}
        <div class="res-head">
            <h3>📊 Resumen de Planos <span class="res-periodo" id="res-periodo-lbl"></span></h3>
            <button class="res-close" onclick="cerrarModal('modal-resumen-planos')">✕</button>
        </div>

        {{-- LEYENDA FIJA --}}
        <div class="res-legend">
            <span><span class="leg-dot" style="background:#86efac;border:1px solid #4ade80"></span>Todo pagado</span>
            <span><span class="leg-dot" style="background:#fca5a5;border:1px solid #ef4444"></span>Pendiente</span>
            <span><span class="leg-dot" style="background:#fde047;border:1px solid #ca8a04"></span>IR (P100)</span>
            <span id="res-dia-aviso" style="display:none;color:#92400e;font-weight:700;margin-left:.3rem">
                ⚠️ IR se muestra desde el día 26
            </span>
        </div>

        {{-- ZONA SCROLLABLE --}}
        <div class="res-scroll">
            <div id="res-loading" style="text-align:center;padding:2.5rem;color:#94a3b8;font-size:.85rem">
                ⏳ Cargando…
            </div>
            <table class="res-table" id="res-tabla" style="display:none">
                <thead>
                    <tr>
                        <th style="width:46%">Razón Social / Plano</th>
                        <th style="width:14%">✅ Pagados</th>
                        <th style="width:14%">⏳ Pendientes</th>
                        <th style="width:26%">Estado</th>
                    </tr>
                </thead>
                <tbody id="res-tbody"></tbody>
            </table>
        </div>

        {{-- PIE FIJO CON TOTALES --}}
        <div class="res-foot" id="res-foot" style="display:none">
            <div class="tot-item">
                <span class="tv" id="res-tot-rs"   style="color:#0f172a">—</span>
                <span class="tl">Empresas</span>
            </div>
            <div class="tot-sep"></div>
            <div class="tot-item">
                <span class="tv" id="res-tot-tot"  style="color:#0f172a">—</span>
                <span class="tl">Total registros</span>
            </div>
            <div class="tot-sep"></div>
            <div class="tot-item">
                <span class="tv" id="res-tot-pag"  style="color:#16a34a">—</span>
                <span class="tl">Pagados</span>
            </div>
            <div class="tot-sep"></div>
            <div class="tot-item">
                <span class="tv" id="res-tot-pend" style="color:#dc2626">—</span>
                <span class="tl">Pendientes</span>
            </div>
            <button class="btn-cerrar-res" onclick="cerrarModal('modal-resumen-planos')">Cerrar</button>
        </div>
    </div>
</div>

{{-- Barra de acción masiva flotante --}}
<div id="bar-accion-masiva" style="display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#0a1628;color:#fff;border-radius:12px;padding:.6rem 1.2rem;box-shadow:0 10px 30px rgba(0,0,0,.35);align-items:center;gap:1rem;z-index:9999;border:1px solid rgba(255,255,255,.1)">
    <span style="font-size:.8rem;font-weight:700" id="txt-seleccionados-cant">0 seleccionados</span>
    <div style="width:1px;height:20px;background:rgba(255,255,255,.2)"></div>
    <span style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em">Mover al plano:</span>
    <select id="masivo-plano-nuevo" style="background:#1e293b;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:.2rem .4rem;font-size:.78rem;font-weight:700;outline:none;cursor:pointer">
        @for($np = 1; $np <= 40; $np++)
        <option value="{{ $np }}">P{{ $np }}</option>
        @endfor
    </select>
    <button type="button" onclick="guardarMoverMasivo()" style="background:#10b981;color:#fff;border:none;border-radius:6px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:.3rem" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
        🔄 Mover
    </button>
    <button type="button" onclick="cancelarSeleccionMasiva()" style="background:transparent;color:#94a3b8;border:none;cursor:pointer;font-size:.85rem;padding:.2rem" title="Cancelar selección">✕</button>
</div>

@push('scripts')
<script>
// ── Modal Resumen Planos ──────────────────────────────────────────────
const RES_URL  = '{{ route('admin.planos.api.resumen') }}';
const RES_ANIO = {{ $anio }};
const RES_MES  = {{ $mes }};
const RES_DIA  = {{ $diaHoy }};
const MESES_RES = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function abrirResumenPlanos() {
    document.getElementById('modal-resumen-planos').classList.add('open');
    document.getElementById('res-periodo-lbl').textContent =
        MESES_RES[RES_MES] + ' ' + RES_ANIO;
    document.getElementById('res-dia-aviso').style.display =
        RES_DIA < 26 ? 'inline-flex' : 'none';

    // reset
    document.getElementById('res-loading').style.display = 'block';
    document.getElementById('res-tabla').style.display   = 'none';
    document.getElementById('res-foot').style.display    = 'none';
    document.getElementById('res-tbody').innerHTML       = '';

    fetch(`${RES_URL}?anio=${RES_ANIO}&mes=${RES_MES}`)
        .then(r => r.json())
        .then(json => {
            document.getElementById('res-loading').style.display = 'none';
            renderResumen(json.data, json.dia);
        })
        .catch(() => {
            document.getElementById('res-loading').textContent = '❌ Error al cargar el resumen.';
        });
}

function renderResumen(data, dia) {
    const tbody = document.getElementById('res-tbody');
    if (!data || !data.length) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:2rem;color:#94a3b8">
            No hay planos en este período.</td></tr>`;
        document.getElementById('res-tabla').style.display = 'table';
        return;
    }

    // ── Totales globales ─────────────────────────────────────────────
    let totPend = 0, totPag = 0;
    data.forEach(rs => {
        totPend += rs.total_pend;
        totPag  += rs.total_pag;
    });
    const totTot = totPend + totPag;

    document.getElementById('res-tot-rs').textContent   = data.length;
    document.getElementById('res-tot-tot').textContent  = totTot;
    document.getElementById('res-tot-pag').textContent  = totPag;
    document.getElementById('res-tot-pend').textContent = totPend;
    document.getElementById('res-tot-pend').style.color = totPend > 0 ? '#dc2626' : '#16a34a';

    // ── Filas ────────────────────────────────────────────────────────
    let html = '';

    data.forEach(rs => {
        // Calcular si TODA la RS está pagada (ignorando IR si día < 26)
        const rsPendReal = rs.planos.reduce((s, pl) => {
            const irOculto = pl.n_plano === 100 && dia < 26;
            return s + (irOculto ? 0 : pl.pendientes);
        }, 0);
        const rsTodoPag = rsPendReal === 0 && rs.total_pag > 0;

        // Fila separadora de RS
        const badgeBg    = rsTodoPag ? '#dcfce7' : '#fee2e2';
        const badgeColor = rsTodoPag ? '#15803d' : '#dc2626';
        const badgeTxt   = rsTodoPag ? '✅ Pagado' : `⏳ ${rsPendReal} pend.`;

        html += `<tr class="rs-header">
            <td colspan="4">
                ${rs.razon_social.toUpperCase()}
                <span class="rs-badge" style="background:${badgeBg};color:${badgeColor}">${badgeTxt}</span>
            </td>
        </tr>`;

        // Filas de cada n_plano de esta RS
        rs.planos.forEach(pl => {
            const esIR     = pl.n_plano === 100;
            const irOculto = esIR && dia < 26;
            const pendReal = irOculto ? 0 : pl.pendientes;
            const todoPag  = pl.pagados > 0 && pendReal === 0;
            const hayPend  = pendReal > 0;

            let rowCls = '', estadoHtml = '', plLabel = '';

            // badge plano
            if (esIR) {
                plLabel = `<span style="background:#fef9c3;color:#92400e;border:1px solid #fde047;
                           border-radius:4px;padding:.06rem .35rem;font-size:.65rem;font-weight:700">
                           P100 IR</span>`;
            } else {
                plLabel = `<span style="font-weight:700;color:#1d4ed8;font-size:.78rem">P${pl.n_plano}</span>`;
            }

            // estado y color fila
            if (todoPag) {
                rowCls = 'ok';
                estadoHtml = `<span style="color:#15803d;font-weight:700;font-size:.73rem">✅ Pagado</span>`;
            } else if (hayPend && !esIR) {
                rowCls = 'warn';
                estadoHtml = `<span style="color:#dc2626;font-weight:700;font-size:.73rem">⏳ ${pendReal} pendiente${pendReal>1?'s':''}</span>`;
            } else if (esIR) {
                rowCls = irOculto ? '' : 'ir';
                estadoHtml = irOculto
                    ? `<span style="color:#94a3b8;font-size:.67rem">🕐 desde día 26</span>`
                    : `<span style="color:#b45309;font-weight:700;font-size:.73rem">⚠️ ${pendReal} pend.</span>`;
            } else {
                estadoHtml = `<span style="color:#94a3b8">—</span>`;
            }

            const pagCol  = pl.pagados   > 0
                ? `<strong style="color:#15803d">${pl.pagados}</strong>`   : `<span style="color:#cbd5e1">—</span>`;
            const pendCol = pl.pendientes > 0
                ? `<strong style="color:#dc2626">${pl.pendientes}</strong>` : `<span style="color:#cbd5e1">—</span>`;

            html += `<tr class="plano-row ${rowCls}">
                <td>${plLabel}</td>
                <td>${pagCol}</td>
                <td>${pendCol}</td>
                <td>${estadoHtml}</td>
            </tr>`;
        });
    });

    tbody.innerHTML = html;
    document.getElementById('res-tabla').style.display = 'table';
    document.getElementById('res-foot').style.display  = 'flex';
}

// ── Selección y Movimiento Masivo de Planos ───────────────────────────
function toggleSeleccionMasiva(activo) {
    const th = document.getElementById('th-numero-plano');
    const celdas = document.querySelectorAll('.td-numero-plano');
    const bar = document.getElementById('bar-accion-masiva');

    if (activo) {
        // Cabecera: cambiar '#' por un checkbox de seleccionar todos
        th.innerHTML = '<input type="checkbox" id="chk-seleccionar-todos" onchange="seleccionarTodosPlanos(this.checked)" style="width:.95rem;height:.95rem;cursor:pointer;margin:0 auto;display:block">';
        
        // Celdas: cambiar el número secuencial por un checkbox
        celdas.forEach(td => {
            const id = td.getAttribute('data-id');
            const num = td.getAttribute('data-numero');
            td.innerHTML = `<input type="checkbox" class="chk-registro-plano" value="${id}" onchange="actualizarBotonMoverMasivo(event)" style="width:.95rem;height:.95rem;cursor:pointer;margin:0 auto;display:block">`;
            td.style.textAlign = 'center';
            td.title = 'Seleccionar para mover plano';
        });

        // Mostrar la barra si hay seleccionados
        actualizarBotonMoverMasivo();
    } else {
        // Restaurar cabecera
        th.innerHTML = '#';

        // Restaurar celdas con su número original
        celdas.forEach(td => {
            const num = td.getAttribute('data-numero');
            td.innerHTML = num;
            td.style.textAlign = 'left';
            td.title = `Clic para mover este registro a otro plano (Plano actual: P${td.getAttribute('data-nplano')})`;
        });

        // Ocular barra flotante y desmarcar todos
        bar.style.display = 'none';
        const chkTodos = document.getElementById('chk-seleccionar-todos');
        if (chkTodos) chkTodos.checked = false;
    }
}

function seleccionarTodosPlanos(checked) {
    const checkboxes = document.querySelectorAll('.chk-registro-plano');
    checkboxes.forEach(chk => {
        chk.checked = checked;
    });
    actualizarBotonMoverMasivo();
}

function actualizarBotonMoverMasivo(event) {
    if (event) {
        event.stopPropagation();
    }
    const checkboxes = document.querySelectorAll('.chk-registro-plano');
    const seleccionados = Array.from(checkboxes).filter(chk => chk.checked);
    const bar = document.getElementById('bar-accion-masiva');
    const txtCant = document.getElementById('txt-seleccionados-cant');

    if (seleccionados.length > 0) {
        txtCant.textContent = `${seleccionados.length} ${seleccionados.length === 1 ? 'seleccionado' : 'seleccionados'}`;
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
        const chkTodos = document.getElementById('chk-seleccionar-todos');
        if (chkTodos) chkTodos.checked = false;
    }
}

function cancelarSeleccionMasiva() {
    document.getElementById('chk-seleccion-masiva').checked = false;
    toggleSeleccionMasiva(false);
}

function manejarClicCeldaNumero(td, event) {
    const isMasiva = document.getElementById('chk-seleccion-masiva').checked;
    const id = td.getAttribute('data-id');
    const nplano = td.getAttribute('data-nplano');
    
    if (!isMasiva) {
        abrirModalMover(id, parseInt(nplano));
    } else {
        // Si el clic no fue directamente en el checkbox, lo alternamos
        const chk = td.querySelector('.chk-registro-plano');
        if (chk && event.target !== chk) {
            chk.checked = !chk.checked;
            actualizarBotonMoverMasivo();
        }
    }
}

async function guardarMoverMasivo() {
    const checkboxes = document.querySelectorAll('.chk-registro-plano');
    const ids = Array.from(checkboxes).filter(chk => chk.checked).map(chk => parseInt(chk.value));
    const nPlano = parseInt(document.getElementById('masivo-plano-nuevo').value);

    if (ids.length === 0) {
        mostrarToast('Debe seleccionar al menos un registro.', 'error');
        return;
    }
    if (!nPlano || nPlano < 1) {
        mostrarToast('N_PLANO inválido.', 'error');
        return;
    }

    try {
        const resp = await fetch(`/admin/planos/mover-masivo`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CTX.csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ ids: ids, n_plano: nPlano })
        });
        const data = await resp.json();
        if (data.ok) {
            mostrarToast(data.mensaje, 'success');
            cancelarSeleccionMasiva();
            setTimeout(() => location.reload(), 700);
        } else {
            mostrarToast(data.mensaje || 'Error al mover los registros.', 'error');
        }
    } catch (e) {
        mostrarToast('Error de conexión.', 'error');
    }
}

// ── Ordenamiento interactivo de tabla de planos ──────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('.tabla-planos');
    if (!table) return;

    const headers = table.querySelectorAll('thead th.sortable');
    const tbody = table.querySelector('tbody');

    headers.forEach(header => {
        header.addEventListener('click', function () {
            const columnIndex = Array.from(header.parentNode.children).indexOf(header);
            const isAsc = header.classList.contains('asc');
            const direction = isAsc ? 'desc' : 'asc';

            // Limpiar clases en todos los headers
            headers.forEach(h => h.classList.remove('asc', 'desc'));
            header.classList.add(direction);

            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                const cellA = a.children[columnIndex];
                const cellB = b.children[columnIndex];
                if (!cellA || !cellB) return 0;

                const valA = (cellA.getAttribute('data-order') || cellA.textContent).trim();
                const valB = (cellB.getAttribute('data-order') || cellB.textContent).trim();

                // Manejo de vacíos: colocar siempre al final
                const isEmptyA = valA === '' || valA === '—';
                const isEmptyB = valB === '' || valB === '—';
                if (isEmptyA && !isEmptyB) return 1;
                if (!isEmptyA && isEmptyB) return -1;
                if (isEmptyA && isEmptyB) return 0;

                // Comprobar si son números
                const numA = parseFloat(valA);
                const numB = parseFloat(valB);
                const isNumA = !isNaN(numA) && /^-?[0-9.]+$/.test(valA);
                const isNumB = !isNaN(numB) && /^-?[0-9.]+$/.test(valB);

                if (isNumA && isNumB) {
                    return direction === 'asc' ? numA - numB : numB - numA;
                }

                // Ordenamiento de texto en español
                return direction === 'asc'
                    ? valA.localeCompare(valB, 'es', { numeric: true, sensitivity: 'base' })
                    : valB.localeCompare(valA, 'es', { numeric: true, sensitivity: 'base' });
            });

            // Reinsertar las filas ordenadas en el tbody
            rows.forEach(row => tbody.appendChild(row));

            // Re-numerar la primera columna secuencial (#) si no está activo el modo de selección masiva
            let i = 1;
            tbody.querySelectorAll('tr').forEach(row => {
                const firstCell = row.querySelector('td');
                if (firstCell) {
                    // Actualizar el atributo data-numero
                    firstCell.setAttribute('data-numero', i);
                    // Solo sobreescribir con texto si no estamos en selección masiva
                    const isMasiva = document.getElementById('chk-seleccion-masiva').checked;
                    if (!isMasiva) {
                        firstCell.textContent = i;
                    }
                    i++;
                }
            });
        });
    });
});

// ── Estado del operador al cargar la página ──────────────────────────
// Antes solo se consultaba dentro de abrirModalDescarga(), así que la barra
// de totales de arriba nunca se enteraba de que la tanda ya estaba liquidada:
// seguía mostrando su propia estimación —"Sin mora", $0— aunque el operador
// ya hubiera cobrado. Se consulta también al entrar, que es cuando el usuario
// mira ese bloque. Al abrir el modal se vuelve a consultar para refrescar.
if (CTX.razonSocialId) cargarEstadoEnlace();
</script>
@endpush
