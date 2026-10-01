/**
 * Mueve la fecha de inicio de la cobertura de un trabajador en ARL Sura.
 *
 * Es la forma barata de renovar: un solo trámite en vez de anular y volver a
 * afiliar, y sin el momento intermedio en que el trabajador queda sin ARL.
 * Tampoco gasta la ventana de 30 días que sí exige la anulación.
 *
 * Como la anulación, **no tiene API**: vive en el Struts legacy
 * (`movimientoCobertura.consultar` → `.procesar`). La pantalla se llama
 * "coberturas futuras" por la fecha de destino, que Sura exige que sea
 * posterior a hoy; la cobertura de origen sí puede llevar días corriendo.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa, tipoId,
 *                     numDoc, fechaNueva: 'dd/mm/aaaa', tipoAfiliado: '01'|'02'}
 * Salida por stdout: {ok, mensaje, fechaAnterior, error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { iniciarSesion, rutaChrome } from './arl-sura-sesion-comun.mjs';

const URL_CONSULTA = 'https://arpsura.suramericana.com/servicios-linea/movimientoCobertura.consultar.sl';
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

const { usuario, contrasena, tipoId = 'C', numDoc, fechaNueva, tipoAfiliado = '01' } = entrada;
if (!usuario || !contrasena || !numDoc) salir({ ok: false, error: 'Faltan credenciales o documento.' });
if (!/^\d{2}\/\d{2}\/\d{4}$/.test(String(fechaNueva || ''))) {
  salir({ ok: false, error: 'La fecha nueva debe venir como dd/mm/aaaa.' });
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

let pagina;
let paso = 'inicio';
try {
  pagina = await navegador.newPage();
  paso = 'login';
  await iniciarSesion(pagina, entrada);

  paso = 'abrir modificación de coberturas';
  await pagina.goto(`${URL_CONSULTA}?tipoAfiliado=${tipoAfiliado}`, { waitUntil: 'networkidle2', timeout: 60000 });

  if (/servicelogin/i.test(pagina.url())) {
    throw new Error('El portal pidió login de nuevo al abrir la modificación.');
  }

  // El clic navega de inmediato y revienta el contexto del evaluate: esa
  // navegación es justamente la señal de que el clic sirvió.
  const pulsar = async (accion) => {
    const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
    try { await pagina.evaluate(accion); }
    catch (e) {
      if (!/Execution context was destroyed|Target closed/i.test(String(e.message || e))) throw e;
    }
    await navegacion;
  };

  paso = 'consultar el trabajador';
  await pagina.waitForSelector('#dni', { visible: true, timeout: 40000 });
  await esperar(1200);

  await pagina.evaluate((tipo, doc) => {
    const sel = document.getElementById('tipodocumento');
    if (sel) sel.value = tipo;
    const dni = document.getElementById('dni');
    if (dni) dni.value = doc;
  }, tipoId, String(numDoc));

  // `consultar()` es la función del propio Struts: el botón es un enlace
  // javascript, así que llamarla directo evita depender de su texto.
  await pulsar(() => window.consultar && window.consultar());
  await esperar(2000);

  const textoConsulta = await pagina.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim());

  if (/no existen coberturas que puedan ser movidas/i.test(textoConsulta)) {
    throw new Error('Sura no deja mover la cobertura de este trabajador: ' +
      textoConsulta.replace(/.*?(No existen coberturas[^.]*\.).*/i, '$1').slice(0, 200));
  }

  paso = 'escribir la fecha nueva';
  const anterior = await pagina.evaluate((fecha) => {
    // Puede haber varias coberturas; se mueve la que esté abierta, que es la
    // que no tiene fecha de fin.
    const campos = [...document.querySelectorAll('input[id^="fechaAlta"]')]
      .filter(i => !i.readOnly && !i.disabled && i.offsetParent !== null);
    if (!campos.length) return null;

    const campo = campos[0];
    const previa = campo.value;
    campo.focus();
    campo.value = fecha;
    campo.dispatchEvent(new Event('change', { bubbles: true }));
    return previa;
  }, fechaNueva);

  if (anterior === null) throw new Error('El portal no mostró ninguna cobertura editable para ese documento.');

  paso = 'confirmar la modificación';
  await pulsar(() => {
    const re = /modificar cobertura/i;
    const b = [...document.querySelectorAll('input,button,a')]
      .find(e => re.test(e.value || e.innerText || ''));
    if (b) b.click();
  });
  await esperar(2500);

  const resultado = await pagina.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim());

  if (!/se modificaron correctamente/i.test(resultado)) {
    // Los rechazos de Sura vienen en una ventana con el motivo; se devuelve tal
    // cual para que quien lea el error sepa qué corregir.
    throw new Error('El portal no confirmó la modificación: ' + resultado.slice(0, 250));
  }

  salir({
    ok: true,
    mensaje: `Cobertura movida a ${fechaNueva}.`,
    fechaAnterior: anterior || null,
  });
} catch (e) {
  let captura = null;
  try {
    if (pagina && !pagina.isClosed() && process.env.ARL_DEBUG_DIR) {
      captura = `${process.env.ARL_DEBUG_DIR}/arl-modificar-fallo.png`;
      await pagina.screenshot({ path: captura, fullPage: true });
    }
  } catch {}
  salir({ ok: false, paso, error: String(e.message || e).slice(0, 300), captura });
} finally {
  await navegador.close().catch(() => {});
}
