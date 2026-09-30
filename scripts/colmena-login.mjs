/**
 * Abre sesión en la Oficina Digital ARL de Colmena y devuelve el token.
 *
 * El portal es un Next.js cuyo backend (`/portal/back/…`) se opera con un JWT
 * corto (3 horas) que viaja en la cookie `token`. Ese token no se puede pedir
 * por HTTP: nace del login de **Azure AD B2C**
 * (`colmenaprodB2C.b2clogin.com`, política `B2C_1_oficina-virtual-prd-signin`),
 * que es una página con su propio JavaScript y antibot de Imperva delante. Por
 * eso el login lo hace un Chrome de verdad y el resto del trabajo se hace por
 * HTTP desde PHP, igual que en ARL Sura.
 *
 * Un usuario puede tener varios contratos (Global Contact, Homenurse…): el
 * portal pide elegir uno y guarda su consecutivo en la cookie `userContract`.
 * Ese número es el `contractId` que exige cada llamada del API, así que se
 * elige aquí por NIT y se devuelve junto al token.
 *
 * No es un script para correr a mano: lo invoca ColmenaSesionService.
 *
 *   echo '{"usuario":"…","contrasena":"…","nitEmpresa":"901709476"}' \
 *     | node scripts/colmena-login.mjs
 *
 * Imprime en stdout {ok, token, contrato, empresa, contratos[], error}. Las
 * credenciales entran por stdin y nunca se escriben en el log.
 */
import puppeteer from 'puppeteer-core';

const HOST = 'https://portalcliente.colmenaseguros.com';

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

let entrada;
try {
  entrada = JSON.parse(process.argv[2] || await leerStdin() || '{}');
} catch {
  salir({ ok: false, error: 'Entrada JSON inválida.' });
}

const { usuario, contrasena, nitEmpresa, contrato: contratoPedido } = entrada;
if (!usuario || !contrasena) salir({ ok: false, error: 'Faltan usuario o contraseña.' });

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
  headless: 'new',
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
    '--window-size=1400,900',
  ],
});

/** La cookie tal como la ve el navegador, ya decodificada. */
const cookie = async (pagina, nombre) => {
  const todas = await pagina.cookies(HOST);
  const c = todas.find(c => c.name === nombre);
  return c ? decodeURIComponent(c.value) : null;
};

/** El primer elemento visible cuyo texto cumpla la prueba. */
const visibleConTexto = async (pagina, prueba) => {
  const candidatos = await pagina.$$('button, a, [role="button"], li, tr, div[class*="cursor-pointer"]');
  for (const el of candidatos) {
    const texto = await el.evaluate(e => (e.offsetParent !== null ? (e.innerText || '') : '')).catch(() => '');
    if (texto && prueba(texto.replace(/\s+/g, ' ').trim())) return el;
  }
  return null;
};

let pagina;
try {
  pagina = await navegador.newPage();
  await pagina.setViewport({ width: 1400, height: 900 });
  await pagina.setUserAgent(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
  );

  // El portal redirige solo a B2C cuando no hay sesión.
  await pagina.goto(HOST, { waitUntil: 'networkidle2', timeout: 60000 });
  await pagina.waitForSelector('input[type="password"]', { visible: true, timeout: 45000 });

  // B2C nombra los campos igual en todas sus plantillas; los respaldos son por
  // si Colmena cambia la suya.
  const campoUsuario = await pagina.$('#signInName')
    ?? await pagina.$('#email')
    ?? await pagina.$('input[name="signInName"]')
    ?? await pagina.$('input[type="email"]')
    ?? await pagina.$('input[type="text"]');

  if (!campoUsuario) throw new Error('No se encontró el campo de usuario en el login de B2C.');

  await campoUsuario.click({ clickCount: 3 });
  await campoUsuario.type(String(usuario), { delay: 45 });

  const campoClave = await pagina.$('input[type="password"]');
  await campoClave.click({ clickCount: 3 });
  await campoClave.type(String(contrasena), { delay: 45 });

  const boton = await pagina.$('#next') ?? await pagina.$('button[type="submit"]') ?? await pagina.$('input[type="submit"]');
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }).catch(() => {}),
    boton ? boton.click() : campoClave.press('Enter'),
  ]);

  await esperar(3000);

  // ¿Sigue en B2C? Entonces rechazó la clave, y el motivo está en pantalla.
  if (/b2clogin\.com|login\./i.test(pagina.url()) && await pagina.$('input[type="password"]')) {
    const motivo = await pagina.evaluate(() => {
      const t = document.body?.innerText || '';
      const m = t.match(/[^.\n]*(incorrect|inv[áa]lid|no v[áa]lid|bloque|intent|contrase[ñn]a)[^.\n]*\.?/i);
      return m ? m[0].trim().slice(0, 160) : '';
    }).catch(() => '');
    throw new Error('El login no pasó' + (motivo ? `: ${motivo}` : '. Revisa usuario y contraseña.'));
  }

  // El token aparece en cuanto el portal canjea el código de B2C.
  let token = null;
  for (let i = 0; i < 20 && !token; i++) {
    token = await cookie(pagina, 'token');
    if (!token) await esperar(1500);
  }
  if (!token) throw new Error('Se entró, pero el portal no dejó la cookie `token`.');

  // Elegir el contrato. Si el usuario solo tiene uno, el portal lo elige solo.
  const buscado = String(contratoPedido || nitEmpresa || '').replace(/\D/g, '');
  let contrato = await cookie(pagina, 'userContract');
  let empresa = null;
  let contratos = [];

  if (buscado) {
    for (let vuelta = 1; vuelta <= 8 && (!contrato || (contratoPedido && contrato !== String(contratoPedido))); vuelta++) {
      await esperar(2000);

      // Lo que la pantalla de selección ofrece, para poder explicar un fallo.
      contratos = await pagina.evaluate(() => {
        const t = document.body?.innerText || '';
        return t.split('\n').map(l => l.trim()).filter(l => /\b\d{6,10}\b/.test(l)).slice(0, 12);
      }).catch(() => []);

      const fila = await visibleConTexto(pagina, txt => txt.replace(/\D/g, '').includes(buscado));
      if (fila) {
        empresa = await fila.evaluate(e => (e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120)).catch(() => null);
        await Promise.all([
          pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
          fila.click().catch(() => {}),
        ]);
        await esperar(2500);
      }

      contrato = await cookie(pagina, 'userContract');
      if (contrato && !fila) break; // ya estaba elegido y no hay pantalla que tocar
    }
  }

  // Con un solo contrato el portal salta la selección y cae en «Selecciona un
  // módulo»: el contrato queda en la cookie al entrar a Oficina Digital.
  if (!contrato) {
    const modulo = await visibleConTexto(pagina, txt => /oficina digital/i.test(txt));
    if (modulo) {
      await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
        modulo.click().catch(() => {}),
      ]);
      for (let i = 0; i < 6 && !contrato; i++) {
        await esperar(1500);
        contrato = await cookie(pagina, 'userContract');
      }
      // Las pantallas de Dependientes sí llaman con contractId=…
      if (!contrato) {
        for (const paso of [/^dependientes$/i, /^novedades$/i, /ingresos?$/i, /^reportes$/i, /afiliados|vigentes/i]) {
          const el = await visibleConTexto(pagina, txt => paso.test(txt) && txt.length < 60);
          if (el) { await el.evaluate(e => e.scrollIntoView({block:'center'})).catch(() => {}); await el.click().catch(() => {}); await esperar(4000); }
        }
      }
      // Sin selección no hay cookie: el id del contrato viaja en las llamadas
      // del propio portal (contractId=…) y en el almacenamiento de la página.
      if (!contrato) {
        contrato = await pagina.evaluate(() => {
          for (const almacen of [localStorage, sessionStorage]) {
            for (const k of Object.keys(almacen)) {
              if (/contract/i.test(k) && /^\d{4,}$/.test(String(almacen.getItem(k)).replace(/"/g, ''))) return String(almacen.getItem(k)).replace(/"/g, '');
            }
          }
          const vistos = performance.getEntriesByType('resource').map(r => r.name).join(' ');
          const m = vistos.match(/contractId=(\d+)/i);
          return m ? m[1] : null;
        }).catch(() => null);
      }
    }
  }

  contrato ??= await cookie(pagina, 'userContract');
  if (!contrato) {
    throw new Error(
      `Se entró, pero no se pudo elegir el contrato${buscado ? ` de ${buscado}` : ''}.` +
      (contratos.length ? ` La pantalla ofrece: ${contratos.join(' | ')}` : '')
    );
  }

  salir({ ok: true, token, contrato: String(contrato), empresa, contratos });
} catch (e) {
  let url = null, texto = null, captura = null;
  try {
    if (pagina && !pagina.isClosed()) {
      url = pagina.url();
      texto = (await pagina.evaluate(() => document.body?.innerText || '')).replace(/\s+/g, ' ').trim().slice(0, 400);
      if (process.env.ARL_DEBUG_DIR) {
        captura = `${process.env.ARL_DEBUG_DIR}/colmena-login-fallo.png`;
        await pagina.screenshot({ path: captura, fullPage: true });
      }
    }
  } catch {}
  salir({ ok: false, error: String(e.message || e).slice(0, 300), url, texto, captura });
} finally {
  await navegador.close().catch(() => {});
}
