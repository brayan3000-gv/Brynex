/**
 * Lo que hay que leerle a Comfenalco Valle desde el servidor.
 *
 * Dos consultas, según `modo`:
 *   morosos      → los trabajadores morosos e inexactos (el bloqueo del subsidio)
 *   trabajadores → los afiliados de la empresa, para conciliar los radicados de caja
 *
 * Lo de siempre:
 *
 * Es el equivalente de los bloqueos de subsidio de Comfandi, pero mucho más
 * barato: la Sucursal Virtual tiene «Trabajadores Morosos y con Inexactitud
 * **Por Empresa**», así que una sola consulta cubre la nómina entera en vez de
 * entrar trabajador por trabajador.
 *
 * Por eso, cuando la consulta responde, **todos los candidatos quedan
 * revisados**: quien no aparezca en las tablas es que ya no tiene el problema, y
 * su tarea se puede cerrar.
 *
 * El portal es jQuery con `ejecutarAjax("CmndX")` contra `ServiciosWebRyA-Back`,
 * y el login vive en AuthComfe —correo y contraseña, sin código ni imagen que
 * descifrar—, así que la corrida nocturna entra sola.
 *
 * No es un script para correr a mano: lo invoca ComfenalcoSubsidiosHeadless.
 *
 *   echo '{"usuario":"empresa@correo.com","contrasena":"…"}' \
 *     | node scripts/comfenalco-subsidios.mjs
 *
 * Imprime {ok, empresa, movimientos[], sucursales, error}. Las credenciales
 * entran por stdin y nunca se escriben en el log.
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const HOST = 'https://virtual.comfenalcovalle.com.co';
const BASE = `${HOST}/ServiciosWebRyA`;
const LOGIN = 'https://authcomfeempresasprod.web.app/login?app_id=comfenalco.sucursalvirtual.empresas.app&tipo=E';

// Quién NO pasa por el proxy colombiano. Solo el portal SWRYA
// (virtual.comfenalcovalle.com.co) lo bloquea el WAF por IP; el login de
// AuthComfe es Firebase/Google, que no está geobloqueado y de día se cae con
// auth/network-request-failed cuando sale por el proxy residencial. Así que el
// login y todo el SDK de Firebase salen DIRECTO; el portal sigue por el proxy.
const DIRECTO = [
  'authcomfeempresasprod.web.app',
  '*.web.app',
  '*.firebaseapp.com',
  '*.firebaseio.com',
  'identitytoolkit.googleapis.com',
  'securetoken.googleapis.com',
  '*.googleapis.com',
  'apis.google.com',
  '*.google.com',
  '*.gstatic.com',
].join(';');

const CHROME_CANDIDATOS = [
  process.env.CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium',
].filter(Boolean);

const esperar = (ms) => new Promise(r => setTimeout(r, ms));
const salir = (data) => { console.log(JSON.stringify(data)); process.exit(data.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const trozo of process.stdin) datos += trozo;
  return datos.trim();
};

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

const usuario = String(entrada.usuario || '').trim();
const contrasena = String(entrada.contrasena || '');

if (!usuario || !contrasena) salir({ ok: false, error: 'Faltan usuario o contraseña.' });

// El WAF del portal atiende a un Chrome con ventana desde una conexión
// colombiana, y rechaza tanto el headless como la IP del servidor (datacenter,
// fuera del país). Por eso la corrida de netcup sale por el proxy colombiano —
// el mismo de Nueva EPS— y con la ventana que le pone Xvfb. Chrome no acepta la
// clave en --proxy-server: se entrega con page.authenticate().
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

// La otra salida colombiana: el túnel inverso desde el PC de la oficina, que
// ya trae a Nueva EPS. Un puerto por dominio, y `--host-rules` manda el tráfico
// de cada uno al suyo. El TLS sigue validándose contra el certificado real del
// portal, así que el túnel no ve nada del contenido.
const mapa = [];
for (const [host, destino] of [
  ['virtual.comfenalcovalle.com.co', entrada.tunel],
  ['authcomfeempresasprod.web.app', entrada.tunel_auth],
]) {
  if (!destino) continue;
  if (!/^[\w.-]+:\d{2,5}$/.test(String(destino))) {
    salir({ ok: false, error: `La dirección del túnel de ${host} no es válida (se espera 127.0.0.1:18444).` });
  }
  mapa.push(`MAP ${host} ${destino}`);
}

const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const ruta of CHROME_CANDIDATOS) {
    try { await access(ruta); return ruta; } catch {}
  }
  return null;
})();

if (!ejecutable) salir({ ok: false, error: 'No se encontró Chrome. Instálalo o define CHROME_PATH.' });

const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: entrada.visible ? false : 'new',
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
    '--window-size=1400,900',
    ...(proxy ? [`--proxy-server=${proxy.servidor}`, `--proxy-bypass-list=${DIRECTO}`] : []),
    ...(mapa.length ? [`--host-rules=${mapa.join(',')}`, '--disable-quic'] : []),
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

/**
 * Los afiliados que la caja tiene de esta empresa.
 *
 * Es la lista con la que se confirman los radicados de caja. Si el portal
 * devuelve menos filas de las que él mismo dice tener, no se entrega nada: con
 * la lista a medias media empresa parecería sin afiliar y la conciliación
 * pondría en "falta afiliar" a gente que sí está.
 */
const afiliadosDeLaEmpresa = async (pagina) => {
  await pagina.goto(`${BASE}/consultaTrabajadoresEmpresa.html`, { waitUntil: 'networkidle2', timeout: 60000 });

  const listo = await insistir(pagina, () => !!document.getElementById('btnConsultar'), [], 30000);
  if (!listo) return { ok: false, error: 'No cargó la consulta de trabajadores por empresa.' };

  await esperar(2000);

  const empresa = await pagina.evaluate(() => ({
    nit: (document.getElementById('txtNumDocumentoEmp')?.value || '').replace(/\D/g, ''),
    razon: document.getElementById('txtRazonSocal')?.value || null,
  })).catch(() => ({ nit: '', razon: null }));

  if (!empresa.nit) return { ok: false, error: 'El portal no mostró la empresa de la sesión.' };

  await pagina.evaluate(() => document.getElementById('btnConsultar')?.click());

  const filas = await insistir(pagina, () => {
    const visible = (e) => !!(e && (e.offsetWidth || e.offsetHeight));
    const modal = [...document.querySelectorAll('.jconfirm-content')].filter(visible)
      .map(e => e.innerText.replace(/\s+/g, ' ').trim());
    if (modal.length) return { error: modal.join(' ') };

    const tabla = window.$ && $('#tablaTrabajadores').DataTable ? $('#tablaTrabajadores').DataTable() : null;
    const datos = tabla ? tabla.rows().data().toArray() : [];
    if (! datos.length) return null;

    let esperadas = datos.length;
    try {
      const info = tabla.page.info();
      esperadas = info.recordsTotal || info.recordsDisplay || datos.length;
    } catch { /* sin paginación */ }

    return {
      datos: datos.map(f => [...f].slice(0, 4).map(x => String(x).replace(/<[^>]*>/g, '').trim())),
      esperadas,
    };
  }, [], 90000);

  if (! filas) return { ok: false, error: 'El portal no devolvió la lista de afiliados (¿la empresa no tiene ninguno?).' };
  if (filas.error) return { ok: false, error: filas.error };

  if (filas.datos.length < filas.esperadas) {
    return {
      ok: false,
      error: `Solo se leyeron ${filas.datos.length} de ${filas.esperadas} afiliados; con la lista a medias no se concilia.`,
    };
  }

  return { ok: true, nit: empresa.nit, empresa: empresa.razon, filas: filas.datos, completa: true };
};

/**
 * El certificado de afiliación de un trabajador (certificacionAfiliacionTrabajador.html).
 *
 * En vez de reconstruir los parámetros frágiles del back (tipoUsuario,
 * DIRECCION_IP, el cupo diario), se maneja la propia pantalla: se escoge el tipo
 * y número de documento del trabajador y se pulsa «Consultar». La página corre
 * su lógica —valida el cupo con CmndConsultaMaximoCertif y, si hay, pide el PDF
 * con CmndGenerarAfiliadosPersona— y nosotros solo escuchamos esa respuesta: el
 * PDF viene en base64 dentro de `respuesta.encodedString`. Así respetamos el
 * cupo y los datos de la empresa de la sesión sin tocarlos.
 *
 * Devuelve {ok, empresa, encodedString, namePdf} o {ok:false, motivo, error}
 * con motivo 'cupo' (máximo del día) o 'no_afiliado' (el trabajador no se puede
 * certificar todavía).
 */
const certificadoAfiliacion = async (pagina, entrada) => {
  const tipoTexto = {
    CC: 'ciudadania', CE: 'extranjeria', PA: 'pasaporte', PAS: 'pasaporte',
    PT: 'proteccion temporal', PPT: 'proteccion temporal', RC: 'registro civil',
  }[String(entrada.tipoDocTrabajador || '').toUpperCase()] || null;
  const numDoc = String(entrada.numDocTrabajador || '').replace(/\D/g, '');

  if (!tipoTexto) return { ok: false, error: `Tipo de documento no soportado para el certificado: ${entrada.tipoDocTrabajador}.` };
  if (!numDoc) return { ok: false, error: 'Falta el número de documento del trabajador para el certificado.' };

  // Se escucha la respuesta del PDF y la del cupo ANTES de disparar nada.
  let pdfResp = null;
  let cupoResp = null;
  pagina.on('response', async (rp) => {
    try {
      const u = rp.url();
      if (/CmndGenerarAfiliadosPersona/i.test(u)) pdfResp = await rp.text().catch(() => null);
      else if (/CmndConsultaMaximoCertif/i.test(u)) cupoResp = await rp.text().catch(() => null);
    } catch { /* */ }
  });

  // domcontentloaded, no networkidle2: esta pantalla trae Google Analytics
  // pingueando sin parar y la red nunca queda "quieta". Se espera a mano a que
  // el formulario y las opciones del combo (que cargan async) estén listos.
  await pagina.goto(`${BASE}/certificacionAfiliacionTrabajador.html`, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const listo = await insistir(pagina, () => {
    const sel = document.getElementById('cmbTipoDocumento');
    return !!document.getElementById('btnConsultar')
      && !!document.getElementById('txtNumDocumento')
      && !!sel && sel.options.length > 1;
  }, [], 40000);
  if (!listo) return { ok: false, error: 'No cargó la pantalla de certificación de afiliación (o no cargaron los tipos de documento).' };

  await esperar(1500);

  const empresa = await pagina.evaluate(() => {
    try { return (JSON.parse(localStorage.getItem('empresa') || 'null') || {}).razonSocial || null; } catch { return null; }
  }).catch(() => null);

  // Tipo de documento: el combo es Chosen, hay que avisarle o sigue en lo viejo.
  const tipoOk = await pagina.evaluate((txt) => {
    const sel = document.getElementById('cmbTipoDocumento');
    if (!sel) return false;
    const opt = [...sel.options].find((o) => (o.text || '').toLowerCase().includes(txt));
    if (!opt) return false;
    sel.value = opt.value;
    if (window.$) window.$(sel).trigger('change').trigger('chosen:updated');
    else sel.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, tipoTexto);
  if (!tipoOk) return { ok: false, error: `El portal no ofrece el tipo de documento «${tipoTexto}» para el certificado.` };

  await pagina.evaluate((n) => {
    const i = document.getElementById('txtNumDocumento');
    if (i) { i.value = n; i.dispatchEvent(new Event('input', { bubbles: true })); i.dispatchEvent(new Event('change', { bubbles: true })); }
  }, numDoc);

  await esperar(600);

  // El certificado es en dos pasos:
  //   1) «Consultar Beneficiarios» (btnConsultarBenefi) → CmndConsultarTrabajador
  //      llena la tabla #tableBeneficiario con el trabajador y sus beneficiarios
  //      (columnas Identificación, Nombre, Parentesco, Acc.). Si el trabajador no
  //      tiene vínculo laboral con la empresa, el portal lo dice en un modal.
  //   2) En la columna «Acc.» de la fila de cada persona está la acción que pide
  //      el PDF (CmndConsultaMaximoCertif + CmndGenerarAfiliadosPersona).
  await pagina.evaluate(() => document.getElementById('btnConsultarBenefi')?.click());

  let listaLista = false;
  const limiteConsulta = Date.now() + 45000;
  while (Date.now() < limiteConsulta && !listaLista) {
    await esperar(1500);
    const e = await pagina.evaluate(() => {
      const visible = (n) => !!(n && (n.offsetWidth || n.offsetHeight));
      const msg = [...document.querySelectorAll('.jconfirm-content, .jconfirm .content')].filter(visible)
        .map((n) => (n.innerText || '').replace(/\s+/g, ' ').trim()).join(' | ').slice(0, 250);
      return { filas: document.querySelectorAll('#tableBeneficiario tbody tr').length, msg };
    }).catch(() => ({ filas: 0, msg: '' }));

    if (e.msg && /no tiene informaci|sin informaci|no.*(afili|encontr|activ|existe|registr)|no.*labor/i.test(e.msg)) {
      return { ok: false, motivo: 'no_afiliado', error: `Comfenalco no certifica a este trabajador todavía: ${e.msg}` };
    }
    if (e.filas > 0) listaLista = true;
  }

  if (!listaLista) {
    return { ok: false, motivo: 'no_afiliado', error: 'El portal no listó al trabajador tras la consulta (puede no tener vínculo laboral activo con la empresa en la caja).' };
  }

  await esperar(800);
  // La acción del certificado está en la columna «Acc.» de la fila del trabajador.
  // El click genérico cubre botón/ícono/enlace, cualquiera que sea el elemento.
  const clic = await pagina.evaluate((doc) => {
    const tr = [...document.querySelectorAll('#tableBeneficiario tbody tr')]
      .find((r) => r.innerText.replace(/\D/g, '').includes(doc));
    if (!tr) return 'sin-fila';
    const el = tr.querySelector('td:last-child button, td:last-child a, td:last-child i, td:last-child [onclick]');
    if (!el) return 'sin-accion';
    el.click();
    return 'ok';
  }, numDoc).catch(() => 'error');

  if (clic !== 'ok') {
    return { ok: false, error: `No se encontró la acción para generar el certificado del trabajador en la tabla (${clic}).` };
  }

  // Se espera el PDF, el cupo agotado o un mensaje del portal (hasta 90 s).
  const limite = Date.now() + 90000;
  while (Date.now() < limite && !pdfResp) {
    await esperar(1500);

    if (cupoResp) {
      try {
        const r = JSON.parse(JSON.parse(cupoResp).respuesta || '{}');
        if (r.consultas_realizadas != null && parseInt(r.consultas_realizadas, 10) >= parseInt(r.consulta_permitidas, 10)) {
          return { ok: false, motivo: 'cupo', error: `Comfenalco no deja más certificados por hoy para esta empresa (${r.consultas_realizadas}/${r.consulta_permitidas}). Reintentar mañana.` };
        }
      } catch { /* */ }
    }

    const msg = await pagina.evaluate(() => {
      const visible = (e) => !!(e && (e.offsetWidth || e.offsetHeight));
      const d = [...document.querySelectorAll('.jconfirm-content, .jconfirm .content')].filter(visible)
        .map((e) => (e.innerText || '').replace(/\s+/g, ' ').trim()).join(' ');
      return d.slice(0, 300);
    }).catch(() => '');
    if (msg && /no.*(afili|encontr|activ|existe|registr)|sin informaci|no se encontr|no tiene/i.test(msg)) {
      return { ok: false, motivo: 'no_afiliado', error: `Comfenalco no certifica a este trabajador todavía: ${msg}` };
    }
  }

  if (!pdfResp) return { ok: false, error: 'El portal de Comfenalco no devolvió el certificado a tiempo (90 s).' };

  let encodedString = null;
  let namePdf = null;
  try {
    const inner = JSON.parse(JSON.parse(pdfResp).respuesta);
    encodedString = inner.encodedString || null;
    namePdf = inner.namePdf || null;
  } catch { /* */ }

  if (!encodedString) return { ok: false, error: 'La respuesta de Comfenalco no trae el PDF del certificado.' };

  return { ok: true, empresa, encodedString, namePdf: namePdf || `certificado_afiliacion_${numDoc}.pdf` };
};

let pagina;
try {
  pagina = await navegador.newPage();
  await pagina.setViewport({ width: 1400, height: 900 });
  if (proxy?.usuario) await pagina.authenticate({ username: proxy.usuario, password: proxy.clave });

  // ── Entrar ────────────────────────────────────────────────────────────────
  await pagina.goto(LOGIN, { waitUntil: 'networkidle2', timeout: 60000 });

  const campos = await insistir(pagina, () =>
    !!document.querySelector('input[type=password]') &&
    !!document.querySelector('input[name=email], input[type=text], input[type=email]'), [], 30000);

  if (!campos) salir({ ok: false, error: 'No apareció el formulario de acceso de Comfenalco.' });

  // Se teclea de verdad: AuthComfe es un formulario de Angular que valida con
  // los eventos del teclado, y con el valor puesto por script daba el campo por
  // vacío —dejaba el botón sin habilitar y el acceso no salía de esta pantalla—.
  const campoUsuario = await pagina.$('input[name=email], input[type=email]')
    ?? await pagina.$('input[type=text]');
  const campoClave = await pagina.$('input[type=password]');

  if (!campoUsuario || !campoClave) salir({ ok: false, error: 'El formulario de Comfenalco no tiene los campos esperados.' });

  await campoUsuario.click({ clickCount: 3 });
  await campoUsuario.type(usuario, { delay: 40 });
  await campoClave.click({ clickCount: 3 });
  await campoClave.type(contrasena, { delay: 40 });

  await esperar(800);

  let pulsado = false;

  for (const b of await pagina.$$('button,input[type=submit]')) {
    const texto = await b.evaluate(e => (e.innerText || e.value || '')).catch(() => '');
    if (!/iniciar sesi/i.test(texto)) continue;

    const apagado = await b.evaluate(e => e.disabled === true).catch(() => false);
    if (apagado) break;

    await b.click().catch(() => null);
    pulsado = true;
    break;
  }

  if (!pulsado) salir({ ok: false, error: 'El botón de acceso de Comfenalco no se dejó pulsar (¿quedó deshabilitado?).' });

  // La señal de estar dentro es el `usuario` del localStorage del portal, no la
  // pantalla de AuthComfe, que es una aplicación aparte.
  //
  // Se espera con paciencia y se vuelve a pulsar: por el proxy residencial cada
  // salto tarda, y un clic que llega mientras la página aún se asienta no hace
  // nada. Mientras sigamos en el formulario y esté listo, se insiste.
  const limite = Date.now() + 180000;
  let dentro = false;
  let ultimoIntento = Date.now();
  let reintentosRed = 0;

  while (Date.now() < limite && !dentro) {
    await esperar(2500);

    dentro = await pagina.evaluate(() =>
      /comfenalcovalle/.test(location.host) && !!localStorage.getItem('usuario')).catch(() => false);
    if (dentro) continue;

    // auth/network-request-failed es un fallo de RED del login de Firebase, no
    // de la clave. Con --proxy-bypass-list ya no debería aparecer; si aún así
    // sale, se reintenta con backoff corto (5s, 10s, 20s) en vez de esperar los
    // 30s del reintento normal.
    const errRed = await pagina.evaluate(() =>
      /network-request-failed/i.test(document.body.innerText || '')).catch(() => false);

    const espera = errRed ? Math.min(5000 * 2 ** reintentosRed, 20000) : 30000;
    if (Date.now() - ultimoIntento < espera) continue;

    const reintentado = await pagina.evaluate(() => {
      const clave = document.querySelector('input[type=password]');
      if (!clave || !clave.value) return false;          // ya no estamos en el login

      const btn = [...document.querySelectorAll('button,input[type=submit]')]
        .find(b => /iniciar sesi/i.test(b.innerText || b.value || ''));
      if (!btn || btn.disabled) return false;

      btn.click();

      return true;
    }).catch(() => false);

    if (reintentado) {
      ultimoIntento = Date.now();
      if (errRed) reintentosRed++;
    }
  }

  if (!dentro) {
    // Qué quedó en pantalla: sin esto, "no se abrió la sesión" no distingue
    // entre que el formulario no se llenara, que el botón no se dejara pulsar
    // o que el portal tardara más de la cuenta.
    const pantalla = await pagina.evaluate(() => {
      const usuario = document.querySelector('input[name=email], input[type=email], input[type=text]');
      const clave = document.querySelector('input[type=password]');
      const boton = [...document.querySelectorAll('button,input[type=submit]')]
        .find(b => /iniciar sesi/i.test(b.innerText || b.value || ''));

      return {
        donde: location.host + location.pathname,
        texto: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 250),
        campos: `usuario ${usuario ? (usuario.value ? 'con valor' : 'vacío') : 'no está'}`
          + `, clave ${clave ? (clave.value ? 'con valor' : 'vacía') : 'no está'}`
          + `, botón ${boton ? (boton.disabled ? 'deshabilitado' : 'listo') : 'no está'}`,
      };
    }).catch(() => null);

    const bloqueado = /web page blocked|attack id/i.test(pantalla?.texto || '');

    salir({
      ok: false,
      error: /incorrect|inv[aá]lid|no existe/i.test(pantalla?.texto || '')
        ? 'Comfenalco rechazó el usuario o la clave guardada en BryNex.'
        : bloqueado
          ? 'El portal de Comfenalco le niega el acceso a esta salida a internet. Atiende a un navegador con ventana desde una conexión colombiana: hace falta el proxy (PROXY_COLOMBIA).'
          : 'No se llegó a abrir la sesión de Comfenalco.'
            + (pantalla ? ` Quedó en ${pantalla.donde} [${pantalla.campos}]: "${pantalla.texto}"` : ''),
    });
  }

  if (entrada.modo === 'trabajadores') {
    salir(await afiliadosDeLaEmpresa(pagina));
  }

  if (entrada.modo === 'certificado') {
    salir(await certificadoAfiliacion(pagina, entrada));
  }

  // ── La consulta, una por sucursal ─────────────────────────────────────────
  await pagina.goto(`${BASE}/consultaTrabajadorMoroso.html`, { waitUntil: 'networkidle2', timeout: 60000 });

  const listo = await insistir(pagina, () => !!document.getElementById('cmbSucursalEmpresa'), [], 30000);
  if (!listo) salir({ ok: false, error: 'No cargó la consulta de trabajadores morosos.' });

  await esperar(2000);

  const empresa = await pagina.evaluate(() => {
    try { return (JSON.parse(localStorage.getItem('empresa') || 'null') || {}).razonSocial || null; } catch { return null; }
  }).catch(() => null);

  const sucursales = await pagina.evaluate(() =>
    [...document.querySelectorAll('#cmbSucursalEmpresa option')]
      .map(o => ({ valor: o.value, nombre: (o.text || '').trim() }))
      .filter(o => o.valor && o.valor !== '-1')).catch(() => []);

  if (!sucursales.length) salir({ ok: false, error: 'La empresa no tiene sucursales en Comfenalco.' });

  const movimientos = [];

  for (const sucursal of sucursales) {
    await pagina.evaluate((v) => {
      const s = document.getElementById('cmbSucursalEmpresa');
      s.value = v;
      // Los combos del portal son Chosen: sin avisarle, el que manda sigue
      // mostrando lo de antes y la consulta sale con la sucursal vieja.
      if (window.$) $(s).trigger('change').trigger('chosen:updated');
      else s.dispatchEvent(new Event('change', { bubbles: true }));
    }, sucursal.valor);

    await esperar(800);

    for (const b of await pagina.$$('button')) {
      const texto = await b.evaluate(e => (e.innerText || '')).catch(() => '');
      if (/^\s*consultar\s*$/i.test(texto.trim())) { await b.click().catch(() => null); break; }
    }

    await esperar(6000);

    const filas = await pagina.evaluate(() => {
      const leer = (id, clase) => [...document.querySelectorAll(`#${id} tbody tr`)]
        .map(r => [...r.querySelectorAll('td')].map(c => c.innerText.trim()))
        .filter(c => c.length >= 4)
        .map(c => ({ periodo: c[0], clase: c[1], documento: c[2], nombre: c[3], valor: c[4] || '0', origen: clase }));

      return [...leer('tablaMorosidad', 'mora'), ...leer('tablaInexactitud', 'inexactitud')];
    }).catch(() => []);

    filas.forEach(f => movimientos.push({ ...f, sucursal: sucursal.valor }));

    // El "Atención: no hay información" tapa la pantalla y deja la siguiente
    // consulta sin poder pulsarse.
    await pagina.evaluate(() => {
      [...document.querySelectorAll('button,a')]
        .filter(e => /^\s*cerrar\s*$/i.test((e.innerText || '').trim()))
        .forEach(e => e.click());
    }).catch(() => null);

    await esperar(800);
  }

  salir({ ok: true, empresa, sucursales: sucursales.length, movimientos });
} catch (e) {
  const donde = await pagina?.evaluate(() => location.host + location.pathname).catch(() => '');
  salir({ ok: false, error: String(e?.message || e).slice(0, 400), url: donde });
} finally {
  // Cerrar el navegador no cierra la sesión del portal: la siguiente empresa
  // entraría sobre los restos de esta, y todas salen por la misma IP.
  await pagina?.evaluate(() => {
    const salir = [...document.querySelectorAll('a,button,div,span')]
      .filter(e => e.children.length === 0)
      .find(e => /^\s*cerrar sesi[oó]n\s*$/i.test(e.innerText || ''));

    if (salir) (salir.closest('a,button') || salir).click();

    try { localStorage.clear(); sessionStorage.clear(); } catch { /* da igual */ }
  }).catch(() => null);

  await esperar(2000);
  await navegador.close().catch(() => {});
}
