/**
 * Portal de empleadores de Nueva EPS: reingresos y consulta de novedades.
 *
 * El login es JSF (ICEfaces) sin teclado virtual, pero Reingresos y Retiros es
 * una SPA Angular (`/rrweb`) con API JSON en `/RRWeb-0.0.1-SNAPSHOT/service/`.
 * La SPA guarda un JWT en `localStorage.token2` y lo manda como Authorization;
 * por eso las llamadas se hacen desde la propia página, con la sesión que abrió
 * este navegador, y no desde PHP.
 *
 * Modos (stdin JSON, siempre con {tipoDocumento, usuario, contrasena, nitEmpresa}):
 *  - reingreso: {persona:{tipo, numero, apellido}, ibc, fechaInicio 'AAAA-MM-DD',
 *               cargo:{codigo, descripcion}, asesor, registrar, buscarDesde}
 *    Consulta el nombre en la EPS, busca reingresos ya radicados de esa persona
 *    y, solo si registrar=true y no hay uno, radica el reingreso.
 *  - novedades: {documentos:[{numero, desde}], pdf}
 *    Busca los reingresos de la empresa y devuelve el último de cada documento,
 *    con el certificado en base64 si pdf=true.
 *
 * Salida por stdout: {ok, paso, error, ...}. `paso: 'login'` significa que la
 * clave fue rechazada: quien llama no debe reintentar con la misma.
 */
import { rmSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';

const BASE = 'https://portal.nuevaeps.com.co';
const TIPOS_LOGIN = { CC: '3', CE: '1', TI: '2', PA: '6', PS: '6', PT: '15', PE: '13', NT: '4', RC: '5', SC: '12', CD: '10' };

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

const { usuario, contrasena, nitEmpresa, modo = 'reingreso' } = entrada;
if (modo !== 'conexion' && (!usuario || !contrasena || !nitEmpresa)) salir({ ok: false, error: 'Faltan credenciales o NIT de la empresa.' });

// Nueva EPS responde 403 "El contenido a este sitio está Restringido" a la IP
// del servidor (datacenter fuera de Colombia), así que se sale por un proxy con
// IP colombiana: `http://usuario:clave@host:puerto`. Chrome no acepta la clave
// en --proxy-server; se entrega con page.authenticate().
let proxy = null;
if (entrada.proxy) {
  try {
    const u = new URL(entrada.proxy);
    proxy = {
      servidor: `${u.protocol}//${u.hostname}:${u.port}`,
      usuario: decodeURIComponent(u.username || ''),
      clave: decodeURIComponent(u.password || ''),
    };
  } catch {
    salir({ ok: false, paso: 'proxy', error: 'La dirección del proxy no es válida (se espera http://usuario:clave@host:puerto).' });
  }
}

// Túnel de la oficina: `127.0.0.1:18443` reenvía al 443 del portal por la IP del
// ISP de la oficina. Chrome resuelve el dominio a ese puerto; el certificado
// sigue siendo el de Nueva EPS, así que TLS valida igual. Sin QUIC, que iría
// por UDP y el túnel solo lleva TCP.
let tunel = null;
if (entrada.tunel) {
  if (!/^[\w.-]+:\d{2,5}$/.test(String(entrada.tunel))) {
    salir({ ok: false, paso: 'tunel', error: 'La dirección del túnel no es válida (se espera 127.0.0.1:18443).' });
  }
  tunel = String(entrada.tunel);
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
  args: [
    '--no-sandbox', '--disable-dev-shm-usage', '--disable-blink-features=AutomationControlled',
    ...(proxy ? [`--proxy-server=${proxy.servidor}`] : []),
    ...(tunel ? [`--host-rules=MAP portal.nuevaeps.com.co ${tunel}`, '--disable-quic'] : []),
  ],
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

const contextoPerdido = (e) => /Execution context was destroyed|Cannot find context|Target closed/i.test(String(e?.message || e));
const texto = (p) => p.evaluate(() => document.body?.innerText || '').catch(() => '');

/** Clic en el enlace cuyo texto cumple el patrón; tolera la navegación que dispara. */
const clicEnlace = async (pagina, patron) => {
  const navegacion = pagina.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
  let hecho = false;
  try {
    hecho = await pagina.evaluate((p) => {
      const re = new RegExp(p, 'i');
      const a = [...document.querySelectorAll('a')].find(e => re.test(e.innerText || ''));
      if (a) { a.click(); return true; }
      return false;
    }, patron);
  } catch (e) {
    if (!contextoPerdido(e)) throw e;
    hecho = true;
  }
  await navegacion;
  return hecho;
};

/**
 * Espera hasta que la condición (evaluada en la página) sea verdadera. Un error
 * dentro de la condición cuenta como "todavía no": en plena navegación
 * `document.body` puede ser null por un instante (pasó en el servidor, con más
 * latencia, justo después de pulsar Ingresar).
 */
const esperarQue = async (pagina, fn, ms, arg) => {
  const limite = Date.now() + ms;
  let ultimo = null;
  while (Date.now() < limite) {
    try { if (await pagina.evaluate(fn, arg)) return true; }
    catch (e) { ultimo = e; }
    await esperar(700);
  }
  if (ultimo && !contextoPerdido(ultimo) && !/of null|of undefined/i.test(String(ultimo.message))) throw ultimo;
  return false;
};

let pagina;
let paso = 'inicio';

try {
  pagina = await navegador.newPage();
  await pagina.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
  if (proxy?.usuario) {
    await pagina.authenticate({ username: proxy.usuario, password: proxy.clave });
  }

  // ── Conexión: con qué IP se sale y si Nueva EPS deja entrar. Sin claves. ──
  if (modo === 'conexion') {
    paso = 'conexion';
    let ip = null;
    try {
      await pagina.goto('https://ipinfo.io/json', { waitUntil: 'domcontentloaded', timeout: 45000 });
      ip = JSON.parse(await pagina.evaluate(() => document.body.innerText));
    } catch (e) {
      ip = { error: String(e.message || e).slice(0, 150) };
    }

    // Con túnel, la IP de arriba es la del servidor (ipinfo no pasa por el
    // túnel); lo que prueba el túnel es que el portal responda por 127.0.0.1.
    let estado = null;
    let remoto = null;
    const alResponder = (r) => {
      if (estado === null && r.request().isNavigationRequest()) { estado = r.status(); remoto = r.remoteAddress(); }
    };
    pagina.on('response', alResponder);
    await pagina.goto(`${BASE}/Portal/home.jspx`, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    pagina.off('response', alResponder);

    const formulario = !!(await pagina.$('[id="loginForm:clave"]').catch(() => null));
    salir({
      ok: formulario, modo, proxy: !!proxy, tunel,
      nueva_eps_remoto: remoto?.ip ? `${remoto.ip}:${remoto.port}` : null,
      ip: ip?.ip ?? null, pais: ip?.country ?? null, ciudad: ip?.city ?? null, red: ip?.org ?? null, ip_error: ip?.error ?? null,
      nueva_eps_http: estado, nueva_eps_titulo: await pagina.title().catch(() => null), formulario_login: formulario,
      error: formulario ? undefined : 'Nueva EPS no mostró el formulario de ingreso desde esta conexión.',
    });
  }

  // ── Login ──
  // `paso: 'login'` le dice a PHP que la clave fue rechazada y la bloquea hasta
  // que la cambien. Solo se usa cuando el portal MUESTRA un rechazo: un portal
  // que no carga (403 por IP, túnel caído), un error del script o un ingreso
  // que tarda no son eso (dos falsos bloqueos de ELITES el 14-sep-2026).
  paso = 'abrir portal';
  await pagina.goto(`${BASE}/Portal/home.jspx`, { waitUntil: 'networkidle2', timeout: 60000 });
  await pagina.waitForSelector('[id="loginForm:clave"]', { visible: true, timeout: 30000 })
    .catch(async () => { throw new Error(`Nueva EPS no mostró el formulario de ingreso: ${(await pagina.title().catch(() => '')) || 'sin respuesta'}.`); });
  paso = 'ingresar';

  const [tipoTexto, numeroUsuario] = String(usuario).trim().match(/^([A-Za-z]{2})\s+(\d+)$/)
    ? String(usuario).trim().split(/\s+/)
    : [entrada.tipoDocumento || 'CC', String(usuario).trim()];
  await pagina.select('[id="loginForm:tipoId"]', TIPOS_LOGIN[String(tipoTexto).toUpperCase()] || '3');
  await pagina.type('[id="loginForm:id"]', numeroUsuario, { delay: 30 });
  await pagina.type('[id="loginForm:clave"]', String(contrasena), { delay: 30 });
  await pagina.click('[id="loginForm:loginButton"]').catch(() => {});

  const entro = await esperarQue(pagina, () => /Bienvenido/i.test(document.body?.innerText || ''), 45000);
  if (!entro) {
    const t = (await texto(pagina)).replace(/\s+/g, ' ');
    const motivo = (t.match(/[^.]*(inv[aá]lid|incorrect|bloquead|errad|no existe|no coincide)[^.]*\.?/i) || [])[0];
    if (motivo) paso = 'login';
    throw new Error((motivo || `El portal no confirmó el ingreso (sin mensaje de rechazo): ${t.slice(0, 120)}`).trim().slice(0, 200));
  }

  // ── Empleador ──
  paso = 'elegir empleador';
  await pagina.goto(`${BASE}/Portal/pages/menu/welcome.jspx`, { waitUntil: 'networkidle2', timeout: 60000 });
  if (!await clicEnlace(pagina, '^\\s*Empleador\\s*$')) throw new Error('No apareció la opción Empleador.');

  const menuVisible = () => [...document.querySelectorAll('a')].some(a => /Reingresos y Retiros Laborales/i.test(a.innerText || ''));
  if (!await esperarQue(pagina, menuVisible, 5000)) {
    // Varios empleadores: se elige el del NIT.
    if (!await clicEnlace(pagina, `\\b${String(nitEmpresa).replace(/\D/g, '')}\\b`)) {
      const t = (await texto(pagina)).replace(/\s+/g, ' ');
      throw new Error(`El usuario no administra la empresa ${nitEmpresa} en Nueva EPS. ${t.slice(0, 160)}`);
    }
    if (!await esperarQue(pagina, menuVisible, 30000)) throw new Error('No cargó el menú del empleador.');
  }

  // ── Mapa del menú del empleador ──
  // Exploración: qué ofrece el portal a esta empresa. No toca nada, solo lista
  // los enlaces con su destino, que es como se descubre dónde vive cada cosa.
  if (modo === 'menu') {
    paso = 'leer menú';
    const enlaces = await pagina.evaluate(() => [...document.querySelectorAll('a')]
      .map((a) => ({
        texto: (a.innerText || '').replace(/\s+/g, ' ').trim(),
        destino: (a.getAttribute('href') || a.getAttribute('onclick') || '').slice(0, 120),
      }))
      .filter((e) => e.texto));

    salir({
      ok: true, modo, url: pagina.url(), titulo: await pagina.title().catch(() => null),
      enlaces, pantalla: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 1200),
    });
  }

  // ── Abrir una opción del menú y describirla ──
  // Sigue siendo exploración: entra a la pantalla que se le pida y cuenta qué
  // campos tiene y qué muestra, sin rellenar ni enviar nada.
  if (modo === 'pantalla') {
    const opcion = String(entrada.opcion || '').trim();
    paso = `abrir ${opcion}`;

    const destino = await pagina.evaluate((patron) => {
      const re = new RegExp(patron, 'i');
      const a = [...document.querySelectorAll('a')].find((x) => re.test((x.innerText || '').trim()));
      return a ? a.getAttribute('href') : null;
    }, opcion);

    if (!destino) throw new Error(`El menú no tiene la opción ${opcion}.`);

    await pagina.goto(new URL(destino, pagina.url()).href, { waitUntil: 'networkidle2', timeout: 60000 });

    // ICEfaces pinta la pantalla por AJAX después de cargar el marco: sin esto
    // solo se ve la cabecera y parece que la opción está vacía.
    await esperarQue(pagina, () => document.querySelectorAll(
      'input:not([id^="headerForm"]):not([type=hidden]), select, table tr:nth-child(2)'
    ).length > 0, 20000);
    await esperar(2500);

    const campos = await pagina.evaluate(() => [...document.querySelectorAll('input, select, textarea')]
      .filter((e) => e.type !== 'hidden')
      .map((e) => ({
        etiqueta: e.tagName.toLowerCase(),
        id: e.id || null,
        nombre: e.getAttribute('name') || null,
        tipo: e.getAttribute('type') || null,
        valor: (e.value || '').slice(0, 40) || null,
        opciones: e.tagName === 'SELECT' ? [...e.options].slice(0, 14).map((o) => `${o.value}=${o.text}`.slice(0, 40)) : undefined,
      })));

    // Las cabeceras de tabla dicen qué trae la consulta aunque venga vacía.
    const tablas = await pagina.evaluate(() => [...document.querySelectorAll('table')]
      .map((t) => ({
        columnas: [...t.querySelectorAll('th')].map((c) => (c.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean),
        filas: t.querySelectorAll('tbody tr').length,
      }))
      .filter((t) => t.columnas.length));

    salir({
      ok: true, modo, opcion, url: pagina.url(), titulo: await pagina.title().catch(() => null),
      campos, tablas, pantalla: (await texto(pagina)).replace(/\s+/g, ' ').slice(0, 2000),
    });
  }

  // ── Mora por trabajador (Estado de cuenta individual) ──
  // El portal la calcula solo: el reporte trae la mora ANTERIOR a la fecha que
  // se le pide, así que pidiéndolo con el primer día del mes en curso queda
  // fuera el mes que todavía se puede pagar sin estar en mora.
  if (modo === 'mora' || modo === 'cotizantes') {
    const fechaCorte = String(entrada.fechaCorte || '').match(/^\d{4}-\d{2}-\d{2}$/)
      ? entrada.fechaCorte
      : `${new Date().toISOString().slice(0, 7)}-01`;

    paso = 'abrir estado de cuenta';
    const destino = await pagina.evaluate(() => {
      const a = [...document.querySelectorAll('a')].find((x) => /Estado Cuenta Individual/i.test(x.innerText || ''));
      return a ? a.getAttribute('href') : null;
    });
    if (!destino) throw new Error('El menú del empleador no tiene Estado Cuenta Individual.');
    await pagina.goto(new URL(destino, pagina.url()).href, { waitUntil: 'networkidle2', timeout: 60000 });

    // El reporte es otra aplicación (report_portal) dentro de un iframe, con su
    // propio sessionId en la ruta. Las llamadas salen de ahí, no de la página.
    paso = 'abrir el reporte';
    if (!await esperarQue(pagina, () => [...document.querySelectorAll('iframe')].some((f) => f.src.includes('estadoCuentaIndividual')), 30000)) {
      throw new Error('No cargó el reporte de estado de cuenta.');
    }

    paso = 'pedir el reporte';
    const filtro = modo === 'cotizantes' ? 'todos' : 'mora';
    const crudo = await pagina.evaluate(async (fecha, filtro) => {
      const marco = [...document.querySelectorAll('iframe')].find((x) => x.src.includes('estadoCuentaIndividual'));
      const partes = marco.src.split('/');
      const node = partes.pop();
      const sid = partes.pop();
      const w = marco.contentWindow;
      const q = `sessionId=${sid}&node=${node}`;
      const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
      const json = { 'Content-Type': 'application/json' };

      // El portal corta conexiones de vez en cuando ("Failed to fetch") y eso
      // dejaba la empresa entera sin revisar. Se reintenta antes de rendirse.
      const pedir = async (url, opciones) => {
        let ultimo = null;

        for (let intento = 1; intento <= 3; intento++) {
          try {
            return await w.fetch(url, opciones);
          } catch (e) {
            ultimo = e;
            await dormir(3000 * intento);
          }
        }

        throw ultimo;
      };

      const pedido = await pedir(`/report_portal/v1/api/saveReportRequest?${q}`, {
        method: 'POST', headers: json,
        body: JSON.stringify({
          parameter: JSON.stringify({ fechaInicial: fecha, filter: filtro }),
          classType: 'estadoCuentaIndividualService', method: 'generarReporte',
        }),
      });
      if (!pedido.ok) return { error: `El portal rechazó la solicitud del reporte (${pedido.status}).` };

      // Lo genera aparte y avisa por estado; en empresas grandes tarda minutos.
      let estado = null;
      for (let i = 0; i < 36; i++) {
        await dormir(5000);
        const r = await pedir(`/report_portal/v1/api/lastReportRequestByUser/estadoCuentaIndividualService?${q}`).catch(() => null);
        if (!r) continue;
        if (!r.ok) continue;
        estado = (await r.json().catch(() => null))?.state || null;
        if (estado === 'PROCESADO') break;
      }
      if (estado !== 'PROCESADO') return { error: `El reporte no quedó listo (estado: ${estado || 'sin respuesta'}).` };

      // La pantalla lo pagina de 10 en 10; aquí se pide entero.
      const r = await pedir(`/report_portal/v1/api/empleador/reporteEstadoCuentaIndividual?${q}`, {
        method: 'POST', headers: json,
        body: JSON.stringify({
          page: 1, itemsPerPage: 2000, sortBy: [], sortDesc: [false], groupBy: [], groupDesc: [],
          mustSort: false, multiSort: false, filter: { columns: [], value: '' }, busquedaGeneral: {},
        }),
      });
      if (!r.ok) return { error: `El reporte respondió ${r.status}.` };
      const j = await r.json();
      return { data: j.data || [], total: j.pagination?.totalDesserts ?? null };
    }, fechaCorte, filtro);

    if (crudo.error) throw new Error(crudo.error);

    // Una fila por persona y año: el mes en mora va como "70333 (M)".
    const MESES = { ene: 1, feb: 2, mar: 3, abr: 4, may: 5, jun: 6, jul: 7, ago: 8, sept: 9, oct: 10, nov: 11, dic: 12 };
    const trabajadores = (crudo.data || []).map((f) => ({
      tipo: f.tipo || null,
      documento: String(f.numeroDocumento || '').trim(),
      nombre: String(f.nombreCotizante || '').replace(/\s+/g, ' ').trim(),
      fecha_ingreso: f.fechaIngreso || null,
      fecha_retiro: f.fechaRetiro || null,
      salario: Number(f.salario || 0),
      total_mora: Number(f.fullMora || 0),
      periodos: Object.entries(MESES)
        .filter(([nombre]) => f[nombre])
        .map(([nombre, mes]) => ({
          periodo: `${f.anuo}-${String(mes).padStart(2, '0')}`,
          valor: Number(String(f[nombre]).replace(/\D/g, '')) || 0,
        })),
    }));

    salir({ ok: true, modo, filtro, nit: String(nitEmpresa), fecha_corte: fechaCorte, filas: crudo.total, trabajadores });
  }

  // ── Reingresos y Retiros (SPA) ──
  paso = 'abrir reingresos';
  await clicEnlace(pagina, 'Reingresos y Retiros Laborales');
  const listo = await esperarQue(pagina, () => location.pathname.startsWith('/rrweb') && !!localStorage.getItem('token2') && !!localStorage.getItem('user'), 45000);
  if (!listo) throw new Error('No abrió la aplicación de Reingresos y Retiros.');

  const sesion = await pagina.evaluate(() => JSON.parse(localStorage.getItem('user') || '{}'));
  if (String(sesion.idParameter) !== String(nitEmpresa).replace(/\D/g, '')) {
    throw new Error(`La sesión quedó en la empresa ${sesion.idParameter}, no en ${nitEmpresa}.`);
  }

  // Llamadas al API desde la página, con el JWT de la SPA.
  const api = (metodo, ruta, cuerpo) => pagina.evaluate(async (metodo, ruta, cuerpo) => {
    const r = await fetch('/RRWeb-0.0.1-SNAPSHOT/service/' + ruta, {
      method: metodo,
      headers: { 'Content-Type': 'application/json', 'Authorization': localStorage.getItem('token2') },
      body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
    });
    const t = await r.text();
    let json = null; try { json = JSON.parse(t); } catch {}
    return { status: r.status, json, texto: t.slice(0, 500) };
  }, metodo, ruta, cuerpo);

  // Las fechas del API son medianoche de Colombia en milisegundos.
  const fecha = (ms) => ms ? new Date(Number(ms) - 5 * 3600 * 1000).toISOString().slice(0, 10) : null;
  const iso = (dia) => `${dia}T05:00:00.000Z`;
  const manana = new Date(Date.now() + 864e5).toISOString().slice(0, 10);

  /** Reingresos radicados o procesados de la empresa en el rango. */
  const reingresos = async (desde) => {
    const salida = [];
    for (const estadoSolicitud of ['1', '2']) {
      const r = await api('POST', 'SearchUpdates/doSearchListener', {
        tipoNovedad: '2', idTipoEmpresa: String(sesion.tidCodigoParameter), idNumeroEmpresa: String(sesion.idParameter),
        fechaInicial: iso(desde), fechaFinal: iso(manana), estadoSolicitud,
      });
      if (r.status === 200 && Array.isArray(r.json)) salida.push(...r.json);
      else if (r.status !== 400) throw new Error(`Consulta de novedades respondió ${r.status}: ${r.texto}`);
    }
    return salida;
  };

  const resumirRadicado = (rad, persona) => ({
    radicado: String(rad.numeroRadicado),
    estado: rad.estado,
    fecha_radicacion: fecha(rad.fechaRadicacion),
    inicio: fecha(persona?.inicioRelacionLaboral),
    asesor: persona?.codigoAsesor ?? null,
    respuesta: (rad.respuesta || '').slice(0, 400),
  });

  const hace = (dias) => new Date(Date.now() - dias * 864e5).toISOString().slice(0, 10);
  const desdeMinimo = (docs, porDefecto) => [porDefecto, ...docs.map(d => d.desde).filter(Boolean)].sort()[0];

  /**
   * El último reingreso de cada documento dentro de `todos`. El certificado
   * solo se pide si el documento lo pide (`pdf`), porque cada uno es otra llamada.
   * @param docs [{numero, desde, pdf}]
   */
  const resolverDocumentos = async (todos, docs) => {
    const resultados = [];
    for (const d of docs) {
      const numero = String(d.numero).replace(/\D/g, '');
      const candidatos = todos
        .map(rad => ({ rad, p: (rad.reintegros || []).find(x => String(x.documentoCotizante) === numero) }))
        .filter(({ rad, p }) => p && (!d.desde || fecha(rad.fechaRadicacion) >= d.desde))
        .sort((a, b) => Number(b.rad.fechaRadicacion) - Number(a.rad.fechaRadicacion));

      if (!candidatos.length) { resultados.push({ numero, encontrado: false }); continue; }

      const { rad, p } = candidatos[0];
      const fila = { numero, encontrado: true, ...resumirRadicado(rad, p) };
      if (d.pdf ?? entrada.pdf) {
        const pdf = await api('POST', 'SearchUpdates/generarPDF', rad);
        fila.pdf = pdf.json?.file || null;
      }
      resultados.push(fila);
    }
    return resultados;
  };

  if (modo === 'novedades') {
    paso = 'novedades';
    const docs = entrada.documentos || [];
    const todos = await reingresos(docs.length ? desdeMinimo(docs, hace(90)) : hace(90));

    salir({ ok: true, modo, empresa: sesion.nameParameter, resultados: await resolverDocumentos(todos, docs) });
  }

  // ── Reingreso ──
  paso = 'consultar persona';
  const persona = entrada.persona || {};
  const numero = String(persona.numero || '').replace(/\D/g, '');

  const tipos = (await api('GET', 'ReInsertRegister/loadDocumentTypesItems')).json || [];
  const abreviatura = { PA: 'PS', PP: 'PS', PPT: 'PT', PEP: 'PE' }[String(persona.tipo).toUpperCase()] || String(persona.tipo).toUpperCase();
  const tipoDoc = tipos.find(t => t.tipo === abreviatura);
  if (!tipoDoc) throw new Error(`Nueva EPS no tiene el tipo de documento ${persona.tipo}.`);

  const info = (await api('POST', `ReInsertRegister/doChargeTDInformation?documentoCotizante=${numero}&tipoDocumento=${tipoDoc.idTipoDocumento}`)).json || {};
  const nombreEps = [info.nombresCotizante, info.primerApellido, info.segundoApellido].filter(Boolean).join(' ').trim();

  const asesores = (await api('GET', `ReInsertRegister/asesoresList?tidCodigoParameter=${sesion.tidCodigoParameter}&idParameter=${sesion.idParameter}`)).json || [];

  // El asesor más usado por la empresa en el último año, por si no hay uno
  // configurado. El mismo historial sirve para revisar de paso los radicados en
  // trámite de la empresa (`conciliar`), así que alcanza hasta el más viejo.
  const porConciliar = entrada.conciliar || [];
  const historial = await reingresos(desdeMinimo(porConciliar, hace(365)));
  const conteo = {};
  historial.forEach(rad => (rad.reintegros || []).forEach(p => { if (p.codigoAsesor) conteo[p.codigoAsesor] = (conteo[p.codigoAsesor] || 0) + 1; }));
  const asesorSugerido = Object.entries(conteo).sort((a, b) => b[1] - a[1])[0]?.[0] || null;

  // Reingresos que ya existen para esta persona: evita radicar dos veces.
  const desde = entrada.buscarDesde || entrada.fechaInicio;
  const existentes = historial
    .map(rad => ({ rad, p: (rad.reintegros || []).find(x => String(x.documentoCotizante) === numero) }))
    .filter(({ rad, p }) => p && fecha(rad.fechaRadicacion) >= desde)
    .sort((a, b) => Number(b.rad.fechaRadicacion) - Number(a.rad.fechaRadicacion))
    .map(({ rad, p }) => resumirRadicado(rad, p));

  // Cargo: el código viene de la tabla de equivalencias; la descripción se toma
  // del catálogo del portal para mandar exactamente lo que la SPA mandaría.
  let cargoPortal = null;
  const palabra = String(entrada.cargo?.descripcion || '').split(/\s+/).find(w => w.length > 3) || '';
  const catalogo = (await api('GET', 'ReInsertRegister/loadPositionTypes?filtro=' + encodeURIComponent(palabra))).json || [];
  cargoPortal = catalogo.find(c => String(c.ocpCodigo) === String(entrada.cargo?.codigo)) || null;

  const normalizar = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/\s+/g, ' ').trim();
  const apellidoCoincide = !!persona.apellido && normalizar(nombreEps).includes(normalizar(persona.apellido));

  // Radicados en trámite de la empresa, revisados con la sesión ya abierta. Un
  // fallo aquí no debe tumbar el reingreso, que es lo que se pidió.
  let conciliacion = [];
  try {
    conciliacion = await resolverDocumentos(historial, porConciliar);
  } catch (e) {
    conciliacion = { error: String(e.message || e).slice(0, 200) };
  }

  const base = {
    ok: true, modo, empresa: sesion.nameParameter,
    nombre_eps: nombreEps || null, apellido_coincide: apellidoCoincide,
    asesores: asesores.map(a => ({ codigo: a.asesor, nombre: a.nombre })), asesor_sugerido: asesorSugerido,
    existentes, cargo_portal: cargoPortal ? { codigo: String(cargoPortal.ocpCodigo), descripcion: cargoPortal.ocpDescripcion } : null,
    conciliacion,
  };

  if (!entrada.registrar) salir(base);

  // ── Validaciones antes de escribir ──
  paso = 'validar';
  if (existentes.length) salir({ ...base, registrado: false, motivo: 'ya_existe' });
  if (!nombreEps) throw new Error('Nueva EPS no encuentra a la persona con ese documento: el reingreso no aplica (puede ser un traslado).');
  if (!apellidoCoincide) throw new Error(`El nombre en Nueva EPS (${nombreEps}) no coincide con el apellido de BryNex.`);
  if (!cargoPortal) throw new Error(`El cargo ${entrada.cargo?.codigo} no está en el catálogo de Nueva EPS.`);
  if (!asesores.some(a => a.asesor === entrada.asesor)) throw new Error(`El asesor ${entrada.asesor} no está disponible para esta empresa.`);

  // ── Registrar ──
  paso = 'registrar';
  const dto = {
    idRadicadoReintegro: {},
    idTipoDocumento: tipoDoc,
    listArchivosSoporte: [],
    documentoCotizante: numero,
    ibc: Number(entrada.ibc),
    inicioRelacionLaboral: iso(entrada.fechaInicio),
    codigoAsesor: entrada.asesor,
    cargo: cargoPortal.ocpDescripcion,
    idCargo: String(cargoPortal.ocpCodigo),
    nombresCotizante: nombreEps,
  };

  const lista = await api('POST', `ReInsertRegister/doCreateListener?numEmp=${sesion.idParameter}&tidCodigoParameter=${sesion.tidCodigoParameter}&id=${sesion.id}&typeId=${sesion.typeId}`, dto);
  if (lista.status !== 200 || !Array.isArray(lista.json) || !lista.json.length) {
    throw new Error(`Nueva EPS no aceptó los datos del reingreso (${lista.status}): ${lista.texto}`);
  }

  const q = new URLSearchParams({
    numEmp: sesion.idParameter, tidCodigoParameter: sesion.tidCodigoParameter, id: sesion.id,
    typeId: sesion.typeId, nameParameter: sesion.nameParameter || '', email: sesion.email || '',
  });
  const reg = await api('POST', `ReInsertRegister/doRegistrarReintegro?${q}`, lista.json);
  const mensaje = reg.json?.mensaje || reg.texto;
  const radicado = (String(mensaje).match(/(\d{6,})/) || [])[1] || null;

  if (reg.status !== 200 || !radicado) {
    throw new Error(`El registro del reingreso no confirmó un radicado (${reg.status}): ${String(mensaje).slice(0, 200)}`);
  }

  salir({ ...base, registrado: true, radicado, mensaje, dto, pdf: reg.json?.file || null });
} catch (e) {
  salir({ ok: false, paso, error: String(e.message || e).slice(0, 300) });
} finally {
  await navegador.close().catch(() => {});
}
