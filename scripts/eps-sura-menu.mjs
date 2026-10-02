/**
 * Mapa del portal de empleadores de EPS SURA: qué opciones ofrece a una empresa.
 *
 * Exploración pura —entra y lista los enlaces con su destino, no toca nada—,
 * para encontrar dónde publica la cartera y la mora por trabajador.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa}
 * Salida por stdout: {ok, url, enlaces: [{texto, destino}], pantalla, error}
 */
import { rmSync } from 'node:fs';
import { mkdtemp, readdir, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, texto, salirDelPortal } from './eps-sura-sesion-comun.mjs';

// `process.exit()` corta el proceso sin pasar por el `finally`, así que el
// cierre de sesión del portal tiene que ocurrir ANTES: durante todo el 2-oct-2026
// el logout estaba puesto en el `finally` y no se ejecutó nunca, y cada corrida
// dejaba la sesión viva para estorbar a la siguiente.
let paginaAbierta = null;
const salir = async (d) => {
  try { if (paginaAbierta) await salirDelPortal(paginaAbierta); } catch { /* la sesión caduca sola */ }
  console.log(JSON.stringify(d));
  process.exit(d.ok ? 0 : 1);
};

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

const entrada = JSON.parse((await leerStdin()) || '{}');

/** Abre la opción del menú cuyo texto coincide. */
const irA = async (pagina, patron) => {
  const destino = await pagina.evaluate((p) => {
    const re = new RegExp(p, 'i');
    const a = [...document.querySelectorAll('a')].find((x) => re.test((x.innerText || '').trim()));
    return a ? a.getAttribute('href') : null;
  }, patron);

  if (!destino) throw new Error(`El menú no tiene la opción ${patron}.`);

  await pagina.goto(new URL(destino, pagina.url()).href, { waitUntil: 'networkidle2', timeout: 60000 });
  await esperar(3000);
};

// rutaChrome() da candidatos, no una ruta: el del Mac y los del servidor.
const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const r of rutaChrome()) { try { await access(r); return r; } catch {} }
  return null;
})();
if (!ejecutable) await salir({ ok: false, error: 'No se encontró Chrome. Define CHROME_PATH.' });

const navegador = await puppeteer.launch({
  executablePath: ejecutable,
  headless: 'new',
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--window-size=1400,900'],
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

let paso = 'inicio';

try {
  const pagina = await navegador.newPage();
  paginaAbierta = pagina;
  await pagina.setViewport({ width: 1400, height: 900 });

  paso = 'entrar';
  await entrarEmpresaEps(pagina, entrada);
  await esperar(2500);

  // Estado de cuenta: el informe de aportes del rango de meses, en XLS.
  // El portal lo descarga como archivo, así que hay que decirle a Chrome dónde
  // dejarlo y esperar a que aparezca.
  if (entrada.modo === 'estadoCuenta') {
    paso = 'abrir estados de cuenta';
    await irA(pagina, 'Estados de cuenta');

    paso = 'pedir el informe';
    const [desdeAnio, desdeMes] = String(entrada.desde || '').split('-');
    const [hastaAnio, hastaMes] = String(entrada.hasta || '').split('-');

    // El formato va PRIMERO: marcarlo dispara un postback de JSF que deja los
    // meses como estaban al cargar. Al revés, el informe sale en PDF y del PDF
    // no se saca la mora.
    const marcarXls = () => pagina.evaluate(() => {
      const xls = [...document.querySelectorAll('input[type=radio]')].find((r) => r.value === 'xls');
      if (!xls) return false;
      if (!xls.checked) xls.click();

      return true;
    });

    if (!await marcarXls()) throw new Error('La pantalla no ofrece el formato XLS.');
    await esperar(2500);

    await pagina.select('[id="estadosCuenta:idAnio"]', desdeAnio);
    await pagina.select('[id="estadosCuenta:idMes"]', desdeMes);
    await pagina.select('[id="estadosCuenta:idAnioFinal"]', hastaAnio);
    await pagina.select('[id="estadosCuenta:idMesFinal"]', hastaMes);
    await esperar(800);

    // Y se comprueba, porque cualquiera de esos postbacks pudo desmarcarlo.
    const enXls = await pagina.evaluate(() => {
      const xls = [...document.querySelectorAll('input[type=radio]')].find((r) => r.value === 'xls');
      return !!xls?.checked;
    });
    if (!enXls) { await marcarXls(); await esperar(2000); }

    const carpeta = await mkdtemp(join(tmpdir(), 'sura-'));
    const cdp = await pagina.target().createCDPSession();
    await cdp.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: carpeta });

    const pulsado = await pagina.evaluate(() => {
      const b = [...document.querySelectorAll('a, button, input[type=submit], input[type=button]')]
        .find((e) => /generar/i.test(e.innerText || e.value || ''));
      if (!b) return false;
      b.click();

      return true;
    });
    if (!pulsado) throw new Error('No apareció el botón de generar el informe.');

    // El informe tarda: se espera al archivo, no a la pantalla.
    paso = 'esperar el archivo';
    let archivo = null;

    for (let i = 0; i < 60 && !archivo; i++) {
      await esperar(2000);
      const nombres = await readdir(carpeta).catch(() => []);
      archivo = nombres.find((n) => !n.endsWith('.crdownload')) || null;
    }

    if (!archivo) {
      await salir({ ok: false, paso, error: 'El portal no entregó el archivo del estado de cuenta.', pantalla: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 600) });
    }

    // El archivo se queda en disco y solo se devuelve su ruta: por stdout se
    // perdía, porque la salida del proceso se corta a 64 KB y un certificado en
    // PDF pesa más que eso.
    const ruta = join(carpeta, archivo);
    const { size } = await stat(ruta);

    // El PDF no es un fallo: cuando la empresa no debe nada, el portal emite
    // un certificado de no deuda y eso siempre sale en PDF, se pida lo que se
    // pida. Quien llama lo comprueba leyéndolo, y borra el archivo.
    await salir({
      ok: true, modo: 'estadoCuenta', archivo, ruta,
      formato: /\.pdf$/i.test(archivo) ? 'pdf' : 'csv',
      bytes: size,
    });
  }

  // Con `opcion`, en vez del menú se abre esa pantalla y se describe: qué
  // filtros pide y qué columnas trae. Sigue sin tocar nada.
  if (entrada.opcion) {
    paso = `abrir ${entrada.opcion}`;

    await irA(pagina, entrada.opcion);

    const campos = await pagina.evaluate(() => [...document.querySelectorAll('input, select, textarea')]
      .filter((e) => e.type !== 'hidden')
      .map((e) => ({
        etiqueta: e.tagName.toLowerCase(),
        id: e.id || null,
        tipo: e.getAttribute('type') || null,
        valor: (e.value || '').slice(0, 30) || null,
        opciones: e.tagName === 'SELECT' ? [...e.options].slice(0, 16).map((o) => `${o.value}=${o.text}`.slice(0, 44)) : undefined,
      })));

    const tablas = await pagina.evaluate(() => [...document.querySelectorAll('table')]
      .map((t) => ({
        columnas: [...t.querySelectorAll('th')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean),
        filas: t.querySelectorAll('tbody tr').length,
      }))
      .filter((t) => t.columnas.length));

    await salir({
      ok: true, opcion: entrada.opcion, url: pagina.url(),
      campos, tablas, pantalla: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 1800),
    });
  }

  paso = 'leer menú';
  // El menú de JSF cuelga de enlaces y de nodos con onclick; se listan los dos.
  const enlaces = await pagina.evaluate(() => [...document.querySelectorAll('a, [onclick]')]
    .map((e) => ({
      texto: (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim(),
      destino: (e.getAttribute('href') || e.getAttribute('onclick') || '').replace(/\s+/g, ' ').slice(0, 100),
    }))
    .filter((e) => e.texto && e.texto.length < 70));

  await salir({
    ok: true,
    url: pagina.url(),
    titulo: await pagina.title().catch(() => null),
    enlaces,
    pantalla: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 1500),
  });
} catch (e) {
  await salir({ ok: false, paso, error: String(e?.message || e).slice(0, 300) });
} finally {
  await navegador.close().catch(() => null);
}
