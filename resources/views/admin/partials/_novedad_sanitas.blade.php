{{--
    Modal de novedad de cambio de empleador en Sanitas, autocontenido.

    Lo abre `abrirNovedadSanitas(contratoId)` desde el radicado de EPS. Sanitas
    está detrás de Radware, así que el formulario web lo llena la extensión
    BryNex Portales en este mismo navegador: antes pide la firma del trabajador (la
    plantilla de Sanitas la lleva y la devuelven sin ella), abre Sanitas de fondo,
    el robot llena el formulario con el PDF adjunto y pulsa Enviar, y el modal lee
    el número de radicado y lo guarda en BryNex con la constancia. BryNex se queda
    al frente; solo se muestra la pestaña de Sanitas si hay que intervenir.
--}}
<style>
.sann-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.sann-bg.open { display:flex }
.sann-box { background:#fff;border-radius:14px;max-width:560px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.sann-head { background:linear-gradient(135deg,#0e7490,#0891b2);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.sann-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.sann-x { background:none;border:none;color:#cffafe;font-size:1.15rem;cursor:pointer;line-height:1 }
.sann-body { padding:1.1rem }
.sann-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.sann-resumen span { color:#64748b }
.sann-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.sann-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.sann-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.sann-info { background:#ecfeff;border:1px solid #a5f3fc;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#155e75;line-height:1.55 }
.sann-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.sann-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.sann-btn.sec { background:#fff;color:#0e7490;border:1px solid #67e8f9 }
.sann-btn.rojo { background:#fff;color:#b91c1c;border:1px solid #fecaca }
.sann-btn:disabled { opacity:.5;cursor:not-allowed }
.sann-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.4rem .55rem;font-size:.82rem;font-family:inherit }
.sann-texto { font-size:.7rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:.45rem .55rem;max-height:120px;overflow-y:auto;margin:.4rem 0;line-height:1.45 }
</style>

<div class="sann-bg" id="sannModal">
  <div class="sann-box">
    <div class="sann-head">
      <h3>🏥 Cambio de empleador en Sanitas</h3>
      <button class="sann-x" onclick="cerrarNovedadSanitas()">✕</button>
    </div>
    <div class="sann-body">
      <div id="sannCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>

      <div id="sannContenido" style="display:none">
        <div class="sann-resumen" id="sannResumen"></div>
        <div class="sann-prob" id="sannProblemas" style="display:none">
          <strong>No se puede radicar todavía:</strong>
          <ul id="sannProblemasLista"></ul>
        </div>
        <div class="sann-aviso" id="sannAvisos" style="display:none"></div>

        {{-- Sin la firma dibujada la plantilla de Sanitas sale en blanco y la devuelven:
             se dibuja aquí mismo y al guardarla queda habilitado Radicar. --}}
        <div class="sann-aviso" id="sannFirma" style="display:none">
          ✍️ <strong>Falta la firma del trabajador.</strong> Que la dibuje aquí con el dedo o el mouse y pulsa Guardar firma.
          <canvas id="sannCanvas" width="600" height="200" style="width:100%;height:auto;background:#fff;border:1px dashed #94a3b8;border-radius:8px;margin-top:.5rem;touch-action:none;cursor:crosshair;display:block"></canvas>
          <div style="display:flex;gap:.5rem">
            <button class="sann-btn sec" style="flex:1" onclick="sannLimpiarFirma()">🧹 Limpiar</button>
            <button class="sann-btn" style="flex:2" id="sannBtnFirma" onclick="sannGuardarFirma()">💾 Guardar firma</button>
          </div>
        </div>

        <div class="sann-info" id="sannSesion"></div>
        <button class="sann-btn sec" id="sannBtnAbrir" style="display:none" onclick="sannExt('novedadAbrir')">🌐 Abrir el formulario de novedades de Sanitas (de fondo)</button>
        <button class="sann-btn sec" id="sannBtnMostrar" style="display:none" onclick="sannExt('novedadMostrar')">👁️ Ver la pestaña de Sanitas</button>
        <button class="sann-btn" id="sannBtnLlenar" style="display:none" onclick="llenarNovedadSanitas()">🤖 Radicar en Sanitas (el robot llena y envía)</button>

        <div id="sannLleno" style="display:none"></div>

        {{-- Después del Enviar: número de radicado --}}
        <div id="sannEnviado" style="display:none">
          <div class="sann-info" id="sannEnviadoInfo"></div>
          <div class="sann-texto" id="sannEnviadoTexto" style="display:none"></div>
          {{-- Sanitas contestó con una falla suya («demoras temporales»): no hay número que
               guardar, el radicado sigue pendiente y se reintenta. --}}
          <div id="sannCaida" style="display:none">
            <button class="sann-btn" onclick="abrirNovedadSanitas(sannContratoId)">🔁 Reintentar ahora</button>
            <button class="sann-btn sec" onclick="sannEl('sannCaida').style.display='none'; sannEl('sannManual').style.display='block'">Sanitas sí dio un número: escribirlo</button>
          </div>
          <div id="sannManual">
            <label style="font-size:.74rem;font-weight:600;color:#475569">Número de radicado que dio Sanitas</label>
            <input id="sannNumero" class="sann-input" placeholder="Ej: 12345678">
            <button class="sann-btn" id="sannBtnGuardar" onclick="guardarNovedadSanitas()">💾 Guardar radicado en BryNex</button>
            <button class="sann-btn rojo" onclick="rechazoNovedadSanitas()">⛔ Sanitas no lo recibió</button>
          </div>
        </div>
      </div>

      <div id="sannResultado" class="sann-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let sannContratoId = null, sannPrep = {}, sannReloj = null, sannEnvio = null, sannAbrio = false;
// Segundos entre llenar y pulsar Enviar: con el clic inmediato Sanitas contesta «demoras
// temporales» (1-oct-2026: 5 de 5 rechazados a los ~3 s; pasaron con 20, 30 y 60 s). La
// espera la hace la extensión desde la 1.45.42; con una más vieja el robot llena y el
// Enviar lo pulsa la persona, que es lo que sí funciona.
let sannEspera = 20;
// Copias de BryNex Portales que contestan en este Chrome. Con más de una, cada pedido se
// hace varias veces a la vez (1-oct-2026: el formulario quedó adjunto cuatro veces y las
// copias viejas enviaban sin esperar), así que no se deja radicar hasta dejar una.
let sannCopias = 1;
const SANN_EXT_ESPERA = '1.45.42';
const SANN_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const sannEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sannEl = id => document.getElementById(id);
const sannFmt = iso => iso ? iso.split('-').reverse().join('/') : '—';

function cerrarNovedadSanitas() { sannEl('sannModal').classList.remove('open'); clearInterval(sannReloj); }

async function sannPedir(ruta, metodo = 'GET', cuerpo = null) {
    const r = await fetch(`/admin/afiliaciones/${sannContratoId}/sanitas/${ruta}`, {
        method: metodo,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': SANN_CSRF },
        body: cuerpo ? JSON.stringify(cuerpo) : null,
    });
    return await r.json();
}

/** Pedido a la extensión BryNex Portales (por el puente que ella inyecta en esta página). */
function sannExt(accion, datos = {}, limiteSeg = 180) {
    return new Promise((resolve) => {
        if (!document.documentElement.dataset.brynexPortales) {
            resolve({ ok: false, sinExtension: true, error: 'La extensión BryNex Portales no está instalada en este navegador.' });
            return;
        }
        const id = Date.now() + '-' + Math.random().toString(36).slice(2);
        // Cada copia instalada de la extensión contesta el mismo pedido: se cuentan las
        // respuestas un momento después de la primera (ver sannCopias).
        let respuestas = 0;
        const oyente = (ev) => {
            if (ev.source !== window || ev.data?.canal !== 'brynex-portales' || ev.data.tipo !== 'respuesta' || ev.data.id !== id) return;
            respuestas++;
            sannCopias = Math.max(sannCopias, respuestas);
            if (respuestas > 1) return;
            clearTimeout(alarma);
            setTimeout(() => window.removeEventListener('message', oyente), 1500);
            resolve(ev.data.respuesta || { ok: false, error: 'Respuesta vacía de la extensión.' });
        };
        window.addEventListener('message', oyente);
        const alarma = setTimeout(() => { window.removeEventListener('message', oyente); resolve({ ok: false, error: 'La extensión no respondió a tiempo.' }); }, limiteSeg * 1000);
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'sanitas', accion, datos }, window.location.origin);
    });
}

// ── Pad de firma ──────────────────────────────────────────────
let sannTrazo = false, sannDibuja = false, sannX = 0, sannY = 0, sannPadListo = false;

function sannIniciarPad() {
    if (sannPadListo) return;
    sannPadListo = true;
    const c = sannEl('sannCanvas'), ctx = c.getContext('2d');
    ctx.lineJoin = ctx.lineCap = 'round'; ctx.strokeStyle = '#000'; ctx.lineWidth = 3;
    const pos = (e) => {
        const r = c.getBoundingClientRect(), t = e.touches?.[0] ?? e;
        return [(t.clientX - r.left) * (c.width / r.width), (t.clientY - r.top) * (c.height / r.height)];
    };
    const ini = (e) => { sannDibuja = true; sannTrazo = true; [sannX, sannY] = pos(e); };
    const mover = (e) => {
        if (!sannDibuja) return;
        e.preventDefault();
        const [x, y] = pos(e);
        ctx.beginPath(); ctx.moveTo(sannX, sannY); ctx.lineTo(x, y); ctx.stroke();
        [sannX, sannY] = [x, y];
    };
    const fin = () => sannDibuja = false;
    c.addEventListener('mousedown', ini); c.addEventListener('mousemove', mover);
    c.addEventListener('mouseup', fin); c.addEventListener('mouseleave', fin);
    c.addEventListener('touchstart', ini, { passive: false }); c.addEventListener('touchmove', mover, { passive: false });
    c.addEventListener('touchend', fin);
}

function sannLimpiarFirma() {
    const c = sannEl('sannCanvas');
    c.getContext('2d').clearRect(0, 0, c.width, c.height);
    sannTrazo = false;
}

async function sannGuardarFirma() {
    if (!sannTrazo) { alert('Dibuja la firma antes de guardar.'); return; }
    const btn = sannEl('sannBtnFirma');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const form = new FormData();
        form.append('_token', SANN_CSRF);
        form.append('firma', sannEl('sannCanvas').toDataURL('image/png'));
        const r = await fetch(sannPrep.url_firma, { method: 'POST', headers: { 'Accept': 'application/json' }, body: form });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error('El servidor no guardó la firma.');
        // Con la firma guardada ya se puede radicar: se revalida y se habilita el botón.
        sannPrep = await sannPedir('precheck');
        sannLimpiarFirma();
        await revisarFormularioSanitas();
    } catch (e) {
        alert('No se pudo guardar la firma: ' + e.message);
    }
    btn.disabled = false; btn.textContent = '💾 Guardar firma';
}

async function abrirNovedadSanitas(contratoId) {
    sannContratoId = contratoId; sannEnvio = null; sannAbrio = false; clearInterval(sannReloj);
    ['sannContenido', 'sannResultado', 'sannLleno', 'sannEnviado', 'sannCaida', 'sannBtnAbrir', 'sannBtnMostrar', 'sannBtnLlenar', 'sannAvisos', 'sannFirma'].forEach(id => sannEl(id).style.display = 'none');
    sannEl('sannManual').style.display = 'block';
    sannEl('sannCargando').style.display = 'block';
    sannEl('sannCargando').textContent = '⏳ Revisando los datos del contrato...';
    sannEl('sannModal').classList.add('open');

    try { sannPrep = await sannPedir('precheck'); }
    catch (e) { sannEl('sannCargando').textContent = '⚠️ No se pudo revisar el contrato.'; return; }

    sannEl('sannCargando').style.display = 'none';
    sannEl('sannContenido').style.display = 'block';
    const r = sannPrep.resumen || {};
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${sannEsc(v)}</strong></div>` : '';
    sannEl('sannResumen').innerHTML =
        li('Trabajador', `${r.trabajador} — ${r.documento}`) +
        li('Empleador', `${r.razon_social} (NIT ${r.nit})`) +
        li('Ingreso', sannFmt(r.fecha_ingreso)) +
        li('Residencia', r.residencia) +
        li('Teléfonos', `fijo ${r.telefono_fijo || '—'} · celular ${r.celular || '—'}`) +
        li('Respuesta de Sanitas a', r.correo) +
        li('Radicado BryNex', r.estado_radicado ? `${r.estado_radicado}${r.numero_radicado ? ' · N° ' + r.numero_radicado : ''}` : null);

    const problemas = sannPrep.problemas || [];
    sannEl('sannProblemas').style.display = problemas.length ? 'block' : 'none';
    sannEl('sannProblemasLista').innerHTML = problemas.map(p => `<li>${sannEsc(p)}</li>`).join('');
    const avisos = sannPrep.avisos || [];
    sannEl('sannAvisos').style.display = avisos.length ? 'block' : 'none';
    sannEl('sannAvisos').innerHTML = avisos.map(sannEsc).join('<br>');
    if (problemas.length) { sannEl('sannSesion').style.display = 'none'; return; }

    sannEl('sannSesion').style.display = 'block';
    await revisarFormularioSanitas();
    // Mientras falte la pestaña o el formulario, se vuelve a mirar sola.
    sannReloj = setInterval(() => { if (!sannEnvio) revisarFormularioSanitas(); }, 3000);
}

async function revisarFormularioSanitas() {
    const caja = sannEl('sannSesion');
    const lleno = sannEl('sannLleno').style.display === 'block';
    sannEl('sannBtnAbrir').style.display = 'none';
    sannEl('sannBtnMostrar').style.display = 'none';

    // Sin firma no se radica: se vuelve a mirar hasta que el trabajador la dibuje.
    if (sannPrep.falta_firma && !lleno) {
        if (!sannDibuja && !sannTrazo) { try { const nuevo = await sannPedir('precheck'); if (nuevo.resumen) sannPrep = nuevo; } catch (err) { /* se reintenta */ } }
        sannEl('sannFirma').style.display = sannPrep.falta_firma ? 'block' : 'none';
        if (sannPrep.falta_firma) {
            sannIniciarPad();
            caja.innerHTML = '✍️ Dibuja la firma y guárdala para poder radicar.';
            sannEl('sannBtnLlenar').style.display = 'none';
            return;
        }
    }
    sannEl('sannFirma').style.display = 'none';

    const e = await sannExt('novedadEstado', {}, 20);
    if (e.sinExtension) {
        caja.innerHTML = '🧩 Instala la extensión <strong>BryNex Portales</strong> (carpeta <code>extensiones/brynex-portales</code>) y recarga esta página.';
        sannEl('sannBtnLlenar').style.display = 'none';
        return;
    }
    if (sannCopias > 1) {
        caja.innerHTML = `⚠️ Hay <strong>${sannCopias} copias de BryNex Portales</strong> instaladas en este Chrome y cada una hace el trámite: Sanitas recibiría el formulario repetido. ` +
            'Abre <code>chrome://extensions</code>, quita todas las «BryNex Portales» menos la de versión más alta y recarga esta página.';
        sannEl('sannBtnLlenar').style.display = 'none';
        return;
    }
    if (!e.abierta) {
        // Se abre sola y de fondo: BryNex sigue al frente.
        if (!sannAbrio) {
            sannAbrio = true;
            caja.innerHTML = '🌐 Abriendo el formulario de Sanitas de fondo...';
            await sannExt('novedadAbrir', {}, 20);
            return;
        }
        caja.innerHTML = '🌐 Abre el formulario de <strong>Novedades a la afiliación</strong> de Sanitas (se abre de fondo).';
        sannEl('sannBtnAbrir').style.display = 'block';
        sannEl('sannBtnLlenar').style.display = 'none';
        return;
    }
    if (!e.listo) {
        caja.innerHTML = '⚠️ ' + sannEsc(e.error || 'El formulario de Sanitas no está listo.');
        sannEl('sannBtnMostrar').style.display = 'block';
        sannEl('sannBtnLlenar').style.display = 'none';
        return;
    }
    if (lleno) return;
    caja.innerHTML = '✅ Firmado y el formulario de Sanitas está abierto de fondo. El robot lo llena, adjunta y envía.';
    sannEl('sannBtnLlenar').style.display = 'block';
}

/** ¿La extensión instalada es al menos la versión dada? */
function sannExtAlMenos(minima) {
    const v = (document.documentElement.dataset.brynexPortales || '0').split('.').map(Number);
    const m = minima.split('.').map(Number);
    for (let i = 0; i < m.length; i++) { if ((v[i] || 0) !== m[i]) return (v[i] || 0) > m[i]; }
    return true;
}

async function llenarNovedadSanitas() {
    const btn = sannEl('sannBtnLlenar');
    if (sannCopias > 1) { revisarFormularioSanitas(); return; }
    const espera = sannExtAlMenos(SANN_EXT_ESPERA) ? sannEspera : null;
    btn.disabled = true; btn.textContent = '⏳ El robot está llenando el formulario en Sanitas...';
    // Mientras la extensión espera para pulsar Enviar, el botón lleva la cuenta.
    const inicio = Date.now();
    const cuenta = espera ? setInterval(() => {
        const s = Math.round((Date.now() - inicio) / 1000);
        btn.textContent = s < 8 ? '⏳ El robot está llenando el formulario en Sanitas...'
            : `⏳ Formulario lleno: el robot pulsa Enviar en unos ${Math.max(espera + 8 - s, 1)} s (Sanitas rechaza el envío inmediato)`;
    }, 1000) : null;
    const r = await sannExt('novedadLlenar', { ...sannPrep.portal, enviar: espera !== null, esperar: espera || 0 }, 150 + (espera || 0));
    clearInterval(cuenta);
    btn.disabled = false; btn.textContent = '🤖 Radicar en Sanitas (el robot llena y envía)';

    if (!r.ok) { alert(r.error || 'No se pudo llenar el formulario de Sanitas.'); return; }

    const caja = sannEl('sannLleno');
    caja.style.display = 'block';
    caja.innerHTML = `<div class="${r.adjunto ? 'sann-info' : 'sann-aviso'}">` +
        `📝 Formulario lleno: municipio <strong>${sannEsc(r.municipio)}</strong>, tipo de novedad <strong>Cambio de empleador</strong>, ` +
        (r.adjunto ? 'formulario PDF <strong>adjunto</strong>.' : `<strong>${sannEsc(r.aviso)}</strong>`) +
        (r.requisitos ? `<div class="sann-texto">${sannEsc(r.requisitos)}</div>` : '') +
        (r.enviado
            ? `🤖 El robot pulsó <strong>Enviar</strong> en Sanitas${r.esperado ? ` ${r.esperado} s después de llenarlo` : ''}. No cierres este modal.</div>`
            : espera === null && r.adjunto
                ? '👉 Tu extensión BryNex Portales es anterior a la ' + SANN_EXT_ESPERA + ' y no sabe esperar antes de enviar (Sanitas rechaza el envío inmediato). Ve a la pestaña de Sanitas (se trajo al frente) y pulsa <strong>Enviar</strong> tú en un minuto. Actualízala desde Conciliar EPS. No cierres este modal.</div>'
                : '👉 El robot no pudo enviar solo: ve a la pestaña de Sanitas (se trajo al frente), revisa y pulsa <strong>Enviar</strong>. No cierres este modal.</div>');
    btn.style.display = 'none';
    sannEl('sannBtnMostrar').style.display = 'none';
    sannEl('sannSesion').innerHTML = r.enviado
        ? '⏳ Esperando la respuesta de Sanitas...'
        : '⏳ Esperando a que pulses <strong>Enviar</strong> en Sanitas...';

    // Espera la respuesta hasta 20 minutos. Si el robot ya dio Enviar y Sanitas no
    // contesta en 75 s, se trae su pestaña al frente para que la persona la vea.
    clearInterval(sannReloj);
    const desde = Date.now();
    let mostrada = !r.enviado;
    sannReloj = setInterval(async () => {
        if (!mostrada && Date.now() - desde > 75 * 1000) {
            mostrada = true;
            await sannExt('novedadMostrar', {}, 20);
            sannEl('sannSesion').innerHTML = '⚠️ Sanitas no ha respondido al Enviar del robot: se trajo su pestaña al frente. Revisa si pide algo o pulsa <strong>Enviar</strong> tú.';
        }
        if (Date.now() - desde > 20 * 60 * 1000) { clearInterval(sannReloj); sannEl('sannSesion').innerHTML = '⌛ Se dejó de esperar el Enviar. Si ya lo enviaste, escribe el número abajo.'; mostrarEnvioSanitas({}); return; }
        const res = await sannExt('novedadResultado', { documento: sannPrep.portal.documento }, 20);
        if (res.ok && res.enviado) { clearInterval(sannReloj); mostrarEnvioSanitas(res); }
        else if (res.errores?.length) sannEl('sannSesion').innerHTML = '⚠️ Sanitas marcó: ' + sannEsc(res.errores.join(' · ')) + ' — se trajo su pestaña al frente.';
    }, 3000);
}

function mostrarEnvioSanitas(res) {
    sannEnvio = res;
    sannEl('sannEnviado').style.display = 'block';
    sannEl('sannNumero').value = res.radicado || '';
    sannEl('sannFirma').style.display = 'none';
    sannEl('sannEnviadoInfo').innerHTML = res.radicado
        ? `📨 Sanitas respondió con el radicado <strong>${sannEsc(res.radicado)}</strong>. Revísalo y guárdalo.`
        : '📨 Se envió el formulario, pero no se encontró el número en la página. Cópialo de la pestaña de Sanitas.';
    const texto = [res.exito, (res.errores || []).join(' · '), res.texto].filter(Boolean).join(' — ');
    sannEl('sannEnviadoTexto').style.display = texto ? 'block' : 'none';
    sannEl('sannEnviadoTexto').textContent = texto.slice(0, 1500);
    sannEl('sannSesion').innerHTML = '✅ Enviado a Sanitas.';

    // Sanitas a veces contesta el Enviar con una falla suya («Estamos presentando demoras
    // temporales, por favor inténtalo más tarde») y vuelve a mostrar el formulario vacío.
    // No lo recibió: no hay número que pedir y no es un error del trámite, así que el
    // radicado se deja pendiente y se ofrece reintentar.
    const caida = !res.radicado && sannFallaSanitas(texto);
    if (caida) {
        sannEl('sannEnviadoInfo').className = 'sann-aviso';
        sannEl('sannEnviadoInfo').innerHTML = `⛔ <strong>Sanitas no recibió la novedad</strong> y no dio número: «${sannEsc(caida)}».` +
            '<br>En BryNex el radicado sigue <strong>pendiente</strong>. Reintenta en un rato.';
        sannEl('sannEnviadoTexto').style.display = 'none';
        sannEl('sannSesion').innerHTML = '⚠️ Sanitas no lo recibió.';
    } else {
        sannEl('sannEnviadoInfo').className = 'sann-info';
    }
    sannEl('sannCaida').style.display = caida ? 'block' : 'none';
    sannEl('sannManual').style.display = caida ? 'none' : 'block';

    // Con el número y la confirmación de Sanitas ("registrada exitosamente") se guarda solo.
    if (res.radicado && /exitosa/i.test(res.texto || '') && !(res.errores || []).length) {
        sannEl('sannEnviadoInfo').innerHTML = `📨 Sanitas registró el radicado <strong>${sannEsc(res.radicado)}</strong>. Guardando en BryNex...`;
        guardarNovedadSanitas();
    }
}

/** La frase con que Sanitas dice que falló de su lado, o null si el texto no trae ninguna. */
function sannFallaSanitas(texto) {
    const m = String(texto || '').match(/[^.—]*(demoras temporales|int[eé]ntalo (?:nuevamente|m[aá]s tarde)|intente (?:nuevamente|m[aá]s tarde)|ha ocurrido un error|servicio no (?:est[aá] )?disponible)[^.—]*/i);
    return m ? m[0].trim() : null;
}

async function guardarNovedadSanitas() {
    const numero = sannEl('sannNumero').value.trim();
    if (!numero) { alert('Escribe el número de radicado que dio Sanitas.'); return; }
    const btn = sannEl('sannBtnGuardar');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';
    const r = await sannPedir('aplicar', 'POST', { radicado: numero, texto: sannEnvio?.texto || '', captura: sannEnvio?.captura || null });
    btn.disabled = false; btn.textContent = '💾 Guardar radicado en BryNex';
    if (!r.ok) { alert(r.error || r.mensaje || 'No se pudo guardar.'); return; }
    sannActualizarFila(r.badge);
    sannTerminar(`✅ Radicado <strong>${sannEsc(r.radicado)}</strong> guardado: el radicado de EPS queda <strong>en trámite</strong>` +
        (r.pdf ? (r.con_formulario ? ' y la constancia, con el formulario firmado, quedó en los soportes.' : ' y la constancia quedó en los soportes.') : '.') + '<br><span style="color:#475569">Sanitas responde por correo; la conciliación lo pasará a OK.</span>');
}

async function rechazoNovedadSanitas() {
    const motivo = prompt('¿Qué dijo Sanitas? (queda en el radicado como error)', (sannEnvio?.errores || []).join(' · ') || '');
    if (motivo === null) return;
    const r = await sannPedir('aplicar', 'POST', { error: motivo || 'Sanitas no recibió la novedad.', texto: sannEnvio?.texto || '' });
    if (r.error) { alert(r.error); return; }
    sannActualizarFila(r.badge);
    sannTerminar(`⛔ ${sannEsc(r.mensaje)}`);
}

/** Deja la insignia del radicado en la tabla con su estado nuevo, sin recargar la página. */
function sannActualizarFila(b) {
    if (!b) return;
    const btn = document.querySelector(`.btn-rad[data-rad-id="${b.id}"]`)
        || document.querySelector(`.btn-rad-crear[data-contrato-id="${sannContratoId}"][data-tipo="eps"]`);
    if (!btn) return;
    // Un contrato sin radicado en BD traía el botón de "crear": ya existe, pasa a ser el normal.
    btn.classList.remove('btn-rad-crear');
    btn.classList.add('btn-rad');
    btn.className = btn.className.replace(/\bbadge-(?!estado\b)[\w-]+/g, '').trim() + ' badge-' + b.clase;
    btn.dataset.radId = b.id;
    btn.dataset.contratoId = sannContratoId;
    btn.dataset.rad = JSON.stringify(b.rad);
    btn.textContent = b.texto;
    if (b.titulo) btn.title = b.titulo; else btn.removeAttribute('title');
}

function sannTerminar(html) {
    clearInterval(sannReloj);
    sannEl('sannContenido').style.display = 'none';
    sannEl('sannResultado').style.display = 'block';
    sannEl('sannResultado').innerHTML = html;
    if (typeof mostrarToast === 'function') mostrarToast('Radicado de Sanitas actualizado.', 'success');
}
</script>
