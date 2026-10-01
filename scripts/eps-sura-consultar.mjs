/**
 * Consulta en el portal de empleadores de EPS SURA si unos trabajadores ya son
 * cotizantes vigentes de una empresa. Solo lee: no aplica ninguna novedad.
 *
 * El portal (`epsapps.suramericana.com/Semp`) es JSF sin API JSON, así que la
 * consulta es la de pantalla: Consultas → Afiliados → Cotizantes, reporte
 * detallado. Ese reporte SÍ filtra por empleador —con una cédula de otra empresa
 * responde "El afiliado no existe como cotizante de la empresa."—, que es
 * justo lo que hace falta para saber si el trámite de EPS ya está hecho.
 *
 * Entrada por stdin: {tipoDocumento, usuario, contrasena, nitEmpresa,
 *                     documentos: [{tipo: 'CC', numero: '123'}]}
 * Salida por stdout: {ok, empresa, resultados: [{tipo, numero, encontrado,
 *                     estado, nombre, parentesco, cotiza, ips, empresa, mensaje}], error}
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { entrarEmpresaEps, esperar, pulsarId, texto } from './eps-sura-sesion-comun.mjs';

const URL_CONSULTA = 'https://epsapps.suramericana.com/Semp/faces/pos/afiliadosCotizantes/parametros.jspx';

// Tipos de documento de BryNex → valores del select de la consulta.
const TIPOS = { CC: 'CC', CE: 'CE', PA: 'PA', PP: 'PA', TI: 'TI', RC: 'RC', PT: 'PT', PPT: 'PT', PE: 'PE', PEP: 'PE', SC: 'SC', CD: 'CD' };

const salir = (d) => { console.log(JSON.stringify(d)); process.exit(d.ok ? 0 : 1); };

const leerStdin = async () => {
  let datos = '';
  for await (const t of process.stdin) datos += t;
  return datos.trim();
};

let entrada;
try { entrada = JSON.parse(await leerStdin() || '{}'); }
catch { salir({ ok: false, error: 'Entrada JSON inválida.' }); }

const { usuario, contrasena, nitEmpresa, documentos = [] } = entrada;
if (!usuario || !contrasena || !nitEmpresa) salir({ ok: false, error: 'Faltan credenciales o NIT de la empresa.' });

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

/** El valor de una fila etiqueta→valor del reporte detallado. */
const campo = (t, etiqueta) => {
  const m = t.match(new RegExp(etiqueta + '[ \\t]*\\t[ \\t]*([^\\t\\n]*)', 'i'));
  return m ? m[1].trim() : null;
};

let pagina;
let paso = 'inicio';
const resultados = [];
let empresa = null;

try {
  pagina = await navegador.newPage();

  // El paso se distingue: si falla el login, quien llama no debe insistir
  // (Sura bloquea al usuario tras varios intentos fallidos).
  paso = 'login';
  await entrarEmpresaEps(pagina, entrada);

  // ── Una consulta por documento ──
  for (const doc of documentos) {
    const numero = String(doc.numero || '').replace(/\D/g, '');
    const tipo = TIPOS[String(doc.tipo || 'CC').toUpperCase()] || 'CC';
    const r = { tipo: doc.tipo || 'CC', numero, encontrado: false, estado: null, nombre: null,
                parentesco: null, cotiza: null, ips: null, empresa: null, mensaje: null };
    paso = `consulta ${numero}`;

    try {
      await pagina.goto(URL_CONSULTA, { waitUntil: 'networkidle2', timeout: 60000 });
      await pagina.waitForSelector('[id="afiliadosCotizantes:dniCotizante"]', { visible: true, timeout: 30000 });

      // Detallado: el general no dice si tiene derecho a los servicios. El radio
      // dispara un ajax de RichFaces; hay que dejarlo terminar antes de seguir.
      await pagina.click('[id="afiliadosCotizantes:tipoReporte:1"]');
      await esperar(1500);
      await pagina.select('[id="afiliadosCotizantes:tipoDniCotizante"]', tipo).catch(() => {});
      await pagina.click('[id="afiliadosCotizantes:dniCotizante"]', { clickCount: 3 });
      await pagina.type('[id="afiliadosCotizantes:dniCotizante"]', numero, { delay: 30 });
      // Salir del campo lanza la validación ajax que el reporte necesita.
      await pagina.keyboard.press('Tab');
      await esperar(2500);

      await pulsarId(pagina, 'afiliadosCotizantes:generar');

      // El "no existe" llega por ajax en un modal; el reporte, navegando.
      let t = '';
      for (let i = 0; i < 30; i++) {
        await esperar(700);
        t = await texto(pagina);
        if (/no existe como cotizante de la empresa|Afiliados cotizantes detallado/i.test(t)) break;
      }

      if (/no existe como cotizante de la empresa/i.test(t)) {
        r.mensaje = 'El afiliado no existe como cotizante de la empresa.';
      } else if (/Afiliados cotizantes detallado/i.test(t)) {
        r.encontrado = true;
        r.estado  = campo(t, 'Estado para la prestaci[oó]n del servicio');
        r.ips     = campo(t, 'IPS');
        r.empresa = campo(t, 'Empresa');
        r.nombre  = [campo(t, 'Nombre'), campo(t, 'Primer apellido'), campo(t, 'Segundo apellido')]
          .filter(Boolean).join(' ');

        // La fila del propio afiliado en el grupo familiar dice si es titular o
        // beneficiario de otro y si cotiza.
        const fila = t.split('\n').find(l => new RegExp(`^\\s*[A-Z]{2}\\s+0*${numero}\\t`).test(l));
        if (fila) {
          const c = fila.split('\t').map(s => s.trim());
          r.parentesco = c[2] || null;
          r.cotiza     = c[6] || null;
        }
        empresa ??= r.empresa;
      } else {
        // Ni una cosa ni la otra: el portal mostró algo inesperado (sesión
        // caída, mantenimiento). Se reporta sin adivinar.
        r.mensaje = 'Respuesta no reconocida: ' + t.replace(/\s+/g, ' ').trim().slice(0, 200);
      }
    } catch (e) {
      r.mensaje = 'Error consultando: ' + String(e.message || e).slice(0, 200);
    }

    resultados.push(r);
  }

  salir({ ok: true, empresa, resultados });
} catch (e) {
  let captura = null;
  try {
    if (pagina && !pagina.isClosed() && process.env.ARL_DEBUG_DIR) {
      captura = `${process.env.ARL_DEBUG_DIR}/eps-sura-consultar-fallo.png`;
      await pagina.screenshot({ path: captura, fullPage: true });
    }
  } catch {}
  salir({ ok: false, paso, error: String(e.message || e).slice(0, 300), resultados, captura });
} finally {
  await navegador.close().catch(() => {});
}
