/**
 * Reingreso de un trabajador en el portal de empleadores de EPS SURA.
 *
 * Es el trámite de Transacciones → Afiliados → Reingresos, que el portal
 * atiende en otra aplicación: `solucionesenlineaeps.suramericana.com/reingresos`,
 * ASP.NET WebForms con postbacks (no hay API JSON detrás).
 *
 * Tres modos, de menos a más comprometido:
 *   - explorar:  entra a la pantalla y devuelve el inventario de campos. No
 *                escribe nada. Sirve para ajustar los selectores sin radicar.
 *   - consultar: escribe el documento para que el portal traiga el nombre y
 *                dice si la persona existe para reingreso. No guarda.
 *   - registrar: además completa la novedad y pulsa «Aplicar Novedad».
 *
 * Los campos se buscan por el FINAL del id (`[id$="..."]`) porque ASP.NET les
 * antepone el árbol de controles (`AffiliationReadmissions1_Repeater1_ctl00_…`)
 * y ese prefijo cambia entre pantallas.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa,
 *                     modo: 'explorar'|'consultar'|'registrar',
 *                     persona: {tipo, numero}, ibc, fechaIngreso: 'YYYY-MM-DD',
 *                     tipoCotizante?, asesor?}
 * Salida por stdout: {ok, paso, modo, campos?, nombre?, mensajes, alerta?, error?}
 */
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, texto } from './eps-sura-sesion-comun.mjs';

const URL_MENU = 'https://epsapps.suramericana.com/Semp/faces/pos/transNovedades/srvReingresos.jspx';

// Tipos de documento de BryNex → los que usa el buscador de personas.
const TIPOS = { CC: 'CC', CE: 'CE', PA: 'PA', PP: 'PA', TI: 'TI', RC: 'RC', PT: 'PT', PPT: 'PT', PE: 'PE', PEP: 'PE', SC: 'SC', CD: 'CD' };

// «1 Dependiente» en el desplegable de tipo de cotizante: el value NO es el
// código de cotizante de la PILA (4 = doméstico, 51 = veterano…).
const COTIZANTE_DEPENDIENTE = '2';

const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

let entrada;
try { entrada = JSON.parse(await leerStdin() || '{}'); }
catch { salir({ ok: false, error: 'Entrada JSON inválida.' }); }

const { usuario, contrasena, nitEmpresa, persona = {}, ibc, fechaIngreso } = entrada;
const modo = ['explorar', 'consultar', 'registrar'].includes(entrada.modo) ? entrada.modo : 'explorar';
const asesor = String(entrada.asesor ?? '0');
const tipoCotizante = String(entrada.tipoCotizante ?? COTIZANTE_DEPENDIENTE);

if (!usuario || !contrasena || !nitEmpresa) salir({ ok: false, error: 'Faltan credenciales o NIT de la empresa.' });
if (modo !== 'explorar') {
  if (!persona.numero) salir({ ok: false, error: 'Falta el documento de la persona.' });
  if (modo === 'registrar' && (!ibc || !fechaIngreso)) salir({ ok: false, error: 'Faltan IBC o fecha de ingreso.' });
}

const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const r of rutaChrome()) { try { await access(r); return r; } catch {} }
  return null;
})();
if (!ejecutable) salir({ ok: false, error: 'No se encontró Chrome. Define CHROME_PATH.' });

const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: 'new',
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-blink-features=AutomationControlled'],
});

let pagina;
let paso = 'inicio';
const alertas = [];

/** El marco donde vive el formulario: la app de reingresos puede ir en iframe. */
const marcoConCampo = async (pag, sufijo) => {
  for (const marco of pag.frames()) {
    try { if (await marco.$(`[id$="${sufijo}"]`)) return marco; } catch {}
  }
  return null;
};

/** Escribe en un input de ASP.NET y deja que su postback termine. */
const escribir = async (marco, sufijo, valor, { conBlur = true } = {}) => {
  const sel = `[id$="${sufijo}"]`;
  const campo = await marco.$(sel);
  if (!campo) return false;

  await campo.click({ clickCount: 3 }).catch(() => {});
  // Las máscaras de estas pantallas ignoran el tecleo simulado; el valor se
  // asigna directo y se avisa con los eventos que ASP.NET escucha.
  await marco.evaluate((s, v) => {
    const e = document.querySelector(s);
    if (!e) return;
    e.value = v;
    e.dispatchEvent(new Event('input', { bubbles: true }));
    e.dispatchEvent(new Event('change', { bubbles: true }));
  }, sel, String(valor));

  if (conBlur) {
    await marco.evaluate((s) => document.querySelector(s)?.blur(), sel).catch(() => {});
    await esperar(2500);   // el blur dispara el postback que trae los datos
  }
  return true;
};

try {
  pagina = await navegador.newPage();

  // El resultado de «Aplicar Novedad» llega en un alert inyectado por el
  // postback, no en la pantalla: hay que escucharlo.
  pagina.on('dialog', async (d) => {
    alertas.push(d.message());
    await d.accept().catch(() => {});
  });

  paso = 'login';
  await entrarEmpresaEps(pagina, entrada);

  paso = 'abrir reingresos';
  await pagina.goto(URL_MENU, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  // El menú JSF redirige solo a la aplicación de reingresos; darle tiempo.
  for (let i = 0; i < 20 && !/reingresos/i.test(pagina.url()); i++) await esperar(1000);

  const marco = (await marcoConCampo(pagina, 'WucSearchPerson_txtId'))
    ?? (await marcoConCampo(pagina, 'txtId'))
    ?? pagina.mainFrame();

  // Inventario de lo que hay en pantalla: con esto se ajustan los selectores
  // sin tener que radicar nada para verlos.
  paso = 'inventario';
  const campos = await marco.evaluate(() => Array.from(document.querySelectorAll('input,select,textarea,a[id],button'))
    .filter((e) => e.id || e.name)
    .map((e) => ({
      id: e.id || null,
      name: e.name || null,
      etiqueta: e.tagName.toLowerCase(),
      tipo: e.type || null,
      valor: e.tagName === 'SELECT' ? undefined : String(e.value ?? '').slice(0, 40),
      opciones: e.tagName === 'SELECT' ? Array.from(e.options).slice(0, 25).map((o) => `${o.value}|${o.text}`.slice(0, 60)) : undefined,
      visible: !!(e.offsetParent || e.type === 'hidden'),
    })));

  if (modo === 'explorar') {
    salir({ ok: true, modo, paso, url: pagina.url(), campos, texto: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 1200) });
  }

  // ── Persona ──
  paso = 'documento';
  const tipo = TIPOS[String(persona.tipo || 'CC').toUpperCase()] || 'CC';
  await marco.evaluate((t) => {
    const s = Array.from(document.querySelectorAll('select')).find((x) => /idtype|tipoid|tipodoc/i.test(x.id + x.name));
    if (!s) return;
    const op = Array.from(s.options).find((o) => o.value === t || o.text.trim().toUpperCase().startsWith(t));
    if (op) { s.value = op.value; s.dispatchEvent(new Event('change', { bubbles: true })); }
  }, tipo).catch(() => {});

  const puesto = await escribir(marco, 'WucSearchPerson_txtId', String(persona.numero).replace(/\D/g, ''))
    || await escribir(marco, 'txtId', String(persona.numero).replace(/\D/g, ''));
  if (!puesto) throw new Error('No apareció el campo del documento en la pantalla de reingresos.');

  const nombre = await marco.evaluate(() => document.querySelector('[id$="txtName"]')?.value || null).catch(() => null);
  const enPantalla = (await texto(pagina)).replace(/\s+/g, ' ').trim();

  if (modo === 'consultar') {
    salir({
      ok: true, modo, paso, url: pagina.url(), nombre,
      // Sin nombre no es un reingreso: la persona no está en SURA y lo que
      // corresponde es un traslado, que no se hace por esta pantalla.
      esReingreso: !!(nombre && nombre.trim()),
      alertas, texto: enPantalla.slice(0, 600),
    });
  }

  // ── Novedad ──
  paso = 'datos de la novedad';
  await marco.evaluate((v) => {
    const s = document.querySelector('[id$="DdlSettlementParam"]');
    if (s) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
  }, tipoCotizante).catch(() => {});
  await esperar(1500);

  await escribir(marco, 'TxtSalary', String(Math.round(Number(ibc))), { conBlur: false });

  const [a, m, d] = String(fechaIngreso).split('-');
  await escribir(marco, 'TxtInitialdate', `${d}/${m}/${a}`, { conBlur: false });

  // El asesor es obligatorio aunque el procedimiento de Sura no lo diga: con 0
  // queda «SIN ASESOR- SIN DIRECCIÓN COMERCIAL».
  await escribir(marco, 'tbxIntermediaryCode', asesor);

  paso = 'aplicar novedad';
  const antes = alertas.length;
  await marco.evaluate(() => document.querySelector('[id$="BtnSaveNovelty"]')?.click());
  for (let i = 0; i < 40 && alertas.length === antes; i++) await esperar(800);

  const despues = (await texto(pagina)).replace(/\s+/g, ' ').trim();
  const alerta = alertas.slice(antes).join(' | ');
  const conError = /error|no se pudo|no fue posible|inconsist/i.test(alerta + ' ' + despues);

  salir({
    ok: !conError,
    modo, paso, nombre, alerta: alerta || null,
    titulo: await pagina.title().catch(() => null),
    texto: despues.slice(0, 800),
    error: conError ? (alerta || 'La pantalla quedó con un error tras aplicar la novedad.') : undefined,
  });
} catch (e) {
  let captura = null;
  try {
    if (pagina && !pagina.isClosed() && process.env.ARL_DEBUG_DIR) {
      captura = `${process.env.ARL_DEBUG_DIR}/eps-sura-reingreso-fallo.png`;
      await pagina.screenshot({ path: captura, fullPage: true });
    }
  } catch {}
  salir({ ok: false, modo, paso, error: String(e.message || e).slice(0, 300), alertas, captura });
} finally {
  await navegador.close().catch(() => {});
}
