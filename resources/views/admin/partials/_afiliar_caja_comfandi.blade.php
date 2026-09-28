{{--
    Modal de afiliación a la caja Comfandi, autocontenido.

    Lo abre `abrirCajaComfandi(contratoId)` desde el radicado de caja. La persona
    entra a la Sucursal Virtual Empresas con el NIT de la empresa (y omite el
    segundo factor); la extensión BryNex Portales consulta al trabajador y llena
    el formulario de Afiliación individual, que es uno solo. Ella revisa y pulsa
    Finalizar; el modal lee el número de radicado y lo registra.
--}}
<style>
.cfd-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.cfd-bg.open { display:flex }
.cfd-box { background:#fff;border-radius:14px;max-width:580px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.cfd-head { background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.cfd-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.cfd-x { background:none;border:none;color:#dbeafe;font-size:1.15rem;cursor:pointer;line-height:1 }
.cfd-body { padding:1.1rem }
.cfd-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.cfd-resumen span { color:#64748b }
.cfd-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.cfd-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.cfd-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.cfd-info { background:#eff6ff;border:1px solid #93c5fd;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#1e3a8a;line-height:1.55 }
.cfd-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.cfd-btn { width:100%;margin-top:.5rem;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.cfd-btn.sec { background:#fff;color:#1d4ed8;border:1px solid #93c5fd }
.cfd-btn.rojo { background:#fff;color:#b91c1c;border:1px solid #fecaca }
.cfd-btn:disabled { opacity:.5;cursor:not-allowed }
.cfd-campo { display:block;font-size:.72rem;color:#475569;font-weight:600;margin:.45rem 0 .15rem }
.cfd-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.35rem .5rem;font-size:.8rem;font-family:inherit }
.cfd-fila { display:grid;grid-template-columns:1fr 1fr;gap:.5rem }
.cfd-texto { font-size:.7rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:.45rem .55rem;max-height:130px;overflow-y:auto;margin:.4rem 0;line-height:1.45 }
</style>

<div class="cfd-bg" id="cfdModal">
  <div class="cfd-box">
    <div class="cfd-head">
      <h3>🏢 Afiliación a la caja Comfandi</h3>
      <button class="cfd-x" onclick="cerrarCajaComfandi()">✕</button>
    </div>
    <div class="cfd-body">
      <div id="cfdCargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Revisando los datos del contrato...</div>
      <div id="cfdContenido" style="display:none">
        <div class="cfd-resumen" id="cfdResumen"></div>
        <div class="cfd-prob" id="cfdProblemas" style="display:none"><strong>No se puede afiliar todavía:</strong><ul id="cfdProblemasLista"></ul></div>
        <div class="cfd-aviso" id="cfdAvisos" style="display:none"></div>

        <div id="cfdDatos">
          <div class="cfd-fila">
            <div><label class="cfd-campo" for="cfdGenero">Género</label><select id="cfdGenero" class="cfd-input"></select></div>
            <div><label class="cfd-campo" for="cfdEstadoCivil">Estado civil</label><select id="cfdEstadoCivil" class="cfd-input"></select></div>
          </div>
          <div class="cfd-fila">
            <div><label class="cfd-campo" for="cfdContrato">Tipo de contrato</label><select id="cfdContrato" class="cfd-input"></select></div>
            <div><label class="cfd-campo" for="cfdSalario">Tipo de salario</label><select id="cfdSalario" class="cfd-input"></select></div>
          </div>
          <div class="cfd-fila">
            <div><label class="cfd-campo" for="cfdHoras">Horas diarias</label><select id="cfdHoras" class="cfd-input"></select></div>
            <div><label class="cfd-campo" for="cfdNivel">Nivel académico</label><select id="cfdNivel" class="cfd-input"></select></div>
          </div>
          <label class="cfd-campo" for="cfdOcupacion">Ocupación (se busca en la lista CIUO del portal)</label>
          <input id="cfdOcupacion" class="cfd-input">
          <label class="cfd-campo" for="cfdDireccion">Dirección de residencia (sin # ni -, y terminada en SECTOR URBANO o SECTOR RURAL)</label>
          <input id="cfdDireccion" class="cfd-input" placeholder="Ej: CALLE 43 37 31 SECTOR URBANO">
          <div class="cfd-info" id="cfdSueldoAviso" style="margin-top:.5rem"></div>
          <div class="cfd-aviso" style="margin-top:.6rem">
            Orientación sexual, pertenencia étnica y factor de vulnerabilidad van con los valores neutros
            del portal («información no disponible», «no se autoreconoce», «no aplica»), porque BryNex no
            los guarda.
          </div>
        </div>

        <div class="cfd-info" id="cfdSesion"></div>
        <button class="cfd-btn sec" id="cfdBtnAbrir" style="display:none" onclick="abrirPortalComfandi()">🌐 Abrir el portal para iniciar sesión</button>
        <button class="cfd-btn" id="cfdBtnIniciar" style="display:none" onclick="iniciarCajaComfandi()">🔎 Consultar al trabajador y llenar</button>
        <div id="cfdPasos" style="display:none"></div>

        <div id="cfdRadicado" style="display:none">
          <div class="cfd-info" id="cfdRadicadoInfo"></div>
          <div class="cfd-texto" id="cfdRadicadoTexto" style="display:none"></div>
          <label class="cfd-campo" for="cfdNumero">Número de radicado que dio el portal</label>
          <input id="cfdNumero" class="cfd-input" placeholder="Ej: 002-002-00436282">
          <button class="cfd-btn" id="cfdBtnGuardar" onclick="guardarCajaComfandi()">💾 Registrar en BryNex</button>
          <button class="cfd-btn rojo" onclick="rechazoCajaComfandi()">⛔ El portal no la radicó</button>
        </div>
      </div>
      <div id="cfdResultado" class="cfd-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let cfdContratoId = null, cfdPrep = {}, cfdReloj = null, cfdFinal = null, cfdAvisoPortal = '';
const CFD_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const cfdEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const cfdEl = id => document.getElementById(id);
const cfdFmt = iso => iso ? iso.split('-').reverse().join('/') : '—';

function cerrarCajaComfandi() { cfdEl('cfdModal').classList.remove('open'); clearInterval(cfdReloj); }

async function cfdPedir(ruta, metodo = 'GET', cuerpo = null) {
    const r = await fetch(`/admin/afiliaciones/${cfdContratoId}/caja-comfandi/${ruta}`, {
        method: metodo,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFD_CSRF },
        body: cuerpo ? JSON.stringify(cuerpo) : null,
    });
    return await r.json();
}

function cfdExt(accion, datos = {}, limiteSeg = 90) {
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
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'cfd', accion, datos }, window.location.origin);
    });
}

function cfdOpciones(sel, lista, porDefecto) {
    sel.innerHTML = Object.entries(lista || {}).map(([v, t]) => `<option value="${v}"${String(v) === String(porDefecto) ? ' selected' : ''}>${cfdEsc(t)}</option>`).join('');
}

async function abrirCajaComfandi(contratoId) {
    cfdContratoId = contratoId; cfdFinal = null; clearInterval(cfdReloj);
    ['cfdContenido', 'cfdResultado', 'cfdPasos', 'cfdRadicado', 'cfdBtnAbrir', 'cfdBtnIniciar', 'cfdAvisos'].forEach(id => cfdEl(id).style.display = 'none');
    cfdEl('cfdCargando').style.display = 'block';
    cfdEl('cfdModal').classList.add('open');

    try { cfdPrep = await cfdPedir('precheck'); } catch (e) { cfdPrep = { problemas: ['No se pudo revisar el contrato.'] }; }
    cfdEl('cfdCargando').style.display = 'none';
    cfdEl('cfdContenido').style.display = 'block';

    const r = cfdPrep.resumen || {};
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${cfdEsc(v)}</strong></div>` : '';
    cfdEl('cfdResumen').innerHTML =
        li('Trabajador', `${r.trabajador ?? ''} — ${r.documento ?? ''}`) +
        li('Empresa', r.razon_social ? `${r.razon_social} (NIT ${r.nit})` : null) +
        li('Caja', r.caja) + li('Ingreso', cfdFmt(r.fecha_ingreso)) +
        li('Salario', r.salario ? '$' + Number(r.salario).toLocaleString('es-CO') : null) +
        li('Residencia', r.residencia) + li('Celular', r.celular) + li('Correo', r.correo) +
        li('Usuario del portal', r.usuario_portal) +
        li('Radicado de caja', r.estado_radicado ? `${r.estado_radicado}${r.numero_radicado ? ' · ' + r.numero_radicado : ''}` : null);

    const problemas = cfdPrep.problemas || [];
    cfdEl('cfdProblemas').style.display = problemas.length ? 'block' : 'none';
    cfdEl('cfdProblemasLista').innerHTML = problemas.map(p => `<li>${cfdEsc(p)}</li>`).join('');
    const avisos = cfdPrep.avisos || [];
    cfdEl('cfdAvisos').style.display = avisos.length ? 'block' : 'none';
    cfdEl('cfdAvisos').innerHTML = avisos.map(cfdEsc).join('<br>');

    cfdOpciones(cfdEl('cfdGenero'), cfdPrep.listas?.generos, cfdPrep.portal?.genero || '1');
    cfdOpciones(cfdEl('cfdEstadoCivil'), cfdPrep.listas?.estados_civil, '1');
    cfdOpciones(cfdEl('cfdContrato'), cfdPrep.listas?.contratos, '02');
    cfdOpciones(cfdEl('cfdSalario'), cfdPrep.listas?.salarios, '02');
    cfdOpciones(cfdEl('cfdHoras'), cfdPrep.listas?.horas, '8');
    cfdOpciones(cfdEl('cfdNivel'), cfdPrep.listas?.niveles, '10');
    cfdEl('cfdOcupacion').value = cfdPrep.portal?.ocupacionTexto || '';
    cfdEl('cfdDireccion').value = cfdPrep.portal?.direccion || cfdPrep.resumen?.direccion || '';
    cfdEl('cfdHoras').onchange = cfdAvisarSueldo;
    cfdAvisarSueldo();

    if (problemas.length) { cfdEl('cfdDatos').style.display = 'none'; cfdEl('cfdSesion').style.display = 'none'; return; }
    cfdEl('cfdSesion').style.display = 'block';
    await revisarSesionComfandi();
    cfdReloj = setInterval(() => { if (cfdEl('cfdPasos').style.display !== 'block') revisarSesionComfandi(); }, 3000);
}

async function revisarSesionComfandi() {
    const e = await cfdExt('cfdEstado', {}, 20);
    const caja = cfdEl('cfdSesion');
    cfdEl('cfdBtnAbrir').style.display = 'none';
    cfdEl('cfdBtnIniciar').style.display = 'none';
    if (e.sinExtension) { caja.innerHTML = '🧩 Falta la extensión <strong>BryNex Portales</strong>. Se descarga desde Afiliaciones → 🩺 Conciliar EPS → botón 🧩 Extensión; después recarga esta página.'; return; }
    if (!e.abierta || !e.sesion) {
        caja.innerHTML = `1️⃣ Abre la <strong>Sucursal Virtual Empresas</strong> e inicia sesión con el NIT de <strong>${cfdEsc(cfdPrep.portal.empresa)}</strong>` +
            (cfdPrep.resumen?.usuario_portal ? ` (<strong>${cfdEsc(cfdPrep.resumen.usuario_portal)}</strong>)` : '') +
            '. BryNex deja escrito el usuario; tú pones la clave. Si te ofrece la verificación en dos pasos, pulsa <strong>Omitir por ahora</strong>, y después selecciona la empresa.';
        caja.innerHTML += cfdAvisoPortal;
        cfdEl('cfdBtnAbrir').style.display = 'block';
        return;
    }
    cfdAvisoPortal = '';
    caja.innerHTML = `✅ Portal abierto${e.empresa ? ' con <strong>' + cfdEsc(e.empresa) + '</strong>' : ''}.`;
    cfdEl('cfdBtnIniciar').style.display = 'block';
}

async function abrirPortalComfandi() {
    const caja = cfdEl('cfdSesion');
    const btn = cfdEl('cfdBtnAbrir');
    const antes = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Entrando al portal…';

    let cred = {};
    try { cred = await cfdPedir('credencial', 'POST'); } catch (e) {}

    if (!cred.contrasena) {
        caja.innerHTML += '<br>🔑 En BryNex no hay clave de Comfandi para esta empresa: se abre el portal y la escribes tú.';
    }

    const r = await cfdExt('cfdAbrir', {
        usuario: cred.usuario || '',
        contrasena: cred.contrasena || '',
        // El portal lista las empresas por nombre, sin el NIT: sin esto la
        // extensión no sabe cuál pulsar y se queda en «selecciona tu empresa».
        empresa: cfdPrep.portal?.empresa || '',
    }, 120);

    btn.disabled = false;
    btn.textContent = antes;

    // El tipo de documento del login es un combo propio y a veces se queda en
    // CC: con el NIT en el campo, el portal responde "Documento o contraseña
    // incorrectos" aunque la clave esté bien. Mejor avisarlo que dejarlo
    // descubrir a punta de intentos.
    // El aviso se guarda, no se escribe encima: el reloj repinta este bloque
    // cada 3 segundos y antes se comía el motivo del fallo al instante.
    cfdAvisoPortal = '';
    if (r?.avisoTipo) cfdAvisoPortal += `<br>⚠️ ${cfdEsc(r.avisoTipo)}`;
    if (r && r.ok === false && r.error) cfdAvisoPortal += `<br>❌ ${cfdEsc(r.error)}`;
    if (!r) cfdAvisoPortal += '<br>❌ El portal no respondió a tiempo. Mira la pestaña de Comfandi para ver en qué pantalla quedó.';

    revisarSesionComfandi();
}

// Comfandi exige que el sueldo declarado sea proporcional a la jornada: 240
// horas al mes es la completa. Con 4 horas (120 al mes) sobre el mínimo da
// 875.453, el mismo salario que BryNex usa en un Tiempo Parcial (14).
function cfdSueldoPorHoras(salario, horas) {
    const h = Math.max(1, Math.min(8, parseInt(horas, 10) || 8));
    return Math.round(Number(salario || 0) * (h * 30) / 240);
}

function cfdDatosPortal() {
    const horas = cfdEl('cfdHoras').value;
    return Object.assign({}, cfdPrep.portal, {
        genero: cfdEl('cfdGenero').value,
        estadoCivil: cfdEl('cfdEstadoCivil').value,
        tipoContrato: cfdEl('cfdContrato').value,
        tipoSalario: cfdEl('cfdSalario').value,
        horas: horas,
        nivel: cfdEl('cfdNivel').value,
        ocupacionTexto: cfdEl('cfdOcupacion').value.trim(),
        direccion: cfdEl('cfdDireccion').value.trim(),
        salario: cfdSueldoPorHoras(cfdPrep.portal?.salario, horas),
    });
}

function cfdAvisarSueldo() {
    const s = cfdSueldoPorHoras(cfdPrep.portal?.salario, cfdEl('cfdHoras').value);
    cfdEl('cfdSueldoAviso').textContent = 'Sueldo declarado al portal: $' + s.toLocaleString('es-CO')
        + ' (Comfandi exige que sea proporcional a la jornada; el IBC de la planilla va aparte).';
}

async function iniciarCajaComfandi() {
    const btn = cfdEl('cfdBtnIniciar');
    btn.disabled = true; btn.textContent = '⏳ Consultando al trabajador...';
    const r = await cfdExt('cfdConsultar', cfdDatosPortal(), 120);
    btn.disabled = false; btn.textContent = '🔎 Consultar al trabajador y llenar';
    // El aviso va dentro del modal, no en un alert(): el alert congela la página
    // entera —y con ella el resto del trámite— hasta que alguien le da Aceptar.
    if (!r.ok) {
        const caja = cfdEl('cfdPasos');
        caja.style.display = 'block';
        caja.innerHTML = `<div class="cfd-aviso">❌ ${cfdEsc(r.error || 'No se pudo consultar al trabajador.')}</div>`;
        return;
    }

    const caja = cfdEl('cfdPasos');
    caja.style.display = 'block';
    btn.style.display = 'none';
    caja.innerHTML = (r.yaAfiliado
            ? `<div class="cfd-aviso">⚠️ <strong>${cfdEsc(r.nombre || 'El trabajador')}</strong> ya aparece en el listado de trabajadores de la empresa` +
              (r.desde ? ` desde el <strong>${cfdEsc(r.desde)}</strong>` : '') + '. No hay que afiliarlo: cierra esto y marca el radicado en OK.</div>'
            : `<div class="cfd-info">👤 ${cfdEsc(r.nombre || '')}${r.precargado ? ' (nombre y fecha de nacimiento los trajo la Registraduría)' : ''}</div>`) +
        '<div class="cfd-aviso">👉 BryNex ya llenó el formulario. <strong>Revísalo de arriba a abajo</strong> y pulsa <strong>Finalizar</strong>. ' +
        'La fecha de ingreso la escoge BryNex en el calendario; si quedó vacía, ábrelo y selecciónala tú.<br>' +
        'Después de Finalizar sale una ventana que te pide <strong>verificar el sueldo</strong>: revísalo y pulsa Confirmar. ' +
        'Si el portal se queja de algo, el mensaje sale <strong>arriba en el formulario</strong>, no donde estás mirando — súbelo antes de volver a intentar.</div>' +
        '<div id="cfdPasoActual" class="cfd-texto">Esperando el formulario…</div>';

    clearInterval(cfdReloj);
    const desde = Date.now();
    cfdReloj = setInterval(async () => {
        if (Date.now() - desde > 45 * 60 * 1000) { clearInterval(cfdReloj); return; }
        // El documento va porque, si el portal no enseña el número, hay que ir a
        // buscarlo a Radicados por la cédula.
        const fin = await cfdExt('cfdResultado', { documento: cfdPrep.portal?.documento || '' }, 40);
        if (fin.ok && fin.radicado) { clearInterval(cfdReloj); mostrarRadicadoComfandi(fin); return; }
        const p = await cfdExt('cfdLlenar', cfdDatosPortal(), 40);
        if (!p.ok) return;
        cfdEl('cfdPasoActual').innerHTML = `<strong>${cfdEsc(p.paso || 'Afiliación individual')}</strong>` +
            (p.hecho?.length ? '<br>✅ ' + p.hecho.map(cfdEsc).join('<br>✅ ') : '') +
            (p.falta?.length ? '<br>⚠️ ' + p.falta.map(cfdEsc).join('<br>⚠️ ') : '') +
            (p.errores?.length ? '<br>❗ ' + p.errores.map(cfdEsc).join('<br>❗ ') : '');
    }, 4000);
}

function mostrarRadicadoComfandi(fin) {
    cfdFinal = fin;
    cfdEl('cfdRadicado').style.display = 'block';
    cfdEl('cfdNumero').value = fin.numero || '';

    // Cuando el portal dijo que no pudo radicar y sí lo hizo, conviene decirlo:
    // el número no salió de la pantalla de éxito sino de la pestaña Radicados.
    if (fin.recuperado) {
        const caja = cfdEl('cfdPasos');
        if (caja) {
            caja.style.display = 'block';
            caja.innerHTML += '<div class="cfd-aviso">🔎 El portal ' +
                (fin.recuperado === 'ya-existe'
                    ? 'avisó de que esta cédula ya tenía un radicado en proceso'
                    : 'dijo que no pudo generar el radicado') +
                ', pero la afiliación sí quedó: el número se tomó de la pestaña <strong>Radicados</strong>' +
                (fin.estadoPortal ? ' (estado: <strong>' + cfdEsc(fin.estadoPortal) + '</strong>)' : '') + '.</div>';
        }
    }
    cfdEl('cfdRadicadoInfo').innerHTML = fin.numero
        ? `📨 El portal radicó la afiliación con el N° <strong>${cfdEsc(fin.numero)}</strong>.`
        : '📨 El portal respondió, pero no se encontró el número: cópialo de la pantalla o de la pestaña Radicados.';
    cfdEl('cfdRadicadoTexto').style.display = fin.texto ? 'block' : 'none';
    cfdEl('cfdRadicadoTexto').textContent = (fin.texto || '').slice(0, 1200);
}

async function guardarCajaComfandi() {
    const numero = cfdEl('cfdNumero').value.trim();
    if (!numero) { alert('Escribe el número de radicado que dio el portal.'); return; }
    const btn = cfdEl('cfdBtnGuardar');
    btn.disabled = true; btn.textContent = '⏳ Registrando...';
    const r = await cfdPedir('aplicar', 'POST', { numero, texto: cfdFinal?.texto || '' });
    btn.disabled = false; btn.textContent = '💾 Registrar en BryNex';
    if (!r.ok) { alert(r.error || r.mensaje || 'No se pudo registrar.'); return; }
    cfdTerminar(`✅ ${cfdEsc(r.mensaje)}`);
}

async function rechazoCajaComfandi() {
    const motivo = prompt('¿Qué dijo el portal? (queda en el radicado como error)', cfdFinal?.texto || '');
    if (motivo === null) return;
    const r = await cfdPedir('aplicar', 'POST', { error: motivo || 'El portal no radicó la afiliación.', texto: cfdFinal?.texto || '' });
    cfdTerminar(`⛔ ${cfdEsc(r.mensaje || r.error || '')}`);
}

function cfdTerminar(html) {
    clearInterval(cfdReloj);
    cfdEl('cfdContenido').style.display = 'none';
    cfdEl('cfdResultado').style.display = 'block';
    cfdEl('cfdResultado').innerHTML = html + '<br><span style="color:#475569">El radicado queda visible en la pestaña Radicados del portal hasta que Comfandi lo procese.</span>';
    if (typeof mostrarToast === 'function') mostrarToast('Radicado de caja actualizado. Recarga para verlo.', 'success');
}
</script>
