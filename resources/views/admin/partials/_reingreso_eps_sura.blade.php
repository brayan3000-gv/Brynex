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

        <button class="esu-btn" id="esuBtnRadicar" onclick="realizarReingresoEpsSura()">🏥 Realizar reingreso</button>
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

    const btn = esuEl('esuBtnRadicar');
    btn.disabled = problemas.length > 0;
    btn.textContent = problemas.length ? '🚫 Completa los datos primero' : '🏥 Realizar reingreso';
}

/** Habla con la extensión BryNex Portales, igual que los demás modales. */
function esuExt(accion, datos = {}, limiteSeg = 300) {
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'sura', accion, datos }, window.location.origin);
    });
}

/**
 * Hace el reingreso de principio a fin.
 *
 * Con la extensión, porque es la única que trae el comprobante del portal —el
 * que muestra solo esta empresa—; si no está instalada, lo radica el servidor,
 * que hace el trámite igual pero sin ese documento.
 */
async function realizarReingresoEpsSura() {
    const btn = esuEl('esuBtnRadicar');
    const r = esuPrep?.resumen || {};
    const hayExtension = !!document.documentElement.dataset.brynexPortales;

    if (!confirm(`¿Realizar el reingreso de ${r.trabajador || 'este trabajador'} en EPS SURA?\n\n` +
        (hayExtension ? '' : 'La extensión no está instalada: lo hará el servidor y no se podrá guardar el comprobante.\n\n') +
        'Queda aplicado en el portal y no se puede anular desde aquí.')) return;

    const parar = esuEsperar(btn, 'Haciendo el reingreso...');
    const soltar = (texto = '🏥 Realizar reingreso') => { parar(); btn.disabled = false; btn.textContent = texto; };

    if (!hayExtension) { await reingresoPorServidor(soltar); return; }

    // La pestaña del portal: si no está abierta, se abre y se espera a la persona.
    const estado = await esuExt('suraEstado', {}, 60);
    if (!estado.ok) {
        await esuExt('suraAbrir', {}, 60);
        soltar('🏥 Reintentar cuando entres');
        esuEl('esuPortal').innerHTML = 'Se abrió el portal de EPS SURA en otra pestaña: <strong>inicia sesión ahí</strong> con la empresa y vuelve a pulsar el botón.';
        esuEl('esuPortal').style.display = 'block';
        return;
    }

    // La empresa de la pestaña tiene que ser la del contrato: el portal no avisa
    // si se radica en otra, y ese error cuesta deshacerlo.
    const nitContrato = String(r.nit || '').replace(/\D/g, '');
    if (estado.empresa && nitContrato && estado.empresa !== nitContrato) {
        soltar();
        alert(`La pestaña del portal está en la empresa ${estado.empresa} y el contrato es de la ${nitContrato}. Entra con la empresa correcta y vuelve a intentar.`);
        return;
    }

    const p = esuPrep?.datos || {};
    const d = await esuExt('suraRadicar', {
        tipo: p.persona?.tipo, documento: p.persona?.numero,
        ibc: p.ibc, fechaIngreso: p.fechaIngreso, asesor: p.asesor,
    }, 300);
    soltar();

    // Lo que trajo se registra aunque el portal haya rechazado: el motivo vale.
    let g = {};
    try {
        g = await esuPedir('aplicar', 'POST', 120, {
            ok: !!d.ok, radicado: d.radicado || null, transaccion: d.transaccion || null,
            periodoPago: d.periodoPago || null, resultado: d.resultado || null,
            error: d.error || null, pdf: d.pdf || null,
        });
    } catch (e) {
        alert('Se hizo el trámite, pero no se pudo guardar en BryNex: ' + (d.radicado || 'sin número') + '. Anótalo.');
        return;
    }

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

/** Camino de respaldo: lo hace el servidor, sin comprobante. */
async function reingresoPorServidor(soltar) {
    let d;
    try { d = await esuPedir('registrar', 'POST', 420); }
    catch (e) { d = { ok: false, error: 'Se perdió la conexión. Antes de reintentar revisa en el portal: pudo quedar radicado.' }; }
    soltar('🏥 Reintentar');

    if (d.radicado) pintarRadicadoEnLista(d.radicado, esuContratoId);
    if (!d.ok) { alert(d.error || 'No se pudo radicar.'); return; }

    esuEl('esuContenido').style.display = 'none';
    const caja = esuEl('esuResultado');
    caja.innerHTML = '✅ Reingreso aplicado en EPS SURA.' +
        '<br><span style="color:#475569">El radicado quedó en trámite; pasa a OK cuando la conciliación lo vea vigente.</span>';
    caja.style.display = 'block';
}

</script>
