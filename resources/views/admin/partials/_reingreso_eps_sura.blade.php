{{--
    Modal de reingreso en EPS SURA, autocontenido.

    Lo abre `abrirReingresoEpsSura(contratoId)` desde el radicado de EPS. Va en
    pasos, para no radicar a ciegas: revisar (sin portal) → consultar en SURA
    (dice si la persona existe para reingreso) → radicar.

    Si SURA no devuelve el nombre, la persona no está en esa EPS: eso es un
    traslado y por esta pantalla del portal no se hace.
--}}
<style>
.esu-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.esu-bg.open { display:flex }
.esu-box { background:#fff;border-radius:14px;max-width:540px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:92vh;overflow-y:auto }
.esu-head { background:linear-gradient(135deg,#0033a0,#2563eb);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.esu-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.esu-x { background:none;border:none;color:#bfdbfe;font-size:1.15rem;cursor:pointer;line-height:1 }
.esu-body { padding:1.1rem }
.esu-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.esu-resumen span { color:#64748b }
.esu-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.esu-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.esu-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.esu-info { background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#1e3a8a;line-height:1.55 }
.esu-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.esu-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#0033a0,#2563eb);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.esu-btn.sec { background:#fff;color:#0033a0;border:1px solid #bfdbfe }
.esu-btn:disabled { opacity:.5;cursor:not-allowed }
.esu-link { background:none;border:none;color:#64748b;font-size:.7rem;cursor:pointer;text-decoration:underline;margin-top:.5rem;padding:0 }
</style>

<div class="esu-bg" id="esuModal">
  <div class="esu-box">
    <div class="esu-head">
      <h3>🏥 Reingreso en EPS SURA</h3>
      <button class="esu-x" onclick="cerrarReingresoEpsSura()">✕</button>
    </div>
    <div class="esu-body">
      <div id="esuCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>

      <div id="esuContenido" style="display:none">
        <div class="esu-resumen" id="esuResumen"></div>
        <div class="esu-prob" id="esuProblemas" style="display:none">
          <strong>No se puede tramitar todavía:</strong>
          <ul id="esuProblemasLista"></ul>
        </div>
        <div class="esu-info" id="esuPortal" style="display:none"></div>
        <div class="esu-aviso" id="esuAviso" style="display:none"></div>

        <button class="esu-btn sec" id="esuBtnConsultar" onclick="consultarEpsSura()">🔎 Consultar en SURA</button>
        <button class="esu-btn" id="esuBtnRegistrar" style="display:none" onclick="registrarEpsSura()">🏥 Radicar reingreso</button>

        {{-- Por extensión: el comprobante del portal —el que muestra solo esta
             empresa— solo se puede capturar desde el navegador de una persona.
             Por el servidor se radica igual, pero ese documento no se obtiene. --}}
        <div style="border-top:1px dashed #e2e8f0;margin:.7rem 0 .4rem"></div>
        <div style="font-size:.7rem;color:#64748b;margin-bottom:.35rem">🧩 Con la extensión se guarda además el comprobante del portal</div>
        <button class="esu-btn sec" id="esuBtnExtAbrir" onclick="abrirPortalEpsSura()">🌐 Abrir EPS SURA</button>
        <button class="esu-btn" id="esuBtnExtRadicar" onclick="radicarConExtensionEpsSura()">🧩 Radicar con la extensión</button>
        {{-- Los ids de la pantalla del portal cambian entre versiones: esto los
             lista tal como están hoy, sin escribir nada. --}}
        <button class="esu-link" id="esuBtnExplorar" onclick="explorarEpsSura()">🔧 Ver los campos del portal (no radica nada)</button>
      </div>

      <div id="esuResultado" class="esu-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let esuContratoId = null, esuPrep = null;
const ESU_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const esuEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const esuEl = id => document.getElementById(id);

function cerrarReingresoEpsSura() {
    esuEl('esuModal').classList.remove('open');
    // Por si el trámite terminó y la fila quedó sin repintar.
    if (esuRadicadoNuevo) { pintarRadicadoEnLista(esuRadicadoNuevo, esuContratoId); esuRadicadoNuevo = null; }
}

// Lo que dejó el trámite, para poner al día la pastilla de EPS de la fila.
let esuRadicadoNuevo = null;

// Consultar y radicar abren un navegador en el servidor: cerca de un minuto.
function esuEsperar(btn, texto) {
    const desde = Date.now();
    const pintar = () => btn.textContent = `⏳ ${texto} ${Math.round((Date.now() - desde) / 1000)}s`;
    btn.disabled = true; pintar();
    const reloj = setInterval(pintar, 1000);
    return () => clearInterval(reloj);
}

async function esuPedir(ruta, metodo, limiteSeg, cuerpo = null) {
    const corte = new AbortController();
    const alarma = setTimeout(() => corte.abort(), limiteSeg * 1000);
    try {
        const r = await fetch(`/admin/afiliaciones/${esuContratoId}/eps-sura/${ruta}`, {
            method: metodo, signal: corte.signal,
            headers: Object.assign({ 'Accept': 'application/json', 'X-CSRF-TOKEN': ESU_CSRF },
                cuerpo ? { 'Content-Type': 'application/json' } : {}),
            body: cuerpo ? JSON.stringify(cuerpo) : undefined,
        });
        return await r.json();
    } finally {
        clearTimeout(alarma);
    }
}

function esuPintarResumen(r) {
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${esuEsc(v)}</strong></div>` : '';
    esuEl('esuResumen').innerHTML =
        li('Trabajador', `${r.trabajador ?? ''} — ${r.documento ?? ''}`) +
        li('Empresa', r.razon_social ? `${r.razon_social} (NIT ${r.nit})` : null) +
        li('EPS', r.eps) +
        li('IBC', r.ibc ? '$' + Number(r.ibc).toLocaleString('es-CO') : null) +
        li('Fecha de ingreso', r.fecha_ingreso) +
        li('Usuario del portal', r.usuario_portal) +
        li('Radicado de EPS', r.estado_radicado ? `${r.estado_radicado}${r.numero_radicado ? ' · ' + r.numero_radicado : ''}` : null);
}

async function abrirReingresoEpsSura(contratoId) {
    esuContratoId = contratoId;
    ['esuContenido', 'esuResultado', 'esuPortal', 'esuAviso'].forEach(id => esuEl(id).style.display = 'none');
    esuEl('esuCargando').style.display = 'block';
    esuEl('esuCargando').textContent = '⏳ Revisando los datos del contrato...';
    esuEl('esuBtnRegistrar').style.display = 'none';
    esuEl('esuModal').classList.add('open');

    let d;
    try { d = await esuPedir('precheck', 'GET', 30); }
    catch (e) { esuEl('esuCargando').textContent = '⚠️ No se pudo revisar el contrato.'; return; }

    esuEl('esuCargando').style.display = 'none';
    esuEl('esuContenido').style.display = 'block';
    esuPrep = d;                      // el resumen y los datos para la extensión
    esuPintarResumen(d.resumen || {});

    const problemas = d.problemas || [];
    esuEl('esuProblemas').style.display = problemas.length ? 'block' : 'none';
    esuEl('esuProblemasLista').innerHTML = problemas.map(p => `<li>${esuEsc(p)}</li>`).join('');

    const avisos = d.avisos || [];
    esuEl('esuAviso').innerHTML = avisos.map(a => '⚠️ ' + esuEsc(a)).join('<br>');
    esuEl('esuAviso').style.display = avisos.length ? 'block' : 'none';

    const btn = esuEl('esuBtnConsultar');
    btn.disabled = problemas.length > 0;
    btn.textContent = problemas.length ? '🚫 Completa los datos primero' : '🔎 Consultar en SURA';
}

async function consultarEpsSura() {
    const btn = esuEl('esuBtnConsultar');
    const parar = esuEsperar(btn, 'Consultando en SURA...');
    let d;
    try { d = await esuPedir('consultar', 'POST', 280); }
    catch (e) { d = { ok: false, error: 'Se perdió la conexión con el servidor.' }; }
    parar();
    btn.disabled = false; btn.textContent = '🔎 Consultar de nuevo';

    if (!d.ok) { alert(d.error || (d.problemas || []).join('\n') || 'No se pudo consultar.'); return; }
    if (d.resumen) esuPintarResumen(d.resumen);

    const portal = esuEl('esuPortal');
    portal.innerHTML = `<div>En SURA: <strong>${esuEsc(d.nombre || 'NO ENCONTRADO')}</strong></div>`;
    portal.style.display = 'block';

    const aviso = esuEl('esuAviso');
    if (!d.esReingreso) {
        aviso.innerHTML = '⚠️ SURA no trae el nombre de la persona: no es un reingreso sino un traslado, y eso no se hace por esta pantalla del portal.';
        aviso.style.display = 'block';
    } else {
        aviso.style.display = 'none';
    }

    const reg = esuEl('esuBtnRegistrar');
    reg.style.display = d.esReingreso ? 'block' : 'none';
    reg.disabled = false;
    reg.textContent = '🏥 Radicar reingreso';
}

async function explorarEpsSura() {
    const btn = esuEl('esuBtnExplorar');
    const parar = esuEsperar(btn, 'Abriendo la pantalla del portal...');
    let d;
    try { d = await esuPedir('explorar', 'POST', 280); }
    catch (e) { d = { ok: false, error: 'Se perdió la conexión con el servidor.' }; }
    parar();
    btn.disabled = false; btn.textContent = '🔧 Ver los campos del portal (no radica nada)';

    if (!d.ok) { alert(d.error || 'No se pudo abrir la pantalla.'); return; }

    const visibles = (d.campos || []).filter(c => c.id && c.visible);
    const portal = esuEl('esuPortal');
    portal.innerHTML = `<div><strong>${visibles.length}</strong> campos en ${esuEsc(d.url || '')}</div>` +
        `<pre style="max-height:220px;overflow:auto;font-size:.66rem;margin:.4rem 0 0;white-space:pre-wrap">${esuEsc(visibles.map(c => `${c.id} (${c.etiqueta}${c.tipo ? '/' + c.tipo : ''})`).join('\n'))}</pre>`;
    portal.style.display = 'block';
}

/** Habla con la extensión BryNex Portales, igual que los demás modales. */
function esuExt(accion, datos = {}, limiteSeg = 300) {
    return new Promise((resolve) => {
        if (!document.documentElement.dataset.brynexPortales) {
            resolve({ ok: false, sinExtension: true, error: 'La extensión BryNex Portales no está instalada en este navegador. Se descarga desde Afiliaciones → 🩺 Conciliar EPS → 🧩 Extensión.' });
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'sura', accion, datos }, window.location.origin);
    });
}

async function abrirPortalEpsSura() {
    const d = await esuExt('suraAbrir', {}, 60);
    if (!d.ok) { alert(d.error || 'No se pudo abrir el portal.'); return; }
    esuEl('esuPortal').innerHTML = 'Se abrió el portal de EPS SURA en otra pestaña. Inicia sesión ahí y vuelve a este modal.';
    esuEl('esuPortal').style.display = 'block';
}

async function radicarConExtensionEpsSura() {
    const r = esuPrep?.resumen || {};
    if (!confirm('¿Radicar el reingreso en EPS SURA desde la pestaña del portal?\n\nQueda aplicado y no se puede anular desde aquí.')) return;

    const btn = esuEl('esuBtnExtRadicar');
    const parar = esuEsperar(btn, 'Radicando con la extensión...');

    // La empresa de la pestaña debe ser la del contrato: el portal no avisa si
    // se radica en otra, y el error sería de los que cuesta deshacer.
    const estado = await esuExt('suraEstado', {}, 60);
    if (!estado.ok) { parar(); btn.disabled = false; btn.textContent = '🧩 Radicar con la extensión'; alert(estado.error || 'No hay pestaña de EPS SURA.'); return; }

    const nitContrato = String(r.nit || '').replace(/\D/g, '');
    if (estado.empresa && nitContrato && estado.empresa !== nitContrato) {
        parar(); btn.disabled = false; btn.textContent = '🧩 Radicar con la extensión';
        alert(`La pestaña está en la empresa ${estado.empresa} y el contrato es de la ${nitContrato}. Entra con la empresa correcta.`);
        return;
    }

    // El servicio arma los datos para el robot del servidor; la extensión los
    // pide en plano.
    const p = esuPrep?.datos || {};
    const d = await esuExt('suraRadicar', {
        tipo: p.persona?.tipo, documento: p.persona?.numero,
        ibc: p.ibc, fechaIngreso: p.fechaIngreso, asesor: p.asesor,
    }, 300);
    parar();
    btn.disabled = false; btn.textContent = '🧩 Radicar con la extensión';

    if (d.sinExtension) { alert(d.error); return; }

    // Lo que trajo se registra igual si falló: el motivo del portal vale.
    let g;
    try {
        g = await esuPedir('aplicar', 'POST', 120, {
            ok: !!d.ok, radicado: d.radicado || null, transaccion: d.transaccion || null,
            periodoPago: d.periodoPago || null, resultado: d.resultado || null,
            error: d.error || null, pdf: d.pdf || null,
        });
    } catch (e) { alert('Se radicó, pero no se pudo guardar en BryNex: ' + (d.radicado || 'sin número')); return; }

    if (g.radicado) pintarRadicadoEnLista(g.radicado, esuContratoId);

    if (!d.ok) { alert(d.error || 'El portal no aplicó la novedad.'); return; }

    esuEl('esuContenido').style.display = 'none';
    const caja = esuEl('esuResultado');
    caja.innerHTML = `✅ Reingreso aplicado en EPS SURA${d.radicado ? `: <strong>${esuEsc(d.radicado)}</strong>` : ''}.` +
        (g.comprobante ? '<br><span style="color:#475569">Comprobante del portal guardado con los soportes.</span>'
                       : '<br><span style="color:#92400e">El portal no entregó el comprobante.</span>') +
        '<br><span style="color:#475569">El radicado quedó en trámite; pasa a OK cuando la conciliación lo vea vigente.</span>';
    caja.style.display = 'block';
}

async function registrarEpsSura() {
    if (!confirm('¿Radicar el reingreso en el portal de EPS SURA con estos datos?\n\nQueda aplicado en el portal y no se puede anular desde aquí.')) return;

    const btn = esuEl('esuBtnRegistrar');
    const parar = esuEsperar(btn, 'Radicando en SURA...');
    let d;
    try { d = await esuPedir('registrar', 'POST', 280); }
    catch (e) {
        // No se reintenta solo: pudo quedar aplicado. Consultar lo detecta.
        d = { ok: false, error: 'Se perdió la conexión. Antes de reintentar usa «Consultar»: si quedó radicado, se verá.' };
    }
    parar();

    if (!d.ok) {
        btn.disabled = false; btn.textContent = '🏥 Reintentar';
        // El radicado quedó en error: la fila lo muestra sin recargar.
        if (d.radicado) pintarRadicadoEnLista(d.radicado, esuContratoId);
        alert(d.error || d.alerta || 'No se pudo radicar.');
        return;
    }

    esuEl('esuContenido').style.display = 'none';
    // La fila queda en trámite al momento, sin recargar la página.
    const pintado = pintarRadicadoEnLista(d.radicado, esuContratoId);
    esuRadicadoNuevo = pintado ? null : (d.radicado || null);

    const caja = esuEl('esuResultado');
    caja.innerHTML = '✅ Reingreso aplicado en el portal de EPS SURA.' +
        (d.alerta ? `<br><span style="color:#475569">${esuEsc(d.alerta)}</span>` : '') +
        '<br><span style="color:#475569">El radicado de EPS quedó en trámite; pasa a OK cuando la conciliación lo vea vigente.</span>';
    caja.style.display = 'block';
}
</script>
