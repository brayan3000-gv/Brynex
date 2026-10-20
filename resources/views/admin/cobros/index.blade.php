@extends('layouts.app')
@section('modulo', 'Cobros')

@push('styles')
<style>
/* ── Cobros: layout de altura completa, solo tbody scrollea ── */
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
</style>
@endpush

@php
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fmt   = fn($v) => '$' . number_format($v ?? 0, 0, ',', '.');
$semLabel = fn($s) => match($s) {
    'verde'    => ['🟢', '#15803d', '#dcfce7', 'Llamado reciente'],
    'amarillo' => ['🟡', '#92400e', '#fef3c7', '3–7 días sin llamar'],
    'rojo'     => ['🔴', '#b91c1c', '#fee2e2', 'Más de 7 días'],
    default    => ['⬜', '#64748b', '#f1f5f9', 'Sin llamadas'],
};
$estadoFact = fn($e) => match($e) {
    'pagada'      => ['Pagada',      '#15803d', '#dcfce7'],
    'abono'       => ['Abono',       '#92400e', '#fef3c7'],
    'prestamo'    => ['Préstamo',    '#6d28d9', '#ede9fe'],
    'pre_factura' => ['Pre-factura', '#64748b', '#f1f5f9'],
    default       => [ucfirst($e ?? '—'), '#64748b', '#f1f5f9'],
};
// Closures y no `function`: una función con nombre queda declarada para todo
// el proceso, y renderizar la vista dos veces en el mismo (tinker, un job, un
// test) moría con "Cannot redeclare sortUrlC()".
$sortUrlC = function ($col, $cs, $cd) {
    $d = ($cs===$col && $cd==='asc') ? 'desc' : 'asc';
    $q = request()->except(['sort','dir']); $q['sort']=$col; $q['dir']=$d;
    return url()->current().'?'.http_build_query($q);
};
$sortClassC = function ($col, $cs, $cd) {
    if($cs!==$col) return ''; return $cd==='asc'?'sort-asc':'sort-desc';
};
@endphp

@section('contenido')



<style>
/* ── Layout ── */
.cob-wrap {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    overflow: hidden;
}

/* ── Header ── */
.cob-header {
    background: linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#1e40af 100%);
    border-radius:12px; padding:0.6rem 1.2rem; color:#fff;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
}
.cob-title { font-size:1.2rem; font-weight:800; letter-spacing:.02em; }
.cob-sub   { font-size:.72rem; color:#94a3b8; margin-top:.15rem; }

/* ── Filtros ── */
.filtros {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:0.4rem 0.8rem; display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;
}
.filtros select, .filtros input {
    padding:.38rem .7rem; border:1px solid #cbd5e1; border-radius:8px;
    font-size:.81rem; outline:none; background:#fff; color:#0f172a;
}
.filtros select:focus, .filtros input:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.12); }
.btn-filtrar {
    padding:.38rem .95rem; background:#1e40af; color:#fff; border:none;
    border-radius:8px; font-size:.81rem; font-weight:600; cursor:pointer; transition:background .15s;
}
.btn-filtrar:hover { background:#1d4ed8; }
.btn-limpiar {
    padding:.38rem .8rem; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;
    border-radius:8px; font-size:.81rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center;
}
.fil-sep { width:1px; height:22px; background:#e2e8f0; }

/* ── Tabla ── */
.tbl-wrap {
    overflow-x: auto;
    overflow-y: auto;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #fff;
    flex: 1;
    min-height: 0;
}
.tbl-cob  { width:100%; border-collapse:collapse; font-size:.775rem; white-space:nowrap; }
.tbl-cob thead th {
    background:#0f172a; color:#fff; padding:.5rem .55rem;
    font-weight:600; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em;
    position:sticky; top:0; z-index:2;
}
.tbl-cob thead th a { color:#cbd5e1; text-decoration:none; display:flex; align-items:center; gap:.2rem; justify-content:center; }
.tbl-cob thead th a:hover { color:#fff; }
.tbl-cob thead th a.sort-asc::after  { content:'\2191'; color:#3b82f6; margin-left:.15rem; }
.tbl-cob thead th a.sort-desc::after { content:'\2193'; color:#3b82f6; margin-left:.15rem; }
/* th-select */
.th-select {
    width:100%; background:transparent; border:none; border-bottom:1px solid rgba(255,255,255,.15);
    color:#fff; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    padding:.2rem .2rem; cursor:pointer; outline:none; appearance:auto; -webkit-appearance:auto;
}
.th-select:hover { border-bottom-color:rgba(255,255,255,.5); }
.th-select:focus { border-bottom-color:#3b82f6; }
.th-select option { background:#0f172a; color:#fff; font-weight:600; text-transform:none; }
.th-select.activo { border-bottom-color:#3b82f6; color:#93c5fd; }

.tbl-cob tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.tbl-cob tbody tr:hover { background:#f8fafc; }
.tbl-cob td { padding:.42rem .52rem; vertical-align:middle; }

/* Badges */
.badge-tipo { display:inline-flex; align-items:center; gap:.2rem; padding:.18rem .45rem; border-radius:20px; font-size:.63rem; font-weight:700; }
.badge-afil { background:#ede9fe; color:#6d28d9; }
.badge-plan { background:#dbeafe; color:#1e40af; }
.badge-fact { display:inline-flex; align-items:center; padding:.16rem .45rem; border-radius:20px; font-size:.63rem; font-weight:700; }

/* Semáforo */
.sem-dot { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem; font-weight:600; }
.sem-dias { font-size:.62rem; color:#94a3b8; margin-left:.2rem; }

/* Botones acción */
.btn-llamar {
    padding:.25rem .6rem; border-radius:7px; font-size:.72rem; font-weight:700;
    cursor:pointer; border:none; transition:all .15s;
    background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff;
    display:inline-flex; align-items:center; gap:.25rem;
}
.btn-llamar:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(37,99,235,.3); }

.razon-badge {
    font-weight:700; font-size:.68rem; padding:.16rem .48rem; border-radius:6px;
    background:#dbeafe; color:#1e40af; display:inline-block; max-width:120px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.num-mono { font-family:monospace; font-size:.77rem; }

/* ── Filas del listado ────────────────────────────────────────────────
   Todo esto iba en style="" dentro de cada celda de cada fila: 22% del peso
   de la tabla (983 KB con 152 filas en el aliado 6). Mismas declaraciones,
   con `.tbl-cob` delante para que ganen como ganaba el estilo en línea. */
.tbl-cob tbody tr.fila-retiro,   .tbl-cob tbody tr.fila-retiro:hover   { background:linear-gradient(90deg,#fff0f0 0%,#fff5f5 100%); border-left:3px solid #dc2626; }
.tbl-cob tbody tr.fila-retpost,  .tbl-cob tbody tr.fila-retpost:hover  { background:linear-gradient(90deg,#fdf2f8 0%,#fef9fb 100%); border-left:3px solid #9333ea; }
.tbl-cob tbody tr.fila-iralerta, .tbl-cob tbody tr.fila-iralerta:hover { background:linear-gradient(90deg,#fff7ed 0%,#fffbf7 100%); border-left:3px solid #f97316; }
.tbl-cob .c-ctr     { text-align:center; }
.tbl-cob .c-ctr72   { text-align:center; font-size:.72rem; }
.tbl-cob .c-nowrap  { white-space:nowrap; }
.tbl-cob .c-acc     { text-align:center; white-space:nowrap; }
.tbl-cob .c-id      { text-align:center; font-weight:700; color:#1e40af; font-size:.72rem; }
.tbl-cob .c-ced     { background:none; border:none; color:#3b82f6; font-weight:700; cursor:pointer; padding:0; font-family:monospace; font-size:.77rem; text-decoration:underline dotted; }
.tbl-cob .c-nom     { font-weight:600; color:#1e3a5f; max-width:140px; line-height:1.2; }
.tbl-cob .c-nom-l   { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tbl-cob .c-prest   { display:inline-block; margin-top:.15rem; padding:.08rem .35rem; border-radius:20px; font-size:.58rem; font-weight:700; background:#ede9fe; color:#6d28d9; text-decoration:none; }
.tbl-cob .c-cel     { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem; color:#334155; font-family:monospace; font-weight:600; }
.tbl-cob .c-wa      { text-decoration:none; line-height:1; display:inline-flex; }
.tbl-cob .c-vacio   { color:#cbd5e1; font-size:.7rem; }
.tbl-cob .c-gris    { color:#cbd5e1; }
.tbl-cob .c-fecha   { text-align:center; font-size:.72rem; color:#64748b; }
.tbl-cob .c-mod     { text-align:center; font-size:.72rem; font-weight:700; }
.tbl-cob .c-admon   { font-weight:600; color:#0f172a; }
.tbl-cob .c-total   { font-weight:700; color:#1e40af; }
.tbl-cob .c-pill    { display:inline-block; padding:.15rem .45rem; border-radius:20px; font-size:.62rem; font-weight:700; }
.tbl-cob .c-emp     { background:#dbeafe; color:#1e40af; max-width:90px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
.tbl-cob .c-ind     { background:#f0fdf4; color:#15803d; }
.tbl-cob .c-mora    { display:inline-block; padding:.12rem .42rem; border-radius:20px; font-size:.62rem; font-weight:700; background:#fef3c7; color:#92400e; }
.tbl-cob .c-fact    { display:inline-block; padding:.15rem .5rem; border-radius:20px; font-size:.62rem; font-weight:700; text-decoration:none; }
.tbl-cob .c-pl      { font-weight:700; padding:0.15rem 0.45rem; border-radius:6px; font-size:0.68rem; display:inline-block; }
.tbl-cob .c-pl-afil { background:#f3e8ff; color:#6b21a8; border: 1px solid #d8b4fe; }
.tbl-cob .c-pl-ok   { background:#dcfce7; color:#166534; border: 1px solid #bbf7d0; }
.tbl-cob .c-pl-ret  { background:#fee2e2; color:#dc2626; border: 1px solid #fca5a5; }
.tbl-cob .c-pl-inf  { background:#fee2e2; color:#b91c1c; border: 1px solid #fca5a5; font-size:0.65rem; }
.tbl-cob .c-np      { color:#475569; font-weight:600; }
.tbl-cob .c-np-ret  { color:#dc2626; font-weight:600; }
.tbl-cob .c-gres    { font-size:.7rem; font-weight:600; color:#334155; }
.tbl-cob .c-gobs    { font-size:.66rem; color:#64748b; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tbl-cob .c-retok   { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; background:#fee2e2; color:#dc2626; font-size:.75rem; }
.tbl-cob .c-recibo  { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#0f766e,#14b8a6); color:#fff; font-size:.9rem; text-decoration:none; cursor:pointer; }

/* ── Modal llamada ── */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; padding:1.4rem; max-width:520px; width:95%; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.22); animation:mIn .18s ease; }
.modal-box.wide { max-width:640px; }
@keyframes mIn { from{transform:translateY(-18px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-title  { font-size:.97rem; font-weight:800; color:#0f172a; margin-bottom:1rem; padding-bottom:.55rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.modal-close  { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8; padding:0; line-height:1; }
.modal-close:hover { color:#ef4444; }
.form-grp { display:flex; flex-direction:column; gap:.22rem; margin-bottom:.75rem; }
.form-grp label { font-size:.7rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.form-grp select, .form-grp textarea, .form-grp input {
    padding:.46rem .65rem; border:1px solid #cbd5e1; border-radius:8px;
    font-size:.85rem; outline:none; font-family:inherit;
}
.form-grp select:focus, .form-grp textarea:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.1); }
.form-grp textarea { resize:vertical; min-height:80px; }
.btn-save {
    background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff; border:none;
    border-radius:10px; padding:.58rem 1.5rem; font-size:.88rem; font-weight:700;
    cursor:pointer; box-shadow:0 3px 10px rgba(37,99,235,.3); transition:all .15s; width:100%;
}
.btn-save:hover { transform:translateY(-1px); box-shadow:0 5px 15px rgba(37,99,235,.4); }
.info-box {
    background:#f0f9ff; border-radius:9px; padding:.55rem .85rem;
    margin-bottom:.85rem; display:flex; flex-wrap:wrap; gap:.6rem; font-size:.77rem;
}
.info-box strong { color:#0f172a; }
.info-box span   { color:#64748b; }
.timeline { position:relative; padding-left:1.4rem; }
.timeline::before { content:''; position:absolute; left:.45rem; top:0; bottom:0; width:2px; background:#e2e8f0; }
.tl-item { position:relative; margin-bottom:.9rem; }
.tl-item::before { content:''; position:absolute; left:-1.05rem; top:.28rem; width:9px; height:9px; border-radius:50%; border:2px solid #3b82f6; background:#fff; }
.tl-date { font-size:.66rem; color:#94a3b8; }
.tl-user { font-size:.7rem; font-weight:700; color:#1e40af; }
.tl-obs  { font-size:.78rem; color:#334155; margin-top:.15rem; }
.tl-res  { font-size:.68rem; font-weight:700; padding:.12rem .4rem; border-radius:5px; background:#f0fdf4; color:#15803d; display:inline-block; margin-top:.15rem; }
.toast {
    position:fixed; bottom:1.2rem; right:1.2rem; z-index:9999;
    padding:.65rem 1.2rem; border-radius:10px; font-weight:600; font-size:.85rem;
    box-shadow:0 4px 16px rgba(0,0,0,.15); animation:toastIn .25s ease; display:none;
}
.toast.show { display:block; }
.toast.success { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.toast.error   { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
@keyframes toastIn { from{transform:translateY(10px);opacity:0} to{transform:translateY(0);opacity:1} }


.btn-cc-ind {
    padding:.25rem .55rem; border-radius:7px; font-size:.72rem; font-weight:700;
    cursor:pointer; border:none; transition:all .15s;
    background:linear-gradient(135deg,#065f46,#059669); color:#fff;
    display:inline-flex; align-items:center; gap:.2rem; margin-left:.2rem;
}
.btn-cc-ind:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(5,150,105,.3); }

/* Modal Cuenta de Cobro Individual */
.modal-cc-bg {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1100;
    align-items:center; justify-content:center; backdrop-filter:blur(3px);
}
.modal-cc-bg.open { display:flex; }
.modal-cc-box {
    background:#fff; border-radius:16px; width:min(660px,96vw);
    max-height:92vh; overflow:hidden; display:flex; flex-direction:column;
    box-shadow:0 24px 80px rgba(0,0,0,.28); animation:mIn .18s ease;
}
.cc-bar {
    background:linear-gradient(135deg,#065f46,#059669); padding:.65rem 1rem;
    display:flex; align-items:center; gap:.5rem; flex-shrink:0;
}
.cc-bar-title { color:#fff; font-weight:800; font-size:.88rem; flex:1; }
.cc-toggle {
    padding:.22rem .65rem; border-radius:6px; border:none; cursor:pointer;
    font-size:.72rem; font-weight:700; transition:all .15s;
}
.cc-toggle.active   { background:#fff; color:#065f46; }
.cc-toggle.inactive { background:rgba(255,255,255,.2); color:#d1fae5; }
.cc-toggle.inactive:hover { background:rgba(255,255,255,.35); }
.cc-body { overflow-y:auto; flex:1; padding:1.2rem; font-family:'Arial',sans-serif; font-size:11px; }
.cc-doc-title {
    text-align:center; font-size:.95rem; font-weight:900;
    letter-spacing:.1em; text-transform:uppercase;
    border-top:2px solid #065f46; border-bottom:2px solid #065f46;
    padding:.3rem 0; margin-bottom:.9rem; color:#065f46;
}
.cc-header-row { display:flex; justify-content:space-between; margin-bottom:.8rem; font-size:10.5px; }
.cc-client-block { line-height:1.7; }
.cc-client-block strong { font-size:12px; color:#0f172a; display:block; }
.cc-client-block span { color:#475569; }
.cc-period { text-align:right; color:#475569; font-size:10px; }
.cc-period strong { display:block; font-size:12px; color:#065f46; }
/* Tabla de entidades */
.cc-tbl { width:100%; border-collapse:collapse; margin-bottom:.8rem; }
.cc-tbl th {
    background:#065f46; color:#fff; padding:.35rem .5rem;
    font-size:9px; text-transform:uppercase; letter-spacing:.04em; text-align:left;
}
.cc-tbl td { padding:.32rem .5rem; border-bottom:1px solid #f1f5f9; font-size:10.5px; vertical-align:middle; }
.cc-tbl tr:nth-child(even) td { background:#f8fafc; }
.cc-tbl .cc-label { color:#475569; font-size:9.5px; font-weight:600; width:90px; }
.cc-tbl .cc-entity { font-weight:600; color:#0f172a; }
.cc-tbl .cc-val { text-align:right; font-family:monospace; font-weight:700; color:#1e40af; white-space:nowrap; }
/* Totales */
.cc-totales { display:flex; flex-direction:column; gap:.2rem; align-items:flex-end; margin-bottom:.85rem; }
.cc-tot-row { display:flex; gap:.5rem; font-size:11px; }
.cc-tot-label { min-width:160px; text-align:right; color:#475569; }
.cc-tot-val { min-width:100px; text-align:right; font-family:monospace; font-weight:700; color:#0f172a; }
.cc-tot-row.principal { border-top:2px solid #065f46; padding-top:.3rem; margin-top:.2rem; }
.cc-tot-row.principal .cc-tot-label { font-size:12px; font-weight:800; color:#065f46; }
.cc-tot-row.principal .cc-tot-val   { font-size:13px; font-weight:900; color:#065f46; }
.cc-mora-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    background:#fef3c7; color:#92400e; border-radius:8px;
    padding:.3rem .75rem; font-size:10.5px; font-weight:700; margin-bottom:.55rem;
}
/* Cuentas bancarias */
.cc-banco-item {
    border-left:4px solid #2563eb; background:#eff6ff;
    border-radius:0 8px 8px 0; padding:.55rem .9rem;
    margin-bottom:.4rem; font-size:10.5px;
    border-top:1px solid #bfdbfe; border-bottom:1px solid #bfdbfe;
}
.cc-banco-nombre { font-weight:900; color:#1e3a5f; font-size:12px; }
.cc-banco-num    { font-family:monospace; font-weight:700; color:#1d4ed8; font-size:12px; letter-spacing:.03em; }
.cc-banco-tipo   { background:#dbeafe; color:#1e40af; font-size:9px; font-weight:700; padding:.1rem .35rem; border-radius:8px; }
.cc-nota {
    background:#fee2e2; border:1px solid #fca5a5; border-radius:6px;
    padding:.45rem .7rem; font-size:9px; color:#7f1d1d; line-height:1.5; margin-top:.5rem;
}
.cc-footer-bar {
    border-top:1px solid #e2e8f0; padding:.55rem 1rem;
    display:flex; gap:.5rem; justify-content:flex-end; flex-shrink:0; background:#f8fafc;
}

.cc-doc-header {
    background:linear-gradient(135deg,#064e3b,#065f46);
    padding:1.4rem 1.6rem 1.1rem; color:#fff;
}
.cc-doc-periodo { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.1em; color:#6ee7b7; margin-bottom:.3rem; }
.cc-doc-nombre { font-size:1.35rem; font-weight:900; letter-spacing:.01em; margin-bottom:.25rem; text-shadow:0 1px 3px rgba(0,0,0,.2); }
.cc-doc-meta { font-size:.82rem; color:#a7f3d0; line-height:1.7; }
.cc-doc-meta strong { color:#fff; font-weight:700; }
.cc-doc-rs { display:inline-block; background:rgba(255,255,255,.15); border-radius:6px; padding:.15rem .55rem; font-size:.78rem; font-weight:700; color:#d1fae5; margin-top:.3rem; }
.cc-main { padding:1.2rem 1.4rem; display:flex; flex-direction:column; gap:1rem; }
.cc-section-title { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:.5rem; display:flex; align-items:center; gap:.4rem; }
.cc-section-title::after { content:''; flex:1; height:1px; background:#e2e8f0; }
.cc-cards { display:grid; grid-template-columns:1fr 1fr; gap:.35rem; align-items:stretch; }
.cc-card { background:#fff; border-radius:12px; padding:.7rem .9rem; border:1.5px solid #e2e8f0; display:flex; align-items:center; gap:.65rem; transition:border-color .15s; box-sizing:border-box; min-width:0; overflow:hidden; }
.cc-card:hover { border-color:#059669; }
.cc-card-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.cc-card-info { flex:1; min-width:0; }
.cc-card-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; }
.cc-card-entity { font-size:.88rem; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cc-card-entity.ninguna { color:#cbd5e1; font-weight:500; font-style:italic; }
.cc-card-val { font-size:.95rem; font-weight:800; color:#1e40af; font-family:monospace; white-space:nowrap; flex-shrink:0; }
.cc-nivel-badge { display:inline-block; background:#dbeafe; color:#1e40af; font-size:.6rem; font-weight:800; padding:.1rem .35rem; border-radius:5px; margin-left:.3rem; vertical-align:middle; }
.cc-plan-row { background:#fff; border-radius:12px; padding:.7rem 1rem; border:1.5px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.cc-plan-badge { background:linear-gradient(135deg,#ede9fe,#ddd6fe); color:#5b21b6; font-size:.78rem; font-weight:800; padding:.25rem .7rem; border-radius:20px; }
.cc-tipo-badge { background:#f0fdf4; color:#15803d; font-size:.78rem; font-weight:700; padding:.25rem .7rem; border-radius:20px; border:1px solid #bbf7d0; }
.cc-total-block { background:linear-gradient(135deg,#064e3b,#065f46); border-radius:14px; padding:1.1rem 1.4rem; display:flex; align-items:center; justify-content:space-between; }
.cc-total-label { color:#a7f3d0; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.25rem; }
.cc-total-amount { color:#fff; font-size:2rem; font-weight:900; font-family:monospace; text-shadow:0 2px 8px rgba(0,0,0,.3); }
.cc-total-subtotals { display:flex; flex-direction:column; gap:.2rem; align-items:flex-end; }
.cc-sub-row { font-size:.75rem; color:#6ee7b7; display:flex; gap:.5rem; }
.cc-sub-row span:last-child { font-weight:700; color:#a7f3d0; }
.cc-mora-block { background:linear-gradient(135deg,#7c2d12,#92400e); border-radius:12px; padding:.75rem 1.1rem; display:flex; align-items:center; justify-content:space-between; }
.cc-mora-label { color:#fed7aa; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.cc-mora-amount { color:#fff; font-size:1.25rem; font-weight:900; font-family:monospace; }
.cc-mora-dias { color:#fed7aa; font-size:.72rem; font-weight:600; margin-top:.15rem; }
.cc-banco-card { background:#fff; border-radius:12px; padding:.7rem .9rem; border:1.5px solid #bfdbfe; display:flex; align-items:center; gap:.9rem; box-sizing:border-box; height:100%; margin-bottom:0; }
.cc-banco-icon { width:42px; height:42px; background:linear-gradient(135deg,#1e40af,#2563eb); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.cc-banco-info { flex:1; }
.cc-banco-nombre2 { font-size:1rem; font-weight:800; color:#1e3a5f; }
.cc-banco-tipo2 { display:inline-block; background:#dbeafe; color:#1e40af; font-size:.65rem; font-weight:700; padding:.1rem .4rem; border-radius:5px; margin-left:.4rem; }
.cc-banco-num2 { font-size:1.1rem; font-family:monospace; font-weight:800; color:#1d4ed8; letter-spacing:.04em; margin-top:.15rem; }
.cc-banco-nit { font-size:.72rem; color:#94a3b8; margin-top:.1rem; }
.cc-nota2 { background:#fff3cd; border-radius:10px; padding:.6rem .9rem; font-size:.72rem; color:#7c5b00; line-height:1.6; border:1px solid #ffe082; box-sizing:border-box; height:100%; }
.cc-footer-bar { border-top:1px solid #e2e8f0; padding:.65rem 1.1rem; display:flex; gap:.5rem; justify-content:flex-end; flex-shrink:0; background:#fff; }
.btn-cc-print { padding:.42rem 1.2rem; border-radius:9px; border:none; cursor:pointer; background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff; font-size:.82rem; font-weight:700; display:flex; align-items:center; gap:.35rem; box-shadow:0 3px 10px rgba(37,99,235,.3); transition:all .15s; }
.btn-cc-print:hover { transform:translateY(-1px); box-shadow:0 5px 15px rgba(37,99,235,.4); }

/* ── Impresión: solo el contenido de la cuenta de cobro ── */
@media print {
    body > *:not(#cc-print-area) { display:none !important; }
    #cc-print-area { display:block !important; position:fixed; inset:0; background:#fff; padding:1rem 1.5rem; z-index:99999; }
    .modal-cc-bg { display:none !important; }
}

/* Responsive */
@media(max-width:768px) {
    .cards-row { grid-template-columns:1fr 1fr; }
}
@media(max-width:1100px) {
    .cards-row { grid-template-columns:repeat(3,1fr); }
}
</style>

<div class="cob-wrap">

{{-- ══ HEADER ══ --}}
<form method="GET" action="{{ route('admin.cobros.index') }}" id="formFiltros">
<div class="cob-header">
    <div>
        <div class="cob-title">💰 Módulo de Cobros</div>
        <div class="cob-sub">Gestión de cartera pendiente · {{ $meses[$mes] }} {{ $anio }}</div>
    </div>
    <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
        {{-- Tabs navegación --}}
        <a href="{{ route('admin.cobros.index', ['mes' => $mes, 'anio' => $anio]) }}"
           style="padding:.3rem .8rem;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;background:#ffffff;color:#0f172a;border:1px solid rgba(255,255,255,.3);">
            👤 Individuales
        </a>
        <a href="{{ route('admin.cobros.empresas', ['mes' => $mes, 'anio' => $anio]) }}"
           style="padding:.3rem .8rem;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;background:rgba(255,255,255,.15);color:#cbd5e1;border:1px solid rgba(255,255,255,.15);">
            🏢 Empresas
        </a>
        <span style="width:1px;height:22px;background:rgba(255,255,255,.2);display:inline-block;"></span>
        <select name="mes" onchange="this.form.submit()" style="font-size:.8rem;padding:.3rem .5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;">
            @foreach($meses as $i => $m)
            @if($i) <option value="{{ $i }}" {{ $mes==$i?'selected':'' }}>{{ $m }}</option> @endif
            @endforeach
        </select>
        <select name="anio" onchange="this.form.submit()" style="font-size:.8rem;padding:.3rem .5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;">
            @for($y=date('Y');$y>=2023;$y--)
            <option value="{{ $y }}" {{ $anio==$y?'selected':'' }}>{{ $y }}</option>
            @endfor
        </select>
        {{-- Estado --}}
        <select name="estado" onchange="this.form.submit()" style="font-size:.78rem;padding:.3rem .5rem;border:1px solid #334155;border-radius:6px;
            @if($soloPend==='pendiente') background:#b45309;color:#fff;
            @elseif($soloPend==='pagado') background:#15803d;color:#fff;
            @else background:#1e3a5f;color:#e2e8f0;
            @endif">
            <option value="pendiente" {{ $soloPend==='pendiente'?'selected':'' }}>⏳ Pendientes</option>
            <option value="pagado"    {{ $soloPend==='pagado'?'selected':'' }}>📤 Facturados</option>
            <option value="todos"     {{ $soloPend==='todos'?'selected':'' }}>📋 Todos</option>
        </select>
        {{-- Tipo --}}
        <select name="tipo" onchange="this.form.submit()" style="font-size:.78rem;padding:.3rem .5rem;border:1px solid #334155;background:#1e3a5f;color:#e2e8f0;border-radius:6px;">
            <option value="individual" {{ $soloInd==='individual'?'selected':'' }}>👤 Individual</option>
            <option value="empresas"   {{ $soloInd==='empresas'?'selected':'' }}>🏢 Empresas</option>
            <option value="todos"      {{ $soloInd==='todos'?'selected':'' }}>📋 Todos</option>
        </select>
        <span style="background:rgba(255,255,255,.15);color:#fff;font-size:.85rem;font-weight:800;padding:.3rem .7rem;border-radius:20px;white-space:nowrap;">
            {{ $contratos->count() }} <span style="font-size:.7rem;font-weight:500;opacity:.75;">registros</span>
        </span>
        <a href="{{ route('admin.cobros.exportar', request()->query()) }}" 
           style="background:#16a34a;color:#fff;font-size:.8rem;font-weight:700;padding:.3rem .7rem;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;"
           title="Exportar listado actual a Excel">
            📥 Excel
        </a>
        <button type="button" onclick="abrirModalWhatsAppMasivo()"
           style="background:#25d366;color:#fff;font-size:.8rem;font-weight:700;padding:.3rem .7rem;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:.3rem;"
           title="Enviar cobros por WhatsApp">
            💬 WhatsApp
        </button>
    </div>
</div>
</form>

{{-- ══ FILTROS SECUNDARIOS Y METRICAS UNIFICADAS ══ --}}
<form method="GET" action="{{ route('admin.cobros.index') }}" id="formFiltros2">
<input type="hidden" name="mes"    value="{{ $mes }}">
<input type="hidden" name="anio"   value="{{ $anio }}">
<input type="hidden" name="estado" value="{{ $soloPend }}">
<input type="hidden" name="tipo"   value="{{ $soloInd }}">
<input type="hidden" name="afil_plan" value="{{ $afilPlan }}">
<input type="hidden" name="empresa_cliente" value="{{ $empresaCliente }}">
<input type="hidden" name="tipo_modal" value="{{ $tipoModalFiltro }}">

<div class="filtros" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.4rem;padding:0.4rem 0.8rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:0.1rem;">
    {{-- Filtros a la izquierda --}}
    <div style="display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
        <input type="text" name="buscar" value="{{ $buscar }}" placeholder="🔍 Nombre o cédula..." style="min-width:130px;padding:0.3rem 0.5rem;font-size:0.75rem;height:28px;box-sizing:border-box;">
        <select name="razon_social_id" onchange="this.form.submit()" style="padding:0.3rem 0.5rem;font-size:0.75rem;height:28px;max-width:250px;box-sizing:border-box;" title="Filtrar por Razón Social">
            <option value="">— Razón Social —</option>
            @foreach($razonesDisponibles as $rs)
            <option value="{{ $rs->id }}" {{ $rsId==$rs->id?'selected':'' }}>{{ $rs->razon_social }}</option>
            @endforeach
        </select>
        <select name="asesor_id" onchange="this.form.submit()" style="padding:0.3rem 0.5rem;font-size:0.75rem;height:28px;box-sizing:border-box;">
            <option value="">— Asesor —</option>
            @foreach($asesoresDisponibles as $as)
            <option value="{{ $as->id }}" {{ $asesorId==$as->id?'selected':'' }}>{{ $as->nombre }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-filtrar" style="padding:0.3rem 0.75rem;font-size:0.75rem;height:28px;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;">Filtrar</button>
        <a href="{{ route('admin.cobros.index') }}" class="btn-limpiar" style="padding:0.3rem 0.6rem;font-size:0.75rem;height:28px;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;">✕ Limpiar</a>
    </div>

    {{-- Cards de resumen (mini-kpis) a la derecha --}}
    <div class="mini-kpis" style="display:flex;align-items:center;gap:0.3rem;flex-wrap:wrap;">
        <span style="background:#eff6ff;color:#1e40af;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #bfdbfe;" title="Administración por cobrar">Admon: <strong>{{ $fmt($totalAdmon) }}</strong></span>
        <span style="background:#f8fafc;color:#0f172a;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #e2e8f0;" title="Contratos con pago pendiente">Contratos: <strong>{{ $totalPendientes }}</strong></span>
        <span style="background:#fee2e2;color:#dc2626;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #fca5a5;" title="Nunca llamado o >7 días sin llamar">🔴 Sin llamar: <strong>{{ $sinLlamar }}</strong></span>
        <span style="background:#fff7ed;color:#d97706;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #fed7aa;" title="Última llamada es promesa de pago">🤝 Promesas: <strong>{{ $prometieronPago }}</strong></span>
        <span style="background:#f5f3ff;color:#7c3aed;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #ddd6fe;" title="Total Seguridad Social estimado (EPS+ARL+AFP+Caja)">📊 SS: <strong>{{ $fmt($totalSS) }}</strong></span>
        <a href="{{ route('admin.prestamos.index') }}" style="text-decoration:none;" title="Ver módulo Préstamos">
            <span style="background:#f5f3ff;color:#4f46e5;padding:0.25rem 0.45rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #c7d2fe;display:inline-flex;align-items:center;gap:0.2rem;">
                💳 Préstamos: <strong id="kpi-prest-val">—</strong>
                <span id="kpi-prest-sub" style="display:none;"></span>
            </span>
        </a>
    </div>
</div>
</form>

{{-- ══ TABLA ══ --}}
@if($contratos->isEmpty())
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#fff;border-radius:12px;border:1px solid #e2e8f0;">
    <div style="font-size:3rem;">💰</div>
    <div style="font-size:1rem;font-weight:600;margin-top:.5rem;">Sin contratos pendientes para este período</div>
    <div style="font-size:.8rem;margin-top:.25rem;">Prueba cambiando el filtro de Estado a "Todos".</div>
</div>
@else
<div class="tbl-wrap">
{{-- Ícono de WhatsApp de cada fila: se dibuja una vez y las filas lo referencian --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="ico-wa" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></symbol></svg>
<table class="tbl-cob">
<thead>
<tr>
    {{-- N° Contrato --}}
    <th><a href="{{ $sortUrlC('contrato', $sort, $dir) }}" class="{{ $sortClassC('contrato', $sort, $dir) }}">N°</a></th>
    {{-- Cédula --}}
    <th><a href="{{ $sortUrlC('cedula', $sort, $dir) }}"   class="{{ $sortClassC('cedula', $sort, $dir) }}">Cédula</a></th>
    {{-- Nombre --}}
    <th>Nombre</th>
    {{-- Celular --}}
    <th style="text-align:center;" title="Celular">Celular</th>
    {{-- Razón Social --}}
    <th>
        <form method="GET" action="{{ route('admin.cobros.index') }}" style="margin:0">
            @foreach(request()->except(['razon_social_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="razon_social_id" onchange="this.form.submit()" class="th-select {{ $rsId ? 'activo' : '' }}">
                <option value="">↓ Razón Social</option>
                @foreach($razonesDisponibles as $rs)<option value="{{ $rs->id }}" {{ $rsId==$rs->id?'selected':'' }}>{{ \Illuminate\Support\Str::limit($rs->razon_social, 20, '…') }}</option>@endforeach
            </select>
        </form>
    </th>
    {{-- Ingreso --}}
    <th><a href="{{ $sortUrlC('ingreso', $sort, $dir) }}"  class="{{ $sortClassC('ingreso', $sort, $dir) }}">Ingreso</a></th>
    {{-- Tipo Modalidad --}}
    <th>
        <form method="GET" action="{{ route('admin.cobros.index') }}" style="margin:0">
            @foreach(request()->except(['tipo_modal','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="tipo_modal" onchange="this.form.submit()" class="th-select {{ $tipoModalFiltro ? 'activo' : '' }}">
                <option value="">↓ Modalidad</option>
                @foreach($modalidadesDisponibles as $tm)
                    <option value="{{ $tm->id }}" {{ $tipoModalFiltro == $tm->id ? 'selected' : '' }}>
                        {{ $tm->tipo_modalidad ?: $tm->nombre }}
                    </option>
                @endforeach
            </select>
        </form>
    </th>
    <th>
        <form method="GET" action="{{ route('admin.cobros.index') }}" style="margin:0">
            @foreach(request()->except(['afil_plan','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="afil_plan" onchange="this.form.submit()" class="th-select {{ $afilPlan ? 'activo' : '' }}">
                <option value="">↓ Tipo</option>
                <option value="todos" {{ $afilPlan === 'todos' ? 'selected' : '' }}>Todos</option>
                <option value="afil"  {{ $afilPlan === 'afil'  ? 'selected' : '' }}>📌 AFIL</option>
                <option value="plan"  {{ $afilPlan === 'plan'  ? 'selected' : '' }}>📄 PLAN</option>
                <option value="retiro" {{ $afilPlan === 'retiro' ? 'selected' : '' }}>🔴 RETIRO</option>
            </select>
        </form>
    </th>
    {{-- Empresa/Cliente: solo cuando tipo = todos --}}
    @if($soloInd === 'todos')
    <th>
        <form method="GET" action="{{ route('admin.cobros.index') }}" style="margin:0">
            @foreach(request()->except(['empresa_cliente','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="empresa_cliente" onchange="this.form.submit()" class="th-select {{ $empresaCliente ? 'activo' : '' }}">
                <option value="">↓ Empresa/Cliente</option>
                <option value="todos" {{ $empresaCliente === 'todos' ? 'selected' : '' }}>Todos</option>
                @foreach($opcionesEmpresaCliente as $opc)
                    <option value="{{ $opc }}" {{ $empresaCliente === $opc ? 'selected' : '' }}>
                        {{ $opc === 'Individual' ? '👤 Individual' : '🏢 ' . \Illuminate\Support\Str::limit($opc, 15, '…') }}
                    </option>
                @endforeach
            </select>
        </form>
    </th>
    @endif
    {{-- Admon: solo cuando tipo = individual --}}
    @if($soloInd !== 'todos')
    <th class="num-col" title="Administración (solo empresa)">Admon</th>
    @endif
    <th class="num-col" title="Total estimado (SS+Admon+Seguro)">Total</th>
    {{-- Mora estimada al cliente --}}
    @if($soloPend !== 'pagado')
    <th class="num-col" title="Mora estimada por pago tardío" style="color:#fbbf24;">⚠️ Mora</th>
    @endif
    {{-- Factura: solo cuando filtro = todos --}}
    @if($soloPend === 'todos')
    <th style="text-align:center">Factura</th>
    @endif
    @if($soloPend === 'todos' || $soloPend === 'pagado')
    <th title="N° Planilla">
        <a href="{{ $sortUrlC('n_planilla', $sort, $dir) }}" class="{{ $sortClassC('n_planilla', $sort, $dir) }}">N° Planilla</a>
    </th>
    @endif
    {{-- Semáforo siempre --}}
    @if($soloPend !== 'pagado')
    <th style="text-align:center;min-width:90px">Semáforo</th>
    @endif
    {{-- Gestión: solo cuando filtro = pendientes --}}
    @if($soloPend === 'pendiente')
    <th style="min-width:120px">Última gestión</th>
    @endif
    <th style="text-align:center;min-width:90px">Acciones</th>
</tr>
</thead>
<tbody>
@foreach($contratos as $c)
@php
$nombre     = trim(($c->cliente?->primer_nombre ?? '') . ' ' . ($c->cliente?->primer_apellido ?? ''));
$nombre = nombre_oracion($nombre);
// Celda en dos filas: nombres arriba, apellidos abajo. Con préstamo el badge
// ocupa espacio, así que solo va el primero de cada uno.
$conPrestamo = (bool) ($c->tiene_prestamo ?? false);
$filaNombres = nombre_oracion($conPrestamo
    ? ($c->cliente?->primer_nombre ?? '')
    : trim(($c->cliente?->primer_nombre ?? '') . ' ' . ($c->cliente?->segundo_nombre ?? '')));
$filaApellidos = nombre_oracion($conPrestamo
    ? ($c->cliente?->primer_apellido ?? '')
    : trim(($c->cliente?->primer_apellido ?? '') . ' ' . ($c->cliente?->segundo_apellido ?? '')));
$rs         = $c->razonSocial?->razon_social ?? '—';
$celular    = $c->cliente?->celular ?? '—';
$fIng       = $c->fecha_ingreso?->format('d/m/Y') ?? '—';
$tipoMod    = $c->tipoModalidad?->tipo_modalidad ?? '?';
$tipoNom    = $c->tipoModalidad?->nombre ?? '—';
$esIndep    = $c->tipoModalidad?->esIndependiente() ? 'true' : 'false';
$costoAfil  = (int)($c->costo_afiliacion ?? 0);
$arlNivel   = $c->n_arl ?? 1;
$distAsesor = (int)($c->asesor?->comision_afil_valor ?? 0);
$fIngMes    = $c->fecha_ingreso?->month ?? 0;
$fIngAnio   = $c->fecha_ingreso?->year ?? 0;
[$semIco, $semColor, $semBg, $semTip] = $semLabel($c->semaforo);
// Ingreso-Retiro: alerta si planilla > 5 días
$esIrAlerta       = $c->es_ir_alerta ?? false;
$diasIrEstim      = $c->dias_cotiz_estim ?? 30;
$esRetiro         = $c->es_retiro ?? false;
$esRetiroInformativo = $esRetiro && ($c->es_retiro_informativo ?? false);
$esRetiroPosterior = $c->es_retiro_posterior ?? false;
$rowClass = $esRetiro ? 'fila-retiro' : ($esRetiroPosterior ? 'fila-retpost' : ($esIrAlerta ? 'fila-iralerta' : ''));
@endphp
<tr data-cid="{{ $c->id }}"@if($rowClass) class="{{ $rowClass }}"@endif>
    {{-- N° Contrato --}}
    <td class="c-id">{{ $c->id }}</td>

    {{-- Cédula → abre contrato en modal iframe --}}
    <td>
        <button type="button"
            class="btn-facturar-cedula num-mono c-ced"
            data-contrato-id="{{ $c->id }}"
            data-nombre="{{ $nombre }}"
            data-cedula="{{ $c->cedula }}"
            title="Clic para abrir contrato">
            {{ $c->cedula }}
        </button>
    </td>

    {{-- Nombre --}}
    <td>
        <div class="c-nom" title="{{ nombre_oracion(trim(($c->cliente?->primer_nombre ?? '').' '.($c->cliente?->segundo_nombre ?? '').' '.($c->cliente?->primer_apellido ?? '').' '.($c->cliente?->segundo_apellido ?? ''))) }}">
            @if($filaNombres !== '' || $filaApellidos !== '')
                <span class="c-nom-l">{{ $filaNombres }}</span>
                <span class="c-nom-l">{{ $filaApellidos }}</span>
            @else
                —
            @endif
        </div>
        {{-- Badge préstamo pendiente --}}
        @if($c->tiene_prestamo ?? false)
        <a href="{{ route('admin.prestamos.index', ['buscar' => $c->cedula, 'tab' => 'individuales']) }}"
           class="c-prest"
           title="Tiene préstamo pendiente — clic para ver">
            💳 Préstamo
        </a>
        @endif
    </td>

    {{-- Celular + WhatsApp --}}
    <td class="c-nowrap">
        @if($celular && $celular !== '—')
        <div class="c-cel">
            {{ $celular }}
            <a href="https://wa.me/57{{ preg_replace('/\D/', '', $celular) }}" target="_blank"
               title="Abrir WhatsApp" class="c-wa">
                <svg fill="#25d366" width="14" height="14"><use href="#ico-wa"/></svg>
            </a>
        </div>
        @else
        <span class="c-vacio">—</span>
        @endif
    </td>

    {{-- Razón Social --}}
    <td>
        @if($esIrAlerta)
            <div style="display:flex;flex-direction:column;gap:2px;">
                <a href="{{ route('admin.contratos.edit', $c->id) }}"
                   style="display:inline-flex;align-items:center;gap:.28rem;padding:.2rem .5rem;
                          border-radius:6px;background:#fff7ed;border:1px solid #fed7aa;
                          color:#c2410c;font-size:.63rem;font-weight:700;white-space:nowrap;text-decoration:none;"
                   title="Plan Ingreso-Retiro: planilla tendría {{ $diasIrEstim }} días. Debe rotar RS.">
                    &#128260; ¡Rotar RS! ({{ $diasIrEstim }}d)
                </a>
                <span class="razon-badge" title="{{ $rs }}" style="font-size:.58rem;opacity:.65;">
                    {{ \Illuminate\Support\Str::limit($rs, 18, '…') }}
                </span>
            </div>
        @else
            <span class="razon-badge" title="{{ $rs }}">{{ \Illuminate\Support\Str::limit($rs, 20, '…') }}</span>
        @endif
    </td>

    {{-- Ingreso --}}
    <td class="c-fecha">{{ $fIng }}</td>

    {{-- Tipo Modalidad --}}
    <td class="c-mod" title="{{ $tipoNom }}">{{ $tipoMod }}</td>

    {{-- TIPO (AFIL / PLAN / RETIRO / RETIRO INF. / ret.) --}}
    <td class="c-ctr">
        @if($c->es_afil_atrasada_sola ?? false)
            <span class="badge-tipo badge-afil" title="Afiliación de {{ $c->afil_atrasada['periodo'] ?? '' }} que nunca se cobró{{ $c->estado === 'retirado' ? ' — contrato ya retirado' : '' }}">📌 AFIL <span style="font-size:.55rem;opacity:.7;">{{ $c->afil_atrasada['periodo'] ?? '' }}</span></span>
        @elseif($esRetiroInformativo)
            <span class="badge-tipo" style="background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5;font-size:.6rem;" title="Retiro informativo — sin costo SS">⚪ RETIRO INF.</span>
        @elseif($esRetiro)
            <span class="badge-tipo" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;" title="Retiro marcado en este periodo">🔴 RETIRO</span>
        @elseif($esRetiroPosterior)
            {{-- Tenian planilla en el periodo pero se retiraron despues --}}
            @if($c->es_afil)
                <span class="badge-tipo badge-afil" title="Afiliación del periodo — contrato ya retirado">📌 AFIL <span style="font-size:.55rem;opacity:.7;">ret.</span></span>
            @else
                <span class="badge-tipo badge-plan" title="Planilla del periodo — contrato ya retirado">📄 PLAN <span style="font-size:.55rem;opacity:.7;">ret.</span></span>
            @endif
        @elseif($c->es_ind_act_primer_mes ?? false)
            <span class="badge-tipo" style="background:#f3e8ff;color:#7c3aed;" title="I ACT · Cobra Afiliación + Planilla juntas este mes">⚡ ACT</span>
        @elseif($c->es_afil)
            <span class="badge-tipo badge-afil">📌 AFIL</span>
        @else
            <span class="badge-tipo badge-plan">📄 PLAN</span>
        @endif
    </td>

    {{-- Empresa/Cliente: solo cuando tipo = todos --}}
    @if($soloInd === 'todos')
    <td class="c-ctr72">
        @if($c->es_empresa)
            <span class="c-pill c-emp"
                  title="{{ $c->nombre_empresa }}">
                🏢 {{ \Illuminate\Support\Str::limit($c->nombre_empresa, 12, '…') }}
            </span>
        @else
            <span class="c-pill c-ind">
                👤 Individual
            </span>
        @endif
    </td>
    @endif

    {{-- Admon: solo cuando tipo = individual --}}
    @if($soloInd !== 'todos')
    <td class="num-col c-admon">
        {{ $fmt($c->administracion ?? 0) }}
    </td>
    @endif

    {{-- Total estimado --}}
    <td class="num-col c-total" title="SS: {{ $fmt($c->v_ss) }}">
        {{ $fmt($c->total_estimado + ($c->mora_estimada ?? 0)) }}
        {{-- Afiliación de un mes anterior que nunca se cobró: va aparte del cobro del mes --}}
        @if(($c->afil_atrasada ?? null) && !($c->es_afil_atrasada_sola ?? false))
        <div style="font-size:.6rem;font-weight:700;color:#b45309;white-space:nowrap;"
             title="La afiliación de {{ $c->afil_atrasada['periodo'] }} nunca se cobró. Se factura en ese mes.">
            ⚠ + afil. {{ $c->afil_atrasada['periodo'] }} {{ $fmt($c->afil_atrasada['valor']) }}
        </div>
        @endif
    </td>

    {{-- Mora estimada --}}
    @if($soloPend !== 'pagado')
    <td class="num-col">
        @if(($c->mora_estimada ?? 0) > 0)
            <span class="c-mora" title="Mora estimada por pago tardío">
                {{ $fmt($c->mora_estimada) }}
            </span>
        @else
            <span class="c-vacio">—</span>
        @endif
    </td>
    @endif

    {{-- Factura y N° Planilla --}}
    @if($soloPend === 'todos')
    <td class="c-ctr">
        @if($c->fact_id)
            @php [$fl, $fc, $fb] = $estadoFact($c->fact_estado); @endphp
            <a href="{{ route('admin.facturacion.recibo', $c->fact_id) }}" target="_blank"
               class="c-fact" style="background:{{ $fb }};color:{{ $fc }};"
               title="Recibo #{{ $c->fact_numero }} ({{ $fl }})">
                {{ $c->fact_numero }}
            </a>
        @else
            <span class="c-vacio">Sin factura</span>
        @endif
    </td>
    @endif
    @if($soloPend === 'todos' || $soloPend === 'pagado')
    <td class="c-ctr72">
        @if($c->es_afil && !$esRetiro)
            <span class="c-pl c-pl-afil" title="Cobro de afiliación, no requiere número de planilla">
                Afiliación
            </span>
        @elseif($c->fact_n_planilla)
            {{-- Rojo para RETIRO, verde para el resto --}}
            @if($esRetiro)
                <span class="c-pl c-pl-ret" title="{{ $esRetiroInformativo ? 'Planilla retiro informativo' : 'Planilla del retiro' }}">
                    {{ $c->fact_n_planilla }}
                </span>
            @else
                <span class="c-pl c-pl-ok" title="Número de planilla oficial (operador)">
                    {{ $c->fact_n_planilla }}
                </span>
            @endif
        @elseif($esRetiroInformativo)
            {{-- Retiro informativo sin planilla registrada: mostrar etiqueta en rojo --}}
            <span class="c-pl c-pl-inf" title="Retiro informativo — sin planilla SS">
                Retiro Inf.
            </span>
        @elseif($c->fact_local_plano)
            @if($esRetiro)
                <span class="c-np-ret" title="Plano interno del sistema (retiro)">
                    NP {{ $c->fact_local_plano }}
                </span>
            @else
                <span class="c-np" title="Plano interno del sistema">
                    NP {{ $c->fact_local_plano }}
                </span>
            @endif
        @else
            @if($esRetiroInformativo)
                <span class="c-pl c-pl-inf">
                    Retiro Inf.
                </span>
            @else
                <span class="c-gris">—</span>
            @endif
        @endif
    </td>
    @endif

    {{-- Semáforo --}}
    @if($soloPend !== 'pagado')
    <td class="c-ctr">
        <span class="sem-dot" style="color:{{ $semColor }};" title="{{ $semTip }}">
            {{ $semIco }}
            @if($c->dias_sin_llamar !== null)
                <span class="sem-dias">{{ $c->dias_sin_llamar }}d</span>
            @endif
        </span>
    </td>
    @endif

    {{-- Gestión: solo cuando filtro = pendientes --}}
    @if($soloPend === 'pendiente')
    <td>
        @if($c->ultima_llamada)
            <div class="c-gres">
                {{ \App\Models\BitacoraCobro::RESULTADOS[$c->ultima_llamada->resultado] ?? $c->ultima_llamada->resultado }}
            </div>
            @if($c->ultima_llamada->observacion)
            <div class="c-gobs" title="{{ $c->ultima_llamada->observacion }}">
                {{ $c->ultima_llamada->observacion }}
            </div>
            @endif
        @else
            <span class="c-vacio">Sin gestiones</span>
        @endif
    </td>
    @endif

    {{-- Acciones: Llamar + Cuenta de Cobro o Recibo --}}
    <td class="c-acc">
        {{-- Botón llamar: solo para vigentes, no para retirados --}}
        @if(!$esRetiro && !$esRetiroPosterior)
        <button class="btn-llamar btn-abrir-modal"
            data-contrato-id="{{ $c->id }}"
            data-nombre="{{ $nombre }}"
            data-cedula="{{ $c->cedula }}"
            data-celular="{{ $celular }}"
            data-admon="{{ $fmt($c->administracion ?? 0) }}"
            data-total="{{ $fmt($c->total_estimado + ($c->mora_estimada ?? 0)) }}"
            data-factura-id="{{ $c->fact_id ?? '' }}"
            data-semaforo="{{ $c->semaforo }}"
            title="Registrar llamada de cobro">
            📞
        </button>
        @else
        <span class="c-retok" title="Contrato retirado — sin gestión de cobro">✓</span>
        @endif
        {{-- Botón: si ya tiene factura emitida → 📄 abrir recibo; sino → 🧭 cuenta de cobro --}}
        @if($c->fact_id && $c->fact_emitida)
        <a href="{{ route('admin.facturacion.recibo', $c->fact_id) }}" target="_blank"
            class="c-recibo"
            title="Abrir recibo #{{ $c->fact_numero }}">
            📄
        </a>
        @else
        <button class="btn-cc-ind"
            data-nombre="{{ $nombre }}"
            data-cedula="{{ $c->cedula }}"
            data-rs="{{ $rs }}"
            data-plan="{{ $c->plan_nombre }}"
            data-tipo="{{ $c->tipo_mod_nombre }}"
            data-eps="{{ $c->eps_nombre }}"
            data-arl="{{ $c->arl_nombre }}"
            data-n-arl="{{ $c->n_arl ?? 1 }}"
            data-afp="{{ $c->afp_nombre }}"
            data-caja="{{ $c->caja_nombre }}"
            data-v-eps="{{ $c->v_eps ?? 0 }}"
            data-v-arl="{{ $c->v_arl ?? 0 }}"
            data-v-afp="{{ $c->v_pen ?? 0 }}"
            data-v-caja="{{ $c->v_caja ?? 0 }}"
            data-v-admon="{{ (int)($c->administracion ?? 0) }}"
            data-v-total="{{ $c->total_estimado ?? 0 }}"
            data-mora-val="{{ $c->mora_estimada ?? 0 }}"
            data-mora-dias="{{ $c->mora_dias ?? 0 }}"
            data-dias="{{ $c->dias_cotizados ?? 30 }}"
            title="Ver cuenta de cobro individual">
            🧾
        </button>
        @endif
    </td>
</tr>
@endforeach
</tbody>
<tfoot>
<tr style="background:#0f172a;color:#fff;font-weight:700;">
    <td colspan="8" style="padding:.5rem .55rem;font-size:.72rem;">TOTALES ({{ $contratos->count() }} registros)</td>
    <td class="num-col" style="color:#34d399;padding:.5rem .55rem;">{{ $fmt($totalAdmon) }}</td>
    <td class="num-col" style="color:#34d399;padding:.5rem .55rem;">{{ $fmt($contratos->sum(fn($c) => $c->total_estimado + ($c->mora_estimada ?? 0))) }}</td>
    <td class="num-col" style="color:#fbbf24;padding:.5rem .55rem;" title="Mora total estimada">
        {{ $contratos->sum('mora_estimada') > 0 ? $fmt($contratos->sum('mora_estimada')) : '—' }}
    </td>
    <td colspan="{{ $soloPend === 'todos' ? 4 : ($soloPend === 'pendiente' ? 3 : 3) }}"></td>
</tr>
</tfoot>
</table>
</div>
@endif

</div>{{-- /cob-wrap --}}

{{-- ══ MODAL CUENTA DE COBRO INDIVIDUAL ══ --}}
<div class="modal-cc-bg" id="modalCuentaCobro">
<div class="modal-cc-box">
    {{-- Barra superior --}}
    <div class="cc-bar">
        <span class="cc-bar-title">🧾 Cuenta de Cobro Individual</span>
        <button id="cc-btn-simple"    class="cc-toggle active"   onclick="ccToggleVista('simple')">📄 Simple</button>
        <button id="cc-btn-detallada" class="cc-toggle inactive" onclick="ccToggleVista('detallada')">📋 Detallada</button>
        <button onclick="document.getElementById('modalCuentaCobro').classList.remove('open')"
            style="background:rgba(255,255,255,.18);border:none;color:#fff;width:28px;height:28px;border-radius:7px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;margin-left:.3rem;">✕</button>
    </div>
    {{-- Cuerpo (renderizado por JS) --}}
    <div class="cc-body" id="cc-body-content"></div>
    {{-- Footer --}}
    <div class="cc-footer-bar">
        <button class="btn-cc-print" onclick="ccImprimir()">🖨️ Imprimir / PDF</button>
    </div>
</div>
</div>

{{-- Área de impresión oculta (se muestra solo en @media print) --}}
<div id="cc-print-area" style="display:none;"></div>

{{-- ══ MODAL REGISTRAR LLAMADA ══ --}}
<div class="modal-bg" id="modalLlamada">
<div class="modal-box">
    <div class="modal-title">
        <span>📞 Registrar Llamada de Cobro</span>
        <button class="modal-close" onclick="cerrarModal('modalLlamada')">✕</button>
    </div>

    <div class="info-box">
        <div><span>👤 Cliente:</span> <strong id="ml-nombre"></strong></div>
        <div><span>ID:</span> <strong id="ml-cedula"></strong></div>
        <div><span>Admon:</span> <strong id="ml-admon"></strong></div>
        <div><span>Total estimado:</span> <strong id="ml-total"></strong></div>
        <div style="display:flex;align-items:center;gap:.4rem;">
            <span>📞</span> <strong id="ml-celular"></strong>
            <a id="ml-wa-link" href="#" target="_blank" title="WhatsApp"
               style="text-decoration:none;display:none;line-height:1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366" width="16" height="16"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            </a>
        </div>
    </div>

    <form id="formLlamada" onsubmit="guardarLlamada(event)">
        <input type="hidden" id="ml-contrato-id">
        <input type="hidden" id="ml-factura-id">

        <div class="form-grp">
            <label>Resultado de la llamada *</label>
            <select id="ml-resultado">
                <option value="no_contesta">📵 No contesta</option>
                <option value="promesa_pago">🤝 Promesa de pago</option>
                <option value="pagado">✅ Ya pagó / Pagará hoy</option>
                <option value="numero_errado">❌ Número errado</option>
                <option value="whatsapp">💬 WhatsApp enviado</option>
                <option value="otro">📝 Otro</option>
            </select>
        </div>

        <div class="form-grp">
            <label>Observación — ¿Qué dijo el cliente?</label>
            <textarea id="ml-observacion" placeholder="Ej: informó que consigna el viernes..."></textarea>
        </div>

        <button type="submit" class="btn-save" id="btnGuardarLlamada">💾 Guardar Llamada</button>
    </form>

    {{-- Historial de llamadas previas --}}
    <div style="margin-top:1rem;padding-top:.9rem;border-top:1px solid #f1f5f9;">
        <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
            Historial de gestiones
        </div>
        <div id="ml-historial" style="font-size:.75rem;color:#94a3b8;">Cargando...</div>
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
        box-shadow:0 32px 100px rgba(0,0,0,.5);
        overflow:hidden;
    ">
        {{-- Header del modal --}}
        <div style="
            background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
            padding:.65rem 1.2rem; display:flex; align-items:center;
            justify-content:space-between; flex-shrink:0;
        ">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;">📋</span>
                <div>
                    <div style="font-size:.9rem;font-weight:800;color:#fff;" id="iframeContratoTitulo">Contrato</div>
                    <div style="font-size:.62rem;color:rgba(255,255,255,.5);">Puede facturar o marcar retiro desde esta ventana</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <a id="iframeContratoLink" href="#" target="_blank"
                   style="font-size:.72rem;font-weight:600;color:rgba(255,255,255,.6);text-decoration:none;padding:.3rem .7rem;border:1px solid rgba(255,255,255,.2);border-radius:6px;transition:all .15s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.6)'">
                   &#x2197; Abrir pestaña
                </a>
                <button onclick="cerrarModalContrato()" style="
                    width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;
                    background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);
                    font-size:1rem;display:flex;align-items:center;justify-content:center;
                    transition:background .15s;
                " onmouseover="this.style.background='rgba(255,255,255,.22)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">
                    ✕
                </button>
            </div>
        </div>
        {{-- iframe container con spinner --}}
        <div style="position:relative;flex:1;overflow:hidden;">
            {{-- Spinner de carga --}}
            <div id="iframeLoading" style="
                position:absolute;inset:0;background:#f8fafc;
                display:flex;flex-direction:column;align-items:center;justify-content:center;
                gap:1rem;z-index:10;
            ">
                <div style="
                    width:44px;height:44px;border-radius:50%;
                    border:4px solid #e2e8f0;border-top-color:#3b82f6;
                    animation:spinIframe .7s linear infinite;
                "></div>
                <div style="font-size:.82rem;color:#64748b;font-weight:600;">Cargando contrato...</div>
            </div>
            <iframe id="iframeContrato" src=""
                style="width:100%;height:100%;border:none;display:block;"
                onload="document.getElementById('iframeLoading').style.display='none'">
            </iframe>
        </div>
    </div>
</div>

{{-- Toast --}}
<div class="toast" id="toastMsg"></div>

@push('scripts')
<style>
@keyframes spinIframe { to { transform: rotate(360deg); } }
</style>
<script>
const CSRF        = document.querySelector('meta[name="csrf-token"]')?.content;
const URL_LLAMADA  = '{{ route("admin.cobros.llamada.store", ["contratoId" => "__ID__"]) }}';
const URL_LLAMADAS = '{{ route("admin.cobros.llamadas",     ["contratoId" => "__ID__"]) }}';
const BASE_CONTRATO = '{{ url("admin/contratos") }}';
let contratoActivo = null;

// ── Modal iframe: contrato ──────────────────────────────────────────────
function cerrarModalContrato() {
    const ov = document.getElementById('modalContratoOverlay');
    const fr = document.getElementById('iframeContrato');
    ov.style.display = 'none';
    // Resetear antes de limpiar src para que el onload de la página vacía
    // NO sea interpretado como "segunda carga = acción completada"
    _iframeFirstLoad = false;
    fr.src = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModalContrato();
        document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open'));
    }
});

// Click en cédula → abrir iframe
let _iframeFirstLoad  = false; // flag para detectar solo la 1era carga
let _iframeContratoId = null;  // ID del contrato activo en el iframe

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-facturar-cedula');
    if (!btn) return;

    _iframeContratoId = btn.dataset.contratoId;
    _iframeFirstLoad  = false; // reset para este contrato

    const cid     = _iframeContratoId;
    const nombre  = btn.dataset.nombre || btn.dataset.cedula;
    const fullUrl = `${BASE_CONTRATO}/${cid}/edit`;
    const url     = `${fullUrl}?iframe=1`;

    document.getElementById('iframeContratoTitulo').textContent = nombre;
    document.getElementById('iframeContratoLink').href = fullUrl;
    document.getElementById('iframeLoading').style.display = 'flex';
    document.getElementById('iframeContrato').src = url;
    document.getElementById('modalContratoOverlay').style.display = 'flex';
});

// ── Acción completada: quitar solo la fila afectada ──────────────────────
function onAccionCompletada(contratoId, accion, mensaje) {
    cerrarModalContrato();

    const cid = contratoId || _iframeContratoId;
    if (!cid) return;

    const tr = document.querySelector(`tr[data-cid="${cid}"]`);
    if (tr) {
        // Animación de salida
        tr.style.transition = 'opacity .35s ease, transform .35s ease';
        tr.style.opacity    = '0';
        tr.style.transform  = 'translateX(50px)';
        setTimeout(() => {
            tr.remove();
            // Actualizar contador en el footer
            const rows = document.querySelectorAll('tbody tr').length;
            const footerTd = document.querySelector('tfoot tr td:first-child');
            if (footerTd) footerTd.textContent = `TOTALES (${rows} registros)`;
        }, 380);
    }

    mostrarToast('✅ ' + (mensaje || 'Acción completada'), 'success');
    _iframeContratoId = null;
}

// ── Detectar acción desde iframe ──────────────────────────────────────────

// 1) postMessage: enviado por form.blade.php al facturar
window.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'brynex:iframe_done') {
        onAccionCompletada(e.data.contratoId, e.data.accion, e.data.mensaje);
    }
});

// ── Helpers ──
function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if(e.target === m) m.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if(e.key === 'Escape') document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open'));
});

function mostrarToast(msg, tipo = 'success') {
    const t = document.getElementById('toastMsg');
    t.textContent = msg;
    t.className = `toast show ${tipo}`;
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Abrir modal llamada ──
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-abrir-modal');
    if (!btn) return;

    contratoActivo = btn.dataset.contratoId;
    document.getElementById('ml-nombre').textContent  = btn.dataset.nombre;
    document.getElementById('ml-cedula').textContent  = btn.dataset.cedula;
    document.getElementById('ml-admon').textContent   = btn.dataset.admon;
    document.getElementById('ml-total').textContent   = btn.dataset.total;
    document.getElementById('ml-contrato-id').value   = contratoActivo;
    document.getElementById('ml-factura-id').value    = btn.dataset.facturaId || '';
    document.getElementById('ml-resultado').value     = 'no_contesta';
    document.getElementById('ml-observacion').value   = '';
    // Celular + WhatsApp
    const celEl = document.getElementById('ml-celular');
    const waElI = document.getElementById('ml-wa-link');
    const rawCel = (btn.dataset.celular || '').replace(/\D/g,'');
    if (celEl) celEl.textContent = btn.dataset.celular || '';
    if (waElI && rawCel) { waElI.href = 'https://wa.me/57' + rawCel; waElI.style.display = 'inline'; }
    else if (waElI)      { waElI.style.display = 'none'; }
    // Cargar historial
    cargarHistorial(contratoActivo);
    document.getElementById('modalLlamada').classList.add('open');
});

// ── Guardar llamada ──
async function guardarLlamada(e) {
    e.preventDefault();
    const id         = document.getElementById('ml-contrato-id').value;
    const resultado  = document.getElementById('ml-resultado').value;
    const observacion= document.getElementById('ml-observacion').value;
    const facturaId  = document.getElementById('ml-factura-id').value;
    const btn        = document.getElementById('btnGuardarLlamada');

    btn.disabled = true;
    btn.textContent = 'Guardando...';

    try {
        const r = await fetch(URL_LLAMADA.replace('__ID__', id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ resultado, observacion, factura_id: facturaId || null })
        });
        const data = await r.json();
        if (!data.ok) throw new Error('Error al guardar');

        // Actualizar semáforo en la fila de la tabla
        actualizarFilaSemaforo(id, data);
        cerrarModal('modalLlamada');
        mostrarToast('✅ Llamada registrada correctamente');
    } catch(err) {
        mostrarToast('❌ Error: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Guardar Llamada';
    }
}

// ── Cargar historial ──
async function cargarHistorial(contratoId) {
    const el = document.getElementById('ml-historial');
    el.innerHTML = '<span style="color:#94a3b8;">Cargando...</span>';
    try {
        const r = await fetch(URL_LLAMADAS.replace('__ID__', contratoId), {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
        });
        const data = await r.json();
        if (!data.llamadas || !data.llamadas.length) {
            el.innerHTML = '<span style="color:#94a3b8;">Sin gestiones previas</span>';
            return;
        }
        el.innerHTML = '<div class="timeline">' +
            data.llamadas.map(l => `
                <div class="tl-item">
                    <div class="tl-date">${l.fecha} &nbsp; <span class="tl-user">${l.usuario}</span></div>
                    <div class="tl-res">${l.etiqueta}</div>
                    ${l.observacion ? `<div class="tl-obs">${l.observacion}</div>` : ''}
                </div>`).join('') +
        '</div>';
    } catch {
        el.innerHTML = '<span style="color:#94a3b8;">Error al cargar el historial</span>';
    }
}

// ── Actualizar semáforo en la tabla ──
function actualizarFilaSemaforo(contratoId, data) {
    setTimeout(() => location.reload(), 600);
}

// ── KPI Préstamos del mes (carga asíncrona) ──────────────────────────
(function() {
    const valEl = document.getElementById('kpi-prest-val');
    const subEl = document.getElementById('kpi-prest-sub');
    if (!valEl) return;
    const fmtP = v => '$' + Math.round(v||0).toLocaleString('es-CO');
    fetch(`{{ route('admin.informes.financiero.prestamos_mes') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(r => r.json())
        .then(data => {
            const t = data.totales || {};
            valEl.textContent = fmtP(t.saldo_pendiente || 0);
            subEl.textContent = (t.cant || 0) + ' préstamo(s) — saldo pendiente';
        })
        .catch(() => {
            valEl.textContent = '—';
            subEl.textContent = 'No disponible';
        });
})();

// ══════════════════════════════════════════════════════════
// CUENTA DE COBRO INDIVIDUAL
// ══════════════════════════════════════════════════════════

// Datos de cuentas bancarias pasados desde el servidor
const CC_CUENTAS = @json($cuentasCobro ?? []);
const CC_MESES   = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

let ccDatos   = null;  // datos del contrato activo
let ccVista   = 'simple'; // 'simple' | 'detallada'

const fmt = v => '$' + Math.round(v||0).toLocaleString('es-CO');

// Abrir modal al pulsar el botón 🧾
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-cc-ind');
    if (!btn) return;
    ccDatos = {
        nombre:    btn.dataset.nombre,
        cedula:    btn.dataset.cedula,
        rs:        btn.dataset.rs,
        plan:      btn.dataset.plan,
        tipo:      btn.dataset.tipo,
        eps:       btn.dataset.eps,
        arl:       btn.dataset.arl,
        nArl:      btn.dataset.nArl,
        afp:       btn.dataset.afp,
        caja:      btn.dataset.caja,
        vEps:      parseInt(btn.dataset.vEps)  || 0,
        vArl:      parseInt(btn.dataset.vArl)  || 0,
        vAfp:      parseInt(btn.dataset.vAfp)  || 0,
        vCaja:     parseInt(btn.dataset.vCaja) || 0,
        vAdmon:    parseInt(btn.dataset.vAdmon)|| 0,
        vTotal:    parseInt(btn.dataset.vTotal)|| 0,
        moraVal:   parseInt(btn.dataset.moraVal)  || 0,
        moraDias:  parseInt(btn.dataset.moraDias) || 0,
        dias:      parseInt(btn.dataset.dias)      || 30,
    };
    ccVista = 'simple';
    ccToggleVista('simple');
    document.getElementById('modalCuentaCobro').classList.add('open');
});

// Cerrar con Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('modalCuentaCobro').classList.remove('open');
});

// Cerrar al clic en el fondo
document.getElementById('modalCuentaCobro').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});

function ccToggleVista(vista) {
    ccVista = vista;
    document.getElementById('cc-btn-simple').className    = 'cc-toggle ' + (vista==='simple'    ? 'active' : 'inactive');
    document.getElementById('cc-btn-detallada').className = 'cc-toggle ' + (vista==='detallada' ? 'active' : 'inactive');
    if (ccDatos) ccRenderizar();
}

function ccRenderizar() {
    const d = ccDatos;
    const mes  = {{ $mes }};
    const anio = {{ $anio }};
    const periodoLabel = (CC_MESES[mes] || mes) + ' ' + anio;
    const vTotalConMora = d.vTotal + d.moraVal;

    // ── Cabecera compacta (sin Razón Social, cédula al lado del nombre) ──
    const headerHtml = `
    <div class="cc-doc-header">
        <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.3rem;">
            <div class="cc-doc-nombre">${nombreOracion(d.nombre)}</div>
            <div style="font-size:.82rem;color:#6ee7b7;font-weight:600;white-space:nowrap;">CC ${d.cedula}</div>
        </div>
        <div class="cc-doc-periodo" style="margin-top:.25rem;">📅 Cuenta de Cobro · ${periodoLabel}</div>
    </div>`;

    // ── Card de entidad ────────────────────────────────────────────
    const card = (icono, bg, label, entidad, nivel, valor, mostrarValor) => {
        const esNinguna = !entidad || entidad === 'Ninguna';
        const nivBadge  = nivel ? `<span class="cc-nivel-badge">N${nivel}</span>` : '';
        const entHtml   = esNinguna
            ? `<span class="cc-card-entity ninguna">Ninguna</span>`
            : `<span class="cc-card-entity">${entidad}${nivBadge}</span>`;
        const valHtml   = mostrarValor && !esNinguna && valor > 0
            ? `<span class="cc-card-val">${fmt(valor)}</span>`
            : (mostrarValor ? `<span style="color:#e2e8f0;font-size:.85rem;">—</span>` : '');
        return `
        <div class="cc-card">
            <div class="cc-card-icon" style="background:${bg};">${icono}</div>
            <div class="cc-card-info">
                <div class="cc-card-label">${label}</div>
                ${entHtml}
            </div>
            ${valHtml}
        </div>`;
    };

    // ── Cuentas bancarias compactas ────────────────────────────────
    const bancosHtml = CC_CUENTAS.length
        ? CC_CUENTAS.map(c => `
            <div class="cc-banco-card" style="margin-bottom:0;padding:.6rem .8rem;">
                <div class="cc-banco-icon" style="width:34px;height:34px;font-size:1rem;">🏦</div>
                <div class="cc-banco-info">
                    <div>
                        <span class="cc-banco-nombre2" style="font-size:.88rem;">${c.banco || c.nombre || '—'}</span>
                        <span class="cc-banco-tipo2">${c.tipo_cuenta || ''}</span>
                    </div>
                    <div class="cc-banco-num2" style="font-size:.98rem;">${c.numero_cuenta || ''}</div>
                    ${c.nit ? `<div class="cc-banco-nit">NIT: ${c.nit}</div>` : ''}
                </div>
            </div>`).join('')
        : `<div style="background:#fef9c3;border-radius:8px;padding:.5rem .7rem;font-size:.75rem;color:#854d0e;">⚠️ Sin cuentas configuradas.</div>`;

    // ── Bloque Total con subtotales más grandes ────────────────────
    const totalHtml = `
    <div class="cc-total-block" style="padding:.85rem 1.2rem;">
        <div>
            <div class="cc-total-label">Total a Pagar</div>
            <div class="cc-total-amount">${fmt(vTotalConMora)}</div>
        </div>
        ${d.moraVal > 0 ? `
        <div class="cc-total-subtotals">
            <div class="cc-sub-row" style="font-size:.88rem;font-weight:800;">
                <span style="color:#a7f3d0;">Cuota mensual</span>
                <span style="color:#fff;font-size:.95rem;">${fmt(d.vTotal)}</span>
            </div>
            <div class="cc-sub-row" style="font-size:.88rem;font-weight:800;">
                <span style="color:#fcd34d;">⚠️ Mora${d.moraDias > 0 ? ` (${d.moraDias}d)` : ''}</span>
                <span style="color:#fbbf24;font-size:.95rem;">${fmt(d.moraVal)}</span>
            </div>
        </div>` : ''}
    </div>`;

    // ── Nota compacta ──────────────────────────────────────────────
    const notaHtml = `<div class="cc-nota2" style="font-size:.65rem;padding:.45rem .65rem;line-height:1.5;">
        📌 Pagos en los primeros <strong>5 días hábiles</strong> de cada mes. El pago tardío puede generar pérdida de prestaciones (D.047/2000 Art.3, D.1804/99 Art.21).
    </div>`;

    // ══ VISTA SIMPLE ════════════════════════════════════════════
    if (ccVista === 'simple') {
        document.getElementById('cc-body-content').innerHTML = headerHtml + `
        <div class="cc-main" style="gap:.6rem;padding:1rem 1.2rem;">
            <div class="cc-plan-row" style="padding:.55rem .9rem;">
                <span style="font-size:.78rem;font-weight:700;color:#334155;">Plan</span>
                <span class="cc-plan-badge">${d.plan}</span>
                <span class="cc-tipo-badge">${d.tipo}</span>
                ${d.dias > 0 && d.dias < 30 ? `<span style="background:#f0f9ff;color:#0369a1;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:20px;border:1px solid #bae6fd;">${d.dias} días</span>` : ''}
            </div>
            <div>
                <div class="cc-section-title" style="margin-bottom:.35rem;">Entidades</div>
                <div class="cc-cards" style="gap:.35rem;">
                    ${card('❤️','#fef2f2','EPS – Salud',     d.eps,  null,  d.vEps,  false)}
                    ${card('🦺','#fffbeb','ARL – Riesgos',   d.arl,  d.nArl,d.vArl,  false)}
                    ${card('🏦','#f0fdf4','AFP – Pensión',   d.afp,  null,  d.vAfp,  false)}
                    ${card('🏛️','#eff6ff','Caja',            d.caja, null,  d.vCaja, false)}
                </div>
            </div>
            ${totalHtml}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:stretch;">
                <div style="display:flex;flex-direction:column;">
                    <div class="cc-section-title" style="margin-bottom:.35rem;">Consignar en</div>
                    ${bancosHtml}
                </div>
                <div style="display:flex;flex-direction:column;">
                    <div class="cc-section-title" style="margin-bottom:.35rem;">Nota</div>
                    ${notaHtml}
                </div>
            </div>
        </div>`;
        return;
    }

    // ══ VISTA DETALLADA ══════════════════════════════════════════
    document.getElementById('cc-body-content').innerHTML = headerHtml + `
    <div class="cc-main" style="gap:.6rem;padding:1rem 1.2rem;">
        <div class="cc-plan-row" style="padding:.55rem .9rem;">
            <span style="font-size:.78rem;font-weight:700;color:#334155;">Plan</span>
            <span class="cc-plan-badge">${d.plan}</span>
            <span class="cc-tipo-badge">${d.tipo}</span>
            ${d.dias > 0 && d.dias < 30 ? `<span style="background:#f0f9ff;color:#0369a1;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:20px;border:1px solid #bae6fd;">${d.dias} días</span>` : ''}
        </div>
        <div>
            <div class="cc-section-title" style="margin-bottom:.35rem;">Desglose de Aportes</div>
            <div class="cc-cards" style="gap:.35rem;">
                ${card('❤️','#fef2f2','EPS – Salud',      d.eps,  null,  d.vEps,  true)}
                ${card('🦺','#fffbeb','ARL – Riesgos',    d.arl,  d.nArl,d.vArl,  true)}
                ${card('🏦','#f0fdf4','AFP – Pensión',    d.afp,  null,  d.vAfp,  true)}
                ${card('🏛️','#eff6ff','Caja – Bienestar', d.caja, null,  d.vCaja, true)}
                ${d.vAdmon > 0 ? card('⚙️','#f5f3ff','Administración',   'Administración',null,d.vAdmon,true) : ''}
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:stretch;">
            ${totalHtml}
            <div style="display:flex;flex-direction:column;">
                <div class="cc-section-title" style="margin-bottom:.35rem;">Consignar en</div>
                ${bancosHtml}
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:stretch;">
            <div style="display:flex;flex-direction:column;">
                <div class="cc-section-title" style="margin-bottom:.35rem;visibility:hidden;">_</div>
            </div>
            <div style="display:flex;flex-direction:column;">
                <div class="cc-section-title" style="margin-bottom:.35rem;">Nota</div>
                ${notaHtml}
            </div>
        </div>
    </div>`;

}

function ccImprimir() {
    const body = document.getElementById('cc-body-content');
    if (!body) return;
    const area = document.getElementById('cc-print-area');
    area.innerHTML = `<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:680px;margin:0 auto;">${body.innerHTML}</div>`;
    area.style.display = 'block';
    window.print();
    setTimeout(() => { area.style.display = 'none'; }, 1200);
}

// ── ENVIOS MASIVOS POR WHATSAPP ──
let cantClientesMasivo = 0;
let cantEnviosValidosMasivo = 0;
let waTabActiva = 'preview';
let waPreviews = [];
let waPreviewIndexAct = 0;
let waPlantillaIdActual = null;  // plantilla seleccionada en el modal individual

async function abrirModalWhatsAppMasivo() {
    const modal   = document.getElementById('modalWaMasivo');
    const loading = document.getElementById('waPrevisualizacionLoading');
    const content = document.getElementById('waPrevisualizacionContent');

    loading.style.display = 'block';
    content.style.display = 'none';
    modal.classList.add('open');

    cambiarTabWa('preview');

    try {
        const queryParams = new URLSearchParams(window.location.search);
        if (waPlantillaIdActual) queryParams.set('plantilla_id', waPlantillaIdActual);
        const r = await fetch(`{{ route('admin.cobros.whatsapp.previsualizar') }}?${queryParams.toString()}`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await r.json();

        if (!data.ok) {
            cerrarModal('modalWaMasivo');
            mostrarToast('⚠️ ' + data.mensaje, 'error');
            return;
        }

        // Bloqueo / Habilitación del botón Confirmar según envíos válidos disponibles
        const btnConfirmar = document.getElementById('btnWaConfirmarMasivo');
        const bannerHoy    = document.getElementById('waBannerEnvioHoy');
        const resumen      = data.resumen_envios;

        if (resumen && resumen.envios_validos === 0) {
            btnConfirmar.disabled = true;
            btnConfirmar.style.opacity = '.5';
            btnConfirmar.style.cursor  = 'not-allowed';
            bannerHoy.innerHTML = `⚠️ No hay nuevos destinatarios pendientes por enviar en este filtro el día de hoy (todos ya fueron enviados o no tienen celular válido).`;
            bannerHoy.style.display = 'block';
            bannerHoy.style.background = '#fef2f2';
            bannerHoy.style.borderColor = '#fecaca';
            bannerHoy.style.color = '#991b1b';
        } else {
            btnConfirmar.disabled = false;
            btnConfirmar.style.opacity = '1';
            btnConfirmar.style.cursor  = 'pointer';
            if (data.envio_hoy) {
                bannerHoy.innerHTML = `ℹ️ Se detectó un envío masivo previo realizado hoy a las <strong>${data.envio_hoy.hora}</strong>. Se omitirán los clientes ya procesados.`;
                bannerHoy.style.display = 'block';
                bannerHoy.style.background = '#eff6ff';
                bannerHoy.style.borderColor = '#bfdbfe';
                bannerHoy.style.color = '#1e3a8a';
            } else {
                bannerHoy.style.display = 'none';
            }
        }

        cantClientesMasivo = data.cant_clientes;
        cantEnviosValidosMasivo = resumen ? resumen.envios_validos : cantClientesMasivo;
        document.getElementById('waDestinatariosCount').textContent = cantClientesMasivo;

        // Poblar/actualizar el selector de plantillas
        const selPlant = document.getElementById('waPlantillaSelectInd');
        if (data.plantillas && selPlant) {
            const valorActual = waPlantillaIdActual || data.plantilla_id;
            selPlant.innerHTML = data.plantillas.map(p =>
                `<option value="${p.id}" ${p.id == valorActual ? 'selected' : ''}>${p.nombre_display || p.nombre}</option>`
            ).join('');
            waPlantillaIdActual = parseInt(selPlant.value);
        }

        const resCont = document.getElementById('waResumenEnvios');
        if (resumen) {
            resCont.innerHTML = `
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;">
                    <span>📄 1 solo contrato:</span>
                    <strong>${resumen.solo_uno} envíos</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;">
                    <span>📂 2 o más contratos agrupados:</span>
                    <strong>${resumen.varios} envíos</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;color:#991b1b;">
                    <span>⚠️ Sin celular válido:</span>
                    <strong>${resumen.sin_celular} omitidos</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;color:#b45309;padding-left:.8rem;">
                    <span>ya enviados hoy:</span>
                    <strong>${resumen.ya_enviados_hoy} omitidos</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;font-size:.82rem;margin-top:.3rem;color:#15803d;">
                    <strong>💬 TOTAL ENVÍOS REALES:</strong>
                    <strong>${resumen.envios_validos} de ${resumen.total_destinatarios}</strong>
                </div>
            `;
            resCont.style.display = 'block';
        } else {
            resCont.style.display = 'none';
        }

        // Cargar previsualizaciones reales
        waPreviews = data.previsualizaciones || [];
        waPreviewIndexAct = 0;
        mostrarPrevisualizacionActual();

        // Mostrar control de navegación si hay más de 1 previsualización
        const navCont = document.getElementById('waPreviewNavigation');
        if (waPreviews.length > 1) {
            navCont.style.display = 'flex';
        } else {
            navCont.style.display = 'none';
        }

        const foot = document.getElementById('waPreviewFooter');
        if (data.footer) { foot.textContent = data.footer; foot.style.display = 'block'; }
        else { foot.style.display = 'none'; }

        const imgCont = document.getElementById('waPreviewHeaderImageContainer');
        const img     = document.getElementById('waPreviewHeaderImage');
        if (data.header_tipo === 'IMAGE' && data.header_imagen) {
            img.src = data.header_imagen; imgCont.style.display = 'block';
        } else { imgCont.style.display = 'none'; }

        const btnCont = document.getElementById('waPreviewButtons');
        btnCont.innerHTML = '';
        if (data.botones && data.botones.length > 0) {
            data.botones.forEach(btn => {
                const bDiv = document.createElement('div');
                bDiv.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:.45rem;font-size:.78rem;text-align:center;font-weight:700;color:#00a884;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:background .15s;';
                bDiv.textContent = btn.texto;
                bDiv.onmouseover = () => bDiv.style.background = '#f9f9f9';
                bDiv.onmouseout = () => bDiv.style.background = '#fff';
                btnCont.appendChild(bDiv);
            });
            btnCont.style.display = 'flex';
        } else { btnCont.style.display = 'none'; }

        loading.style.display = 'none';
        content.style.display = 'block';
    } catch(err) {
        cerrarModal('modalWaMasivo');
        mostrarToast('❌ Error al cargar previsualización: ' + err.message, 'error');
    }
}

function mostrarPrevisualizacionActual() {
    if (waPreviews.length === 0) return;
    const item = waPreviews[waPreviewIndexAct];
    
    let textFormateado = item.cuerpo;
    textFormateado = textFormateado.replace(/\*(.*?)\*/g, '<strong>$1</strong>');
    textFormateado = textFormateado.replace(/_(.*?)_/g, '<em>$1</em>');
    
    document.getElementById('waPreviewBody').innerHTML = textFormateado;
    
    // Actualizar indicador
    document.getElementById('waPreviewIndex').innerHTML = `<strong>${item.nombre}</strong><br/><span style="color:#64748b;font-size:.65rem;font-weight:600;">(${waPreviewIndexAct + 1} de ${waPreviews.length})</span>`;
    
    // Deshabilitar flechas en los extremos
    document.getElementById('btnWaPrev').disabled = (waPreviewIndexAct === 0);
    document.getElementById('btnWaPrev').style.opacity = (waPreviewIndexAct === 0) ? '.4' : '1';
    document.getElementById('btnWaPrev').style.cursor = (waPreviewIndexAct === 0) ? 'not-allowed' : 'pointer';
    
    document.getElementById('btnWaNext').disabled = (waPreviewIndexAct === waPreviews.length - 1);
    document.getElementById('btnWaNext').style.opacity = (waPreviewIndexAct === waPreviews.length - 1) ? '.4' : '1';
    document.getElementById('btnWaNext').style.cursor = (waPreviewIndexAct === waPreviews.length - 1) ? 'not-allowed' : 'pointer';
}

function navegarVistaPrevia(direccion) {
    const nuevoIndice = waPreviewIndexAct + direccion;
    if (nuevoIndice >= 0 && nuevoIndice < waPreviews.length) {
        waPreviewIndexAct = nuevoIndice;
        mostrarPrevisualizacionActual();
    }
}

function cambiarTabWa(tab) {
    waTabActiva = tab;
    document.getElementById('waTabPreview').classList.toggle('active', tab === 'preview');
    document.getElementById('waTabHistorial').classList.toggle('active', tab === 'historial');
    document.getElementById('waPanelPreview').style.display   = tab === 'preview'   ? 'block' : 'none';
    document.getElementById('waPanelHistorial').style.display = tab === 'historial' ? 'block' : 'none';
    if (tab === 'historial') cargarHistorialEnvios();
}

async function cargarHistorialEnvios() {
    const cont = document.getElementById('waHistorialContenido');
    cont.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#64748b;">Cargando historial...</div>';
    try {
        const queryParams = new URLSearchParams(window.location.search);
        const r    = await fetch(`{{ route('admin.cobros.whatsapp.historial') }}?${queryParams.toString()}`);
        const data = await r.json();
        if (!data.ok || data.lotes.length === 0) {
            cont.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">Sin envíos masivos este mes.</div>';
            return;
        }
        let html = `<table style="width:100%;border-collapse:collapse;font-size:.78rem;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Fecha</th>
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Estado</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Total</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Env.</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Fal.</th>
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Usuario</th>
                <th style="padding:.45rem .5rem;border-bottom:1px solid #e2e8f0;"></th>
            </tr></thead><tbody>`;
        data.lotes.forEach(l => {
            const esHoyBadge = l.es_hoy ? '<span style="background:#dcfce7;color:#166534;border-radius:4px;padding:.1rem .35rem;font-size:.65rem;margin-left:.4rem;">HOY</span>' : '';
            html += `<tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:.4rem .5rem;">${l.fecha}${esHoyBadge}</td>
                <td style="padding:.4rem .5rem;">${l.etiqueta}</td>
                <td style="padding:.4rem .5rem;text-align:center;">${l.total_destinatarios}</td>
                <td style="padding:.4rem .5rem;text-align:center;color:#16a34a;">${l.total_enviados}</td>
                <td style="padding:.4rem .5rem;text-align:center;color:#dc2626;">${l.total_fallidos}</td>
                <td style="padding:.4rem .5rem;">${l.usuario}</td>
                <td style="padding:.4rem .5rem;text-align:center;">
                    <button type="button" onclick="abrirInformeLote(${l.id})"
                        style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:6px;padding:.25rem .55rem;font-size:.72rem;cursor:pointer;">
                        📋 Informe
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
    } catch(err) {
        cont.innerHTML = `<div style="color:#dc2626;padding:1rem;">Error: ${err.message}</div>`;
    }
}

async function abrirInformeLote(loteId) {
    const modal = document.getElementById('modalWaInforme');
    const cont  = document.getElementById('waInformeContenido');
    cont.innerHTML = '<div style="text-align:center;padding:2rem;color:#64748b;">Cargando informe...</div>';
    modal.classList.add('open');
    try {
        const r    = await fetch(`{{ url('admin/cobros/whatsapp') }}/${loteId}/reporte`);
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error');
        const lote = data.lote;
        const hayFallidos = data.detalles.some(d => d.estado === 'fallido' && d.wa_numero);
        let html = `<div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:.5rem .8rem;border-radius:8px;font-size:.78rem;">
                📋 <strong>${lote.plantilla}</strong>
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:.5rem .8rem;border-radius:8px;font-size:.78rem;">
                📅 ${lote.fecha} — <em>${lote.usuario}</em>
            </div>
            <div style="padding:.5rem .8rem;border-radius:8px;font-size:.78rem;background:#f8fafc;border:1px solid #e2e8f0;">
                Total: ${lote.total_destinatarios} | ✅ ${lote.total_enviados} | ❌ ${lote.total_fallidos}
            </div>
            ${hayFallidos ? `<button type="button" onclick="reintentarLote(${lote.id})" id="btnReintentarLote"
                style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:.4rem .75rem;font-size:.76rem;cursor:pointer;">
                🔄 Reintentar fallidos
            </button>` : ''}
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.77rem;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:.4rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Cliente</th>
                <th style="padding:.4rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Celular</th>
                <th style="padding:.4rem .5rem;text-align:right;border-bottom:1px solid #e2e8f0;">Valor</th>
                <th style="padding:.4rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Estado</th>
                <th style="padding:.4rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">WA</th>
                <th style="padding:.4rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;"></th>
            </tr></thead><tbody>`;
        data.detalles.forEach(d => {
            const errTxt   = d.error ? `<div style="color:#dc2626;font-size:.68rem;">${d.error}</div>` : '';
            const chatLink = d.conversacion_id
                ? `<a href="{{ url('admin/whatsapp/conversaciones') }}/${d.conversacion_id}" target="_blank"
                    style="font-size:.7rem;color:#2563eb;text-decoration:none;" title="Ir al chat">💬</a>`
                : '';
            html += `<tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:.38rem .5rem;">${nombreOracion(d.nombre)}</td>
                <td style="padding:.38rem .5rem;color:#64748b;">${d.wa_numero || '<span style="color:#94a3b8">Sin número</span>'}</td>
                <td style="padding:.38rem .5rem;text-align:right;">${d.valor_cobro || '—'}</td>
                <td style="padding:.38rem .5rem;text-align:center;">${waEstadoIcon(d.estado)}${errTxt}</td>
                <td style="padding:.38rem .5rem;text-align:center;">${waMsgEstadoIcon(d.estado_wa)}</td>
                <td style="padding:.38rem .5rem;text-align:center;">${chatLink}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
    } catch(err) {
        cont.innerHTML = `<div style="color:#dc2626;padding:1rem;">Error: ${err.message}</div>`;
    }
}

function waEstadoIcon(estado) {
    const m = { pendiente:'<span style="color:#f59e0b;">⏳ Pendiente</span>', fallido:'<span style="color:#dc2626;">❌ Fallido</span>', enviado:'<span style="color:#16a34a;">✅ Enviado</span>' };
    return m[estado] || '<span style="color:#94a3b8;">—</span>';
}
function waMsgEstadoIcon(estado) {
    if (!estado) return '<span style="color:#94a3b8;font-size:.75rem;">—</span>';
    const m = { enviado:'<span title="Enviado a Meta" style="font-size:.85rem;">📤</span>', entregado:'<span title="Entregado" style="font-size:.85rem;">✓✓</span>', leido:'<span title="Leído" style="color:#22c55e;font-size:.85rem;">✓✓</span>', fallido:'<span title="Falló" style="color:#dc2626;font-size:.85rem;">✗</span>' };
    return m[estado] || `<span style="font-size:.7rem;color:#64748b;">${estado}</span>`;
}

async function reintentarLote(loteId) {
    const btn = document.getElementById('btnReintentarLote');
    if (btn) { btn.disabled = true; btn.textContent = 'Reintentando...'; }
    try {
        const r = await fetch(`{{ url('admin/cobros/whatsapp') }}/${loteId}/reintentar`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' }
        });
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error');
        mostrarToast('🔄 ' + data.mensaje, 'success');
        cerrarModal('modalWaInforme');
    } catch(err) {
        mostrarToast('❌ ' + err.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = '🔄 Reintentar fallidos'; }
    }
}

async function enviarMensajePruebaWa() {
    const celular = document.getElementById('waCelularPrueba').value;
    if (!celular) { mostrarToast('⚠️ Debes ingresar un celular de prueba (57...)', 'error'); return; }
    const btn = document.getElementById('btnWaPrueba');
    btn.disabled = true; btn.textContent = 'Enviando...';
    try {
        const r = await fetch(`{{ route('admin.cobros.whatsapp.prueba') }}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ celular_prueba: celular, plantilla_id: waPlantillaIdActual })
        });
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error al enviar');
        mostrarToast('✅ ' + data.mensaje);
    } catch(err) {
        mostrarToast('❌ ' + err.message, 'error');
    } finally {
        btn.disabled = false; btn.textContent = '🚀 Enviar Prueba';
    }
}

async function confirmarEnvioMasivoWa() {
    if (cantEnviosValidosMasivo === 0) { mostrarToast('⚠️ No hay destinatarios pendientes de envío con datos válidos.', 'error'); return; }
    if (!confirm(`¿Confirmar el envío masivo a ${cantEnviosValidosMasivo} clientes? Los cobros ya pagados serán excluidos automáticamente.`)) return;

    const btn = document.getElementById('btnWaConfirmarMasivo');
    btn.disabled = true; btn.textContent = 'Procesando envío...';
    try {
        const queryParams = new URLSearchParams(window.location.search);
        if (waPlantillaIdActual) queryParams.set('plantilla_id', waPlantillaIdActual);
        const r = await fetch(`{{ route('admin.cobros.whatsapp.enviar_filtro') }}?${queryParams.toString()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
        });
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error al programar');
        cerrarModal('modalWaMasivo');
        mostrarToast('✅ ' + data.mensaje, 'success');
        setTimeout(() => { location.reload(); }, 1800);
    } catch(err) {
        mostrarToast('❌ ' + err.message, 'error');
        btn.disabled = false; btn.textContent = '💬 Confirmar Envío Masivo a Todos';
    }
}

</script>
@endpush

{{-- Modal WhatsApp Masivo --}}
<div id="modalWaMasivo" class="modal-bg">
    <div class="modal-box wide" style="max-width:860px; border-radius: 20px;">
        <div class="modal-title" style="border-bottom: 1px solid #f1f5f9; padding-bottom: .8rem; margin-bottom: 1.2rem;">
            <span style="font-weight: 800; font-size: 1.1rem; color: #0f172a; display: flex; align-items: center; gap: .5rem;">💬 Envío Masivo por WhatsApp</span>
            <button type="button" class="modal-close" onclick="cerrarModal('modalWaMasivo')">&times;</button>
        </div>

        {{-- Estilos personalizados locales para el modal WhatsApp --}}
        <style>
            .wa-tab-container {
                display: inline-flex;
                gap: .4rem;
                margin-bottom: 1.25rem;
                background: #f1f5f9;
                padding: .3rem;
                border-radius: 50px;
                border: 1px solid #e2e8f0;
            }
            .wa-tab-btn {
                background: none;
                border: none;
                border-radius: 50px;
                padding: .45rem 1.2rem;
                font-size: .8rem;
                font-weight: 700;
                cursor: pointer;
                color: #64748b;
                transition: all .2s ease;
                display: inline-flex;
                align-items: center;
                gap: .35rem;
                outline: none;
            }
            .wa-tab-btn.active {
                background: #fff;
                color: #1e40af!important;
                box-shadow: 0 2px 6px rgba(0, 0, 0, .06);
            }
            .wa-pill-btn {
                padding: .55rem 1.25rem;
                border-radius: 50px;
                font-size: .82rem;
                font-weight: 700;
                cursor: pointer;
                border: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: .4rem;
                transition: all .2s ease;
                text-decoration: none;
                outline: none;
            }
            .wa-pill-btn-success {
                background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
                color: #fff;
                box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
            }
            .wa-pill-btn-success:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 18px rgba(34, 197, 94, .38);
                background: linear-gradient(135deg, #26d063 0%, #168a41 100%);
            }
            .wa-pill-btn-success:active {
                transform: translateY(0);
            }
            .wa-pill-btn-outline {
                background: #fff;
                color: #475569;
                border: 1px solid #cbd5e1;
                box-shadow: 0 2px 4px rgba(0,0,0,.03);
            }
            .wa-pill-btn-outline:hover {
                background: #f8fafc;
                color: #0f172a;
                border-color: #94a3b8;
                transform: translateY(-1px);
            }
            .wa-pill-btn-outline:active {
                transform: translateY(0);
            }
            .wa-pill-btn-accent {
                background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
                color: #fff;
                box-shadow: 0 3px 10px rgba(37, 99, 235, .25);
            }
            .wa-pill-btn-accent:hover {
                transform: translateY(-1px);
                box-shadow: 0 5px 15px rgba(37, 99, 235, .38);
                background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            }
            .wa-pill-btn-accent:active {
                transform: translateY(0);
            }
        </style>

        {{-- Pestañas en forma de píldoras --}}
        <div class="wa-tab-container">
            <button id="waTabPreview" onclick="cambiarTabWa('preview')" type="button" class="wa-tab-btn active">
                📱 Previsualización
            </button>
            <button id="waTabHistorial" onclick="cambiarTabWa('historial')" type="button" class="wa-tab-btn">
                📊 Historial del Mes
            </button>
        </div>

        <div id="waPrevisualizacionLoading" style="text-align:center;padding:2rem 0;">
            <span style="color:#64748b;">Cargando plantilla e información...</span>
        </div>

        <div id="waPrevisualizacionContent" style="display:none;">
            <div id="waBannerEnvioHoy" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:8px;padding:.65rem .9rem;font-size:.8rem;margin-bottom:1rem;"></div>

            {{-- Panel Previsualización --}}
            <div id="waPanelPreview">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                    <div>
                        <div style="font-size:.68rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.55rem;">Vista previa (mensajes reales)</div>
                        <div style="background:#e5ddd5;border-radius:12px;padding:1rem;border:1px solid #cbd5e1;min-height:300px;display:flex;flex-direction:column;gap:.5rem;background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');background-repeat:repeat;margin-bottom:.5rem;">
                            <div style="background:#fff;border-radius:8px 8px 8px 0;padding:.5rem .75rem;box-shadow:0 1px 1px rgba(0,0,0,.1);max-width:90%;position:relative;align-self:flex-start;">
                                <div id="waPreviewHeaderImageContainer" style="display:none;margin-bottom:.5rem;">
                                    <img id="waPreviewHeaderImage" src="" alt="Encabezado" style="width:100%;border-radius:6px;max-height:120px;object-fit:cover;">
                                </div>
                                <div id="waPreviewBody" style="font-size:.82rem;color:#303030;white-space:pre-wrap;word-break:break-word;line-height:1.4;"></div>
                                <div id="waPreviewFooter" style="font-size:.7rem;color:#868686;margin-top:.25rem;display:none;"></div>
                                <div id="waPreviewButtons" style="display:none;flex-direction:column;gap:.25rem;margin-top:.5rem;border-top:1px solid #f0f0f0;padding-top:.4rem;"></div>
                            </div>
                        </div>
                        
                        {{-- Control de paginación/navegación de previsualización --}}
                        <div id="waPreviewNavigation" style="display:none;align-items:center;justify-content:center;gap:.75rem;background:#f8fafc;padding:.4rem .8rem;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.03);">
                            <button type="button" onclick="navegarVistaPrevia(-1)" id="btnWaPrev" style="background:#fff;border:1px solid #cbd5e1;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:bold;color:#475569;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:all .15s ease;font-size:1.1rem;outline:none;">&larr;</button>
                            <span id="waPreviewIndex" style="font-size:.72rem;font-weight:700;color:#334155;min-width:140px;text-align:center;line-height:1.3;user-select:none;">Cliente 1 de 1</span>
                            <button type="button" onclick="navegarVistaPrevia(1)" id="btnWaNext" style="background:#fff;border:1px solid #cbd5e1;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:bold;color:#475569;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:all .15s ease;font-size:1.1rem;outline:none;">&rarr;</button>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;justify-content:space-between;">
                        <div>
                            <div style="font-size:.68rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.55rem;">Detalles del Envío</div>

                            {{-- Selector de plantilla --}}
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .8rem;margin-bottom:.85rem;">
                                <label style="font-size:.68rem;font-weight:700;color:#475569;display:block;margin-bottom:.3rem;">📋 Plantilla a usar</label>
                                <select id="waPlantillaSelectInd"
                                    onchange="waPlantillaIdActual = parseInt(this.value); abrirModalWhatsAppMasivo();"
                                    style="width:100%;padding:.4rem .6rem;border-radius:8px;border:1px solid #cbd5e1;font-size:.8rem;background:#fff;color:#0f172a;">
                                    <option value="">Cargando plantillas...</option>
                                </select>
                                <small style="color:#64748b;font-size:.68rem;margin-top:.2rem;display:block;">Cambia la plantilla para ver la previsualización actualizada.</small>
                            </div>

                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:.8rem;border-radius:10px;margin-bottom:1rem;">
                                <div style="font-size:1.1rem;font-weight:800;border-bottom:1px solid rgba(22,101,52,.12);padding-bottom:.4rem;margin-bottom:.4rem;">
                                    <span id="waDestinatariosCount">0</span> contratos pendientes
                                </div>
                                <div id="waResumenEnvios" style="font-size:.76rem;line-height:1.6;color:#15803d;display:none;"></div>
                                <small style="color:#1e8f49;margin-top:.4rem;display:block;font-size:.7rem;line-height:1.3;">Los clientes con facturas ya pagadas se excluyen automáticamente.</small>
                            </div>
                            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:.6rem .8rem;margin-bottom:1rem;font-size:.75rem;color:#92400e;">
                                ℹ️ <strong>Varios contratos de planilla</strong> = 1 mensaje con valor sumado. Afiliaciones = mensaje separado.
                            </div>
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.8rem;margin-bottom:1rem;">
                                <label style="font-size:.68rem;font-weight:700;color:#475569;">Celular de Prueba</label>
                                <div style="display:flex;gap:.4rem;margin-top:.2rem;">
                                    <input type="text" id="waCelularPrueba" class="form-control" placeholder="Ej: 573001234567"
                                        style="flex:1;padding:.4rem .6rem;border-radius:50px;font-size:.8rem;" value="{{ Auth::user()->celular ?? '' }}">
                                    <button type="button" class="wa-pill-btn wa-pill-btn-outline" onclick="enviarMensajePruebaWa()" id="btnWaPrueba"
                                        style="font-size:.75rem;padding:.4rem 1rem;">🚀 Enviar Prueba</button>
                                </div>
                                <small style="color:#64748b;font-size:.7rem;">Recibe el mensaje en tu celular antes de enviarlo masivamente.</small>
                            </div>
                        </div>
                        <div style="border-top:1px solid #f1f5f9;padding-top:1rem;display:flex;flex-direction:column;gap:.4rem;">
                            <button type="button" class="wa-pill-btn wa-pill-btn-success" onclick="confirmarEnvioMasivoWa()" id="btnWaConfirmarMasivo"
                                style="width:100%;padding:.65rem;font-size:.88rem;">
                                💬 Confirmar Envío Masivo a Todos
                            </button>
                            <button type="button" class="wa-pill-btn wa-pill-btn-outline" onclick="cerrarModal('modalWaMasivo')"
                                style="width:100%;padding:.5rem;">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Panel Historial --}}
            <div id="waPanelHistorial" style="display:none;">
                <div id="waHistorialContenido" style="min-height:200px;">
                    <div style="text-align:center;padding:2rem;color:#94a3b8;">Selecciona esta pestaña para ver el historial.</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Sub-modal Informe Detallado --}}
<div id="modalWaInforme" class="modal-bg">
    <div class="modal-box wide" style="max-width:900px;">
        <div class="modal-title">
            <span>📋 Informe Detallado del Lote</span>
            <button type="button" class="modal-close" onclick="cerrarModal('modalWaInforme')">&times;</button>
        </div>
        <div id="waInformeContenido" style="max-height:70vh;overflow-y:auto;">
            <div style="text-align:center;padding:2rem;color:#64748b;">Cargando...</div>
        </div>
    </div>
</div>
@endsection
