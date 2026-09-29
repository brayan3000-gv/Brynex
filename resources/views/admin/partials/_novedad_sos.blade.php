{{--
    Modal de novedad de inicio laboral en S.O.S., autocontenido.

    Lo abre `abrirNovedadSos(contratoId)` desde el radicado de EPS. El login de
    S.O.S. pide captcha, así que el portal lo opera la extensión BryNex Portales
    en este mismo navegador: la persona abre S.O.S. en otra pestaña, inicia
    sesión como siempre y vuelve aquí. El modal le pide a la extensión consultar,
    radicar, adjuntar el lado B o bajar el certificado, y le manda a BryNex lo
    que salió para dejar el radicado al día.
--}}
<style>
.sosn-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.sosn-bg.open { display:flex }
.sosn-box { background:#fff;border-radius:14px;max-width:560px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.sosn-head { background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.sosn-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.sosn-x { background:none;border:none;color:#dbeafe;font-size:1.15rem;cursor:pointer;line-height:1 }
.sosn-body { padding:1.1rem }
.sosn-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.sosn-resumen span { color:#64748b }
.sosn-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.sosn-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.sosn-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.sosn-info { background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#1e3a8a;line-height:1.55 }
.sosn-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.sosn-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.sosn-btn.sec { background:#fff;color:#1d4ed8;border:1px solid #93c5fd }
.sosn-btn:disabled { opacity:.5;cursor:not-allowed }
.sosn-fecha { display:flex;gap:.5rem;align-items:center;font-size:.78rem;margin:.3rem 0 .2rem }
.sosn-fecha input { border:1px solid #cbd5e1;border-radius:7px;padding:.3rem .5rem;font-size:.8rem }
.sosn-correo { border:1px solid #c7d2fe;border-radius:10px;padding:.7rem .8rem;margin-top:.8rem;background:#fafbff }
.sosn-correo h4 { margin:0 0 .5rem;font-size:.84rem;color:#312e81 }
.sosn-campo { display:block;font-size:.72rem;color:#475569;font-weight:600;margin:.45rem 0 .15rem }
.sosn-campo + input, .sosn-campo + textarea, .sosn-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.35rem .5rem;font-size:.78rem;font-family:inherit }
.sosn-adj { font-size:.74rem;margin:.2rem 0 0 1rem;padding:0;line-height:1.6 }
.sosn-chip { display:inline-block;margin-top:.25rem;font-size:.7rem;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:.05rem .5rem;cursor:pointer }
.sosn-pasos { font-size:.74rem;color:#475569;margin:.4rem 0 0 1.1rem;padding:0;line-height:1.6 }
</style>

<div class="sosn-bg" id="sosnModal">
  <div class="sosn-box">
    <div class="sosn-head">
      <h3>🏥 Novedad de inicio laboral en S.O.S.</h3>
      <button class="sosn-x" onclick="cerrarNovedadSos()">✕</button>
    </div>
    <div class="sosn-body">
      <div id="sosnCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>

      <div id="sosnContenido" style="display:none">
        <div class="sosn-resumen" id="sosnResumen"></div>
        <div class="sosn-prob" id="sosnProblemas" style="display:none">
          <strong>No se puede tramitar todavía:</strong>
          <ul id="sosnProblemasLista"></ul>
        </div>

        {{-- Extensión y sesión del portal --}}
        <div class="sosn-info" id="sosnSesion"></div>
        <button class="sosn-btn sec" id="sosnBtnAbrir" style="display:none" onclick="abrirPortalSos()">🌐 Abrir S.O.S. para iniciar sesión</button>

        <div class="sosn-info" id="sosnPortal" style="display:none"></div>
        <div class="sosn-aviso" id="sosnAviso" style="display:none"></div>

        <button class="sosn-btn sec" id="sosnBtnConsultar" style="display:none" onclick="consultarSos()">🔎 Consultar en S.O.S.</button>
        <div id="sosnRegistro" style="display:none">
          {{-- La fecha no se pregunta: es la real del contrato, o la más cercana
               que S.O.S. acepte si quedó fuera de su ventana de ±10 días. --}}
          <div class="sosn-fecha" id="sosnFechaFila">
            <strong>Se reporta con fecha de ingreso:</strong>
            <span id="sosnFechaTexto" style="font-weight:800;color:#1e3a5f"></span>
            <input type="hidden" id="sosnFecha">
          </div>
          <div id="sosnFechaNota" style="font-size:.72rem;color:#64748b"></div>
          <button class="sosn-btn" id="sosnBtnRegistrar" onclick="registrarSos()">🏥 Registrar y adjuntar lado B</button>
        </div>

        {{-- Plan B: correo al asesor de S.O.S. --}}
        <button class="sosn-btn sec" id="sosnBtnCorreo" style="display:none" onclick="abrirCorreoSos('manual')">📧 Enviar por correo al asesor de S.O.S.</button>
        <div class="sosn-correo" id="sosnCorreo" style="display:none">
          <h4>📧 Afiliación por correo al asesor de S.O.S.</h4>
          <div class="sosn-aviso" id="sosnCorreoMotivo" style="display:none"></div>
          <div class="sosn-prob" id="sosnCorreoProblemas" style="display:none"></div>
          <div id="sosnCorreoSubir" style="display:none;font-size:.74rem;margin-bottom:.5rem">
            <label class="sosn-campo" for="sosnCorreoArchivo">Subir copia del documento de identidad (opcional, PDF o imagen)</label>
            <input type="file" id="sosnCorreoArchivo" accept=".pdf,.jpg,.jpeg,.png" class="sosn-input" onchange="subirDocumentoSos()">
          </div>
          <div class="sosn-aviso" id="sosnCorreoAvisos" style="display:none"></div>

          {{-- Sin la firma dibujada el formulario sale en blanco y S.O.S. lo
               devuelve: aquí están las dos salidas, firmar o revisar si ya firmó. --}}
          <div id="sosnCorreoFirma" style="display:none;border:1px solid #fbbf24;background:#fffbeb;border-radius:9px;padding:.55rem .7rem;margin-bottom:.5rem">
            <div style="font-size:.76rem;color:#92400e;font-weight:700;margin-bottom:.4rem">✍️ Falta la firma del contratista</div>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
              <button class="sosn-btn" style="flex:1;min-width:190px" onclick="firmarParaCorreoSos()">✍️ Abrir formulario y firmar</button>
              <button class="sosn-btn sec" style="flex:1;min-width:150px" onclick="abrirCorreoSos(sosnCorreoMotivoActual, true)">✅ Ya firmó, revisar</button>
            </div>
          </div>

          <label class="sosn-campo" for="sosnCorreoPara">Para</label>
          <input id="sosnCorreoPara" class="sosn-input">
          <span class="sosn-chip" id="sosnCorreoReemplazo" style="display:none" onclick="usarReemplazoSos()"></span>
          <label class="sosn-campo" for="sosnCorreoCc">CC (opcional)</label>
          <input id="sosnCorreoCc" class="sosn-input">
          <label class="sosn-campo" for="sosnCorreoAsunto">Asunto</label>
          <input id="sosnCorreoAsunto" class="sosn-input">
          <label class="sosn-campo" for="sosnCorreoCuerpo">Mensaje</label>
          <textarea id="sosnCorreoCuerpo" rows="12" class="sosn-input"></textarea>
          <label id="sosnCorreoBenefFila" style="display:none;font-size:.74rem;margin-top:.4rem"><input type="checkbox" id="sosnCorreoBenef" checked onchange="abrirCorreoSos(sosnCorreoMotivoActual, true)"> Incluir beneficiarios</label>
          <div class="sosn-campo">Adjuntos</div>
          <ul class="sosn-adj" id="sosnCorreoAdjuntos"></ul>
          <div id="sosnCorreoInfo" style="font-size:.72rem;color:#64748b;margin-top:.4rem"></div>
          <button class="sosn-btn" id="sosnBtnEnviarCorreo" onclick="enviarCorreoSos()">📧 Enviar correo</button>
        </div>
      </div>

      <div id="sosnResultado" class="sosn-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let sosnContratoId = null, sosnPrep = {}, sosnNovedad = null, sosnReloj = null;
const SOSN_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const sosnEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sosnFmt = iso => iso ? iso.split('-').reverse().join('/') : '—';
const sosnEl = id => document.getElementById(id);
const sosnNorm = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase()
    .replace(/SOCIEDAD POR ACCIONES SIMPLIFICADA|S\.?\s?A\.?\s?S\.?|LTDA\.?|\s+/g, ' ').trim();

function cerrarNovedadSos() { sosnEl('sosnModal').classList.remove('open'); clearInterval(sosnReloj); }

async function sosnPedir(ruta, metodo = 'GET', cuerpo = null, limiteSeg = 60) {
    const corte = new AbortController();
    const alarma = setTimeout(() => corte.abort(), limiteSeg * 1000);
    try {
        const r = await fetch(`/admin/afiliaciones/${sosnContratoId}/sos/${ruta}`, {
            method: metodo, signal: corte.signal,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': SOSN_CSRF },
            body: cuerpo ? JSON.stringify(cuerpo) : null,
        });
        return await r.json();
    } finally {
        clearTimeout(alarma);
    }
}

/** Pedido a la extensión BryNex Portales (por el puente que ella inyecta en esta página). */
function sosnExt(accion, datos = {}, limiteSeg = 300) {
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'sos', accion, datos }, window.location.origin);
    });
}

function sosnEsperar(btn, texto) {
    const desde = Date.now();
    const pintar = () => btn.textContent = `⏳ ${texto} ${Math.round((Date.now() - desde) / 1000)}s`;
    btn.disabled = true; pintar();
    const reloj = setInterval(pintar, 1000);
    return () => clearInterval(reloj);
}

function sosnPintarResumen(r) {
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${sosnEsc(v)}</strong></div>` : '';
    sosnEl('sosnResumen').innerHTML =
        li('Trabajador', `${r.trabajador} — ${r.documento}`) +
        li('Empresa', `${r.razon_social} (NIT ${r.nit})`) +
        li('Plan / EPS', `${r.plan ?? '—'} · ${r.eps ?? '—'}`) +
        li('IBC', r.ibc ? '$' + Number(r.ibc).toLocaleString('es-CO') : null) +
        li('ARL / AFP', `${r.arl} · ${r.afp}`) +
        li('Ingreso real', sosnFmt(r.fecha_ingreso) + (r.en_plazo ? '' : ' ⚠️ fuera del plazo de S.O.S. (±10 días)'));
}

async function abrirNovedadSos(contratoId) {
    sosnContratoId = contratoId; sosnNovedad = null;
    ['sosnContenido', 'sosnResultado', 'sosnPortal', 'sosnAviso', 'sosnRegistro', 'sosnBtnConsultar', 'sosnBtnAbrir'].forEach(id => sosnEl(id).style.display = 'none');
    sosnEl('sosnCargando').style.display = 'block';
    sosnEl('sosnModal').classList.add('open');

    try { sosnPrep = await sosnPedir('precheck'); }
    catch (e) { sosnEl('sosnCargando').textContent = '⚠️ No se pudo revisar el contrato.'; return; }

    sosnEl('sosnCargando').style.display = 'none';
    sosnEl('sosnContenido').style.display = 'block';
    sosnPintarResumen(sosnPrep.resumen || {});

    const problemas = sosnPrep.problemas || [];
    sosnEl('sosnProblemas').style.display = problemas.length ? 'block' : 'none';
    sosnEl('sosnProblemasLista').innerHTML = problemas.map(p => `<li>${sosnEsc(p)}</li>`).join('');
    // El correo al asesor sirve aunque el portal no aplique (p. ej. independientes).
    sosnEl('sosnBtnCorreo').style.display = 'block';
    sosnEl('sosnCorreo').style.display = 'none';
    if (problemas.length) {
        sosnEl('sosnSesion').style.display = 'none';
        if ((sosnPrep.resumen || {}).independiente) abrirCorreoSos('independiente');
        return;
    }
    sosnEl('sosnSesion').style.display = 'block';

    await revisarSesionSos();
}

/** Revisa extensión y pestaña de S.O.S.; mientras falte algo, vuelve a mirar cada 3 s. */
async function revisarSesionSos() {
    clearInterval(sosnReloj);
    const r = sosnPrep.resumen || {};
    const e = await sosnExt('estado', {}, 15);
    const caja = sosnEl('sosnSesion');
    const abrir = sosnEl('sosnBtnAbrir');
    let lista = false;

    if (e.sinExtension) {
        caja.innerHTML = '🧩 Falta la extensión <strong>BryNex Portales</strong> en este navegador. Pídele a soporte que la instale (Chrome → Extensiones → Cargar descomprimida → carpeta <code>extensiones/brynex-portales</code>) y recarga la página.';
        abrir.style.display = 'none';
    } else if (!e.ok) {
        caja.innerHTML = '⚠️ ' + sosnEsc(e.error);
    } else if (!e.abierta || !e.sesion) {
        caja.innerHTML = (e.abierta ? '🔐 La pestaña de S.O.S. está abierta pero sin sesión.' : '🔐 Abre S.O.S. en otra pestaña e inicia sesión.') +
            `<ol class="sosn-pasos"><li>Pulsa el botón de abajo.</li><li>El usuario${r.usuario_portal ? ' (<strong>' + sosnEsc(r.usuario_portal) + '</strong>)' : ''} y la contraseña se llenan solos; resuelve el captcha y pulsa Ingresar.</li><li>Vuelve a esta pestaña: se detecta sola.</li></ol>`;
        abrir.style.display = 'block';
    } else if (sosnNorm(e.empresa).split(' ')[0] !== sosnNorm(r.razon_social).split(' ')[0]) {
        caja.innerHTML = `⚠️ La sesión de S.O.S. es de <strong>${sosnEsc(e.empresa)}</strong>, pero el contrato es de <strong>${sosnEsc(r.razon_social)}</strong>. Cierra sesión en S.O.S. y entra con la empresa correcta.`;
        abrir.style.display = 'block';
    } else {
        caja.innerHTML = `✅ Sesión de S.O.S. abierta: ${sosnEsc(e.empresa)}. No uses esa pestaña mientras se hace el trámite.`;
        abrir.style.display = 'none';
        lista = true;
    }

    sosnEl('sosnBtnConsultar').style.display = lista ? 'block' : 'none';
    if (!lista && !e.sinExtension) sosnReloj = setInterval(revisarSesionSos, 3000);
    return lista;
}

async function abrirPortalSos() {
    // La clave solo viaja ahora, para llenar el login; la extensión no la guarda.
    let cred = {};
    try { cred = await sosnPedir('credencial', 'POST', {}, 20); } catch (e) {}
    await sosnExt('abrir', { usuario: cred.usuario || sosnPrep.resumen?.usuario_portal || '', contrasena: cred.contrasena || '' }, 40);
    cred = null;
    clearInterval(sosnReloj);
    sosnReloj = setInterval(revisarSesionSos, 3000);
}

async function consultarSos() {
    const btn = sosnEl('sosnBtnConsultar');
    const parar = sosnEsperar(btn, 'Consultando en S.O.S....');
    const d = await sosnExt('consultar', sosnPrep.portal.filtro, 180);
    parar(); btn.disabled = false; btn.textContent = '🔎 Consultar de nuevo';

    if (!d.ok) { alert(d.error || 'No se pudo consultar.'); revisarSesionSos(); return; }

    const filas = (d.filas || []).sort((a, b) => b.radicado.localeCompare(a.radicado));
    sosnNovedad = filas[0] || null;
    const n = sosnNovedad;
    const r = sosnPrep.resumen;

    const portal = sosnEl('sosnPortal');
    portal.innerHTML = n
        ? `<div>Ya tiene novedad en S.O.S.: radicado <strong>${sosnEsc(n.radicado)}</strong> del ${sosnEsc(n.fecha_radicacion)}</div>` +
          `<div>Estado: <strong>${sosnEsc(n.estado)}</strong></div>` +
          (n.causal ? `<div>Motivo de S.O.S.: <strong>${sosnEsc(n.causal)}</strong></div>` : '')
        : '<div>No tiene novedad de inicio laboral reciente en S.O.S.</div>';
    portal.style.display = 'block';

    const aviso = sosnEl('sosnAviso');
    aviso.style.display = 'none';
    const reg = sosnEl('sosnBtnRegistrar');
    const fecha = sosnEl('sosnFecha');

    const devuelta = n && /no aprobad|incorrect|declinad|devuelt|rechaz/i.test(n.estado);
    if (devuelta) {
        // Se registra el motivo en el radicado y se pasa directo al correo al asesor.
        sosnPedir('aplicar', 'POST', { novedad: n }, 60).catch(() => {});
        sosnEl('sosnRegistro').style.display = 'none';
        abrirCorreoSos('portal_rechazo', `radicado ${n.radicado}: ${n.causal || n.estado}`);
        return;
    }
    if (n) {
        sosnEl('sosnFechaFila').style.display = 'none';
        sosnEl('sosnFechaNota').textContent = '';
        reg.textContent = /cara b/i.test(n.estado) ? '📎 Adjuntar lado B y actualizar radicado'
            : /aprobad/i.test(n.estado) && !/no aprobad/i.test(n.estado) ? '📄 Bajar certificado y cerrar radicado'
            : '🔗 Actualizar el radicado con este estado';
    } else {
        sosnEl('sosnFechaFila').style.display = 'flex';
        fecha.value = r.fecha_reportar || r.fecha_ingreso;
        sosnEl('sosnFechaTexto').textContent = sosnFmt(fecha.value);
        sosnEl('sosnFechaNota').textContent = `S.O.S. acepta del ${sosnFmt(r.fecha_minima)} al ${sosnFmt(r.fecha_maxima)}.` +
            (r.en_plazo ? '' : ' Es la más cercana al ingreso real que el portal acepta.');
        if (!r.en_plazo) {
            aviso.innerHTML = `⚠️ El ingreso real (${sosnFmt(r.fecha_ingreso)}) está fuera del plazo de S.O.S.: se reporta el ${sosnFmt(fecha.value)} y queda anotado en el radicado. El lado B que se adjunta lleva la fecha real.`;
            aviso.style.display = 'block';
        }
        reg.textContent = '🏥 Registrar y adjuntar lado B';
    }
    reg.disabled = false;
    sosnEl('sosnRegistro').style.display = 'block';
}

async function registrarSos() {
    const nueva = !sosnNovedad;
    const fecha = sosnEl('sosnFecha').value;
    if (nueva && !fecha) { alert('No se pudo resolver la fecha de ingreso a reportar; vuelve a consultar.'); return; }
    if (nueva && !confirm(`¿Radicar la novedad en S.O.S. con fecha de ingreso ${sosnFmt(fecha)} y adjuntar el lado B firmado?\n\nQueda radicada en el portal y no se puede anular desde aquí.`)) return;

    const btn = sosnEl('sosnBtnRegistrar');
    const parar = sosnEsperar(btn, 'Trabajando en S.O.S....');
    const filtro = sosnPrep.portal.filtro;
    let paso = { novedad: sosnNovedad };

    try {
        if (nueva) {
            const [a, m, d] = fecha.split('-');
            const envio = { ...sosnPrep.portal.envio, fecha: `${d}/${m}/${a}` };
            const resultado = await sosnExt('registrar', envio, 300);
            if (!resultado.ok && ['cargar', 'validar'].includes(resultado.paso)) {
                // Plan B: S.O.S. no dejó registrar (la X): se envía por correo al asesor.
                parar();
                btn.disabled = false; btn.textContent = '🏥 Registrar y adjuntar lado B';
                abrirCorreoSos('portal_rechazo', resultado.error);
                return;
            }
            let novedad = null;
            if (resultado.ok) {
                const hoy = new Date();
                const dd = String(hoy.getDate()).padStart(2, '0'), mm = String(hoy.getMonth() + 1).padStart(2, '0');
                const c = await sosnExt('consultar', { ...filtro, desde: `${dd}/${mm}/${hoy.getFullYear()}` }, 180);
                novedad = (c.filas || []).sort((x, y) => y.radicado.localeCompare(x.radicado))[0] || null;
            }
            paso = { novedad, registro: { fecha, envio, resultado } };
        }

        // Se repite hasta que BryNex no pida nada más (adjuntar lado B o bajar certificado).
        let resp = await sosnPedir('aplicar', 'POST', paso, 120);
        for (let vueltas = 0; resp.ok && resp.siguiente && vueltas < 3; vueltas++) {
            if (resp.siguiente === 'adjuntar') {
                const adj = await sosnExt('adjuntar', { ...filtro, archivo: sosnPrep.portal.lado_b_url }, 300);
                if (!adj.novedad) throw new Error('No se pudo adjuntar el lado B: ' + (adj.error || 'sin detalle') + '. Plazo: 48 horas.');
                resp = await sosnPedir('aplicar', 'POST', { novedad: adj.novedad, adjunto: true }, 120);
            } else if (resp.siguiente === 'certificado') {
                const cert = await sosnExt('certificado', filtro, 180);
                if (!cert.ok) throw new Error('No se pudo bajar el certificado: ' + (cert.error || 'sin detalle'));
                resp = await sosnPedir('aplicar', 'POST', { novedad: { radicado: cert.radicado, estado: cert.estado }, certificado: cert.pdf }, 120);
            } else break;
        }

        if (!resp.ok && !resp.radicado) throw new Error(resp.error || resp.mensaje || 'No se pudo actualizar el radicado.');

        parar();
        sosnEl('sosnContenido').style.display = 'none';
        const caja = sosnEl('sosnResultado');
        caja.innerHTML = `${resp.ok ? '✅' : '⚠️'} S.O.S. — radicado <strong>${sosnEsc(resp.radicado || '—')}</strong>${resp.estado_eps ? ': ' + sosnEsc(resp.estado_eps) : ''}<br>` +
            `<span style="color:#475569">${sosnEsc(resp.mensaje || '')}</span>`;
        caja.style.display = 'block';
        setTimeout(() => location.reload(), 4500);
    } catch (e) {
        parar();
        btn.disabled = false; btn.textContent = '🔁 Reintentar';
        alert(e.message + '\n\nAntes de reintentar pulsa "Consultar": si algo quedó radicado, aparecerá.');
    }
}

// ── Plan B: correo al asesor ────────────────────────────────────────────
let sosnCorreoMotivoActual = 'manual', sosnCorreoPrep = null, sosnCorreoDetalle = '';

// Abre el formulario del contrato en otra pestaña: ahí sale el lienzo para que
// el contratista firme. Al volver, «Ya firmó, revisar» rehace la vista previa.
function firmarParaCorreoSos() {
    window.open(`/admin/afiliaciones/${sosnContratoId}/formulario/eps`, '_blank');
}

async function abrirCorreoSos(motivo, conservarTexto = false, detalle = '') {
    if (typeof conservarTexto === 'string') { detalle = conservarTexto; conservarTexto = false; }
    sosnCorreoMotivoActual = motivo;
    const caja = sosnEl('sosnCorreo');
    caja.style.display = 'block';
    sosnEl('sosnBtnCorreo').style.display = 'none';
    const benef = sosnEl('sosnCorreoBenef').checked ? 1 : 0;
    const previo = conservarTexto ? { para: sosnEl('sosnCorreoPara').value, cc: sosnEl('sosnCorreoCc').value } : null;

    if (detalle) sosnCorreoDetalle = detalle;
    let d;
    try { d = await sosnPedir(`correo?motivo=${motivo}&con_beneficiarios=${benef}&detalle=${encodeURIComponent(sosnCorreoDetalle || '')}`); }
    catch (e) { alert('No se pudo preparar el correo.'); return; }
    if (!d.ok) { alert(d.error || 'No se pudo preparar el correo.'); return; }
    sosnCorreoPrep = d;

    const motivoTxt = { portal_rechazo: '❌ S.O.S. no aceptó la novedad por el portal' + (sosnCorreoDetalle ? ` (${sosnEsc(sosnCorreoDetalle)})` : '') + '. Se envía la afiliación por correo al asesor.',
        independiente: '👤 Independiente: el portal de empleadores no aplica. Se envía por correo al asesor.' }[motivo];
    sosnEl('sosnCorreoMotivo').innerHTML = motivoTxt || '';
    sosnEl('sosnCorreoMotivo').style.display = motivoTxt ? 'block' : 'none';

    const prob = d.problemas || [];
    sosnEl('sosnCorreoProblemas').innerHTML = prob.map(p => '• ' + sosnEsc(p)).join('<br>');
    sosnEl('sosnCorreoProblemas').style.display = prob.length ? 'block' : 'none';
    const av = d.avisos || [];
    sosnEl('sosnCorreoSubir').style.display = av.some(a => /documento de identidad/i.test(a)) ? 'block' : 'none';
    sosnEl('sosnCorreoAvisos').innerHTML = av.map(a => '⚠️ ' + sosnEsc(a)).join('<br>');
    sosnEl('sosnCorreoAvisos').style.display = av.length ? 'block' : 'none';

    // Mientras falte la firma no hay correo que enviar: el asesor lo devolvería.
    sosnEl('sosnCorreoFirma').style.display = d.falta_firma ? 'block' : 'none';
    const btnEnviar = sosnEl('sosnBtnEnviarCorreo');
    btnEnviar.disabled = !!d.falta_firma;
    btnEnviar.textContent = d.falta_firma ? '📧 Enviar correo (falta la firma)' : '📧 Enviar correo';

    sosnEl('sosnCorreoPara').value = previo ? previo.para : d.para.correo;
    sosnEl('sosnCorreoCc').value = previo ? previo.cc : '';
    sosnEl('sosnCorreoAsunto').value = d.asunto;
    sosnEl('sosnCorreoCuerpo').value = d.cuerpo;
    const rem = sosnEl('sosnCorreoReemplazo');
    rem.style.display = d.reemplazo ? 'inline-block' : 'none';
    if (d.reemplazo) rem.textContent = `↪ ¿${d.para.nombre} de vacaciones? Enviar a ${d.reemplazo.nombre} (${d.reemplazo.correo})`;
    sosnEl('sosnCorreoBenefFila').style.display = d.beneficiarios ? 'block' : 'none';

    sosnEl('sosnCorreoAdjuntos').innerHTML = (d.adjuntos || []).map(a => `<li>📎 ${sosnEsc(a.nombre)} <span style="color:#94a3b8">— ${sosnEsc(a.origen)}</span></li>`).join('');
    const previos = (d.previos || []).map(p => `${sosnEsc(p.estado)} · ${sosnEsc((p.enviado_at || '').slice(0, 16).replace('T', ' '))} → ${sosnEsc(p.para)}`).join('<br>');
    sosnEl('sosnCorreoInfo').innerHTML = `Sale desde <strong>${sosnEsc(d.buzon)}</strong>. Si no hay respuesta, se avisa el ${sosnEsc(d.vence)}.` +
        (previos ? `<br>Correos anteriores:<br>${previos}` : '');

    const btn = sosnEl('sosnBtnEnviarCorreo');
    btn.disabled = prob.length > 0;
    btn.textContent = prob.length ? '🚫 Resuelve lo que falta para enviar' : '📧 Enviar correo';
    caja.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function usarReemplazoSos() {
    if (!sosnCorreoPrep?.reemplazo) return;
    sosnEl('sosnCorreoPara').value = sosnCorreoPrep.reemplazo.correo;
    sosnEl('sosnCorreoCc').value = sosnCorreoPrep.para.correo;
    sosnEl('sosnCorreoCuerpo').value = sosnEl('sosnCorreoCuerpo').value.replace(/^Un cordial saludo, [^.\n]+\./, 'Un cordial saludo, ' + sosnCorreoPrep.reemplazo.nombre.split(' ')[0] + '.');
}

async function subirDocumentoSos() {
    const input = sosnEl('sosnCorreoArchivo');
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('archivo', input.files[0]);
    const r = await fetch(`/admin/afiliaciones/${sosnContratoId}/sos/correo/documento`, {
        method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': SOSN_CSRF },
    });
    const d = await r.json().catch(() => ({}));
    input.value = '';
    if (!r.ok || !d.ok) { alert(d.message || d.error || 'No se pudo subir el documento.'); return; }
    abrirCorreoSos(sosnCorreoMotivoActual, true);
}

async function enviarCorreoSos() {
    const para = sosnEl('sosnCorreoPara').value.trim();
    if (!para) { alert('Indica a quién va el correo.'); return; }
    if (!confirm(`¿Enviar la afiliación por correo a ${para}?\n\nSale desde ${sosnCorreoPrep?.buzon} con ${(sosnCorreoPrep?.adjuntos || []).length} adjuntos.`)) return;

    const btn = sosnEl('sosnBtnEnviarCorreo');
    const parar = sosnEsperar(btn, 'Enviando correo...');
    let d;
    try {
        d = await sosnPedir('correo/enviar', 'POST', {
            para, cc: sosnEl('sosnCorreoCc').value.trim(),
            asunto: sosnEl('sosnCorreoAsunto').value, cuerpo: sosnEl('sosnCorreoCuerpo').value,
            motivo: sosnCorreoMotivoActual, con_beneficiarios: sosnEl('sosnCorreoBenef').checked, detalle: sosnCorreoDetalle,
        }, 120);
    } catch (e) { d = { ok: false, error: 'Se perdió la conexión. Revisa en Gmail (Enviados) antes de reintentar.' }; }
    parar();

    if (!d.ok) { btn.disabled = false; btn.textContent = '📧 Reintentar envío'; alert(d.error || d.message || 'No se pudo enviar.'); return; }

    sosnEl('sosnContenido').style.display = 'none';
    const caja = sosnEl('sosnResultado');
    caja.innerHTML = `📧 Correo enviado a <strong>${sosnEsc(d.para)}</strong>.<br><span style="color:#475569">El radicado de EPS quedó en trámite. Si S.O.S. no responde, se avisa el ${sosnEsc(d.vence)}.</span>`;
    caja.style.display = 'block';
    setTimeout(() => location.reload(), 5000);
}
</script>
