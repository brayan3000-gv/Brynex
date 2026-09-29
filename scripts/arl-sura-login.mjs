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
import puppeteer from 'puppeteer-core';
import { loginSso } from './arl-sura-sesion-comun.mjs';

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

let pagina;
try {
  pagina = await navegador.newPage();
  await pagina.setViewport({ width: 1400, height: 900 });
  // El SSO es el mismo de los demás trámites de Sura: un solo login para que
  // un arreglo en uno no se quede sin llegar a los otros.
  await loginSso(pagina, { tipoDocumento, usuario, contrasena }, URL_LOGIN);

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
