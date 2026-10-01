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
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const HOST = 'https://afiliaciones.sucursalcomfandi.com';
const BASE = `${HOST}/sakaar`;

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

let entrada;
try {
  entrada = JSON.parse(process.argv[2] || await leerStdin() || '{}');
} catch {
  salir({ ok: false, error: 'Entrada JSON inválida.' });
}

const usuario = String(entrada.usuario || '').replace(/\D/g, '');
const contrasena = String(entrada.contrasena || '');
const documentos = (entrada.documentos || []).map(d => String(d).replace(/\D/g, '')).filter(Boolean);
const meses = Math.max(1, Math.min(12, parseInt(entrada.meses) || 4));

if (!usuario || !contrasena) salir({ ok: false, error: 'Faltan usuario o contraseña.' });
const modo = entrada.modo === 'listado' ? 'listado' : 'subsidios';
if (modo === 'subsidios' && !documentos.length) salir({ ok: false, error: 'No llegó ninguna cédula para consultar.' });

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
const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: entrada.visible ? false : 'new',
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

/** Los bloqueos de un trabajador, o null si no se pudo llegar a su pantalla. */
const bloqueosDe = async (pagina, documento) => {
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

  if (!await elegirCombo(pagina, 'Bloqueos de subsidio')) return `no se pudo elegir "Bloqueos de subsidio" (${ultimoComboVisto})`;

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

  // ── Entrar ────────────────────────────────────────────────────────────────
  await pagina.goto(`${BASE}/guest`, { waitUntil: 'networkidle2', timeout: 60000 });

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
  let tipoOk = false;

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

  // Las pantallas que el portal encadena después de Entrar, hasta quedar dentro.
  const hasta = Date.now() + 150000;
  let empresa = null;

  while (Date.now() < hasta && !empresa) {
    await esperar(1500);

    const paso = await pagina.evaluate((nit) => {
      const golpe = (e) => ['pointerdown', 'mousedown', 'mouseup', 'click']
        .forEach(t => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window })));
      const texto = document.body.innerText || '';

      if (/documento o contrase|credenciales inv[aá]lidas|usuario o contrase/i.test(texto)) return { fin: 'clave' };

      const dentro = texto.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i);
      if (dentro && !/\/guest/.test(location.pathname)) return { fin: 'dentro', empresa: dentro[1].trim() };

      const botones = [...document.querySelectorAll('button,a,input[type=submit],[role=button]')]
        .filter(e => (e.innerText || e.value || '').trim());

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
    }, usuario).catch(() => ({ paso: 'cargando' }));

    if (paso?.fin === 'clave') salir({ ok: false, error: 'Comfandi rechazó el usuario o la clave guardada en BryNex.' });
    if (paso?.fin === 'dentro') empresa = paso.empresa;
    if (paso?.paso === 'empresa') await escogerEmpresa(pagina);
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
