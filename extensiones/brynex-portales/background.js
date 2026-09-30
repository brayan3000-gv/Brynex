/**
 * BryNex Portales — trámites de afiliación en los portales de las EPS.
 *
 * Por qué una extensión: el login de S.O.S. pide reCAPTCHA y, desde un Chrome
 * automatizado en el servidor, Google no lo deja pasar nunca (sep-2026). Aquí la
 * persona inicia sesión en su propio navegador, como siempre, y BryNex le pide a
 * la extensión que haga el trámite en esa pestaña: consultar, radicar, adjuntar
 * el lado B y bajar el certificado. La extensión nunca ve ni guarda claves.
 *
 * Solo acepta pedidos del puente que corre dentro de BryNex (ver puente.js).
 *
 * Pedidos S.O.S. (portal: 'sos'):
 *  estado                          → {abierta, sesion, usuario, empresa}
 *  abrir                           → abre (o enfoca) la pestaña del login
 *  consultar  {tipo, documento, desde, hasta}                 (DD/MM/AAAA)
 *  consultas  {tipo, documentos:[{tipo,numero}], desde, hasta} → {resultados, fallos} (conciliación)
 *  certificado {tipo, documento, desde, hasta}                → {pdf: base64}
 *  registrar  {tipoId, documento, ibc, fecha, arl, afp, guardar}
 *  adjuntar   {tipo, documento, desde, hasta, archivo}        (URL de BryNex)
 *
 * Pedidos Sanitas (portal: 'sanitas'):
 *  estado / abrir / estadoAfiliacion   Oficina Virtual de Empleadores (conciliación)
 *  novedadEstado                       → {abierta, listo, error} del formulario web de novedades
 *  novedadAbrir                        → abre (o enfoca) el formulario web de novedades
 *  novedadLlenar {tipoDoc, documento, departamento, municipio, municipioDane, telefonoFijo,
 *                 celular, correo, tipoNovedad, observaciones, archivo, nombreArchivo}
 *                                      → llena y adjunta; el clic en Enviar lo da la persona
 *  novedadResultado {documento}        → {enviado, radicado, texto, errores, captura}
 *
 * Pedidos Boxalud (portal: 'boxalud', Emssanar; datos.host dice cuál):
 *  boxEstado {host}                    → {abierta, sesion, empresa}
 *  boxAbrir {host, usuario, contrasena} → abre el login y deja escrito el usuario (y la clave si llegó)
 *  boxLlenar {…datos del contrato}     → Ingreso de afiliación lleno y documentos adjuntos; ACEPTAR y GUARDAR los pulsa la persona
 *  boxResultado {host, documento}      → {guardado, numero, texto, mensajes}
 *
 * Pedidos caja Comfenalco Valle (portal: 'ccfcv', Sucursal Virtual Afiliación):
 *  ccfEstado                           → {abierta, sesion, empresa, pagina}
 *  ccfAbrir {usuario, contrasena}      → abre el login y deja escrito el usuario
 *  ccfConsultar {tipoDoc, documento}   → busca al trabajador y devuelve las opciones del portal
 *  ccfPaso {…datos, opciones}          → llena el paso que esté a la vista (Personal, Laboral, Beneficiarios…)
 *  ccfResultado                        → {radicado, numero, texto} tras Finalizar Afiliación
 *  ccfDeclaracion                      → {base64} PDF oficial de la declaración juramentada (formato del portal, sin descargarlo)
 *  ccfSubirDeclaracion {base64}        → adjunta el PDF firmado en cada "Formato declaración juramentada caja" pendiente
 *  ccfDocumentos                       → {docs:[{requerido, nombre, doc_beneficiario, mime, base64}]} anexos que la caja ya tiene (paso Anexos)
 *  ccfTrabajadores                     → {nit, empresa, filas} de "Trabajadores por Empresa" (conciliación)
 *  ccfGrupoFamiliar {documentos}       → {familias} beneficiarios de cada trabajador, uno por consulta
 *
 * Pedidos caja Comfandi (portal: 'cfd', Sucursal Virtual Empresas):
 *  cfdEstado                           → {abierta, sesion, empresa, pagina}
 *  cfdAbrir {usuario, contrasena, empresa, reiniciar} → entra a la Sucursal Virtual: clave, 2FA omitido y empresa
 *                                      (con reiniciar, cierra la sesión antes: el portal se degrada)
 *  cfdConsultar {…datos}               → abre Afiliación individual y consulta al trabajador
 *  cfdLlenar {…datos}                  → llena el formulario; Finalizar lo pulsa la persona
 *  cfdFinalizar                        → pulsa Finalizar y confirma el sueldo (radica)
 *  cfdResultado                        → {radicado, numero, texto} tras Finalizar
 *  cfdTrabajadores                     → {nit, empresa, filas, radicados} para la conciliación
 *  cfdListado                          → {nit, empresa, archivoUrl} del Excel del portal (listado + beneficiarios)
 *  cfdEmpresa                          → {nit, empresa} de la empresa abierta en el portal
 *  cfdSubsidios {documentos, meses}    → {movimientos, revisados} de bloqueos de subsidio monetario
 *
 * Pedidos Fondo de Solidaridad Pensional (portal: 'fsp', certificado del PSAP en Equiedad):
 *  fspAbrir {tipoDoc, documento}       → abre la consulta del certificado con tipo y número escritos
 *  fspCertificado {documento}          → {listo: false} mientras no marquen el captcha;
 *                                        {listo: true, pdf, nombre} o {listo: true, mensaje} después
 */

// Módulo Renta (BryNex Renta ↔ MUISCA): archivo y puente propios, ver renta.js.
importScripts('renta.js');

const ORIGENES_BRYNEX = ['https://brynex.co', 'https://www.brynex.co', 'http://localhost:8000'];

const SOS_ORIGEN = 'https://centralaplicaciones.sos.com.co';
const SOS_BASE = `${SOS_ORIGEN}/PortalTransaccionalWeb/paginas/empleadores`;
const SOS_LOGIN = `${SOS_BASE}/Logueo/loginEmpleadores.jsf`;
const SOS_MENU = `${SOS_BASE}/view/micuenta/mimenuempleadores.jsf`;

const esperar = (ms) => new Promise(r => setTimeout(r, ms));

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const origen = sender.origin || (sender.url ? new URL(sender.url).origin : '');
  if (msg?.canal !== 'brynex-portales' || !ORIGENES_BRYNEX.includes(origen) || sender.id !== chrome.runtime.id) return;

  // Ver el estado o abrir la pestaña no espera a que termine un trámite en curso.
  const directo = ['recargar', 'estado', 'abrir', 'novedadEstado', 'novedadAbrir', 'novedadResultado', 'boxEstado', 'boxAbrir', 'boxResultado', 'ccfEstado', 'ccfAbrir', 'ccfPaso', 'ccfResultado', 'ccfDocumentos', 'ccfDeclaracion', 'ccfSubirDeclaracion', 'cfdEstado', 'cfdAbrir', 'cfdLlenar', 'cfdResultado', 'fspAbrir', 'fspCertificado'].includes(msg.accion);
  (directo ? atender(msg, origen) : enCola(() => atender(msg, origen)))
    .then(sendResponse)
    .catch(e => sendResponse({ ok: false, error: String(e?.message || e).slice(0, 400) }));
  return true;
});

// Un trámite a la vez: todos usan la misma pestaña del portal.
let cola = Promise.resolve();
const enCola = (fn) => (cola = cola.then(fn, fn));

async function atender({ portal, accion, datos = {} }, origen) {
  // Recargarse a sí misma. Al cambiar el código hay que pulsar "recargar" en
  // chrome://extensions, y esa página no se puede automatizar desde ninguna
  // parte: con esto basta el botón de BryNex. El reload mata el service worker,
  // así que se contesta primero y se recarga un instante después.
  if (portal === 'sys' && accion === 'recargar') {
    setTimeout(() => chrome.runtime.reload(), 300);
    return { ok: true, version: chrome.runtime.getManifest().version };
  }

  if (portal === 'sanitas') return atenderSanitas(accion, datos, origen);
  if (portal === 'boxalud') return atenderBoxalud(accion, datos, origen);
  if (portal === 'ccfcv') return atenderCcfcv(accion, datos);
  if (portal === 'cfd') return atenderCfd(accion, datos);
  if (portal === 'fsp') return atenderFsp(accion, datos);
  if (portal !== 'sos') throw new Error(`Portal desconocido: ${portal}`);

  if (accion === 'estado') return sosEstado();
  if (accion === 'abrir') return sosAbrir(datos);

  const pestana = await pestanaSos();
  if (!pestana) throw new Error('No hay una pestaña de S.O.S. abierta. Pulsa "Abrir S.O.S." e inicia sesión.');
  const estado = await leerCabecera(pestana.id);
  if (!estado.sesion) throw new Error('La pestaña de S.O.S. no tiene la sesión iniciada. Inicia sesión en ella y vuelve a intentar.');

  switch (accion) {
    case 'consultar':   return { ok: true, empresa: estado.empresa, filas: await sosConsultar(pestana.id, datos) };
    case 'consultas':   return { empresa: estado.empresa, ...(await sosConsultas(pestana.id, datos)) };
    case 'certificado': return sosCertificado(pestana.id, datos);
    case 'registrar':   return sosRegistrar(pestana.id, datos);
    case 'adjuntar':    return sosAdjuntar(pestana.id, datos, origen);
    default: throw new Error(`Acción desconocida: ${accion}`);
  }
}

// ── Pestaña y ejecución en la página ─────────────────────────────────────

async function pestanaSos() {
  const pestanas = await chrome.tabs.query({ url: `${SOS_ORIGEN}/*` });
  return pestanas[0] || null;
}

/** Ejecuta `func` en la página (mundo principal, con sus variables JSF). */
async function ejecutar(tabId, func, args = []) {
  const [r] = await chrome.scripting.executeScript({ target: { tabId }, func, args, world: 'MAIN' });
  if (r?.result?.__error) throw new Error(r.result.__error);
  return r?.result;
}

/** Espera a que la página termine de cargar. */
function esperarCarga(tabId, timeout = 45000) {
  return new Promise((resolve) => {
    const fin = () => { chrome.tabs.onUpdated.removeListener(oyente); clearTimeout(reloj); resolve(); };
    const oyente = (id, info) => { if (id === tabId && info.status === 'complete') fin(); };
    chrome.tabs.onUpdated.addListener(oyente);
    const reloj = setTimeout(fin, timeout);
  });
}

/** Clic que puede enviar el formulario (navegación) o hacer AJAX (A4J). */
async function clicYEsperar(tabId, func, args = []) {
  let navego = false;
  const inicio = new Promise((resolve) => {
    const oyente = (id, info) => {
      if (id === tabId && info.status === 'loading') { navego = true; chrome.tabs.onUpdated.removeListener(oyente); resolve(); }
    };
    chrome.tabs.onUpdated.addListener(oyente);
    setTimeout(() => { chrome.tabs.onUpdated.removeListener(oyente); resolve(); }, 2500);
  });
  let resultado;
  try {
    resultado = await ejecutar(tabId, func, args);
  } catch (e) {
    // La navegación puede destruir el marco antes de que el script responda.
    if (!/Frame|context|removed|navigat/i.test(String(e?.message))) throw e;
  }
  await inicio;
  if (navego) await esperarCarga(tabId);
  else await esperar(1200);
  return resultado;
}

/** Repite `func` en la página hasta que devuelva algo verdadero. */
async function esperarQue(tabId, func, args = [], timeout = 30000) {
  const limite = Date.now() + timeout;
  while (Date.now() < limite) {
    try {
      const r = await ejecutar(tabId, func, args);
      if (r) return r;
    } catch { /* la página está cambiando */ }
    await esperar(500);
  }
  return null;
}

// ── Funciones que corren dentro de la página de S.O.S. ────────────────────
// Deben ser autocontenidas: se serializan y se inyectan.

function pClicTexto(patron, dentroDeModal) {
  const re = new RegExp(patron, 'i');
  const visible = e => !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length);
  const raiz = dentroDeModal
    ? [...document.querySelectorAll('.rich-mpnl-body')].filter(visible).pop() || document
    : document;
  const a = [...raiz.querySelectorAll('a,input[type=button],input[type=submit],button')]
    .filter(visible).find(e => re.test((e.value || e.textContent || '').trim()));
  if (!a) return { __error: `No se encontró "${patron}" en el portal de S.O.S.` };
  a.click();
  return true;
}

function pCabecera() {
  const texto = document.body?.innerText || '';
  const m = texto.match(/Bienvenido:\s*([^\n]+)\s*\n+\s*([^\n]+)\s*\n+\s*Cerrar sesi/i);
  const caida = [...document.querySelectorAll('[id*=messageInvalidSession]')].some(e => e.offsetHeight > 0);
  return {
    sesion: !!m && !caida && !/loginEmpleadores/.test(location.pathname),
    usuario: m ? m[1].trim() : null,
    empresa: m ? m[2].trim() : null,
    pagina: location.pathname.split('/').pop(),
  };
}

function pTextoModales() {
  return [...new Set([...document.querySelectorAll('.rich-mpnl-body')]
    .filter(e => e.offsetHeight > 0).map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean))].join(' ');
}

function pFilas(doc) {
  return [...document.querySelectorAll('tr')]
    .filter(r => r.cells.length >= 7 && r.cells.length < 12 && /^\s*\d{6,}\s*$/.test(r.cells[0].innerText)
      && (!doc || r.cells[2].innerText.trim() === String(doc)))
    .map(r => ({
      radicado: r.cells[0].innerText.trim(),
      tipo: r.cells[1].innerText.trim(),
      documento: r.cells[2].innerText.trim(),
      nombre: r.cells[3].innerText.trim(),
      fecha_radicacion: r.cells[4].innerText.trim(),
      // La columna "Estado" es un ícono (✓ / reloj / X) y la "Causal" trae el texto
      // del estado (Aprobado, No aprobado…). El motivo de una devolución no está en
      // la tabla: sale en la ventana que abre la X (ver pMotivoDevolucion).
      icono: [...r.cells[5].querySelectorAll('img')].map(i => i.title || i.alt || i.src.split('/').pop()).join(' '),
      estado: r.cells[6].innerText.trim(),
      causal: '',
      accion: (r.querySelector('a[onclick*="jsfcljs"]')?.getAttribute('onclick')?.match(/\{'([^']+)'/) || [])[1] || null,
    }));
}

// ── S.O.S. ────────────────────────────────────────────────────────────────

async function leerCabecera(tabId) {
  try {
    return await ejecutar(tabId, pCabecera);
  } catch {
    return { sesion: false };
  }
}

async function sosEstado() {
  const pestana = await pestanaSos();
  if (!pestana) return { ok: true, abierta: false, sesion: false };
  return { ok: true, abierta: true, ...(await leerCabecera(pestana.id)) };
}

async function sosAbrir({ usuario, contrasena } = {}) {
  let pestana = await pestanaSos();
  if (pestana) {
    await chrome.tabs.update(pestana.id, { active: true });
    await chrome.windows.update(pestana.windowId, { focused: true });
  } else {
    pestana = await chrome.tabs.create({ url: SOS_LOGIN, active: true });
    await esperarCarga(pestana.id);
  }

  // Deja escritos usuario y clave del módulo de claves (la clave solo si BryNex la
  // mandó: depende del permiso de la persona). No se guardan; el captcha y el
  // botón Ingresar los hace la persona.
  if (usuario) {
    await esperarQue(pestana.id, (u, c) => {
      const campo = document.getElementById('formLogin:loginUsrEMail');
      if (!campo) return false;
      const poner = (e, v) => {
        e.value = v;
        e.dispatchEvent(new Event('input', { bubbles: true }));
        e.dispatchEvent(new Event('change', { bubbles: true }));
      };
      if (!campo.value || /correo/i.test(campo.value)) poner(campo, u);
      const clave = document.getElementById('formLogin:input_contrasena');
      if (clave && c) {
        // S.O.S. lo tiene como input de texto y lo enmascara con JS: se deja tipo password.
        clave.type = 'password';
        poner(clave, c);
      } else {
        clave?.focus();
      }
      return true;
    }, [String(usuario), contrasena ? String(contrasena) : ''], 15000);
  }
  return { ok: true, abierta: true };
}

async function irA(tabId, submenu) {
  const destino = { consultas: 'consultaenviofirma.jsf', inicio: 'iniciorelacionlaboral.jsf' }[submenu];
  // Siempre desde el menú: recargar una página JSF que vino de un POST la reenvía.
  await chrome.tabs.update(tabId, { url: SOS_MENU });
  await esperarCarga(tabId);
  const cab = await leerCabecera(tabId);
  if (!cab.sesion) throw new Error('S.O.S. cerró la sesión. Inicia sesión otra vez en la pestaña de S.O.S.');

  await clicYEsperar(tabId, pClicTexto, ['^Novedades$', false]);
  await clicYEsperar(tabId, pClicTexto, [submenu === 'consultas' ? '^Consultas y Env' : '^Inicio relaci', false]);
  const pagina = await ejecutar(tabId, () => location.pathname);
  if (!pagina.includes(destino)) throw new Error(`No se pudo abrir ${destino} en S.O.S.`);
}

async function sosConsultar(tabId, datos) {
  await irA(tabId, 'consultas');
  return sosBuscar(tabId, datos);
}

/**
 * Las novedades de varias cédulas, para la conciliación de radicados.
 *
 * Entra una sola vez a la pantalla y repite la búsqueda: el menú de S.O.S. es
 * JSF y volver a él por cada persona costaba más que la consulta misma. Una
 * cédula que falle no tumba las demás — se devuelve en `fallos`, porque media
 * conciliación es mejor que ninguna y la que falló se vuelve a pedir después.
 */
async function sosConsultas(tabId, { tipo = 'CC', documentos = [], desde, hasta }) {
  await irA(tabId, 'consultas');

  const resultados = {};
  const fallos = {};

  for (const d of documentos) {
    const numero = String(d?.numero ?? d ?? '').replace(/\D/g, '');
    if (!numero || resultados[numero] || fallos[numero]) continue;

    try {
      resultados[numero] = await sosBuscar(tabId, { tipo: d?.tipo || tipo, documento: numero, desde, hasta });
    } catch (e) {
      fallos[numero] = String(e?.message || e).slice(0, 200);
    }
  }

  return { ok: true, consultados: Object.keys(resultados).length, resultados, fallos };
}

/** Busca una cédula en la pantalla de consultas, que ya tiene que estar abierta. */
async function sosBuscar(tabId, { tipo = 'CC', documento, desde, hasta }) {
  await ejecutar(tabId, (tipo, doc, desde, hasta) => {
    const s = document.getElementById('formGeneral:idTipoIdentificacion');
    const op = [...s.options].find(o => o.text.trim() === tipo);
    if (op) s.value = op.value;
    document.getElementById('formGeneral:idNumeroIdentificacion').value = doc;
    document.getElementById('formGeneral:calendarDesdeInputDate').value = desde;
    document.getElementById('formGeneral:calendarHastaInputDate').value = hasta;
    // Marca para saber cuándo A4J volvió a pintar los resultados.
    document.querySelectorAll('table').forEach(t => { if (/Causal/.test(t.textContent)) t.dataset.brynexViejo = '1'; });
    return true;
  }, [tipo, String(documento), desde, hasta]);

  await ejecutar(tabId, pClicTexto, ['^Buscar$', false]);
  await esperarQue(tabId, () => ![...document.querySelectorAll('table')].some(t => t.dataset.brynexViejo === '1'), [], 25000);
  await esperar(500);

  const filas = await ejecutar(tabId, pFilas, [String(documento)]);

  // Para las devueltas se abre la X de cada una y se lee el motivo.
  for (const fila of filas) {
    if (!/no aprobad|incorrect|declinad|devuelt|rechaz/i.test(fila.estado)) continue;
    fila.causal = (await sosMotivoDevolucion(tabId, fila.radicado)) || '';
  }
  return filas;
}

/** Abre la ventana "Motivos de devolución" de la fila y devuelve el texto. */
async function sosMotivoDevolucion(tabId, radicado) {
  const abierto = await ejecutar(tabId, (rad) => {
    const fila = [...document.querySelectorAll('tr')].find(r => r.cells.length >= 7 && r.cells[0].innerText.trim() === rad);
    if (!fila) return false;
    const candidatos = [...fila.cells[5].querySelectorAll('a, img, input, span, div')];
    const clicable = candidatos.find(e => e.getAttribute('onclick') || e.tagName === 'A') || candidatos[0];
    if (!clicable) return false;
    clicable.click();
    return true;
  }, [radicado]).catch(() => false);
  if (!abierto) return null;

  const texto = await esperarQue(tabId, () => {
    const caja = [...document.querySelectorAll('.rich-mpnl-body, .rich-modalpanel, [id*="modalMostrarMotivo"]')]
      .find(e => e.offsetHeight > 0 && /motivos? de devoluci/i.test(e.innerText));
    if (!caja) return null;
    const t = caja.innerText.replace(/\s+/g, ' ')
      .replace(/motivos? de devoluci[oó]n:?/gi, '').replace(/entendido/gi, '').trim();
    return t || null;
  }, [], 12000);

  // Cerrar la ventana para dejar la página como estaba.
  await ejecutar(tabId, () => {
    const b = [...document.querySelectorAll('a, input[type=button], button')]
      .find(e => e.offsetHeight > 0 && /^\s*entendido\s*$/i.test(e.value || e.textContent || ''));
    b?.click();
    return true;
  }).catch(() => {});
  await esperar(600);

  return texto;
}

async function sosCertificado(tabId, filtro) {
  const filas = await sosConsultar(tabId, filtro);
  const fila = filas.find(f => /aprobad/i.test(f.estado) && !/no aprobad/i.test(f.estado)) || filas[0];
  if (!fila?.accion) return { ok: false, error: 'No hay novedad con certificado para ese documento.' };

  const pdf = await ejecutar(tabId, async (accion) => {
    const f = document.forms.formGeneral;
    const fd = new URLSearchParams(new FormData(f));
    fd.set(accion, accion);
    const res = await fetch(f.action, { method: 'POST', body: fd, credentials: 'include' });
    if (!/pdf/.test(res.headers.get('content-type') || '')) return null;
    const buf = new Uint8Array(await res.arrayBuffer());
    let s = '';
    for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode(...buf.subarray(i, i + 0x8000));
    return btoa(s);
  }, [fila.accion]);

  return pdf ? { ok: true, radicado: fila.radicado, estado: fila.estado, pdf } : { ok: false, error: 'S.O.S. no entregó un PDF.' };
}

async function sosRegistrar(tabId, { tipoId, documento, ibc, fecha, arl, afp, guardar }) {
  await irA(tabId, 'inicio');
  const doc = String(documento);

  const precargados = await ejecutar(tabId, () => [...document.querySelectorAll('tr')]
    .filter(r => r.cells.length >= 8 && /^\s*\d+\s*$/.test(r.cells[0].innerText) && /\d{5,}/.test(r.cells[2]?.innerText || ''))
    .map(r => r.cells[2].innerText.trim()));
  if (precargados.length) {
    return { ok: false, error: `Hay registros precargados en S.O.S. sin guardar (${precargados.join(', ')}). Bórralos en el portal antes de seguir.` };
  }

  await clicYEsperar(tabId, pClicTexto, ['^\\s*A[ñn]adir registro', false]);
  const abierto = await esperarQue(tabId, () => {
    const e = document.getElementById('formGeneral:idIbc');
    return !!(e && e.offsetHeight > 0);
  }, [], 20000);
  if (!abierto) return { ok: false, paso: 'abrir', error: 'No se abrió el formulario "Añadir registro" en S.O.S.' };

  await ejecutar(tabId, (d) => {
    const set = (id, v) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); };
    set('formGeneral:idTipoIdentificacion', d.tipoId);
    set('formGeneral:idNumeroIdentificacion', d.documento);
    set('formGeneral:idIbc', d.ibc);
    set('formGeneral:calendarDesdeInputDate', d.fecha);
    set('formGeneral:calendarRetiroInputDate', '');
    set('formGeneral:idArl', d.arl);
    set('formGeneral:idAfp', d.afp);
    return true;
  }, [{ tipoId: String(tipoId), documento: doc, ibc: String(ibc), fecha, arl, afp }]);

  await ejecutar(tabId, pClicTexto, ['^Cargar datos$', true]);

  const filaCargada = (d) => {
    const r = [...document.querySelectorAll('tr')].find(r => r.cells.length >= 8 && r.cells[2]?.innerText.trim() === d);
    return r ? { nombre: r.cells[3].innerText.trim(), iconos: [...r.querySelectorAll('img')].map(i => i.title || i.alt) } : null;
  };
  const avisos = () => {
    const t = [...document.querySelectorAll('*')]
      .filter(e => e.children.length === 0 && e.offsetHeight > 0 && /debe|inv[aá]lid|obligatori|no se|error/i.test(e.textContent))
      .map(e => e.textContent.trim()).filter(x => x.length < 300);
    return t.length ? [...new Set(t)].join(' ') : null;
  };

  const cargado = await esperarQue(tabId, (d) => {
    const r = [...document.querySelectorAll('tr')].find(r => r.cells.length >= 8 && r.cells[2]?.innerText.trim() === d);
    if (r) return 'fila';
    const aviso = [...document.querySelectorAll('*')].some(e => e.children.length === 0 && e.offsetHeight > 0 && /debe estar|inv[aá]lid|obligatori/i.test(e.textContent));
    return aviso ? 'aviso' : null;
  }, [doc], 25000);

  if (cargado !== 'fila') {
    return { ok: false, paso: 'cargar', error: (await ejecutar(tabId, avisos)) || 'S.O.S. no aceptó los datos.' };
  }

  await clicYEsperar(tabId, () => { document.getElementById('formGeneral:botonValidar').click(); return true; });
  const validada = await esperarQue(tabId, filaCargada, [doc], 20000);
  if (!validada?.iconos.some(t => /validado/i.test(t))) {
    return { ok: false, paso: 'validar', nombre: validada?.nombre, error: `S.O.S. no validó el registro (${validada?.iconos.join(', ') || 'sin detalle'}).` };
  }
  if (!guardar) return { ok: true, validado: true, nombre: validada.nombre };

  await clicYEsperar(tabId, () => { document.getElementById('formGeneral:botonGuardar').click(); return true; });
  const confirmar = await esperarQue(tabId, () => /deseas guardar/i.test([...document.querySelectorAll('.rich-mpnl-body')].filter(e => e.offsetHeight > 0).map(e => e.innerText).join(' ')), [], 20000);
  if (!confirmar) return { ok: false, paso: 'guardar', nombre: validada.nombre, error: 'S.O.S. no pidió confirmar el guardado.' };

  await clicYEsperar(tabId, pClicTexto, ['^Aceptar$', true]);
  const texto = await esperarQue(tabId, () => {
    const t = [...document.querySelectorAll('.rich-mpnl-body')].filter(e => e.offsetHeight > 0).map(e => e.innerText).join(' ');
    return /guardados exitosamente|error|no se pudo/i.test(t) ? t.replace(/\s+/g, ' ') : null;
  }, [], 30000);

  if (!/guardados exitosamente/i.test(texto || '')) {
    return { ok: false, paso: 'guardar', nombre: validada.nombre, error: (texto || 'S.O.S. no confirmó el guardado.').slice(0, 300) };
  }
  return { ok: true, validado: true, guardado: true, nombre: validada.nombre };
}

async function sosAdjuntar(tabId, { tipo, documento, desde, hasta, archivo }, origen) {
  // El lado B lo genera BryNex; solo se acepta de ese mismo origen.
  const url = new URL(archivo, origen);
  if (url.origin !== origen) throw new Error('El archivo del lado B no viene de BryNex.');
  const res = await fetch(url, { credentials: 'include' });
  if (!res.ok || !/image\/(png|jpe?g)/.test(res.headers.get('content-type') || '')) {
    throw new Error(`BryNex no entregó la imagen del lado B (HTTP ${res.status}).`);
  }
  const bytes = new Uint8Array(await res.arrayBuffer());
  let binario = '';
  for (let i = 0; i < bytes.length; i += 0x8000) binario += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  const tipoMime = res.headers.get('content-type').split(';')[0];
  const base64 = btoa(binario);

  const filas = await sosConsultar(tabId, { tipo, documento, desde, hasta });
  const fila = filas.find(f => /cara b/i.test(f.estado));
  if (!fila) return { ok: false, error: 'La novedad no está pendiente del lado B.', filas };

  await clicYEsperar(tabId, (accion) => {
    const a = [...document.querySelectorAll('a[onclick*="jsfcljs"]')].find(e => e.getAttribute('onclick').includes(`'${accion}'`));
    if (!a) return { __error: 'No se encontró la acción de adjuntar en la fila.' };
    a.click();
    return true;
  }, [fila.accion]);

  const listo = await esperarQue(tabId, () => !!document.getElementById('formGeneral:upload6:file'), [], 20000);
  if (!listo) return { ok: false, error: 'No se abrió la ventana de adjuntar en S.O.S.' };

  await ejecutar(tabId, (b64, mime, nombre) => {
    const bin = atob(b64);
    const u8 = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    const dt = new DataTransfer();
    dt.items.add(new File([u8], nombre, { type: mime }));
    const input = document.getElementById('formGeneral:upload6:file');
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, [base64, tipoMime, `lado_b_${documento}.${tipoMime.includes('png') ? 'png' : 'jpg'}`]);

  // La subida es inmediata (RichFaces); al terminar se habilita Guardar.
  await esperar(4000);
  await clicYEsperar(tabId, pClicTexto, ['^Guardar$', true]);
  await esperar(1500);

  const despues = await sosConsultar(tabId, { tipo, documento, desde, hasta });
  const ahora = despues.find(f => f.radicado === fila.radicado);
  return { ok: !!ahora && !/cara b/i.test(ahora.estado), radicado: fila.radicado, estado: ahora?.estado, novedad: ahora || null };
}

// ── Sanitas (Oficina Virtual de Empleadores) ──────────────────────────────
// Detrás de Radware: todo se pide desde la propia pestaña de la persona, con
// su sesión, sin navegar por el sitio (navegar mucho dispara el captcha).

const SANITAS_EMPLEADORES = 'https://www.epssanitas.com/usuarios/group/empleadores';
const SANITAS_PORTLET_ESTADO = 'consultarestadosafiliados_WAR_radicacionincapacidadesportlet';

async function pestanaSanitas() {
  const pestanas = await chrome.tabs.query({ url: ['https://www.epssanitas.com/*', 'https://epssanitas.com/*'] });
  // Mejor una que ya esté dentro de la Oficina Virtual.
  return pestanas.find(p => /\/group\/empleadores/.test(p.url || '')) || pestanas[0] || null;
}

async function atenderSanitas(accion, datos, origen) {
  if (accion.startsWith('novedad')) return atenderSanitasNovedad(accion, datos, origen);

  if (accion === 'abrir') {
    const p = await pestanaSanitas();
    if (p) {
      await chrome.tabs.update(p.id, { active: true });
      await chrome.windows.update(p.windowId, { focused: true });
    } else {
      await chrome.tabs.create({ url: `${SANITAS_EMPLEADORES}/inicio`, active: true });
    }
    return { ok: true, abierta: true };
  }

  const pestana = await pestanaSanitas();
  if (!pestana) return { ok: true, abierta: false, sesion: false };

  if (accion === 'estado') {
    return { ok: true, abierta: true, ...(await sanitasSesion(pestana.id)) };
  }

  if (accion === 'estadoAfiliacion') {
    const s = await sanitasSesion(pestana.id);
    if (!s.sesion) throw new Error('La pestaña de Sanitas no tiene la Oficina Virtual de Empleadores abierta. Inicia sesión y vuelve a intentar.');
    const txt = await ejecutar(pestana.id, async (base, portlet) => {
      const u = `${base}/estado-de-afiliacion?p_p_id=${portlet}&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_cacheability=cacheLevelPage&p_p_col_id=column-1&p_p_col_count=1&_${portlet}_tipoReporte=txt&_${portlet}_accion=desrcargarReporte`;
      const r = await fetch(u, { credentials: 'include' });
      const t = new TextDecoder('utf-8').decode(await r.arrayBuffer());
      if (!/numero identificaci/i.test(t.slice(0, 400))) return { __error: 'Sanitas no entregó el Estado de Afiliación (¿se cerró la sesión o salió el captcha?).' };
      return t;
    }, [SANITAS_EMPLEADORES, SANITAS_PORTLET_ESTADO]);
    return { ok: true, nit: s.nit, empresa: s.empresa, txt };
  }

  throw new Error(`Acción de Sanitas desconocida: ${accion}`);
}

/**
 * ¿Hay sesión en la Oficina Virtual? Pide los datos de la empresa como hace la
 * página del estado de afiliación (JSON con nombre y NIT); sin sesión no es JSON.
 * Esa misma llamada deja listo el reporte que luego se descarga.
 */
async function sanitasSesion(tabId) {
  try {
    return await ejecutar(tabId, async (base, portlet) => {
      const u = `${base}/estado-de-afiliacion?p_p_id=${portlet}&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_cacheability=cacheLevelPage&p_p_col_id=column-1&p_p_col_count=1&_${portlet}_accion=consultaDatosEmpresa`;
      const r = await fetch(u, { method: 'POST', credentials: 'include' });
      const t = await r.text();
      let d;
      try { d = JSON.parse(t); } catch { return { sesion: false, captcha: /perfdrive|radware|captcha/i.test(r.url + t.slice(0, 2000)) }; }
      const b = d?.datosBasicos;
      if (!b?.identificacion?.numIdentificacion) return { sesion: false };
      return { sesion: true, empresa: b.nombreCompleto, nit: String(b.identificacion.numIdentificacion) };
    }, [SANITAS_EMPLEADORES, SANITAS_PORTLET_ESTADO]);
  } catch {
    return { sesion: false };
  }
}

// ── Sanitas: formulario web "Novedades a la afiliación" ───────────────────
// Portlet Liferay `radicarnovedades`. Tipo de novedad "Cambio de empleador" =
// 10513. El adjunto lo sube el cargador de Liferay en cuanto se elige el archivo;
// el Enviar lo pulsa la persona y la página se recarga con la respuesta.

const SANITAS_NOVEDADES = 'https://www.epssanitas.com/usuarios/web/nuevo-portal-eps/novedades-afiliacion';
const SANITAS_NS = '_radicarnovedades_WAR_radicarnovedadesportlet_';

async function pestanaNovedades() {
  const pestanas = await chrome.tabs.query({ url: ['https://www.epssanitas.com/*', 'https://epssanitas.com/*', 'https://validate.perfdrive.com/*'] });
  return pestanas.find(p => /novedades-afiliacion/.test(p.url || '')) || pestanas.find(p => /perfdrive/.test(p.url || '')) || null;
}

async function atenderSanitasNovedad(accion, datos, origen) {
  if (accion === 'novedadAbrir') {
    const p = await pestanaNovedades();
    if (p) {
      await chrome.tabs.update(p.id, { active: true });
      await chrome.windows.update(p.windowId, { focused: true });
    } else {
      await chrome.tabs.create({ url: SANITAS_NOVEDADES, active: true });
    }
    return { ok: true, abierta: true };
  }

  const pestana = await pestanaNovedades();
  if (!pestana) return { ok: true, abierta: false, listo: false };
  if (/perfdrive/.test(pestana.url || '')) {
    return { ok: true, abierta: true, listo: false, error: 'Sanitas pide verificar que no eres un robot: resuélvelo en la pestaña de Sanitas.' };
  }

  if (accion === 'novedadEstado') {
    let e;
    try { e = await ejecutar(pestana.id, pNovedadEstado, [SANITAS_NS]); } catch { e = { listo: false }; }
    return { ok: true, abierta: true, ...e };
  }

  if (accion === 'novedadLlenar') return sanitasNovedadLlenar(pestana, datos, origen);

  if (accion === 'novedadResultado') {
    const r = await ejecutar(pestana.id, pNovedadResultado, [SANITAS_NS, String(datos.documento || '')]);
    if (r?.enviado) {
      // La captura solo sale si la pestaña de Sanitas es la que se ve; si no, queda el texto.
      try {
        const actual = await chrome.tabs.get(pestana.id);
        if (actual.active) {
          const url = await chrome.tabs.captureVisibleTab(actual.windowId, { format: 'jpeg', quality: 70 });
          r.captura = url.split(',')[1] || null;
        }
      } catch { /* sin permiso o sin ventana visible: basta con el texto */ }
    }
    return { ok: true, ...r };
  }

  throw new Error(`Acción de Sanitas desconocida: ${accion}`);
}

function pNovedadEstado(ns) {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight || e.getClientRects().length));
  const form = document.getElementById(ns + 'fmRadicarNov');
  const errores = ['msg-alert-error-login', 'msg-alert-error-titular', 'msg-alert-error-service', 'msg-alert-error-ajax']
    .map(id => document.getElementById(id)).filter(vis).map(e => e.innerText.trim()).filter(Boolean);
  const tipo = document.getElementById(ns + 'tipoNovedadSelect');
  const listo = !!form && !!tipo && [...tipo.options].some(o => o.value === '10513');
  return {
    listo: listo && !errores.length,
    formulario: !!form,
    error: errores.join(' ') || (form ? (listo ? null : 'El formulario de Sanitas aún no carga los tipos de novedad.') : 'La pestaña no muestra el formulario de novedades de Sanitas.'),
  };
}

async function sanitasNovedadLlenar(pestana, d, origen) {
  const estado = await ejecutar(pestana.id, pNovedadEstado, [SANITAS_NS]);
  if (!estado.listo) return { ok: false, error: estado.error || 'El formulario de Sanitas no está listo.' };

  // El formulario PDF lo genera BryNex; solo se acepta de ese mismo origen.
  const url = new URL(d.archivo, origen);
  if (url.origin !== origen) throw new Error('El formulario no viene de BryNex.');
  const res = await fetch(url, { credentials: 'include' });
  if (!res.ok || !/pdf/.test(res.headers.get('content-type') || '')) {
    let detalle = '';
    try { detalle = (await res.json()).error || ''; } catch { /* no era JSON */ }
    throw new Error(detalle || `BryNex no entregó el formulario (HTTP ${res.status}).`);
  }
  const bytes = new Uint8Array(await res.arrayBuffer());
  let binario = '';
  for (let i = 0; i < bytes.length; i += 0x8000) binario += String.fromCharCode(...bytes.subarray(i, i + 0x8000));

  // 1. Datos del afiliado y departamento (el municipio se carga por AJAX).
  await ejecutar(pestana.id, (ns, d) => {
    const $ = id => document.getElementById(ns + id);
    const poner = (id, v) => { const e = $(id); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); };
    poner('documentType', d.tipoDoc);
    window[ns + 'asignarNombreDocumento']?.();
    poner('login', d.documento);
    $('dptoSeleccionado').value = d.departamento;
    window[ns + 'consultarCiudadesAjax']?.();
    return true;
  }, [SANITAS_NS, d]);

  const ciudad = await esperarQue(pestana.id, (ns, d) => {
    const s = document.getElementById(ns + 'citySeleccionado');
    if (!s || s.options.length <= 1) return null;
    const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
    const op = [...s.options].find(o => o.value && (o.value === d.municipioDane || o.value === d.municipioDane.slice(2) || norm(o.text) === norm(d.municipio)))
      || [...s.options].find(o => o.value && norm(o.text).startsWith(norm(d.municipio)));
    if (!op) return { encontrado: false, opciones: [...s.options].slice(1, 8).map(o => o.value + '=' + o.text.trim()) };
    s.value = op.value;
    s.dispatchEvent(new Event('change', { bubbles: true }));
    return { encontrado: true, valor: op.value, texto: op.text.trim() };
  }, [SANITAS_NS, d], 20000);
  if (!ciudad?.encontrado) {
    return { ok: false, error: `Sanitas no cargó el municipio ${d.municipio}${ciudad?.opciones ? ` (opciones: ${ciudad.opciones.join(', ')})` : ''}.` };
  }

  // 2. Contacto, tipo de novedad y observaciones.
  const requisitos = await ejecutar(pestana.id, (ns, d) => {
    const $ = id => document.getElementById(ns + id);
    const poner = (id, v) => { const e = $(id); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); };
    poner('telefonoFijoNumber', d.telefonoFijo);
    poner('celularNumber', d.celular);
    poner('emailAddress', d.correo);
    poner('tipoNovedadSelect', d.tipoNovedad);
    poner('observations', d.observaciones);
    return (document.getElementById('list-documents-requiered')?.innerText || '').trim();
  }, [SANITAS_NS, d]);

  // 3. Adjunto: se entrega al cargador de Liferay, que lo sube de una vez.
  const subido = await ejecutar(pestana.id, (ns, b64, nombre) => {
    const ya = [...document.querySelectorAll(`input[name="${ns}selectUploadedFileCheckbox"]`)].some(c => c.value === nombre);
    if (ya) return 'ya';
    const input = document.querySelector(`#${ns}uploaderContent input[type=file]`) || document.querySelector(`#${ns}fileUpload input[type=file]`);
    if (!input) return { __error: 'No se encontró el cargador de archivos del formulario de Sanitas.' };
    const bin = atob(b64);
    const u8 = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    const dt = new DataTransfer();
    dt.items.add(new File([u8], nombre, { type: 'application/pdf' }));
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return 'enviado';
  }, [SANITAS_NS, btoa(binario), d.nombreArchivo]);

  const adjunto = subido === 'ya' || await esperarQue(pestana.id, (ns, nombre) => {
    // Liferay puede renombrar el temporal: vale el que lleve el nombre, o el único que haya.
    const todos = [...document.querySelectorAll(`input[name="${ns}selectUploadedFileCheckbox"]`)];
    const base = nombre.replace(/\.pdf$/i, '');
    const c = todos.find(x => x.value === nombre) || todos.find(x => x.value.includes(base)) || (todos.length === 1 ? todos[0] : null);
    if (c && !c.checked) c.click();
    return c?.checked ? true : null;
  }, [SANITAS_NS, d.nombreArchivo], 60000);

  // 4. Deja todo a la vista con Enviar resaltado y marca el trámite en la pestaña.
  await ejecutar(pestana.id, (ns, doc) => {
    sessionStorage.setItem('brynexNovedad', JSON.stringify({ documento: doc, desde: Date.now() }));
    const b = document.getElementById(ns + 'btnSend');
    if (b) { b.style.outline = '3px solid #f59e0b'; b.style.outlineOffset = '3px'; b.scrollIntoView({ block: 'center' }); }
    return true;
  }, [SANITAS_NS, String(d.documento)]);
  await chrome.tabs.update(pestana.id, { active: true });
  await chrome.windows.update(pestana.windowId, { focused: true });

  return {
    ok: true,
    municipio: ciudad.texto,
    requisitos,
    adjunto: !!adjunto,
    aviso: adjunto ? null : 'El formulario quedó lleno pero Sanitas no confirmó el adjunto: adjúntalo a mano antes de Enviar.',
  };
}

/** Después del Enviar: la página se recarga (el documento vuelve vacío) y muestra la respuesta. */
function pNovedadResultado(ns, documento) {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight || e.getClientRects().length));
  let marca = null;
  try { marca = JSON.parse(sessionStorage.getItem('brynexNovedad') || 'null'); } catch { /* sin marca */ }
  if (!marca || (documento && marca.documento !== documento)) return { enviado: false, sinTramite: true };

  const login = document.getElementById(ns + 'login');
  const errores = [...document.querySelectorAll('.alert-error, .portlet-msg-error, .form-validator-stack, .help-inline')]
    .filter(vis).map(e => e.innerText.trim()).filter(Boolean);
  // Sigue en el formulario lleno: no han dado Enviar o la validación lo frenó.
  if (login && login.value === marca.documento) return { enviado: false, errores };

  // Sanitas responde en una ventana emergente: "La radicación no. 0014885197 ha sido
  // registrada exitosamente. La respuesta será enviada a: … en los próximos tres días hábiles".
  const limpiar = t => (t || '').replace(/\s+/g, ' ').trim();
  const ventana = [...document.querySelectorAll('.modal, .aui-dialog, .yui3-widget-bd, [role=dialog], .alert, .portlet-msg-success, .portlet-msg-info')]
    .filter(vis).map(e => limpiar(e.innerText)).find(t => /radicaci[oó]n|radicad[oa]/i.test(t)) || '';
  const portlet = document.getElementById('p_p_id' + ns) || document.querySelector('.portlet-body') || document.body;
  // Con la ventana de respuesta basta; sin ella, el texto del portlet (sin las listas del formulario).
  const texto = (ventana || limpiar(portlet.innerText)).slice(0, 6000);
  const buscar = t => t.match(/radicaci[oó]n\s*(?:no\.?|n[°º.]*|n[uú]mero)?\s*:?\s*(\d[\d-]{4,})/i)
    || t.match(/radicad[oa][^0-9]{0,80}?(\d[\d-]{4,})/i) || t.match(/n[uú]mero[^0-9]{0,60}?(\d[\d-]{4,})/i);
  const m = buscar(texto) || buscar(limpiar(document.body.innerText));
  const exito = [...document.querySelectorAll('.alert-success, .portlet-msg-success')].filter(vis).map(e => e.innerText.trim()).join(' ');
  sessionStorage.removeItem('brynexNovedad');
  return { enviado: true, radicado: m ? m[1] : null, texto, exito, errores };
}

// ── Boxalud (Emssanar): Ingreso de afiliación ─────────────────────────────
// ASP.NET + DevExpress. Los controles del formulario son globales del mundo MAIN
// (comboBoxAfiliadoTipoIdentificacion, afiliadoConsultar()…); con su API se
// llenan igual que a mano. Mapeado el 15-sep-2026 sin guardar ninguna afiliación.

const BOXALUD_HOSTS = ['boxalud.emssanareps.co'];
const boxBase = (host) => `https://${host}/Externo/BoxaludExternoNS`;

async function pestanaBoxalud(host) {
  if (!BOXALUD_HOSTS.includes(host)) throw new Error(`Portal Boxalud no permitido: ${host}`);
  const ps = await chrome.tabs.query({ url: `https://${host}/*` });
  return ps.find(p => p.active) || ps[0] || null;
}

function pBoxEstado() {
  const login = !!document.querySelector('[id$="textName_I"]');
  const lineas = (document.body?.innerText || '').split('\n').map(l => l.trim()).filter(Boolean);
  const i = lineas.indexOf('Afiliaciones');
  const empresa = !login && i > 0 && !/^Plan/.test(lineas[i - 1]) ? lineas[i - 1] : null;
  return { sesion: !login && !!empresa, login, empresa, pagina: location.pathname.split('/').pop(), titulo: document.title };
}

async function atenderBoxalud(accion, d, origen) {
  const host = String(d.host || '');
  if (accion === 'boxAbrir') {
    let p = await pestanaBoxalud(host);
    if (p) {
      await chrome.tabs.update(p.id, { active: true });
      await chrome.windows.update(p.windowId, { focused: true });
    } else {
      p = await chrome.tabs.create({ url: `https://${host}/Externo/BoxaludExterno/Seguridad/login.aspx`, active: true });
      await esperarCarga(p.id);
    }
    if (d.usuario) {
      await esperarQue(p.id, (u, c) => {
        const campo = document.querySelector('[id$="textName_I"]');
        if (!campo) return true;              // ya tiene sesión
        const poner = (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); };
        poner(campo, u);
        const clave = document.querySelector('[id$="textPassword_I"]');
        if (clave && c) poner(clave, c); else clave?.focus();
        return true;
      }, [String(d.usuario), d.contrasena ? String(d.contrasena) : ''], 15000);
    }
    return { ok: true, abierta: true };
  }

  const pestana = await pestanaBoxalud(host);
  if (!pestana) return { ok: true, abierta: false, sesion: false };

  if (accion === 'boxEstado') {
    let e;
    try { e = await ejecutar(pestana.id, pBoxEstado); } catch { e = { sesion: false }; }
    return { ok: true, abierta: true, ...e };
  }
  if (accion === 'boxLlenar') return boxaludLlenar(pestana, d, origen);
  if (accion === 'boxResultado') return { ok: true, ...(await ejecutar(pestana.id, pBoxResultado, [String(d.documento || '')])) };

  throw new Error(`Acción de Boxalud desconocida: ${accion}`);
}

async function boxaludLlenar(pestana, d, origen) {
  const tab = pestana.id;
  const avisos = [];
  const norm = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');

  // 0. Sesión y empresa correctas.
  const est = await ejecutar(tab, pBoxEstado);
  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el usuario de la empresa y vuelve a intentar.' };
  if (!norm(est.empresa).includes(norm(d.empresa).slice(0, 12)) && !norm(d.empresa).includes(norm(est.empresa).slice(0, 12))) {
    return { ok: false, error: `El portal está abierto con ${est.empresa}, pero el contrato es de ${d.empresa}. Cierra sesión y entra con el usuario de ${d.empresa}.` };
  }

  // 1. Declaración de la carta de derechos → Diligenciar datos de afiliación.
  await chrome.tabs.update(tab, { url: `${boxBase(d.host)}/Pages/CartaDerechosDeberes.aspx?NA=true` });
  await esperarCarga(tab);
  await clicYEsperar(tab, () => { const b = document.querySelector('[id$="btnSiguiente_I"]'); if (!b) return { __error: 'No apareció la declaración de la carta de derechos.' }; b.click(); return true; });
  const diligenciar = await esperarQue(tab, () => typeof window.afiliadoAdicionar === 'function' && typeof window.dateEditAfiliacionFechaInicial === 'object', [], 30000);
  if (!diligenciar) return { ok: false, error: 'No se abrió "Diligenciar datos de afiliación" en el portal.' };

  // 2. Fecha de inicio y modal del afiliado.
  await ejecutar(tab, (f) => {
    const [a, m, dd] = f.split('-').map(Number);
    dateEditAfiliacionFechaInicial.SetDate(new Date(a, m - 1, dd)); dateEditAfiliacionFechaInicial.RaiseValueChangedEvent?.();
    afiliadoAdicionar();
    return true;
  }, [d.fechaIngreso]);
  const modal = await esperarQue(tab, () => modalDatosAfiliado.IsVisible() && !callbackPanelDatosAfiliado.InCallback() && !loadingPanel.IsVisible(), [], 30000);
  if (!modal) return { ok: false, error: 'No se abrió el formulario del afiliado.' };

  // 3. Documento → el portal valida en ADRES y precarga (hasta ~1 min).
  await ejecutar(tab, (tipo, doc) => {
    comboBoxAfiliadoTipoIdentificacion.SetValue(tipo); comboBoxAfiliadoTipoIdentificacion.RaiseValueChangedEvent?.();
    textBoxAfiliadoNumeroIdentificacion.SetValue(doc);
    afiliadoConsultar(false);
    return true;
  }, [d.tipoDoc, String(d.documento)]);
  await esperar(2000);
  const consulta = await esperarQue(tab, () => {
    const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
    if (window.ModalRedireccionarNovedadAdicion?.IsVisible?.()) return { estado: 'adicion', texto: ModalRedireccionarNovedadAdicion.GetMainElement().innerText.replace(/\s+/g, ' ').trim() };
    if (callbackPanelDatosAfiliado.InCallback() || loadingPanel.IsVisible()) return null;
    const mensajes = [...document.querySelectorAll('[id*="pupMensaje"], [id*="ucMensajeAplicacion"] .dxpc-content')].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean);
    if (textBoxAfiliadoApellido1.GetValue()) return { estado: 'ok', mensajes };
    return mensajes.length ? { estado: 'mensaje', mensajes } : null;
  }, [], 150000);
  if (!consulta) return { ok: false, error: 'El portal no respondió la validación del afiliado en ADRES.' };
  if (consulta.estado === 'adicion') return { ok: false, adicion: true, error: `El portal pide hacerlo como "Adición de relación laboral": ${consulta.texto}` };
  if (consulta.estado !== 'ok') return { ok: false, error: `El portal no cargó al afiliado: ${(consulta.mensajes || []).join(' ')}` };
  avisos.push(...(consulta.mensajes || []));

  // 4. Que sea la misma persona.
  const apellidoPortal = await ejecutar(tab, () => textBoxAfiliadoApellido1.GetValue());
  if (norm(apellidoPortal) !== norm(d.apellido)) {
    return { ok: false, error: `En el portal el documento es de ${apellidoPortal}, que no coincide con el apellido de BryNex (${d.apellido}). Revisa antes de seguir.` };
  }

  // 5. Contacto y residencia (solo lo vacío; la dirección en nomenclatura DANE).
  const contacto = await ejecutar(tab, (d) => {
    const salida = { direccion: null };
    const caption = (c) => (document.getElementById(c.name)?.closest('.dxflItem, td, div')?.parentElement?.innerText || '').toUpperCase();
    const poner = (c, v) => { if (c && v && !c.GetValue()) { c.SetValue(v); c.RaiseValueChangedEvent?.(); } };
    function normalizar(t) {
      let s = String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
      s = s.replace(/\b(N[OO]\.?|NUMERO|NRO\.?)\s*(?=\d)/g, ' ').replace(/[^A-Z0-9 ]/g, ' ');
      const tipos = [[/\b(AVENIDA\s+CALLE|AV\s*CALLE|AC)\b/g, 'AC'], [/\b(AVENIDA\s+CARRERA|AV\s*CARRERA|AK)\b/g, 'AK'], [/\b(CALLE|CLL|CLLE|CL|CALL)\b/g, 'CL'], [/\b(CARRERA|CRA|KRA|KR|CR|CRR|K)\b/g, 'CR'], [/\b(AVENIDA|AVE|AV)\b/g, 'AV'], [/\b(DIAGONAL|DIAG|DG)\b/g, 'DG'], [/\b(TRANSVERSAL|TRANSV|TRV|TV)\b/g, 'TV'], [/\b(AUTOPISTA|AUT)\b/g, 'AUT'], [/\b(CIRCUNVALAR|CRV)\b/g, 'CRV']];
      for (const [re, v] of tipos) s = s.replace(re, v);
      s = s.replace(/(\d)([A-Z])/g, '$1 $2').replace(/([A-Z])(\d)/g, '$1 $2').replace(/\bN\b(?=\s+\d|$)/g, 'NORTE').replace(/\s+/g, ' ').trim();
      const m = s.match(/\b(AC|AK|AV|CL|CR|DG|TV|AUT|CRV)\b.*/);
      return m ? m[0] : s;
    }
    if (!textBoxAfiliadoDireccion.GetValue() && d.direccion) {
      const tok = normalizar(d.direccion).split(' ');
      for (let n = tok.length; n >= 3; n--) { const x = tok.slice(0, n).join(' '); if (validarDireccionRegex(x, 2)) { salida.direccion = x; break; } }
      if (salida.direccion) { textBoxAfiliadoDireccion.SetValue(salida.direccion); try { validarDireccion(textBoxAfiliadoDireccion, 2); } catch {} }
    } else {
      salida.direccion = textBoxAfiliadoDireccion.GetValue();
    }
    const tels = [textBoxAfiliadoTelefono1, textBoxAfiliadoTelefono2, textBoxAfiliadoTelefono3];
    const fijo = tels.find(c => /FIJO/.test(caption(c)));
    const celulares = tels.filter(c => c !== fijo);
    poner(celulares[0], d.celular); poner(celulares[1], d.celular2); poner(fijo, d.fijo);
    poner(textBoxCorreoElectronico1, d.correo);
    if (comboBoxAfiliadoOrientacionSexual.GetValue() == null || String(comboBoxAfiliadoOrientacionSexual.GetValue()) === '-1') {
      comboBoxAfiliadoOrientacionSexual.SetValue(100); comboBoxAfiliadoOrientacionSexual.RaiseValueChangedEvent?.();
    }
    return salida;
  }, [d]);
  if (!contacto.direccion) avisos.push('La dirección de BryNex no tiene formato válido para el portal: escríbela en el campo Dirección (ej. CR 94 1 A 128).');

  // 6. Relación laboral: fecha y tipo de cotizante (recarga la sección), luego el resto.
  await ejecutar(tab, (f) => {
    const [a, m, dd] = f.split('-').map(Number);
    dateEditRelacionLaboralFechaInicial.SetDate(new Date(a, m - 1, dd)); dateEditRelacionLaboralFechaInicial.RaiseValueChangedEvent?.();
    const cb = comboBoxRelacionLaboralTipoCotizante;
    for (let i = 0; i < cb.GetItemCount(); i++) if (String(cb.GetItem(i).value) === '1') cb.SetSelectedIndex(i);
    cb.RaiseValueChangedEvent();
    return true;
  }, [d.fechaIngreso]);
  await esperar(1500);
  await esperarQue(tab, () => !callbackPanelDatosRelacionLaboral.InCallback() && !loadingPanel.IsVisible(), [], 30000);

  const laboral = await ejecutar(tab, (d) => {
    const n = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
    const elegir = (cb, claves) => {
      for (let i = 0; i < cb.GetItemCount(); i++) { const t = n(cb.GetItem(i).text); if (claves.some(k => t.includes(k))) { cb.SetSelectedIndex(i); cb.RaiseValueChangedEvent?.(); return cb.GetItem(i).text; } }
      return null;
    };
    if (String(comboBoxRelacionLaboralTipoCotizante.GetValue()) !== '1') return { __error: 'El portal no tomó el tipo de cotizante Dependiente.' };
    const afp = n(d.afp), arl = n(d.arl);
    const claveAfp = [['PROTECCION', 'PROTECCI'], ['PORVENIR', 'PORVENIR'], ['COLFONDOS', 'COLFONDOS'], ['COLPENSIONES', 'COLPENSIONES'], ['SKANDIA', 'SKANDIA'], ['OLD MUTUAL', 'SKANDIA']].find(([k]) => afp.includes(k));
    const claveArl = [['SURA', 'SURA'], ['POSITIVA', 'POSITIVA'], ['BOLIVAR', 'BOLIVAR'], ['COLMENA', 'COLMENA'], ['EQUIDAD', 'EQUIDAD'], ['AXA', 'AXA'], ['COLPATRIA', 'AXA'], ['LIBERTY', 'LIBERTY'], ['ALFA', 'ALFA'], ['SANITAS', 'COLSANITAS'], ['MAPFRE', 'MAPFRE']].find(([k]) => arl.includes(k));
    const salida = {
      // Contrato sin pensión (p. ej. pensionado o extranjero): "Sin AFP".
      afp: claveAfp ? elegir(comboBoxRelacionLaboralAFP, [claveAfp[1]]) : (afp.trim() === '' ? elegir(comboBoxRelacionLaboralAFP, ['SIN AFP']) : null),
      arl: claveArl ? elegir(comboBoxRelacionLaboralARL, [claveArl[1]]) : null,
    };
    comboBoxAportanteTipoIdentificacionConsultar.SetValue(1); comboBoxAportanteTipoIdentificacionConsultar.RaiseValueChangedEvent?.();
    textBoxAportanteNumeroIdentificacionConsultar.SetValue(d.nit);
    buttonAportanteConsutar.DoClick();
    return salida;
  }, [d]);
  if (!laboral.afp) avisos.push(`No se encontró la AFP "${d.afp}" en el portal: elígela a mano.`);
  if (!laboral.arl) avisos.push(`No se encontró la ARL "${d.arl}" en el portal: elígela a mano.`);

  await esperar(1500);
  const aportante = await esperarQue(tab, (nit) => {
    if (loadingPanel.IsVisible()) return null;
    const modalTxt = modalDatosAfiliado.GetMainElement().innerText;
    const i = modalTxt.indexOf('Nombre o razón social:');
    if (i < 0) return null;
    const bloque = modalTxt.slice(i, i + 400);
    if (!bloque.includes(nit)) return null;
    const lineas = bloque.split('\n').map(l => l.trim()).filter(Boolean);
    const cartera = (bloque.match(/(\d+)\s*periodos/) || [])[1];
    return { razon: lineas[4] || lineas[1] || '', cartera: cartera ? Number(cartera) : 0 };
  }, [d.nit], 30000);
  if (!aportante) avisos.push('El portal no mostró los datos del aportante: pulsa VALIDAR en la sección del aportante.');
  else if (aportante.cartera > 0) avisos.push(`El portal marca ${aportante.cartera} periodo(s) en mora del aportante.`);

  await ejecutar(tab, (d) => {
    const ts = comboBoxRelacionLaboralTipoSalario;
    for (let i = 0; i < ts.GetItemCount(); i++) if (String(ts.GetItem(i).value) === '2') { ts.SetSelectedIndex(i); ts.RaiseValueChangedEvent?.(); }
    if (!textBoxRelacionLaboralIngresoMensual.GetValue()) { textBoxRelacionLaboralIngresoMensual.SetValue(String(d.salario)); textBoxRelacionLaboralIngresoMensual.RaiseValueChangedEvent?.(); }
    if (!textBoxRelacionLaboralCargo.GetValue()) { textBoxRelacionLaboralCargo.SetValue(d.cargo); textBoxRelacionLaboralCargo.RaiseValueChangedEvent?.(); }
    return true;
  }, [d]);

  // 7. Documentos: formulario firmado y encuesta de la carta de derechos.
  await ejecutar(tab, () => { pageControlDatosAfiliado.SetActiveTabIndex(1); return true; });
  await esperar(2500);
  const adjuntos = [];
  for (const doc of d.documentos || []) {
    const url = new URL(doc.url, origen);
    if (url.origin !== origen) throw new Error('El documento no viene de BryNex.');
    const res = await fetch(url, { credentials: 'include' });
    if (!res.ok || !/pdf/.test(res.headers.get('content-type') || '')) { avisos.push(`BryNex no entregó ${doc.nombre}.`); continue; }
    const bytes = new Uint8Array(await res.arrayBuffer());
    let bin = '';
    for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));

    const subido = await ejecutar(tab, (tipo, b64, nombre) => {
      const input = document.querySelector(`input[type=file][id$="Uploader_-1_${tipo}_Pag1_TextBox0_Input"]`);
      if (!input) return { __error: `No se encontró dónde adjuntar el documento ${tipo}.` };
      const raw = atob(b64); const u8 = new Uint8Array(raw.length);
      for (let i = 0; i < raw.length; i++) u8[i] = raw.charCodeAt(i);
      const dt = new DataTransfer(); dt.items.add(new File([u8], nombre, { type: 'application/pdf' }));
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      let ctrl = null;
      ASPx.GetControlCollection().ForEachControl(c => { if (!ctrl && c.name && c.name.endsWith(`Uploader_-1_${tipo}_Pag1`)) ctrl = c; });
      try { if (ctrl && !ctrl.autoStartUpload && typeof ctrl.UploadFile === 'function') ctrl.UploadFile(); } catch { /* sube solo */ }
      return true;
    }, [doc.tipo, btoa(bin), doc.nombre]);
    const listo = subido && await esperarQue(tab, (tipo) => {
      const cont = document.getElementById(`formContenedorAfiliadoDocumentos_${tipo}_0_2`);
      const fila = cont?.closest('tr');
      return fila && !/no ha sido seleccionado/i.test(fila.innerText) ? fila.innerText.replace(/\s+/g, ' ').trim() : null;
    }, [doc.tipo], 60000);
    adjuntos.push({ nombre: doc.nombre, ok: !!listo, estado: listo || 'sin confirmar' });
    if (!listo) avisos.push(`No se confirmó el adjunto ${doc.nombre}: adjúntalo a mano en la pestaña Documentos.`);
  }

  // 8. Todo a la vista: la persona revisa, pulsa ACEPTAR en el afiliado y GUARDAR.
  await ejecutar(tab, (doc) => { sessionStorage.setItem('brynexBoxalud', JSON.stringify({ documento: doc, desde: Date.now() })); return true; }, [String(d.documento)]);
  await chrome.tabs.update(tab, { active: true });
  await chrome.windows.update(pestana.windowId, { focused: true });

  return { ok: true, empresa: est.empresa, direccion: contacto.direccion, afp: laboral.afp, arl: laboral.arl, aportante, adjuntos, avisos };
}

/** Después de GUARDAR: mensajes del portal y, si aparece, el número. */
function pBoxResultado(documento) {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
  let marca = null;
  try { marca = JSON.parse(sessionStorage.getItem('brynexBoxalud') || 'null'); } catch { /* sin marca */ }
  if (!marca || (documento && marca.documento !== documento)) return { guardado: false, sinTramite: true };

  const mensajes = [...document.querySelectorAll('[id*="pupMensaje"], [id*="ucMensajeAplicacion"] .dxpc-content, .dxpc-content')]
    .filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(t => t && t.length < 1500);
  const enModal = typeof window.modalDatosAfiliado === 'object' && window.modalDatosAfiliado.IsVisible?.();
  const texto = [...new Set(mensajes)].join(' — ');
  const exito = /(guardad|registrad|radicad|exitos|creada|satisfactori)/i.test(texto);
  const salioDeDiligenciar = !/Diligenciar/i.test(location.pathname);
  if (!exito && (enModal || !salioDeDiligenciar)) return { guardado: false, mensajes };

  const m = texto.match(/(?:radicad[oa]|solicitud|afiliaci[oó]n|n[uú]mero|consecutivo)[^0-9]{0,40}(\d{4,})/i);
  sessionStorage.removeItem('brynexBoxalud');
  return { guardado: true, numero: m ? m[1] : null, texto: texto || document.body.innerText.replace(/\s+/g, ' ').slice(0, 1500), mensajes, url: location.href };
}

// ── Caja Comfenalco Valle: Sucursal Virtual Afiliación ────────────────────
// jQuery + Chosen. Los combos se llenan con .val().trigger('change') y hay que
// refrescar Chosen; los pasos los avanza la persona (Continuar) y ella acepta los
// términos y pulsa Finalizar. La extensión solo llena lo que esté vacío.

const CCFCV_HOST = 'virtual.comfenalcovalle.com.co';
const CCFCV_BASE = `https://${CCFCV_HOST}/ServiciosWebRyA`;
// El ingreso vive en otro sitio (AuthComfe empresas), sin captcha.
const CCFCV_LOGIN = 'https://authcomfeempresasprod.web.app/login?app_id=comfenalco.sucursalvirtual.empresas.app&tipo=E';

async function pestanaCcfcv() {
  const ps = await chrome.tabs.query({ url: [`https://${CCFCV_HOST}/*`, 'https://authcomfeempresasprod.web.app/*'] });
  return ps.find(p => /ServiciosWebRyA/.test(p.url || '')) || ps[0] || null;
}

/**
 * Baja los anexos que Comfenalco ya tiene guardados (paso Anexos): la cédula del
 * trabajador y, de cada beneficiario, registro civil, consulta ADRES, etc.
 *
 * El botón "Ver" del portal pide `CmndConsultarAnexoSesion {idAnexo, usuario}` y
 * recibe el PDF como arreglo de bytes; aquí se hace la misma llamada, sin abrir
 * el visor, y se devuelve en base64 para que BryNex lo guarde.
 */
async function pCcfDocumentos() {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
  let usuario = null;
  try { usuario = JSON.parse(localStorage.getItem('usuario')); } catch { /* sin usuario */ }
  if (!usuario) return { docs: [], error: 'El portal no tiene la sesión iniciada.' };

  const filas = [];
  document.querySelectorAll('#tablaAnexosTrabajado tbody tr, #tablaAnexosBeneficiario tbody tr').forEach(tr => {
    const celdas = [...tr.querySelectorAll('td')].map(td => td.innerText.replace(/\s+/g, ' ').trim());
    const ver = [...tr.querySelectorAll('button,a,input[type=button]')].find(e => /ver/i.test(e.innerText || e.value || ''));
    const id = /visualziarAnexo\((\d+)\)/.exec(ver?.getAttribute('onclick') || '')?.[1];
    if (id && celdas[2]) filas.push({ id, requerido: celdas[0], archivo: celdas[2] });
  });

  const pedir = id => new Promise(resolve => {
    const reloj = setTimeout(() => resolve(null), 30000);
    ejecutarAjax('CmndConsultarAnexoSesion', { idAnexo: id, usuario }, r => {
      clearTimeout(reloj);
      try { resolve(typeof r.respuesta === 'string' ? JSON.parse(r.respuesta) : r.respuesta); } catch { resolve(null); }
    });
  });

  const docs = [];
  for (const f of filas) {
    const x = await pedir(f.id);
    if (!x || !Array.isArray(x.archivo) || !x.archivo.length) { docs.push({ ...f, error: 'El portal no entregó el archivo.' }); continue; }
    const bytes = Uint8Array.from(x.archivo, v => v & 255);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 8192) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192));
    docs.push({
      requerido: f.requerido,
      nombre: x.nombreArchivo || f.archivo,
      doc_beneficiario: x.idBeneficiario?.documento ? String(x.idBeneficiario.documento) : null,
      tipo_doc_beneficiario: x.idBeneficiario?.tipoDocumento ?? null,
      mime: /^%PDF/.test(String.fromCharCode(...bytes.subarray(0, 4))) ? 'application/pdf' : 'application/octet-stream',
      base64: btoa(bin),
    });
  }

  return { docs };
}

/**
 * Pide al portal el PDF oficial de la declaración juramentada (el mismo del botón
 * "Generar Declaración Juramentada") y lo devuelve en base64 sin descargarlo: la
 * respuesta de `CmndGenerarPdfDeclaracion` ya trae el PDF, y se descarta el
 * manejador original, que lo guardaría en la carpeta de descargas.
 */
async function pCcfDeclaracion() {
  const boton = document.getElementById('btnPdfDeclaracionR');
  if (!boton) return { error: 'No estás en el paso Anexos: el botón de la declaración no existe.' };

  return new Promise(resolve => {
    const original = $.ajax;
    const fin = v => { $.ajax = original; clearTimeout(reloj); resolve(v); };
    const reloj = setTimeout(() => fin({ error: 'El portal no generó la declaración a tiempo.' }), 40000);
    $.ajax = function (o) {
      if (/CmndGenerarPdfDeclaracion/.test(String(o.url))) {
        o.success = r => {
          try {
            const x = typeof r === 'string' ? JSON.parse(r) : r;
            // `respuesta` viene como texto JSON {"encodedString": "<base64>"}.
            let b64 = x?.respuesta;
            if (typeof b64 === 'string' && b64.trim().startsWith('{')) { try { b64 = JSON.parse(b64).encodedString; } catch { /* queda el texto */ } }
            else if (b64 && typeof b64 === 'object') b64 = b64.encodedString;
            fin(b64 ? { base64: b64 } : { error: 'El portal no devolvió el PDF.' });
          } catch (e) { fin({ error: String(e) }); }
        };
      }
      return original.apply(this, arguments);
    };
    boton.click();
  });
}

/**
 * Adjunta el PDF firmado en cada fila "Formato declaración juramentada caja" que
 * aún no tenga archivo. El portal sube el archivo al cambiar el input.
 */
async function pCcfSubirDeclaracion(base64, nombre) {
  const bin = atob(base64);
  const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
  const filas = [...document.querySelectorAll('#tablaAnexosBeneficiario tbody tr')]
    .filter(tr => /juramentada/i.test(tr.innerText));
  const pendientes = filas.filter(tr => !([...tr.querySelectorAll('td')][2]?.innerText || '').trim());
  let subidos = 0;

  for (const tr of pendientes) {
    const input = tr.querySelector('input[type=file]');
    if (!input) continue;
    const dt = new DataTransfer();
    dt.items.add(new File([bytes], nombre, { type: 'application/pdf' }));
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    subidos++;
    await new Promise(r => setTimeout(r, 2500));
  }

  await new Promise(r => setTimeout(r, 2000));
  const quedan = [...document.querySelectorAll('#tablaAnexosBeneficiario tbody tr')]
    .filter(tr => /juramentada/i.test(tr.innerText))
    .filter(tr => !([...tr.querySelectorAll('td')][2]?.innerText || '').trim()).length;

  return { subidos, pendientes: pendientes.length, quedan };
}

function pCcfEstado() {
  if (!/comfenalcovalle/.test(location.host)) return { sesion: false, enLogin: true, pagina: 'login' };
  const login = /index\.html/.test(location.pathname) || !!document.querySelector('#btnLoginAuth0');
  let empresa = null;
  try { empresa = (JSON.parse(localStorage.getItem('empresa') || 'null') || {}).razonSocial || null; } catch { /* sin empresa */ }
  if (!empresa) empresa = document.querySelector('#btnEmpresa, .nombre-empresa')?.innerText?.trim() || $('#txtRazonSocal').val() || null;
  return { sesion: !!localStorage.getItem('usuario') && !login, empresa, pagina: location.pathname.split('/').pop() };
}

async function atenderCcfcv(accion, d = {}) {
  if (accion === 'ccfAbrir') {
    let p = await pestanaCcfcv();
    if (p) {
      await chrome.tabs.update(p.id, { active: true });
      await chrome.windows.update(p.windowId, { focused: true });
    } else {
      p = await chrome.tabs.create({ url: CCFCV_LOGIN, active: true });
      await esperarCarga(p.id);
    }
    // Si ya hay sesión no se toca nada; si está el login de AuthComfe, se deja escrito el usuario.
    const estado = await ejecutar(p.id, pCcfEstado).catch(() => ({ sesion: false }));
    if (!estado.sesion && d.usuario) {
      if (!/authcomfeempresasprod/.test((await chrome.tabs.get(p.id)).url || '')) {
        await chrome.tabs.update(p.id, { url: CCFCV_LOGIN });
        await esperarCarga(p.id);
      }
      await esperarQue(p.id, (u, c) => {
        const campo = document.querySelector('input[name=email], input[type=email]');
        if (!campo) return false;
        const poner = (e, v) => {
          const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
          set.call(e, v);                               // React/Angular solo ven el valor si se pone así
          e.dispatchEvent(new Event('input', { bubbles: true }));
          e.dispatchEvent(new Event('change', { bubbles: true }));
        };
        poner(campo, u);
        const clave = document.querySelector('input[name=password], input[type=password]');
        if (clave && c) poner(clave, c); else clave?.focus();
        return true;
      }, [String(d.usuario), d.contrasena ? String(d.contrasena) : ''], 20000);

      // Con la clave a mano, entrar del todo: hasta ahora se dejaba el
      // formulario lleno y alguien pulsaba. AuthComfe es un login normal de
      // correo y contraseña —sin código ni imagen que descifrar—, así que la
      // sesión se abre sola y la revisión de subsidios puede correr sin nadie.
      if (d.contrasena) return ccfEntrar(p.id, String(d.usuario), String(d.contrasena));
    }
    return { ok: true, abierta: true };
  }

  const pestana = await pestanaCcfcv();
  if (!pestana) return { ok: true, abierta: false, sesion: false };

  if (accion === 'ccfEstado') {
    let e;
    try { e = await ejecutar(pestana.id, pCcfEstado); } catch { e = { sesion: false }; }
    return { ok: true, abierta: true, ...e };
  }
  if (accion === 'ccfConsultar') return ccfConsultar(pestana, d);
  if (accion === 'ccfPaso') return { ok: true, ...(await ejecutar(pestana.id, pCcfPaso, [d])) };
  if (accion === 'ccfResultado') return { ok: true, ...(await ejecutar(pestana.id, pCcfResultado)) };
  if (accion === 'ccfDocumentos') return { ok: true, ...(await ejecutar(pestana.id, pCcfDocumentos)) };
  if (accion === 'ccfDeclaracion') return { ok: true, ...(await ejecutar(pestana.id, pCcfDeclaracion)) };
  if (accion === 'ccfSubirDeclaracion') return { ok: true, ...(await ejecutar(pestana.id, pCcfSubirDeclaracion, [d.base64, d.nombre || 'DeclaracionJuramentada.pdf'])) };
  if (accion === 'ccfTrabajadores') return ccfTrabajadores(pestana);
  if (accion === 'ccfMorosos') return ccfMorosos(pestana);
  if (accion === 'ccfGrupoFamiliar') return ccfGrupoFamiliar(pestana, d);

  throw new Error(`Acción de Comfenalco desconocida: ${accion}`);
}

/**
 * Pulsa INICIAR SESIÓN y espera a estar dentro.
 *
 * AuthComfe es una app aparte (Firebase) que, al validar, devuelve a la
 * Sucursal Virtual; la señal de que se entró es el `usuario` en localStorage
 * del portal, no la pantalla de AuthComfe.
 */
async function ccfEntrar(tab, usuario, contrasena) {
  // El autocompletado de Chrome puede pisar el correo con el de otra empresa
  // después de llenarlo (pasó con ELITES sobre GAVI). Justo antes de pulsar se
  // revisa el valor y, si no es el esperado, se vuelve a escribir.
  const pulsar = () => esperarQue(tab, (u, c) => {
    const campo = document.querySelector('input[name=email], input[type=email]');
    const clave = document.querySelector('input[name=password], input[type=password]');
    const btn = [...document.querySelectorAll('button,input[type=submit]')]
      .find(b => /iniciar sesi/i.test(b.innerText || b.value || ''));
    if (!campo || !clave || !btn) return false;
    const poner = (e, v) => {
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(e, v);
      e.dispatchEvent(new Event('input', { bubbles: true }));
      e.dispatchEvent(new Event('change', { bubbles: true }));
    };
    if (campo.value !== u) poner(campo, u);
    if (clave.value !== c) poner(clave, c);
    if (btn.disabled || campo.value !== u || clave.value !== c) return false;
    btn.click();

    return true;
  }, [usuario, contrasena], 20000);

  const pulsado = await pulsar();

  if (!pulsado) return { ok: false, abierta: true, error: 'No se pudo llenar el acceso de Comfenalco con el usuario y la clave de la empresa (el botón no se habilitó o Chrome cambió el correo).' };

  let reintento = false;
  const inicio = Date.now();

  const limite = Date.now() + 90000;

  while (Date.now() < limite) {
    await esperar(2000);

    const estado = await ejecutar(tab, pCcfEstado).catch(() => null);
    if (estado?.sesion) return { ok: true, abierta: true, sesion: true, empresa: estado.empresa };

    if (!reintento && Date.now() - inicio > 15000 && estado?.enLogin) {
      reintento = true;
      await pulsar();
    }

    const error = await ejecutar(tab, () =>
      /contrase|incorrect|inv[aá]lid|no existe/i.test(document.body.innerText || '')
        ? (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 200)
        : null).catch(() => null);

    if (error && /incorrect|inv[aá]lid|no existe/i.test(error)) {
      return { ok: false, abierta: true, error: 'Comfenalco rechazó el usuario o la clave guardada en BryNex.' };
    }
  }

  const pantalla = await ejecutar(tab, () => ({
    donde: location.host + location.pathname,
    texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 250),
  })).catch(() => null);

  return {
    ok: false,
    abierta: true,
    error: 'No se llegó a abrir la sesión de Comfenalco.'
      + (pantalla ? ` Quedó en ${pantalla.donde}: "${pantalla.texto}"` : ''),
  };
}

/**
 * Los trabajadores que la caja reporta morosos o con inexactitud.
 *
 * Es el equivalente de los bloqueos de subsidio de Comfandi, pero esta consulta
 * es **por empresa**: una sola pantalla cubre la nómina entera, en vez de los
 * diez segundos por persona del otro portal. Por eso, si responde, todos los
 * candidatos quedan revisados y a quien no aparezca se le puede cerrar la tarea.
 *
 * Lo hace la extensión y no el servidor porque el portal tiene un WAF que
 * bloquea a cualquier navegador automatizado —da igual la IP: se probó desde
 * netcup y desde una conexión de Cali—, mientras que en el navegador de la
 * persona, con su sesión, entra sin problema.
 */
async function ccfMorosos(pestana) {
  const tab = pestana.id;
  const est = await ejecutar(tab, pCcfEstado).catch(() => ({ sesion: false }));

  if (!est.sesion) return { ok: false, error: 'El portal de Comfenalco no tiene la sesión iniciada.' };

  await chrome.tabs.update(tab, { url: `${CCFCV_BASE}/consultaTrabajadorMoroso.html` });
  await esperarCarga(tab);

  const listo = await esperarQue(tab, () => !!document.getElementById('cmbSucursalEmpresa'), [], 30000);
  if (!listo) return { ok: false, error: 'No cargó la consulta de trabajadores morosos.' };

  await esperar(1500);

  // El NIT de la sesión, para que BryNex sepa de qué empresa son estas filas y
  // avise si no es la que se escogió.
  const empresa = await ejecutar(tab, () => ({
    nit: (document.getElementById('txtNumDocumentoEmp')?.value || '').replace(/\D/g, ''),
    razon: document.getElementById('txtRazonSocal')?.value || null,
  })).catch(() => ({ nit: '', razon: null }));

  const sucursales = await ejecutar(tab, () =>
    [...document.querySelectorAll('#cmbSucursalEmpresa option')]
      .map(o => o.value).filter(v => v && v !== '-1')).catch(() => []);

  if (!sucursales.length) return { ok: false, error: 'La empresa no tiene sucursales en Comfenalco.' };

  const movimientos = [];

  for (const sucursal of sucursales) {
    await ejecutar(tab, (v) => {
      const s = document.getElementById('cmbSucursalEmpresa');
      s.value = v;
      // Los combos son Chosen: sin avisarle, el que manda sigue mostrando lo de
      // antes y la consulta sale con la sucursal vieja.
      if (window.$) $(s).trigger('change').trigger('chosen:updated');
      else s.dispatchEvent(new Event('change', { bubbles: true }));

      const btn = [...document.querySelectorAll('button')].find(b => /^\s*consultar\s*$/i.test((b.innerText || '').trim()));
      btn?.click();

      return true;
    }, [sucursal]).catch(() => null);

    await esperar(6000);

    const filas = await ejecutar(tab, () => {
      const leer = (id, origen) => [...document.querySelectorAll(`#${id} tbody tr`)]
        .map(r => [...r.querySelectorAll('td')].map(c => c.innerText.trim()))
        .filter(c => c.length >= 4)
        .map(c => ({ periodo: c[0], clase: c[1], documento: c[2], nombre: c[3], valor: c[4] || '0', origen }));

      return [...leer('tablaMorosidad', 'mora'), ...leer('tablaInexactitud', 'inexactitud')];
    }).catch(() => []);

    (filas || []).forEach(f => movimientos.push({ ...f, sucursal }));

    // El aviso de "no hay información" tapa la pantalla y deja la consulta
    // siguiente sin poder pulsarse.
    await ejecutar(tab, () => {
      [...document.querySelectorAll('button,a')]
        .filter(e => /^\s*cerrar\s*$/i.test((e.innerText || '').trim()))
        .forEach(e => e.click());
    }).catch(() => null);

    await esperar(800);
  }

  return { ok: true, nit: empresa.nit, empresa: empresa.razon || est.empresa, sucursales: sucursales.length, movimientos };
}

/** Busca al trabajador en "Realizar Afiliación" y devuelve lo que ofrece el portal. */
async function ccfConsultar(pestana, d) {
  const tab = pestana.id;
  const est = await ejecutar(tab, pCcfEstado);
  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el usuario de la empresa.' };

  if (!/consultarTrabajador/.test(est.pagina || '')) {
    await chrome.tabs.update(tab, { url: `${CCFCV_BASE}/consultarTrabajador.html` });
    await esperarCarga(tab);
    await esperar(2500);
  }
  const empresa = await ejecutar(tab, () => ({ nit: $('#txtNumDocumentoEmp').val(), razon: $('#txtRazonSocal').val() }));
  if (String(empresa.nit || '').replace(/\D/g, '') !== String(d.nit)) {
    return { ok: false, error: `El portal está abierto con el NIT ${empresa.nit} (${empresa.razon}), y el contrato es de ${d.empresa}. Entra con el usuario de esa empresa.` };
  }

  await ejecutar(tab, (tipo, doc) => {
    $('#cmbTipoDocumento').val(String(tipo)).trigger('change').trigger('chosen:updated');
    $('#txtNumDocumento').val(doc).trigger('change');
    $('#btnConsultar').click();
    return true;
  }, [d.tipoDoc, String(d.documento)]);

  const r = await esperarQue(tab, () => {
    const vis = e => !!(e.offsetWidth || e.offsetHeight);
    const modal = [...document.querySelectorAll('.jconfirm-content')].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim());
    if (modal.length) return { modal };
    const ops = [...document.querySelectorAll("#panelAcciones div[id*='opcion']")].filter(vis)
      .map(o => ({ id: o.id, texto: o.innerText.replace(/\s+/g, ' ').trim().slice(0, 160), boton: o.querySelector('a[id], button[id]')?.id }));
    if (!ops.length) return null;
    const familia = [...document.querySelectorAll('table')].filter(vis).find(t => /Parentesco/i.test(t.innerText));
    return { opciones: ops, nombre: ($('#txtNombres').val() || '') + ' ' + ($('#txtApellidos').val() || ''), familia: familia ? familia.innerText.replace(/\s+/g, ' ').trim().slice(0, 500) : null };
  }, [], 40000);

  if (!r) return { ok: false, error: 'El portal no respondió la consulta del trabajador.' };
  if (r.modal) return { ok: false, error: r.modal.join(' ') };

  return { ok: true, ...r };
}

/**
 * Llena el paso que esté a la vista. Se llama cada pocos segundos desde BryNex:
 * la persona pulsa Continuar y en el siguiente llamado se llena el paso nuevo.
 */
function pCcfPaso(d) {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
  const sel = (id, v) => { const e = document.getElementById(id); if (!e || v == null || v === '') return false; $(e).val(String(v)).trigger('change').trigger('chosen:updated'); return true; };
  const txt = (id, v) => { const e = document.getElementById(id); if (!e || !v || e.value) return false; $(e).val(v).trigger('change'); return true; };
  const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
  const porTexto = (id, texto, parcial = true) => {
    const s = document.getElementById(id); if (!s || !texto) return null;
    const t = norm(texto);
    const op = [...s.options].find(o => norm(o.text) === t)
      || (parcial ? [...s.options].find(o => o.value !== '-1' && (norm(o.text).startsWith(t) || t.startsWith(norm(o.text)))) : null)
      || (parcial ? [...s.options].find(o => o.value !== '-1' && norm(o.text).includes(t.split(' ')[0]) && t.split(' ')[0].length > 3) : null);
    if (!op) return null;
    $(s).val(op.value).trigger('change').trigger('chosen:updated');
    return op.text.trim();
  };
  const direccion = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();

  const paso = [...document.querySelectorAll('fieldset, .sf-step')].filter(vis)
    .map(f => f.querySelector('legend, h4, .titulo')?.innerText?.trim()).filter(Boolean)[0] || '';
  const hecho = [];
  const falta = [];

  if (/Personal/i.test(paso)) {
    // Cambiar el tipo de afiliado recarga y vacía la clase: solo se toca si hace falta.
    if ($('#cmbTipoAfiliadoPersonal').val() !== '1') sel('cmbTipoAfiliadoPersonal', 1);
    if ($('#cmbClasesAfiliado option').length > 1) {
      if ($('#cmbClasesAfiliado').val() !== '1') sel('cmbClasesAfiliado', 1);
      $('#cmbClasesAfiliado').val() === '1' ? hecho.push('tipo y clase de afiliado: Dependiente') : falta.push('clase de afiliado');
    }
    sel('cmbEstadosCivilPersonal', d.estadoCivil) && hecho.push('estado civil');
    sel('cmbPaisResidenciaPersonal', 42);
    if ($('#cmbDepartamentos option').length > 1) {
      const dep = porTexto('cmbDepartamentos', d.departamento);
      dep ? hecho.push('departamento: ' + dep) : falta.push('departamento ' + d.departamento);
    }
    if ($('#cmbMunicipioResidencia option').length > 1) {
      if (($('#cmbMunicipioResidencia').val() || '-1') === '-1') porTexto('cmbMunicipioResidencia', d.municipio);
      ($('#cmbMunicipioResidencia').val() || '-1') !== '-1'
        ? hecho.push('municipio: ' + $('#cmbMunicipioResidencia option:selected').text())
        : falta.push('municipio ' + d.municipio + ' (elígelo a mano en la lista)');
    }
    if ($('#BarrioResidencia option').length > 1 && ($('#BarrioResidencia').val() || '-1') === '-1') {
      const b = porTexto('BarrioResidencia', String(d.barrio || '').replace(/^(EL|LA|LOS|LAS)\s+/i, ''));
      b ? hecho.push('barrio: ' + b) : falta.push('barrio ' + (d.barrio || '—'));
    }
    txt('txtDireccionResidenciaPersonal', direccion(d.direccion)) && hecho.push('dirección: ' + direccion(d.direccion));
    txt('txtCelularPersonal', d.celular) && hecho.push('celular');
    txt('txtCorreoPersonal', d.correo) && hecho.push('correo');
    if (($('#cmbOrientacionSexualPersonal').val() || '-1') === '-1') sel('cmbOrientacionSexualPersonal', 4);
    if (($('#cmbFactorVulnerabilidadPersonal').val() || '-1') === '-1') sel('cmbFactorVulnerabilidadPersonal', 12);
    if (($('#cmbPertenenciaEtnicaPersonal').val() || '-1') === '-1') sel('cmbPertenenciaEtnicaPersonal', 7);
  } else if (/Laboral/i.test(paso)) {
    const suc = document.getElementById('cmbSucursalEmpresa');
    if (suc && suc.options.length > 1 && ($('#cmbSucursalEmpresa').val() || '-1') === '-1') { $(suc).val(suc.options[1].value).trigger('change').trigger('chosen:updated'); hecho.push('sucursal'); }
    txt('txtFechaIngresoLaboral', d.fechaIngreso) && hecho.push('fecha de ingreso: ' + d.fechaIngreso);
    sel('cmbTipoContratoLaboral', d.tipoContrato) && hecho.push('tipo de contrato');
    if (($('#txtCargoLaboral').val() || '-1') === '-1') {
      const c = porTexto('txtCargoLaboral', d.cargoTexto);
      c ? hecho.push('cargo: ' + c) : falta.push('cargo (búscalo en la lista: ' + d.cargoTexto + ')');
    }
    sel('cmbTipoSalarioLaboral', 23) && hecho.push('tipo de salario: Fijo');
    txt('txtValorSalarioBasicoLaboral', String(d.salario)) && hecho.push('salario');
    // El portal propone Kupi por defecto: se impone la forma elegida en BryNex.
    if (d.formaPago && $('#cmbFormaPagoLaboral').val() !== String(d.formaPago)) {
      sel('cmbFormaPagoLaboral', d.formaPago);
    }
    if (d.formaPago && $('#cmbFormaPagoLaboral').val() === String(d.formaPago)) {
      hecho.push('forma de pago del subsidio: ' + $('#cmbFormaPagoLaboral option:selected').text());
      // Daviplata: la cuenta es el celular de la persona (Kupi ya trae la cédula).
      if (String(d.formaPago) === '10') {
        const cta = document.getElementById('txtNumeroCuentaBancariaLaboral');
        if (cta && d.celular && cta.value !== String(d.celular)) { $(cta).val(String(d.celular)).trigger('input').trigger('change'); hecho.push('cuenta Daviplata: ' + d.celular); }
        if (($('#cmbTiposCuentaBancariaLaboral').val() || '-1') === '-1') sel('cmbTiposCuentaBancariaLaboral', 2);
      }
    }
  } else if (/Otro Empleador/i.test(paso)) {
    hecho.push('no aplica: continúa');
  } else if (/Beneficiarios/i.test(paso)) {
    // La caja trae su grupo familiar previo en `tableBeneficiarioReactiva`, todos con
    // "Excluir = No" (quedan incluidos). Se cruza con los de BryNex; lo que BryNex
    // tiene y la caja no, se agrega a mano (el formulario pide fechas que BryNex no guarda).
    const limpio = v => String(v || '').replace(/\D/g, '').replace(/^0+/, '');
    const enCaja = [...document.querySelectorAll('#tableBeneficiarioReactiva tbody tr')].map(tr => {
      const cels = [...tr.querySelectorAll('td')].map(td => td.innerText.replace(/\s+/g, ' ').trim());
      const doc = limpio((cels.find(c => /^[A-Z]{2}\.\d+/.test(c)) || '').replace(/^[A-Z]{2}\./, ''));
      return { doc, nombre: [cels[1], cels[2]].filter(Boolean).join(' '), excluir: tr.querySelector('select')?.value };
    }).filter(f => f.doc);
    const deBrynex = (d.listaBeneficiarios || []).map(b => ({ ...b, doc: limpio(b.documento) }));
    enCaja.forEach(f => hecho.push(`${f.nombre} (${f.doc}): ${f.excluir === 'N' ? 'incluido' : 'EXCLUIDO'} — ya estaba en la caja`));
    deBrynex.filter(b => !enCaja.some(f => f.doc === b.doc))
      .forEach(b => falta.push(`agrega a mano: ${b.nombre} (${b.tipo_doc} ${b.documento}, ${b.parentesco || '—'}) — no está en el grupo familiar de la caja`));
    enCaja.filter(f => !deBrynex.some(b => b.doc === f.doc))
      .forEach(f => falta.push(`${f.nombre} está en la caja pero no en BryNex: confirma si sigue a cargo`));
    if (!enCaja.length && !deBrynex.length) hecho.push('sin beneficiarios: continúa');
  } else if (/Conyuge|Cónyuge/i.test(paso)) {
    // La caja precarga a la pareja de su registro previo (nombre, cédula, fecha de inicio de la
    // relación, si trabaja). Regla de BryNex: siempre unión libre ("Compañero"), y si la caja no
    // trae fecha de inicio se propone 2 años antes del ingreso; si no trae "Trabaja", No.
    if (($('#tipoConyugue').val() || '-1') !== '8') { sel('tipoConyugue', 8); }
    $('#tipoConyugue').val() === '8' && hecho.push('tipo de pareja: Compañero (unión libre)');
    if (!$('#numDocConyu').val()) {
      falta.push('la pareja no viene en la caja: escribe su documento, nombre, sexo y nacimiento');
    } else {
      hecho.push(`pareja: ${[$('#primNombCony').val(), $('#primerApellCony').val()].filter(Boolean).join(' ')} (${$('#numDocConyu').val()})`);
    }
    if (!$('#fechaInicioMaritalConyugue').val() && d.fechaIngreso) {
      const f = new Date(d.fechaIngreso + 'T00:00:00'); f.setFullYear(f.getFullYear() - 2);
      const iso = f.toISOString().slice(0, 10);
      $('#fechaInicioMaritalConyugue').val(iso).trigger('change');
      hecho.push('inicio de la unión libre: ' + iso + ' (2 años antes del ingreso — confírmalo con el trabajador)');
    } else if ($('#fechaInicioMaritalConyugue').val()) {
      hecho.push('inicio de la unión libre (de la caja): ' + $('#fechaInicioMaritalConyugue').val());
    }
    if (($('#cmbTrabajaConyugue').val() || '-1') === '-1') { sel('cmbTrabajaConyugue', 22) && hecho.push('la pareja no labora'); }
    else hecho.push('¿la pareja trabaja?: ' + $('#cmbTrabajaConyugue option:selected').text());
  } else if (/Anexos/i.test(paso)) {
    const tabla = [...document.querySelectorAll('table')].filter(vis).find(t => /Obligatorio/i.test(t.innerText));
    const pendientes = tabla ? [...tabla.querySelectorAll('tbody tr')].filter(r => /SI/.test(r.cells[1]?.innerText || '') && !(r.cells[2]?.innerText || '').trim()).map(r => r.cells[0].innerText.trim()) : [];
    pendientes.length ? falta.push('adjunta: ' + pendientes.join(', ')) : hecho.push('anexos obligatorios completos');
  }

  const errores = [...document.querySelectorAll('.jconfirm-content')].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 300));
  return { paso, hecho, falta, errores };
}

/** Lee el mensaje de éxito con el número de formulario después de Finalizar Afiliación. */
function pCcfResultado() {
  const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
  const textos = [...document.querySelectorAll('.jconfirm-content, .jconfirm-box')].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const texto = [...new Set(textos)].join(' — ');
  const m = texto.match(/n[uú]mero de formulario:?\s*([0-9]{6,})/i) || texto.match(/formulario:?\s*([0-9]{6,})/i);
  if (!m && !/registrad|exito/i.test(texto)) return { radicado: false, texto };
  return { radicado: !!m, numero: m ? m[1] : null, texto: texto.slice(0, 1500) };
}

/** Lista de "Trabajadores por Empresa" para conciliar los radicados de caja. */
async function ccfTrabajadores(pestana) {
  const tab = pestana.id;
  const est = await ejecutar(tab, pCcfEstado);
  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el usuario de la empresa.' };

  await chrome.tabs.update(tab, { url: `${CCFCV_BASE}/consultaTrabajadoresEmpresa.html` });
  await esperarCarga(tab);
  await esperar(3000);

  const empresa = await ejecutar(tab, () => ({ nit: ($('#txtNumDocumentoEmp').val() || '').replace(/\D/g, ''), razon: $('#txtRazonSocal').val() }));
  if (!empresa.nit) return { ok: false, error: 'El portal no mostró la empresa de la sesión.' };

  await ejecutar(tab, () => { $('#btnConsultar').click(); return true; });
  const filas = await esperarQue(tab, () => {
    const vis = e => !!(e && (e.offsetWidth || e.offsetHeight));
    const modal = [...document.querySelectorAll('.jconfirm-content')].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim());
    if (modal.length) return { error: modal.join(' ') };
    const t = $('#tablaTrabajadores').DataTable();
    const datos = t ? t.rows().data().toArray() : [];
    if (!datos.length) return null;
    // Cuántas dice el DataTable que hay: si se leyeron menos, la lista está a
    // medias y no se puede concluir que a nadie le falta la afiliación.
    let total = datos.length;
    try { const i = t.page.info(); total = i.recordsTotal || i.recordsDisplay || datos.length; } catch { /* sin paginación */ }
    return {
      datos: datos.map(f => [...f].slice(0, 4).map(x => String(x).replace(/<[^>]*>/g, '').trim())),
      esperadas: total,
    };
  }, [], 60000);

  if (!filas) return { ok: false, error: 'El portal no devolvió la lista de trabajadores (¿la empresa no tiene afiliados?).' };
  if (filas.error) return { ok: false, error: filas.error };

  if (filas.datos.length < filas.esperadas) {
    return {
      ok: false,
      error: `Solo se pudieron leer ${filas.datos.length} de ${filas.esperadas} trabajadores afiliados. `
        + 'Con la lista a medias media empresa parecería no estar afiliada, así que no se concilió nada. Vuelve a intentar.',
    };
  }

  return { ok: true, nit: empresa.nit, empresa: empresa.razon, filas: filas.datos, completa: true };
}

// ── Caja Comfandi (Sucursal Virtual Empresas, Next.js) ───────────────────

const CFD_HOST = 'afiliaciones.sucursalcomfandi.com';
const CFD_BASE = `https://${CFD_HOST}/sakaar`;
const CFD_LOGOUT = 'https://iam.comfandi.com.co/auth/realms/empresas/protocol/openid-connect/logout';

/**
 * La pestaña del portal en la que se trabaja.
 *
 * Con dos pestañas abiertas del portal —lo normal cuando alguien deja una y el
 * proceso abre otra— la elección cambiaba de una llamada a la siguiente: se
 * escribía en una y se leía la otra, y el trámite parecía no avanzar. Ahora la
 * primera que se use queda apuntada y se conserva mientras exista.
 */
async function pestanaCfd() {
  const { cfdTab } = await chrome.storage.session.get('cfdTab').catch(() => ({}));

  if (cfdTab) {
    const viva = await chrome.tabs.get(cfdTab).catch(() => null);
    if (viva && /sucursalcomfandi|iam\.comfandi/.test(viva.url || '')) return viva;
  }

  const ps = await chrome.tabs.query({ url: [`https://${CFD_HOST}/*`, 'https://iam.comfandi.com.co/*'] });
  const elegida = ps.find(p => (p.url || '').includes(CFD_HOST)) || ps[0] || null;

  if (elegida) await chrome.storage.session.set({ cfdTab: elegida.id }).catch(() => null);

  return elegida;
}

function pCfdEstado() {
  if (!/sucursalcomfandi/.test(location.host)) return { sesion: false, enLogin: true, pagina: 'login', donde: location.host + location.pathname };
  const t = document.body.innerText || '';
  const m = t.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
  const enGuest = /\/guest/.test(location.pathname) || /Para acceder a tu cuenta/i.test(t);
  return { sesion: !!m && !enGuest, empresa: m ? m[1].trim() : null, pagina: location.pathname, donde: location.host + location.pathname };
}

/**
 * Entra a la Sucursal Virtual: tipo de documento, usuario, clave, el "Omitir
 * por ahora" del 2FA y la empresa.
 *
 * Antes esto se quedaba a medias —dejaba escrito el NIT y la persona terminaba
 * a mano—, y por eso la revisión de subsidios no podía correr de noche. El
 * portal encadena tres pantallas distintas después de Entrar (el 2FA, la
 * elección de empresa y el inicio), y cuál toca no se sabe de antemano: se
 * mira en qué está y se responde, hasta que aparece el "actualmente estás en".
 *
 * El login es Keycloak (PatternFly), no el react-select del portal: aquí el
 * tipo de documento sí se abre con un clic.
 */
async function cfdEntrar(tab, d) {
  const usuario = String(d.usuario || '').replace(/\D/g, '');
  if (!usuario || !d.contrasena) return { ok: false, abierta: true, error: 'Faltan el usuario o la clave de la empresa.' };

  // Siempre se pide un formulario recién hecho, aunque la pestaña ya muestre
  // uno. El de Keycloak lleva dentro un código de un solo uso con caducidad: si
  // la pestaña llevaba rato abierta en el login, al enviarlo el portal contesta
  // «la autenticación ha caducado» y el trámite se quedaba ahí, girando en
  // vacío hasta el minuto y medio. Reusar lo que hubiera ahorraba dos segundos
  // y costaba el login entero.
  const fresco = await cfdFormularioLogin(tab);
  if (!fresco) return { ok: false, abierta: true, error: 'No se pudo abrir el formulario de acceso de Comfandi.' };

  // El tipo de documento: el usuario de la empresa es el NIT, y con "CC" el
  // portal contesta "Documento o contraseña incorrectos" con la clave buena.
  //
  // Es un typeahead de PatternFly: la lista está siempre en el DOM pero oculta,
  // y un clic sintético no la despliega —solo uno de verdad—. Con `focus()` sí
  // se abre, y escribirle "NIT" la deja en una sola opción, que ya acepta el
  // clic. Pulsar el combo, como se hacía antes, no elegía nada.
  // Escribir y elegir, en la misma espera.
  //
  // Antes eran dos esperas encadenadas y la primera no podía cumplirse nunca:
  // se quedaba mirando el campo oculto, que solo cambia con el clic de la
  // SEGUNDA. Así que durante veinte segundos reescribía "NIT" en el buscador y
  // volvía a abrir la lista una y otra vez —lo que se veía en pantalla era el
  // desplegable abierto y el trámite parado— y solo al agotarse pasaba a
  // pulsar. Ahora, cada vuelta: si el oculto ya dice NIT, listo; si la opción
  // está en pantalla, se pulsa; y si no, se escribe (una sola vez, no en cada
  // vuelta).
  const tipoOk = await esperarQue(tab, () => {
    const oculto = document.querySelector('input[name=identification_type_up]');
    if (!oculto) return false;
    if (oculto.value === 'NIT') return true;

    const li = [...document.querySelectorAll('li')].find(e => e.offsetParent && /^\s*NIT\b/i.test(e.innerText || ''));
    if (li) {
      const destino = li.querySelector('button,a,span') || li;
      ['pointerdown', 'mousedown', 'mouseup', 'click']
        .forEach(t => destino.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      return false;                                          // se confirma leyendo el oculto en la vuelta siguiente
    }

    const caja = document.getElementById('identification_type_search')
      || [...document.querySelectorAll('input')].find(e => /tipo de documento/i.test(e.placeholder || ''));
    if (!caja) return false;

    if (caja.value !== 'NIT') {
      caja.focus();
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(caja, 'NIT');
      caja.dispatchEvent(new Event('input', { bubbles: true }));
    }
    return false;
  }, [], 25000);

  if (!tipoOk) {
    return { ok: false, abierta: true, avisoTipo: 'No se pudo escoger "NIT" en Tipo de documento: el portal cambió el formulario.' };
  }

  const enviado = await esperarQue(tab, (u, c) => {
    const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    const num = document.querySelector('input[name=identificationNumber]');
    const clave = document.querySelector('input[name=password]');
    const btn = document.querySelector('input[name=login]');
    if (!num || !clave || !btn) return false;

    set.call(num, u);
    num.dispatchEvent(new Event('input', { bubbles: true }));
    num.dispatchEvent(new Event('change', { bubbles: true }));
    set.call(clave, c);
    clave.dispatchEvent(new Event('input', { bubbles: true }));
    clave.dispatchEvent(new Event('change', { bubbles: true }));
    btn.click();
    return true;
  }, [usuario, String(d.contrasena)], 20000);

  if (!enviado) return { ok: false, abierta: true, error: 'No se encontró el formulario de acceso de Comfandi.' };

  const r = await cfdDespuesDeEntrar(tab, d);

  // El portal puede caducar el formulario igual —una pestaña vieja, el botón
  // pulsado dos veces—: en ese caso se pide uno nuevo y se reintenta una vez,
  // que es lo que haría una persona.
  if (r.caducado && !d._reintento) {
    return cfdEntrar(tab, { ...d, _reintento: true });
  }

  return r;
}

/**
 * Deja la pestaña en un formulario de acceso recién cargado y devuelve si lo
 * consiguió. Se entra por `/guest` y su botón «Iniciar sesión», que es como
 * Keycloak reparte un código nuevo.
 */
async function cfdFormularioLogin(tab) {
  await chrome.tabs.update(tab, { url: `${CFD_BASE}/guest` });
  await esperarCarga(tab);

  await esperarQue(tab, () => {
    // El portal saluda con un modal («¡Bienvenido a la Sucursal Virtual
    // Empresas!») que tapa el botón de entrar. Se cierra primero, o el clic de
    // abajo se lo come el velo y el trámite se queda esperando el formulario.
    const velo = [...document.querySelectorAll('button')]
      .find(e => e.offsetParent && /^\s*aceptar\s*$/i.test((e.innerText || '').trim()));
    if (velo && /bienvenido a la sucursal/i.test(document.body.innerText || '')) {
      velo.click();
      return false;
    }

    const b = [...document.querySelectorAll('button,a')].find(e => /iniciar sesi/i.test(e.innerText || ''));
    if (!b) return false;
    b.click();
    return true;
  }, [], 20000);

  return esperarQue(tab, () =>
    !!document.querySelector('input[name=password]') && !!document.querySelector('input[name=identification_type_up]'),
  [], 25000);
}

/**
 * Las pantallas que el portal encadena después de Entrar, hasta quedar dentro.
 *
 * El 2FA se omite a propósito: con Authenticator haría falta un código del
 * teléfono en cada corrida y la revisión nocturna no podría existir.
 */
async function cfdDespuesDeEntrar(tab, d) {
  const nit = String(d.usuario || '').replace(/\D/g, '');
  const limite = Date.now() + 150000;

  while (Date.now() < limite) {
    await esperar(1500);

    const paso = await ejecutar(tab, (nitBuscado, nombre) => {
      const golpe = (e) => {
        ['pointerdown', 'mousedown', 'mouseup', 'click']
          .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
        if (typeof e.click === 'function') e.click();
      };
      const texto = document.body.innerText || '';

      if (/documento o contrase|credenciales inv[aá]lidas|usuario o contrase/i.test(texto)) return { fin: 'clave' };

      // Keycloak cuando el formulario enviado ya no vale: «la autenticación ha
      // caducado», «su sesión ha expirado». No es un error de clave y no se
      // arregla esperando: hay que pedir el formulario otra vez.
      if (/autenticaci[oó]n.{0,20}caducad|caducad.{0,20}autenticaci[oó]n|sesi[oó]n.{0,20}(caducad|expirad|venci)|authentication.{0,20}expired/i.test(texto)) {
        return { fin: 'caducado' };
      }

      // Ya dentro: el portal saluda con "actualmente estás en: EMPRESA".
      const dentro = texto.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
      if (dentro && !/\/guest/.test(location.pathname)) return { fin: 'dentro', empresa: dentro[1].trim() };

      // El 2FA con Authenticator: se omite. Son dos pantallas — la primera solo
      // avisa y ofrece "Continuar", y el "Omitir por ahora" está en la
      // siguiente, con el código QR.
      const botones = [...document.querySelectorAll('button,a,input[type=submit],[role=button]')]
        .filter(e => (e.innerText || e.value || '').trim());

      const omitir = botones.find(e => /omitir|m[aá]s tarde|ahora no|despu[eé]s/i.test(e.innerText || e.value || ''));
      if (omitir) { golpe(omitir); return { paso: '2fa-omitir' }; }

      const continuar = botones.find(e => /^\s*continuar\s*$/i.test((e.innerText || e.value || '').trim()));
      if (continuar) { golpe(continuar); return { paso: '2fa-continuar' }; }

      // La empresa. Ya no es una rejilla de tarjetas con el NIT: es un
      // desplegable «Selecciona tu empresa» y dentro un botón con el nombre —y
      // solo el nombre—. Buscar el NIT ahí no encontraba nada y el portal se
      // quedaba con el aviso «Aún no tiene permisos para gestionar
      // afiliaciones», que no es de permisos: es que falta elegir la empresa.
      const MENU = /^(inicio|ir al inicio|gesti[oó]n de trabajadores|radicados|certificados de empresa|actualizar datos de empresa|administraci[oó]n de usuarios|cerrar sesi[oó]n|selecciona tu empresa|aceptar)$/i;

      const opciones = () => [...document.querySelectorAll('button,[role=button],li,div[class*=card]')]
        .filter(e => {
          const t = (e.innerText || '').replace(/\s+/g, ' ').trim();
          return e.offsetParent && t && t.length < 200 && !MENU.test(t);
        });

      const suya = (e) => {
        const t = (e.innerText || '').trim();
        return t.replace(/\D/g, '').includes(nitBuscado.slice(0, 9))
          || (nombre && t.toUpperCase().includes(String(nombre).toUpperCase().slice(0, 12)));
      };

      const abridor = [...document.querySelectorAll('button,[role=button]')]
        .find(e => e.offsetParent && /selecciona tu empresa/i.test(e.innerText || ''));

      let lista = opciones();

      // Cerrado no hay nada que pulsar: primero se abre.
      if (abridor && !lista.length) { golpe(abridor); return { paso: 'empresa-abrir' }; }

      // Con el nombre o el NIT se acierta; con una sola opción, esa es.
      const elegida = lista.find(suya) || (lista.length === 1 ? lista[0] : null);
      if (elegida) { golpe(elegida); return { paso: 'empresa' }; }

      // Algunos portales piden confirmar después de elegir.
      const aceptar = [...document.querySelectorAll('button,input[type=submit]')]
        .find(e => e.offsetParent && /^\s*aceptar\s*$/i.test((e.innerText || e.value || '').trim()));
      if (aceptar) { golpe(aceptar); return { paso: 'empresa-aceptar' }; }

      return { paso: 'esperando', pagina: location.pathname };
    }, [nit, d.empresa || ''], []).catch(() => ({ paso: 'cargando' }));

    if (paso?.fin === 'clave') {
      return { ok: false, abierta: true, error: 'Comfandi rechazó el usuario o la clave guardada en BryNex.' };
    }

    if (paso?.fin === 'caducado') {
      return {
        ok: false,
        abierta: true,
        caducado: true,
        error: 'El portal dijo que la autenticación caducó: el formulario de acceso llevaba rato abierto. Se vuelve a intentar con uno nuevo.',
      };
    }

    if (paso?.fin === 'dentro') {
      return { ok: true, abierta: true, sesion: true, empresa: paso.empresa };
    }
  }

  const estado = await ejecutar(tab, pCfdEstado).catch(() => ({ sesion: false }));
  if (estado.sesion) return { ok: true, abierta: true, sesion: true, empresa: estado.empresa };

  // Sin esto el fallo era mudo y había que adivinar en qué pantalla se quedó.
  const pantalla = await ejecutar(tab, () => ({
    donde: location.host + location.pathname,
    texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300),
    // Qué se podía pulsar: sin esto, un botón con otro nombre deja el trámite
    // parado sin decir cuál era.
    clicables: [...document.querySelectorAll('button,a,input[type=submit],[role=button]')]
      .map(e => (e.innerText || e.value || '').replace(/\s+/g, ' ').trim())
      .filter(Boolean).slice(0, 12),
  })).catch(() => null);

  return {
    ok: false,
    abierta: true,
    error: 'Se entró al portal pero no se llegó a la empresa.'
      + (pantalla ? ` Quedó en ${pantalla.donde}: "${pantalla.texto}" · botones: ${pantalla.clicables.join(' | ')}` : ''),
  };
}

async function atenderCfd(accion, d = {}) {
  if (accion === 'cfdAbrir') {
    // Cerrar la sesión antes de entrar. No se hace solo: se pide desde BryNex
    // cuando hace falta. El portal se degrada tras varias afiliaciones seguidas
    // —empieza a pedir la empresa en cada pantalla y acaba diciendo «Sin
    // permisos para esta empresa», que no es verdad— y solo se le pasa con una
    // sesión nueva. Se cierra en Keycloak, que es donde vive de verdad: salir
    // solo del sakaar deja el SSO en pie y se vuelve a entrar con la sesión
    // cansada.
    if (d.reiniciar) {
      const p0 = await pestanaCfd();
      if (p0) {
        await chrome.tabs.update(p0.id, { url: `${CFD_LOGOUT}?client_id=afiliaciones-empresas` });
        await esperarCarga(p0.id);
        await esperar(2500);
      }
    }

    let p = await pestanaCfd();
    if (p) {
      await chrome.tabs.update(p.id, { active: true });
      await chrome.windows.update(p.windowId, { focused: true });
    } else {
      p = await chrome.tabs.create({ url: `${CFD_BASE}/guest`, active: true });
      await chrome.storage.session.set({ cfdTab: p.id }).catch(() => null);
      await esperarCarga(p.id);
    }

    const estado = await ejecutar(p.id, pCfdEstado).catch(() => ({ sesion: false }));
    if (estado.sesion) return { ok: true, abierta: true, sesion: true, empresa: estado.empresa };

    if (!d.usuario) return { ok: true, abierta: true };

    return cfdEntrar(p.id, d);
  }

  const pestana = await pestanaCfd();
  if (!pestana) return { ok: true, abierta: false, sesion: false };

  if (accion === 'cfdEstado') {
    let e;
    try { e = await ejecutar(pestana.id, pCfdEstado); } catch { e = { sesion: false }; }
    return { ok: true, abierta: true, ...e };
  }
  if (accion === 'cfdConsultar') return cfdConsultar(pestana, d);
  if (accion === 'cfdLlenar') return { ok: true, ...(await ejecutar(pestana.id, pCfdLlenar, [d])) };
  if (accion === 'cfdFinalizar') return cfdFinalizar(pestana.id);
  if (accion === 'cfdResultado') {
    const r = await ejecutar(pestana.id, pCfdResultado);
    if (!r.buscarEnRadicados) return { ok: true, ...r };

    const hallado = await cfdRadicadoDe(pestana.id, String(d.documento || ''));

    return hallado
      ? { ok: true, radicado: true, numero: hallado.numero, estadoPortal: hallado.estado, recuperado: r.motivo, texto: r.texto }
      : { ok: true, ...r };
  }
  if (accion === 'cfdTrabajadores') return cfdTrabajadores(pestana);
  if (accion === 'cfdListado') return cfdListado(pestana);
  if (accion === 'cfdEmpresa') return { ok: true, ...(await cfdNitEmpresa(pestana.id) || {}) };
  if (accion === 'cfdSubsidios') return cfdSubsidios(pestana, d);

  throw new Error(`Acción de Comfandi desconocida: ${accion}`);
}

/**
 * Abre Afiliación individual y consulta al trabajador. Antes mira el Listado de
 * trabajadores: si ya está afiliado no hay nada que radicar, y eso se avisa.
 */
async function cfdConsultar(pestana, d) {
  const tab = pestana.id;
  let est = await ejecutar(tab, pCfdEstado);

  // «Sin sesión» y «con la sesión abierta pero sin empresa escogida» se ven
  // igual desde fuera: en los dos casos falta el «actualmente estás en». Y lo
  // segundo pasa cada pocas afiliaciones. Antes de rendirse, se intenta
  // escoger la empresa, que es lo que de verdad suele faltar.
  if (!est.sesion && await cfdEmpresaSiLaPide(tab, d.nit, d.empresa)) {
    est = await ejecutar(tab, pCfdEstado);
  }

  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el NIT de la empresa y selecciona la empresa.' };

  const norm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (est.empresa && norm(est.empresa).slice(0, 12) && !norm(d.empresa).startsWith(norm(est.empresa).slice(0, 12))) {
    return { ok: false, error: `El portal está abierto con ${est.empresa} y el contrato es de ${d.empresa}. Cambia de empresa arriba o entra con ese NIT.` };
  }

  // ¿Ya está en el listado de trabajadores?
  //
  // Por el menú, no por la URL: las guardas de Next.js rebotan al inicio
  // cualquier navegación que no venga de dentro, y entonces el formulario no
  // aparecía y todo terminaba en «No apareció el cuadro de tipo y número de
  // documento», que no dice nada de lo que pasó.
  await cfdEmpresaSiLaPide(tab, d.nit, d.empresa);

  if (!await cfdIr(tab, 'workers', d.nit, d.empresa)) {
    return { ok: false, error: 'No se pudo abrir «Gestión de trabajadores» en el portal.' };
  }
  const ya = await ejecutar(tab, (doc) => {
    const f = [...document.querySelectorAll('tr')].find(e => (e.innerText || '').replace(/\s/g, '').includes(doc));
    if (!f) return null;
    const c = [...f.querySelectorAll('td')].map(x => x.innerText.trim());
    return { nombre: c[1] || '', desde: c[4] || c[3] || '' };
  }, [String(d.documento)]).catch(() => null);
  if (ya) return { ok: true, yaAfiliado: true, nombre: ya.nombre, desde: ya.desde };

  if (!await cfdAbrirIndividual(tab, d.nit, d.empresa)) {
    return { ok: false, error: 'No se pudo abrir «Afiliar trabajador» en el portal: quedó en otra pantalla.' };
  }

  const puesto = await esperarQue(tab, (tipo, doc) => {
    const s = document.getElementById('selectdocument'), i = document.getElementById('inputdocument');
    if (!s || !i) return false;
    const set = (e, v) => {
      const p = e instanceof HTMLSelectElement ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(p, 'value').set.call(e, v);
      e.dispatchEvent(new Event('input', { bubbles: true }));
      e.dispatchEvent(new Event('change', { bubbles: true }));
    };
    set(s, tipo); set(i, doc);
    return true;
  }, [String(d.tipoDoc), String(d.documento)], 25000);
  if (!puesto) return { ok: false, error: 'No apareció el cuadro de tipo y número de documento.' };

  await ejecutar(tab, () => {
    const b = [...document.querySelectorAll('button')].find(x => /Afiliar trabajador/i.test(x.innerText));
    if (!b) return { __error: 'No se encontró el botón Afiliar trabajador.' };
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  });

  // Ventana con la empresa y el NIT. El botón dice "Continuar" o "Confirmar"
  // según el caso (15-sep-2026: "Continuar" con alguien nuevo, "Confirmar" con
  // quien ya estuvo afiliado a Comfandi antes).
  const confirmar = await esperarQue(tab, () => {
    const b = [...document.querySelectorAll('button')].find(x => /^(Continuar|Confirmar)$/i.test(x.innerText.trim()));
    if (!b) return null;
    const texto = document.body.innerText.replace(/\s+/g, ' ');
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return { texto: texto.slice(0, 400) };
  }, [], 45000);
  if (!confirmar) {
    const msg = await ejecutar(tab, () => document.body.innerText.replace(/\s+/g, ' ').slice(0, 300)).catch(() => '');
    return { ok: false, error: 'El portal no ofreció Continuar ni Confirmar tras la consulta. ' + msg };
  }

  await esperar(3000);
  const datos = await esperarQue(tab, () => {
    const i = [...document.querySelectorAll('input')].find(e => e.placeholder === 'Primer nombre');
    const a = [...document.querySelectorAll('input')].find(e => e.placeholder === 'Primer apellido');
    return i && i.value ? { nombre: `${i.value} ${a?.value || ''}`.trim(), precargado: i.disabled } : null;
  }, [], 30000);

  return { ok: true, yaAfiliado: false, nombre: datos?.nombre || '', precargado: !!datos?.precargado };
}

/**
 * Llena el formulario de Afiliación individual. Es idempotente: BryNex lo llama
 * cada pocos segundos, así que solo toca lo que siga vacío.
 */
function pCfdLlenar(d) {
  const MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
  const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
  const set = (e, v) => {
    if (!e || v == null || v === '') return false;
    const p = e instanceof HTMLSelectElement ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(p, 'value').set.call(e, String(v));
    e.dispatchEvent(new Event('input', { bubbles: true }));
    e.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  const porPlaceholder = ph => [...document.querySelectorAll('input')].find(e => e.placeholder === ph);
  const combo = etiqueta => [...document.querySelectorAll('select')].find(s => (s.options[0]?.text || '').trim() === etiqueta);

  const hecho = [], falta = [];
  const texto = (ph, v, nombre) => { const e = porPlaceholder(ph); if (e && !e.disabled && !e.value && set(e, v)) hecho.push(nombre); };
  const elegir = (etiqueta, v, nombre) => {
    const s = combo(etiqueta); if (!s || s.value || v == null || v === '') return;
    if (![...s.options].some(o => o.value === String(v))) { falta.push(`${nombre}: el portal no tiene el valor ${v}`); return; }
    set(s, v) && hecho.push(nombre);
  };
  const elegirTexto = (etiqueta, t, nombre) => {
    const s = combo(etiqueta); if (!s || s.value || !t) return;
    const q = norm(t);

    // El cargo de BryNex está en singular y en el género de la persona
    // («SECRETARIA») y la lista CIUO en masculino plural («4120 - Secretarios
    // generales»), así que hay que recortar la terminación para encontrarlo.
    //
    // Pero solo con la PRIMERA palabra no vale: «OPERARIO DE CONFECCION» caía
    // en «Operarios en cemento armado», el primer CIUO que dice «operario», y
    // así se afiliaba a una costurera como obrera de construcción (Daniela
    // Ariza, 28-sep-2026, visto antes de enviar). Una palabra genérica
    // —operario, auxiliar, técnico…— no identifica un oficio.
    //
    // Por eso, cuando el cargo tiene varias palabras, se exige que la opción
    // case con la parte que de verdad lo distingue; y si no hay nada claro se
    // para y se pide ayuda, que es preferible a radicar un oficio falso.
    const raiz = (p) => p.replace(/(ES|AS|OS|A|O|E)$/, '');
    const VAGAS = /^(OPERARIO|OPERARIA|OPERARIOS|AUXILIAR|AYUDANTE|TECNICO|TECNICA|ASISTENTE|OPERADOR|OPERADORA|JEFE|SUPERVISOR|TRABAJADOR|EMPLEADO|PERSONAL)$/;

    const palabras = q.split(' ').filter(p => p.length > 3 && !/^(DE|DEL|LA|LAS|LOS|EN|Y|PARA)$/.test(p));
    const clave = palabras.find(p => !VAGAS.test(p)) || palabras[0] || q;

    const busca = (p) => [...s.options].find(o => o.value && norm(o.text).includes(p));

    const op = [...s.options].find(o => norm(o.text) === q)
      || [...s.options].find(o => o.value && norm(o.text).includes(q))
      || (clave.length > 3 ? busca(clave) : null)
      || (raiz(clave).length > 4 ? busca(raiz(clave)) : null);

    if (!op) { falta.push(`${nombre}: no se encontró "${t}" en la lista, escógelo tú`); return; }
    set(s, op.value) && hecho.push(`${nombre}: ${op.text.trim()}`);
  };

  if (!document.getElementById('inputdocument') && !porPlaceholder('Primer nombre')) {
    return { paso: 'Sin formulario a la vista', hecho, falta: ['Abre Afiliación individual'], errores: [] };
  }

  // La identidad la suele precargar la Registraduría al consultar el documento,
  // pero no siempre responde (Erwing Torres, 28-sep-2026): sin nombre, apellido
  // ni nacimiento el portal no deja radicar. Se rellena con lo de BryNex solo si
  // quedó vacío, para no pisar lo que traiga la Registraduría, que manda.
  const idFalta = [
    ['Primer nombre', d.primerNombre, 'primer nombre'],
    ['Segundo nombre (opcional)', d.segundoNombre, 'segundo nombre'],
    ['Primer apellido', d.primerApellido, 'primer apellido'],
    ['Segundo apellido (opcional)', d.segundoApellido, 'segundo apellido'],
  ].filter(([ph]) => { const e = porPlaceholder(ph); return e && !e.value; });

  idFalta.forEach(([ph, valor, nombre]) => { if (valor) texto(ph, valor, nombre + ' (de BryNex)'); });

  const nac = porPlaceholder('Fecha de nacimiento');
  if (nac && !nac.value && d.nacimiento) {
    const comoLoQuiere = d.nacimiento.split('-').reverse().join(' | ');
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    nac.focus();
    setter.call(nac, comoLoQuiere);
    nac.dispatchEvent(new Event('input', { bubbles: true }));
    nac.dispatchEvent(new Event('change', { bubbles: true }));
    nac.blur();
    if (nac.value) hecho.push('fecha de nacimiento: ' + nac.value + ' (de BryNex)');
  }

  elegir('Selecciona Género', d.genero, 'género');
  elegir('Selecciona Estado civil', d.estadoCivil, 'estado civil');
  elegir('Selecciona Orientación sexual', d.orientacion, 'orientación sexual');
  elegir('Selecciona Nivel académico', d.nivel, 'nivel académico');
  elegir('Selecciona Nacionalidad', d.nacionalidad, 'nacionalidad');
  elegir('Selecciona Factor de Vulnerabilidad', d.vulnerabilidad, 'factor de vulnerabilidad');
  elegir('Selecciona Pertenencia Étnica', d.etnia, 'pertenencia étnica');
  elegir('Selecciona Ciudad', d.ciudad, 'ciudad');
  elegir('Selecciona Departamento', d.departamento, 'departamento');
  texto('Dirección del trabajador:', d.direccion, 'dirección: ' + d.direccion);
  texto('Celular', d.celular, 'celular');
  texto('Email', d.correo, 'correo');
  elegir('Selecciona Horas diarias contratadas', d.horas, 'horas diarias');
  elegir('Selecciona Tipo salario', d.tipoSalario, 'tipo de salario');
  texto('Sueldo declarado', String(d.salario), 'sueldo');
  elegir('Selecciona Tipo de contrato', d.tipoContrato, 'tipo de contrato');
  elegirTexto('Selecciona Ocupación', d.ocupacionTexto, 'ocupación');
  elegir('Selecciona Ciudad donde labora el trabajador', d.ciudadLabor, 'ciudad donde labora');

  // La fecha de ingreso es un react-multi-date-picker. Su calendario NO se abre
  // con un clic sintético —solo con uno de verdad—, y por eso el trámite se
  // quedaba pidiendo que la pusiera la persona.
  //
  // Pero el campo sí acepta que se le escriba: con el setter nativo y un
  // 'input', el componente parsea DD/MM/AAAA y lo guarda en su estado (probado
  // en el portal: memoizedState y las props del input quedan con la fecha). Así
  // que primero se escribe, y el calendario queda de respaldo por si alguna
  // pantalla sí lo abre.
  const fecha = porPlaceholder('Fecha de ingreso');
  // El componente guarda «23 | 09 | 2026», con esos separadores: es lo que
  // queda en el campo al escoger el día en el calendario, y es el formato que
  // su máscara reconoce. Con barras el texto se veía bien pero el formulario
  // seguía dándolo por vacío.
  const comoTexto = d.fechaIngreso ? d.fechaIngreso.split('-').reverse().join(' | ') : '';

  if (fecha && !fecha.value && d.fechaIngreso) {
    const [anio, mes, dia] = d.fechaIngreso.split('-').map(Number);
    const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;

    fecha.focus();
    set.call(fecha, comoTexto);
    fecha.dispatchEvent(new Event('input', { bubbles: true }));
    fecha.dispatchEvent(new Event('change', { bubbles: true }));
    fecha.blur();

    if (!document.querySelector('.rmdp-wrapper, .rmdp-calendar')) {
      fecha.focus();
      ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => fecha.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    }
    const w = document.querySelector('.rmdp-wrapper, .rmdp-calendar');
    if (fecha.value) {
      hecho.push('fecha de ingreso: ' + fecha.value);
    } else if (!w) {
      falta.push('fecha de ingreso: no se pudo escribir ni abrir el calendario; escoge el ' + comoTexto + ' a mano');
    } else {
      const cab = (w.querySelector('.rmdp-header')?.innerText || '').replace(/\s+/g, ' ').trim();
      const m = cab.match(/([A-Za-zÁÉÍÓÚáéíóú]+)[, ]+(\d{4})/);
      if (m) {
        const actual = MESES.indexOf(norm(m[1])) + 1;
        const saltos = (Number(m[2]) - anio) * 12 + (actual - mes);
        if (saltos !== 0) {
          const flecha = w.querySelector(saltos > 0 ? '.rmdp-left' : '.rmdp-right');
          (flecha?.querySelector('span') || flecha)?.click();
          falta.push('fecha de ingreso: buscando ' + comoTexto + ' en el calendario');
        } else {
          const celda = [...w.querySelectorAll('.rmdp-day')]
            .filter(x => !/rmdp-deactive|rmdp-disabled/.test(x.className))
            .find(x => x.innerText.trim() === String(dia));
          if (!celda) falta.push('fecha de ingreso: el calendario no deja escoger ese día, escógelo tú');
          else { (celda.querySelector('span') || celda).click(); hecho.push('fecha de ingreso: ' + comoTexto); }
        }
      }
    }
  } else if (fecha && fecha.value) {
    hecho.push('fecha de ingreso: ' + fecha.value.replace(/\s/g, ''));
  }

  const errores = [...new Set([...document.querySelectorAll('*')]
    .filter(e => e.children.length === 0 && /obligatori|inv[aá]lid|no es v[aá]lid/i.test(e.innerText || ''))
    .map(e => e.innerText.trim()).filter(t => t.length < 120))];

  // Los obligatorios que siguen vacíos, leídos de los campos y no de los
  // carteles: el portal deja «La fecha de ingreso es obligatoria» puesto aunque
  // la fecha esté, y fiarse de eso haría esperar para siempre —o, peor, enviar
  // un formulario a medias, que fue lo que pasó con Erwing Torres—.
  [
    ['Primer nombre', 'primer nombre'],
    ['Primer apellido', 'primer apellido'],
    ['Fecha de nacimiento', 'fecha de nacimiento'],
    ['Dirección del trabajador:', 'dirección'],
    ['Celular', 'celular'],
    ['Email', 'correo'],
    ['Fecha de ingreso', 'fecha de ingreso'],
    ['Sueldo declarado', 'sueldo'],
  ].forEach(([ph, nombre]) => {
    const e = porPlaceholder(ph);
    if (e && !String(e.value || '').trim()) falta.push(nombre + ': el portal lo dejó vacío');
  });

  return { paso: 'Afiliación individual', hecho, falta, errores: errores.slice(0, 6) };
}

/** Lee el número de radicado (002-002-…) después de Finalizar. */
function pCfdResultado() {
  const t = (document.body.innerText || '').replace(/\s+/g, ' ');
  const m = t.match(/\b(\d{3}-\d{3}-\d{6,})\b/);
  const exito = /exitos|radicad[oa]|registrad[oa]|recibimos tu solicitud/i.test(t);

  // Comfandi a veces contesta «Tu solicitud de afiliación no pudo ser radicada
  // / No se pudo generar el radicado» y sin embargo la radica: al reintentar
  // avisa de que esa cédula «ya se encuentra con un radicado en proceso»
  // (28-sep-2026, Nidia Belalcázar, 002-002-00450631). Falla al MOSTRAR el
  // número, no al crearlo. Se avisa para ir a buscarlo a Radicados en vez de
  // dar el trámite por perdido.
  const sinNumero = /no se pudo generar el radicado|no pudo ser radicada/i.test(t);
  const yaExiste = /ya se encuentra con un radicado en proceso/i.test(t);

  if (!m && (sinNumero || yaExiste)) {
    return { radicado: false, buscarEnRadicados: true, motivo: yaExiste ? 'ya-existe' : 'sin-numero', texto: t.slice(0, 600) };
  }

  if (!m && !exito) return { radicado: false };

  return { radicado: !!m, numero: m ? m[1] : null, texto: t.slice(0, 1200) };
}

/**
 * Lee una tabla del portal recorriendo TODAS sus páginas.
 *
 * Las tablas de Comfandi paginan de a 5 y el selector de tamaño es un combo
 * propio, así que en vez de pelearse con él se pulsa "Siguiente" hasta que la
 * primera fila deja de cambiar. `columnas` dice qué celdas llevarse y en qué
 * orden; el resto se descarta (la de "Detalle" es un icono).
 */
async function cfdTabla(tab, columnas, limitePaginas = 80) {
  // 50 por página en vez de 5: menos vueltas y menos ocasiones de perder una.
  await ejecutar(tab, () => {
    const o = [...document.querySelectorAll('option,li,div,span,button')]
      .filter(e => e.children.length === 0)
      .find(e => /Visualizando\s*50/i.test(e.innerText || ''));
    if (!o) return false;
    if (o.tagName === 'OPTION' && o.parentElement?.tagName === 'SELECT') {
      const s = o.parentElement;
      Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set.call(s, o.value);
      s.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => o.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }).catch(() => false);
  await esperar(2500);

  // Cuántas filas y páginas dice el portal que hay: es la única forma de saber
  // si al final se leyó todo o se perdió una página por el camino.
  const esperado = await ejecutar(tab, () => {
    const t = document.body.innerText || '';
    const reg = t.match(/N[uú]mero de registros\s*(\d+)/i);
    const pag = t.match(/Pagina\s*(\d+)?\s*de\s*(\d+)/i);
    return { registros: reg ? +reg[1] : null, paginas: pag ? +pag[2] : null };
  }).catch(() => ({ registros: null, paginas: null }));

  const leer = (distintaDe) => esperarQue(tab, (cols, previa) => {
    const t = document.querySelector('table');
    if (!t) return null;
    const tr = [...t.querySelectorAll('tbody tr')];
    if (!tr.length) return previa === null ? { datos: [], huella: 'vacia' } : null;
    const datos = tr.map(f => {
      const c = [...f.querySelectorAll('td')].map(x => (x.innerText || '').replace(/\s+/g, ' ').trim());
      return cols.map(i => c[i] ?? '');
    });
    const huella = JSON.stringify(datos[0] || []);
    if (previa !== null && huella === previa) return null;   // todavía es la anterior
    return { datos, huella };
  }, [columnas, distintaDe ?? null], distintaDe ? 20000 : 30000);

  const filas = [];
  let huella = null;
  let paginas = 0;

  for (let i = 0; i < limitePaginas; i++) {
    const pagina = await leer(huella);
    if (!pagina) break;                              // no cambió: era la última
    huella = pagina.huella;
    paginas++;
    filas.push(...pagina.datos);

    if (esperado.registros && filas.length >= esperado.registros) break;

    const hay = await ejecutar(tab, () => {
      const b = [...document.querySelectorAll('button')].find(x => /^\s*Siguiente\s*$/i.test(x.innerText) && !x.disabled);
      if (!b || b.getAttribute('aria-disabled') === 'true') return false;
      ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      return true;
    }).catch(() => false);
    if (!hay) break;
  }

  // Completa solo si se leyeron todas las filas (o todas las páginas) que el
  // portal declara. Sin esa comprobación, una tabla a medias hace creer que
  // media empresa no está afiliada.
  const completa = esperado.registros ? filas.length >= esperado.registros
    : (esperado.paginas ? paginas >= esperado.paginas : true);

  return { filas, completa, esperadas: esperado.registros ?? null };
}

/**
 * Va a una pantalla del portal pulsando su enlace del menú.
 *
 * Es un Next.js con guardas: pedir la URL directamente rebota al inicio
 * (`/affiliations`) cuando la navegación no viene de dentro. Por eso se pulsa
 * el enlace del menú lateral, y solo si no está se cae a la URL.
 */
async function cfdIr(tab, ruta, nit, empresa) {
  const destino = `/sakaar/${ruta}`;

  const pulsado = await ejecutar(tab, (d) => {
    if (location.pathname === d) return true;
    const a = document.querySelector(`a[href="${d}"]`);
    if (!a) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => a.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [destino]).catch(() => false);

  if (!pulsado) {
    await chrome.tabs.update(tab, { url: `https://${CFD_HOST}${destino}` });
    await esperarCarga(tab);
  }

  // Llegó cuando la ruta es la pedida; si rebotó al inicio, se reintenta desde allí.
  let llego = await esperarQue(tab, (d) => location.pathname === d, [destino], 25000);

  // El rebote casi siempre es que el portal volvió a pedir la empresa: pasa
  // cada pocas afiliaciones, sin avisar y con la sesión abierta. Se elige y se
  // vuelve a intentar una vez.
  if (!llego && await cfdEmpresaSiLaPide(tab, nit, empresa)) {
    await ejecutar(tab, (d) => {
      const a = document.querySelector(`a[href="${d}"]`);
      if (a) ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => a.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      return !!a;
    }, [destino]).catch(() => false);

    llego = await esperarQue(tab, (d) => location.pathname === d, [destino], 25000);
  }

  await esperar(2500);

  return !!llego;
}

/**
 * Pulsa Finalizar y confirma el sueldo, que son los dos clics con los que el
 * portal radica de verdad.
 *
 * Va aparte de `cfdLlenar` a propósito: llenar es reversible y esto no. Solo se
 * llama cuando BryNex ya dio el formulario por completo.
 *
 * Ojo con el cartel «La fecha de ingreso es obligatoria»: el portal lo deja en
 * pantalla aunque la fecha esté puesta y no impide nada. Quien manda es si
 * Finalizar abre la ventana de verificación del sueldo.
 */
async function cfdFinalizar(tab) {
  const pulsar = (texto) => esperarQue(tab, (t) => {
    const b = [...document.querySelectorAll('button,input[type=submit]')]
      .find(e => e.offsetParent && new RegExp('^\\s*' + t + '\\s*$', 'i').test((e.innerText || e.value || '').trim()));
    if (!b || b.disabled) return false;
    b.click();
    return true;
  }, [texto], 20000);

  if (!await pulsar('finalizar')) {
    return { ok: false, error: 'No se encontró el botón Finalizar en el formulario.' };
  }

  // La ventana del sueldo no siempre sale: si el portal se queja de algo, se
  // queda en el formulario con el mensaje arriba.
  const confirmo = await pulsar('confirmar');

  if (!confirmo) {
    const pantalla = await ejecutar(tab, () => ({
      texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 400),
    })).catch(() => null);

    return {
      ok: false,
      error: 'Se pulsó Finalizar pero el portal no pidió confirmar el sueldo.'
        + (pantalla ? ` Quedó en: "${pantalla.texto}"` : ''),
    };
  }

  await esperar(6000);

  // Se espera a que deje de procesar, para que quien lea el resultado no lo
  // pille a medias.
  await esperarQue(tab, () => !/procesando tu solicitud/i.test(document.body.innerText || ''), [], 60000);

  return { ok: true, enviado: true };
}

/**
 * Busca en Radicados el radicado de esa cédula, para cuando el portal lo crea
 * pero no enseña el número.
 */
async function cfdRadicadoDe(tab, documento) {
  const doc = String(documento).replace(/\D/g, '');
  if (!doc) return null;

  if (!await cfdIr(tab, 'filed')) return null;
  await cfdBuscar(tab);

  return ejecutar(tab, (buscado) => {
    const fila = [...document.querySelectorAll('tbody tr')]
      .map(t => (t.innerText || '').replace(/\s+/g, ' ').trim())
      .filter(t => t.replace(/\D/g, '').includes(buscado))
      .find(t => /afiliaci[oó]n individual/i.test(t));
    if (!fila) return null;

    const num = fila.match(/\b(\d{3}-\d{3}-\d{6,})\b/);
    if (!num) return null;

    const est = fila.match(/(En proceso|Procesado con [ÉE]xito|Rechazad[oa]|En validaci[oó]n)/i);
    return { numero: num[1], estado: est ? est[1] : null };
  }, [doc]).catch(() => null);
}

/**
 * Si el portal vuelve a pedir la empresa, la elige.
 *
 * No pasa solo al entrar: a mitad de sesión, al cambiar de pantalla, vuelve a
 * la bienvenida con «Selecciona la empresa con la que deseas trabajar» y un
 * «Aún no tiene permisos para gestionar afiliaciones» que asusta pero solo
 * significa que falta escogerla. Sin esto, el trámite moría ahí.
 */
async function cfdEmpresaSiLaPide(tab, nit, nombre) {
  const lapide = await ejecutar(tab, () =>
    /selecciona la empresa con la que deseas trabajar|no tiene permisos para gestionar/i.test(document.body.innerText || '')
    && !/actualmente est[aá]s en/i.test(document.body.innerText || '')
  ).catch(() => false);

  if (!lapide) return false;

  const limite = Date.now() + 40000;

  while (Date.now() < limite) {
    const listo = await ejecutar(tab, (nitBuscado, nombreBuscado) => {
      // El .click() nativo además de los eventos fabricados: la tarjeta de la
      // empresa no reaccionaba solo con MouseEvent —con el ratón de verdad sí—
      // y el trámite se quedaba en «selecciona tu empresa» sin poder salir.
      const golpe = (e) => {
        ['pointerdown', 'mousedown', 'mouseup', 'click']
          .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
        if (typeof e.click === 'function') e.click();
      };

      if (/actualmente est[aá]s en/i.test(document.body.innerText || '')) return true;

      const MENU = /^(inicio|ir al inicio|gesti[oó]n de trabajadores|radicados|certificados de empresa|actualizar datos de empresa|administraci[oó]n de usuarios|cerrar sesi[oó]n|selecciona tu empresa|aceptar)$/i;

      const opciones = [...document.querySelectorAll('button,[role=button],li,div[class*=card]')]
        .filter(e => {
          const t = (e.innerText || '').replace(/\s+/g, ' ').trim();
          return e.offsetParent && t && t.length < 200 && !MENU.test(t);
        });

      const abridor = [...document.querySelectorAll('button,[role=button]')]
        .find(e => e.offsetParent && /selecciona tu empresa/i.test(e.innerText || ''));
      if (abridor && !opciones.length) { golpe(abridor); return false; }

      const suya = opciones.find(e => {
        const t = (e.innerText || '').trim();
        return t.replace(/\D/g, '').includes(String(nitBuscado).slice(0, 9))
          || (nombreBuscado && t.toUpperCase().includes(String(nombreBuscado).toUpperCase().slice(0, 12)));
      }) || (opciones.length === 1 ? opciones[0] : null);
      if (suya) { golpe(suya); return false; }

      const aceptar = [...document.querySelectorAll('button,input[type=submit]')]
        .find(e => e.offsetParent && /^\s*aceptar\s*$/i.test((e.innerText || e.value || '').trim()));
      if (aceptar) { golpe(aceptar); return false; }

      return false;
    }, [String(nit || ''), String(nombre || '')]).catch(() => false);

    if (listo) return true;
    await esperar(1500);
  }

  return false;
}

/**
 * Deja la pestaña en el formulario de afiliación individual.
 *
 * `/sakaar/individual` no cuelga del menú lateral: se llega desde Gestión de
 * trabajadores, con el botón «Afiliar trabajador». Pedir la URL a pelo rebota
 * al inicio.
 */
async function cfdAbrirIndividual(tab, nit, empresa) {
  const yaEsta = await ejecutar(tab, () => location.pathname === '/sakaar/individual').catch(() => false);
  if (yaEsta) return true;

  await cfdEmpresaSiLaPide(tab, nit, empresa);

  if (!await cfdIr(tab, 'workers', nit, empresa)) return false;

  await esperarQue(tab, () => {
    if (location.pathname === '/sakaar/individual') return true;

    const b = [...document.querySelectorAll('button,a,[role=button]')]
      .find(e => e.offsetParent && /afiliar trabajador/i.test((e.innerText || '').replace(/\s+/g, ' ')));
    if (!b) return false;

    ['pointerdown', 'mousedown', 'mouseup', 'click']
      .forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return false;                                            // se confirma por la ruta
  }, [], 25000);

  const llego = await esperarQue(tab, () => location.pathname === '/sakaar/individual', [], 20000);
  await esperar(2500);

  return !!llego;
}

/**
 * El NIT de la empresa de la sesión, para amarrar la conciliación a la razón
 * social de BryNex.
 *
 * Primero se le pregunta al propio portal por su contexto de empresa, que es
 * como lo sabe él mismo; si de ahí no sale, se lee de la tabla de
 * Administración de usuarios, donde aparece junto a la razón social.
 */
async function cfdNitEmpresa(tab) {
  const porApi = await ejecutar(tab, async () => {
    try {
      const r = await fetch('/sakaar/api/company-context', { credentials: 'include' });
      if (!r.ok) return null;
      const j = await r.json();
      const texto = JSON.stringify(j);
      const m = texto.match(/"(?:nit|documentNumber|numeroDocumento|identification|identificationNumber)"\s*:\s*"?(\d{8,12})"?/i);
      const n = texto.match(/"(?:razonSocial|businessName|name|companyName)"\s*:\s*"([^"]{3,120})"/i);
      return m ? { nit: m[1], empresa: n ? n[1] : null } : null;
    } catch { return null; }
  }).catch(() => null);

  if (porApi?.nit) return porApi;

  if (!await cfdIr(tab, 'admin/users')) return null;

  return await esperarQue(tab, () => {
    const t = document.querySelector('table');
    const fila = t && [...t.querySelectorAll('tbody tr')].find(f => /\d{6,}/.test(f.innerText));
    const m = fila && fila.innerText.match(/\b(\d{8,12})\b/);
    const e = document.body.innerText.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
    return m ? { nit: m[1], empresa: e ? e[1].trim() : null } : null;
  }, [], 25000);
}

/** Listado de trabajadores + Radicados, para conciliar los radicados de caja. */
async function cfdTrabajadores(pestana) {
  const tab = pestana.id;
  let est = await ejecutar(tab, pCfdEstado);

  // «Sin sesión» y «con la sesión abierta pero sin empresa escogida» se ven
  // igual desde fuera: en los dos casos falta el «actualmente estás en». Y lo
  // segundo pasa cada pocas afiliaciones. Antes de rendirse, se intenta
  // escoger la empresa, que es lo que de verdad suele faltar.
  if (!est.sesion && await cfdEmpresaSiLaPide(tab, d.nit, d.empresa)) {
    est = await ejecutar(tab, pCfdEstado);
  }

  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el NIT de la empresa y selecciona la empresa.' };

  const empresa = await cfdNitEmpresa(tab);
  if (!empresa?.nit) {
    return { ok: false, error: 'No se pudo leer el NIT de la empresa. Comprueba que arriba diga "actualmente estás en" con la empresa seleccionada, abre "Administración de usuarios" en el menú y vuelve a intentar.' };
  }

  // Listado de trabajadores: [nombre, "CC 123", ingreso empresa, ingreso caja]
  // se reordena a [documento, nombre, ingreso empresa, ingreso caja].
  if (!await cfdIr(tab, 'workers')) return { ok: false, error: 'No se pudo abrir el Listado de trabajadores en el portal.' };
  const trabajadores = await cfdTabla(tab, [2, 1, 3, 4]);
  if (!trabajadores.completa) {
    return {
      ok: false,
      error: `Solo se pudieron leer ${trabajadores.filas.length} de ${trabajadores.esperadas ?? '?'} trabajadores del listado. `
        + 'Con la lista a medias media empresa parecería no estar afiliada, así que no se concilió nada. Vuelve a intentar.',
    };
  }
  const filas = trabajadores.filas;

  // Radicados: la tabla no carga sola, hay que pulsar Buscar. Si esto falla y
  // se devuelve una lista vacía, BryNex da por no radicado a quien sí lo está y
  // manda a radicarlo otra vez — por eso se espera al botón, se espera a la
  // respuesta y se informa si no se pudo leer.
  const enRadicados = await cfdIr(tab, 'filed');

  const pulsado = enRadicados && await esperarQue(tab, () => {
    const b = [...document.querySelectorAll('button')].find(x => /^\s*Buscar\s*$/i.test(x.innerText) && !x.disabled);
    if (!b) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 30000);

  // La consulta respondió cuando hay tabla con filas o cuando dice que no hay.
  const respondio = pulsado && await esperarQue(tab, () => {
    const t = document.querySelector('table');
    if (t && t.querySelectorAll('tbody tr').length) return 'con datos';
    return /No hay datos/i.test(document.body.innerText || '') ? 'sin datos' : null;
  }, [], 40000);

  const tablaRad = respondio === 'con datos' ? await cfdTabla(tab, [0, 2, 3, 4, 5, 6]) : { filas: [], completa: respondio === 'sin datos' };
  const radicados = tablaRad.filas;

  return {
    ok: true,
    nit: empresa.nit,
    empresa: empresa.empresa,
    filas,
    radicados,
    // false = no se pudo leer la pestaña entera; distinto de "la leí y está
    // vacía". Con la lista incompleta, quien no aparezca podría estar radicado.
    radicadosOk: !!respondio && tablaRad.completa,
  };
}

/**
 * Pide el "Listado de trabajadores" del portal y devuelve la URL firmada para
 * bajarlo.
 *
 * El Excel trae la empresa entera —y los beneficiarios de cada trabajador—, así
 * que evita raspar una tabla que pagina de a 5 y que con cualquier tropiezo
 * deja media empresa sin leer.
 *
 * El camino es: pedir el listado (queda como un radicado "Listado de
 * trabajadores"), esperar a que el portal lo procese, abrir su detalle y
 * capturar la ruta del archivo al pulsar "Documento Resultado". Con el token de
 * la sesión, `/sus/download` devuelve una URL de S3 firmada; esa es la que se
 * le pasa a BryNex, porque S3 no deja leerla desde el navegador.
 */
async function cfdListado(pestana) {
  const tab = pestana.id;

  const sesion = await ejecutar(tab, async () => {
    try {
      const s = await (await fetch('/sakaar/api/auth/session', { credentials: 'include' })).json();
      if (!s?.access_token) return null;
      const p = JSON.parse(atob(s.access_token.split('.')[1]));
      const e = (p.companies || [])[0] || {};
      return { hayToken: true, nit: e.identification || null, empresa: e.name || null, companyId: e.id || null };
    } catch { return null; }
  }).catch(() => null);

  if (!sesion?.hayToken) return { ok: false, error: 'No se pudo leer la sesión del portal. Entra con el NIT de la empresa y selecciona la empresa.' };

  if (!await cfdIr(tab, 'filed')) return { ok: false, error: 'No se pudo abrir la pestaña Radicados.' };
  await cfdBuscar(tab);

  let fila = await cfdFilaListado(tab);

  // Si no hay un listado de hoy, se pide uno nuevo y se espera a que salga.
  if (!fila) {
    if (!await cfdIr(tab, 'workers')) return { ok: false, error: 'No se pudo abrir Gestión de trabajadores para pedir el listado.' };
    const pedido = await esperarQue(tab, () => {
      const b = [...document.querySelectorAll('button,div,span')].filter(e => e.children.length === 0)
        .find(e => /^\s*Listado de trabajadores\s*$/i.test(e.innerText || ''));
      if (!b) return false;
      const c = b.closest('button') || b;
      ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => c.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      return true;
    }, [], 25000);
    if (!pedido) return { ok: false, error: 'No se encontró el botón "Listado de trabajadores" en Gestión de trabajadores.' };

    // El portal lo genera en unos segundos; se mira la pestaña Radicados hasta
    // que aparezca procesado.
    for (let i = 0; i < 10 && !fila; i++) {
      await esperar(6000);
      if (!await cfdIr(tab, 'filed')) break;
      await cfdBuscar(tab);
      fila = await cfdFilaListado(tab);
    }
    if (!fila) return { ok: false, error: 'Se pidió el listado pero el portal aún no lo ha procesado. Espera un momento y vuelve a intentar.' };
  }

  // Abre el detalle del radicado y captura la ruta del archivo.
  //
  // El nombre lleva la hora de generación ("..._2026-09-16_1036.xlsx"), así que
  // no se puede construir: hay que ver qué pide el portal. El espía se pone
  // ANTES de tocar nada —si se pone después, la llamada ya pasó— y cada paso
  // espera a que ocurra en vez de dormir un rato fijo.
  const ruta = await ejecutar(tab, async (numero) => {
    const golpe = (e) => ['pointerdown', 'mousedown', 'mouseup', 'click']
      .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));

    // Se vigilan fetch y XHR: no se sabe cuál usa cada versión del portal.
    if (!window.__cfdEspia) {
      window.__cfdEspia = { url: null };
      const of = window.fetch;
      window.fetch = function (...a) {
        try {
          const u = String(a[0]?.url || a[0]);
          if (u.includes('/sus/download')) window.__cfdEspia.url = u;
        } catch {}
        return of.apply(this, a);
      };
      const oo = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function (m, u) {
        try { if (String(u).includes('/sus/download')) window.__cfdEspia.url = String(u); } catch {}
        return oo.apply(this, arguments);
      };
    }
    window.__cfdEspia.url = null;

    const hasta = (fn, ms) => new Promise((res) => {
      const fin = Date.now() + ms;
      const tic = () => {
        let v = null;
        try { v = fn(); } catch {}
        if (v) return res(v);
        if (Date.now() > fin) return res(null);
        setTimeout(tic, 300);
      };
      tic();
    });

    const fila = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.includes(numero));
    if (!fila) return { error: 'no se encontró la fila del listado' };
    golpe(fila.querySelector('button'));

    // El modal llega cuando aparece el botón del documento.
    const doc = await hasta(() => [...document.querySelectorAll('*')]
      .filter(e => e.children.length === 0)
      .find(e => /Documento Resultado/i.test(e.innerText || '')), 20000);
    if (!doc) return { error: 'el detalle no mostró "Documento Resultado"' };

    golpe(doc.closest('button') || doc);

    const url = await hasta(() => window.__cfdEspia.url, 20000);

    return url ? { url } : { error: 'se pulsó el documento pero el portal no pidió la descarga' };
  }, [fila.numero]).catch((e) => ({ error: String(e?.message || e).slice(0, 150) }));

  if (!ruta?.url) {
    return { ok: false, error: `Se encontró el listado ${fila.numero} pero no se pudo obtener su archivo (${ruta?.error || 'sin detalle'}).` };
  }

  // Con el token de la sesión, el portal devuelve la URL firmada de S3.
  const firmada = await ejecutar(tab, async (url) => {
    try {
      const s = await (await fetch('/sakaar/api/auth/session', { credentials: 'include' })).json();
      const p = JSON.parse(atob(s.access_token.split('.')[1]));
      const e = (p.companies || [])[0] || {};
      const r = await fetch(url, { headers: { Accept: 'application/json', Authorization: 'Bearer ' + s.access_token, 'x-company-id': e.id } });
      if (!r.ok) return null;
      const t = (await r.text()).trim();
      return t.startsWith('http') ? t : null;
    } catch { return null; }
  }, [ruta.url]).catch(() => null);

  if (!firmada) return { ok: false, error: 'El portal no entregó el enlace de descarga del listado.' };

  return { ok: true, nit: sesion.nit, empresa: sesion.empresa, archivoUrl: firmada, radicadoListado: fila.numero, fecha: fila.fecha };
}

/** Pulsa Buscar en Radicados y espera la respuesta. */
async function cfdBuscar(tab) {
  const pulsado = await esperarQue(tab, () => {
    const b = [...document.querySelectorAll('button')].find(x => /^\s*Buscar\s*$/i.test(x.innerText) && !x.disabled);
    if (!b) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 30000);

  if (!pulsado) return false;

  return !!await esperarQue(tab, () => {
    const t = document.querySelector('table');
    if (t && t.querySelectorAll('tbody tr').length) return 'con datos';
    return /No hay datos/i.test(document.body.innerText || '') ? 'sin datos' : null;
  }, [], 40000);
}

/** El "Listado de trabajadores" procesado más reciente, si es de hoy. */
async function cfdFilaListado(tab) {
  return await ejecutar(tab, () => {
    const hoy = new Date();
    const dd = String(hoy.getDate()).padStart(2, '0') + '/' + String(hoy.getMonth() + 1).padStart(2, '0') + '/' + hoy.getFullYear();
    const f = [...document.querySelectorAll('tbody tr')].find(r => {
      const t = r.innerText;
      return /Listado de trabajadores/i.test(t) && /Procesado con [Éé]xito/i.test(t) && t.includes(dd);
    });
    if (!f) return null;
    const c = [...f.querySelectorAll('td')].map(x => (x.innerText || '').replace(/\s+/g, ' ').trim());
    return { numero: c[0], fecha: c[4] || null };
  }).catch(() => null);
}

/**
 * Bloqueos de subsidio monetario de una lista de trabajadores.
 *
 * El portal solo responde de a una persona: hay que buscarla en Gestión de
 * trabajadores, entrar a "Subsidio monetario" y consultar el rango. Por eso
 * BryNex no manda a todos los afiliados sino a los sospechosos del día — quien
 * pagó con mora, quien ya tiene tarea abierta y los recién afiliados— y aquí se
 * recorren uno por uno.
 *
 * Detrás hay un API (`ria.sucursalcomfandi.com/monetary-subsidy/getMovements`),
 * pero rechaza los tokens de la sesión del navegador: la llamada la hace el
 * servidor del portal con uno propio. Mientras eso siga así, se lee la pantalla.
 *
 * `revisados` son las cédulas que sí se alcanzaron a consultar. BryNex solo
 * cierra tareas de esas: un bloqueo que nadie miró no está resuelto.
 */
async function cfdSubsidios(pestana, d = {}) {
  const tab = pestana.id;
  let est = await ejecutar(tab, pCfdEstado);

  // «Sin sesión» y «con la sesión abierta pero sin empresa escogida» se ven
  // igual desde fuera: en los dos casos falta el «actualmente estás en». Y lo
  // segundo pasa cada pocas afiliaciones. Antes de rendirse, se intenta
  // escoger la empresa, que es lo que de verdad suele faltar.
  if (!est.sesion && await cfdEmpresaSiLaPide(tab, d.nit, d.empresa)) {
    est = await ejecutar(tab, pCfdEstado);
  }

  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada. Entra con el NIT de la empresa y selecciona la empresa.' };

  const empresa = await cfdNitEmpresa(tab);
  if (!empresa?.nit) return { ok: false, error: 'No se pudo leer el NIT de la empresa seleccionada en el portal.' };

  const documentos = (d.documentos || []).map(x => String(x).replace(/\D/g, '')).filter(Boolean);
  if (!documentos.length) return { ok: false, error: 'No llegó ninguna cédula para consultar.' };

  // Cuántos meses atrás se pide. El portal abre en el mes en curso y los
  // bloqueos se acumulan del ciclo anterior, así que por defecto van cuatro.
  const meses = Math.max(1, Math.min(12, parseInt(d.meses) || 4));

  const movimientos = [];
  const revisados = [];
  const errores = [];

  for (const documento of documentos) {
    try {
      const filas = await cfdBloqueosDe(tab, documento, meses);
      if (filas === null) {
        errores.push({ documento, error: 'No se pudo abrir su subsidio monetario.' });
        continue;
      }
      revisados.push(documento);
      filas.forEach(f => movimientos.push({ ...f, documento }));
    } catch (e) {
      errores.push({ documento, error: String(e?.message || e).slice(0, 150) });
    }
  }

  return { ok: true, nit: empresa.nit, empresa: empresa.empresa, movimientos, revisados, errores };
}

/**
 * Elige una opción de un combo del portal.
 *
 * Los filtros de Comfandi son react-select: el input va siempre vacío aunque
 * haya valor —el elegido se lee del contenedor del control—, no tienen
 * placeholder, y un clic sintético no despliega el menú. La flecha abajo sí lo
 * abre, y ya desplegado el clic sobre la opción vale. Mirar `input.value`, como
 * se hacía antes, daba el combo por vacío para siempre y la búsqueda no salía
 * nunca de la primera pantalla.
 *
 * @param tab       pestaña del portal
 * @param opcion    texto de la opción, tal como la lista el portal
 * @param cual      índice del combo en la pantalla (casi siempre hay uno solo)
 */
async function cfdElegirCombo(tab, opcion, cual = 0) {
  const elegido = await esperarQue(tab, (texto, i) => {
    const combos = [...document.querySelectorAll('input[role=combobox]')];
    const combo = combos[i];
    if (!combo) return false;

    const re = new RegExp('^\\s*' + texto, 'i');
    if (re.test(combo.closest('[class*=control]')?.innerText || '')) return 'ya';

    combo.focus();
    combo.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: 'ArrowDown', code: 'ArrowDown', keyCode: 40, which: 40 }));
    return 'abierto';
  }, [opcion, cual], 15000);

  if (!elegido) return false;
  if (elegido === 'ya') return true;

  await esperar(600);

  return !!await esperarQue(tab, (texto) => {
    const re = new RegExp('^\\s*' + texto, 'i');
    const o = [...document.querySelectorAll('[class*=option]')].find(e => re.test(e.innerText || ''));
    if (!o) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => o.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [opcion], 10000);
}

/**
 * Los bloqueos de un trabajador, o null si no se pudo llegar a su pantalla.
 *
 * El camino es siempre el mismo: buscarlo en el listado, "Gestionar", "Subsidio
 * monetario", escoger el tipo de búsqueda, correr la fecha inicial hacia atrás
 * y pulsar Buscar.
 */
async function cfdBloqueosDe(tab, documento, meses) {
  if (!await cfdIr(tab, 'workers')) return null;

  // Buscar al trabajador. Sin tipo de documento el portal no filtra —saca la
  // lista entera paginada— y el de al lado saldría como "sin bloqueos".
  if (!await cfdElegirCombo(tab, 'C[ée]dula de Ciudadan')) return null;

  const buscado = await esperarQue(tab, (doc) => {
    const golpe = (e) => ['pointerdown', 'mousedown', 'mouseup', 'click']
      .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    const num = [...document.querySelectorAll('input')].find(i => /documento del trabajador/i.test(i.placeholder || ''));
    if (!num) return false;

    const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    set.call(num, doc);
    num.dispatchEvent(new Event('input', { bubbles: true }));

    const btn = [...document.querySelectorAll('button')].find(b => /^\s*Buscar\s*$/i.test(b.innerText));
    if (!btn) return false;
    golpe(btn);
    return true;
  }, [documento], 25000);

  if (!buscado) return null;

  // Su fila y, dentro, el botón Gestionar → "Subsidio monetario".
  const entro = await esperarQue(tab, (doc) => {
    const fila = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.replace(/\D/g, '').includes(doc));
    if (!fila) return false;
    const b = fila.querySelector('button');
    if (!b) return false;
    b.click();
    return true;
  }, [documento], 20000);

  if (!entro) return null;

  const abrio = await esperarQue(tab, () => {
    const e = [...document.querySelectorAll('button,div,span')].filter(x => x.children.length === 0)
      .find(x => /^\s*Subsidio monetario\s*$/i.test(x.innerText || ''));
    if (!e) return false;
    const c = e.closest('button') || e;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => c.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 20000);

  if (!abrio) return null;

  // La pantalla de subsidio: tipo de búsqueda "Bloqueos" y fecha inicial atrás.
  const listo = await esperarQue(tab, () => {
    const ins = [...document.querySelectorAll('input')];
    return ins.some(i => /fecha inicial/i.test(i.placeholder || '')) ? true : false;
  }, [], 25000);

  if (!listo) return null;

  if (!await cfdElegirCombo(tab, 'Bloqueos de subsidio')) return null;

  await esperar(600);

  // La fecha inicial es un react-datepicker: se abre con un clic, se retrocede
  // con su flecha y se pulsa el día 1. Escribirle el texto no sirve.
  await ejecutar(tab, async (n) => {
    const espera = ms => new Promise(r => setTimeout(r, ms));
    const campo = [...document.querySelectorAll('input')].find(i => /fecha inicial/i.test(i.placeholder || ''));
    if (!campo) return false;
    campo.focus();
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => campo.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    await espera(600);
    for (let i = 0; i < n; i++) {
      document.querySelector('.react-datepicker__navigation--previous')?.click();
      await espera(250);
    }
    const dia = [...document.querySelectorAll('.react-datepicker__day:not(.react-datepicker__day--outside-month)')]
      .find(e => e.innerText.trim() === '1');
    dia?.click();
    return true;
  }, [meses]).catch(() => null);

  await esperar(600);

  const respondio = await esperarQue(tab, () => {
    const btn = [...document.querySelectorAll('button')].find(b => /^\s*Buscar\s*$/i.test(b.innerText));
    if (!btn) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => btn.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 20000);

  if (!respondio) return null;

  // Resultado: tarjetas con período, fecha, motivo y valor, o el "¡Upss!" de
  // que no hay movimientos — que también es una respuesta válida.
  const filas = await esperarQue(tab, () => {
    const texto = document.body.innerText || '';
    if (/no se encontraron movimientos/i.test(texto)) return { vacio: true, filas: [] };

    const tarjetas = [...document.querySelectorAll('*')]
      .filter(e => e.children.length === 0 && /Periodo de bloqueo/i.test(e.innerText || ''))
      .map(e => e.closest('div[class*=border], div[class*=rounded], li, article') || e.parentElement);

    const vistos = new Set();
    const filas = [];

    for (const t of tarjetas) {
      const txt = (t?.innerText || '').replace(/\s+/g, ' ').trim();
      if (!txt || vistos.has(txt)) continue;
      vistos.add(txt);
      const periodo = txt.match(/Periodo de bloqueo:\s*([^|]+?)\s*(?:\||Fecha)/i);
      const fecha = txt.match(/Fecha de Bloqueo:\s*([0-9]{1,2}\s+\w+\s+[0-9]{4})/i);
      const motivo = txt.match(/Motivo de Bloqueo:\s*(.+?)\s*$/i);
      const valor = txt.match(/-?\$\s*([\d.,]+)/);
      filas.push({
        tipo: 'BLOQUEO',
        periodo: periodo ? periodo[1].trim() : '',
        fecha: fecha ? fecha[1].trim() : null,
        motivo: motivo ? motivo[1].trim() : '',
        valor: valor ? valor[1] : '0',
      });
    }

    return filas.length ? { vacio: false, filas } : null;
  }, [], 30000);

  if (!filas) return null;

  return filas.filas;
}

// ── Fondo de Solidaridad Pensional: certificado del PSAP ─────────────────
//
// La consulta es pública pero tiene reCAPTCHA, así que desde el servidor no se
// puede pedir. La extensión deja escritos el tipo y el número; la persona marca
// el captcha y la extensión envía el formulario ella misma con fetch para
// quedarse con el PDF y dárselo a BryNex, en vez de que se descargue.
//
// El formulario es JSF/PrimeFaces sin AJAX: la respuesta es el PDF o la misma
// página con un Growl que dice por qué no (p. ej. "el documento X no se
// encuentra en el programa de PSAP"). Los ids son autogenerados (j_idt19…), por
// eso los campos se buscan por su forma y no por el id.

const FSP_CERTIFICADO = 'https://nelfsp.equiedad.com.co:8001/faces/GenerarCertificadoPsapCAPTCHA.xhtml';

async function pestanaFsp() {
  // Los patrones de URL no admiten puerto: este coincide con el :8001.
  const ps = await chrome.tabs.query({ url: 'https://nelfsp.equiedad.com.co/*' });
  return ps.find(p => (p.url || '').includes('GenerarCertificadoPsap')) || null;
}

async function atenderFsp(accion, d = {}) {
  if (accion === 'fspAbrir') {
    let p = await pestanaFsp();
    if (p) {
      // Se recarga para arrancar con un captcha sin usar.
      const carga = esperarCarga(p.id);
      await chrome.tabs.update(p.id, { url: FSP_CERTIFICADO, active: true });
      await carga;
    } else {
      p = await chrome.tabs.create({ url: FSP_CERTIFICADO, active: true });
      await esperarCarga(p.id);
    }
    await chrome.windows.update(p.windowId, { focused: true });

    const lleno = await esperarQue(p.id, pFspLlenar, [d.tipoDoc || 'CC', String(d.documento || '')], 20000);
    if (!lleno) return { ok: false, error: 'La página del Fondo no mostró el formulario del certificado.' };
    return { ok: true };
  }

  if (accion === 'fspCertificado') {
    const p = await pestanaFsp();
    if (!p) return { ok: false, cerrada: true, error: 'Se cerró la pestaña del Fondo de Solidaridad.' };
    return { ok: true, ...(await ejecutar(p.id, pFspCertificado, [String(d.documento || '')])) };
  }

  throw new Error(`Acción desconocida: ${accion}`);
}

function pFspLlenar(tipo, documento) {
  const form = document.querySelector('form[id*="consultabeneficiarios"]') || document.forms[0];
  const W = window.PrimeFaces?.widgets || {};
  const select = form?.querySelector('select');
  const oculto = form?.querySelector('input[type=hidden][name$="_hinput"]');
  if (!select || !oculto) return false;

  // Widgets de PrimeFaces: escribirle al <select> o al input a secas no cambia
  // lo que el formulario envía (el número vive en el oculto _hinput).
  const widget = (el, sufijo) => W['widget_' + el.id.replace(sufijo, '').replace(/:/g, '_')];
  const wTipo = widget(select, /_input$/);
  const wNumero = widget(oculto, /_hinput$/);
  if (!wTipo || !wNumero) return false;

  const tipos = [...select.options].map(o => o.value);
  const tipoPortal = { NUIP: 'NU', NU: 'NU', CE: 'CE', TI: 'TI' }[String(tipo).toUpperCase()] || 'CC';
  wTipo.selectValue(tipos.includes(tipoPortal) ? tipoPortal : 'CC');
  wNumero.setValue(documento);

  if (!document.getElementById('brynex-fsp-aviso')) {
    const aviso = document.createElement('div');
    aviso.id = 'brynex-fsp-aviso';
    aviso.textContent = 'BryNex: marca «No soy un robot». El certificado se guarda solo en BryNex, no hace falta pulsar Descargar.';
    aviso.style.cssText = 'margin:10px auto;max-width:520px;padding:10px 14px;border-radius:8px;background:#eff6ff;border:1px solid #93c5fd;color:#1e3a8a;font:14px sans-serif;text-align:center;';
    form.parentElement.insertBefore(aviso, form);
  }
  return oculto.value === documento;
}

async function pFspCertificado(documento) {
  const form = document.querySelector('form[id*="consultabeneficiarios"]') || document.forms[0];
  const token = form?.querySelector('[name="g-recaptcha-response"]')?.value || '';
  if (!token) return { listo: false };

  const oculto = form.querySelector('input[type=hidden][name$="_hinput"]');
  if (documento && oculto && oculto.value !== documento) {
    return { listo: true, mensaje: `En la pestaña del Fondo quedó escrito otro documento (${oculto.value}). Vuelve a pedir el certificado desde BryNex.` };
  }

  const aviso = document.getElementById('brynex-fsp-aviso');
  const avisar = (texto, color) => { if (aviso) { aviso.textContent = texto; aviso.style.color = color; } };

  const datos = new FormData(form);
  const boton = form.querySelector('button[type=submit]');
  if (boton?.name) datos.append(boton.name, '');

  try {
    const r = await fetch(form.action, { method: 'POST', body: new URLSearchParams(datos), credentials: 'include' });
    const bytes = new Uint8Array(await r.arrayBuffer());
    // Un captcha sirve para una sola consulta.
    try { window.grecaptcha?.reset(); } catch { /* sin captcha a la vista */ }

    if (bytes.length > 4 && String.fromCharCode(...bytes.slice(0, 4)) === '%PDF') {
      let bin = '';
      for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
      const nombre = (r.headers.get('content-disposition') || '').match(/filename="?([^";]+)/i)?.[1] || null;
      avisar('BryNex: certificado recibido. Ya puedes cerrar esta pestaña.', '#166534');
      return { listo: true, pdf: btoa(bin), nombre };
    }

    const html = new TextDecoder('utf-8').decode(bytes);
    const detalles = [...html.matchAll(/detail:"((?:[^"\\]|\\.)*)"/g)].map(m => m[1].replace(/\\(.)/g, '$1'));
    const mensaje = detalles.join(' · ') || `El Fondo no entregó el certificado (respuesta ${r.status}).`;
    avisar('BryNex: ' + mensaje, '#991b1b');
    return { listo: true, mensaje };
  } catch (e) {
    return { listo: true, mensaje: 'No se pudo pedir el certificado al Fondo: ' + (e?.message || e) };
  }
}

/**
 * Grupo familiar de cada trabajador afiliado en Comfenalco.
 *
 * A diferencia de Comfandi, que entrega toda la empresa en un Excel, aquí hay
 * que preguntar uno por uno en "Afiliación grupo familiar": la consulta recibe
 * un documento y devuelve la tabla `tablaGrupo` con el cotizante y sus
 * beneficiarios. Por eso se hace aparte de la conciliación y no en cada corrida.
 *
 * La tabla trae [documento "CC - 123", nombre, parentesco, edad, calidad,
 * categoría]; el cotizante es la persona misma y se descarta. No hay fecha de
 * nacimiento, solo la edad.
 */
async function ccfGrupoFamiliar(pestana, d = {}) {
  const tab = pestana.id;
  const est = await ejecutar(tab, pCcfEstado);
  if (!est.sesion) return { ok: false, error: 'El portal no tiene la sesión iniciada.' };

  const documentos = (d.documentos || []).map(x => String(x).replace(/\D/g, '')).filter(Boolean).slice(0, 400);
  if (!documentos.length) return { ok: false, error: 'No llegaron documentos que consultar.' };

  if (!/consultaGrupoFamiliar/.test(est.pagina || '')) {
    await chrome.tabs.update(tab, { url: `${CCFCV_BASE}/consultaGrupoFamiliar.html` });
    await esperarCarga(tab);
    await esperar(2500);
  }

  const familias = {};
  let fallos = 0;

  for (const doc of documentos) {
    const r = await ejecutar(tab, (tipo, numero) => {
      $('#cmbTipoDocumento').val(String(tipo)).trigger('change').trigger('chosen:updated');
      $('#txtNumDocumento').val(numero).trigger('change');
      const t = $('#tablaGrupo').DataTable ? null : null;
      // Se marca la tabla para saber si la respuesta es nueva o la anterior.
      const tabla = document.querySelector('#tablaGrupo');
      if (tabla) tabla.dataset.brynexPrevio = tabla.innerText.slice(0, 120);
      $('#btnConsultar').click();
      return true;
    }, [d.tipoDoc || 1, doc]).catch(() => null);

    if (!r) { fallos++; continue; }

    const filas = await esperarQue(tab, (numero) => {
      const t = document.querySelector('#tablaGrupo');
      if (!t) return null;
      const tr = [...t.querySelectorAll('tbody tr')];
      if (!tr.length) return null;
      const datos = tr.map(f => [...f.querySelectorAll('td')].map(c => (c.innerText || '').replace(/\s+/g, ' ').trim()));
      // La respuesta es de esta persona cuando el cotizante es su documento.
      const suya = datos.some(f => (f[0] || '').replace(/\D/g, '').endsWith(numero));
      return suya ? datos : null;
    }, [doc], 25000);

    if (!filas) { fallos++; continue; }

    const suyos = filas
      .filter(f => /BENEFICIARIO/i.test(f[4] || ''))
      .map(f => {
        const partes = (f[0] || '').split('-').map(x => x.trim());
        return {
          tipo_doc: partes.length > 1 ? partes[0].toUpperCase() : null,
          documento: (partes[partes.length - 1] || '').replace(/\D/g, '').replace(/^0+/, ''),
          nombre: f[1] || '',
          parentesco: f[2] || null,
          edad: f[3] || null,
          categoria: f[5] || null,
        };
      })
      .filter(b => b.documento);

    if (suyos.length) familias[doc] = suyos;
    await esperar(600);
  }

  return { ok: true, familias, consultados: documentos.length, fallos };
}
