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
import { mkdtemp, readdir, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, texto } from './eps-sura-sesion-comun.mjs';

/**
 * Baja el comprobante en PDF exportándolo desde el visor de informes.
 *
 * No es el botón «Descargar Documento» —ese solo reabre la página para
 * imprimirla y devuelve HTML—, sino lo que hace el icono «Exportar este
 * informe» de la barra del visor: un POST a la propia página, con el visor
 * como destino del evento y el formato en el argumento, que responde con
 * «application/pdf». Como va por fetch con la sesión puesta, funciona igual
 * desde el servidor, sin descargas ni ventanas emergentes de por medio.
 */
async function exportarComprobante(pagina, carpeta) {
  for (const marco of pagina.frames()) {
    const base64 = await marco.evaluate(async () => {
      const form = document.forms[0];
      if (!form) return null;

      // El visor se reconoce por el campo donde guarda su estado: lo que sigue
      // a ese prefijo es su identificador para el postback.
      const estado = [...form.elements].find((e) => (e.name || '').startsWith('__CRYSTALSTATE'));
      if (!estado) return null;

      const cuerpo = new URLSearchParams();
      for (const el of form.elements) {
        if (!el.name) continue;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
        cuerpo.append(el.name, el.value || '');
      }
      cuerpo.set('__EVENTTARGET', estado.name.slice('__CRYSTALSTATE'.length));
      cuerpo.set('__EVENTARGUMENT', '{"text":"PDF", "range":"false", "tb":"crexport"}');

      const res = await fetch(location.href, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: cuerpo.toString(),
      });
      if (!res.ok) return null;

      const buf = new Uint8Array(await res.arrayBuffer());
      if (buf[0] !== 0x25 || buf[1] !== 0x50) return null;       // %P de %PDF

      let s = '';
      for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode(...buf.subarray(i, i + 0x8000));

      return btoa(s);
    }).catch(() => null);

    if (!base64) continue;

    const destino = join(carpeta, `comprobante_${Date.now()}.pdf`);
    await writeFile(destino, Buffer.from(base64, 'base64'));
    const { size } = await stat(destino);

    if (size > 5000) return { archivo: 'comprobante_reingreso.pdf', ruta: destino, bytes: size };
  }

  return null;
}

const URL_MENU = 'https://epsapps.suramericana.com/Semp/faces/pos/transNovedades/srvReingresos.jspx';
const URL_CERTIFICADO = 'https://epsapps.suramericana.com/Semp/faces/pos/certificados/afiliacionPos/parametros.jspx';

// Esa pantalla usa las siglas, no los códigos numéricos del reingreso.
const TIPOS_CERTIFICADO = { CC: 'CC', CE: 'CE', PA: 'PA', PP: 'PA', TI: 'TI', RC: 'RC', PT: 'PT', PPT: 'PT', PE: 'PE', PEP: 'PE', SC: 'SC', CD: 'CD' };

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
const modo = ['explorar', 'consultar', 'registrar', 'certificado', 'lote'].includes(entrada.modo) ? entrada.modo : 'explorar';
const asesor = String(entrada.asesor ?? '0');
const tipoCotizante = String(entrada.tipoCotizante ?? COTIZANTE_DEPENDIENTE);

if (!usuario || !contrasena || !nitEmpresa) salir({ ok: false, error: 'Faltan credenciales o NIT de la empresa.' });

if (modo === 'lote') {
  // En un lote los datos de cada quien van en la lista, no sueltos.
  const gente = Array.isArray(entrada.personas) ? entrada.personas : [];
  if (!gente.length) salir({ ok: false, error: 'El lote llegó sin personas.' });
  const incompleta = gente.find((q) => !q?.persona?.numero || !q.ibc || !q.fechaIngreso);
  if (incompleta) salir({ ok: false, error: 'Alguien del lote viene sin documento, IBC o fecha de ingreso.' });
} else if (modo !== 'explorar') {
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
  // Con ventana (xvfb-run pone el display en el servidor) el visor del
  // comprobante sí se dibuja: sin ella, Crystal Reports no pinta nada y por eso
  // ni se podía leer el resultado ni imprimir la pantalla.
  headless: process.env.SURA_CON_VENTANA === '1' ? false : 'new',
  args: [
    '--no-sandbox', '--disable-dev-shm-usage', '--disable-blink-features=AutomationControlled',
    // El comprobante se baja desde una ventana emergente: con el bloqueo puesto
    // esa ventana nunca se abre y la descarga no llega a ninguna parte.
    '--disable-popup-blocking',
  ],
});

let pagina;
let paso = 'inicio';

/**
 * Deja el paso en curso y lo cuenta por stderr, donde BryNex lo lee en vivo
 * para enseñarlo en pantalla. Va por stderr a propósito: stdout lleva el JSON
 * del resultado y mezclar las dos cosas lo rompería.
 */
const vaPor = (texto) => {
  paso = texto;
  try { process.stderr.write(`@paso ${texto}\n`); } catch { /* da igual si no se puede */ }

  return texto;
};
const alertas = [];
const nuevasVentanas = [];

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

  vaPor('login');
  await entrarEmpresaEps(pagina, entrada);

  /**
   * Deja las descargas en una carpeta propia y devuelve el primer archivo que llegue.
   *
   * El permiso va a nivel de NAVEGADOR, no de página: el portal entrega el
   * certificado como respuesta de un POST de navegación (200 con
   * Content-Disposition, que Chrome anota como ERR_ABORTED), y esa descarga no
   * pasa por el CDP de la pestaña. Con `Page.setDownloadBehavior` a secas el
   * archivo no aterrizaba en ninguna parte —comprobado en el portal el
   * 30-sep-2026—. El de página se deja además, por si acaso.
   */
  const conDescargas = async () => {
    const carpeta = await mkdtemp(join(tmpdir(), 'sura-'));
    const cdpNavegador = await navegador.target().createCDPSession();
    await cdpNavegador.send('Browser.setDownloadBehavior', {
      behavior: 'allow', downloadPath: carpeta, eventsEnabled: true,
    }).catch(() => {});
    const cdp = await pagina.target().createCDPSession();
    await cdp.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: carpeta }).catch(() => {});

    return {
      carpeta,
      esperar: async (vueltas) => {
        for (let i = 0; i < vueltas; i++) {
          await esperar(1500);
          const n = (await readdir(carpeta).catch(() => [])).find((x) => !x.endsWith('.crdownload'));
          if (n) {
            const ruta = join(carpeta, n);

            return { archivo: n, ruta, bytes: (await stat(ruta)).size };
          }
        }

        return null;
      },
    };
  };

  // ── Certificado de afiliación al PBS ──
  // Solo lee: se puede pedir las veces que haga falta, y sirve igual para quien
  // ya estaba afiliado. Es el documento que se guarda con el radicado.
  if (modo === 'certificado') {
    vaPor('certificado');
    const descarga = await conDescargas();

    await pagina.goto(URL_CERTIFICADO, { waitUntil: 'networkidle2', timeout: 60000 });
    await pagina.waitForSelector('[id="certificadoAfiliacionPos:numeroIdentificacion"]', { visible: true, timeout: 30000 });

    await pagina.select('[id="certificadoAfiliacionPos:tipoIdentificacion"]',
      TIPOS_CERTIFICADO[String(persona.tipo || 'CC').toUpperCase()] || 'CC').catch(() => {});
    await pagina.click('[id="certificadoAfiliacionPos:numeroIdentificacion"]', { clickCount: 3 });
    await pagina.type('[id="certificadoAfiliacionPos:numeroIdentificacion"]', String(persona.numero).replace(/\D/g, ''), { delay: 30 });
    await pagina.keyboard.press('Tab');
    await esperar(2000);

    const pulsado = await pagina.evaluate(() => {
      const b = [...document.querySelectorAll('a, button, input[type=submit], input[type=button]')]
        .find((e) => /generar|consultar/i.test(e.innerText || e.value || ''));
      if (!b) return false;
      b.click();

      return true;
    });
    if (!pulsado) throw new Error('No apareció el botón de generar el certificado.');

    const soporte = await descarga.esperar(25);
    const pantalla = (await texto(pagina)).replace(/\s+/g, ' ').trim();

    // Sin certificado el portal suele decir por qué (no está afiliado, no es de
    // esta empresa): ese texto vale más que un «no se pudo». Pero la pantalla
    // empieza SIEMPRE con el mismo párrafo de ayuda, así que recortarla por el
    // principio devolvía ese párrafo y escondía justo el motivo. Se busca la
    // frase que lo dice y, si no aparece, se manda el final, que es donde el
    // portal lo escribe.
    const motivo = (pantalla.match(/((?:no |sin )(?:se |hay )?(?:encontr|est[aá]|existe|pertenece|tiene|aparece|registra|afiliad|result|informaci)[^.]{0,200}\.?)/i) || [])[1]?.trim()
      || (pantalla.length > 300 ? '…'.concat(pantalla.slice(-300)) : pantalla);

    salir({
      ok: !!soporte, modo, paso, soporte,
      texto: pantalla.slice(0, 600),
      error: soporte ? undefined : 'El portal no entregó el certificado: '.concat(motivo),
    });
  }

  vaPor('abrir reingresos');
  await pagina.goto(URL_MENU, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  // El menú JSF redirige solo a la aplicación de reingresos; darle tiempo.
  for (let i = 0; i < 20 && !/reingresos/i.test(pagina.url()); i++) await esperar(1000);

  // La pantalla se dibuja en varios tiempos (ScriptManager + UpdatePanel): leer
  // el DOM al llegar devolvía solo los hidden de ASP.NET. Se espera a que haya
  // algo con lo que trabajar antes de mirar.
  vaPor('esperar formulario');
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
  vaPor('inventario');
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

  // La pantalla trae el NIT del empleador con el que se entró. Se compara con el
  // del contrato: si el usuario administra una sola empresa el portal no
  // pregunta cuál, y sin esta comprobación se podría radicar a la persona en la
  // empresa equivocada sin que nada avisara.
  vaPor('empresa');
  const nitPantalla = (await marco.evaluate(() => document.querySelector('[id$="TbxEmployerId"]')?.value || '').catch(() => '')).replace(/\D/g, '');
  const nitEsperado = String(nitEmpresa).replace(/\D/g, '');
  if (nitPantalla && nitEsperado && nitPantalla !== nitEsperado) {
    throw new Error(`El portal está en la empresa ${nitPantalla} y el contrato es de la ${nitEsperado}: no se toca nada.`);
  }

  /**
   * Hace el trámite de UNA persona con la sesión ya abierta y la empresa
   * elegida, y devuelve cómo fue. Separarlo así es lo que permite atender a
   * varias seguidas sin volver a entrar al portal: el login son veintitantos
   * segundos y se repetía en cada una.
   */
  const tramitarPersona = async ({ persona = {}, ibc, fechaIngreso, tipoCotizante = COTIZANTE_DEPENDIENTE, asesor = '0' }) => {
    // Tras cada trámite la pantalla es otra, así que el marco se vuelve a buscar.
    marco = (await campoDe()) ?? marco;

    // ── Persona ──
    vaPor('documento');
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
      vaPor('buscar persona');
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

      return {
        ok: true, modo, paso, url: pagina.url(), nombre: nombre || null,
        // Para saber si de verdad corrió con ventana (xvfb) o sin ella.
        conVentana: process.env.SURA_CON_VENTANA === '1',
        display: process.env.DISPLAY || null,
        // Sin nombre no es un reingreso: la persona no está en SURA y lo que
        // corresponde es un traslado, que no se hace por esta pantalla.
        esReingreso: !!nombre,
        campos: despues, alertas, texto: enPantalla.slice(0, 900),
      };
    }

    // ── Novedad ──
    vaPor('datos de la novedad');

    // El tipo de cotizante es AutoPostBack y recarga la página ENTERA, así que
    // hay que esperar esa recarga antes de escribir lo demás. Con una espera
    // fija era una carrera: el salario, la fecha y el asesor se escribían
    // mientras la página se reemplazaba y el formulario quedaba vacío, con lo
    // que el portal no aplicaba nada —y sin decir por qué— (Dora Oime,
    // 30-sep-2026; antes había salido bien once veces por pura suerte de
    // tiempos).
    await Promise.all([
      pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {}),
      marco.evaluate((v) => {
        const s = document.querySelector('[id$="DdlSettlementParam"]') || document.querySelector('[id$="DdlContributorType"]');
        if (s) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
      }, tipoCotizante).catch(() => {}),
    ]);
    await esperar(1500);

    // Tras la recarga el marco anterior ya no sirve: hay que volver a buscarlo.
    marco = (await marcoConCampo(pagina, 'TxtSalary')) ?? (await campoDe()) ?? marco;

    await escribir(marco, 'TxtSalary', String(Math.round(Number(ibc))), { conBlur: false });

    const [a, m, d] = String(fechaIngreso).split('-');
    await escribir(marco, 'TxtInitialdate', `${d}/${m}/${a}`, { conBlur: false });

    // El asesor es obligatorio aunque el procedimiento de Sura no lo diga: con 0
    // queda «SIN ASESOR- SIN DIRECCIÓN COMERCIAL».
    await escribir(marco, 'tbxIntermediaryCode', asesor);

    // El número de solicitud está en el formulario antes de aplicar y es el que
    // sale en el comprobante (8692154 → «6I_8692154» en Génesis, 30-sep-2026).
    // Sirve de respaldo cuando el visor del comprobante no se deja leer.
    const solicitudPrevia = (await marco.evaluate(() => document.querySelector('[id$="TxbApplicationNumber"]')?.value || '').catch(() => '')).trim();

    // Con el formulario a medio llenar el portal no aplica nada y tampoco dice
    // qué faltó: se mira antes y se corta aquí, que es donde se ve.
    const llenado = await marco.evaluate(() => {
      const v = (sufijo) => document.querySelector(`[id$="${sufijo}"]`)?.value?.trim() || '';

      return { salario: v('TxtSalary'), fecha: v('TxtInitialdate'), asesor: v('hddIntermediary') };
    }).catch(() => ({}));

    const falta = [
      !llenado.salario && 'el salario',
      !llenado.fecha && 'la fecha de ingreso',
      !llenado.asesor && 'el asesor',
    ].filter(Boolean);

    if (falta.length) {
      // Se devuelve, no se sale: en un lote cortar aquí dejaría sin atender a
      // las demás personas de la empresa.
      return {
        ok: false, modo, paso: 'datos de la novedad', nombre,
        formulario: llenado, solicitudPrevia,
        error: `El formulario no se llenó: falta ${falta.join(', ')}. No se aplicó nada.`,
      };
    }

    vaPor('aplicar novedad');
    // Las descargas se habilitan ANTES de guardar: el portal ofrece el soporte de
    // la novedad en cuanto la aplica, y si no hay dónde dejarlo se pierde.
    const descarga = await conDescargas();

    const antes = alertas.length;
    await marco.evaluate(() => document.querySelector('[id$="BtnSaveNovelty"]')?.click());

    // Se espera a lo que llegue primero: la alerta —que es como avisa el rechazo—
    // o la pantalla del comprobante, que es como termina cuando sale bien. Antes
    // solo miraba la alerta y, como un reingreso aplicado no muestra ninguna, se
    // comía los 32 s enteros en TODOS los que salían bien (medido el 1-oct-2026:
    // 32,0 s clavados en las cinco corridas).
    for (let i = 0; i < 40 && alertas.length === antes; i++) {
      await esperar(800);
      if (/AffiliationReadmissionsRepLoad/i.test(pagina.url())) break;
    }

    // El resultado sale en otra pantalla (AffiliationReadmissionsRepLoad.aspx) y
    // dentro de un iframe de Crystal Reports: leer solo el marco principal
    // devolvía «Informe principal L01» y nada más. Se espera al comprobante y se
    // lee de todos los marcos.
    vaPor('comprobante');
    let comprobante = '';
    let enComprobante = false;

    // Diez vueltas, no cuarenta: el resultado se saca del PDF que se exporta más
    // abajo, que no depende de que el visor llegue a dibujarse. Esperar a que
    // pintara costaba 40 s en cada trámite —otros 40 s clavados en las cinco
    // corridas— para acabar leyéndolo del PDF igualmente.
    for (let i = 0; i < 10 && !comprobante; i++) {
      await esperar(1000);

      // La pantalla del comprobante se reconoce por su propio texto, aunque el
      // reporte todavía no se pueda leer: que se haya llegado hasta aquí ya
      // significa que la novedad se envió.
      for (const f of pagina.frames()) {
        const t = await f.evaluate(() => document.body?.innerText || '').catch(() => '');
        if (/documento soporte|Informe principal/i.test(t)) enComprobante = true;
        if (/Resultado del|Novedad aplicada|Número de Solicitud/i.test(t)) { comprobante = t.replace(/\s+/g, ' ').trim(); break; }
      }
      if (comprobante) break;

      // El reporte va en un iframe que el visor escribe por dentro (sin src), y
      // ese marco no siempre sale en pagina.frames(): se lee su contentDocument
      // desde la página, que es del mismo dominio.
      for (const f of pagina.frames()) {
        const dentro = await f.evaluate(() => Array.from(document.querySelectorAll('iframe'))
          .map((x) => { try { return x.contentDocument?.body?.innerText || ''; } catch { return ''; } })
          .join(' \n ')).catch(() => '');
        if (/Resultado del|Novedad aplicada|Número de Solicitud/i.test(dentro)) {
          comprobante = dentro.replace(/\s+/g, ' ').trim();
          break;
        }
      }
    }

    const despues = (comprobante || (await texto(pagina))).replace(/\s+/g, ' ').trim();
    const alerta = alertas.slice(antes).join(' | ');
    const aplicada = /Novedad aplicada con [eé]xito/i.test(comprobante);
    const conError = !aplicada || /error|no se pudo|no fue posible|inconsist/i.test(alerta);

    // Lo que hay que guardar: el número de solicitud es el radicado del trámite y
    // el código de transacción es su respaldo; el portal muestra los dos.
    const dato = (re) => (comprobante.match(re) || [])[1]?.trim() || null;
    // El número lleva dígitos: sin eso, en la pantalla de rechazo se capturaba
    // «Autogenerar», que es la etiqueta de la casilla de al lado.
    const solicitud = dato(/N[uú]mero de Solicitud\s+([A-Z0-9]*\d[A-Z0-9_]*)/i);
    const transaccion = dato(/C[oó]digo de Transacci[oó]n\s+(\d+)/i);
    const periodo = dato(/per[ií]odo de inicio de pago es\s*([\d/]+)/i);

    // El soporte: el portal lo baja solo, y si no, deja el enlace «Informe
    // principal» para pedirlo a mano. Se intenta, pero su ausencia no invalida
    // la novedad: eso lo dice el portal, no el archivo.
    // El comprobante se baja con «Descargar Documento», que abre una ventana
    // aparte: la descarga nace en otro target del navegador y por eso el permiso
    // va a nivel de navegador (ver conDescargas). Si en vez de descargar abre el
    // PDF en una pestaña, se recoge de ahí.
    navegador.on('targetcreated', async (t) => {
      try {
        const u = t.url() || '';
        if (/\.pdf|informe|reporte|crystal/i.test(u)) nuevasVentanas.push(u);
      } catch {}
    });

    vaPor('soporte');

    // Primero la exportación del visor, que entrega el PDF de una. Lo de abajo
    // —el clic con el ratón, la ventana emergente, la impresión de la pantalla—
    // queda de respaldo por si el visor no estuviera.
    let soporte = await exportarComprobante(pagina, descarga.carpeta);

    if (!soporte) soporte = await descarga.esperar(4);
    if (!soporte) {
      // El botón se pulsa con el ratón, no con element.click() por JavaScript:
      // la ventana emergente donde ocurre la descarga solo se abre si Chrome ve
      // un gesto de verdad, y un clic sintético no cuenta ni con el bloqueo
      // desactivado. Por eso no bajaba nada (Paola Montoya, 30-sep-2026).
      for (const f of pagina.frames()) {
        let boton = null;
        for (const h of await f.$$('button, a, input[type=button], input[type=submit]').catch(() => [])) {
          const t = await h.evaluate((e) => (e.innerText || e.value || '').trim()).catch(() => '');
          if (/descargar documento|informe principal/i.test(t)) { boton = h; break; }
        }
        if (!boton) continue;

        const antesVentanas = navegador.targets().length;
        await boton.click({ delay: 60 }).catch(async () => {
          // Si no se deja pulsar (tapado, fuera de pantalla), al menos se intenta.
          await boton.evaluate((e) => e.click()).catch(() => {});
        });

        // La ventana emergente tarda en abrirse y en soltar el archivo.
        soporte = await descarga.esperar(8);

        // Si el botón abrió una ventana, se anota su dirección: sirve para saber
        // por dónde sale el documento sin alargar la corrida.
        if (!soporte && navegador.targets().length > antesVentanas) {
          const nueva = navegador.targets().slice(antesVentanas).map((t) => t.url()).find((u) => u && u !== 'about:blank');
          if (nueva) nuevasVentanas.push(nueva);
        }
        break;
      }
    }

    // Si el botón no suelta el archivo, se imprime la pantalla del comprobante.
    // Es el documento que hace falta: el certificado de afiliación lista TODOS
    // los empleadores de la persona —SURA lo emite así— y el comprobante del
    // reingreso muestra solo la empresa del trámite.
    //
    // La impresión es rápida y va con su propio límite: esperar de más tumbó la
    // corrida de Diana Ruiz por tiempo (30-sep-2026) y dejó la novedad aplicada
    // sin registrar, que es el peor final.
    if (!soporte) {
      try {
        const ruta = join(descarga.carpeta, `comprobante_${Date.now()}.pdf`);
        await Promise.race([
          pagina.pdf({ path: ruta, format: 'A4', printBackground: true }),
          new Promise((_, rechazar) => setTimeout(() => rechazar(new Error('pdf lento')), 25000)),
        ]);
        const { size } = await stat(ruta);
        // Una hoja en blanco pesa poco: si el visor no alcanzó a dibujarse, no sirve.
        if (size > 12000) soporte = { archivo: 'comprobante_reingreso.pdf', ruta, bytes: size, impreso: true };
      } catch {}
    }

    return {
        ok: !conError,
      modo, paso: 'aplicar novedad', nombre, alerta: alerta || null,
      radicado: solicitud || (enComprobante && !comprobante ? solicitudPrevia || null : null),
      // Dice de dónde salió el número: del comprobante o del formulario.
      numeroSinConfirmar: !solicitud && enComprobante && !comprobante && !!solicitudPrevia,
      transaccion, periodoPago: periodo,
      soporte, ventanas: nuevasVentanas.slice(0, 3),
      texto: despues.slice(0, 900),
      // Se distingue «no se aplicó» de «se aplicó pero no pude leerlo»: en el
      // segundo caso repetir el trámite lo duplicaría.
      enComprobante,
      error: conError
        ? (alerta || (comprobante
          ? 'El portal no confirmó la novedad: '.concat(despues.slice(0, 300))
          : (enComprobante
            ? 'La novedad se envió y el portal mostró el comprobante, pero no se pudo leer el resultado: revísalo en el portal ANTES de repetirlo.'
            : 'No apareció el comprobante del reingreso.')))
        : undefined,
    };
  };

  if (modo === 'lote') {
    // Todas las personas del lote son de la MISMA empresa: por eso basta con
    // haber entrado una vez. Cada una se anuncia en cuanto termina, para que
    // BryNex la guarde al vuelo y un corte a mitad no se lleve lo ya hecho.
    const personas = Array.isArray(entrada.personas) ? entrada.personas : [];
    const resultados = [];

    for (const [i, quien] of personas.entries()) {
      if (i > 0) {
        // La pantalla anterior quedó en el comprobante: se vuelve al formulario.
        vaPor('abrir reingresos');
        await pagina.goto(URL_MENU, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
        await esperar(2000);
      }

      let resultado;
      try {
        resultado = await tramitarPersona(quien);
      } catch (fallo) {
        resultado = { ok: false, error: String(fallo.message || fallo).slice(0, 200) };
      }
      resultado.contratoId = quien.contratoId ?? null;
      resultados.push(resultado);

      try { process.stderr.write('@resultado '.concat(JSON.stringify(resultado), '\n')); } catch { /* da igual */ }
    }

    salir({ ok: true, modo, total: resultados.length, resultados });
  }

  salir(await tramitarPersona({ persona, ibc, fechaIngreso, tipoCotizante, asesor }));

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
