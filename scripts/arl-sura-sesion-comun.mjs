/**
 * Login en los portales de Sura, compartido por los scripts que lo necesitan.
 *
 * Vive aparte porque la sesión NO se puede pasar de un navegador a otro: Sura
 * está detrás de Imperva Incapsula, que ata la sesión al navegador que la abrió.
 * Inyectar las cookies en un Chrome distinto devuelve la pantalla de login, sin
 * decir por qué. Así que cada proceso que necesite operar abre la suya.
 *
 * ARL y EPS usan el mismo SSO (login.sura.com) con el mismo usuario; solo cambian
 * `service` y `continueTo` en la URL. Por eso el login va en `loginSso()` y cada
 * portal pone encima lo suyo: la ARL atraviesa la Sucursal Virtual, la EPS elige
 * la empresa en su propia pantalla.
 */

const URL_LOGIN =
  'https://login.sura.com/sso/servicelogin.aspx' +
  '?continueTo=https%3A%2F%2Fwww.arlsura.com%2Fcomponent%2Farl_login&service=arpsura';

const SEL_TIPO   = '#ctl00_ContentMain_suraType';
const SEL_USER   = '#suraName';
const SEL_CLAVE  = '#suraPassword';
const SEL_ENTRAR = '#session-internet';

const esperar = (ms) => new Promise(r => setTimeout(r, ms));

/**
 * Pasa el SSO de login.sura.com. Deja la página donde el SSO redirija
 * (`continueTo`), o lanza con el motivo que muestre el portal.
 */
export async function loginSso(pagina, { tipoDocumento = 'C', usuario, contrasena }, urlLogin) {
  await pagina.setUserAgent(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
  );

  await pagina.goto(urlLogin, { waitUntil: 'networkidle2', timeout: 60000 });
  await pagina.waitForSelector(SEL_CLAVE, { visible: true, timeout: 30000 });

  await pagina.select(SEL_TIPO, tipoDocumento).catch(() => {});
  await pagina.click(SEL_USER, { clickCount: 3 });
  await pagina.type(SEL_USER, usuario, { delay: 45 });

  // La contraseña se teclea en el teclado virtual: el input real está bloqueado
  // y los dígitos cambian de sitio en cada pulsación.
  await pagina.click(SEL_CLAVE);
  await pagina.waitForSelector('.ui-keyboard', { visible: true, timeout: 15000 });

  for (const caracter of contrasena.split('')) {
    const tecla = `.ui-keyboard button.ui-keyboard-button[data-value="${caracter}"]`;
    if (!await pagina.$(tecla)) {
      throw new Error(`El teclado virtual no ofrece la tecla "${caracter}".`);
    }
    await pagina.click(tecla);
    await esperar(120);
  }

  const aceptar = await pagina.$('.ui-keyboard button.ui-keyboard-accept');
  if (aceptar) { await aceptar.click(); await esperar(400); }

  // "Iniciar sesión" es un input[type=button] con JavaScript: Enter no envía.
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {}),
    pagina.click(SEL_ENTRAR),
  ]);

  // Tras el submit la página puede seguir redirigiendo; comprobar en ese momento
  // revienta con "Execution context was destroyed". Se deja asentar primero.
  await esperar(2500);

  // Visible, no solo presente: una página que ya entró puede traer el
  // formulario escondido en el DOM, y contarlo como "sigue pidiendo la clave"
  // da un falso "el login no pasó".
  let siguePidiendoClave = false;
  try {
    siguePidiendoClave = await pagina.$eval(SEL_CLAVE, e => e.offsetParent !== null).catch(() => false);
  } catch {
    siguePidiendoClave = false; // navegando: señal de que sí entró
  }

  if (siguePidiendoClave) {
    // El portal escribe el motivo en rojo bajo el formulario ("Usuario o
    // contraseña no válidos", "Usuario bloqueado"...). Repetirlo tal cual le
    // ahorra a quien lo ve tener que adivinar qué salió mal.
    let motivo = '';
    try {
      motivo = await pagina.evaluate(() => {
        const t = document.body?.innerText || '';
        const m = t.match(/[^.\n]*(no v\u00e1lid|inv\u00e1lid|incorrect|no son correct|no coincide|bloque|inactiv|expir|vencid|caducad|cambiar (su |la )?contrase|intentos)[^.\n]*\.?/i);
        return m ? m[0].trim().slice(0, 120) : '';
      });
    } catch {}

    throw new Error(motivo || 'El login no pasó. Revisa usuario y contraseña.');
  }
}

export async function iniciarSesion(pagina, { tipoDocumento = 'C', usuario, contrasena, nitEmpresa }) {
  await loginSso(pagina, { tipoDocumento, usuario, contrasena }, URL_LOGIN);

  // Después del login hay que atravesar la Sucursal Virtual: primero
  // "Selecciona el módulo" (solo un botón Ingresar) y luego el NIT de la
  // empresa. Sin eso la sesión no tiene empresa y cualquier trámite del legacy
  // rebota a esta misma pantalla.
  //
  // La SVE es Angular con web components y sus controles viven en shadow DOM:
  // `document.querySelector` no los ve. Por eso se usa el selector `pierce/` de
  // Puppeteer, que sí entra en los shadow roots.
  if (nitEmpresa) {
    for (let vuelta = 1; vuelta <= 6; vuelta++) {
      await esperar(2500);

      // ¿Pide el NIT? Se escribe.
      const campos = await pagina.$$('pierce/input');
      for (const campo of campos) {
        const usable = await campo.evaluate(e =>
          e.offsetParent !== null && !['hidden', 'checkbox', 'radio'].includes(e.type)).catch(() => false);
        if (usable) {
          await campo.click({ clickCount: 3 }).catch(() => {});
          await campo.type(String(nitEmpresa), { delay: 40 }).catch(() => {});
          break;
        }
      }

      // Pulsar "Ingresar" / "Continuar", esté donde esté.
      const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
      let pulsado = false;
      // `pierce/` no acepta listas separadas por coma: una consulta por tipo.
      const candidatos = [
        ...await pagina.$$('pierce/button'),
        ...await pagina.$$('pierce/a'),
        ...await pagina.$$('pierce/input'),
      ];
      for (const b of candidatos) {
        const texto = await b.evaluate(e =>
          e.offsetParent !== null ? (e.innerText || e.value || '') : '').catch(() => '');
        if (/ingresar|continuar/i.test(texto)) {
          await b.click().catch(() => {});
          pulsado = true;
          break;
        }
      }
      await navegacion;

      // Ya dentro: el legacy responde sin devolvernos a la SVE.
      const dentro = await pagina.evaluate(() =>
        !/Selecciona el m\u00f3dulo|n\u00famero de identificaci\u00f3n de la empresa/i.test(document.body.innerText || '')
      ).catch(() => false);

      if (!pulsado && dentro) break;
    }
  }

  // Tocar el legacy para que nazca la sesión de arpsura.
  await pagina.goto(
    'https://arpsura.suramericana.com/servicios-linea/gestorURLWeb3.redireccionar.sl?opcion=008',
    { waitUntil: 'networkidle2', timeout: 60000 }
  ).catch(() => {});
}

export function rutaChrome() {
  return [
    process.env.CHROME_PATH,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
  ].filter(Boolean);
}
