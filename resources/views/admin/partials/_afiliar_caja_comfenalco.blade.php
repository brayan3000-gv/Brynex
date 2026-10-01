{{--
    Modal de afiliación a la caja Comfenalco Valle, autocontenido.

    Lo abre `abrirCajaComfenalco(contratoId)` desde el radicado de caja. La persona
    entra al portal con el usuario de la empresa (captcha) y la extensión BryNex
    Portales llena cada paso del asistente con los datos de BryNex; ella pulsa
    Continuar, acepta los términos y pulsa Finalizar Afiliación. El modal lee el
    número de formulario y lo registra en el radicado.
--}}
<style>
.ccf-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.ccf-bg.open { display:flex }
.ccf-box { background:#fff;border-radius:14px;max-width:580px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.ccf-head { background:linear-gradient(135deg,#047857,#059669);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.ccf-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.ccf-x { background:none;border:none;color:#d1fae5;font-size:1.15rem;cursor:pointer;line-height:1 }
.ccf-body { padding:1.1rem }
.ccf-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.ccf-resumen span { color:#64748b }
.ccf-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.ccf-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.ccf-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.ccf-info { background:#ecfdf5;border:1px solid #6ee7b7;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#065f46;line-height:1.55 }
.ccf-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.ccf-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#047857,#059669);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.ccf-btn.sec { background:#fff;color:#047857;border:1px solid #6ee7b7 }
.ccf-btn.rojo { background:#fff;color:#b91c1c;border:1px solid #fecaca }
.ccf-btn:disabled { opacity:.5;cursor:not-allowed }
.ccf-campo { display:block;font-size:.72rem;color:#475569;font-weight:600;margin:.45rem 0 .15rem }
.ccf-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.35rem .5rem;font-size:.8rem;font-family:inherit }
.ccf-fila { display:grid;grid-template-columns:1fr 1fr;gap:.5rem }
.ccf-texto { font-size:.7rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:.45rem .55rem;max-height:130px;overflow-y:auto;margin:.4rem 0;line-height:1.45 }
</style>

<div class="ccf-bg" id="ccfModal">
  <div class="ccf-box">
    <div class="ccf-head">
      <h3>🏢 Afiliación a la caja Comfenalco Valle</h3>
      <button class="ccf-x" onclick="cerrarCajaComfenalco()">✕</button>
    </div>
    <div class="ccf-body">
      <div id="ccfCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>
      <div id="ccfContenido" style="display:none">
        <div class="ccf-resumen" id="ccfResumen"></div>
        <div class="ccf-prob" id="ccfProblemas" style="display:none"><strong>No se puede afiliar todavía:</strong><ul id="ccfProblemasLista"></ul></div>
        <div class="ccf-aviso" id="ccfAvisos" style="display:none"></div>

        <div id="ccfDatos">
          <div class="ccf-fila">
            <div><label class="ccf-campo" for="ccfEstadoCivil">Estado civil</label><select id="ccfEstadoCivil" class="ccf-input"></select></div>
            <div><label class="ccf-campo" for="ccfContrato">Tipo de contrato</label><select id="ccfContrato" class="ccf-input"></select></div>
          </div>
          <div class="ccf-fila">
            <div><label class="ccf-campo" for="ccfFormaPago">Pago del subsidio</label><select id="ccfFormaPago" class="ccf-input"></select></div>
            <div><label class="ccf-campo" for="ccfCargo">Cargo (se busca en la lista del portal)</label><input id="ccfCargo" class="ccf-input"></div>
          </div>
          <div id="ccfAvisoCony" class="ccf-aviso" style="display:none">Con casado o unión libre el portal exige los datos del cónyuge: los escribes tú en ese paso, o afilias como está y lo agregas después con una adición.</div>
        </div>

        <div class="ccf-info" id="ccfSesion"></div>
        <button class="ccf-btn sec" id="ccfBtnAbrir" style="display:none" onclick="abrirPortalCaja()">🌐 Abrir el portal para iniciar sesión</button>
        <button class="ccf-btn" id="ccfBtnIniciar" style="display:none" onclick="iniciarCajaComfenalco()">🔎 Buscar al trabajador y empezar</button>
        <div id="ccfPasos" style="display:none"></div>

        <div id="ccfFirmaBox" class="ccf-info" style="display:none">
          <strong>✍️ Declaración juramentada</strong>
          <div id="ccfFirmaTexto" style="margin:.3rem 0"></div>
          <div style="font-size:.68rem;color:#64748b;margin-bottom:.3rem">Firma el trabajador (declarante) y, si hay padres en la sección 3, también el padre y la madre. Cada firma lleva su número de documento. La firma del cónyuge cuidador(a) solo aplica si esa sección trae filas.</div>
          <div id="ccfFirmasLista"></div>
          <button class="ccf-btn sec" id="ccfFirmaPrevia" onclick="ccfVistaPrevia()">👁️ Ver cómo queda la declaración (vista previa)</button>
          <iframe id="ccfPreviaVisor" style="display:none;width:100%;height:480px;border:1px solid #cbd5e1;border-radius:8px;margin-top:.4rem;background:#fff"></iframe>
          <button class="ccf-btn" id="ccfFirmaBtn" onclick="ccfFirmarYAdjuntar()">✍️ Firmar y adjuntar la declaración</button>
        </div>
        <div id="ccfFinalizarBox" style="display:none">
          <button class="ccf-btn" id="ccfFinalizarBtn" onclick="ccfFinalizar()" style="background:linear-gradient(135deg,#1d4ed8,#2563eb)">🚀 Finalizar y radicar la afiliación</button>
          <div id="ccfFinalizarLog" class="ccf-texto" style="display:none;max-height:none"></div>
        </div>

        <div id="ccfRadicado" style="display:none">
          <div class="ccf-info" id="ccfRadicadoInfo"></div>
          <div class="ccf-texto" id="ccfRadicadoTexto" style="display:none"></div>
          <label class="ccf-campo" for="ccfNumero">Número de formulario que dio el portal</label>
          <input id="ccfNumero" class="ccf-input" placeholder="Ej: 1089000002377457">
          <button class="ccf-btn" id="ccfBtnGuardar" onclick="guardarCajaComfenalco()">💾 Registrar en BryNex</button>
          <button class="ccf-btn rojo" onclick="rechazoCajaComfenalco()">⛔ El portal no la radicó</button>
        </div>
      </div>
      <div id="ccfResultado" class="ccf-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let ccfContratoId = null, ccfPrep = {}, ccfReloj = null, ccfFinal = null, ccfDocsGuardados = false, ccfDeclPdf = null, ccfFirmantes = [];
// ── Bitácora en vivo del robot: lo que va haciendo, con contador de tiempo desde que se pulsa «Buscar» ──
let ccfT0 = 0, ccfNotas = [], ccfRelojBit = null, ccfVistos = new Set(), ccfUltimoPaso = null;
const ccfMMSS = s => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
function ccfPintarBitacora() {
    const c = ccfEl('ccfPasoActual'); if (!c) return;
    const s = Math.floor((Date.now() - ccfT0) / 1000);
    const abajo = c.scrollTop + c.clientHeight >= c.scrollHeight - 8;
    c.innerHTML = `<strong>⏱ ${ccfMMSS(s)} — robot de la caja</strong><br>` +
        ccfNotas.map(n => `<span style="color:#64748b">[${ccfMMSS(n.s)}]</span> ${n.m}`).join('<br>');
    if (abajo) c.scrollTop = c.scrollHeight;
}
function ccfNota(m) { ccfNotas.push({ s: Math.floor((Date.now() - ccfT0) / 1000), m }); ccfPintarBitacora(); }
function ccfIniciarBitacora() { ccfT0 = Date.now(); ccfNotas = []; ccfVistos = new Set(); ccfUltimoPaso = null; clearInterval(ccfRelojBit); ccfRelojBit = setInterval(ccfPintarBitacora, 1000); }
function ccfDetenerBitacora() { clearInterval(ccfRelojBit); ccfPintarBitacora(); }

const CCF_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const ccfEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ccfEl = id => document.getElementById(id);
const ccfFmt = iso => iso ? iso.split('-').reverse().join('/') : '—';

function cerrarCajaComfenalco() { ccfEl('ccfModal').classList.remove('open'); clearInterval(ccfReloj); }

async function ccfPedir(ruta, metodo = 'GET', cuerpo = null) {
    const r = await fetch(`/admin/afiliaciones/${ccfContratoId}/caja-comfenalco/${ruta}`, {
        method: metodo,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CCF_CSRF },
        body: cuerpo ? JSON.stringify(cuerpo) : null,
    });
    return await r.json();
}

function ccfExt(accion, datos = {}, limiteSeg = 90) {
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'ccfcv', accion, datos }, window.location.origin);
    });
}

function ccfOpciones(sel, lista, porDefecto) {
    sel.innerHTML = Object.entries(lista || {}).map(([v, t]) => `<option value="${v}"${String(v) === String(porDefecto) ? ' selected' : ''}>${ccfEsc(t)}</option>`).join('');
}

async function abrirCajaComfenalco(contratoId) {
    ccfContratoId = contratoId; ccfAutoIntentado = false; ccfAbriendo = false; ccfCambioIntentado = false; ccfFinal = null; ccfDocsGuardados = false; ccfDeclPdf = null; clearInterval(ccfRelojBit); ccfEl('ccfFirmaBox').style.display = 'none'; ccfEl('ccfFinalizarBox').style.display = 'none'; clearInterval(ccfReloj);
    ['ccfContenido', 'ccfResultado', 'ccfPasos', 'ccfRadicado', 'ccfBtnAbrir', 'ccfBtnIniciar', 'ccfAvisos'].forEach(id => ccfEl(id).style.display = 'none');
    ccfEl('ccfCargando').style.display = 'block';
    ccfEl('ccfModal').classList.add('open');

    try { ccfPrep = await ccfPedir('precheck'); } catch (e) { ccfPrep = { problemas: ['No se pudo revisar el contrato.'] }; }
    ccfEl('ccfCargando').style.display = 'none';
    ccfEl('ccfContenido').style.display = 'block';

    const r = ccfPrep.resumen || {};
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${ccfEsc(v)}</strong></div>` : '';
    ccfEl('ccfResumen').innerHTML =
        li('Trabajador', `${r.trabajador ?? ''} — ${r.documento ?? ''}`) +
        li('Empresa', r.razon_social ? `${r.razon_social} (NIT ${r.nit})` : null) +
        li('Caja', r.caja) + li('Ingreso', ccfFmt(r.fecha_ingreso)) +
        li('Salario', r.salario ? '$' + Number(r.salario).toLocaleString('es-CO') : null) +
        li('Residencia', `${r.residencia ?? ''} · ${r.barrio ?? ''}`) + li('Dirección', r.direccion) +
        li('Celular', r.celular) + li('Beneficiarios en BryNex', r.beneficiarios || null) +
        li('Usuario del portal', r.usuario_portal) +
        li('Radicado de caja', r.estado_radicado ? `${r.estado_radicado}${r.numero_radicado ? ' · ' + r.numero_radicado : ''}` : null);

    const problemas = ccfPrep.problemas || [];
    ccfEl('ccfProblemas').style.display = problemas.length ? 'block' : 'none';
    ccfEl('ccfProblemasLista').innerHTML = problemas.map(p => `<li>${ccfEsc(p)}</li>`).join('');
    const avisos = ccfPrep.avisos || [];
    ccfEl('ccfAvisos').style.display = avisos.length ? 'block' : 'none';
    ccfEl('ccfAvisos').innerHTML = avisos.map(ccfEsc).join('<br>');

    // Con compañero(a) o cónyuge en BryNex se propone Unión libre; si no, Soltero.
    const conPareja = (ccfPrep.portal?.listaBeneficiarios || []).some(b => /compa[ñn]er|espos|c[oó]nyug/i.test(b.parentesco || ''));
    ccfOpciones(ccfEl('ccfEstadoCivil'), ccfPrep.listas?.estados_civil, conPareja ? 4 : 1);
    ccfOpciones(ccfEl('ccfContrato'), ccfPrep.listas?.contratos, 1);
    ccfOpciones(ccfEl('ccfFormaPago'), ccfPrep.listas?.formas_pago, 13);
    ccfEl('ccfCargo').value = ccfPrep.portal?.cargoTexto || 'APOYO ADMINISTRATIVO';
    ccfEl('ccfEstadoCivil').onchange = () => { ccfEl('ccfAvisoCony').style.display = ['2', '4'].includes(ccfEl('ccfEstadoCivil').value) ? 'block' : 'none'; };

    if (problemas.length) { ccfEl('ccfDatos').style.display = 'none'; ccfEl('ccfSesion').style.display = 'none'; return; }
    ccfEl('ccfSesion').style.display = 'block';
    await revisarSesionCaja();
    ccfReloj = setInterval(() => { if (ccfEl('ccfPasos').style.display !== 'block') revisarSesionCaja(); }, 3000);
}

async function revisarSesionCaja() {
    const e = await ccfExt('ccfEstado', {}, 20);
    const caja = ccfEl('ccfSesion');
    ccfEl('ccfBtnAbrir').style.display = 'none';
    ccfEl('ccfBtnIniciar').style.display = 'none';
    if (e.sinExtension) { caja.innerHTML = '🧩 Falta la extensión <strong>BryNex Portales</strong>. Se descarga desde Afiliaciones → 🩺 Conciliar EPS → botón 🧩 Extensión; después recarga esta página.'; return; }
    if (ccfAbriendo) return;
    if (ccfOtraEmpresa(e)) {
        caja.innerHTML = `⚠️ El portal está abierto con <strong>${ccfEsc(e.empresa || 'otra empresa')}</strong> (NIT ${ccfEsc(e.nit)}) y este contrato es de <strong>${ccfEsc(ccfPrep.portal.empresa)}</strong>. Al pulsar «Buscar al trabajador y empezar» se cierra esa sesión y se entra con la clave de ${ccfEsc(ccfPrep.portal.empresa)}.`;
        ccfEl('ccfBtnIniciar').style.display = 'block';
        return;
    }
    if ((!e.abierta || !e.sesion) && await ccfAbrirSolo()) return;
    if (ccfAbriendo) return;
    if (!e.abierta || !e.sesion) {
        if (ccfAutoIntentado) return;                              // ya se intentó solo: queda el mensaje y el botón
        caja.innerHTML = `1️⃣ Abre la <strong>Sucursal Virtual Afiliación</strong> e inicia sesión con el usuario de <strong>${ccfEsc(ccfPrep.portal.empresa)}</strong>` +
            (ccfPrep.resumen?.usuario_portal ? ` (<strong>${ccfEsc(ccfPrep.resumen.usuario_portal)}</strong>)` : '') + '. BryNex deja escrito el usuario; solo confirma e ingresa.';
        ccfEl('ccfBtnAbrir').style.display = 'block';
        return;
    }
    caja.innerHTML = `✅ Portal abierto${e.empresa ? ' con <strong>' + ccfEsc(e.empresa) + '</strong>' : ''}.`;
    ccfEl('ccfBtnIniciar').style.display = 'block';
}

async function abrirPortalCaja() {
    let cred = {};
    try { cred = await ccfPedir('credencial', 'POST'); } catch (e) {}
    return await ccfExt('ccfAbrir', { usuario: cred.usuario || '', contrasena: cred.contrasena || '' }, 150);
}

// Si el portal no está abierto (o no tiene sesión), se abre solo y entra con la clave que la empresa
// tiene en el módulo de claves. Se intenta UNA vez por apertura del modal: repetir logins con una
// clave mala puede bloquear el usuario del portal.
let ccfAutoIntentado = false, ccfAbriendo = false;
// El portal puede estar abierto con OTRA empresa (p. ej. YEVI EXPRESS cuando el contrato es de GAVI).
// Se compara el NIT de la sesión con el del contrato; al pulsar «Buscar al trabajador» se cierra esa
// sesión y se entra con la clave de la empresa del contrato (una vez por apertura del modal).
const ccfSoloDigitos = v => String(v || '').replace(/\D/g, '');
function ccfOtraEmpresa(e) {
    const esperado = ccfSoloDigitos(ccfPrep.portal?.nit);
    return !!(e && e.sesion && e.nit && esperado && ccfSoloDigitos(e.nit) !== esperado);
}
let ccfCambioIntentado = false;
async function ccfCambiarEmpresa(e) {
    if (ccfCambioIntentado || ccfAbriendo) return false;
    if (!ccfPrep.resumen?.usuario_portal) return false;           // sin la clave de esta empresa no se puede entrar con ella
    ccfCambioIntentado = true; ccfAbriendo = true;
    const caja = ccfEl('ccfSesion'), t0 = Date.now();
    const pintar = () => { caja.innerHTML = `🔄 El portal está con <strong>${ccfEsc(e.empresa || 'otra empresa')}</strong> (NIT ${ccfEsc(e.nit)}) y este contrato es de <strong>${ccfEsc(ccfPrep.portal.empresa)}</strong> (NIT ${ccfEsc(ccfPrep.portal.nit)}). Cerrando esa sesión y entrando con la clave correcta… <strong>⏱ ${Math.floor((Date.now() - t0) / 1000)} s</strong>`; };
    pintar(); const reloj = setInterval(pintar, 1000);
    const c = await ccfExt('ccfCerrarSesion', {}, 60);
    clearInterval(reloj); ccfAbriendo = false;
    if (!c.ok) { caja.innerHTML = `⚠️ No se pudo cerrar la sesión de ${ccfEsc(e.empresa || 'la otra empresa')}: ${ccfEsc(c.error || 'sin respuesta')}. Ciérrala a mano en el portal.`; return false; }
    ccfAutoIntentado = false;                                     // ahora sí: abrir con la clave de esta empresa
    const ok = await ccfAbrirSolo();
    if (ok) {
        const n = await ccfExt('ccfEstado', {}, 20);
        if (ccfOtraEmpresa(n)) { caja.innerHTML = `⚠️ Se entró, pero el portal sigue con <strong>${ccfEsc(n.empresa)}</strong> (NIT ${ccfEsc(n.nit)}). Revisa la sesión.`; ccfEl('ccfBtnIniciar').style.display = 'none'; return false; }
    }
    return ok;
}

async function ccfAbrirSolo() {
    if (ccfAutoIntentado || ccfAbriendo) return false;
    if (!ccfPrep.resumen?.usuario_portal) return false;           // sin clave guardada: lo hace la persona
    ccfAutoIntentado = true; ccfAbriendo = true;
    const caja = ccfEl('ccfSesion'), t0 = Date.now();
    const pintar = () => { caja.innerHTML = `🔐 Abriendo el portal e iniciando sesión con la clave de <strong>${ccfEsc(ccfPrep.portal.empresa)}</strong>… <strong>⏱ ${Math.floor((Date.now() - t0) / 1000)} s</strong>`; };
    pintar(); const reloj = setInterval(pintar, 1000);
    ccfEl('ccfBtnAbrir').style.display = 'none';
    const r = await abrirPortalCaja();
    clearInterval(reloj); ccfAbriendo = false;
    if (r.ok && r.sesion) { caja.innerHTML = `✅ Portal abierto e iniciado solo${r.empresa ? ' con <strong>' + ccfEsc(r.empresa) + '</strong>' : ''} (${Math.floor((Date.now() - t0) / 1000)} s).`; ccfEl('ccfBtnIniciar').style.display = 'block'; return true; }
    caja.innerHTML = `⚠️ No se pudo entrar solo al portal: ${ccfEsc(r.error || 'sin respuesta')}. Inicia sesión a mano con el botón.`;
    ccfEl('ccfBtnAbrir').style.display = 'block';
    return false;
}

function ccfDatosPortal() {
    return Object.assign({}, ccfPrep.portal, {
        estadoCivil: ccfEl('ccfEstadoCivil').value,
        tipoContrato: ccfEl('ccfContrato').value,
        formaPago: ccfEl('ccfFormaPago').value,
        cargoTexto: ccfEl('ccfCargo').value.trim(),
        beneficiarios: ccfPrep.resumen?.beneficiarios || 0,
    });
}

async function iniciarCajaComfenalco() {
    const btn = ccfEl('ccfBtnIniciar');
    btn.disabled = true; btn.textContent = '⏳ Buscando al trabajador en el portal...';
    // Sin sesión en el portal se abre solo antes de buscar.
    const est0 = await ccfExt('ccfEstado', {}, 20);
    if (ccfOtraEmpresa(est0)) {
        btn.textContent = '🔄 Cambiando de empresa en el portal…';
        if (!(await ccfCambiarEmpresa(est0))) { btn.disabled = false; btn.textContent = '🔎 Buscar al trabajador y empezar'; return; }
        btn.textContent = '⏳ Buscando al trabajador en el portal...';
    } else if (!est0.sesion && !est0.sinExtension) {
        btn.textContent = '🔐 Abriendo el portal…';
        if (!(await ccfAbrirSolo())) { btn.disabled = false; btn.textContent = '🔎 Buscar al trabajador y empezar'; if (!ccfAutoIntentado) alert('El portal no tiene sesión y la empresa no tiene clave guardada: inicia sesión a mano.'); return; }
        btn.textContent = '⏳ Buscando al trabajador en el portal...';
    }
    // La bitácora arranca al pulsar, antes de que exista el panel de pasos.
    const caja0 = ccfEl('ccfPasos'); caja0.style.display = 'block';
    caja0.innerHTML = '<div id="ccfPasoActual" class="ccf-texto" style="max-height:240px"></div>';
    ccfIniciarBitacora();
    const d0 = ccfDatosPortal();
    ccfNota(`🔎 Buscando a ${ccfEsc(ccfPrep.resumen?.trabajador || '')} (${ccfEsc(ccfPrep.resumen?.documento || '')}) en el portal de Comfenalco…`);
    const r = await ccfExt('ccfConsultar', d0, 90);
    btn.disabled = false; btn.textContent = '🔎 Buscar al trabajador y empezar';
    if (!r.ok) { ccfNota('❗ ' + ccfEsc(r.error || 'No se pudo consultar al trabajador.')); ccfDetenerBitacora(); alert(r.error || 'No se pudo consultar al trabajador.'); return; }

    const nueva = (r.opciones || []).find(o => /Nueva/i.test(o.texto));
    const caja = ccfEl('ccfPasos');
    btn.style.display = 'none';
    ccfNota(`✅ El portal encontró a ${ccfEsc(r.nombre || '')}. Opciones: ${(r.opciones || []).map(o => ccfEsc(o.texto.split(' Realiza')[0])).join(' · ') || '—'}`);
    if (r.familia) ccfNota(`👪 Grupo familiar que ya tiene en la caja: ${ccfEsc(r.familia)}`);
    caja.insertAdjacentHTML('afterbegin', `<div class="ccf-aviso">👉 En el portal pulsa <strong>${nueva ? 'Nueva' : 'la opción que corresponda'}</strong> y ve avanzando con <strong>Continuar</strong>. ` +
        'BryNex va llenando cada paso; revisa siempre antes de continuar. Al final pulsas <strong>Finalizar y radicar</strong>.</div>');
    ccfNota('⏳ Esperando que abras el formulario en el portal (botón «Nueva»)…');

    clearInterval(ccfReloj);
    const desde = Date.now();
    ccfReloj = setInterval(async () => {
        if (Date.now() - desde > 45 * 60 * 1000) { clearInterval(ccfReloj); return; }
        const fin = await ccfExt('ccfResultado', {}, 20);
        if (fin.ok && fin.radicado) { clearInterval(ccfReloj); mostrarRadicadoCaja(fin); return; }
        const p = await ccfExt('ccfPaso', ccfDatosPortal(), 30);
        if (!p.ok) return;
        if (p.paso && p.paso !== ccfUltimoPaso) { ccfUltimoPaso = p.paso; ccfNota(`➡️ <strong>Paso: ${ccfEsc(p.paso)}</strong>`); }
        const nuevos = (icono, lista) => (lista || []).forEach(t => {
            const k = icono + '|' + (p.paso || '') + '|' + t;
            if (!ccfVistos.has(k)) { ccfVistos.add(k); ccfNota(`${icono} ${ccfEsc(t)}`); }
        });
        nuevos('✅', p.hecho); nuevos('⚠️', p.falta); nuevos('❗', p.errores);
        if (/Anexos/i.test(p.paso || '') && p.anexosListos && !ccfDocsGuardados) { ccfDocsGuardados = true; ccfGuardarDocumentos().then(ccfPrepararDeclaracion); }
    }, 4000);
}

// En el paso Anexos la caja ya tiene guardados algunos documentos (cédula, registros
// civiles, ADRES…): se bajan y se guardan en BryNex una sola vez por afiliación.
async function ccfGuardarDocumentos() {
    const aviso = t => ccfNota(t);
    aviso('📎 Bajando los documentos que la caja ya tiene…');
    const r = await ccfExt('ccfDocumentos', {}, 180);
    const docs = (r.docs || []).filter(d => d.base64);
    if (!r.ok || !docs.length) { aviso('📎 ' + ccfEsc(r.error || 'La caja no tiene documentos guardados para esta persona.')); return; }
    const g = await ccfPedir('documentos', 'POST', { docs });
    if (!g.ok) { aviso('❗ No se pudieron guardar los documentos: ' + ccfEsc(g.message || g.error || '')); return; }
    aviso(`✅ Documentos en BryNex: ${g.guardados} nuevos, ${g.repetidos} ya estaban.` + (g.rechazados ? ` ${g.rechazados} rechazados.` : ''));
}

// ── Declaración juramentada: formato oficial del portal + firma en pantalla ──────────
let ccfDeclEnCurso = false;
async function ccfPrepararDeclaracion() {
    if (ccfDeclEnCurso || ccfDeclPdf) return;
    ccfDeclEnCurso = true;
    const aviso = t => ccfNota(t);
    aviso('📄 Pidiendo al portal la declaración juramentada…');
    const r = await ccfExt('ccfDeclaracion', {}, 90);
    ccfDeclEnCurso = false;
    if (r.ok && r.omitida) {
        aviso('✅ Esta afiliación no lleva declaración juramentada (sin beneficiarios ni pareja): no hace falta firma.');
        ccfDeclPdf = 'omitida';
        ccfEl('ccfFinalizarBox').style.display = 'block'; ccfEl('ccfFinalizarBtn').style.display = 'block';
        return;
    }
    if (!r.ok || !r.base64) { aviso('❗ ' + ccfEsc(r.error || 'El portal no entregó la declaración.')); return; }
    ccfDeclPdf = r.base64;
    const personas = r.personas || {};
    const docsP = Object.values(personas).map(p => p.doc);
    const f = await ccfPedir('firma' + (docsP.length ? '?' + docsP.map(d => 'docs[]=' + encodeURIComponent(d)).join('&') : ''));
    const rs = ccfPrep.resumen || {};
    ccfFirmantes = [{ rol: 'declarante', etiqueta: `${rs.trabajador || 'Trabajador'} — trabajador (declarante)`, doc: rs.documento || '', guardada: !!f.tiene, nueva: !f.tiene, trazo: false }];
    for (const rol of ['padre', 'madre']) {
        const p = personas[rol];
        if (p) ccfFirmantes.push({ rol, etiqueta: `${p.nombre} — ${rol}`, doc: `${p.tipo} ${p.doc}`, guardada: !!f.beneficiarios?.[p.doc], nueva: !f.beneficiarios?.[p.doc], trazo: false, persona: p });
    }
    aviso(`📄 Declaración juramentada lista: firman ${ccfFirmantes.length} persona(s): ${ccfFirmantes.map(x => x.rol).join(', ')}.`);
    ccfEl('ccfFirmaBox').style.display = 'block';
    ccfMostrarFirmas();
}

// Un recuadro de firma por cada persona que firma la declaración (trabajador, y padre/madre si están).
function ccfMostrarFirmas() {
    // Tras firmar a un trabajador el modal queda con estos tres ocultos; al armar otra declaración se vuelven a mostrar.
    ['ccfFirmasLista', 'ccfFirmaPrevia', 'ccfFirmaBtn'].forEach(id => ccfEl(id).style.display = '');
    ccfEl('ccfFirmaBtn').disabled = false; ccfEl('ccfFirmaBtn').textContent = '✍️ Firmar y adjuntar la declaración';
    ccfEl('ccfFirmaTexto').innerHTML = `La declaración ya está lista con los datos del portal. <strong>Firman ${ccfFirmantes.length}:</strong> cada firma queda guardada para las próximas afiliaciones y lleva su número de documento. <strong>Al firmar se radica la afiliación en Comfenalco.</strong>`;
    ccfEl('ccfFirmasLista').innerHTML = ccfFirmantes.map(f => {
        const pad = f.nueva || !f.guardada;
        return `<div style="border:1px solid #a7f3d0;border-radius:8px;padding:.45rem .55rem;margin:.4rem 0;background:#fff">
            <strong>✍️ ${ccfEsc(f.etiqueta)}</strong> <span style="color:#64748b">· ${ccfEsc(f.doc)}</span>
            ${pad
                ? `<canvas id="ccfL_${f.rol}" width="520" height="130" style="display:block;width:100%;max-width:520px;height:130px;background:#fff;border:1px dashed #6ee7b7;border-radius:8px;touch-action:none;margin-top:.3rem"></canvas>
                   <button class="ccf-btn sec" style="margin-top:.3rem" onclick="ccfBorrarFirma('${f.rol}')">🧽 Borrar y firmar de nuevo</button>`
                : `<div style="margin:.3rem 0">✅ Se usará la <strong>firma guardada</strong>.</div>
                   <button class="ccf-btn sec" onclick="ccfFirmarNueva('${f.rol}')">✏️ Firmar de nuevo en vez de usar la guardada</button>`}
        </div>`;
    }).join('');
    ccfFirmantes.forEach(f => { if (f.nueva || !f.guardada) ccfIniciarLienzo(f); });
}

function ccfFirmarNueva(rol) { const f = ccfFirmantes.find(x => x.rol === rol); if (f) { f.nueva = true; ccfMostrarFirmas(); } }
function ccfBorrarFirma(rol) { const f = ccfFirmantes.find(x => x.rol === rol); if (f) ccfIniciarLienzo(f); }

function ccfIniciarLienzo(f) {
    const c = ccfEl('ccfL_' + f.rol); if (!c) return;
    const g = c.getContext('2d');
    g.clearRect(0, 0, c.width, c.height); g.lineWidth = 2.5; g.lineCap = 'round'; g.strokeStyle = '#0b1f6b'; f.trazo = false;
    let dibujando = false;
    const pos = e => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
    c.onpointerdown = e => { dibujando = true; c.setPointerCapture(e.pointerId); const [x, y] = pos(e); g.beginPath(); g.moveTo(x, y); };
    c.onpointermove = e => { if (!dibujando) return; const [x, y] = pos(e); g.lineTo(x, y); g.stroke(); f.trazo = true; };
    c.onpointerup = () => { dibujando = false; };
}

// Cuerpo para el servidor: las firmas dibujadas (las guardadas las pone el servidor) y quién firma además del trabajador.
function ccfCuerpoDeclaracion() {
    const cuerpo = { pdf: ccfDeclPdf, firmas: {}, personas: {} };
    for (const f of ccfFirmantes) {
        if (f.persona) cuerpo.personas[f.rol] = f.persona;
        if ((f.nueva || !f.guardada) && f.trazo) cuerpo.firmas[f.rol] = ccfEl('ccfL_' + f.rol).toDataURL('image/png');
    }
    return cuerpo;
}

// Vista previa: el PDF con lo que haya firmado hasta ahora (y las firmas guardadas), sin guardar nada ni adjuntar.
async function ccfVistaPrevia() {
    const btn = ccfEl('ccfFirmaPrevia'), visor = ccfEl('ccfPreviaVisor');
    btn.disabled = true; btn.textContent = '⏳ Armando la vista previa…';
    const r = await ccfPedir('declaracion', 'POST', Object.assign(ccfCuerpoDeclaracion(), { previa: true }));
    btn.disabled = false; btn.textContent = '👁️ Ver cómo queda la declaración (vista previa)';
    if (!r.ok) { alert(r.error || 'No se pudo armar la vista previa.'); return; }
    const bytes = Uint8Array.from(atob(r.pdf), c => c.charCodeAt(0));
    visor.src = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' })) + '#zoom=70';
    visor.style.display = 'block';
}

async function ccfFirmarYAdjuntar() {
    const btn = ccfEl('ccfFirmaBtn');
    const sinFirma = ccfFirmantes.filter(f => (f.nueva || !f.guardada) && !f.trazo);
    if (sinFirma.length) { alert('Faltan firmas: ' + sinFirma.map(f => f.etiqueta).join('; ') + '.'); return; }
    btn.disabled = true; btn.textContent = '⏳ Firmando…';
    const r = await ccfPedir('declaracion', 'POST', ccfCuerpoDeclaracion());
    if (!r.ok) { btn.disabled = false; btn.textContent = '✍️ Firmar y radicar la afiliación'; alert(r.error || 'No se pudo firmar la declaración.'); return; }
    btn.textContent = '⏳ Adjuntando en el portal…';
    const s = await ccfExt('ccfSubirDeclaracion', { base64: r.pdf, nombre: `DeclaracionJuramentada_${ccfPrep.resumen?.documento?.replace(/\D/g, '') || ''}.pdf` }, 120);
    btn.disabled = false; btn.textContent = '✍️ Firmar y radicar la afiliación';
    const caja = ccfEl('ccfFirmaTexto');
    if (!s.ok) { caja.innerHTML = '❗ ' + ccfEsc(s.error || 'La extensión no pudo adjuntar la declaración.'); return; }
    ['ccfFirmasLista', 'ccfFirmaPrevia', 'ccfFirmaBtn'].forEach(id => ccfEl(id).style.display = 'none');
    caja.innerHTML = s.quedan
        ? `⚠️ Se adjuntó en ${s.subidos} de ${s.pendientes} documentos; revisa el resto en el portal.`
        : `✅ Declaración firmada por ${r.firmantes.join(', ')} y adjunta en ${s.subidos} lugar(es) del portal, y guardada en BryNex. Revisa el paso Anexos y pulsa <strong>Finalizar y radicar</strong>.`;
    if (!s.quedan) ccfEl('ccfFinalizarBox').style.display = 'block';
}

// Radica de verdad: el portal acepta los términos y condiciones y envía la afiliación a
// Comfenalco. Solo corre cuando la persona pulsa el botón y confirma.
async function ccfFinalizar() {
    const r = ccfPrep.resumen || {};
    if (!confirm(`¿Finalizar y radicar la afiliación a la caja Comfenalco Valle?\n\n${r.trabajador} — ${r.documento}\nIngreso: ${ccfFmt(r.fecha_ingreso)}\n\nEsto ACEPTA los términos y condiciones del portal y envía la afiliación a Comfenalco. No se puede deshacer.`)) return;
    const btn = ccfEl('ccfFinalizarBtn'), log = ccfEl('ccfFinalizarLog');
    clearInterval(ccfReloj);   // el sondeo de pasos no debe competir con la radicación
    btn.style.display = 'none'; log.style.display = 'block';
    const inicio = Date.now();
    let pasos = [];
    const pintar = () => {
        const seg = Math.floor((Date.now() - inicio) / 1000);
        log.innerHTML = `<strong>⏱ ${seg} s — radicando en el portal de Comfenalco…</strong><br>` +
            (pasos.length ? pasos.map((p, i) => (i === pasos.length - 1 && !/^[✅❗]/.test(p.m) ? '⏳ ' : (/^[✅❗]/.test(p.m) ? '' : '✔ ')) + ccfEsc(p.m)).join('<br>') : 'Iniciando…');
    };
    pintar();
    const reloj = setInterval(pintar, 1000);
    const sondeo = setInterval(async () => {
        const e = await ccfExt('ccfProgreso', {}, 10);
        if (e.ok && e.progreso?.pasos) pasos = e.progreso.pasos;
    }, 1500);
    const f = await ccfExt('ccfFinalizar', {}, 180);
    clearInterval(reloj); clearInterval(sondeo);
    const ult = await ccfExt('ccfProgreso', {}, 10);
    if (ult.ok && ult.progreso?.pasos) pasos = ult.progreso.pasos;
    pintar();
    if (!f.ok || f.error) {
        btn.style.display = 'block'; btn.disabled = false; btn.textContent = '🚀 Reintentar: finalizar y radicar';
        alert((f.error || 'La extensión no pudo finalizar.') + (f.botones ? '\nBotones: ' + f.botones.join(' | ') : ''));
        return;
    }
    if (!f.numero) { mostrarRadicadoCaja(f); return; }
    ccfFinal = f;
    log.insertAdjacentHTML('beforeend', '<br>⏳ Registrando en BryNex…');
    const g = await ccfPedir('aplicar', 'POST', { numero: f.numero, texto: f.texto || '', pdf: f.pdf || null });
    if (!g.ok) { mostrarRadicadoCaja(f); alert('Se radicó en el portal (formulario ' + f.numero + ') pero no se pudo registrar en BryNex: ' + (g.error || g.mensaje || '')); return; }
    if (!g.pdf_guardado) {                                         // el PDF no llegó al radicar: se le pide otra vez al portal
        log.insertAdjacentHTML('beforeend', '<br>📄 El PDF no llegó: pidiéndoselo otra vez al portal…');
        const rf = await ccfExt('ccfFormulario', {}, 70);
        if (rf.ok && rf.base64) { const pp = await ccfPedir('pdf-radicado', 'POST', { pdf: rf.base64 }); if (pp.ok) g.pdf_guardado = true; }
    }
    ccfTerminar(`✅ ${ccfEsc(g.mensaje)}` + (g.pdf_guardado ? ' El PDF del formulario quedó guardado en el radicado.' :
        '<br>⚠️ <strong>El PDF del formulario no se pudo capturar.</strong> Descárgalo del portal (o del correo «Afiliación exitosa» de sirap@comfenalcovalle.com.co) y súbelo con «Subir PDF» en el radicado.'));
}

function mostrarRadicadoCaja(fin) {
    ccfFinal = fin;
    ccfEl('ccfRadicado').style.display = 'block';
    ccfEl('ccfNumero').value = fin.numero || '';
    ccfEl('ccfRadicadoInfo').innerHTML = fin.numero
        ? `📨 El portal radicó la afiliación con el formulario <strong>${ccfEsc(fin.numero)}</strong>.`
        : '📨 El portal respondió, pero no se encontró el número: cópialo de la ventana de éxito.';
    ccfEl('ccfRadicadoTexto').style.display = fin.texto ? 'block' : 'none';
    ccfEl('ccfRadicadoTexto').textContent = (fin.texto || '').slice(0, 1200);
}

async function guardarCajaComfenalco() {
    const numero = ccfEl('ccfNumero').value.trim();
    if (!numero) { alert('Escribe el número de formulario que dio el portal.'); return; }
    const btn = ccfEl('ccfBtnGuardar');
    btn.disabled = true; btn.textContent = '⏳ Registrando...';
    const r = await ccfPedir('aplicar', 'POST', { numero, texto: ccfFinal?.texto || '', pdf: ccfFinal?.pdf || null });
    btn.disabled = false; btn.textContent = '💾 Registrar en BryNex';
    if (!r.ok) { alert(r.error || r.mensaje || 'No se pudo registrar.'); return; }
    ccfTerminar(`✅ ${ccfEsc(r.mensaje)}`);
}

async function rechazoCajaComfenalco() {
    const motivo = prompt('¿Qué dijo el portal? (queda en el radicado como error)', ccfFinal?.texto || '');
    if (motivo === null) return;
    const r = await ccfPedir('aplicar', 'POST', { error: motivo || 'El portal no radicó la afiliación.', texto: ccfFinal?.texto || '' });
    ccfTerminar(`⛔ ${ccfEsc(r.mensaje || r.error || '')}`);
}

function ccfTerminar(html) {
    clearInterval(ccfReloj); ccfDetenerBitacora();
    ccfEl('ccfContenido').style.display = 'none';
    ccfEl('ccfResultado').style.display = 'block';
    ccfEl('ccfResultado').innerHTML = html + '<br><span style="color:#475569">Comfenalco verifica en máximo 2 días y manda el correo «Afiliación exitosa».</span>';
    if (typeof mostrarToast === 'function') mostrarToast('Radicado de caja actualizado. Recarga para verlo.', 'success');
}
</script>
