/**
 * Averigua la póliza (el "contrato") de una empresa en ARL Sura.
 *
 * No hay endpoint que la devuelva: todo el API la exige como header, así que
 * preguntarla por ahí es circular. La única fuente es el legacy, en
 * `datosBasicosEmpresa.busqueda.sl`, que muestra "Contrato NNNNNNNNN" junto al
 * documento y el nombre de la empresa.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa}
 * Salida: {ok, poliza, nit, empresa, error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { iniciarSesion, rutaChrome } from './arl-sura-sesion-comun.mjs';

const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };
const leer = async () => { let x = ''; for await (const t of process.stdin) x += t; return x.trim(); };

let entrada;
try { entrada = JSON.parse(await leer() || '{}'); }
catch { salir({ ok: false, error: 'Entrada JSON inválida.' }); }

if (!entrada.usuario || !entrada.contrasena) salir({ ok: false, error: 'Faltan credenciales.' });

const exe = await (async () => {
  const { access } = await import('node:fs/promises');
  for (const r of rutaChrome()) { try { await access(r); return r; } catch {} }
})();
if (!exe) salir({ ok: false, error: 'No se encontró Chrome. Define CHROME_PATH.' });

const nav = await puppeteer.launch({
  executablePath: exe, headless: 'new',
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-blink-features=AutomationControlled'],
});


// Al salir se borra el perfil temporal del navegador. Hace falta a mano porque
// el script termina con process.exit(), que se salta el cierre de puppeteer —y
// es ese cierre el que normalmente lo borra—: cada corrida dejaba su carpeta en
// /tmp, y entre todos los robots se juntaron 11 GB (1-oct-2026).
process.on('exit', () => {
  try {
    const perfil = (nav.process()?.spawnargs || [])
      .find((a) => a.startsWith('--user-data-dir='))
      ?.slice('--user-data-dir='.length);

    if (perfil && /puppeteer_dev/.test(perfil)) rmSync(perfil, { recursive: true, force: true });
  } catch { /* si no se deja borrar, no es motivo para tumbar nada */ }
});

let pagina;
try {
  pagina = await nav.newPage();
  await iniciarSesion(pagina, entrada);

  await pagina.goto('https://arpsura.suramericana.com/servicios-linea/datosBasicosEmpresa.busqueda.sl',
    { waitUntil: 'networkidle2', timeout: 60000 });
  await new Promise(r => setTimeout(r, 1500));

  const datos = await pagina.evaluate(() => {
    const t = (document.body.innerText || '').replace(/\s+/g, ' ').trim();
    return {
      poliza:  (t.match(/Contrato\s+(\d{6,12})/i) || [])[1] || null,
      nit:     (t.match(/Documento\s+[A-Z]?(\d{6,12})/i) || [])[1] || null,
      empresa: (t.match(/Empresa\s+([^0-9]{4,60}?)\s+\d\./i) || [])[1]?.trim() || null,
      texto:   t.slice(0, 160),
    };
  });

  if (!datos.poliza) {
    throw new Error('No se pudo leer la póliza. El portal muestra: ' + datos.texto);
  }

  salir({ ok: true, poliza: datos.poliza, nit: datos.nit, empresa: datos.empresa });
} catch (e) {
  // Sin ver la pantalla, un "no pasó el login" no distingue entre clave mala,
  // usuario bloqueado o un aviso del portal.
  let captura = null, texto = null;
  try {
    if (pagina && !pagina.isClosed()) {
      texto = (await pagina.evaluate(() => document.body?.innerText || '')).replace(/\s+/g, ' ').trim().slice(0, 300);
      if (process.env.ARL_DEBUG_DIR) {
        captura = `${process.env.ARL_DEBUG_DIR}/arl-poliza-fallo.png`;
        await pagina.screenshot({ path: captura, fullPage: true });
      }
    }
  } catch {}
  salir({ ok: false, error: String(e.message || e).slice(0, 300), texto, captura });
} finally {
  await nav.close().catch(() => {});
}
