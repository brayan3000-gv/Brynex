/**
 * Entrada al portal de empleadores de EPS SURA, compartida por los scripts de
 * EPS (consultar cotizantes, bajar el informe de afiliados).
 *
 * Mismo SSO que la ARL (ver arl-sura-sesion-comun.mjs); después del login la EPS
 * pide el NIT de la empresa en su propia pantalla. Un usuario que administra
 * varias empresas cambia de una a otra ahí mismo, sin volver a iniciar sesión.
 *
 * Semp (JSF) encadena redirecciones por JavaScript después de cargar: leer el
 * DOM en ese momento revienta con "Execution context was destroyed", por eso
 * todo lo que lee la página va con reintentos.
 */
import { loginSso } from './arl-sura-sesion-comun.mjs';
import { mkdirSync, writeFileSync } from 'node:fs';

const URL_LOGIN =
  'https://login.sura.com/sso/servicelogin.aspx' +
  '?continueTo=https%3A%2F%2Fepsapps.suramericana.com%2FSemp%2F&service=epssura';
const URL_EMPRESA = 'https://epsapps.suramericana.com/Semp/faces/empleadores/login/loginEmpresas.jspx';

export const esperar = (ms) => new Promise(r => setTimeout(r, ms));

export const texto = (pagina) => pagina.evaluate(() => document.body?.innerText || '').catch(() => '');

const esContextoPerdido = (e) =>
  /Execution context was destroyed|Cannot find context|Target closed/i.test(String(e?.message || e));

/**
 * Espera a que la página deje de redirigir y muestre uno de los selectores.
 *
 * @returns el primer selector encontrado, o null si se agotó el tiempo.
 */
export const esperarUno = async (pagina, selectores, ms = 30000) => {
  const limite = Date.now() + ms;
  while (Date.now() < limite) {
    try {
      for (const s of selectores) {
        if (await pagina.$(s)) return s;
      }
    } catch (e) {
      if (!esContextoPerdido(e)) throw e;
    }
    await esperar(700);
  }
  return null;
};

/**
 * Pulsa un control por id y espera lo que el clic dispare. Los botones de JSF
 * son <a onclick="mojarra.jsfcljs(...)"> que envían el formulario: el evaluate
 * puede reventar con el contexto destruido justo porque sí navegó.
 */
export const pulsarId = async (pagina, id) => {
  const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
  try {
    await pagina.evaluate((i) => document.getElementById(i)?.click(), id);
  } catch (e) {
    if (!esContextoPerdido(e)) throw e;
  }
  await navegacion;
};

/**
 * Inicia sesión y deja elegida la empresa. Lanza con el motivo si no puede.
 */
export async function entrarEmpresaEps(pagina, { tipoDocumento, usuario, contrasena, nitEmpresa }) {
  await loginSso(pagina, { tipoDocumento, usuario, contrasena }, URL_LOGIN);

  // El SSO todavía puede estar llegando a Semp: se deja asentar antes de ir.
  await esperar(3000);
  await pagina.goto(URL_EMPRESA, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});

  const SEL_NIT = '[id="loginEmpresas:dniEmpresa"]';
  const pantalla = await esperarUno(pagina, [SEL_NIT, 'a[href*="afiliadosCotizantes"]', '#suraPassword']);

  if (pantalla === '#suraPassword' || /servicelogin/i.test(pagina.url())) {
    throw new Error('El portal de EPS pidió login de nuevo después de iniciar sesión.');
  }
  if (!pantalla) {
    throw new Error('No apareció ni la selección de empresa ni el menú del portal de EPS.');
  }
  if (pantalla !== SEL_NIT) {
    // Los enlaces del menú también existen en la pantalla de selección, así que
    // pueden ganarle al campo del NIT mientras se dibuja. Concluir "una sola
    // empresa" en ese instante dejaba la sesión en la empresa por defecto del
    // usuario: el 15-sep-2026 el informe de Global Contact salió de otra
    // empresa (0 de 77 coincidencias). Solo se da por única si el campo no
    // aparece en unos segundos.
    let campo = null;
    for (let i = 0; i < 10 && !campo; i++) {
      await esperar(600);
      campo = await pagina.$(SEL_NIT).catch(() => null);
    }
    if (!campo) return; // una sola empresa: el portal no pregunta
  }

  // Se intenta varias veces recargando la pantalla. El SSO sigue asentándose
  // cuando el robot llega aquí, y si la vista se dibujó antes de que la sesión
  // de Semp estuviera lista, el formulario se envía contra un ViewState que ya
  // no vale: el portal devuelve la misma pantalla con el NIT escrito y el aviso
  // «Usted no tiene acceso a los recursos de esta Aplicación», que parece falta
  // de permiso y no lo es —el 2-oct-2026 el mismo usuario entró a mano a esa
  // empresa sin problema mientras el robot fallaba—. Una recarga limpia basta.
  let sigue = true;

  for (let intento = 1; intento <= 3 && sigue; intento++) {
    if (intento > 1) {
      await pagina.goto(URL_EMPRESA, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
      await esperar(2000);
      if (! await pagina.$(SEL_NIT).catch(() => null)) return; // ya quedó dentro
    }

    await pagina.select('[id="loginEmpresas:tipoDniEmpresa"]', 'NI').catch(() => {});
    await pagina.click(SEL_NIT, { clickCount: 3 });
    await pagina.type(SEL_NIT, String(nitEmpresa), { delay: 40 });
    await pulsarId(pagina, 'loginEmpresas:generar');

    // Entró cuando el formulario de la empresa desaparece; si sigue ahí, el
    // portal no la aceptó (el usuario no la administra, NIT mal escrito, o la
    // vista venía caducada). Los enlaces del menú no sirven de señal: también
    // existen en esta pantalla.
    sigue = true;
    for (let i = 0; i < 25 && sigue; i++) {
      await esperar(800);
      try { sigue = !!(await pagina.$(SEL_NIT)); } catch { sigue = true; }
    }

    if (sigue) {
      process.stderr.write(`@paso la empresa ${nitEmpresa} no entró (intento ${intento} de 3)\n`);
    }
  }

  if (sigue) {
    const t = (await texto(pagina)).replace(/\s+/g, ' ').trim();
    await dejarRastro(pagina, `empresa-${nitEmpresa}`);
    throw new Error(`El portal no aceptó la empresa ${nitEmpresa}: ${t.slice(0, 200)}`);
  }
}

/**
 * Captura y HTML de la pantalla donde se atascó.
 *
 * El mensaje del portal no alcanza para saber por qué: la misma pantalla sale
 * cuando el usuario no administra la empresa, cuando el NIT no entró en el
 * campo y cuando el botón no llegó a pulsarse. A mano el mismo usuario entra
 * sin problema (probado con LALA GROUP el 2-oct-2026), así que la diferencia
 * está en lo que ve el robot y hay que poder mirarlo.
 */
async function dejarRastro(pagina, nombre) {
  try {
    const carpeta = 'storage/app/robots/eps-sura';
    mkdirSync(carpeta, { recursive: true });
    const sello = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    await pagina.screenshot({ path: `${carpeta}/${nombre}-${sello}.png`, fullPage: true });
    writeFileSync(`${carpeta}/${nombre}-${sello}.html`, await pagina.content());
    process.stderr.write(`@paso rastro guardado en ${carpeta}/${nombre}-${sello}\n`);
  } catch (e) {
    process.stderr.write(`@paso no se pudo guardar el rastro: ${e.message}\n`);
  }
}
