{{-- Estilos del módulo de cotizaciones (listado, nueva y detalle). --}}
<style>
.cz-page {
    font-family: 'Inter', sans-serif;
    max-width: 1200px; margin: 0 auto;
    display: flex; flex-direction: column; gap: 1rem;
}

/* ── Encabezado ── */
.cz-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap;
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 60%, #3b82f6 100%);
    border-radius: 16px; padding: 1.1rem 1.4rem;
    box-shadow: 0 4px 20px rgba(37,99,235,.3);
}
.cz-header-left { display: flex; align-items: center; gap: .9rem; min-width: 0; }
.cz-header-icon {
    width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
}
.cz-title    { margin: 0; font-size: 1.12rem; font-weight: 700; color: #fff; line-height: 1.25; }
.cz-subtitle { margin: .2rem 0 0; font-size: .8rem; color: rgba(255,255,255,.82); display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.cz-count    { background: rgba(255,255,255,.2); color: #fff; padding: .1rem .55rem; border-radius: 99px; font-weight: 700; font-size: .78rem; }
.cz-header-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.cz-btn-header {
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
    background: rgba(255,255,255,.18); border: 1.5px solid rgba(255,255,255,.35);
    color: #fff; font-weight: 600; font-size: .83rem; text-decoration: none; font-family: inherit;
    padding: .55rem 1.05rem; border-radius: 10px; cursor: pointer; white-space: nowrap;
    transition: background .2s, transform .2s;
}
.cz-btn-header:hover { background: rgba(255,255,255,.28); transform: translateY(-1px); color: #fff; }
.cz-btn-header--solido { background: #10b981; border-color: #10b981; }
.cz-btn-header--solido:hover { background: #059669; }

/* ── Avisos ── */
.cz-flash {
    display: flex; align-items: flex-start; gap: .6rem;
    border-radius: 10px; padding: .65rem 1rem; font-size: .83rem; font-weight: 500;
}
.cz-flash ul { margin: .25rem 0 0 1rem; font-weight: 400; }
.cz-flash--ok    { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.25); border-left: 3px solid #10b981; color: #065f46; }
.cz-flash--info  { background: #eff6ff; border: 1px solid #bfdbfe; border-left: 3px solid #3b82f6; color: #1e40af; }
.cz-flash--error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3); border-left: 3px solid #ef4444; color: #991b1b; }

/* ── Botones ── */
.cz-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    height: 38px; padding: 0 1.1rem; border-radius: 8px;
    font-size: .84rem; font-weight: 600; font-family: inherit;
    text-decoration: none; cursor: pointer; white-space: nowrap;
    border: 1.5px solid transparent; transition: all .15s;
}
.cz-btn--primario { background: #2563eb; color: #fff; box-shadow: 0 2px 8px rgba(37,99,235,.3); }
.cz-btn--primario:hover { background: #1d4ed8; transform: translateY(-1px); color: #fff; }
.cz-btn--primario:disabled { opacity: .6; cursor: wait; transform: none; }
.cz-btn--borde { background: #fff; border-color: #e2e8f0; color: #475569; }
.cz-btn--borde:hover { background: #f1f5f9; border-color: #cbd5e1; }
.cz-btn--texto { background: none; color: #64748b; padding: 0 .6rem; }
.cz-btn--texto:hover { color: #0f172a; }
.cz-btn--chico { height: 32px; padding: 0 .8rem; font-size: .78rem; }

/* ── Tarjetas ── */
.cz-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 1.1rem 1.25rem; box-shadow: 0 1px 6px rgba(0,0,0,.04);
}
.cz-card-head {
    display: flex; align-items: center; justify-content: space-between; gap: .6rem;
    margin-bottom: 1rem; padding-bottom: .7rem; border-bottom: 1px solid #f1f5f9;
}
.cz-card-title { display: flex; align-items: center; gap: .6rem; margin: 0; font-size: .95rem; font-weight: 700; color: #0f172a; }
.cz-step {
    width: 26px; height: 26px; border-radius: 8px; flex-shrink: 0;
    background: #eff6ff; color: #1d4ed8; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* ── Formulario: una sola rejilla de 4 columnas para que todo quede alineado ── */
.cz-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1.25rem; align-items: start; }
.cz-col-form { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.cz-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .9rem 1rem; }
.cz-field { display: flex; flex-direction: column; min-width: 0; }
.cz-span2 { grid-column: span 2; }
.cz-label {
    font-size: .72rem; font-weight: 600; color: #475569; margin-bottom: .3rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cz-req { color: #ef4444; }
.cz-hint { font-size: .7rem; color: #94a3b8; margin-top: .25rem; }
.cz-input {
    width: 100%; height: 38px; padding: 0 .7rem;
    border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: .86rem; font-family: inherit; color: #0f172a; background: #fff;
    transition: border-color .15s, box-shadow .15s;
    font-variant-numeric: tabular-nums;
}
.cz-input::placeholder { color: #94a3b8; }
.cz-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); position: relative; z-index: 1; }
textarea.cz-input { height: auto; padding: .55rem .7rem; line-height: 1.45; resize: vertical; }
select.cz-input {
    appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 1.9rem;
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.6' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right .5rem center; background-repeat: no-repeat; background-size: 1.15em;
    text-overflow: ellipsis;
}
/* Campos pegados: tipo + número de documento, celular + WhatsApp */
.cz-group { display: flex; min-width: 0; }
.cz-group > * { border-radius: 0; margin-left: -1.5px; }
.cz-group > :first-child { border-radius: 8px 0 0 8px; margin-left: 0; }
.cz-group > :last-child  { border-radius: 0 8px 8px 0; }
.cz-group > .cz-input { flex: 1; min-width: 0; }
.cz-group > .cz-group-fijo { flex: 0 0 74px; width: 74px; padding-left: .55rem; padding-right: 1.5rem; }
.cz-wa {
    flex: 0 0 40px; height: 38px;
    display: inline-flex; align-items: center; justify-content: center;
    background: #25d366; border: 1.5px solid #25d366; color: #fff; transition: background .15s;
}
.cz-wa:hover { background: #1ebe5b; color: #fff; }
.cz-wa--off { background: #f1f5f9; border-color: #e2e8f0; color: #cbd5e1; pointer-events: none; }
/* Valores en pesos */
.cz-money { position: relative; }
.cz-money::before {
    content: '$'; position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .86rem; z-index: 2; pointer-events: none;
}
.cz-money .cz-input { padding-left: 1.45rem; }

/* Barra de guardar: normal en escritorio, fija abajo en celular */
.cz-actions { display: flex; align-items: center; justify-content: flex-end; gap: .6rem; }
.cz-actions-total { display: none; }

/* ── Resumen de la cotización ── */
.cz-resumen {
    position: sticky; top: 1rem;
    background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 100%);
    border-radius: 14px; color: #f8fafc; padding: 1.1rem 1.2rem;
    box-shadow: 0 10px 25px -8px rgba(15,23,42,.45);
}
.cz-resumen-title {
    margin: 0 0 .8rem; padding-bottom: .65rem; border-bottom: 1px solid rgba(255,255,255,.12);
    font-size: .95rem; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: .5rem;
}
.cz-resumen-estado { font-size: .7rem; font-weight: 500; color: #93c5fd; }
.cz-resumen-vacio { text-align: center; padding: 1.6rem 0; color: #94a3b8; font-size: .82rem; }
.cz-resumen-error { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.35); color: #fecaca; border-radius: 8px; padding: .55rem .7rem; font-size: .78rem; margin-bottom: .7rem; }
.cz-resumen-error button { background: none; border: none; color: #fff; font-weight: 700; text-decoration: underline; cursor: pointer; font-family: inherit; font-size: inherit; }
.cz-resumen-cuerpo { transition: opacity .15s; }
.cz-resumen-cuerpo.cz-calculando { opacity: .45; }
.cz-fila {
    display: grid; grid-template-columns: minmax(0, 1fr) 84px 84px; gap: .4rem; align-items: baseline;
    font-size: .8rem; padding: .38rem 0; border-bottom: 1px solid rgba(255,255,255,.08);
    font-variant-numeric: tabular-nums;
}
.cz-sin-prop .cz-fila { grid-template-columns: minmax(0, 1fr) 96px; }
.cz-sin-prop .cz-col-prop { display: none; }
.cz-fila > :not(:first-child) { text-align: right; }
.cz-fila--head > div { white-space: nowrap; }
.cz-fila--head { font-size: .66rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; align-items: end; }
.cz-fila--fijo { color: #93c5fd; }
.cz-col-prop { color: #cbd5e1; }
.cz-pct { color: #94a3b8; font-size: .68rem; }
.cz-totales { margin-top: .9rem; background: rgba(2,6,23,.5); border-radius: 10px; padding: .2rem .85rem; }
.cz-total { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .65rem 0; border-bottom: 1px solid rgba(255,255,255,.1); font-variant-numeric: tabular-nums; }
.cz-total:last-child { border-bottom: none; }
.cz-total-nombre { font-size: .82rem; font-weight: 600; }
.cz-total-detalle { font-size: .68rem; color: #94a3b8; margin-top: .1rem; }
.cz-total-valor { font-size: 1.05rem; font-weight: 700; white-space: nowrap; }
.cz-total-valor--afiliacion { color: #fcd34d; }
.cz-total-valor--prop { color: #93c5fd; }
.cz-total-valor--mes { color: #34d399; font-size: 1.3rem; font-weight: 800; }

/* ── Estados ── */
.cz-estado {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .25rem .7rem; border-radius: 99px;
    font-size: .73rem; font-weight: 700; white-space: nowrap;
}
.cz-estado::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; opacity: .75; }
.cz-estado--interesado     { background: #dbeafe; color: #1d4ed8; }
.cz-estado--sin_respuesta  { background: #f1f5f9; color: #475569; }
.cz-estado--pendiente_resp { background: #fef3c7; color: #b45309; }
.cz-estado--no_interesado  { background: #fee2e2; color: #b91c1c; }
.cz-estado--convertido     { background: #dcfce7; color: #15803d; }

/* ── Listado: pestañas por estado ── */
.cz-tabs { display: flex; gap: .45rem; overflow-x: auto; padding-bottom: .15rem; scrollbar-width: none; }
.cz-tabs::-webkit-scrollbar { display: none; }
.cz-tab {
    display: inline-flex; align-items: center; gap: .45rem; flex-shrink: 0;
    padding: .45rem .85rem; border-radius: 99px;
    background: #fff; border: 1.5px solid #e2e8f0; color: #475569;
    font-size: .8rem; font-weight: 600; text-decoration: none; white-space: nowrap; transition: all .15s;
}
.cz-tab:hover { border-color: #93c5fd; color: #1d4ed8; }
.cz-tab b { background: #f1f5f9; color: #475569; border-radius: 99px; padding: .05rem .45rem; font-size: .72rem; font-weight: 700; }
.cz-tab--activo { background: #2563eb; border-color: #2563eb; color: #fff; }
.cz-tab--activo:hover { color: #fff; }
.cz-tab--activo b { background: rgba(255,255,255,.22); color: #fff; }
.cz-tab--alerta { border-color: #fecaca; color: #b91c1c; }
.cz-tab--alerta b { background: #fee2e2; color: #b91c1c; }
.cz-tab--alerta.cz-tab--activo { background: #dc2626; border-color: #dc2626; color: #fff; }
.cz-tab--alerta.cz-tab--activo b { background: rgba(255,255,255,.22); color: #fff; }

/* ── Listado: filtros ── */
.cz-filtros {
    display: grid; grid-template-columns: minmax(0, 2fr) repeat(2, minmax(0, 1fr)) auto auto; gap: .6rem; align-items: center;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: .75rem 1rem;
    box-shadow: 0 1px 6px rgba(0,0,0,.04);
}
.cz-buscar { position: relative; min-width: 0; }
.cz-buscar svg { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); pointer-events: none; }
.cz-buscar .cz-input { padding-left: 2.2rem; }
.cz-filtros .cz-input { background-color: #f8fafc; }
.cz-filtros .cz-input:focus { background-color: #fff; }

/* ── Listado: tabla ── */
.cz-tabla-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow-x: auto; box-shadow: 0 1px 8px rgba(0,0,0,.05); }
.cz-tabla { width: 100%; border-collapse: collapse; font-size: .84rem; }
.cz-tabla th {
    padding: .75rem 1rem; text-align: left; white-space: nowrap;
    font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em;
    background: #f8fafc; border-bottom: 1.5px solid #e2e8f0;
}
.cz-tabla td { padding: .7rem 1rem; vertical-align: middle; color: #475569; }
.cz-tabla tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
.cz-tabla tbody tr:last-child { border-bottom: none; }
.cz-tabla tbody tr:hover { background: #fafbff; }
.cz-num { text-align: right !important; font-variant-numeric: tabular-nums; }
.cz-persona { display: flex; align-items: center; gap: .7rem; min-width: 0; }
.cz-avatar {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 800; text-transform: uppercase;
    background: #f1f5f9; color: #64748b;
}
.cz-avatar--interesado, .cz-avatar--convertido { background: #dbeafe; color: #1d4ed8; }
.cz-avatar--no_interesado { background: #fee2e2; color: #b91c1c; }
.cz-nombre { display: block; color: #0f172a; font-weight: 600; font-size: .87rem; text-decoration: none; line-height: 1.3; }
.cz-nombre:hover { color: #2563eb; }
.cz-sub { font-size: .74rem; color: #94a3b8; margin-top: .1rem; font-variant-numeric: tabular-nums; }
.cz-contacto { display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap; font-weight: 500; color: #0f172a; font-variant-numeric: tabular-nums; }
.cz-contacto a.cz-tel { color: inherit; text-decoration: none; }
.cz-wa-mini {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 7px; background: #dcfce7; color: #16a34a; text-decoration: none; transition: background .15s;
}
.cz-wa-mini:hover { background: #bbf7d0; }
.cz-chip { display: inline-flex; align-items: center; background: #eff6ff; color: #1d4ed8; padding: .22rem .6rem; border-radius: 7px; font-size: .76rem; font-weight: 600; white-space: nowrap; }
.cz-vacio-dato { color: #cbd5e1; }
.cz-llamada { white-space: nowrap; font-weight: 500; color: #2563eb; font-variant-numeric: tabular-nums; }
.cz-llamada--vencida { color: #dc2626; font-weight: 700; }
.cz-valor { font-weight: 700; color: #0f172a; white-space: nowrap; }
.cz-td-valor .cz-sub { white-space: nowrap; }
.cz-btn-abrir {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .38rem .8rem; border-radius: 8px; border: 1.5px solid #bfdbfe; background: #eff6ff;
    color: #1d4ed8; font-size: .78rem; font-weight: 700; text-decoration: none; white-space: nowrap; transition: all .15s;
}
.cz-btn-abrir:hover { background: #2563eb; border-color: #2563eb; color: #fff; }
.cz-etiqueta { display: none; }
.cz-vacio { text-align: center; padding: 3rem 1rem !important; color: #94a3b8; font-size: .9rem; }
.cz-vacio svg { display: block; margin: 0 auto .75rem; }

/* ── Paginación ── */
.cz-paginacion {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: .7rem 1rem;
}
.cz-pag-info { font-size: .8rem; color: #64748b; }
.cz-pag-info strong { color: #0f172a; font-weight: 600; }
.cz-pag-controles { display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; }
.cz-pag-btn, .cz-pag-num {
    display: inline-flex; align-items: center; justify-content: center;
    height: 34px; padding: 0 .75rem; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #fff;
    color: #334155; font-size: .8rem; font-weight: 600; text-decoration: none; white-space: nowrap; transition: all .15s;
}
.cz-pag-num { width: 34px; padding: 0; }
a.cz-pag-btn:hover, a.cz-pag-num:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.cz-pag-btn--off { background: #f8fafc; border-color: #f1f5f9; color: #cbd5e1; }
.cz-pag-num--activo { background: #2563eb; border-color: #2563eb; color: #fff; }

/* ── Detalle: gestiones ── */
.cz-gestion-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; }
.cz-gestion-form .cz-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.cz-gestion-form .cz-span-todo { grid-column: 1 / -1; }
.cz-gestiones { display: flex; flex-direction: column; }
.cz-gestion { display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: .75rem; padding: .75rem 0; border-bottom: 1px solid #f1f5f9; }
.cz-gestion:last-child { border-bottom: none; padding-bottom: 0; }
.cz-gestion-icono { width: 34px; height: 34px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; }
.cz-gestion-cab { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; font-size: .8rem; }
.cz-gestion-cab strong { color: #0f172a; font-weight: 600; }
.cz-gestion-fecha { color: #94a3b8; font-size: .74rem; margin-left: auto; white-space: nowrap; }
.cz-gestion-texto { margin: .25rem 0 0; font-size: .83rem; color: #475569; line-height: 1.5; overflow-wrap: anywhere; }
.cz-gestion-prox { display: inline-flex; align-items: center; gap: .3rem; margin-top: .4rem; font-size: .72rem; font-weight: 600; color: #b45309; background: #fef3c7; padding: .15rem .5rem; border-radius: 6px; }
.cz-ver-mas { background: none; border: none; color: #2563eb; font-size: .78rem; font-weight: 600; cursor: pointer; padding: .6rem 0 0; font-family: inherit; }
.cz-ia-nota { display: flex; align-items: center; gap: .5rem; font-size: .76rem; color: #bfdbfe; background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.3); border-radius: 8px; padding: .45rem .65rem; margin-bottom: .75rem; }

/* ════ Tableta: el resumen baja y la barra de guardar queda fija ════ */
@media (max-width: 1100px) {
    .cz-layout { grid-template-columns: minmax(0, 1fr); }
    .cz-resumen { position: static; }
    .cz-page--form { padding-bottom: 5rem; }
    .cz-actions {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 900;
        background: #fff; border-top: 1px solid #e2e8f0;
        padding: .6rem 1rem calc(.6rem + env(safe-area-inset-bottom));
        box-shadow: 0 -6px 20px rgba(15,23,42,.1);
    }
    .cz-actions-total { display: block; margin-right: auto; line-height: 1.2; min-width: 0; }
    .cz-actions-total small { display: block; font-size: .68rem; color: #64748b; font-weight: 500; }
    .cz-actions-total strong { font-size: 1.05rem; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; }
    .cz-filtros { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cz-buscar { grid-column: 1 / -1; }
}

/* ════ Celular ════ */
@media (max-width: 720px) {
    .cz-header { padding: .9rem 1rem; border-radius: 14px; }
    .cz-header-icon { display: none; }
    .cz-title { font-size: 1rem; }
    .cz-header-actions { width: 100%; }
    .cz-header-actions > *, .cz-header-actions form { flex: 1; }
    .cz-header-actions form .cz-btn-header { width: 100%; }
    .cz-card { padding: 1rem; }

    /* Campos a 16px: con menos, iOS hace zoom al tocarlos */
    .cz-input { height: 44px; font-size: 16px; }
    .cz-label { font-size: .76rem; }
    .cz-wa { height: 44px; flex-basis: 46px; }
    .cz-group > .cz-group-fijo { flex-basis: 84px; width: 84px; }
    .cz-btn { height: 44px; }
    .cz-btn--chico { height: 38px; }
    .cz-grid, .cz-gestion-form .cz-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cz-field { grid-column: 1 / -1; }
    .cz-field.cz-m-mitad { grid-column: span 1; }
    .cz-actions .cz-btn--borde { display: none; }
    .cz-fila { grid-template-columns: minmax(0, 1fr) 82px 82px; font-size: .8rem; }

    .cz-filtros .cz-btn { width: 100%; }

    /* La tabla se vuelve una lista de tarjetas */
    .cz-tabla-wrap { background: none; border: none; box-shadow: none; overflow: visible; }
    .cz-tabla thead { display: none; }
    .cz-tabla, .cz-tabla tbody { display: block; }
    .cz-tabla tbody { display: flex; flex-direction: column; gap: .65rem; }
    .cz-tabla tbody tr {
        display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .55rem .75rem; align-items: center;
        grid-template-areas: "persona estado" "contacto valor" "origen fecha" "llamada abrir";
        background: #fff; border: 1px solid #e2e8f0 !important; border-radius: 12px; padding: .85rem .9rem;
        box-shadow: 0 1px 6px rgba(0,0,0,.04);
    }
    .cz-tabla td { display: block; padding: 0; min-width: 0; }
    .cz-td-persona  { grid-area: persona; }
    .cz-td-estado   { grid-area: estado; justify-self: end; }
    .cz-td-contacto { grid-area: contacto; }
    .cz-td-valor    { grid-area: valor; justify-self: end; text-align: right; }
    .cz-td-origen   { grid-area: origen; display: flex !important; align-items: center; flex-wrap: wrap; gap: .3rem .6rem; }
    .cz-td-origen .cz-sub { margin-top: 0; }
    .cz-td-fecha    { grid-area: fecha; justify-self: end; font-size: .78rem; }
    .cz-td-llamada  { grid-area: llamada; font-size: .8rem; }
    .cz-td-abrir    { grid-area: abrir; justify-self: end; }
    .cz-solo-escritorio { display: none !important; }
    .cz-etiqueta { display: inline; color: #94a3b8; font-weight: 500; margin-right: .2rem; }
    .cz-wa-mini { width: 34px; height: 34px; }
    .cz-btn-abrir { padding: .55rem 1rem; }
    .cz-tabla tbody tr.cz-fila-vacia { display: block; }

    .cz-paginacion { justify-content: center; }
    .cz-pag-info { display: none; }
    .cz-gestion-fecha { margin-left: 0; width: 100%; }
}
</style>
