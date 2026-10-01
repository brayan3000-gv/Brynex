/**
 * Baja el "Informe de Afiliados a EPS Sura" detallado de una empresa: todos sus
 * cotizantes, con tipo de cotizante, fecha de ingreso a la empresa y estado para
 * la prestación del servicio. Solo lee.
 *
 * Sirve para conciliar al revés que la consulta por cédula: en vez de preguntar
 * por los que BryNex espera, trae a todos los que Sura tiene, y así aparecen los
 * que no deberían estar —por ejemplo los de Gestión ARL de ELITES, a los que la
 * afiliación a la ARL les activó la EPS sin que nadie la pidiera—.
 *
 * El informe es JSF con un datascroller de RichFaces que pagina de a 10 por ajax.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa}
 * Salida por stdout: {ok, empresa, total, afiliados: [{tipo, numero, apellido1,
 *                     apellido2, nombres, tipo_afiliado, tipo_cotizante,
 *                     fecha_ingreso, parentesco, cobertura, estado}], error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, texto } from './eps-sura-sesion-comun.mjs';

const URL_INFORME = 'https://epsapps.suramericana.com/Semp/faces/pos/afiliadosporestado/parametros.jspx';

/** Tope de páginas: la empresa más grande de Brygar tiene 15; evita un bucle infinito. */
const MAX_PAGINAS = 300;

const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

let entrada;
try { entrada = JSON.parse(await leerStdin() || '{}'); }
catch { salir({ ok: false, error: 'Entrada JSON inválida.' }); }

if (!entrada.usuario || !entrada.contrasena || !entrada.nitEmpresa) {
  salir({ ok: false, error: 'Faltan credenciales o NIT de la empresa.' });
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
 * Las filas de la página visible, en orden. Las de un solo cell en mayúsculas
 * son el encabezado del grupo de estado ("TIENE DERECHO A COBERTURA INTEGRAL")
 * y se arrastran a las filas que siguen. El encabezado no se repite en cada
 * página: las primeras filas heredan el estado con que terminó la anterior
 * (`estadoInicial`); sin eso salían "sin estado" y no se confirmaban.
 */
const leerPagina = (pagina, estadoInicial = null) => pagina.evaluate((inicial) => {
  const filas = [];
  let estado = inicial;
  for (const tr of document.querySelectorAll('tr')) {
    const celdas = [...tr.children].map(td => td.innerText.trim());
    if (celdas.length === 1 && /^[A-ZÁÉÍÓÚÑ ()]{8,}$/.test(celdas[0])) {
      estado = celdas[0];
      continue;
    }
    if (celdas.length >= 9 && /^[A-Z]{2} \d+$/.test(celdas[0])) {
      filas.push({ celdas, estado });
    }
  }
  return filas;
}, estadoInicial).catch(() => []);

/**
 * La página visible cuando ya terminó de dibujarse: vale cuando dos lecturas
 * seguidas coinciden.
 */
const leerEstable = async (pagina, estadoInicial) => {
  let anterior = JSON.stringify(await leerPagina(pagina, estadoInicial));
  for (let i = 0; i < 12; i++) {
    await esperar(500);
    const actual = JSON.stringify(await leerPagina(pagina, estadoInicial));
    if (actual === anterior) break;
    anterior = actual;
  }
  return JSON.parse(anterior);
};

/** Pulsa un botón del datascroller y espera a que cambie la primera fila. */
const pulsarYEsperar = async (pagina, elegir, arg) => {
  const primera = (await leerPagina(pagina))[0]?.celdas[0];
  const pulsado = await pagina.evaluate(elegir, arg).catch(() => false);
  if (!pulsado) return 'sin boton';
  for (let i = 0; i < 25; i++) {
    await esperar(400);
    if ((await leerPagina(pagina))[0]?.celdas[0] !== primera) return 'ok';
  }
  return 'no cambio';
};

// El número de la página si está a la vista; si no, "siguiente".
const botonPagina = (num) => {
  const botones = [...document.querySelectorAll('[id*="_ds_"]')];
  const b = botones.find(e => e.id.endsWith('_ds_' + num))
    ?? botones.find(e => /_ds_next$/.test(e.id) && !/dsbl/.test(e.className));
  if (!b || /rf-ds-act/.test(b.className)) return false;
  b.click();
  return true;
};

const botonPrimera = () => {
  const b = [...document.querySelectorAll('[id*="_ds_"]')]
    .find(e => /_ds_(f|1)$/.test(e.id) && !/dsbl|rf-ds-act/.test(e.className));
  if (!b) return false;
  b.click();
  return true;
};

let pagina;
let paso = 'inicio';

try {
  pagina = await navegador.newPage();

  paso = 'login';
  await entrarEmpresaEps(pagina, entrada);

  paso = 'generar informe';
  await pagina.goto(URL_INFORME, { waitUntil: 'networkidle2', timeout: 60000 });
  await pagina.waitForSelector('input[type="radio"][value="det"]', { visible: true, timeout: 30000 });
  await pagina.click('input[type="radio"][value="det"]');
  await esperar(1500);

  const generar = await pagina.$('a[id$=":generar"]');
  if (!generar) throw new Error('No apareció el botón "Generar reporte".');
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {}),
    generar.click(),
  ]);

  let t = '';
  for (let i = 0; i < 40; i++) {
    await esperar(800);
    t = await texto(pagina);
    if (/Detalle de afiliados por estado|no (se encontraron|hay) (registros|afiliados)/i.test(t)) break;
  }
  if (!/Detalle de afiliados por estado/i.test(t)) {
    if (/no (se encontraron|hay) (registros|afiliados)/i.test(t)) {
      salir({ ok: true, empresa: null, total: 0, afiliados: [] });
    }
    throw new Error('El informe no cargó: ' + t.replace(/\s+/g, ' ').trim().slice(0, 200));
  }

  const empresa = (t.match(/Empresa\t([^\n\t]+)/) || [])[1]?.trim() || null;
  const totalSura = Number((t.match(/Total afiliados\t(\d+)/) || [])[1] || 0) || null;

  // El informe tiene que ser de la empresa pedida: cruzarlo con el de otra
  // confirmaría a quien trabaje en ambas. El encabezado trae
  // "Identificación NI <nit> Empresa <nombre>"; se compara con o sin dígito de verificación.
  paso = 'verificar empresa';
  const nitInforme = (t.match(/Identificaci[oó]n\s+NI\s+(\d+)/i) || [])[1] || null;
  const nitPedido = String(entrada.nitEmpresa).replace(/\D/g, '');
  if (!nitInforme || !(nitInforme.startsWith(nitPedido) || nitPedido.startsWith(nitInforme))) {
    throw new Error(`El informe salió de otra empresa (NIT ${nitInforme ?? 'sin identificar'}${empresa ? ', ' + empresa : ''}), no de ${nitPedido}.`);
  }

  // ── Paginar ──
  paso = 'paginar';
  // La llave es la fila sin el estado: releer una página no duplica, y si una
  // pasada la leyó sin estado y otra con estado, se queda la que lo tiene.
  const vistos = new Map();
  let ultimoEstado = null;
  const guardar = (filas) => {
    for (const f of filas) {
      const llave = f.celdas.join('|');
      if (!vistos.has(llave) || (!vistos.get(llave).estado && f.estado)) vistos.set(llave, f);
    }
    ultimoEstado = filas.at(-1)?.estado ?? ultimoEstado;
  };

  // En el servidor el informe a veces sale corto por 1 o 2 filas, sin repetidos
  // ni patrón (ELITES 139/141, Construtech 12/13, Work at Home 53/54 la noche del
  // 14-sep-2026; al depurar al día siguiente salió completo). En vez de adivinar
  // la causa, si no cuadra se vuelve a la página 1 y se recorre otra vez,
  // juntando lo leído. `bitacora` queda en la salida para el diagnóstico.
  const bitacora = [];
  for (let pasada = 1; pasada <= 3; pasada++) {
    if (pasada > 1) {
      const vuelta = await pulsarYEsperar(pagina, botonPrimera);
      bitacora.push({ pasada, volver: vuelta });
      if (vuelta !== 'ok') break;
      ultimoEstado = null;
    }

    const primeraPagina = await leerEstable(pagina, ultimoEstado);
    bitacora.push({ pasada, n: 1, filas: primeraPagina.length });
    guardar(primeraPagina);

    for (let n = 2; n <= MAX_PAGINAS; n++) {
      const r = await pulsarYEsperar(pagina, botonPagina, n);
      if (r !== 'ok') break;
      const filas = await leerEstable(pagina, ultimoEstado);
      bitacora.push({ pasada, n, filas: filas.length });
      guardar(filas);
    }

    if (!totalSura || vistos.size >= totalSura) break;
  }

  const afiliados = [...vistos.values()].map(({ celdas: c, estado }) => {
    const [tipo, numero] = c[0].split(' ');
    return {
      tipo, numero,
      apellido1: c[1] || null, apellido2: c[2] || null, nombres: c[3] || null,
      tipo_afiliado: c[4] || null, tipo_cotizante: c[5] || null,
      fecha_ingreso: c[6] || null, parentesco: c[7] || null, cobertura: c[8] || null,
      estado,
    };
  });

  // Si tras las pasadas sigue corto, se entrega igual marcado como incompleto:
  // quien llama decide. El informe de sobran/faltan lo rechaza (los que falten
  // saldrían como "no están en EPS" sin ser cierto); el cruce de confirmación lo
  // acepta, porque solo confirma a quien sí aparece.
  const incompleto = !!totalSura && afiliados.length < totalSura;

  salir({
    ok: true, empresa, total: afiliados.length, total_sura: totalSura, incompleto, afiliados,
    ...(incompleto ? { bitacora } : {}),
  });
} catch (e) {
  let captura = null;
  try {
    if (pagina && !pagina.isClosed() && process.env.ARL_DEBUG_DIR) {
      captura = `${process.env.ARL_DEBUG_DIR}/eps-sura-afiliados-fallo.png`;
      await pagina.screenshot({ path: captura, fullPage: true });
    }
  } catch {}
  salir({ ok: false, paso, error: String(e.message || e).slice(0, 300), captura });
} finally {
  await navegador.close().catch(() => {});
}
