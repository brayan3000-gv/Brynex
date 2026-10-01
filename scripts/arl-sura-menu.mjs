/**
 * Mapa del portal de ARL SURA: qué opciones ofrece a una empresa.
 *
 * Exploración pura —entra, lista los enlaces y sale—, para encontrar dónde
 * publica la cartera y la mora. Mira los dos mundos del portal: la Sucursal
 * Virtual (Angular, con los controles en shadow DOM) y el legacy de arpsura,
 * que es donde viven los trámites.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa}
 * Salida por stdout: {ok, sve: [...], legacy: [...], pantalla, error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { iniciarSesion, rutaChrome } from './arl-sura-sesion-comun.mjs';

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

const entrada = JSON.parse((await leerStdin()) || '{}');

const ejecutable = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const r of rutaChrome()) { try { await access(r); return r; } catch {} }
  return null;
})();
if (!ejecutable) salir({ ok: false, error: 'No se encontró Chrome. Define CHROME_PATH.' });

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
  await pagina.setViewport({ width: 1400, height: 900 });

  paso = 'entrar';
  await iniciarSesion(pagina, entrada);
  await esperar(2500);

  // Las pantallas de cartera del legacy. La SVE las abre en pestaña nueva, pero
  // sus URLs están en su menú (sessionStorage 'menu-dynamic-persistent'):
  //   estadoCuentaIntegral.sl          Estado de Cuenta Integral
  //   gestionNotas.cargarInconsistencias.sl   Carga de consolidado
  //   gestionNotas.cargarVistaValidarTrab.sl  Validar trabajadores vs. ARL
  //   renes.consulta.sl                Trabajadores con pago y sin afiliación
  //   enriques.consulta.sl             Trabajadores afiliados y sin pago (mora)
  //   ctSinAfiliados.busqueda.sl       Centros de trabajo sin afiliados
  if (entrada.pantalla) {
    paso = `abrir ${entrada.pantalla}`;
    const base = 'https://arpsura.suramericana.com/servicios-linea/';

    await pagina.goto(base + entrada.pantalla, { waitUntil: 'networkidle2', timeout: 60000 });
    await esperar(3000);

    const campos = await pagina.evaluate(() => [...document.querySelectorAll('input, select, textarea')]
      .filter((e) => e.type !== 'hidden')
      .map((e) => ({
        etiqueta: e.tagName.toLowerCase(),
        id: e.id || null,
        nombre: e.getAttribute('name') || null,
        tipo: e.getAttribute('type') || null,
        valor: (e.value || '').slice(0, 30) || null,
        opciones: e.tagName === 'SELECT' ? [...e.options].slice(0, 14).map((o) => `${o.value}=${o.text}`.slice(0, 40)) : undefined,
      })));

    const tablas = await pagina.evaluate(() => [...document.querySelectorAll('table')]
      .map((t) => ({
        columnas: [...t.querySelectorAll('th')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean),
        filas: t.querySelectorAll('tbody tr').length,
      }))
      .filter((t) => t.columnas.length));

    salir({
      ok: true, pantalla: entrada.pantalla, url: pagina.url(), titulo: await pagina.title().catch(() => null),
      campos, tablas,
      texto: await pagina.evaluate(() => (document.body?.innerText || '').replace(/\s+/g, ' ').slice(0, 1500)).catch(() => ''),
    });
  }

  // Generar una de esas consultas y leer su resultado.
  // `enriques.consulta.sl` (afiliados sin pago) y `renes.consulta.sl` (con pago
  // y sin afiliación) comparten pantalla: mes, año, detallado/total y formato.
  // Se pide HTML: sale en la misma página y no hay archivo que descargar.
  if (entrada.consulta) {
    // Varios meses en la misma sesión: el login cuesta cerca de un minuto y
    // repetirlo por cada mes multiplicaría la corrida sin motivo.
    const periodos = Array.isArray(entrada.periodos) && entrada.periodos.length
      ? entrada.periodos
      : [entrada.periodo].filter(Boolean);

    const resultados = {};

    for (const periodo of periodos) {
      paso = `abrir ${entrada.consulta} de ${periodo}`;
      await pagina.goto('https://arpsura.suramericana.com/servicios-linea/' + entrada.consulta, { waitUntil: 'networkidle2', timeout: 60000 });
      await esperar(2000);

      paso = `generar ${periodo}`;
      const [anio, mes] = String(periodo).split('-');
      await pagina.select('[name="pop_mes_inicial"]', mes).catch(() => null);
      await pagina.select('[name="pop_ano_inicial"]', anio).catch(() => null);

      await pagina.evaluate(() => {
        const marcar = (valor) => {
          const r = [...document.querySelectorAll('input[type=radio]')].find((x) => x.value === valor);
          if (r) r.click();
        };
        marcar('d');    // detallado: una fila por trabajador
        marcar('HTML'); // en pantalla, sin archivo que descargar
      });
      await esperar(800);

      const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }).catch(() => null);
      await pagina.evaluate(() => {
        const b = [...document.querySelectorAll('input[type=submit], input[type=button], button, a')]
          .find((e) => /generar/i.test(e.value || e.innerText || ''));
        if (b) b.click();
      });
      await navegacion;
      await esperar(2500);

      resultados[periodo] = await pagina.evaluate(() => [...document.querySelectorAll('table')]
        .map((t) => [...t.querySelectorAll('tr')]
          .map((f) => [...f.querySelectorAll('th, td')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()))
          .filter((f) => f.some((c) => c !== '')))
        .filter((t) => t.length > 1));
    }

    salir({ ok: true, consulta: entrada.consulta, periodos, resultados });
  }

  // Los enlaces del legacy, que es donde el portal tiene los trámites.
  paso = 'leer el legacy';
  const legacy = await pagina.evaluate(() => [...document.querySelectorAll('a, [onclick]')]
    .map((e) => ({
      texto: (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim(),
      destino: (e.getAttribute('href') || e.getAttribute('onclick') || '').replace(/\s+/g, ' ').slice(0, 90),
    }))
    .filter((e) => e.texto && e.texto.length < 70));

  const pantallaLegacy = await pagina.evaluate(() => (document.body?.innerText || '').replace(/\s+/g, ' ').slice(0, 1200)).catch(() => '');

  // El menú del legacy: la home de servicios en línea, que es la que lista los
  // trámites de la empresa (afiliación, novedades, pagos…).
  paso = 'leer la home del legacy';
  const menuLegacy = [];

  for (const url of [
    'https://arpsura.suramericana.com/servicios-linea/',
    'https://arpsura.suramericana.com/servicios-linea/gestorURLWeb3.redireccionar.sl?opcion=001',
  ]) {
    await pagina.goto(url, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
    await esperar(2500);

    const enlaces = await pagina.evaluate(() => [...document.querySelectorAll('a, [onclick]')]
      .map((e) => ({
        texto: (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim(),
        destino: (e.getAttribute('href') || e.getAttribute('onclick') || '').replace(/\s+/g, ' ').slice(0, 90),
      }))
      .filter((e) => e.texto && e.texto.length < 70)).catch(() => []);

    menuLegacy.push({ url, titulo: await pagina.title().catch(() => null), enlaces });
  }

  // Y los de la Sucursal Virtual, que están dentro de shadow roots.
  paso = 'leer la sucursal virtual';
  await pagina.goto('https://sucursalempresas.suramericana.com/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
  await esperar(4000);

  // Los menús de la SVE vienen colapsados: hay que abrirlos para ver qué hay
  // debajo, y sus controles están dentro de shadow roots.
  for (let vuelta = 1; vuelta <= 3; vuelta++) {
    const abiertos = await pagina.evaluate(() => {
      let n = 0;
      const recorrer = (raiz, hondo = 0) => {
        if (hondo > 6) return;
        for (const e of raiz.querySelectorAll('a, button, [role=menuitem], [role=button], li')) {
          const texto = (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim();
          if (/^gesti[oó]n\s/i.test(texto) && texto.length < 60 && e.offsetParent !== null) { e.click(); n++; }
        }
        for (const e of raiz.querySelectorAll('*')) {
          if (e.shadowRoot) recorrer(e.shadowRoot, hondo + 1);
        }
      };
      recorrer(document);

      return n;
    }).catch(() => 0);

    if (!abiertos) break;
    await esperar(2500);
  }

  const sve = await pagina.evaluate(() => {
    const salida = [];
    const recorrer = (raiz, hondo = 0) => {
      if (hondo > 6) return;
      for (const e of raiz.querySelectorAll('a, button, [role=menuitem], [role=link]')) {
        const texto = (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim();
        if (texto && texto.length < 70) salida.push({ texto, destino: (e.getAttribute('href') || '').slice(0, 90) });
      }
      for (const e of raiz.querySelectorAll('*')) {
        if (e.shadowRoot) recorrer(e.shadowRoot, hondo + 1);
      }
    };
    recorrer(document);

    return salida;
  }).catch(() => []);

  salir({
    ok: true,
    url: pagina.url(),
    legacy,
    menu_legacy: menuLegacy,
    sve,
    pantalla: pantallaLegacy,
    sve_pantalla: await pagina.evaluate(() => (document.body?.innerText || '').replace(/\s+/g, ' ').slice(0, 800)).catch(() => ''),
  });
} catch (e) {
  salir({ ok: false, paso, error: String(e?.message || e).slice(0, 300) });
} finally {
  await navegador.close().catch(() => null);
}
