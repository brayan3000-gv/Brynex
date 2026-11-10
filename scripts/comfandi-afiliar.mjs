// Afiliación individual a Comfandi por robot de servidor.
//
// Porta el llenado del formulario de la extensión BryNex Portales
// (`background.js`, `pCfdLlenar`/`pCfdResultado`) a Puppeteer de servidor, para
// que el daemon (`comfandi-subsidios.mjs`, modo `afiliarIndividual`) radique
// reusando la sesión del gestor. Lo llama `ComfandiApi::afiliarIndividual` vía
// `ComfandiConsultaApiJob` (worker), no una petición web (tarda 1–3 min).
//
// La TRAMPA clave es el cambio de empresa: el switcher es el «pill» del header
// con el nombre de la empresa activa; un clic abre la lista de empresas del
// gestor. Se verifica la empresa y se ABORTA si no es la del contrato, antes de
// llenar y antes de Finalizar (si no, se radicaría en la empresa equivocada).

/** Lee el número de radicado (002-002-…) tras Finalizar. Se inyecta en la página. */
export function pCfdResultado() {
  const t = (document.body.innerText || '').replace(/\s+/g, ' ');
  if (/Aqu[ií] podr[aá]s visualizar los radicados/i.test(t) && !/Tu n[uú]mero de radicado es/i.test(t)) return { radicado: false, buscarEnRadicados: true, motivo: 'en-lista', texto: t.slice(0, 600) };
  const m = t.match(/n[uú]mero de radicado es:?\s*(\d{3}-\d{3}-\d{6,})/i) || t.match(/\b(\d{3}-\d{3}-\d{6,})\b/);
  const exito = /exitos|radicad[oa]|registrad[oa]|recibimos tu solicitud/i.test(t);
  const sinNumero = /no se pudo generar el radicado|no pudo ser radicada/i.test(t);
  const yaExiste = /ya se encuentra con un radicado en proceso/i.test(t);
  if (!m && (sinNumero || yaExiste)) return { radicado: false, buscarEnRadicados: true, motivo: yaExiste ? 'ya-existe' : 'sin-numero', texto: t.slice(0, 600) };
  if (!m && !exito) return { radicado: false, texto: t.slice(0, 600) };
  return { radicado: !!m, numero: m ? m[1] : null, texto: t.slice(0, 1200) };
}

/** Llena el formulario de Afiliación individual. Idempotente: solo toca lo vacío. Se inyecta en la página. */
export function pCfdLlenar(d) {
  const MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
  const norm = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
  const set = (e, v) => { if (!e || v == null || v === '') return false; const p = e instanceof HTMLSelectElement ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(p, 'value').set.call(e, String(v)); e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); return true; };
  const porPlaceholder = (ph) => [...document.querySelectorAll('input')].find((e) => e.placeholder === ph);
  const combo = (etiqueta) => [...document.querySelectorAll('select')].find((s) => (s.options[0]?.text || '').trim() === etiqueta);
  const hecho = [], falta = [];
  const texto = (ph, v, nombre) => { const e = porPlaceholder(ph); if (e && !e.disabled && !e.value && set(e, v)) hecho.push(nombre); };
  const elegir = (etiqueta, v, nombre) => { const s = combo(etiqueta); if (!s || s.value || v == null || v === '') return; if (![...s.options].some((o) => o.value === String(v))) { falta.push(`${nombre}: el portal no tiene el valor ${v}`); return; } set(s, v) && hecho.push(nombre); };
  const elegirTexto = (etiqueta, t, nombre) => {
    const s = combo(etiqueta); if (!s || s.value || !t) return; const q = norm(t);
    const raiz = (p) => p.replace(/(ES|AS|OS|A|O|E)$/, '');
    const VAGAS = /^(OPERARIO|OPERARIA|OPERARIOS|AUXILIAR|AYUDANTE|TECNICO|TECNICA|ASISTENTE|OPERADOR|OPERADORA|JEFE|SUPERVISOR|TRABAJADOR|EMPLEADO|PERSONAL)$/;
    const palabras = q.split(' ').filter((p) => p.length > 3 && !/^(DE|DEL|LA|LAS|LOS|EN|Y|PARA)$/.test(p));
    const clave = palabras.find((p) => !VAGAS.test(p)) || palabras[0] || q;
    const busca = (p) => [...s.options].find((o) => o.value && norm(o.text).includes(p));
    const op = [...s.options].find((o) => norm(o.text) === q) || [...s.options].find((o) => o.value && norm(o.text).includes(q)) || (clave.length > 3 ? busca(clave) : null) || (raiz(clave).length > 4 ? busca(raiz(clave)) : null);
    if (!op) { falta.push(`${nombre}: no se encontró "${t}" en la lista, escógelo tú`); return; }
    set(s, op.value) && hecho.push(`${nombre}: ${op.text.trim()}`);
  };
  if (!document.getElementById('inputdocument') && !porPlaceholder('Primer nombre')) return { paso: 'Sin formulario a la vista', hecho, falta: ['Abre Afiliación individual'], errores: [] };
  const idFalta = [['Primer nombre', d.primerNombre, 'primer nombre'], ['Segundo nombre (opcional)', d.segundoNombre, 'segundo nombre'], ['Primer apellido', d.primerApellido, 'primer apellido'], ['Segundo apellido (opcional)', d.segundoApellido, 'segundo apellido']].filter(([ph]) => { const e = porPlaceholder(ph); return e && !e.value; });
  idFalta.forEach(([ph, valor, nombre]) => { if (valor) texto(ph, valor, nombre + ' (de BryNex)'); });
  const nac = porPlaceholder('Fecha de nacimiento');
  if (nac && !nac.value && d.nacimiento) { const comoLoQuiere = d.nacimiento.split('-').reverse().join(' | '); const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set; nac.focus(); setter.call(nac, comoLoQuiere); nac.dispatchEvent(new Event('input', { bubbles: true })); nac.dispatchEvent(new Event('change', { bubbles: true })); nac.blur(); if (nac.value) hecho.push('fecha de nacimiento: ' + nac.value + ' (de BryNex)'); }
  elegir('Selecciona Género', d.genero, 'género');
  elegir('Selecciona Estado civil', d.estadoCivil, 'estado civil');
  elegir('Selecciona Orientación sexual', d.orientacion, 'orientación sexual');
  elegir('Selecciona Nivel académico', d.nivel, 'nivel académico');
  elegir('Selecciona Nacionalidad', d.nacionalidad, 'nacionalidad');
  elegir('Selecciona Factor de Vulnerabilidad', d.vulnerabilidad, 'factor de vulnerabilidad');
  elegir('Selecciona Pertenencia Étnica', d.etnia, 'pertenencia étnica');
  elegir('Selecciona Ciudad', d.ciudad, 'ciudad');
  elegir('Selecciona Departamento', d.departamento, 'departamento');
  texto('Dirección del trabajador:', d.direccion, 'dirección: ' + d.direccion);
  texto('Celular', d.celular, 'celular');
  texto('Email', d.correo, 'correo');
  elegir('Selecciona Horas diarias contratadas', d.horas, 'horas diarias');
  elegir('Selecciona Tipo salario', d.tipoSalario, 'tipo de salario');
  texto('Sueldo declarado', String(d.salario), 'sueldo');
  elegir('Selecciona Tipo de contrato', d.tipoContrato, 'tipo de contrato');
  elegirTexto('Selecciona Ocupación', d.ocupacionTexto, 'ocupación');
  elegir('Selecciona Ciudad donde labora el trabajador', d.ciudadLabor, 'ciudad donde labora');
  const fecha = porPlaceholder('Fecha de ingreso');
  const comoTexto = d.fechaIngreso ? d.fechaIngreso.split('-').reverse().join(' | ') : '';
  if (fecha && !fecha.value && d.fechaIngreso) {
    const [anio, mes, dia] = d.fechaIngreso.split('-').map(Number);
    const set2 = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    fecha.focus(); set2.call(fecha, comoTexto); fecha.dispatchEvent(new Event('input', { bubbles: true })); fecha.dispatchEvent(new Event('change', { bubbles: true })); fecha.blur();
    if (!document.querySelector('.rmdp-wrapper, .rmdp-calendar')) { fecha.focus(); ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => fecha.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); }
    const w = document.querySelector('.rmdp-wrapper, .rmdp-calendar');
    if (fecha.value) hecho.push('fecha de ingreso: ' + fecha.value);
    else if (!w) falta.push('fecha de ingreso: no se pudo escribir ni abrir el calendario; escoge el ' + comoTexto + ' a mano');
    else { const cab = (w.querySelector('.rmdp-header')?.innerText || '').replace(/\s+/g, ' ').trim(); const m = cab.match(/([A-Za-zÁÉÍÓÚáéíóú]+)[, ]+(\d{4})/); if (m) { const actual = MESES.indexOf(norm(m[1])) + 1; const saltos = (Number(m[2]) - anio) * 12 + (actual - mes); if (saltos !== 0) { const flecha = w.querySelector(saltos > 0 ? '.rmdp-left' : '.rmdp-right'); (flecha?.querySelector('span') || flecha)?.click(); falta.push('fecha de ingreso: buscando ' + comoTexto + ' en el calendario'); } else { const celda = [...w.querySelectorAll('.rmdp-day')].filter((x) => !/rmdp-deactive|rmdp-disabled/.test(x.className)).find((x) => x.innerText.trim() === String(dia)); if (!celda) falta.push('fecha de ingreso: el calendario no deja escoger ese día, escógelo tú'); else { (celda.querySelector('span') || celda).click(); hecho.push('fecha de ingreso: ' + comoTexto); } } } }
  } else if (fecha && fecha.value) hecho.push('fecha de ingreso: ' + fecha.value.replace(/\s/g, ''));
  const errores = [...new Set([...document.querySelectorAll('*')].filter((e) => e.children.length === 0 && /obligatori|inv[aá]lid|no es v[aá]lid/i.test(e.innerText || '')).map((e) => e.innerText.trim()).filter((t) => t.length < 120))];
  [['Primer nombre', 'primer nombre'], ['Primer apellido', 'primer apellido'], ['Fecha de nacimiento', 'fecha de nacimiento'], ['Dirección del trabajador:', 'dirección'], ['Celular', 'celular'], ['Email', 'correo'], ['Fecha de ingreso', 'fecha de ingreso'], ['Sueldo declarado', 'sueldo']].forEach(([ph, nombre]) => { const e = porPlaceholder(ph); if (e && !String(e.value || '').trim()) falta.push(nombre + ': el portal lo dejó vacío'); });
  return { paso: 'Afiliación individual', hecho, falta, errores: errores.slice(0, 6) };
}

/**
 * Radica (o solo llena, si `op.finalizar` es falso) una afiliación individual.
 * `pagina` es la página del daemon ya logueado; `op` es el array `portal` de
 * `ComfandiCajaService::preparar` + `finalizar`. `esperar`/`BASE` vienen del .mjs.
 */
export async function afiliarIndividual(pagina, op, esperar, BASE) {
  const ev = (fn, ...a) => pagina.evaluate(fn, ...a).catch(() => null);
  const esperarQue = async (fn, args = [], timeout = 30000) => { const lim = Date.now() + timeout; while (Date.now() < lim) { const r = await pagina.evaluate(fn, ...args).catch(() => null); if (r) return r; await esperar(600); } return null; };
  const activa = () => pagina.evaluate(() => (document.body.innerText.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i) || [])[1] || '').catch(() => '');
  const dest = String(op.empresa || '').toUpperCase().replace(/\s+/g, ' ').slice(0, 12); // prefijo robusto (CONTAC vs CONTACT)
  const d = { ...op, tipoContrato: op.tipoContrato || '02', estadoCivil: op.estadoCivil || '1' };

  const cambiarEmpresa = async (dd) => {
    for (let i = 0; i < 3; i++) {
      if ((await activa()).toUpperCase().includes(dd)) return true;
      await ev(() => { const act = (document.body.innerText.match(/actualmente est[aá]s en:\s*\n+\s*([^\n]+)/i) || [])[1] || ''; const bs = [...document.querySelectorAll('button')].filter((x) => x.offsetParent && (x.innerText || '').replace(/\s+/g, ' ').trim() === act.trim()); const pill = bs.find((b) => /rounded|bg-principal/.test(b.className)) || bs[bs.length - 1]; if (pill) { ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => pill.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); if (pill.click) pill.click(); } });
      await esperar(1500);
      await ev((ddd) => { const norm = (t) => String(t || '').toUpperCase().replace(/\s+/g, ' ').trim(); const o = [...document.querySelectorAll('button,[role=button],[role=option],li')].find((e) => e.offsetParent && norm(e.innerText).includes(ddd) && norm(e.innerText).length < 70); if (o) { ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => o.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); if (o.click) o.click(); } return !!o; }, dd);
      await esperar(2000);
      await ev(() => { const a = [...document.querySelectorAll('button,input[type=submit]')].find((e) => e.offsetParent && /^\s*aceptar\s*$/i.test((e.innerText || e.value || '').trim())); if (a) a.click(); });
      await esperar(2500);
    }
    return (await activa()).toUpperCase().includes(dd);
  };

  const empresaSiLaPide = async (nit, nombre) => {
    const lapide = await ev(() => /selecciona la empresa con la que deseas trabajar|no tiene permisos para gestionar/i.test(document.body.innerText || '') && !/actualmente est[aá]s en/i.test(document.body.innerText || ''));
    if (!lapide) return false;
    const lim = Date.now() + 40000;
    while (Date.now() < lim) {
      const listo = await ev((nitB, nomB) => {
        const golpe = (e) => { ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => e.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); if (typeof e.click === 'function') e.click(); };
        if (/actualmente est[aá]s en/i.test(document.body.innerText || '')) return true;
        const MENU = /^(inicio|ir al inicio|gesti[oó]n de trabajadores|radicados|certificados de empresa|actualizar datos de empresa|administraci[oó]n de usuarios|cerrar sesi[oó]n|selecciona tu empresa|aceptar)$/i;
        const opciones = [...document.querySelectorAll('button,[role=button],li,div[class*=card]')].filter((e) => { const t = (e.innerText || '').replace(/\s+/g, ' ').trim(); return e.offsetParent && t && t.length < 200 && !MENU.test(t); });
        const abridor = [...document.querySelectorAll('button,[role=button]')].find((e) => e.offsetParent && /selecciona tu empresa/i.test(e.innerText || ''));
        if (abridor && !opciones.length) { golpe(abridor); return false; }
        const suya = opciones.find((e) => { const t = (e.innerText || '').trim(); return t.replace(/\D/g, '').includes(String(nitB).slice(0, 9)) || (nomB && t.toUpperCase().includes(String(nomB).toUpperCase().slice(0, 12))); }) || (opciones.length === 1 ? opciones[0] : null);
        if (suya) { golpe(suya); return false; }
        const aceptar = [...document.querySelectorAll('button,input[type=submit]')].find((e) => e.offsetParent && /^\s*aceptar\s*$/i.test((e.innerText || e.value || '').trim()));
        if (aceptar) { golpe(aceptar); return false; }
        return false;
      }, [String(nit || ''), String(nombre || '')]);
      if (listo) return true;
      await esperar(1500);
    }
    return false;
  };

  const ir = async (ruta, nit, empresa) => {
    const destino = `/sakaar/${ruta}`;
    const pulsado = await ev((dd) => { if (location.pathname === dd) return true; const a = document.querySelector(`a[href="${dd}"]`); if (!a) return false; ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => a.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); return true; }, destino);
    if (!pulsado) { await pagina.goto(`${BASE.replace('/sakaar', '')}${destino}`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}); }
    let llego = await esperarQue((dd) => location.pathname === dd, [destino], 25000);
    if (!llego && await empresaSiLaPide(nit, empresa)) {
      await ev((dd) => { const a = document.querySelector(`a[href="${dd}"]`); if (a) ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => a.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); return !!a; }, destino);
      llego = await esperarQue((dd) => location.pathname === dd, [destino], 25000);
    }
    await esperar(2500);
    return !!llego;
  };

  const abrirIndividual = async (nit, empresa) => {
    if (await ev(() => location.pathname === '/sakaar/individual') === true) return true;
    await empresaSiLaPide(nit, empresa);
    if (!await ir('workers', nit, empresa)) return false;
    await esperarQue(() => { if (location.pathname === '/sakaar/individual') return true; const b = [...document.querySelectorAll('button,a,[role=button]')].find((e) => e.offsetParent && /afiliar trabajador/i.test((e.innerText || '').replace(/\s+/g, ' '))); if (!b) return false; ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); return false; }, [], 25000);
    const llego = await esperarQue(() => location.pathname === '/sakaar/individual', [], 20000);
    await esperar(2500);
    return !!llego;
  };

  // ── Flujo ──────────────────────────────────────────────────────────────────
  await pagina.goto(`${BASE}/affiliations`, { waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
  await esperar(2000);
  await cambiarEmpresa(dest);
  const emp = await activa();
  if (!emp.toUpperCase().includes(dest)) return { ok: false, error: `No se pudo cambiar a la empresa ${op.empresa} (quedó en «${emp}»).` };

  if (!await ir('workers', op.nit, op.empresa)) return { ok: false, error: 'No se pudo abrir Gestión de trabajadores.' };

  const ya = await ev((doc) => { const f = [...document.querySelectorAll('tr')].find((e) => (e.innerText || '').replace(/\s/g, '').includes(doc)); if (!f) return null; const c = [...f.querySelectorAll('td')].map((x) => x.innerText.trim()); return { nombre: c[1] || '', desde: c[4] || c[3] || '' }; }, String(op.documento));
  if (ya && ya.nombre) return { ok: true, yaAfiliado: true, nombre: ya.nombre, desde: ya.desde };

  if (!await abrirIndividual(op.nit, op.empresa)) return { ok: false, error: 'No se pudo abrir «Afiliar trabajador».' };

  const puesto = await esperarQue((tipo, doc) => { const s = document.getElementById('selectdocument'), i = document.getElementById('inputdocument'); if (!s || !i) return false; const set = (e, v) => { const p = e instanceof HTMLSelectElement ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(p, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }; set(s, tipo); set(i, doc); return true; }, [String(op.tipoDoc), String(op.documento)], 25000);
  if (!puesto) return { ok: false, error: 'No apareció el cuadro de tipo y número de documento.' };

  await ev(() => { const b = [...document.querySelectorAll('button')].find((x) => /Afiliar trabajador/i.test(x.innerText)); if (!b) return false; ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); return true; });

  const confirmar = await esperarQue(() => { const b = [...document.querySelectorAll('button')].find((x) => /^(Continuar|Confirmar)$/i.test(x.innerText.trim())); if (!b) return null; ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((t) => b.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, view: window }))); return true; }, [], 45000);
  if (!confirmar) { const msg = await ev(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300)); return { ok: false, error: 'El portal no ofreció Continuar/Confirmar tras la consulta. ' + (msg || '') }; }

  await esperar(3500);
  await esperarQue(() => { const i = [...document.querySelectorAll('input')].find((e) => e.placeholder === 'Primer nombre'); return i ? true : null; }, [], 30000);

  const emp2 = await activa();
  if (!emp2.toUpperCase().includes(dest)) return { ok: false, error: `La empresa cambió antes de llenar («${emp2}»).` };

  await pagina.evaluate(pCfdLlenar, d);
  await esperar(1500);
  const r = await pagina.evaluate(pCfdLlenar, d);
  if (r.falta && r.falta.length) return { ok: false, error: 'El formulario quedó incompleto: ' + r.falta.join('; '), hecho: r.hecho, falta: r.falta };
  if (!op.finalizar) return { ok: true, lleno: true, hecho: r.hecho, empresa: emp2 };

  const emp3 = await activa();
  if (!emp3.toUpperCase().includes(dest)) return { ok: false, error: `La empresa cambió antes de Finalizar («${emp3}»).` };

  const clickBtn = (re) => esperarQue((pat) => { const b = [...document.querySelectorAll('button,input[type=submit]')].find((e) => e.offsetParent && new RegExp(pat, 'i').test((e.innerText || e.value || '').trim())); if (!b || b.disabled) return false; b.click(); return true; }, [re], 25000);
  if (!await clickBtn('^\\s*finalizar')) return { ok: false, error: 'No se encontró el botón Finalizar.' };
  if (!await clickBtn('^\\s*confirmar\\s*$')) { const p = await ev(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300)); return { ok: false, error: 'Se pulsó Finalizar pero el portal no pidió confirmar el sueldo. ' + (p || '') }; }

  await esperar(6000);
  await esperarQue(() => !/procesando tu solicitud/i.test(document.body.innerText || ''), [], 60000);
  const res = await pagina.evaluate(pCfdResultado);
  if (res.radicado && res.numero) return { ok: true, numero: res.numero, texto: res.texto };
  if (res.buscarEnRadicados) return { ok: false, buscarEnRadicados: true, motivo: res.motivo, error: 'Radicó pero el portal no mostró el número; búscalo en Radicados.', texto: res.texto };
  return { ok: false, error: 'No se obtuvo el número de radicado tras Finalizar.', texto: res.texto };
}
