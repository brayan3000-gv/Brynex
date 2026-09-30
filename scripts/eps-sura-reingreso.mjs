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
import { mkdtemp, readdir, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, texto } from './eps-sura-sesion-comun.mjs';

const URL_MENU = 'https://epsapps.suramericana.com/Semp/faces/pos/transNovedades/srvReingresos.jspx';

/**
 * Tipos de documento de BryNex → los códigos del desplegable del portal, que
 * son numéricos (leídos de la pantalla el 30-sep-2026):
 * 1 CC · 2 CE · 3 Menor sin identificar · 4 NIT · 5 NUIP · 6 Pasaporte ·
 * 7 Registro civil · 8 Tarjeta de identidad · 10 Certificado de nacido vivo ·
 * 11 Salvoconducto · 14 Permiso de protección temporal.
 */
const TIPOS = { CC: '1', CE: '2', NI: '4', NUIP: '5', PA: '6', PP: '6', RC: '7', TI: '8', CN: '10', SC: '11', PT: '14', PPT: '14', PE: '14', PEP: '14' };

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

  // La pantalla se dibuja en varios tiempos (ScriptManager + UpdatePanel): leer
  // el DOM al llegar devolvía solo los hidden de ASP.NET. Se espera a que haya
  // algo con lo que trabajar antes de mirar.
  paso = 'esperar formulario';
  const campoDe = async () => (await marcoConCampo(pagina, 'WucSearchPerson_txtId'))
    ?? (await marcoConCampo(pagina, 'txtId'))
    ?? null;

  let marco = null;
  for (let i = 0; i < 30 && !marco; i++) {
    marco = await campoDe();
    if (!marco) {
      // O el campo aún no existe, o el formulario está en otro marco: sirve
      // cualquier marco que ya tenga controles de verdad, no solo los ocultos.
      for (const f of pagina.frames()) {
        const utiles = await f.evaluate(() => document.querySelectorAll('input:not([type=hidden]),select,textarea').length).catch(() => 0);
        if (utiles > 0) { marco = f; break; }
      }
    }
    if (!marco) await esperar(1000);
  }
  marco ??= pagina.mainFrame();

  // Inventario de lo que hay en pantalla —de TODOS los marcos, con el suyo
  // anotado—: con esto se ajustan los selectores sin radicar nada para verlos.
  paso = 'inventario';
  const inventario = async (f) => f.evaluate(() => Array.from(document.querySelectorAll('input,select,textarea,a[id],button'))
    .filter((e) => e.id || e.name)
    .map((e) => ({
      id: e.id || null,
      name: e.name || null,
      etiqueta: e.tagName.toLowerCase(),
      tipo: e.type || null,
      valor: e.tagName === 'SELECT' ? undefined : String(e.value ?? '').slice(0, 40),
      opciones: e.tagName === 'SELECT' ? Array.from(e.options).slice(0, 25).map((o) => `${o.value}|${o.text}`.slice(0, 60)) : undefined,
      // offsetParent es null en los position:fixed: se mira también el tamaño.
      visible: e.type === 'hidden' ? false : !!(e.offsetParent || e.getClientRects().length),
    }))).catch(() => []);

  const campos = [];
  for (const f of pagina.frames()) {
    for (const c of await inventario(f)) campos.push({ ...c, marco: f.url().slice(-60) });
  }

  if (modo === 'explorar') {
    const textos = [];
    for (const f of pagina.frames()) {
      const t = await f.evaluate(() => document.body?.innerText || '').catch(() => '');
      if (t.trim()) textos.push(t.replace(/\s+/g, ' ').trim().slice(0, 600));
    }

    salir({ ok: true, modo, paso, url: pagina.url(), marcos: pagina.frames().length, campos, texto: textos.join(' ⏐ ').slice(0, 1500) });
  }

  // ── Persona ──
  paso = 'documento';
  const tipo = TIPOS[String(persona.tipo || 'CC').toUpperCase()] || '1';
  await marco.evaluate((t) => {
    const s = document.querySelector('[id$="Repeater1_ctl00_DdlTypeIdentification"]')
      || document.querySelector('[id$="DdlTypeIdentification"]');
    if (!s) return;
    s.value = t;
    s.dispatchEvent(new Event('change', { bubbles: true }));
  }, tipo).catch(() => {});
  await esperar(1200);

  const puesto = await escribir(marco, 'WucSearchPerson_txtId', String(persona.numero).replace(/\D/g, ''));
  if (!puesto) throw new Error('No apareció el campo del documento en la pantalla de reingresos.');

  // El blur no siempre dispara la búsqueda: la lupa del buscador sí.
  const nombreDe = () => marco.evaluate(() => document.querySelector('[id$="WucSearchPerson_txtName"]')?.value?.trim() || '').catch(() => '');
  let nombre = await nombreDe();
  if (!nombre) {
    paso = 'buscar persona';
    await marco.evaluate(() => document.querySelector('[id$="WucSearchPerson_LinkButton1"]')?.click()).catch(() => {});
    for (let i = 0; i < 20 && !nombre; i++) { await esperar(900); nombre = await nombreDe(); }
  }

  const enPantalla = (await texto(pagina)).replace(/\s+/g, ' ').trim();

  if (modo === 'consultar') {
    // El formulario de la novedad se despliega recién cuando el portal
    // encuentra a la persona: se inventaría aquí, con la pantalla ya abierta.
    const despues = [];
    for (const f of pagina.frames()) {
      for (const c of await inventario(f)) if (c.visible || /salary|date|intermediary|save|settlement|option/i.test(c.id || '')) despues.push(c);
    }

    salir({
      ok: true, modo, paso, url: pagina.url(), nombre: nombre || null,
      // Sin nombre no es un reingreso: la persona no está en SURA y lo que
      // corresponde es un traslado, que no se hace por esta pantalla.
      esReingreso: !!nombre,
      campos: despues, alertas, texto: enPantalla.slice(0, 900),
    });
  }

  // ── Novedad ──
  paso = 'datos de la novedad';
  await marco.evaluate((v) => {
    const s = document.querySelector('[id$="DdlSettlementParam"]') || document.querySelector('[id$="DdlContributorType"]');
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
  // Las descargas se habilitan ANTES de guardar: el portal ofrece el soporte de
  // la novedad en cuanto la aplica, y si no hay dónde dejarlo se pierde.
  const carpeta = await mkdtemp(join(tmpdir(), 'sura-reingreso-'));
  const cdp = await pagina.target().createCDPSession();
  await cdp.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: carpeta }).catch(() => {});

  const antes = alertas.length;
  await marco.evaluate(() => document.querySelector('[id$="BtnSaveNovelty"]')?.click());
  for (let i = 0; i < 40 && alertas.length === antes; i++) await esperar(800);

  const despues = (await texto(pagina)).replace(/\s+/g, ' ').trim();
  const alerta = alertas.slice(antes).join(' | ');
  const conError = /error|no se pudo|no fue posible|inconsist/i.test(alerta + ' ' + despues);

  // El soporte: el portal lo baja solo, y si no, deja el enlace «Informe
  // principal» para pedirlo a mano. Se intenta, pero su ausencia no invalida
  // la novedad: eso lo dice el portal, no el archivo.
  paso = 'soporte';
  let soporte = null;
  const esperarArchivo = async (vueltas) => {
    for (let i = 0; i < vueltas; i++) {
      await esperar(1500);
      const n = (await readdir(carpeta).catch(() => [])).find((x) => !x.endsWith('.crdownload'));
      if (n) return n;
    }
    return null;
  };

  let archivo = await esperarArchivo(6);
  if (!archivo && !conError) {
    for (const f of pagina.frames()) {
      const pulsado = await f.evaluate(() => {
        const a = [...document.querySelectorAll('a, input[type=submit], input[type=button], button')]
          .find((e) => /informe principal|soporte|certificad/i.test(e.innerText || e.value || ''));
        if (!a) return false;
        a.click();

        return true;
      }).catch(() => false);
      if (pulsado) { archivo = await esperarArchivo(20); break; }
    }
  }
  if (archivo) {
    const ruta = join(carpeta, archivo);
    soporte = { archivo, ruta, bytes: (await stat(ruta)).size };
  }

  salir({
    ok: !conError,
    modo, paso: 'aplicar novedad', nombre, alerta: alerta || null,
    titulo: await pagina.title().catch(() => null),
    soporte,
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
