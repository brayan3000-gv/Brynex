/**
 * Los bloqueos de subsidio monetario que Comfandi reporta de unos trabajadores.
 *
 * Es el mismo recorrido que hace la extensión BryNex Portales en el navegador
 * de la persona, pero con un Chrome propio, para que la revisión pueda correr
 * de noche sin nadie delante. El portal no tiene API: es un Next.js con Server
 * Actions, así que todo se opera por pantalla, igual que en Colmena.
 *
 * Tres detalles del portal mandan sobre el código:
 *
 *  - El **tipo de documento** del login es un typeahead de PatternFly: la lista
 *    vive oculta en el DOM y sólo se despliega con el foco. Con "CC" el portal
 *    contesta "Documento o contraseña incorrectos" aunque la clave sea buena.
 *  - El **2FA** son dos pantallas: la primera sólo ofrece "Continuar" y el
 *    "Omitir por ahora" está en la del código QR. Se omite a propósito: con
 *    Authenticator haría falta un código del teléfono en cada corrida.
 *  - Los **filtros del portal** son react-select, donde el input va vacío
 *    aunque haya valor y el menú no se abre con un clic: se abre con la flecha
 *    abajo y se pulsa la opción.
 *
 * No es un script para correr a mano: lo invoca ComfandiSubsidiosHeadless.
 *
 *   echo '{"usuario":"9016037383","contrasena":"…","documentos":["1144132276"]}' \
 *     | node scripts/comfandi-subsidios.mjs
 *
 * Imprime en stdout {ok, nit, empresa, movimientos[], revisados[], errores[]}.
 * Las credenciales entran por stdin y nunca se escriben en el log.
 */
import { rmSync, mkdirSync, writeFileSync, unlinkSync, readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import puppeteer from 'puppeteer-core';
import { afiliarIndividual } from './comfandi-afiliar.mjs';

const HOST = 'https://afiliaciones.sucursalcomfandi.com';
const BASE = `${HOST}/sakaar`;
const RIA = 'https://ria.sucursalcomfandi.com';

/**
 * Código del Authenticator (TOTP) que Comfandi ahora obliga en el login. Keycloak
 * usa el estándar: HMAC-SHA1, 6 dígitos, ventana de 30 s, y la clave son los
 * bytes crudos del secreto (el QR que muestra es base32 de esos mismos bytes).
 * El secreto se configura una vez por empresa y se guarda en BryNex.
 */
const totpCodigo = (secreto, t = Date.now()) => {
  const contador = Buffer.alloc(8);
  contador.writeBigUInt64BE(BigInt(Math.floor(t / 1000 / 30)));
  const h = createHmac('sha1', Buffer.from(secreto, 'utf8')).update(contador).digest();
  const o = h[h.length - 1] & 0x0f;
  const num = ((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1_000_000;
  return String(num).padStart(6, '0');
};

const CHROME_CANDIDATOS = [
  process.env.CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium',
].filter(Boolean);

const esperar = (ms) => new Promise(r => setTimeout(r, ms));

// Lo último que se vio al intentar abrir un combo, para que el fallo lo cuente.
let ultimoComboVisto = '';
const salir = (data) => { console.log(JSON.stringify(data)); process.exit(data.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const trozo of process.stdin) datos += trozo;
  return datos.trim();
};

/**
 * Reintenta una comprobación hasta que devuelva algo verdadero.
 *
 * El portal repinta sin avisar —Next.js— y una espera por selector no alcanza:
 * lo que hace falta es volver a intentar la acción entera.
 */
const insistir = async (pagina, fn, args = [], limite = 25000) => {
  const hasta = Date.now() + limite;
  while (Date.now() < hasta) {
    try {
      const r = await pagina.evaluate(fn, ...args);
      if (r) return r;
    } catch { /* la página está cambiando */ }
    await esperar(500);
  }
  return null;
};

// El daemon se arranca "desprendido" (detached) desde PHP, donde pasar la clave
// por argv la dejaría visible en `ps`. Por eso la entrada va en un archivo
// (--entrada-file=ruta) que se lee y se borra enseguida. En las corridas de un
// disparo sigue llegando por argv[2] o stdin, como siempre.
let entrada;
try {
  const arg = (process.argv.slice(2).find((a) => a.startsWith('--entrada-file=')) || '').slice('--entrada-file='.length);
  if (arg) {
    const crudo = readFileSync(arg, 'utf8');
    try { unlinkSync(arg); } catch { /* ya no está */ }
    entrada = JSON.parse(crudo || '{}');
  } else {
    entrada = JSON.parse((process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : '') || await leerStdin() || '{}');
  }
} catch {
  salir({ ok: false, error: 'Entrada JSON inválida.' });
}

const usuario = String(entrada.usuario || '').replace(/\D/g, '');
const contrasena = String(entrada.contrasena || '');
const documentos = (entrada.documentos || []).map(d => String(d).replace(/\D/g, '')).filter(Boolean);
const meses = Math.max(1, Math.min(12, parseInt(entrada.meses) || 4));
// Secreto del Authenticator (vacío si la cuenta aún no lo tiene configurado; en
// ese caso el 2FA hay que hacerlo/omitirlo a mano la 1.ª vez).
const totpSecret = String(entrada.totpSecret || '').trim();

// Vía 'api': en vez de raspar el DOM, se llama la API JSON del portal con fetch
// desde dentro del navegador (lo único que pasa Akamai). Devuelve el JSON crudo
// de cada endpoint; el mapeo de formas se hace en PHP. nit/companyId: la empresa
// sobre la que se opera (x-company-id sale de company-context). desde/hasta:
// rango AAAA-MM-DD para subsidios.
const via = entrada.via === 'api' ? 'api' : 'dom';
// Tipo de documento del login: las empresas entran por NIT; el usuario gestor
// (vía API) entra por CC (cédula), que además es el valor por defecto del combo.
const tipoLogin = String(entrada.tipoLogin || (via === 'api' ? 'CC' : 'NIT')).toUpperCase();
const nit = String(entrada.nit || '').replace(/\D/g, '');
const companyId = String(entrada.companyId || '').trim();
const desde = String(entrada.desde || '').trim();
const hasta = String(entrada.hasta || '').trim();
// Certificado: el código del certificado (BEN = con beneficiarios; TRA = solo el
// trabajador) y el tipo de documento del trabajador (CO1C = cédula).
const certCodigo = String(entrada.certCodigo || 'BEN').trim().toUpperCase();
const tipoDocTrab = String(entrada.tipoDocTrab || 'CO1C').trim().toUpperCase();
// Modo servicio ("sesión caliente"): tras entrar, queda vivo atendiendo consultas
// por HTTP local reusando la misma sesión; se apaga tras `inactividadMs` sin uso.
const servidor = entrada.servidor === true && via === 'api';
const inactividadMs = Math.max(60, Math.min(1800, parseInt(entrada.inactividadSeg) || 720)) * 1000;
// Latido de mantenimiento: cada tanto el daemon refresca el token por dentro
// (una navegación liviana) para que no caduque entre huecos de consultas. Debe
// ser más corto que la vida del token de acceso. No es un login.
const latidoMs = Math.max(30, Math.min(600, parseInt(entrada.latidoSeg) || 100)) * 1000;

if (!usuario || !contrasena) salir({ ok: false, error: 'Faltan usuario o contraseña.' });
const modo = ['listado', 'certificado'].includes(entrada.modo) ? entrada.modo : 'subsidios';
if (via === 'dom' && modo === 'subsidios' && !documentos.length) salir({ ok: false, error: 'No llegó ninguna cédula para consultar.' });

// Comfandi tiene Akamai delante y le contesta "Access Denied" a la IP del
// servidor (datacenter fuera de Colombia): sin proxy colombiano el login no
// llega ni a mostrarse. Chrome no acepta la clave en --proxy-server, así que se
// entrega con page.authenticate(). Ver PROXY_COLOMBIA.
let proxy = null;
if (entrada.proxy) {
  try {
    const u = new URL(entrada.proxy);
    proxy = {
      servidor: `${u.protocol}//${u.hostname}:${u.port}`,
      usuario: decodeURIComponent(u.username || ''),
      clave: decodeURIComponent(u.password || ''),
    };
  } catch {
    salir({ ok: false, error: 'La dirección del proxy no es válida (se espera http://usuario:clave@host:puerto).' });
  }
}

const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const ruta of CHROME_CANDIDATOS) {
    try { await access(ruta); return ruta; } catch {}
  }
  return null;
})();

if (!ejecutable) salir({ ok: false, error: 'No se encontró Chrome. Instálalo o define CHROME_PATH.' });

// El portal solo atiende a un navegador con ventana. En el servidor la ventana
// la da Xvfb (un display virtual), asi que Chrome corre normal, sin pantalla
// donde dibujar. Ver ComfandiSubsidiosHeadless.
// Perfil persistente por empresa: Comfandi ofrece "No volver a preguntar en 60
// días" ligado al navegador+cookies, así que guardando el perfil el 2FA solo se
// pide una vez cada 60 días (o si se borra el perfil). Es una carpeta fija por
// usuario, no el temporal de puppeteer, así que el borrado de abajo no la toca.
let perfilDir = null;
if (usuario) {
  perfilDir = `${process.cwd()}/storage/app/comfandi-perfiles/${usuario}`;
  try { mkdirSync(perfilDir, { recursive: true }); } catch {}
  // Si una corrida anterior murió, Chrome deja un lock que impide reabrir el
  // perfil: se quita para no quedar tumbado por un cierre sucio.
  try { rmSync(`${perfilDir}/SingletonLock`, { force: true }); } catch {}
}

const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: entrada.visible ? false : 'new',
  ...(perfilDir ? { userDataDir: perfilDir } : {}),
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
    '--window-size=1400,900',
    ...(proxy ? [`--proxy-server=${proxy.servidor}`] : []),
  ],
});


// Al salir se borra el perfil temporal del navegador. Hace falta a mano porque
// el script termina con process.exit(), que se salta el cierre de puppeteer —y
// es ese cierre el que normalmente lo borra—: cada corrida dejaba su carpeta en
// /tmp, y entre todos los robots se juntaron 11 GB (1-oct-2026).
process.on('exit', () => {
  try {
    const perfil = (navegador.process()?.spawnargs || [])
      .find((a) => a.startsWith('--user-data-dir='))
      ?.slice('--user-data-dir='.length);

    if (perfil && /puppeteer_dev/.test(perfil)) rmSync(perfil, { recursive: true, force: true });
  } catch { /* si no se deja borrar, no es motivo para tumbar nada */ }
});

/** La empresa en la que está la sesión, o null si aún no se ha entrado. */
const empresaAbierta = (pagina) => pagina.evaluate(() => {
  const m = (document.body.innerText || '').match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
  return m && !/\/guest/.test(location.pathname) ? m[1].trim() : null;
});

/**
 * Elige una opción de un react-select del portal.
 *
 * Aquí, a diferencia de la extensión, se usan el teclado y el clic de verdad de
 * Puppeteer: los eventos fabricados a mano abren el menú en un Chrome con
 * ventana, pero en headless no siempre, y el filtro se quedaba sin poner.
 */
const elegirCombo = async (pagina, opcion, cual = 0) => {
  const re = new RegExp('^\\s*' + opcion, 'i');

  const yaEsta = await pagina.evaluate((texto, i) => {
    const combo = [...document.querySelectorAll('input[role=combobox]')][i];
    return !!combo && new RegExp('^\\s*' + texto, 'i').test(combo.closest('[class*=control]')?.innerText || '');
  }, opcion, cual).catch(() => false);

  if (yaEsta) return true;

  // El portal pinta los filtros por su cuenta después de cargar la pantalla, y
  // mirarlos una sola vez descarta al trabajador por llegar medio segundo antes
  // (dos así el 24-sep-2026). Se espera a que aparezcan.
  let combos = [];
  let combo = null;

  for (let espera = 0; espera < 6 && !combo; espera++) {
    if (espera > 0) await esperar(1500);

    combos = await pagina.$$('input[role=combobox]');
    combo = combos[cual] || null;
  }

  if (!combo) {
    ultimoComboVisto = `no hay combo #${cual} en la página (${combos.length} en total)`;

    return false;
  }

  // Varios intentos: el menú tarda en pintarse, una sola pulsación se pierde y
  // —lo que costó encontrar— el portal carga las opciones por detrás, así que
  // al abrirlo demasiado pronto contesta "No options" y se queda vacío para
  // siempre si no se vuelve a intentar.
  for (let intento = 0; intento < 5; intento++) {
    if (intento > 0) {
      await pagina.keyboard.press('Escape').catch(() => null);
      await esperar(1500);
    }

    await combo.focus();
    await pagina.keyboard.press('ArrowDown');
    await esperar(900);

    const opciones = await pagina.$$('[class*=option]');
    const vistas = [];

    for (const o of opciones) {
      const texto = await o.evaluate(e => e.innerText || '').catch(() => '');
      vistas.push(texto.trim().slice(0, 28));

      if (! re.test(texto)) continue;

      await o.click().catch(() => null);
      await esperar(600);

      return true;
    }

    ultimoComboVisto = vistas.length ? `se veían [${vistas.slice(0, 6).join(' | ')}]` : 'el menú no se abrió';

    // "No options" no es que falte la nuestra: es que aún no llegó ninguna.
    if (vistas.some(v => /^no options/i.test(v))) await esperar(2500);
  }

  return false;
};

/**
 * El modal de bienvenida: escoge la empresa y acepta.
 *
 * Nada más entrar, el portal dice "Aún no tiene permisos para gestionar
 * afiliaciones" y pide elegir la empresa en un desplegable; hasta que no se
 * acepta, el menú no lleva a ninguna parte. El usuario del login es el NIT de
 * la empresa, así que casi siempre hay una sola, pero si hay varias manda la
 * que el portal saluda arriba.
 */
const escogerEmpresa = async (pagina) => {
  const saludada = await pagina.evaluate(() =>
    ((document.body.innerText || '').match(/Hola,\s*([^\n.]+)/i) || [])[1]?.trim() || '').catch(() => '');

  // El desplegable: react-select si lo hay, y si no el propio "Selecciona tu
  // empresa", que el portal dibuja como un botón.
  const combo = (await pagina.$$('input[role=combobox]'))[0];

  if (combo) {
    await combo.focus();
    await pagina.keyboard.press('ArrowDown');
  } else {
    for (const b of await pagina.$$('button,[role=button],div[class*=select]')) {
      const texto = await b.evaluate(e => (e.offsetParent ? (e.innerText || '') : '')).catch(() => '');
      if (/selecciona tu empresa/i.test(texto)) { await b.click().catch(() => null); break; }
    }
  }

  await esperar(900);

  // La opción del desplegable no es un <li>: el portal la dibuja como un botón
  // más, así que se busca por texto entre todo lo pulsable, saltándose el
  // propio "Selecciona tu empresa" y los enlaces del menú.
  const menu = /^(inicio|ir al inicio|radicados|cerrar sesi|gesti[oó]n de trabajadores|certificados|actualizar datos|administraci[oó]n|selecciona tu empresa|aceptar|c\.)/i;
  let elegida = null;

  for (const o of [...await pagina.$$('[class*=option]'), ...await pagina.$$('li'), ...await pagina.$$('button,[role=button]')]) {
    const texto = await o.evaluate(e => (e.offsetParent ? (e.innerText || '').trim() : '')).catch(() => '');
    if (!texto || texto.length > 120 || menu.test(texto)) continue;
    if (!elegida) elegida = o;
    if (saludada && texto.toUpperCase().includes(saludada.toUpperCase().slice(0, 12))) { elegida = o; break; }
  }

  if (elegida) {
    await elegida.click().catch(() => null);
    await esperar(700);
  }

  for (const b of await pagina.$$('button,[role=button],input[type=submit]')) {
    const texto = await b.evaluate(e => (e.offsetParent ? (e.innerText || e.value || '') : '')).catch(() => '');
    if (/^\s*aceptar\s*$/i.test(texto.trim())) { await b.click().catch(() => null); return true; }
  }

  return !!elegida;
};

/**
 * Elige la empresa del gestor cuyo nombre coincide (para la vía API: varias
 * empresas y hay que entrar en la que toca, porque algunos datos —subsidios—
 * solo salen con la empresa seleccionada en la sesión, no basta el header).
 */
const escogerEmpresaPorNombre = async (pagina, nombre) => {
  const clave = String(nombre || '').toUpperCase().replace(/\s+/g, ' ').slice(0, 15);
  if (!clave) return false;

  for (const b of await pagina.$$('button,[role=button],div[class*=select]')) {
    const t = await b.evaluate(e => (e.offsetParent ? (e.innerText || '') : '')).catch(() => '');
    if (/selecciona tu empresa/i.test(t)) { await b.click().catch(() => null); break; }
  }
  await esperar(900);

  let elegida = null;
  for (const o of [...await pagina.$$('[class*=option]'), ...await pagina.$$('li'), ...await pagina.$$('button,[role=button]')]) {
    const t = await o.evaluate(e => (e.offsetParent ? (e.innerText || '').trim() : '')).catch(() => '');
    if (t && t.toUpperCase().replace(/\s+/g, ' ').includes(clave)) { elegida = o; break; }
  }
  if (elegida) { await elegida.click().catch(() => null); await esperar(700); }

  for (const b of await pagina.$$('button,[role=button],input[type=submit]')) {
    const t = await b.evaluate(e => (e.offsetParent ? (e.innerText || e.value || '') : '')).catch(() => '');
    if (/^\s*aceptar\s*$/i.test((t || '').trim())) { await b.click().catch(() => null); break; }
  }
  await esperar(2500);
  return !!elegida;
};

/** Los bloqueos de un trabajador, o null si no se pudo llegar a su pantalla. */
const bloqueosDe = async (pagina, documento, tipoBusqueda = 'Bloqueos de subsidio') => {
  // La pantalla del filtro, hasta que esté de verdad.
  //
  // Se vuelve por el menú, no por la URL: el portal es un Next.js y una
  // navegación de las suyas remonta el formulario, mientras que recargar la
  // página entera a veces lo deja sin combo ni botón Buscar —y entonces no hay
  // nada que pulsar—. La recarga queda como último recurso.
  const hayFiltro = async () => !!await insistir(pagina, () => {
    const cargando = [...document.querySelectorAll('[class*=spinner], [class*=loading], [class*=backdrop], [class*=overlay]')]
      .some(e => e.offsetParent !== null);
    if (cargando) return false;

    return !!document.querySelector('input[role=combobox]')
      && [...document.querySelectorAll('button')].some(b => /^\s*Buscar\s*$/i.test(b.innerText));
  }, [], 15000);

  const porElMenu = await pagina.evaluate(() => {
    const a = document.querySelector('a[href="/sakaar/workers"]');
    if (!a) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click']
      .forEach(t => a.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));

    return true;
  }).catch(() => false);

  if (!porElMenu) {
    await pagina.goto(`${BASE}/workers`, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
  }

  if (! await hayFiltro()) {
    await pagina.goto(`${BASE}/workers`, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);

    if (! await hayFiltro()) return 'la pantalla de Gestión de trabajadores no mostró el filtro';
  }

  await esperar(1200);

  // Sin tipo de documento el portal no filtra: saca la lista entera paginada y
  // el trabajador podría no estar en la primera página.
  if (!await elegirCombo(pagina, 'C[ée]dula de Ciudadan')) return `no se pudo elegir el tipo de documento en el listado (${ultimoComboVisto})`;

  const buscado = await insistir(pagina, (doc) => {
    const num = [...document.querySelectorAll('input')].find(i => /documento del trabajador/i.test(i.placeholder || ''));
    if (!num) return false;
    const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    set.call(num, doc);
    num.dispatchEvent(new Event('input', { bubbles: true }));
    const btn = [...document.querySelectorAll('button')].find(b => /^\s*Buscar\s*$/i.test(b.innerText));
    if (!btn) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => btn.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [documento], 25000);

  if (!buscado) return 'no apareció el campo del documento o el botón Buscar';

  const entro = await insistir(pagina, (doc) => {
    const fila = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.replace(/\D/g, '').includes(doc));
    const b = fila?.querySelector('button');
    if (!b) return false;
    b.click();
    return true;
  }, [documento], 20000);

  if (!entro) {
    // Distinto de que el portal falle: puede que esa persona no esté afiliada a
    // esta empresa en Comfandi (BryNex la tiene, la caja no). Eso no se
    // reintenta ni se reporta como avería.
    const vacia = await pagina.evaluate((doc) => {
      const filas = [...document.querySelectorAll('tbody tr')];
      const suya = filas.some(r => r.innerText.replace(/\D/g, '').includes(doc));

      return !suya && (filas.length > 0 || /no se encontraron|sin resultados/i.test(document.body.innerText || ''));
    }, documento).catch(() => false);

    return vacia ? 'no-esta' : 'no se encontró su fila en el listado ni se pudo pulsar Gestionar';
  }

  const abrio = await insistir(pagina, () => {
    const e = [...document.querySelectorAll('button,div,span')].filter(x => x.children.length === 0)
      .find(x => /^\s*Subsidio monetario\s*$/i.test(x.innerText || ''));
    if (!e) return false;
    const c = e.closest('button') || e;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => c.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 20000);

  if (!abrio) return 'no se abrió "Subsidio monetario" en el menú Gestionar';

  const listo = await insistir(pagina, () =>
    [...document.querySelectorAll('input')].some(i => /fecha inicial/i.test(i.placeholder || '')), [], 25000);

  if (!listo) return 'no cargó la pantalla de subsidio monetario';

  if (!await elegirCombo(pagina, tipoBusqueda)) return `no se pudo elegir "${tipoBusqueda}" (${ultimoComboVisto})`;

  await esperar(600);

  // La fecha inicial es un react-datepicker: se abre con un clic, se retrocede
  // con su flecha y se pulsa el día 1. Escribirle el texto no sirve.
  await pagina.evaluate(async (n) => {
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
    [...document.querySelectorAll('.react-datepicker__day:not(.react-datepicker__day--outside-month)')]
      .find(e => e.innerText.trim() === '1')?.click();
    return true;
  }, meses).catch(() => null);

  await esperar(600);

  const respondio = await insistir(pagina, () => {
    const btn = [...document.querySelectorAll('button')].find(b => /^\s*Buscar\s*$/i.test(b.innerText));
    if (!btn) return false;
    ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(t => btn.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
    return true;
  }, [], 20000);

  if (!respondio) return 'no se encontró el botón Buscar de la consulta';

  // "No se encontraron movimientos" también es una respuesta: significa que ese
  // trabajador no tiene bloqueos, y su tarea se puede cerrar.
  const filas = await insistir(pagina, () => {
    const texto = document.body.innerText || '';
    if (/no se encontraron movimientos/i.test(texto)) return { vacio: true, filas: [] };

    const bloques = texto.split(/(?=Bloqueo de Subsidio Monetario)/i).filter(b => /Periodo de bloqueo/i.test(b));
    if (!bloques.length) return false;

    const vistos = new Set();
    const filas = [];

    for (const b of bloques) {
      const periodo = (b.match(/Periodo de bloqueo:\s*([^\n|]+)/i) || [])[1]?.trim() || '';
      const fecha = (b.match(/Fecha de Bloqueo:\s*([^\n|]+)/i) || [])[1]?.trim() || '';
      const motivo = (b.match(/Motivo de Bloqueo:\s*([^\n|]+)/i) || [])[1]?.trim() || '';
      const valor = (b.match(/-?\$\s*([\d.,]+)/) || [])[1] || '0';
      const llave = periodo + '|' + fecha + '|' + motivo;
      if (vistos.has(llave)) continue;
      vistos.add(llave);
      filas.push({ tipo: 'BLOQUEO', periodo, fecha, motivo, valor });
    }

    return { vacio: false, filas };
  }, [], 30000);

  return filas ? filas.filas : null;
};

/**
 * Cierra la sesión del portal antes de irse.
 *
 * Cerrar el navegador no cierra la sesión: la de Keycloak sigue viva del lado
 * de Comfandi, y como todas las empresas salen por la misma IP del servidor, la
 * siguiente entraba sobre los restos de la anterior —el login devolvía una
 * página en blanco, o el listado se quedaba sin filtro—. Por eso la primera
 * empresa de cada corrida funcionaba y las de después no.
 */
const cerrarSesion = async (pagina) => {
  if (! pagina) return;

  await pagina.evaluate(() => {
    const salir = [...document.querySelectorAll('a,button,div,span')]
      .filter(e => e.children.length === 0)
      .find(e => /^\s*cerrar sesi[oó]n\s*$/i.test(e.innerText || ''));

    if (salir) {
      const destino = salir.closest('a,button') || salir;
      ['pointerdown', 'mousedown', 'mouseup', 'click']
        .forEach(t => destino.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));

      return true;
    }

    return false;
  }).catch(() => null);

  // Se le da un momento para que la petición salga, y se limpia lo local por si
  // el botón no estaba a la vista.
  await esperar(2500);

  await pagina.evaluate(() => {
    try { localStorage.clear(); sessionStorage.clear(); } catch { /* da igual */ }
  }).catch(() => null);
};

let pagina;
try {
  pagina = await navegador.newPage();
  await pagina.setViewport({ width: 1400, height: 900 });
  if (proxy?.usuario) await pagina.authenticate({ username: proxy.usuario, password: proxy.clave });

  // Vía API: capturar el token Bearer y el x-company-id desde el lado de Node
  // (con page.on('request')). Esto sobrevive a las navegaciones del login/2FA,
  // cosa que un hook dentro de la página no hace: cada documento es un contexto
  // nuevo y el token capturado se perdía al navegar.
  let apiToken = null;
  let apiCompanyId = null;
  const apiUrls = [];
  if (via === 'api') {
    pagina.on('request', (req) => {
      try {
        // Solo el token de las peticiones a la API (ria): Keycloak emite varios
        // y los demás la API los rechaza con "no permisos" aunque sean válidos.
        if (! /ria\.sucursalcomfandi/.test(req.url())) return;
        apiUrls.push(req.method() + ' ' + req.url().split('?')[0]);
        const h = req.headers() || {};
        const a = h.authorization || h.Authorization;
        if (a && /^bearer /i.test(a)) apiToken = a;
        const c = h['x-company-id'] || h['X-Company-Id'];
        if (c) apiCompanyId = c;
      } catch { /* */ }
    });
  }

  // ── Entrar ────────────────────────────────────────────────────────────────
  // Lo que va haciendo, para el modal de la ficha: ComfandiApi lee estas líneas.
  const avance = (texto) => { if (via === 'api') console.error('paso: ' + texto); };

  avance('Abriendo el portal de Comfandi');
  await pagina.goto(`${BASE}/guest`, { waitUntil: 'networkidle2', timeout: 60000 });

  // Con el perfil persistente la sesión puede seguir viva (confianza de 60 días):
  // si ya se está dentro, el formulario de login no existe y hay que saltárselo,
  // o si no el script se quedaba buscando el campo de tipo de documento. El panel
  // se hidrata después de networkidle2, así que un chequeo único llegaba temprano
  // y creía que NO estaba dentro (y luego fallaba al no hallar el formulario). Se
  // sondea hasta que la página se define: o el panel de "ya dentro", o el login.
  let yaDentro0 = false;
  for (let i = 0; i < 20; i++) {
    const estado = await pagina.evaluate(() => {
      const t = document.body.innerText || '';
      return {
        dentro: /cerrar sesi[oó]n|actualmente est[aá]s en|¿qu[eé] quieres hacer hoy|selecciona tu empresa/i.test(t),
        login: !!document.querySelector('input[name=password]') || !!document.querySelector('input.documentTypeSearchInput')
          || [...document.querySelectorAll('button,a')].some(x => /iniciar sesi/i.test(x.innerText || '')),
      };
    }).catch(() => ({ dentro: false, login: false }));
    if (estado.dentro) { yaDentro0 = true; break; }
    if (estado.login) { yaDentro0 = false; break; }
    await esperar(500);
  }

  avance(yaDentro0 ? 'La sesión de Comfandi seguía abierta' : 'Escribiendo usuario y clave');

  if (!yaDentro0) {
  await insistir(pagina, () => {
    const b = [...document.querySelectorAll('button,a')].find(e => /iniciar sesi/i.test(e.innerText || ''));
    if (!b) return false;
    b.click();
    return true;
  }, [], 20000);

  await insistir(pagina, () =>
    !!document.querySelector('input[name=password]') && !!document.querySelector('input[name=identification_type_up]'), [], 30000);

  // El tipo de documento: con "CC" el portal rechaza la clave buena. Es un
  // typeahead de PatternFly cuya lista vive oculta en el DOM y sólo se
  // despliega al enfocarlo de verdad; en headless los eventos fabricados no la
  // abrían y el login se quedaba aquí.
  const campoTipo = await pagina.$('input.documentTypeSearchInput') ?? await (async () => {
    for (const i of await pagina.$$('input')) {
      const ph = await i.evaluate(e => e.placeholder || '').catch(() => '');
      if (/tipo de documento/i.test(ph)) return i;
    }
    return null;
  })();

  if (!campoTipo) {
    // Sin esto el fallo no decía nada: el portal puede haber contestado otra
    // cosa (mantenimiento, bloqueo por país) y el script solo veía que faltaba
    // un campo.
    const pantalla = await pagina.evaluate(() => ({
      donde: location.host + location.pathname,
      texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 400),
    })).catch(() => ({ donde: '', texto: '' }));

    salir({
      ok: false,
      error: /access denied|edgesuite/i.test(pantalla.texto)
        ? 'Comfandi (Akamai) le niega el acceso a la IP del servidor: hace falta salir por el proxy colombiano (PROXY_COLOMBIA).'
        : `El login de Comfandi no mostró el campo de tipo de documento. Quedó en ${pantalla.donde}: "${pantalla.texto}"`,
      url: pantalla.donde,
    });
  }

  // Se intenta varias veces y de dos maneras: anoche fallaron tres de cada
  // cuatro empresas aqui. Entre escribir y elegir se puede perder el foco —y
  // con el la lista—, y el clic de Puppeteer no siempre prende en el <li>, que
  // a veces escucha en un hijo.
  // El gestor entra por CC, que es el valor por defecto del combo: no se toca.
  // Solo las empresas (NIT) necesitan cambiarlo, con el typeahead de abajo.
  let tipoOk = tipoLogin === 'CC';

  for (let intento = 0; intento < 4 && ! tipoOk; intento++) {
    // Hay que vaciarlo antes: el campo llega con "CC - Cédula de ciudadanía"
    // escrito y, si se teclea encima, queda "CC - Cédula de ciudadaníaNIT" y el
    // typeahead responde "No se encontraron coincidencias". Con Construtech
    // venía vacío y por eso parecía funcionar.
    await campoTipo.click({ clickCount: 3 }).catch(() => null);
    await campoTipo.evaluate((e) => {
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(e, '');
      e.dispatchEvent(new Event('input', { bubbles: true }));
    }).catch(() => null);
    await esperar(300);
    await campoTipo.type('NIT', { delay: 60 }).catch(() => null);
    await esperar(900);

    for (const li of await pagina.$$('li')) {
      const texto = await li.evaluate(e => (e.offsetParent ? (e.innerText || '') : '')).catch(() => '');
      if (! /^\s*NIT\b/i.test(texto)) continue;

      await li.click().catch(() => null);
      await esperar(400);

      const puesto = await pagina.evaluate(() =>
        document.querySelector('input[name=identification_type_up]')?.value === 'NIT').catch(() => false);

      if (! puesto) {
        await li.evaluate((e) => {
          const destino = e.querySelector('button,a,span') || e;
          ['pointerdown', 'mousedown', 'mouseup', 'click']
            .forEach(t => destino.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
        }).catch(() => null);
      }

      break;
    }

    tipoOk = await insistir(pagina, () =>
      document.querySelector('input[name=identification_type_up]')?.value === 'NIT', [], 6000);
  }

  if (! tipoOk) {
    // Que habia en pantalla, en vez de culpar al portal de haber cambiado.
    const estado = await pagina.evaluate(() => ({
      donde: location.host + location.pathname,
      valor: document.querySelector('input[name=identification_type_up]')?.value ?? 'no esta',
      opciones: [...document.querySelectorAll('li')].filter(e => e.offsetParent).map(e => (e.innerText || '').trim().slice(0, 24)).slice(0, 6),
    })).catch(() => null);

    salir({
      ok: false,
      error: 'No se pudo escoger "NIT" en Tipo de documento.'
        + (estado ? ` En ${estado.donde}: el campo vale "${estado.valor}" y se ven [${estado.opciones.join(' | ')}].` : ''),
    });
  }

  await insistir(pagina, (u, c) => {
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
  }, [usuario, contrasena], 20000);
  }

  // Las pantallas que el portal encadena después de Entrar, hasta quedar dentro.
  const finLogin = Date.now() + 150000;
  let empresa = null;
  let codigoAvisado = false;

  while (Date.now() < finLogin && !empresa) {
    await esperar(1500);

    // Código fresco cada vuelta: si uno vence a mitad del envío, el siguiente
    // intento ya trae otro (la ventana TOTP es de 30 s y el bucle corre cada 1,5).
    const codigo = totpSecret ? totpCodigo(totpSecret) : null;

    const paso = await pagina.evaluate((nit, codigo) => {
      const golpe = (e) => ['pointerdown', 'mousedown', 'mouseup', 'click']
        .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      const texto = document.body.innerText || '';

      if (/documento o contrase|credenciales inv[aá]lidas|usuario o contrase/i.test(texto)) return { fin: 'clave' };

      const dentro = texto.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
      if (dentro && !/\/guest/.test(location.pathname)) return { fin: 'dentro', empresa: dentro[1].trim() };

      const botones = [...document.querySelectorAll('button,a,input[type=submit],[role=button]')]
        .filter(e => (e.innerText || e.value || '').trim());

      // 2FA del Authenticator (lo que reemplazó al viejo "Omitir"). Si hay una
      // pantalla de código —6 casillas o un solo campo— se llena con el TOTP que
      // llega de Node y se pulsa Verificar. Antes, la pantalla que pregunta cómo
      // verificar: se toma el camino del QR/aplicación, que lleva al código.
      if (codigo) {
        const esCampo = (e) => ['text', 'tel', 'number', 'password'].includes(e.type);
        const campos = [...document.querySelectorAll('input')].filter(esCampo);
        const pideCodigo = /ingresa (tu|el) c[oó]digo|c[oó]digo de verificaci[oó]n|c[oó]digo [uú]nico|verificar c[oó]digo|c[oó]digo del? authenticator/i.test(texto);
        if (pideCodigo || campos.length >= 6) {
          const set = (el, val) => {
            Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set.call(el, val);
            ['input', 'change', 'keyup'].forEach(t => el.dispatchEvent(new Event(t, { bubbles: true })));
          };
          const cajas = campos.filter(e => e.maxLength === 1 || /otp|totp|code|digit/i.test(e.name || e.id || ''));
          if (cajas.length >= 6) { for (let i = 0; i < 6; i++) { cajas[i].focus(); set(cajas[i], codigo[i]); } }
          else if (campos[0]) { campos[0].focus(); set(campos[0], codigo); }
          const hid = document.querySelector('input[name=totp],input[name=otp]');
          if (hid) set(hid, codigo);
          const verificar = botones.find(e => /verificar|continuar|ingresar/i.test(e.innerText || e.value || ''));
          if (verificar) { golpe(verificar); return { paso: '2fa-codigo' }; }
        }
        const metodo = botones.find(e => /escanear c[oó]digo qr|authenticator|aplicaci[oó]n de autenticaci[oó]n/i.test(e.innerText || ''));
        if (metodo && /c[oó]mo quieres verificar|verificar tu identidad/i.test(texto)) { golpe(metodo); return { paso: '2fa-metodo' }; }
      }

      const omitir = botones.find(e => /omitir|m[aá]s tarde|ahora no|despu[eé]s/i.test(e.innerText || e.value || ''));
      if (omitir) { golpe(omitir); return { paso: '2fa-omitir' }; }

      const continuar = botones.find(e => /^\s*continuar\s*$/i.test((e.innerText || e.value || '').trim()));
      if (continuar) { golpe(continuar); return { paso: '2fa-continuar' }; }

      // La empresa se escoge fuera de aquí: su desplegable necesita el teclado
      // y el clic de verdad de Puppeteer, no eventos fabricados.
      if (/selecciona (tu|la) empresa|no tiene permisos para gestionar/i.test(texto)) return { paso: 'empresa' };

      const tarjetas = [...document.querySelectorAll('button,[role=button],li,div[class*=card]')]
        .filter(e => {
          const t = (e.innerText || '').trim();
          return t && t.length < 200 && t.replace(/\D/g, '').includes(nit.slice(0, 9));
        });
      if (tarjetas.length) { golpe(tarjetas[tarjetas.length - 1]); return { paso: 'tarjeta' }; }

      return { paso: 'esperando' };
    }, usuario, codigo).catch(() => ({ paso: 'cargando' }));

    if (paso?.fin === 'clave') salir({ ok: false, error: 'Comfandi rechazó el usuario o la clave guardada en BryNex.' });
    if (paso?.fin === 'dentro') empresa = paso.empresa;
    if (paso?.paso === '2fa-codigo' && !codigoAvisado) {
      codigoAvisado = true;
      avance('Ingresando el código de verificación (2FA)');
    }
    // En la vía API no se elige empresa en la UI (el gestor ve varias): basta
    // estar dentro con el token capturado para llamar la API por x-company-id.
    if (via === 'api') {
      // Con el token ya capturado (de alguna petición del portal) se puede
      // llamar la API; no hace falta elegir empresa en la UI.
      if (apiToken) break;
    } else if (paso?.paso === 'empresa') {
      await escogerEmpresa(pagina);
    }
  }

  // ── Vía API: llamar la API JSON del portal con fetch (corre dentro del
  // navegador real, así que pasa Akamai). Devuelve el JSON crudo de cada
  // endpoint; el mapeo de formas y NIT→x-company-id se resuelve en PHP.
  if (via === 'api') {
    avance('Obteniendo el permiso para consultar');
    // El token lo capturó page.on('request'). Si aún no ha llegado, se espera un
    // poco y se fuerza una navegación a Afiliaciones, que dispara company-context.
    for (let i = 0; i < 20 && !apiToken; i++) {
      if (i === 4) await pagina.goto(`${BASE}/affiliations`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
      await esperar(500);
    }
    const token = apiToken;
    if (!token) salir({ ok: false, error: 'Entró al portal pero no se capturó el token de la API (ninguna llamada autenticada).' });

    // El token (JWT) trae las empresas del gestor en el claim `companies`: de
    // ahí sale el mapa NIT → x-company-id (el `id` es el GUID). No se usa
    // company-context, que para el gestor responde 403.
    let empresas = [];
    try {
      const jwt = token.replace(/^bearer /i, '');
      const payload = JSON.parse(Buffer.from(jwt.split('.')[1], 'base64').toString('utf8'));
      empresas = Array.isArray(payload.companies) ? payload.companies : [];
    } catch { /* si no se decodifica, empresas queda vacío */ }

    // opts: { method, body, binario }. Con `binario` lee la respuesta como bytes
    // y la devuelve en base64 (para el certificado, que es un PDF). Los GET de
    // siempre no pasan opts y se comportan igual que antes.
    const apiRia = (path, cid, opts = {}) => pagina.evaluate(async (base, ruta, tk, companyId, o) => {
      // getMovements exige el header Accept (sin él da 400); los demás endpoints
      // no lo piden, pero ponerlo no estorba.
      const headers = { Authorization: tk, Accept: 'application/json, text/plain, */*' };
      if (companyId) headers['x-company-id'] = companyId;
      const init = { method: o.method || 'GET', headers };
      if (o.body != null) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(o.body); }
      try {
        const r = await fetch(base + ruta, init);
        const ct = r.headers.get('content-type') || '';
        if (o.binario) {
          const buf = new Uint8Array(await r.arrayBuffer());
          let bin = ''; for (let i = 0; i < buf.length; i++) bin += String.fromCharCode(buf[i]);
          return { status: r.status, contentType: ct, base64: btoa(bin) };
        }
        const t = await r.text();
        let j = null; try { j = JSON.parse(t); } catch { /* texto plano */ }
        return { status: r.status, contentType: ct, body: j !== null ? j : t.slice(0, 2000) };
      } catch (e) { return { status: 0, error: String(e) }; }
    }, RIA, path, apiToken, cid || null, opts).catch((e) => ({ status: 0, error: String(e) }));

    const soloDigitos = (s) => String(s || '').replace(/\D/g, '');
    const empresaDe = (unNit) => empresas.find((e) => {
      const a = soloDigitos(e.identification), b = soloDigitos(unNit);
      return a === b || a.startsWith(b) || b.startsWith(a);
    });

    const hoy = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    // El mapa de empresas del gestor (NIT → GUID), para que PHP lo use o guarde.
    const empresasMap = empresas.map((e) => ({ id: e.id, nit: e.identification, nombre: e.name, afiliada: e.is_affiliated, roles: e.roles }));

    // Re-login suave: el token de acceso vive pocos minutos; una navegación al
    // portal hace que la app pida uno nuevo (NextAuth lo refresca) y
    // page.on('request') lo recaptura. Si la sesión SSO ya murió del todo no
    // refresca, y ahí el daemon reporta el fallo (PHP lo relanza con login
    // completo). No pide 2FA por la confianza de 60 días.
    const reLoguear = async () => {
      const antes = apiToken;
      await pagina.goto(`${BASE}/affiliations`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
      for (let i = 0; i < 16 && apiToken === antes; i++) await esperar(500);
      return !!apiToken && apiToken !== antes;
    };

    // apiRia con reintento tras re-login si la respuesta es 401 (sesión caduca).
    const apiRiaAuth = async (path, cid, opts = {}) => {
      let r = await apiRia(path, cid, opts);
      if (r && r.status === 401 && await reLoguear()) r = await apiRia(path, cid, opts);
      return r;
    };

    // Una consulta: selecciona la empresa del NIT en la sesión y hace el dispatch
    // (sap/listado/certificado/subsidios). Devuelve la salida de ESA consulta.
    // En modo servidor se llama una vez por request; en un disparo, una sola vez.
    const atender = async (op) => {
      // Afiliación individual por robot: reusa la sesión del gestor (DOM), no el API.
      if (op.modo === 'afiliarIndividual') return afiliarIndividual(pagina, op, esperar, BASE);
      const unNit = String(op.nit || '').replace(/\D/g, '');
      const docs = (op.documentos || []).map((d) => String(d).replace(/\D/g, '')).filter(Boolean);
      const unModo = ['listado', 'certificado'].includes(op.modo) ? op.modo : 'subsidios';
      const opDesde = String(op.desde || '').trim();
      const opHasta = String(op.hasta || '').trim();
      const opTipoDoc = String(op.tipoDocTrab || 'CO1C').trim().toUpperCase();
      const opCert = String(op.certCodigo || 'BEN').trim().toUpperCase();

      const salida = { ok: true, via: 'api', empresas: empresasMap };
      const emp = empresaDe(unNit);
      // affiliateNit va CON dígito de verificación (9021031841); el token lo trae
      // en `identification` ("902103184-1").
      const cid = String(op.companyId || '').trim() || emp?.id || null;
      const nitDv = soloDigitos(emp?.identification || unNit);
      salida.companyId = cid;

      // Subsidios solo salen con la empresa seleccionada en la sesión (no basta el
      // x-company-id). Se selecciona la del NIT pedido.
      if (emp && emp.name) {
        await pagina.goto(`${BASE}/affiliations`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
        await esperar(1500);
        salida.empresaElegida = await escogerEmpresaPorNombre(pagina, emp.name);
        // La sesión necesita un respiro tras elegir la empresa: si el /sap sale
        // enseguida a veces vuelve vacío (arranque en frío).
        await esperar(2800);
      }

      if (cid && unNit) {
        if (unModo === 'listado') {
          salida.listado = await apiRiaAuth(`/sap?affilationDate=${hoy}&affiliateTypeDoc=CO1N&affiliateNit=${nitDv}&page=1&maxLength=2000`, cid);
        } else if (unModo === 'certificado' && docs.length) {
          // Certificado por API: POST a /certificate/new-create-affiliation.
          // certificateCode BEN = con beneficiarios; companyDocNum va CON dígito de
          // verificación. La respuesta es el PDF (binario → base64).
          salida.certificados = {};
          for (const doc of docs) {
            salida.certificados[doc] = await apiRiaAuth('/certificate/new-create-affiliation', cid, {
              method: 'POST', binario: true,
              body: {
                workerDocType: opTipoDoc, workerDocNum: doc,
                companyDocType: 'CO1N', companyDocNum: emp?.identification || unNit,
                certificateCode: opCert,
              },
            });
          }
        } else if (docs.length) {
          salida.trabajadores = {};
          salida.subsidios = {};
          for (const doc of docs) {
            salida.trabajadores[doc] = await apiRiaAuth(`/sap?affilationDate=${hoy}&affiliateTypeDoc=CO1N&affiliateNit=${nitDv}&page=1&maxLength=1&documentType=CO1C&documentNumber=${doc}`, cid);
            if (opDesde && opHasta) {
              // getMovements no se puede replicar por fetch fuera de la sesión (da
              // 400): se abre la pantalla de Subsidio monetario con "Búsqueda
              // general", se captura el TOKEN + x-company-id de ESA llamada y sus
              // respuestas, y se re-ejecuta getMovements con ese token para el
              // rango completo (sin depender del datepicker, que es lo frágil).
              const mov = [];
              const respuestas = [];
              let gmAuth = null, gmCid = null;
              const onReq = (rq) => {
                try {
                  if (! /monetary-subsidy\/getMovements/.test(rq.url())) return;
                  const h = rq.headers() || {};
                  if (h.authorization) gmAuth = h.authorization;
                  if (h['x-company-id']) gmCid = h['x-company-id'];
                } catch { /* */ }
              };
              const onResp = async (rp) => {
                try {
                  if (! /monetary-subsidy\/getMovements/.test(rp.url())) return;
                  let n = 0;
                  if (rp.status() === 200) {
                    const j = await rp.json().catch(() => null);
                    const arr = j && j.data && Array.isArray(j.data.movements) ? j.data.movements : null;
                    if (arr) { n = arr.length; if (n) mov.push(...arr); }
                  }
                  const u = new URL(rp.url());
                  respuestas.push({ status: rp.status(), fi: u.searchParams.get('fechaInicio'), ff: u.searchParams.get('fechaFin'), n });
                } catch { /* */ }
              };
              pagina.on('request', onReq);
              pagina.on('response', onResp);
              let errSub = null;
              try {
                const res = await bloqueosDe(pagina, doc, 'B[uú]squeda general');
                if (typeof res === 'string' && res !== 'no-esta') errSub = res;
              } catch (e) { errSub = String(e).slice(0, 150); }
              await esperar(2000);

              let replay = null;
              if (gmAuth) {
                replay = await pagina.evaluate(async (base, auth, cid2, d, h, docu) => {
                  try {
                    const r = await fetch(`${base}/monetary-subsidy/getMovements?movementType=GENERAL&fechaInicio=${d}&fechaFin=${h}&offset=0&limit=100&employeeIdType=CC&employeeIdNumber=${docu}`,
                      { headers: { Authorization: auth, 'x-company-id': cid2, Accept: 'application/json, text/plain, */*' } });
                    const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch { /* */ }
                    return { status: r.status, data: (j && j.data && Array.isArray(j.data.movements)) ? j.data.movements : null };
                  } catch (e) { return { status: 0, err: String(e) }; }
                }, RIA, gmAuth, gmCid, opDesde, opHasta, doc).catch((e) => ({ status: 0, err: String(e) }));
              }
              pagina.off('request', onReq);
              pagina.off('response', onResp);

              const delReplay = replay && replay.status === 200 && Array.isArray(replay.data) ? replay.data : null;
              salida.subsidios[doc] = {
                movimientos: delReplay || mov,
                fuente: delReplay ? 'replay' : 'respuesta',
                replayStatus: replay ? replay.status : null,
                respuestas,
                ...(errSub ? { error: errSub } : {}),
              };
            }
          }
        }
      } else if (unNit) {
        salida.aviso = `El gestor no tiene la empresa ${unNit} entre sus ${empresas.length} empresas de Comfandi.`;
      }
      return salida;
    };

    if (servidor) {
      // ── Modo servicio (sesión caliente) ──────────────────────────────────
      // Queda vivo atendiendo consultas por HTTP local (127.0.0.1) reusando esta
      // sesión. Las consultas se serializan (una sola página) con una cadena de
      // promesas; un reaper lo apaga tras `inactividadMs` sin uso.
      let cola = Promise.resolve();
      let timer = null;
      let latidoTimer = null;
      let ocupado = false; // una consulta real está en curso
      const apagar = (motivo) => {
        try { if (perfilDir) unlinkSync(`${perfilDir}/daemon.json`); } catch { /* */ }
        if (latidoTimer) clearInterval(latidoTimer);
        console.error('daemon-cerrado: ' + motivo);
        navegador.close().catch(() => null).finally(() => process.exit(0));
        setTimeout(() => process.exit(0), 4000);
      };
      // El reaper mide la inactividad desde la última consulta REAL (los latidos
      // de mantenimiento no cuentan): así la sesión se autolimpia cuando de
      // verdad nadie consulta, aunque el latido la mantenga caliente mientras.
      const reiniciarReaper = () => { if (timer) clearTimeout(timer); timer = setTimeout(() => apagar('inactividad'), inactividadMs); };

      // Latido: si no hay consulta en vuelo, encola un refresco liviano del token
      // para que no caduque entre huecos. No reinicia el reaper (no es actividad
      // real) ni es un login (no toca la clave): sin riesgo de bloqueo.
      const latir = () => {
        if (ocupado) return; // una consulta real ya mantiene la sesión caliente
        cola = cola.then(async () => {
          if (ocupado) return;
          try { await reLoguear(); console.error('daemon-latido'); } catch { /* */ }
        }, () => {});
      };

      const srv = createServer((req, res) => {
        if (req.url === '/health') { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ ready: true, pid: process.pid })); return; }
        if (req.method !== 'POST') { res.writeHead(405); res.end(); return; }
        let cuerpo = '';
        req.on('data', (c) => { cuerpo += c; if (cuerpo.length > 2e6) req.destroy(); });
        req.on('end', () => {
          let op = {};
          try { op = JSON.parse(cuerpo || '{}'); } catch { /* */ }
          reiniciarReaper(); // solo las consultas reales reinician el reaper
          ocupado = true;
          cola = cola.then(async () => {
            let salida;
            try { salida = await atender(op); } catch (e) { salida = { ok: false, error: String(e?.message || e).slice(0, 300) }; }
            ocupado = false;
            reiniciarReaper();
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify(salida));
          }, () => { ocupado = false; });
        });
      });

      srv.listen(0, '127.0.0.1', () => {
        const puerto = srv.address().port;
        try { if (perfilDir) writeFileSync(`${perfilDir}/daemon.json`, JSON.stringify({ puerto, pid: process.pid, desde: new Date().toISOString() })); } catch { /* */ }
        console.error('daemon-listo puerto=' + puerto + ' pid=' + process.pid);
        reiniciarReaper();
        latidoTimer = setInterval(latir, latidoMs);
      });

      // Queda vivo: el proceso no sale aquí; lo apaga el reaper con process.exit().
      await new Promise(() => {});
    }

    salir(await atender({ modo, nit, documentos, desde, hasta, tipoDocTrab, certCodigo }));
  }

  if (!empresa) empresa = await empresaAbierta(pagina);

  if (!empresa) {
    // Sin el detalle el fallo era mudo y había que adivinar en qué pantalla se
    // quedó: el portal encadena varias y cada una tiene sus botones.
    const pantalla = await pagina.evaluate(() => ({
      donde: location.host + location.pathname,
      texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300),
      clicables: [...document.querySelectorAll('button,a,input[type=submit],[role=button]')]
        .map(e => (e.innerText || e.value || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean).slice(0, 12),
    })).catch(() => ({ donde: '', texto: '', clicables: [] }));

    salir({
      ok: false,
      error: `Se entró al portal pero no se llegó a la empresa. Quedó en ${pantalla.donde}: "${pantalla.texto}" · botones: ${pantalla.clicables.join(' | ')}`,
      url: pantalla.donde,
    });
  }

  // ── Recorrer a los trabajadores ───────────────────────────────────────────
  const movimientos = [];
  const revisados = [];
  let errores = [];

  const consultar = async (documento) => {
    try {
      const filas = await bloqueosDe(pagina, documento);

      // Un texto en vez de las filas es el paso donde se quedó: saber cuál es
      // la diferencia entre arreglarlo y volver a mirar a ciegas.
      if (typeof filas === 'string' && filas !== 'no-esta') {
        return { documento, error: `No se pudo abrir su subsidio monetario: ${filas}.` };
      }

      if (! filas) return { documento, error: 'No se pudo abrir su subsidio monetario.' };

      if (filas === 'no-esta') {
        return { documento, error: 'No aparece en el listado de trabajadores de esa empresa en Comfandi.', noEsta: true };
      }

      revisados.push(documento);
      filas.forEach(f => movimientos.push({ ...f, documento }));

      return null;
    } catch (e) {
      return { documento, error: String(e?.message || e).slice(0, 150) };
    }
  };

  // ── El listado entero de trabajadores de la empresa ──────────────────
  // Es lo que hace falta para conciliar: quién tiene Comfandi por esta empresa,
  // sin preguntar uno por uno.
  if (modo === 'listado') {
    await pagina.goto(`${BASE}/workers`, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
    await esperar(3000);

    const leerPagina = () => pagina.evaluate(() => {
      const tabla = document.querySelector('table');
      if (!tabla) return { columnas: [], filas: [] };

      return {
        columnas: [...tabla.querySelectorAll('thead th')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()),
        filas: [...tabla.querySelectorAll('tbody tr')]
          .map((f) => [...f.querySelectorAll('td')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()))
          .filter((f) => f.join('').trim() !== ''),
      };
    });

    // La tabla se pinta después de cargar la pantalla: sin esperarla, la
    // primera lectura sale vacía y, como no hay nada nuevo, el recorrido se
    // para creyendo que la empresa no tiene a nadie.
    await insistir(pagina, () => document.querySelectorAll('tbody tr').length > 0, [], 20000);
    await esperar(1200);

    // El portal dice cuántos son ("Numero de registros 70"): es el único modo
    // de saber si el recorrido llegó al final o se quedó a medias, que es lo
    // que pasaba —la misma empresa daba 10, 20 o 70 según el día—.
    const declarados = await pagina.evaluate(() => {
      const m = (document.body.innerText || '').match(/n[uú]mero\s+de\s+registros\s*:?\s*(\d+)/i);
      return m ? Number(m[1]) : null;
    }).catch(() => null);

    const vistas = new Set();
    let columnas = [];
    const filas = [];

    for (let pag = 1; pag <= 60; pag++) {
      const actual = await leerPagina();
      if (!columnas.length) columnas = actual.columnas;

      const antes = vistas.size;
      for (const f of actual.filas) {
        const clave = f.join('|');
        if (!vistas.has(clave)) { vistas.add(clave); filas.push(f); }
      }

      // Si la página no trajo nada nuevo, el paginador no avanzó: se para.
      if (vistas.size === antes) break;

      // El paginador es de MUI y son botones con el número de la página; la
      // flecha de "siguiente" no siempre está, así que se pulsa el número.
      const avanzo = await pagina.evaluate((siguiente) => {
        const botones = [...document.querySelectorAll('button, a')].filter((e) => e.offsetParent !== null && !e.disabled);

        const porNumero = botones.find((e) => (e.innerText || '').trim() === String(siguiente));
        if (porNumero) { porNumero.click(); return true; }

        const porFlecha = botones.find((e) => /next page|siguiente|›|»/i.test((e.innerText || '') + ' ' + (e.getAttribute('aria-label') || '')));
        if (porFlecha) { porFlecha.click(); return true; }

        return false;
      }, pag + 1).catch(() => false);

      if (!avanzo) break;
      await esperar(2500);
    }

    // Si faltó gente, se vuelve a recorrer desde el principio: el portal a
    // veces repinta la tabla tarde y una página se lee vacía.
    if (declarados && filas.length < declarados) {
      await pagina.goto(`${BASE}/workers`, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
      await insistir(pagina, () => document.querySelectorAll('tbody tr').length > 0, [], 20000);
      await esperar(2000);

      for (let pag = 1; pag <= 60 && filas.length < declarados; pag++) {
        const actual = await leerPagina();

        for (const f of actual.filas) {
          const clave = f.join('|');
          if (!vistas.has(clave)) { vistas.add(clave); filas.push(f); }
        }

        const avanzo = await pagina.evaluate((siguiente) => {
          const b = [...document.querySelectorAll('button, a')]
            .filter((e) => e.offsetParent !== null && !e.disabled)
            .find((e) => (e.innerText || '').trim() === String(siguiente));
          if (!b) return false;
          b.click();

          return true;
        }, pag + 1).catch(() => false);

        if (!avanzo) break;
        await esperar(3000);
      }
    }

    // Qué controles de paginación ofrece la pantalla: si el listado se queda
    // corto, aquí se ve por qué sin volver a entrar al portal.
    const controles = await pagina.evaluate(() => [...document.querySelectorAll('button, a, select, [role=button]')]
      .filter((e) => e.offsetParent !== null)
      .map((e) => ({
        etiqueta: e.tagName.toLowerCase(),
        texto: (e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 30),
        aria: (e.getAttribute('aria-label') || '').slice(0, 40),
        clase: (e.className || '').toString().slice(0, 50),
        deshabilitado: !!e.disabled,
      }))
      .filter((e) => /^\d{1,3}$/.test(e.texto) || /pag|next|siguiente|arrow|chevron/i.test(e.clase + e.aria + e.texto)
        || /listado|ver m[aá]s|mostrar/i.test(e.texto))
      .slice(0, 25)).catch(() => []);

    // Y cuántas filas dice tener el portal, que suele ir en un texto suelto.
    const totales = await pagina.evaluate(() => (document.body.innerText || '')
      .split('\n').map((l) => l.trim())
      .filter((l) => /(\d+\s*(de|\/)\s*\d+)|registros|resultados|total/i.test(l) && l.length < 80)
      .slice(0, 6)).catch(() => []);

    await cerrarSesion(pagina).catch(() => null);
    salir({ ok: true, modo, columnas, filas, declarados, completo: !declarados || filas.length >= declarados, controles, totales });
  }

  for (const documento of documentos) {
    const fallo = await consultar(documento);
    if (fallo) errores.push(fallo);
  }

  // Segunda pasada: el portal se traba con uno de cada dos —tarda en repintar
  // la tabla y el paso siguiente encuentra la de antes—, y al reintentarlo sí
  // responde. Un trabajador no consultado deja su tarea sin cerrar, así que
  // vale la pena la vuelta extra.
  const averias = errores.filter(e => !e.noEsta);

  if (averias.length) {
    errores = errores.filter(e => e.noEsta);

    for (const { documento } of averias) {
      const fallo = await consultar(documento);
      if (fallo) errores.push(fallo);
    }
  }

  salir({ ok: true, nit: usuario, empresa, movimientos, revisados, errores });
} catch (e) {
  const donde = await pagina?.evaluate(() => location.host + location.pathname).catch(() => '');
  salir({ ok: false, error: String(e?.message || e).slice(0, 400), url: donde });
} finally {
  await cerrarSesion(pagina);
  await navegador.close().catch(() => {});
}
