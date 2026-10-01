/**
 * Anula la novedad de ingreso de un trabajador en ARL Sura.
 *
 * A diferencia de afiliar, retirar o descargar documentos, **la anulación no
 * tiene API**: vive en el Struts legacy de Servicios en Línea, en tres pantallas
 * encadenadas (`borradoCobertura.seleccion` → `.verificacion` → `.procesar`).
 *
 * Abre su propia sesión en vez de recibir cookies: Incapsula ata la sesión al
 * navegador que la creó, así que inyectarlas en otro Chrome devuelve el login.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa, tipoId, numDoc}
 * Salida por stdout: {ok, mensaje, error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { iniciarSesion, rutaChrome } from './arl-sura-sesion-comun.mjs';

// Sura tiene una pantalla por tipo de afiliado, y la de dependientes no
// encuentra a un independiente.
const URL_DEPENDIENTE   = 'https://arpsura.suramericana.com/servicios-linea/borradoCobertura.seleccion.sl';
const URL_INDEPENDIENTE = 'https://arpsura.suramericana.com/servicios-linea/borradoCoberturaIndependiente.seleccion.sl';
const esperar = (ms) => new Promise(r => setTimeout(r, ms));
const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

let entrada;
try { entrada = JSON.parse(await leerStdin() || '{}'); }
catch { salir({ ok: false, error: 'Entrada JSON inválida.' }); }

const { usuario, contrasena, tipoId = 'C', numDoc, tipoAfiliado = 'D' } = entrada;
const URL_SELECCION = tipoAfiliado === 'I' ? URL_INDEPENDIENTE : URL_DEPENDIENTE;
if (!usuario || !contrasena || !numDoc) salir({ ok: false, error: 'Faltan credenciales o documento.' });

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

let pagina;
let paso = 'inicio';
try {
  pagina = await navegador.newPage();
  paso = 'login';
  await iniciarSesion(pagina, entrada);
  paso = 'abrir pantalla de anulación';

  // ── Paso 1: cuántos trabajadores ──
  await pagina.goto(URL_SELECCION, { waitUntil: 'networkidle2', timeout: 60000 });

  if (/servicelogin/i.test(pagina.url())) {
    throw new Error('El portal pidió login de nuevo al abrir la anulación.');
  }

  /**
   * Pulsa el control cuyo texto coincide y espera la navegación.
   *
   * El `evaluate` revienta con "Execution context was destroyed" cuando el clic
   * navega de inmediato —que es justo lo que hace este Struts—, así que ese
   * error concreto se ignora: la navegación es la señal de que el clic sirvió.
   */
  const pulsar = async (patron) => {
    const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
    try {
      await pagina.evaluate((p) => {
        const re = new RegExp(p, 'i');
        const b = [...document.querySelectorAll('input,button,a')]
          .find(e => re.test(e.value || e.innerText || ''));
        if (b) b.click();
      }, patron);
    } catch (e) {
      if (!/Execution context was destroyed|Target closed/i.test(String(e.message || e))) throw e;
    }
    await navegacion;
  };

  const visibles = async (sel) => {
    const out = [];
    for (const el of await pagina.$$(sel)) {
      const c = await el.boundingBox();
      if (c && c.width > 15 && c.height > 8) out.push(el);
    }
    return out;
  };

  paso = 'paso 1: cantidad de trabajadores';

  // El Struts sigue redirigiendo un rato después del goto; medir elementos en
  // ese momento revienta el contexto. Se espera al campo y se deja asentar.
  await pagina.waitForSelector('input[type="text"]', { visible: true, timeout: 40000 });
  await esperar(1500);

  const puso = await pagina.evaluate(() => {
    const i = [...document.querySelectorAll('input[type="text"]')].find(x => x.offsetParent !== null);
    if (!i) return false;
    i.focus(); i.value = '1';
    i.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  });
  if (!puso) throw new Error('No apareció el campo "¿a cuántos trabajadores?".');

  await pulsar('continuar');
  await esperar(1800);

  // ── Paso 2: documento → Verificar ──
  paso = 'paso 2: documento del trabajador';
  await esperar(800);
  await pagina.evaluate((tipo, doc) => {
    // Por nombre y no por posición: la pantalla de independientes trae un campo
    // extra para el nombre del trabajador, y el último input visible es ese.
    const sel = document.getElementsByName('tipoDocumento_1')[0]
      ?? [...document.querySelectorAll('select')].find(s => s.offsetParent !== null);
    if (sel) sel.value = tipo;

    const campo = document.getElementsByName('documento_1')[0]
      ?? [...document.querySelectorAll('input[type="text"]')].filter(i => i.offsetParent !== null).pop();

    if (campo) {
      campo.value = doc;
      // El portal busca el nombre al salir del campo, y sin ese paso la
      // verificación no encuentra al trabajador.
      campo.dispatchEvent(new Event('change', { bubbles: true }));
      campo.dispatchEvent(new Event('blur', { bubbles: true }));
    }
  }, tipoId, String(numDoc));

  await esperar(2500); // deja que el portal traiga el nombre
  await pulsar('verificar');
  await esperar(2500);

  if (!await pagina.evaluate(() => /borrar registros/i.test(document.body.innerText))) {
    const texto = await pagina.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 200));
    throw new Error('No hay cobertura anulable para ese documento. El portal dice: ' + texto);
  }

  // ── Paso 3: borrar ──
  paso = 'paso 3: borrar registros';

  // Cuando el trabajador tiene varias coberturas hay que marcarlas: la pantalla
  // ofrece «Seleccionar todos» y sin marcar nada el borrado no hace nada.
  await pagina.evaluate(() => {
    document.querySelectorAll('input[type="checkbox"]').forEach((c) => {
      if (! c.checked && c.offsetParent !== null) c.click();
    });
  });

  await pulsar('borrar registros');
  await esperar(2500);

  const resultado = await pagina.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim());
  if (!/borradas exitosamente/i.test(resultado)) {
    throw new Error('El portal no confirmó la anulación: ' + resultado.slice(0, 220));
  }

  salir({ ok: true, mensaje: 'Coberturas borradas exitosamente.' });
} catch (e) {
  let captura = null;
  try {
    if (pagina && !pagina.isClosed() && process.env.ARL_DEBUG_DIR) {
      captura = `${process.env.ARL_DEBUG_DIR}/arl-anular-fallo.png`;
      await pagina.screenshot({ path: captura, fullPage: true });
    }
  } catch {}
  salir({ ok: false, paso, error: String(e.message || e).slice(0, 300), captura });
} finally {
  await navegador.close().catch(() => {});
}
