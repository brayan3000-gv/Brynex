/**
 * Abre sesión en el portal de ARL Sura y devuelve las cookies.
 *
 * Existe porque la sesión de `sel-services` no se puede pedir por HTTP: nace del
 * SSO de login.sura.com (ASP.NET, con teclado virtual) detrás de Imperva
 * Incapsula, y la cookie que importa —JSESSIONID— es httpOnly, así que no se
 * puede leer con JavaScript de página. Un navegador de verdad sí la ve.
 *
 * No es un script suelto para correr a mano: lo invoca ArlSuraSesionService.
 *
 *   echo '{"tipoDocumento":"C","usuario":"…","contrasena":"…","nitEmpresa":"901918923"}' \
 *     | node scripts/arl-sura-login.mjs
 *
 * Imprime en stdout un JSON: {ok, cookie, error}. Las credenciales entran por
 * stdin y nunca se escriben en el log.
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const CHROME_CANDIDATOS = [
  process.env.CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium',
].filter(Boolean);

const URL_LOGIN =
  'https://login.sura.com/sso/servicelogin.aspx' +
  '?continueTo=https%3A%2F%2Fwww.arlsura.com%2Fcomponent%2Farl_login&service=arpsura';

// Cualquier acción del legacy sirve para que se cree la sesión de arpsura, que
// es el dominio cuyas cookies necesitamos.
const URL_LEGACY = 'https://arpsura.suramericana.com/servicios-linea/gestorURLWeb3.redireccionar.sl?opcion=008';

const salir = (data) => { console.log(JSON.stringify(data)); process.exit(data.ok ? 0 : 1); };

// La entrada llega por stdin, no por argumento: un argv con la contraseña
// queda visible en `ps` para cualquier usuario de la máquina.
const leerStdin = async () => {
  let datos = '';
  for await (const trozo of process.stdin) datos += trozo;
  return datos.trim();
};

let entrada;
try {
  entrada = JSON.parse(process.argv[2] || await leerStdin() || '{}');
} catch {
  salir({ ok: false, error: 'Entrada JSON inválida.' });
}

const { tipoDocumento = 'C', usuario, contrasena, nitEmpresa } = entrada;
if (!usuario || !contrasena) salir({ ok: false, error: 'Faltan usuario o contraseña.' });

const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const ruta of CHROME_CANDIDATOS) {
    try { await access(ruta); return ruta; } catch {}
  }
  return null;
})();

if (!ejecutable) {
  salir({ ok: false, error: 'No se encontró Chrome. Instálalo o define CHROME_PATH.' });
}

const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: 'new',
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled', // Incapsula mira esta bandera
    '--window-size=1400,900',
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

let pagina;
try {
  pagina = await navegador.newPage();
  await pagina.setViewport({ width: 1400, height: 900 });
  // Sin un UA de navegador real, Incapsula devuelve el reto en vez del login.
  await pagina.setUserAgent(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
  );

  await pagina.goto(URL_LOGIN, { waitUntil: 'networkidle2', timeout: 60000 });

  // Los campos del login, por id. El formulario es ASP.NET y está lleno de
  // inputs ocultos (ViewState), así que buscar "el primer input de texto"
  // acaba escribiendo en uno invisible.
  const SEL_TIPO  = '#ctl00_ContentMain_suraType';
  const SEL_USER  = '#suraName';
  const SEL_CLAVE = '#suraPassword';
  const SEL_ENTRAR = '#session-internet';

  await pagina.waitForSelector(SEL_CLAVE, { visible: true, timeout: 30000 });

  await pagina.select(SEL_TIPO, tipoDocumento).catch(() => {});

  await pagina.click(SEL_USER, { clickCount: 3 });
  await pagina.type(SEL_USER, usuario, { delay: 45 });

  // La contraseña NO se escribe con el teclado: al enfocar el campo, Sura abre
  // un teclado virtual (plugin jQuery Keyboard) con los dígitos en posiciones
  // aleatorias, y el input real queda bloqueado. Es una defensa anti-keylogger,
  // así que hay que pulsar tecla por tecla.
  await pagina.click(SEL_CLAVE);
  await pagina.waitForSelector('.ui-keyboard', { visible: true, timeout: 15000 });

  for (const caracter of contrasena.split('')) {
    // El teclado se rebaraja tras cada pulsación: hay que volver a localizar
    // la tecla cada vez en lugar de guardar posiciones.
    const tecla = `.ui-keyboard button.ui-keyboard-button[data-value="${caracter}"]`;
    const existe = await pagina.$(tecla);

    if (!existe) {
      throw new Error(`El teclado virtual no ofrece la tecla "${caracter}". ¿La contraseña tiene caracteres que ese teclado no muestra?`);
    }

    await pagina.click(tecla);
    await new Promise(r => setTimeout(r, 120));
  }

  // El botón verde del teclado confirma y lo cierra.
  const aceptar = await pagina.$('.ui-keyboard button.ui-keyboard-accept');
  if (aceptar) {
    await aceptar.click();
    await new Promise(r => setTimeout(r, 400));
  }

  // "Iniciar sesión" es un input[type=button] movido por JavaScript, no un
  // submit: pulsar Enter no envía nada y la página se queda igual.
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {}),
    pagina.click(SEL_ENTRAR),
  ]);

  if (await pagina.$(SEL_CLAVE)) {
    const mensaje = await pagina.evaluate(() =>
      (document.body.innerText.match(/.*(incorrect|inválid|bloque|error).*/i) || [''])[0].trim().slice(0, 160)
    );
    throw new Error('El login no pasó' + (mensaje ? `: ${mensaje}` : '. Revisa usuario y contraseña.'));
  }

  // La Sucursal Virtual pide el NIT de la empresa sobre la que se va a trabajar.
  //
  // Este paso es el más frágil: la SVE es una SPA que sigue navegando por su
  // cuenta después del login, y tocar un elemento mientras redirige revienta con
  // "Execution context was destroyed". Por eso se espera a que se calme, se
  // reintenta, y un fallo aquí no aborta: puede que la empresa ya esté elegida.
  if (nitEmpresa) {
    for (let intento = 1; intento <= 3; intento++) {
      try {
        await new Promise(r => setTimeout(r, 3000));

        const campos = [];
        for (const el of await pagina.$$('input[type="text"], input[type="number"], input:not([type])')) {
          const caja = await el.boundingBox();
          if (caja && caja.width > 20 && caja.height > 8) campos.push(el);
        }

        if (!campos.length) break; // no lo está pidiendo: seguir

        await campos[0].click({ clickCount: 3 });
        await campos[0].type(String(nitEmpresa), { delay: 40 });
        await Promise.all([
          pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {}),
          campos[0].press('Enter'),
        ]);
        break;
      } catch (e) {
        if (intento === 3) break;
      }
    }
  }

  // Tocar el legacy para que se cree la sesión de arpsura.
  await pagina.goto(URL_LEGACY, { waitUntil: 'networkidle2', timeout: 60000 });

  const cookies = await pagina.cookies('https://arpsura.suramericana.com');
  const cookie  = cookies.map(c => `${c.name}=${c.value}`).join('; ');

  if (!/JSESSIONID/i.test(cookie)) {
    throw new Error('Se entró, pero no se obtuvo JSESSIONID de arpsura. ¿El usuario tiene acceso a esa empresa?');
  }

  salir({ ok: true, cookie });
} catch (e) {
  // Diagnóstico: sin ver en qué pantalla se quedó, cualquier ajuste es a ciegas.
  let captura = null, url = null, texto = null;
  try {
    if (pagina && !pagina.isClosed()) {
      url   = pagina.url();
      texto = (await pagina.evaluate(() => document.body?.innerText || '')).replace(/\s+/g, ' ').trim().slice(0, 400);
      if (process.env.ARL_DEBUG_DIR) {
        captura = `${process.env.ARL_DEBUG_DIR}/arl-login-fallo.png`;
        await pagina.screenshot({ path: captura, fullPage: true });
      }
    }
  } catch {}
  salir({ ok: false, error: String(e.message || e).slice(0, 300), captura, url, texto });
} finally {
  await navegador.close().catch(() => {});
}
