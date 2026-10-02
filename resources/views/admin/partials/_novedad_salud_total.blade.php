{{--
    Modal de novedad de inicio laboral en Salud Total, autocontenido.

    Lo abre `abrirNovedadSaludTotal(contratoId)` desde el radicado de EPS. Un
    solo botón: al pulsarlo el servidor consulta y radica en la misma pasada —si
    la persona ya tiene novedad o ya está activa con la empresa no repite—, y va
    contando por dónde anda. Todo por HTTP: tarda segundos, no minutos.
--}}
<style>
.stn-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.stn-bg.open { display:flex }
.stn-box { background:#fff;border-radius:14px;max-width:540px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:92vh;overflow-y:auto }
.stn-head { background:linear-gradient(135deg,#15803d,#22c55e);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.stn-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.stn-x { background:none;border:none;color:#dcfce7;font-size:1.15rem;cursor:pointer;line-height:1 }
.stn-body { padding:1.1rem }
.stn-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.stn-resumen span { color:#64748b }
.stn-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.stn-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.stn-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.stn-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.stn-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#15803d,#22c55e);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.stn-btn.sec { background:#fff;color:#15803d;border:1px solid #86efac }
.stn-btn:disabled { opacity:.5;cursor:not-allowed }
.stn-robot { background:#f1f5f9;border:1px solid #cbd5e1;border-radius:9px;padding:.6rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#334155;display:flex;align-items:center;gap:.5rem }
.stn-robot b { font-weight:700 }
</style>

<div class="stn-bg" id="stnModal">
  <div class="stn-box">
    <div class="stn-head">
      <h3>🏥 Novedad de inicio laboral en Salud Total</h3>
      <button class="stn-x" onclick="cerrarNovedadSaludTotal()">✕</button>
    </div>
    <div class="stn-body">
      <div id="stnCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>

      <div id="stnContenido" style="display:none">
        <div class="stn-resumen" id="stnResumen"></div>
        <div class="stn-prob" id="stnProblemas" style="display:none">
          <strong>No se puede tramitar todavía:</strong>
          <ul id="stnProblemasLista"></ul>
        </div>
        <div class="stn-robot" id="stnRobot" style="display:none"></div>
        <div class="stn-aviso" id="stnAviso" style="display:none"></div>

        <button class="stn-btn" id="stnBtnAfiliar" onclick="afiliarSaludTotal()">🏥 Registrar afiliación</button>
      </div>

      <div id="stnResultado" class="stn-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let stnContratoId = null;
const STN_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const stnEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function cerrarNovedadSaludTotal() { document.getElementById('stnModal').classList.remove('open'); }

function stnEsperar(btn, texto) {
    const desde = Date.now();
    const pintar = () => btn.textContent = `⏳ ${texto} ${Math.round((Date.now() - desde) / 1000)}s`;
    btn.disabled = true; pintar();
    const reloj = setInterval(pintar, 1000);
    return () => clearInterval(reloj);
}

/**
 * Mientras el servidor trabaja le pregunta por dónde va, cada segundo y medio,
 * y lo pinta con el reloj: así se ve que avanza y, si se atasca, en qué paso.
 */
function stnSeguirPasos() {
    const caja = document.getElementById('stnRobot');
    const desde = Date.now();
    let paso = 'Empezando';
    let vivo = true;

    const pintar = () => caja.innerHTML = `🤖 <b>${stnEsc(paso)}</b>… <span style="color:#64748b">${Math.round((Date.now() - desde) / 1000)}s</span>`;
    caja.style.display = 'flex';
    pintar();
    const reloj = setInterval(pintar, 1000);

    (async () => {
        while (vivo) {
            await new Promise(r => setTimeout(r, 1500));
            if (!vivo) return;
            try {
                const p = await stnPedir(`/admin/afiliaciones/${stnContratoId}/salud-total/progreso`, 'GET', 15);
                if (p && p.paso) paso = p.paso;
            } catch (e) { /* si el contador falla, el trámite sigue igual */ }
        }
    })();

    return (dejarVisible) => {
        vivo = false;
        clearInterval(reloj);
        if (dejarVisible) { paso = dejarVisible; pintar(); } else { caja.style.display = 'none'; }
    };
}

async function stnPedir(url, metodo, limiteSeg) {
    const corte = new AbortController();
    const alarma = setTimeout(() => corte.abort(), limiteSeg * 1000);
    try {
        const r = await fetch(url, { method: metodo, signal: corte.signal,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': STN_CSRF } });
        return await r.json();
    } finally {
        clearTimeout(alarma);
    }
}

function stnPintarResumen(r) {
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${stnEsc(v)}</strong></div>` : '';
    document.getElementById('stnResumen').innerHTML =
        li('Trabajador', `${r.trabajador} — ${r.documento}`) +
        li('Empresa', `${r.razon_social} (NIT ${r.nit})`) +
        li('Plan / EPS', `${r.plan ?? '—'} · ${r.eps ?? '—'}`) +
        li('Tipo de cotizante', r.cotizante) +
        li('IBC', r.ibc ? '$' + Number(r.ibc).toLocaleString('es-CO') : null) +
        li('Fecha de ingreso', r.fecha_inicio + (r.fuera_de_plazo ? ' ⚠️ fuera del plazo del portal' : ''));
}

async function abrirNovedadSaludTotal(contratoId) {
    stnContratoId = contratoId;
    ['stnContenido', 'stnResultado', 'stnAviso', 'stnRobot'].forEach(id => document.getElementById(id).style.display = 'none');
    document.getElementById('stnCargando').style.display = 'block';
    document.getElementById('stnCargando').textContent = '⏳ Revisando los datos del contrato...';
    document.getElementById('stnModal').classList.add('open');

    let d;
    try {
        d = await stnPedir(`/admin/afiliaciones/${contratoId}/salud-total/precheck`, 'GET', 30);
    } catch (e) {
        document.getElementById('stnCargando').textContent = '⚠️ No se pudo revisar el contrato.';
        return;
    }

    document.getElementById('stnCargando').style.display = 'none';
    document.getElementById('stnContenido').style.display = 'block';
    stnPintarResumen(d.resumen || {});

    const problemas = d.problemas || [];
    document.getElementById('stnProblemas').style.display = problemas.length ? 'block' : 'none';
    document.getElementById('stnProblemasLista').innerHTML = problemas.map(p => `<li>${stnEsc(p)}</li>`).join('');
    const fuera = d.resumen && d.resumen.fuera_de_plazo;
    const aviso = document.getElementById('stnAviso');

    if (fuera) {
        aviso.innerHTML = '⚠️ ' + stnEsc(fuera) + '<br>Se puede intentar igual: si ya tiene novedad radicada o ya está activo con la empresa, se vincula.';
        aviso.style.display = 'block';
    }

    const btn = document.getElementById('stnBtnAfiliar');
    btn.disabled = problemas.length > 0;
    btn.textContent = problemas.length ? '🚫 Completa los datos primero' : '🏥 Registrar afiliación';
}

/**
 * Un solo paso: el servidor entra al portal, mira cómo está la persona y radica.
 *
 * La consulta va por dentro porque es la misma que hace falta para no repetir:
 * si ya tiene novedad se vincula, si ya está activa con la empresa el radicado
 * pasa a OK, y si no está en Salud Total o el nombre no cuadra se detiene sin
 * radicar y lo dice.
 */
async function afiliarSaludTotal() {
    if (!confirm('¿Registrar la afiliación en Salud Total con estos datos?\n\nSe consulta primero: si ya tiene novedad o ya está activo con la empresa, no se repite.\nLo que quede radicado en el portal no se puede anular desde aquí.')) return;

    const btn = document.getElementById('stnBtnAfiliar');
    const parar = stnEsperar(btn, 'Afiliando en Salud Total...');
    const pararPasos = stnSeguirPasos();
    let d;
    try {
        d = await stnPedir(`/admin/afiliaciones/${stnContratoId}/salud-total/registrar`, 'POST', 280);
    } catch (e) {
        // No se reintenta solo: pudo quedar radicada. Al reintentar, la consulta
        // de adentro la encuentra y la vincula en vez de duplicarla.
        d = { ok: false, error: 'Se perdió la conexión con el servidor. Puedes volver a intentarlo: si quedó radicada, se vincula sin repetirla.' };
    }
    parar();
    pararPasos(d.ok ? null : 'Se quedó aquí');

    if (!d.ok) {
        btn.disabled = false; btn.textContent = '🏥 Reintentar';
        document.getElementById('stnAviso').innerHTML = '⚠️ ' + stnEsc(d.error || 'No se pudo registrar.');
        document.getElementById('stnAviso').style.display = 'block';
        return;
    }

    const familia = d.beneficiarios && d.beneficiarios.total
        ? `<br><span style="color:#475569">Grupo familiar: ${d.beneficiarios.total} beneficiario${d.beneficiarios.total === 1 ? '' : 's'}` +
          (d.beneficiarios.nuevos ? ` · ${d.beneficiarios.nuevos} guardado${d.beneficiarios.nuevos === 1 ? '' : 's'} en BryNex` : ' · ya estaban en BryNex') + '</span>'
        : '';

    document.getElementById('stnContenido').style.display = 'none';
    const caja = document.getElementById('stnResultado');
    caja.innerHTML = (d.ya_existia
        ? `🔗 Ya tenía novedad en Salud Total: formulario <strong>${stnEsc(d.radicado)}</strong> (${stnEsc(d.estado_eps)}). Se vinculó al radicado sin volver a radicar.`
        : d.ya_activo
        ? `✅ Ya estaba activo con la empresa desde ${stnEsc(d.desde)}. El radicado de EPS quedó en OK.`
        : `✅ Afiliación registrada en Salud Total: formulario <strong>${stnEsc(d.radicado)}</strong><br>` +
          `<span style="color:#475569">El radicado de EPS quedó en trámite${d.pdf ? ' con el Formulario Único adjunto' : ' (sin PDF)'}. Pasa a OK cuando Salud Total la apruebe.</span>`)
        + (d.nombre_eps ? `<br><span style="color:#475569">En Salud Total: ${stnEsc(d.nombre_eps)}</span>` : '')
        + familia;
    caja.style.display = 'block';
    setTimeout(() => location.reload(), 4000);
}

</script>
