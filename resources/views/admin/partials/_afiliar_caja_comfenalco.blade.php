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
let ccfContratoId = null, ccfPrep = {}, ccfReloj = null, ccfFinal = null;
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
    ccfContratoId = contratoId; ccfFinal = null; clearInterval(ccfReloj);
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

    ccfOpciones(ccfEl('ccfEstadoCivil'), ccfPrep.listas?.estados_civil, 1);
    ccfOpciones(ccfEl('ccfContrato'), ccfPrep.listas?.contratos, 1);
    ccfOpciones(ccfEl('ccfFormaPago'), ccfPrep.listas?.formas_pago, 10);   // Daviplata por defecto
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
    if (!e.abierta || !e.sesion) {
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
    await ccfExt('ccfAbrir', { usuario: cred.usuario || '', contrasena: cred.contrasena || '' }, 40);
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
    const r = await ccfExt('ccfConsultar', ccfDatosPortal(), 90);
    btn.disabled = false; btn.textContent = '🔎 Buscar al trabajador y empezar';
    if (!r.ok) { alert(r.error || 'No se pudo consultar al trabajador.'); return; }

    const nueva = (r.opciones || []).find(o => /Nueva/i.test(o.texto));
    const caja = ccfEl('ccfPasos');
    caja.style.display = 'block';
    btn.style.display = 'none';
    caja.innerHTML = `<div class="ccf-info">👤 ${ccfEsc(r.nombre || '')}<br>` +
        `Opciones del portal: ${(r.opciones || []).map(o => ccfEsc(o.texto.split(' Realiza')[0])).join(' · ') || '—'}` +
        (r.familia ? `<br>Grupo familiar que ya tiene en la caja: ${ccfEsc(r.familia)}` : '') + '</div>' +
        `<div class="ccf-aviso">👉 En el portal pulsa <strong>${nueva ? 'Nueva' : 'la opción que corresponda'}</strong> y ve avanzando con <strong>Continuar</strong>. ` +
        'BryNex va llenando cada paso; revisa siempre antes de continuar. Al final aceptas los términos y pulsas <strong>Finalizar Afiliación</strong>.</div>' +
        '<div id="ccfPasoActual" class="ccf-texto">Esperando el formulario…</div>';

    clearInterval(ccfReloj);
    const desde = Date.now();
    ccfReloj = setInterval(async () => {
        if (Date.now() - desde > 45 * 60 * 1000) { clearInterval(ccfReloj); return; }
        const fin = await ccfExt('ccfResultado', {}, 20);
        if (fin.ok && fin.radicado) { clearInterval(ccfReloj); mostrarRadicadoCaja(fin); return; }
        const p = await ccfExt('ccfPaso', ccfDatosPortal(), 30);
        if (!p.ok) return;
        ccfEl('ccfPasoActual').innerHTML = `<strong>Paso: ${ccfEsc(p.paso || '—')}</strong>` +
            (p.hecho?.length ? '<br>✅ ' + p.hecho.map(ccfEsc).join('<br>✅ ') : '') +
            (p.falta?.length ? '<br>⚠️ ' + p.falta.map(ccfEsc).join('<br>⚠️ ') : '') +
            (p.errores?.length ? '<br>❗ ' + p.errores.map(ccfEsc).join('<br>❗ ') : '');
    }, 4000);
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
    const r = await ccfPedir('aplicar', 'POST', { numero, texto: ccfFinal?.texto || '' });
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
    clearInterval(ccfReloj);
    ccfEl('ccfContenido').style.display = 'none';
    ccfEl('ccfResultado').style.display = 'block';
    ccfEl('ccfResultado').innerHTML = html + '<br><span style="color:#475569">Comfenalco verifica en máximo 2 días y manda el correo «Afiliación exitosa».</span>';
    if (typeof mostrarToast === 'function') mostrarToast('Radicado de caja actualizado. Recarga para verlo.', 'success');
}
</script>
