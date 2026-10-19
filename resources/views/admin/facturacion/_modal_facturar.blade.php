{{--
    ╔══════════════════════════════════════════════════════════════════╗
    ║  PARTIAL: _modal_facturar.blade.php  —  BryNex                  ║
    ║  Modal UNIFICADO de Facturación — layout 2 columnas             ║
    ║                                                                  ║
    ║  Variables via @include:                                         ║
    ║    $bancos   → BancoCuenta::paraFacturacion() (requerida)   ║
    ║    $mfMes    → mes por defecto (opcional)                        ║
    ║    $mfAnio   → año por defecto (opcional)                        ║
    ╚══════════════════════════════════════════════════════════════════╝
--}}
@php
    $mfBancos = $bancos ?? collect();
    $mfMesD   = $mfMes  ?? now()->month;
    $mfAnioD  = $mfAnio ?? now()->year;
    $meses_nombres = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
@endphp

<style>
/* ════════════════════════════════════════════════════════════════
   Modal Unificado de Facturación — BryNex  (diseño 2 columnas)
════════════════════════════════════════════════════════════════ */
#mf-overlay {
    position: fixed; inset: 0;
    background: rgba(10, 10, 20, .65);
    backdrop-filter: blur(4px);
    z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    padding: .75rem;
}
#mf-box {
    background: #ffffff;
    border-radius: 18px;
    width: min(920px, 98vw);
    max-height: 96vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 32px 100px rgba(0,0,0,.45), 0 0 0 1px rgba(255,255,255,.06);
}

/* ── HEADER ── */
#mf-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    padding: .8rem 1.2rem .7rem;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
#mf-header-left { display: flex; align-items: center; gap: .65rem; }
#mf-header-icon {
    width: 34px; height: 34px; border-radius: 9px;
    background: rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
}
#mf-header-text h2 {
    font-size: .92rem; font-weight: 800; color: #fff; margin: 0; line-height: 1.2;
}
#mf-header-text p {
    font-size: .65rem; color: rgba(255,255,255,.55); margin: 0;
}
#mf-close-btn {
    width: 28px; height: 28px; border-radius: 7px; border: none; cursor: pointer;
    background: rgba(255,255,255,.1); color: rgba(255,255,255,.7);
    font-size: .95rem; display: flex; align-items: center; justify-content: center;
    transition: background .18s;
}
#mf-close-btn:hover { background: rgba(255,255,255,.2); color: #fff; }

/* ── CONTROLES SUPERIORES (tipo/estado/mes/año) ── */
#mf-controls {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: .6rem 1.2rem;
    display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
    flex-shrink: 0;
}
.mf-ctrl-group { display: flex; flex-direction: column; gap: .12rem; min-width: 0; }
.mf-ctrl-label {
    font-size: .57rem; font-weight: 800; color: #64748b;
    text-transform: uppercase; letter-spacing: .05em;
}
.mf-ctrl-sel {
    padding: .3rem .55rem; border: 1.5px solid #e2e8f0;
    border-radius: 7px; font-size: .78rem; background: #fff; cursor: pointer;
    font-family: inherit; color: #0f172a; outline: none; transition: border-color .15s;
    min-width: 90px;
}
.mf-ctrl-sel:focus { border-color: #3b82f6; }

/* ── ALERTAS ── */
#mf-alerts { flex-shrink: 0; }
.mf-alert {
    padding: .4rem 1.2rem; font-size: .73rem; font-weight: 700;
    display: flex; align-items: center; gap: .45rem; border-bottom: 1px solid transparent;
}
.mf-alert-warn  { background: #fefce8; color: #78350f; border-color: #fde68a; }
.mf-alert-purple{ background: #faf5ff; color: #6d28d9; border-color: #e9d5ff; }
.mf-alert-blue  { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
/* saldos dentro de col-pagos */
#mf-saldos-panel {
    display: none; flex-direction: column; gap: .3rem;
    border-radius: 8px; overflow: hidden;
}
.mf-badge-favor    { display:flex;align-items:center;gap:.4rem;background: #dcfce7; color: #15803d; padding: .3rem .7rem; border-radius: 7px; font-size: .72rem; font-weight: 700; }
.mf-badge-pendiente{ display:flex;align-items:center;gap:.4rem;background: #fee2e2; color: #dc2626; padding: .3rem .7rem; border-radius: 7px; font-size: .72rem; font-weight: 700; }

/* ── PANEL ANTICIPOS ── */
#mf-anticipo-panel {
    background: linear-gradient(135deg, #fffbeb, #fef9c3);
    border: 1.5px solid #fde68a;
    border-radius: 11px; overflow: hidden; display: none;
}
#mf-anticipo-hdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: .38rem .75rem;
    font-size: .65rem; font-weight: 800; color: #78350f;
    text-transform: uppercase; letter-spacing: .06em;
    background: #fef3c7; border-bottom: 1px solid #fde68a;
    cursor: pointer;
}
#mf-anticipo-total-badge {
    font-size: .72rem; font-weight: 900; color: #92400e;
    background: #fde68a; border-radius: 5px; padding: .1rem .45rem;
}
#mf-anticipo-body { padding: .35rem .5rem; display: flex; flex-direction: column; gap: .22rem; max-height: 150px; overflow-y: auto; }
.mf-ant-row {
    display: grid; grid-template-columns: 20px 1fr auto auto;
    gap: .3rem; align-items: center;
    padding: .22rem .3rem; border-radius: 6px; transition: background .15s;
}
.mf-ant-row:hover { background: rgba(253,230,138,.4); }
.mf-ant-cb { accent-color: #d97706; cursor: pointer; }
.mf-ant-info { font-size: .7rem; color: #78350f; font-weight: 600; display: flex; flex-direction: column; gap: .05rem; }
.mf-ant-fecha { font-size: .62rem; color: #92400e; font-weight: 500; }
.mf-ant-forma { font-size: .6rem; color: #a16207; background: #fef3c7; border-radius: 3px; padding: .02rem .22rem; }
.mf-ant-monto { font-size: .75rem; font-weight: 900; color: #78350f; font-family: monospace; white-space: nowrap; }
.mf-ant-ref { font-size: .58rem; color: #a16207; }
#mf-anticipo-subtotal {
    display: flex; justify-content: space-between; align-items: center;
    padding: .28rem .5rem; border-top: 1px solid #fde68a;
    font-size: .7rem; font-weight: 800; color: #78350f; background: #fffbeb;
}

/* ── OPCIONES INDEPENDIENTE ── */
#mf-indep-opts {
    display: none;
    padding: .4rem 1.2rem; background: #faf5ff; border-bottom: 2px solid #e9d5ff;
    flex-shrink: 0;
}
.mf-radio-row { display: flex; gap: .9rem; margin-top: .22rem; }
.mf-radio-lbl {
    display: flex; align-items: center; gap: .35rem;
    font-size: .78rem; font-weight: 700; color: #6d28d9; cursor: pointer;
}
.mf-radio-lbl input { accent-color: #7c3aed; }

/* ── BODY 2 COLUMNAS ── */
#mf-body {
    display: grid;
    grid-template-columns: 1.5fr 3.7fr;
    gap: 0;
    overflow: hidden;
    flex: 1;
    min-height: 0;
}

/* ── COLUMNA IZQUIERDA: detalle a cobrar ── */
#mf-col-desglose {
    padding: .9rem 1rem .9rem 1.2rem;
    overflow-y: auto;
    border-right: 1px solid #f1f5f9;
    background: #fff;
}
.mf-col-title {
    font-size: .6rem; font-weight: 800; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .08em;
    margin-bottom: .6rem; padding-bottom: .3rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: .4rem; justify-content: space-between;
}
/* Tabla SS */
.mf-detail-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .25rem 0; border-bottom: 1px solid #f8fafc;
}
.mf-detail-row:last-child { border-bottom: none; }
.mf-detail-lbl { font-size: .75rem; color: #475569; font-weight: 600; display: flex; align-items: center; gap: .3rem; }
.mf-detail-val { font-size: .76rem; font-weight: 800; color: #1e293b; font-family: 'JetBrains Mono', monospace, monospace; }
.mf-divider { border: none; border-top: 1px dashed #e2e8f0; margin: .3rem 0; }
/* SS total highlighted */
.mf-ss-total .mf-detail-lbl { font-size: .8rem; font-weight: 800; color: #1d4ed8; }
.mf-ss-total .mf-detail-val { font-size: .84rem; color: #1d4ed8; }
/* Total box */
.mf-total-box {
    margin-top: .55rem;
    background: linear-gradient(135deg, #0f172a, #1e3a5f);
    border-radius: 10px;
    padding: .55rem .8rem;
    display: flex; justify-content: space-between; align-items: center;
}
.mf-total-label { font-size: .72rem; font-weight: 700; color: rgba(255,255,255,.7); text-transform: uppercase; letter-spacing: .04em; }
.mf-total-val   { font-size: 1.15rem; font-weight: 900; color: #fff; font-family: monospace; }
/* Inputs editables en desglose */
.mf-edit-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .2rem 0;
}
.mf-edit-lbl { font-size: .73rem; color: #64748b; font-weight: 600; }
.mf-edit-inp {
    text-align: right; font-size: .74rem; font-weight: 700; font-family: monospace;
    border: 1.5px solid #e2e8f0; border-radius: 6px;
    padding: .18rem .4rem; background: #f8fafc; width: 90px;
    transition: border-color .15s; outline: none;
}
.mf-edit-inp:focus { border-color: #3b82f6; background: #fff; }
/* Distribución afiliación */
.mf-dist-card {
    background: linear-gradient(135deg, #faf5ff, #f5f3ff);
    border: 1.5px solid #ddd6fe;
    border-radius: 10px; padding: .6rem .75rem; margin-top: .5rem;
}
.mf-dist-card-title { font-size: .62rem; font-weight: 800; color: #7c3aed; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .4rem; }
.mf-dist-grid { display: grid; grid-template-columns: 1fr auto; gap: .22rem .5rem; align-items: center; }
.mf-dist-lbl { font-size: .71rem; font-weight: 700; color: #5b21b6; }
.mf-dist-inp {
    text-align: right; font-size: .73rem; font-family: monospace; font-weight: 700;
    border: 1.5px solid #ddd6fe; border-radius: 5px; padding: .16rem .35rem;
    background: #fff; width: 82px; outline: none;
}
.mf-dist-inp:focus { border-color: #7c3aed; }
.mf-dist-total-row { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #ddd6fe; padding-top: .28rem; margin-top: .18rem; }
.mf-dist-total-lbl { font-size: .71rem; font-weight: 700; color: #4c1d95; }
#mf-dist-utilidad { font-size: .82rem; font-weight: 900; color: #16a34a; font-family: monospace; }
/* Afiliacion label */
#mf-detalle-afil { font-size: .8rem; font-weight: 700; color: #7c3aed; padding: .35rem 0; }

/* ── COLUMNA DERECHA: pagos ── */
#mf-col-pagos {
    padding: .9rem 1.2rem .9rem 1rem;
    overflow-y: auto;
    background: #fafafa;
    display: flex; flex-direction: column; gap: .55rem;
}
/* Pendiente badge grande */
.mf-pendiente-box {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 2px solid #bfdbfe;
    border-radius: 12px; padding: .55rem .85rem;
    display: flex; align-items: center; justify-content: space-between;
}
.mf-pendiente-label { font-size: .65rem; font-weight: 800; color: #1e40af; text-transform: uppercase; letter-spacing: .06em; }
#mf-pendiente { font-size: 1.15rem; font-weight: 900; font-family: monospace; transition: color .2s; }
/* Sección consignaciones */
.mf-pagos-sec {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 11px;
    overflow: hidden;
}
.mf-pagos-sec-hdr {
    background: #f8fafc; padding: .38rem .75rem;
    font-size: .6rem; font-weight: 800; color: #64748b;
    text-transform: uppercase; letter-spacing: .06em;
    border-bottom: 1px solid #e2e8f0;
    display: flex; align-items: center; justify-content: space-between;
}
.mf-consig-add-btn {
    padding: .18rem .52rem; font-size: .65rem; border-radius: 5px;
    border: 1.5px solid #3b82f6; background: #eff6ff; color: #1d4ed8;
    cursor: pointer; font-weight: 700; transition: background .15s;
}
.mf-consig-add-btn:hover { background: #dbeafe; }
#mf-consig-list {
    padding: .3rem .5rem; display: flex; flex-direction: column; gap: .22rem;
    max-height: 140px; overflow-y: auto;
}
.mf-consig-row {
    display: grid; grid-template-columns: minmax(180px, 1.8fr) 88px 115px minmax(50px, 0.5fr) 34px 22px;
    gap: .25rem; align-items: center;
}
.mf-consig-sel, .mf-consig-monto-inp, .mf-consig-fecha-inp {
    padding: .25rem .35rem; border: 1px solid #e2e8f0; border-radius: 5px;
    font-size: .71rem; font-family: inherit; outline: none; background: #fff;
    transition: border-color .15s;
}
.mf-consig-sel:focus, .mf-consig-monto-inp:focus, .mf-consig-fecha-inp:focus { border-color: #3b82f6; }
/* Select de banco: compacto cuando está cerrado, completo al abrir */
.mf-consig-banco {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
}
.mf-consig-monto-inp { text-align: right; font-weight: 700; font-family: monospace; }
.mf-consig-del {
    padding: 2px 5px; border: none; background: #fee2e2; color: #dc2626;
    border-radius: 5px; cursor: pointer; font-size: .85rem;
    transition: background .15s;
}
.mf-consig-del:hover { background: #fecaca; }
.mf-consig-img-lbl {
    display: flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 5px;
    background: #f0f9ff; border: 1px solid #bae6fd; cursor: pointer;
    font-size: .9rem; transition: background .15s;
}
.mf-consig-img-lbl:hover { background: #e0f2fe; }
/* Efectivo / Préstamo */
.mf-pago-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: .4rem .75rem; border-top: 1px solid #f1f5f9;
}
.mf-pago-lbl { font-size: .73rem; font-weight: 700; color: #475569; display: flex; align-items: center; gap: .3rem; }
.mf-pago-inp {
    width: 88px; text-align: right; padding: .27rem .42rem;
    border: 1.5px solid #e2e8f0; border-radius: 6px; font-size: .76rem;
    font-family: monospace; font-weight: 700; background: #fff; outline: none;
    transition: border-color .15s;
}
.mf-pago-inp:focus { border-color: #3b82f6; }
/* Observación */
.mf-obs-wrap { flex-shrink: 0; }
.mf-obs-inp {
    width: 100%; padding: .35rem .55rem; border: 1.5px solid #e2e8f0;
    border-radius: 8px; font-size: .77rem; font-family: inherit; outline: none;
    background: #fff; transition: border-color .15s; box-sizing: border-box;
}
.mf-obs-inp:focus { border-color: #3b82f6; }

/* ── NP plano ── */
.mf-np-wrap { display: none; }
.mf-np-inp {
    width: 100%; padding: .3rem .5rem; border: 1.5px solid #e2e8f0;
    border-radius: 7px; font-size: .8rem; outline: none; background: #fff;
    transition: border-color .15s; box-sizing: border-box;
}
.mf-np-inp:focus { border-color: #3b82f6; }

/* ── Retiro ── */
#mf-retiro-card {
    background: linear-gradient(135deg, #fff5f5, #fef2f2);
    border: 1.5px solid #fecaca;
    border-radius: 11px; overflow: hidden;
    transition: border-color .18s;
}
#mf-retiro-card.activo {
    border-color: #ef4444;
    box-shadow: 0 2px 10px rgba(239,68,68,.12);
}
#mf-retiro-hdr {
    display: flex; align-items: center; gap: .5rem;
    padding: .42rem .72rem;
    cursor: pointer; user-select: none;
    background: transparent;
    border: none; width: 100%; text-align: left;
}
#mf-retiro-hdr:hover { background: rgba(239,68,68,.05); }
.mf-retiro-check {
    width: 15px; height: 15px; accent-color: #ef4444; cursor: pointer; flex-shrink: 0;
}
#mf-retiro-hdr-label {
    font-size: .75rem; font-weight: 700; color: #dc2626;
    display: flex; align-items: center; gap: .35rem;
}
#mf-retiro-body {
    display: none;
    padding: .45rem .72rem .6rem;
    border-top: 1px solid #fecaca;
    flex-direction: column; gap: .4rem;
}
#mf-retiro-body.visible { display: flex; }
.mf-retiro-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; align-items: end;
}
.mf-retiro-lbl {
    font-size: .57rem; font-weight: 800; color: #991b1b;
    text-transform: uppercase; letter-spacing: .05em;
    display: block; margin-bottom: .18rem;
}
.mf-retiro-date {
    padding: .3rem .5rem; border: 1.5px solid #fca5a5; border-radius: 7px;
    font-size: .78rem; font-family: inherit; outline: none;
    background: #fff; width: 100%; box-sizing: border-box;
    transition: border-color .15s;
}
.mf-retiro-date:focus { border-color: #ef4444; }
#mf-retiro-dias-box {
    background: #fff; border: 1.5px solid #fca5a5; border-radius: 7px;
    padding: .3rem .55rem;
    display: flex; align-items: center; justify-content: space-between;
}
#mf-retiro-dias-num {
    font-size: 1.05rem; font-weight: 900; color: #dc2626; font-family: monospace;
}
.mf-retiro-aviso {
    font-size: .67rem; color: #92400e; background: #fef3c7;
    border-radius: 5px; padding: .25rem .5rem; border: 1px solid #fde68a;
}

/* ── FOOTER ── */
#mf-footer {
    background: #f8fafc; border-top: 1px solid #e2e8f0;
    padding: .6rem 1.2rem;
    display: flex; align-items: center; justify-content: flex-end; gap: .5rem;
    flex-shrink: 0;
}
.mf-btn-cancel {
    padding: .42rem 1.1rem; background: #fff; color: #475569;
    border: 1.5px solid #e2e8f0; border-radius: 8px; cursor: pointer;
    font-size: .8rem; font-weight: 600; transition: all .15s;
}
.mf-btn-cancel:hover { background: #f1f5f9; border-color: #cbd5e1; }
.mf-btn-guardar {
    padding: .44rem 1.4rem;
    background: linear-gradient(135deg, #166534, #15803d);
    color: #fff; border: none; border-radius: 8px; cursor: pointer;
    font-size: .83rem; font-weight: 800; letter-spacing: .01em;
    box-shadow: 0 2px 10px rgba(21, 128, 61, .3);
    transition: all .18s; display: flex; align-items: center; gap: .4rem;
}
.mf-btn-guardar:hover { background: linear-gradient(135deg, #14532d, #166534); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(21, 128, 61, .35); }
.mf-btn-guardar:disabled { opacity: .6; transform: none; cursor: not-allowed; }

/* ── Botón Registrar Anticipo ── */
.mf-btn-anticipo {
    padding: .42rem 1rem;
    background: linear-gradient(135deg, #78350f, #d97706);
    color: #fff; border: none; border-radius: 8px; cursor: pointer;
    font-size: .78rem; font-weight: 700; letter-spacing: .01em;
    box-shadow: 0 2px 8px rgba(217,119,6,.3);
    transition: all .18s; display: flex; align-items: center; gap: .35rem;
}
.mf-btn-anticipo:hover { opacity: .9; transform: translateY(-1px); }

/* ── Badge nivel ARL ── */
.mf-arl-badge {
    font-size: .58rem; background: #e0f2fe; color: #0369a1;
    border-radius: 4px; padding: .03rem .28rem; font-weight: 800;
}

/* ── Scrollbar fina ── */
#mf-col-desglose::-webkit-scrollbar,
#mf-col-pagos::-webkit-scrollbar,
#mf-consig-list::-webkit-scrollbar { width: 4px; }
#mf-col-desglose::-webkit-scrollbar-thumb,
#mf-col-pagos::-webkit-scrollbar-thumb,
#mf-consig-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* ── 2do Contrato (multi-contrato desde form individual) ── */
#mf-c2-wrap {
    margin-top: .65rem;
    border-top: 2px dashed #e2e8f0;
    padding-top: .6rem;
    display: none; /* visible via JS cuando hay otros contratos vigentes */
}
#mf-c2-header {
    display: flex; align-items: center; gap: .45rem;
    font-size: .62rem; font-weight: 800; color: #7c3aed;
    text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: .4rem;
}
#mf-c2-select {
    width: 100%; padding: .32rem .5rem;
    border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: .78rem; background: #fff; outline: none;
    color: #0f172a; cursor: pointer; transition: border-color .15s;
    margin-bottom: .4rem;
}
#mf-c2-select:focus { border-color: #7c3aed; }
#mf-c2-spinner {
    display: none;
    flex-direction: column; align-items: center; justify-content: center;
    padding: 1.2rem; gap: .4rem;
    font-size: .75rem; color: #7c3aed; font-weight: 600;
}
.mf-c2-spin {
    width: 22px; height: 22px;
    border: 3px solid #e9d5ff; border-top-color: #7c3aed;
    border-radius: 50%;
    animation: mf-spin .7s linear infinite;
}
@keyframes mf-spin { to { transform: rotate(360deg); } }
#mf-c2-detalle {
    display: none;
    background: linear-gradient(135deg, #faf5ff, #f5f3ff);
    border: 1.5px solid #ddd6fe;
    border-radius: 10px; padding: .55rem .75rem;
}
.mf-c2-rs-title {
    font-size: .72rem; font-weight: 800; color: #6d28d9;
    margin-bottom: .35rem;
    display: flex; align-items: center; gap: .3rem;
}
.mf-c2-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .18rem 0; border-bottom: 1px solid rgba(167,139,250,.15);
    font-size: .73rem;
}
.mf-c2-row:last-child { border-bottom: none; }
.mf-c2-lbl { color: #5b21b6; font-weight: 600; }
.mf-c2-val { font-family: monospace; font-weight: 700; color: #4c1d95; }
.mf-c2-total-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: .28rem; padding-top: .28rem; border-top: 1.5px solid #c4b5fd;
    font-size: .78rem; font-weight: 800;
}
.mf-c2-total-lbl { color: #4c1d95; }
.mf-c2-total-val { color: #6d28d9; font-family: monospace; font-size: .9rem; }
#mf-c2-aviso {
    display: none;
    background: #fef3c7; border: 1px solid #fde68a;
    border-radius: 6px; padding: .3rem .55rem;
    font-size: .68rem; font-weight: 600; color: #92400e;
    margin-top: .3rem;
}

/* ── Responsive (< 600px → 1 col) ── */
@media (max-width: 600px) {
    #mf-body { grid-template-columns: 1fr; }
    #mf-col-desglose { border-right: none; border-bottom: 1px solid #f1f5f9; }
}
</style>

{{-- ════════════════════════════════════════════════════════ --}}
{{--  OVERLAY + BOX                                          --}}
{{-- ════════════════════════════════════════════════════════ --}}
<div id="mf-overlay" style="display:none;">
<div id="mf-box" onclick="event.stopPropagation()">

    {{-- ── HEADER ── --}}
    <div id="mf-header">
        <div id="mf-header-left">
            <div id="mf-header-icon">🧾</div>
            <div id="mf-header-text">
                <h2>Facturar</h2>
                <p id="mf-subtitle">Seleccione los datos de pago</p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;">
            <span id="mf-badge-dias" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:.63rem;font-weight:700;padding:.18rem .55rem;border-radius:5px;"></span>
            <button id="mf-close-btn" onclick="MF.cerrar()" title="Cerrar">✕</button>
        </div>
    </div>

    {{-- ── CONTROLES: 2do contrato a la izquierda | Tipo/Mes/Año a la derecha ── --}}
    <div id="mf-controls">
        {{-- IZQUIERDA: select 2do contrato (solo modo individual con otros vigentes) --}}
        <div id="mf-c2-ctrl" style="display:none;">
            <div class="mf-ctrl-label" style="color:#7c3aed;">📋 2do Contrato</div>
            <select id="mf-c2-select" class="mf-ctrl-sel" style="min-width:190px;border-color:#ddd6fe;color:#5b21b6;"
                    onchange="MF.seleccionarSegundoContrato(this.value)">
                <option value="">— Sin segundo contrato —</option>
                {{-- Opciones inyectadas por JS --}}
            </select>
        </div>

        {{-- IZQUIERDA (alternativa): opciones independiente (primer mes) --}}
        <div id="mf-indep-opts" style="display:none;">
            <div style="font-size:.57rem;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">⚡ Primer mes — ¿Qué se cobra?</div>
            <div style="display:flex;gap:.75rem;align-items:center;">
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#6d28d9;cursor:pointer;white-space:nowrap;">
                    <input type="radio" name="mf_indep_modo" value="normal" checked onchange="MF.actualizarTipo()" style="accent-color:#7c3aed;"> Solo Afiliación
                </label>
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#6d28d9;cursor:pointer;white-space:nowrap;">
                    <input type="radio" name="mf_indep_modo" value="ambos" onchange="MF.actualizarTipo()" style="accent-color:#7c3aed;"> Planilla + Afiliación
                </label>
            </div>
        </div>

        {{-- DERECHA: controles estándar --}}
        <div style="display:flex;align-items:center;gap:.7rem;margin-left:auto;flex-wrap:wrap;">
            <div class="mf-ctrl-group">
                <span class="mf-ctrl-label">Tipo</span>
                <select id="mf-estado" class="mf-ctrl-sel" onchange="MF.onEstado()">
                    <option value="pagada">🧳 Facturar</option>
                    <option value="pre_factura">📋 Pre-factura</option>
                    <option value="prestamo">💳 Préstamo</option>
                </select>
            </div>
            <div class="mf-ctrl-group">
                <span class="mf-ctrl-label">Mes</span>
                <select id="mf-mes" class="mf-ctrl-sel" onchange="MF.cambiarPeriodo()">
                    @foreach($meses_nombres as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mfMesD ? 'selected' : '' }}>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mf-ctrl-group">
                <span class="mf-ctrl-label">Año</span>
                <select id="mf-anio" class="mf-ctrl-sel" onchange="MF.cambiarPeriodo()">
                    @for($y = now()->year - 1; $y <= now()->year + 2; $y++)
                    <option value="{{ $y }}" {{ $y == $mfAnioD ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div class="mf-ctrl-group mf-np-wrap" id="mf-nplano-wrap">
                <span class="mf-ctrl-label">N° Plano (NP)</span>
                <input type="number" id="mf-nplano" class="mf-np-inp" placeholder="Auto" min="1" style="width:88px;">
            </div>
        </div>
        <input type="hidden" id="mf-tipo" value="planilla">
    </div>

    {{-- ── ALERTAS ── --}}
    <div id="mf-alerts">
        <div id="mf-aviso-mes"  style="display:none;" class="mf-alert mf-alert-warn">⚠️ <span id="mf-aviso-mes-txt"></span></div>
        <div id="mf-aviso-tipo" style="display:none;" class="mf-alert mf-alert-warn"></div>
    </div>

    {{-- ══════════════════════  BODY 2 COLUMNAS  ══════════════════════ --}}
    <div id="mf-body">

        {{-- ╔══════════════════════════════════════════════╗ --}}
        {{-- ║  COL IZQUIERDA — DETALLE A COBRAR           ║ --}}
        {{-- ╚══════════════════════════════════════════════╝ --}}
        <div id="mf-col-desglose">
            <div class="mf-col-title">
                <span>📋 Detalle a cobrar</span>
            </div>

            {{-- Desglose SS (visible en planilla) --}}
            <div id="mf-detalle-ss">
                {{-- EPS --}}
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">EPS <span id="mf-eps-pct" style="font-size:.6rem;color:#94a3b8;font-weight:500;"></span></span>
                    <span id="mf-v-eps" class="mf-detail-val">$0</span>
                </div>
                {{-- ARL --}}
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">ARL <span id="mf-arl-badge" class="mf-arl-badge"></span></span>
                    <span id="mf-v-arl" class="mf-detail-val">$0</span>
                </div>
                {{-- Pensión --}}
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">Pensión</span>
                    <span id="mf-v-afp" class="mf-detail-val">$0</span>
                </div>
                {{-- Caja --}}
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">Caja Comp.</span>
                    <span id="mf-v-caja" class="mf-detail-val">$0</span>
                </div>
                {{-- Parafiscales: solo el aportante no exonerado del art. 114-1 los
                     paga, así que la fila la muestra el JS cuando hay valor. --}}
                <div class="mf-detail-row" id="mf-row-paraf" style="display:none">
                    <span class="mf-detail-lbl">Parafiscales</span>
                    <span id="mf-v-paraf" class="mf-detail-val">$0</span>
                </div>
                {{-- SS Total --}}
                <hr class="mf-divider">
                <div class="mf-detail-row mf-ss-total">
                    <span class="mf-detail-lbl" style="color:#1d4ed8;">Seg. Social</span>
                    <span id="mf-v-ss" class="mf-detail-val" style="color:#1d4ed8;">$0</span>
                </div>
                <hr class="mf-divider">
                {{-- Admin / Seguro / IVA --}}
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">Admon empresa</span>
                    <span id="mf-v-admon" class="mf-detail-val">$0</span>
                </div>
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">Seguro</span>
                    <span id="mf-v-seg" class="mf-detail-val">$0</span>
                </div>
                <div class="mf-detail-row">
                    <span class="mf-detail-lbl">IVA</span>
                    <span id="mf-v-iva" class="mf-detail-val">$0</span>
                </div>
                {{-- Afiliación (visible cuando hay contratos en tipo afiliación) --}}
                <div class="mf-detail-row" id="mf-row-afil" style="display:none;">
                    <span class="mf-detail-lbl" style="color:#16a34a;font-weight:700;">📌 Afiliación</span>
                    <span id="mf-v-afil" class="mf-detail-val" style="color:#16a34a;font-weight:700;">$0</span>
                </div>
                <hr class="mf-divider">
                {{-- Otros editables --}}
                <div class="mf-edit-row">
                    <label class="mf-edit-lbl" for="mf-otros">Otros planilla</label>
                    <input type="text" id="mf-otros" class="mf-edit-inp" value="0" oninput="MF.recalc()">
                </div>
                <div class="mf-edit-row" style="margin-top:.18rem;">
                    <label class="mf-edit-lbl" for="mf-otros-admon">Otros admon</label>
                    <input type="text" id="mf-otros-admon" class="mf-edit-inp" value="0" oninput="MF.recalc()">
                </div>
                {{-- ⚠️ MORA AL CLIENTE — solo visible si aplica mora --}}
                <div class="mf-edit-row" id="mf-row-mora" style="display:none;margin-top:.18rem;
                     background:linear-gradient(135deg,#fffbeb,#fef3c7);
                     border:1.5px solid #fde68a;border-radius:7px;padding:.28rem .45rem;">
                    <label class="mf-edit-lbl" for="mf-mora"
                           style="color:#92400e;font-weight:800;display:flex;align-items:center;gap:.28rem;">
                        ⚠️ Mora cliente
                    </label>
                    <input type="text" id="mf-mora" class="mf-edit-inp" value="0"
                           oninput="MF.onMoraInput()"
                           style="border-color:#f59e0b;background:#fffbeb;color:#92400e;"
                           title="Mora por pago tardío. Editable. No se contabiliza como ingreso.">
                </div>
                <div id="mf-mora-info" style="display:none;font-size:.62rem;color:#78350f;
                     background:#fef3c7;border-radius:5px;padding:.2rem .45rem;margin-top:.15rem;
                     border:1px solid #fde68a;line-height:1.4;"></div>
            </div>
            {{-- Detalle afiliación (legacy) --}}
            <div id="mf-detalle-afil" style="display:none;"></div>

            {{-- Total bruto -- al fondo de la columna izquierda --}}
            <div class="mf-total-box" style="margin-top:.5rem;">
                <span class="mf-total-label">Total bruto</span>
                <span id="mf-total" class="mf-total-val">$0</span>
            </div>

            {{-- Distribución afiliación (solo en modo afiliación) --}}
            <div id="mf-dist-sec" style="display:none;" class="mf-dist-card">
                <div class="mf-dist-card-title">📊 Distribución Afiliación <span style="font-weight:400;opacity:.7;">(interno)</span></div>
                <div class="mf-dist-grid">
                    <span class="mf-dist-lbl">💼 Asesor</span>
                    <input type="text" id="mf-dist-asesor" value="0" oninput="MF.distRecalc()" class="mf-dist-inp">

                    <span class="mf-dist-lbl">🔒 Retiro/Novedad</span>
                    <input type="text" id="mf-dist-retiro" value="0" oninput="MF.distRecalc()" class="mf-dist-inp">

                    <span class="mf-dist-lbl">👤 Encargado</span>
                    <input type="text" id="mf-dist-encargado" value="0" oninput="MF.distRecalc()" class="mf-dist-inp">

                    <span class="mf-dist-lbl">🏢 Gasto/Admon</span>
                    <input type="text" id="mf-dist-admon" value="0" oninput="MF.distRecalc()" class="mf-dist-inp">
                </div>
                <div class="mf-dist-total-row">
                    <span class="mf-dist-total-lbl">📈 Utilidad</span>
                    <span id="mf-dist-utilidad">$0</span>
                </div>
                <div id="mf-dist-aviso" style="display:none;margin-top:.3rem;font-size:.67rem;color:#dc2626;font-weight:700;"></div>
            </div>

        {{-- ═══════════════════════════════════════════════════════════════════
             SECCIÓN 2DO CONTRATO — spinner + detalle (el select está arriba en #mf-controls)
             Visible cuando el usuario escoge un 2do contrato en el select.
        ═══════════════════════════════════════════════════════════════════ --}}
        <div id="mf-c2-wrap" style="display:none;">
            {{-- Aviso si ya fue facturado en el período seleccionado --}}
            <div id="mf-c2-aviso">⚠️ <span id="mf-c2-aviso-txt"></span></div>

            {{-- Spinner de carga --}}
            <div id="mf-c2-spinner">
                <div class="mf-c2-spin"></div>
                <span>Cargando datos del contrato…</span>
            </div>

            {{-- Detalle del 2do contrato (read-only) --}}
            <div id="mf-c2-detalle">
                <div class="mf-c2-rs-title">
                    🏢 <span id="mf-c2-rs">—</span>
                </div>
                <div class="mf-c2-row">
                    <span class="mf-c2-lbl">Seg. Social</span>
                    <span class="mf-c2-val" id="mf-c2-ss">$0</span>
                </div>
                <div class="mf-c2-row">
                    <span class="mf-c2-lbl">Admon</span>
                    <span class="mf-c2-val" id="mf-c2-admon">$0</span>
                </div>
                <div class="mf-c2-row">
                    <span class="mf-c2-lbl">Seguro</span>
                    <span class="mf-c2-val" id="mf-c2-seguro">$0</span>
                </div>
                <div class="mf-c2-row" id="mf-c2-row-afil" style="display:none">
                    <span class="mf-c2-lbl" style="color:#16a34a;">📌 Afiliación</span>
                    <span class="mf-c2-val" id="mf-c2-afil" style="color:#16a34a;">$0</span>
                </div>
                <div class="mf-c2-row" id="mf-c2-row-iva" style="display:none">
                    <span class="mf-c2-lbl">IVA</span>
                    <span class="mf-c2-val" id="mf-c2-iva">$0</span>
                </div>
                <div class="mf-c2-row" id="mf-c2-row-mora" style="display:none">
                    <span class="mf-c2-lbl" style="color:#92400e;">⚠️ Mora</span>
                    <span class="mf-c2-val" id="mf-c2-mora" style="color:#92400e;">$0</span>
                </div>
                <div class="mf-c2-total-row">
                    <span class="mf-c2-total-lbl">Total C2</span>
                    <span class="mf-c2-total-val" id="mf-c2-total">$0</span>
                </div>
            </div>
        </div>{{-- /mf-c2-wrap --}}

        </div>{{-- /mf-col-desglose --}}

        {{-- ╔══════════════════════════════════════════════╗ --}}
        {{-- ║  COL DERECHA — PAGOS                        ║ --}}
        {{-- ╚══════════════════════════════════════════════╝ --}}
        <div id="mf-col-pagos">

            {{-- Saldos a favor / pendientes (aparece si los hay) — ARRIBA del pendiente --}}
            <div id="mf-saldos-panel" style="display:none;"></div>

            {{-- Saldo a pagar (columna derecha) --}}
            <div class="mf-pendiente-box">
                <div>
                    <div class="mf-pendiente-label">Saldo a pagar</div>
                    <div style="font-size:.62rem;color:#3b82f6;margin-top:.08rem;">Total bruto − anticipo − pagos recibidos</div>
                </div>
                <span id="mf-pendiente" style="color:#dc2626;">$0</span>
            </div>

            {{-- Consignaciones --}}
            <div class="mf-pagos-sec">
                <div class="mf-pagos-sec-hdr">
                    <span>🏦 Consignaciones</span>
                    <button class="mf-consig-add-btn" onclick="MF.addConsig()">＋ Agregar</button>
                </div>
                {{-- Cabecera --}}
                <div id="mf-consig-hdr" style="display:grid;grid-template-columns:minmax(180px,1.8fr) 88px 115px minmax(50px,0.5fr) 34px 22px;gap:.25rem;padding:.22rem .6rem .15rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;">Banco</span>
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:right;">Valor</span>
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:center;">Fecha</span>
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:center;">Referencia</span>
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:center;">Adj.</span>
                    <span style="font-size:.57rem;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:center;">Del.</span>
                </div>
                <div id="mf-consig-list"></div>
            </div>

            {{-- Efectivo --}}
            <div class="mf-pagos-sec" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #3b82f6;border-radius:10px;margin-top:.4rem;">
                <div class="mf-pagos-sec-hdr" style="color:#1d4ed8;font-weight:800;font-size:.82rem;">💵 Efectivo</div>
                <div style="padding:.4rem .7rem .55rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                    <span style="font-size:.78rem;font-weight:700;color:#1e40af;">Valor en efectivo</span>
                    <input type="text" id="mf-efectivo" value="0" oninput="MF.recalc()"
                        style="width:130px;padding:.45rem .65rem;border:2px solid #3b82f6;border-radius:8px;
                               font-size:1rem;font-weight:800;color:#1d4ed8;text-align:right;
                               background:#fff;outline:none;box-shadow:0 2px 8px rgba(59,130,246,.15);">
                </div>
            </div>

            {{-- Préstamo (solo si estado=prestamo) --}}
            <div id="mf-prest-wrap" style="display:none;" class="mf-pagos-sec">
                <div class="mf-pagos-sec-hdr"><span>💳 Valor Préstamo</span></div>
                <div class="mf-pago-row">
                    <span class="mf-pago-lbl">Monto del préstamo</span>
                    <input type="text" id="mf-prestamo" value="0" oninput="MF.recalc()" class="mf-pago-inp">
                </div>
            </div>

            {{-- Retiro --}}
            <div id="mf-retiro-card">
                <button type="button" id="mf-retiro-hdr" onclick="MF.toggleRetiro()">
                    <input type="checkbox" id="mf-retiro-check" class="mf-retiro-check"
                           onclick="event.stopPropagation();MF.toggleRetiro()">
                    <span id="mf-retiro-hdr-label">🚪 Marcar Retiro en este período</span>
                </button>
                <div id="mf-retiro-body" style="display:none;">
                    <div class="mf-retiro-row">
                        <div>
                            <label class="mf-retiro-lbl" for="mf-retiro-fecha">Fecha de Retiro</label>
                            <input type="date" id="mf-retiro-fecha" class="mf-retiro-date"
                                   oninput="MF.onRetiroFecha()">
                        </div>
                        <div>
                            <label class="mf-retiro-lbl">Días a pagar</label>
                            <div id="mf-retiro-dias-box">
                                <span style="font-size:.67rem;color:#991b1b;font-weight:600;">días</span>
                                <span id="mf-retiro-dias-num">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="mf-retiro-aviso">
                        ⚠️ Los días calculados actualizan el cotizador para que el SS sea proporcional al retiro.
                    </div>
                </div>
            </div>

            {{-- ── Aviso de cobro de Retiros de meses anteriores ── --}}
            <div id="mf-retiros-facturables-card" style="display:none; background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:.6rem .8rem; margin-top:.4rem;">
                <div style="font-size:.75rem; font-weight:800; color:#c2410c; display:flex; align-items:center; gap:.4rem; margin-bottom:.3rem;">
                    <span>⚠️</span> Cobro de Retiros Anteriores
                </div>
                <div style="font-size:.7rem; color:#9a3412; line-height:1.4;">
                    Has seleccionado <strong id="mf-retiros-facturables-count">0</strong> retiro(s) pendiente(s) de meses pasados para facturar en este lote.
                </div>
                <div style="margin-top:.5rem; display:flex; align-items:center; gap:.4rem;">
                    <input type="checkbox" id="mf-retiros-admon-completa" checked onchange="MF.recalc()"
                           style="width:1rem;height:1rem;cursor:pointer;accent-color:#ea580c;">
                    <label for="mf-retiros-admon-completa" style="font-size:.7rem; font-weight:700; color:#c2410c; cursor:pointer;">
                        Cobrar administración completa (30 días)
                    </label>
                </div>
                <div style="font-size:.62rem; color:#9a3412; margin-top:.2rem; padding-left:1.4rem;">
                    Si se desmarca, la administración se cobrará proporcional a los días de retiro.
                </div>
            </div>

            {{-- ── ANTICIPOS disponibles (se muestra si el cliente tiene saldo) ── --}}
            <div id="mf-anticipo-panel">
                <div id="mf-anticipo-hdr" onclick="MF_ANT.toggleBody()">
                    <span>💰 Anticipos disponibles</span>
                    <span id="mf-anticipo-total-badge">$0</span>
                </div>
                <div id="mf-anticipo-body"></div>
                <div id="mf-anticipo-subtotal">
                    <span>Total seleccionado:</span>
                    <span id="mf-anticipo-sel-total">$0</span>
                </div>
            </div>

            {{-- Observación --}}
            <div class="mf-obs-wrap">
                <div class="mf-col-title" style="margin-bottom:.25rem;">
                    <span>🗒 Observación</span>
                </div>
                <input type="text" id="mf-obs" class="mf-obs-inp" placeholder="Agrega una nota opcional...">
            </div>

        </div>{{-- /mf-col-pagos --}}
    </div>{{-- /mf-body --}}

    {{-- ── FOOTER ── --}}
    <div id="mf-footer" style="justify-content:space-between">

        {{-- Izquierda: registrar anticipo --}}
        <button type="button" class="mf-btn-anticipo" id="mf-btn-anticipo"
                onclick="MF._abrirAnticipo()">
            💰 Registrar Anticipo
        </button>

        {{-- Derecha: cancelar + facturar --}}
        <div style="display:flex;gap:.5rem;align-items:center">
            <button type="button" class="mf-btn-cancel" onclick="MF.cerrar()">Cancelar</button>
            <button type="button" class="mf-btn-guardar" id="mf-btn-guardar" onclick="MF.guardar()">🧾 Facturar ahora</button>
        </div>

    </div>

</div>
</div>

{{-- Datos bancos para JS --}}
<script>
window._MF_BANCOS = [
    {id:'', label:'-- Seleccionar banco --'},
    @foreach($mfBancos as $b)
    {id:{{ $b->id }}, label:{!! json_encode(strtoupper($b->banco) . '   ' . $b->nombre . '   # ' . $b->numero_cuenta) !!}},
    @endforeach
];
</script>

{{-- ══ MODAL REGISTRAR ANTICIPO ══════════════════════════════════════════ --}}
<div id="ant-overlay" style="display:none;position:fixed;inset:0;background:rgba(10,10,20,.6);backdrop-filter:blur(4px);z-index:3000;display:none;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:min(480px,96vw);box-shadow:0 24px 80px rgba(0,0,0,.35);overflow:hidden;display:flex;flex-direction:column;" onclick="event.stopPropagation()">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#78350f,#d97706);padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:.55rem;">
            <span style="font-size:1.2rem;">💰</span>
            <div>
                <div style="font-size:.9rem;font-weight:800;color:#fff;">Registrar Anticipo</div>
                <div style="font-size:.62rem;color:rgba(255,255,255,.7);">Pago recibido sin factura</div>
            </div>
        </div>
        <button onclick="ANT.cerrar()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:7px;width:26px;height:26px;cursor:pointer;font-size:.9rem;">✕</button>
    </div>
    {{-- Body --}}
    <div style="padding:1rem 1.2rem;display:flex;flex-direction:column;gap:.65rem;">
        <input type="hidden" id="ant-contrato-id">
        <input type="hidden" id="ant-empresa-id">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;">
            <div>
                <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Fecha de pago</label>
                <input type="date" id="ant-fecha"
                    style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;outline:none;box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Valor</label>
                <input type="text" id="ant-valor" placeholder="0"
                    style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;font-family:monospace;font-weight:700;outline:none;box-sizing:border-box;text-align:right;">
            </div>
        </div>

        <div>
            <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Forma de pago</label>
            <select id="ant-forma"
                style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;outline:none;background:#fff;box-sizing:border-box;"
                onchange="ANT.onForma()">
                <option value="efectivo" selected>💵 Efectivo</option>
                <option value="transferencia">⇔️ Transferencia</option>
            </select>
        </div>

        <div id="ant-banco-wrap" style="display:none;">
            <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Banco destino</label>
            <select id="ant-banco"
                style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;outline:none;background:#fff;box-sizing:border-box;">
                <option value="">— Seleccionar banco —</option>
                @foreach($mfBancos as $b)
                <option value="{{ $b->id }}">{{ strtoupper($b->banco) }}   {{ $b->nombre }}   # {{ $b->numero_cuenta }}</option>
                @endforeach
            </select>
        </div>

        {{-- ZONA DE IMAGEN: solo transferencia --}}
        <div id="ant-imagen-wrap" style="display:none;">
            <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Comprobante de transferencia</label>
            {{-- Drop zone --}}
            <div id="ant-drop-zone"
                 onclick="document.getElementById('ant-imagen-file').click()"
                 style="border:2px dashed #d97706;border-radius:9px;padding:.8rem;text-align:center;cursor:pointer;background:#fffbeb;transition:background .15s;position:relative;">
                <span id="ant-drop-label" style="font-size:.75rem;color:#92400e;font-weight:600;">
                    📎 Clic, arrastra o pega (Ctrl+V) el comprobante
                </span>
                <input type="file" id="ant-imagen-file" accept="image/*,.pdf"
                       style="display:none;"
                       onchange="ANT._onFileChange(this.files[0])">
            </div>
            {{-- Preview --}}
            <div id="ant-imagen-preview" style="display:none;margin-top:.5rem;position:relative;">
                <img id="ant-img-preview" src="" alt="preview"
                     style="max-width:100%;max-height:140px;border-radius:7px;border:1px solid #e2e8f0;object-fit:contain;display:block;">
                <div id="ant-pdf-preview"
                     style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:.5rem;font-size:.75rem;color:#374151;font-weight:600;">
                    📄 <span id="ant-pdf-name"></span>
                </div>
                <button type="button" onclick="ANT._clearFile()"
                        style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:.7rem;line-height:1;font-weight:800;">
                    ×
                </button>
            </div>
        </div>

        <div>
            <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Referencia (núm. operación)</label>
            <input type="text" id="ant-ref" placeholder="Número de referencia o comprobante"
                style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;outline:none;box-sizing:border-box;">
        </div>

        <div>
            <label style="font-size:.6rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.22rem;">Observación</label>
            <input type="text" id="ant-obs" placeholder="Opcional…"
                style="width:100%;padding:.38rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;outline:none;box-sizing:border-box;">
        </div>

        <div id="ant-error" style="display:none;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:7px;padding:.4rem .65rem;font-size:.75rem;font-weight:700;color:#dc2626;"></div>
    </div>
    {{-- Footer --}}
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:.65rem 1.2rem;display:flex;justify-content:flex-end;gap:.5rem;">
        <button onclick="ANT.cerrar()" style="padding:.4rem 1.1rem;background:#fff;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;font-size:.8rem;font-weight:600;">Cancelar</button>
        <button id="ant-btn-guardar" onclick="ANT.guardar()"
            style="padding:.42rem 1.4rem;background:linear-gradient(135deg,#78350f,#d97706);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.83rem;font-weight:800;">💰 Registrar</button>
    </div>
</div>
</div>

<script>
// ══════════════════════════════════════════
// Módulo ANT: Modal Registrar Anticipo
// ══════════════════════════════════════════
window.ANT = {
    _contratoId: null,
    _empresaId: null,
    _onSuccess: null,
    _archivo: null,
    _pasteHandler: null,

    // Abrir modal de registro
    abrir(contratoId, empresaId, onSuccess) {
        this._contratoId = contratoId || null;
        this._empresaId  = empresaId  || null;
        this._onSuccess  = onSuccess  || null;

        // Defaults
        const hoy = new Date().toISOString().split('T')[0];
        document.getElementById('ant-fecha').value  = hoy;
        document.getElementById('ant-valor').value  = '';
        document.getElementById('ant-forma').value  = 'efectivo';
        document.getElementById('ant-ref').value    = '';
        document.getElementById('ant-obs').value    = '';
        document.getElementById('ant-banco').value  = '';
        document.getElementById('ant-banco-wrap').style.display  = 'none';
        document.getElementById('ant-imagen-wrap').style.display = 'none';
        document.getElementById('ant-error').style.display = 'none';
        document.getElementById('ant-contrato-id').value = contratoId || '';
        document.getElementById('ant-empresa-id').value  = empresaId  || '';
        this._clearFile();

        // Paste listener
        if (this._pasteHandler) document.removeEventListener('paste', this._pasteHandler);
        this._pasteHandler = (e) => {
            if (document.getElementById('ant-forma').value !== 'transferencia') return;
            const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
            if (item) this._onFileChange(item.getAsFile());
        };
        document.addEventListener('paste', this._pasteHandler);

        // Drag & drop
        const dz = document.getElementById('ant-drop-zone');
        dz.ondragover  = (e) => { e.preventDefault(); dz.style.background='#fef3c7'; };
        dz.ondragleave = ()  => { dz.style.background='#fffbeb'; };
        dz.ondrop      = (e) => { e.preventDefault(); dz.style.background='#fffbeb'; const f=e.dataTransfer?.files?.[0]; if(f) this._onFileChange(f); };

        document.getElementById('ant-overlay').style.display = 'flex';
    },

    cerrar() {
        document.getElementById('ant-overlay').style.display = 'none';
        if (this._pasteHandler) {
            document.removeEventListener('paste', this._pasteHandler);
            this._pasteHandler = null;
        }
    },

    onForma() {
        const forma   = document.getElementById('ant-forma').value;
        const esTrans = forma === 'transferencia';
        document.getElementById('ant-banco-wrap').style.display  = esTrans ? 'block' : 'none';
        document.getElementById('ant-imagen-wrap').style.display = esTrans ? 'block' : 'none';
        if (!esTrans) this._clearFile();
    },

    _onFileChange(file) {
        if (!file) return;
        this._archivo = file;
        const prev = document.getElementById('ant-imagen-preview');
        const img  = document.getElementById('ant-img-preview');
        const pdf  = document.getElementById('ant-pdf-preview');
        prev.style.display = 'block';
        if (file.type === 'application/pdf') {
            img.style.display = 'none'; pdf.style.display = 'block';
            document.getElementById('ant-pdf-name').textContent = file.name;
        } else {
            pdf.style.display = 'none'; img.style.display = 'block';
            img.src = URL.createObjectURL(file);
        }
    },

    _clearFile() {
        this._archivo = null;
        const fi = document.getElementById('ant-imagen-file');
        if (fi) fi.value = '';
        const prev = document.getElementById('ant-imagen-preview');
        if (prev) prev.style.display = 'none';
        const img = document.getElementById('ant-img-preview');
        if (img) img.src = '';
    },

    async guardar() {
        const btn = document.getElementById('ant-btn-guardar');
        const errDiv = document.getElementById('ant-error');
        errDiv.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const forma     = document.getElementById('ant-forma').value;
        const csrfToken = document.querySelector('meta[name=csrf-token]')?.content || '';

        let fetchOpts;
        if (this._archivo && forma === 'transferencia') {
            const fd = new FormData();
            fd.append('contrato_id',     document.getElementById('ant-contrato-id').value || '');
            fd.append('empresa_id',      document.getElementById('ant-empresa-id').value  || '');
            fd.append('fecha_pago',      document.getElementById('ant-fecha').value);
            fd.append('valor',           parseInt(document.getElementById('ant-valor').value.replace(/\D/g,'')) || 0);
            fd.append('forma_pago',      forma);
            fd.append('banco_cuenta_id', document.getElementById('ant-banco').value || '');
            fd.append('referencia',      document.getElementById('ant-ref').value || '');
            fd.append('observacion',     document.getElementById('ant-obs').value || '');
            fd.append('imagen',          this._archivo);
            if (this._confirmoEfectivo) fd.append('confirmo_efectivo', '1');
            fetchOpts = { method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':csrfToken}, body:fd };
        } else {
            fetchOpts = {
                method: 'POST',
                headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':csrfToken },
                body: JSON.stringify({
                    contrato_id:     document.getElementById('ant-contrato-id').value || null,
                    empresa_id:      document.getElementById('ant-empresa-id').value  || null,
                    fecha_pago:      document.getElementById('ant-fecha').value,
                    valor:           parseInt(document.getElementById('ant-valor').value.replace(/\D/g,'')) || 0,
                    forma_pago:      forma,
                    banco_cuenta_id: document.getElementById('ant-banco').value || null,
                    referencia:      document.getElementById('ant-ref').value || null,
                    observacion:     document.getElementById('ant-obs').value || null,
                    confirmo_efectivo: this._confirmoEfectivo ? 1 : 0,
                }),
            };
        }

        try {
            const res  = await fetch('/admin/anticipos', fetchOpts);

            const data = await res.json();

            // Anticipo de empresa grande en efectivo: confirmar que fueron billetes.
            if (res.status === 409 && data.confirmar_efectivo) {
                btn.disabled = false;
                btn.textContent = '💰 Registrar';
                if (confirm(data.mensaje)) {
                    this._confirmoEfectivo = true;
                    return this.guardar();
                }
                return;
            }
            this._confirmoEfectivo = false;

            if (res.status === 409 && data.alerta) {
                // Referencia duplicada — advertir pero dejar al usuario decidir
                if (!confirm(data.mensaje + '\n\n¿Deseas registrarlo de todas formas?')) {
                    btn.disabled = false;
                    btn.textContent = '💰 Registrar';
                    return;
                }
                // Reintentar sin validación de duplicado — por ahora solo avisa
                errDiv.textContent = data.mensaje;
                errDiv.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '💰 Registrar';
                return;
            }

            if (!data.ok) {
                errDiv.textContent = data.mensaje || 'Error al registrar.';
                errDiv.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '💰 Registrar';
                return;
            }

            // Éxito
            this.cerrar();

            // Cerrar el modal de facturar (si existe)
            if (typeof MF !== 'undefined' && typeof MF.cerrar === 'function') {
                MF.cerrar();
            }

            // Abrir el recibo de anticipo
            if (typeof abrirRecibo === 'function') {
                abrirRecibo(`/admin/anticipos/${data.anticipo_id}/recibo?modal=1`);
            } else {
                window.open(`/admin/anticipos/${data.anticipo_id}/recibo`, '_blank');
            }

            // Toast de confirmación
            const toast = document.createElement('div');
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:linear-gradient(135deg,#78350f,#d97706);color:#fff;padding:.65rem 1.2rem;border-radius:10px;font-size:.83rem;font-weight:700;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;gap:.5rem;';
            toast.innerHTML = '💰 Anticipo registrado exitosamente';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3500);

            if (this._onSuccess) this._onSuccess(data);

        } catch(e) {
            errDiv.textContent = 'Error de red: ' + e.message;
            errDiv.style.display = 'block';
        }

        btn.disabled = false;
        btn.textContent = '💰 Registrar';
    }
};

// ══════════════════════════════════════════
// Módulo MF_ANT: Anticipos en modal facturar
// ══════════════════════════════════════════
window.MF_ANT = {
    _anticipos: [],    // lista completa cargada de la API
    _seleccionados: new Set(),

    // Carga anticipos disponibles para un contrato o empresa
    async cargar(contratoId, empresaId, contratoIdsSeleccionados = null) {
        this._anticipos = [];
        this._seleccionados.clear();
        this._render();

        let url = null;
        let esModoEmpresa = false;

        if (contratoId) {
            url = `/admin/anticipos/api/contrato/${contratoId}`;
        } else if (empresaId) {
            url = `/admin/anticipos/api/empresa/${empresaId}`;
            esModoEmpresa = true;
        }
        
        if (!url) {
            document.getElementById('mf-anticipo-panel').style.display = 'none';
            return;
        }

        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            
            if (esModoEmpresa) {
                // Para empresa, aplanamos individuales y empresariales marcando su origen
                let individuales = (data.individuales || []).map(a => ({ ...a, tipo_anticipo: 'individual' }));
                
                // Si se especifican contratos seleccionados para facturar, filtramos los individuales
                if (contratoIdsSeleccionados && contratoIdsSeleccionados.length > 0) {
                    individuales = individuales.filter(a => contratoIdsSeleccionados.includes(a.contrato_id));
                }

                const empresariales = (data.empresariales || []).map(a => ({ ...a, tipo_anticipo: 'empresa' }));
                this._anticipos = [...individuales, ...empresariales];
            } else {
                // Para contrato individual, todos se tratan como individuales
                this._anticipos = (data.anticipos || []).map(a => ({ ...a, tipo_anticipo: 'individual' }));
            }

            // Preseleccionar automáticamente los anticipos individuales
            this._anticipos.forEach(a => {
                if (a.tipo_anticipo === 'individual') {
                    this._seleccionados.add(a.id);
                }
            });

        } catch(e) {
            console.error("Error al cargar anticipos:", e);
            this._anticipos = [];
        }

        this._render();
    },

    _render() {
        const panel = document.getElementById('mf-anticipo-panel');
        const body  = document.getElementById('mf-anticipo-body');
        const badge = document.getElementById('mf-anticipo-total-badge');

        if (!this._anticipos.length) {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = 'block';
        const totalDisp = this._anticipos.reduce((s,a) => s + a.valor_disponible, 0);
        badge.textContent = '$' + totalDisp.toLocaleString('es-CO');

        const individuales = this._anticipos.filter(a => a.tipo_anticipo === 'individual');
        const empresariales = this._anticipos.filter(a => a.tipo_anticipo === 'empresa');

        const renderRow = (a) => {
            const isChecked = this._seleccionados.has(a.id) ? 'checked' : '';
            return `
                <div class="mf-ant-row">
                    <input type="checkbox" class="mf-ant-cb"
                        id="mf-ant-${a.id}"
                        data-id="${a.id}" data-val="${a.valor_disponible}"
                        data-forma="${a.forma_pago}"
                        ${isChecked}
                        onchange="MF_ANT.toggle(${a.id}, this.checked)">
                    <div class="mf-ant-info">
                        <span>
                            ${a.forma_label}${a.referencia ? ' · <em>' + a.referencia + '</em>' : ''}
                            ${a.cliente_nombre && a.tipo_anticipo === 'individual' ? ' · <b style="color:#b45309;">' + a.cliente_nombre + '</b>' : ''}
                        </span>
                        <span class="mf-ant-fecha">${a.fecha_pago}</span>
                    </div>
                    <span class="mf-ant-monto">$${a.valor_disponible.toLocaleString('es-CO')}</span>
                </div>
            `;
        };

        let html = '';
        if (individuales.length > 0) {
            html += `<div style="font-size:.55rem;font-weight:800;color:#92400e;text-transform:uppercase;margin:.2rem 0 .15rem;border-bottom:1px dashed #fcd34d;padding-bottom:.05rem;letter-spacing:.04em;">── Anticipos Individuales ──</div>`;
            html += individuales.map(renderRow).join('');
        }
        if (empresariales.length > 0) {
            html += `<div style="font-size:.55rem;font-weight:800;color:#92400e;text-transform:uppercase;margin:.4rem 0 .15rem;border-bottom:1px dashed #fcd34d;padding-bottom:.05rem;letter-spacing:.04em;">── Anticipos de Empresa ──</div>`;
            html += empresariales.map(renderRow).join('');
        }

        body.innerHTML = html;
        this._actualizarSubtotal();
    },

    toggleBody() {
        const body = document.getElementById('mf-anticipo-body');
        body.style.display = body.style.display === 'none' ? 'flex' : 'none';
    },

    toggle(id, checked) {
        if (checked) this._seleccionados.add(id);
        else this._seleccionados.delete(id);
        this._actualizarSubtotal();
        if (typeof MF !== 'undefined') MF.recalc();
    },

    _actualizarSubtotal() {
        const selDiv = document.getElementById('mf-anticipo-sel-total');
        const total = this._anticipos
            .filter(a => this._seleccionados.has(a.id))
            .reduce((s,a) => s + a.valor_disponible, 0);
        selDiv.textContent = '$' + total.toLocaleString('es-CO');
        return total;
    },

    // Total actualmente seleccionado (usado por MF.recalc())
    totalSeleccionado() {
        return this._anticipos
            .filter(a => this._seleccionados.has(a.id))
            .reduce((s,a) => s + a.valor_disponible, 0);
    },

    // IDs seleccionados para enviar al backend
    ids() {
        return [...this._seleccionados];
    },

    // Seleccionar todos
    seleccionarTodos(checked) {
        this._anticipos.forEach(a => {
            const cb = document.getElementById(`mf-ant-${a.id}`);
            if (cb) { cb.checked = checked; }
            if (checked) this._seleccionados.add(a.id);
            else this._seleccionados.delete(a.id);
        });
        this._actualizarSubtotal();
        if (typeof MF !== 'undefined') MF.recalc();
    },
};
</script>
