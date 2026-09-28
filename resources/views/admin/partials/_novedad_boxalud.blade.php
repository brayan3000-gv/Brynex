{{--
    Modal de afiliación en el portal Boxalud (Emssanar), autocontenido.

    Lo abre `abrirNovedadBoxalud(contratoId, eps)` desde el radicado de EPS. La
    persona entra al portal con el usuario de la razón social (login con captcha)
    y la extensión BryNex Portales llena "Ingreso de afiliación" y adjunta los
    documentos. ACEPTAR y GUARDAR del portal los pulsa la persona; el modal lee el
    resultado y lo registra. Plan B: correo a la EPS (abrirCorreoEps).
--}}
<style>
.bxn-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.bxn-bg.open { display:flex }
.bxn-box { background:#fff;border-radius:14px;max-width:580px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.bxn-head { background:linear-gradient(135deg,#4d7c0f,#65a30d);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.bxn-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.bxn-x { background:none;border:none;color:#ecfccb;font-size:1.15rem;cursor:pointer;line-height:1 }
.bxn-body { padding:1.1rem }
.bxn-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.bxn-resumen span { color:#64748b }
.bxn-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.bxn-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.bxn-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.bxn-info { background:#f7fee7;border:1px solid #bef264;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#365314;line-height:1.55 }
.bxn-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.bxn-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#4d7c0f,#65a30d);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.bxn-btn.sec { background:#fff;color:#4d7c0f;border:1px solid #bef264 }
.bxn-btn.rojo { background:#fff;color:#b91c1c;border:1px solid #fecaca }
.bxn-btn:disabled { opacity:.5;cursor:not-allowed }
.bxn-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.4rem .55rem;font-size:.82rem;font-family:inherit }
.bxn-texto { font-size:.7rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:.45rem .55rem;max-height:120px;overflow-y:auto;margin:.4rem 0;line-height:1.45 }
</style>

<div class="bxn-bg" id="bxnModal">
  <div class="bxn-box">
    <div class="bxn-head">
      <h3 id="bxnTitulo">🏥 Afiliación en el portal</h3>
      <button class="bxn-x" onclick="cerrarNovedadBoxalud()">✕</button>
    </div>
    <div class="bxn-body">
      <div id="bxnCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>
      <div id="bxnContenido" style="display:none">
        <div class="bxn-resumen" id="bxnResumen"></div>
        <div class="bxn-prob" id="bxnProblemas" style="display:none"><strong>No se puede por el portal todavía:</strong><ul id="bxnProblemasLista"></ul></div>
        <div class="bxn-aviso" id="bxnAvisos" style="display:none"></div>

        <div class="bxn-info" id="bxnSesion"></div>
        <button class="bxn-btn sec" id="bxnBtnAbrir" style="display:none" onclick="abrirPortalBoxalud()">🌐 Abrir el portal para iniciar sesión</button>
        <button class="bxn-btn" id="bxnBtnLlenar" style="display:none" onclick="llenarBoxalud()">📝 Llenar la afiliación en el portal</button>
        <div id="bxnLleno" style="display:none"></div>

        <div id="bxnGuardado" style="display:none">
          <div class="bxn-info" id="bxnGuardadoInfo"></div>
          <div class="bxn-texto" id="bxnGuardadoTexto" style="display:none"></div>
          <label style="font-size:.74rem;font-weight:600;color:#475569">Número que dio el portal (si mostró uno)</label>
          <input id="bxnNumero" class="bxn-input" placeholder="Opcional">
          <button class="bxn-btn" id="bxnBtnGuardar" onclick="guardarBoxalud()">💾 Registrar en BryNex</button>
          <button class="bxn-btn rojo" onclick="rechazoBoxalud()">⛔ El portal no la registró</button>
        </div>

        <button class="bxn-btn sec" id="bxnBtnCorreo" onclick="correoDesdeBoxalud()">📧 Plan B: enviar por correo a la EPS</button>
      </div>
      <div id="bxnResultado" class="bxn-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let bxnContratoId = null, bxnEps = null, bxnPrep = {}, bxnReloj = null, bxnEnvio = null;
const BXN_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const bxnEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const bxnEl = id => document.getElementById(id);
const bxnFmt = iso => iso ? iso.split('-').reverse().join('/') : '—';

function cerrarNovedadBoxalud() { bxnEl('bxnModal').classList.remove('open'); clearInterval(bxnReloj); }

async function bxnPedir(ruta, metodo = 'GET', cuerpo = null) {
    const r = await fetch(`/admin/afiliaciones/${bxnContratoId}/boxalud/${bxnEps}/${ruta}`, {
        method: metodo,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': BXN_CSRF },
        body: cuerpo ? JSON.stringify(cuerpo) : null,
    });
    return await r.json();
}

function bxnExt(accion, datos = {}, limiteSeg = 60) {
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'boxalud', accion, datos }, window.location.origin);
    });
}

async function abrirNovedadBoxalud(contratoId, eps) {
    bxnContratoId = contratoId; bxnEps = eps; bxnEnvio = null; clearInterval(bxnReloj);
    ['bxnContenido', 'bxnResultado', 'bxnLleno', 'bxnGuardado', 'bxnBtnAbrir', 'bxnBtnLlenar', 'bxnAvisos'].forEach(id => bxnEl(id).style.display = 'none');
    bxnEl('bxnCargando').style.display = 'block';
    bxnEl('bxnModal').classList.add('open');

    try { bxnPrep = await bxnPedir('precheck'); } catch (e) { bxnPrep = { problemas: ['No se pudo revisar el contrato.'] }; }
    bxnEl('bxnCargando').style.display = 'none';
    bxnEl('bxnContenido').style.display = 'block';
    const r = bxnPrep.resumen || {};
    bxnEl('bxnTitulo').textContent = `🏥 Afiliación en el portal de ${r.portal || 'la EPS'}`;
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${bxnEsc(v)}</strong></div>` : '';
    bxnEl('bxnResumen').innerHTML =
        li('Trabajador', `${r.trabajador ?? ''} — ${r.documento ?? ''}`) +
        li('Empresa', r.razon_social ? `${r.razon_social} (NIT ${r.nit})` : null) +
        li('Ingreso', bxnFmt(r.fecha_ingreso)) +
        li('Salario', r.salario ? '$' + Number(r.salario).toLocaleString('es-CO') : null) +
        li('AFP / ARL', `${r.afp ?? '—'} · ${r.arl ?? '—'}`) +
        li('Dirección', r.direccion) + li('Celulares', r.celulares) +
        li('Usuario del portal', r.usuario_portal);

    const problemas = bxnPrep.problemas || [];
    bxnEl('bxnProblemas').style.display = problemas.length ? 'block' : 'none';
    bxnEl('bxnProblemasLista').innerHTML = problemas.map(p => `<li>${bxnEsc(p)}</li>`).join('');
    const avisos = bxnPrep.avisos || [];
    bxnEl('bxnAvisos').style.display = avisos.length ? 'block' : 'none';
    bxnEl('bxnAvisos').innerHTML = avisos.map(bxnEsc).join('<br>');
    if (problemas.length) { bxnEl('bxnSesion').style.display = 'none'; return; }

    bxnEl('bxnSesion').style.display = 'block';
    await revisarSesionBoxalud();
    bxnReloj = setInterval(() => { if (bxnEl('bxnLleno').style.display !== 'block') revisarSesionBoxalud(); }, 3000);
}

async function revisarSesionBoxalud() {
    const e = await bxnExt('boxEstado', { host: bxnPrep.portal.host }, 20);
    const caja = bxnEl('bxnSesion');
    bxnEl('bxnBtnAbrir').style.display = 'none';
    bxnEl('bxnBtnLlenar').style.display = 'none';
    if (e.sinExtension) { caja.innerHTML = '🧩 Falta la extensión <strong>BryNex Portales</strong>. Se descarga desde Afiliaciones → 🩺 Conciliar EPS → botón 🧩 Extensión; después recarga esta página.'; return; }
    if (!e.abierta || !e.sesion) {
        caja.innerHTML = `1️⃣ Abre el portal e inicia sesión con el usuario de <strong>${bxnEsc(bxnPrep.portal.empresa)}</strong>` +
            (bxnPrep.resumen?.usuario_portal ? ` (<strong>${bxnEsc(bxnPrep.resumen.usuario_portal)}</strong>)` : '') + '. El captcha lo resuelves tú.';
        bxnEl('bxnBtnAbrir').style.display = 'block';
        return;
    }
    const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    const misma = norm(e.empresa).slice(0, 12) && (norm(e.empresa).includes(norm(bxnPrep.portal.empresa).slice(0, 12)) || norm(bxnPrep.portal.empresa).includes(norm(e.empresa).slice(0, 12)));
    if (!misma) {
        caja.innerHTML = `⚠️ El portal está abierto con <strong>${bxnEsc(e.empresa)}</strong>, pero el contrato es de <strong>${bxnEsc(bxnPrep.portal.empresa)}</strong>. Cierra sesión allá y entra con el usuario correcto.`;
        bxnEl('bxnBtnAbrir').style.display = 'block';
        return;
    }
    caja.innerHTML = `✅ Portal abierto con <strong>${bxnEsc(e.empresa)}</strong>.`;
    bxnEl('bxnBtnLlenar').style.display = 'block';
}

async function abrirPortalBoxalud() {
    let cred = {};
    try { cred = await bxnPedir('credencial', 'POST'); } catch (e) {}
    await bxnExt('boxAbrir', { host: bxnPrep.portal.host, usuario: cred.usuario || '', contrasena: cred.contrasena || '' }, 40);
}

async function llenarBoxalud() {
    const btn = bxnEl('bxnBtnLlenar');
    const desde = Date.now();
    btn.disabled = true;
    const reloj = setInterval(() => btn.textContent = `⏳ Llenando en el portal (valida en ADRES)... ${Math.round((Date.now() - desde) / 1000)}s`, 1000);
    const r = await bxnExt('boxLlenar', bxnPrep.portal, 420);
    clearInterval(reloj); btn.disabled = false; btn.textContent = '📝 Llenar la afiliación en el portal';

    if (!r.ok) {
        alert((r.adicion ? '↪ ' : '') + (r.error || 'No se pudo llenar la afiliación en el portal.'));
        return;
    }
    btn.style.display = 'none';
    const caja = bxnEl('bxnLleno');
    caja.style.display = 'block';
    const adj = (r.adjuntos || []).map(a => `${a.ok ? '✅' : '⚠️'} ${bxnEsc(a.nombre)}`).join('<br>');
    caja.innerHTML = `<div class="bxn-info">📝 Afiliación llena en el portal con <strong>${bxnEsc(r.empresa)}</strong>:` +
        `<br>• Dirección: ${bxnEsc(r.direccion || '— (escríbela en el portal)')}` +
        `<br>• AFP: ${bxnEsc(r.afp || '—')} · ARL: ${bxnEsc(r.arl || '—')}` +
        (r.aportante ? `<br>• Aportante validado: ${bxnEsc(r.aportante.razon)}` : '') +
        (adj ? `<br>${adj}` : '') + `</div>` +
        ((r.avisos || []).length ? `<div class="bxn-aviso">${r.avisos.map(bxnEsc).join('<br>')}</div>` : '') +
        `<div class="bxn-aviso">👉 Ve al portal, revisa las dos pestañas, pulsa <strong>ACEPTAR</strong> y luego <strong>GUARDAR</strong>. No cierres este modal.</div>`;
    bxnEl('bxnSesion').innerHTML = '⏳ Esperando a que pulses GUARDAR en el portal...';

    clearInterval(bxnReloj);
    const inicio = Date.now();
    bxnReloj = setInterval(async () => {
        if (Date.now() - inicio > 30 * 60 * 1000) { clearInterval(bxnReloj); mostrarGuardadoBoxalud({}); return; }
        const res = await bxnExt('boxResultado', { host: bxnPrep.portal.host, documento: bxnPrep.portal.documento }, 20);
        if (res.ok && res.guardado) { clearInterval(bxnReloj); mostrarGuardadoBoxalud(res); }
    }, 4000);
}

function mostrarGuardadoBoxalud(res) {
    bxnEnvio = res;
    bxnEl('bxnGuardado').style.display = 'block';
    bxnEl('bxnNumero').value = res.numero || '';
    bxnEl('bxnGuardadoInfo').innerHTML = res.guardado
        ? '📨 El portal respondió después de GUARDAR. Revisa el mensaje y registra en BryNex.'
        : '⌛ Se dejó de esperar. Si ya guardaste en el portal, registra aquí lo que salió.';
    bxnEl('bxnGuardadoTexto').style.display = res.texto ? 'block' : 'none';
    bxnEl('bxnGuardadoTexto').textContent = (res.texto || '').slice(0, 1500);
    bxnEl('bxnSesion').innerHTML = res.guardado ? '✅ Guardado en el portal.' : '';
}

async function guardarBoxalud() {
    const btn = bxnEl('bxnBtnGuardar');
    btn.disabled = true; btn.textContent = '⏳ Registrando...';
    const r = await bxnPedir('aplicar', 'POST', { numero: bxnEl('bxnNumero').value.trim(), texto: bxnEnvio?.texto || '' });
    btn.disabled = false; btn.textContent = '💾 Registrar en BryNex';
    if (!r.ok) { alert(r.error || r.mensaje || 'No se pudo registrar.'); return; }
    bxnTerminar(`✅ ${bxnEsc(r.mensaje)}`);
}

async function rechazoBoxalud() {
    const motivo = prompt('¿Qué dijo el portal? (queda en el radicado como error)', bxnEnvio?.texto || '');
    if (motivo === null) return;
    const r = await bxnPedir('aplicar', 'POST', { error: motivo || 'El portal no registró la afiliación.', texto: bxnEnvio?.texto || '' });
    bxnTerminar(`⛔ ${bxnEsc(r.mensaje || r.error || '')}`);
}

function correoDesdeBoxalud() {
    cerrarNovedadBoxalud();
    if (typeof abrirCorreoEps === 'function') abrirCorreoEps(bxnContratoId, bxnEps);
}

function bxnTerminar(html) {
    clearInterval(bxnReloj);
    bxnEl('bxnContenido').style.display = 'none';
    bxnEl('bxnResultado').style.display = 'block';
    bxnEl('bxnResultado').innerHTML = html;
    if (typeof mostrarToast === 'function') mostrarToast('Radicado de EPS actualizado. Recarga para verlo.', 'success');
}
</script>
