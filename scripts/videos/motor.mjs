/**
 * El motor que graba los videos de Brynex (adaptado del de LiderApp).
 *
 * Abre Chrome sin ventana contra la copia de Brynex que sirve app-videos.sh
 * (base de Docker con el aliado «Seguridad Social Demo», gente inventada),
 * entra con la cuenta del rol y graba la pantalla cuadro a cuadro por CDP (`Page.startScreencast`),
 * que da imágenes nítidas a 1920×1080 o 1080×1920 —la grabación de video de
 * Playwright sale borrosa—. El cursor, el círculo de cada clic, los subtítulos,
 * los títulos y el zoom se dibujan DENTRO de la página: el FFmpeg de este Mac
 * no trae libass ni drawtext, y así lo que se ve es exactamente lo que se grabó.
 *
 * Cada escena marca sus tareas (`e.tarea('registrar', 'Cómo registrar a alguien')`):
 * esas marcas son las que `armar.mjs` usa para cortar los clips del manual.
 *
 * Playwright no es dependencia del proyecto: se instala aparte y se indica con
 * PLAYWRIGHT_DIR (ver README.md de esta carpeta).
 *
 * Nada sale del Mac: el navegador aborta toda petición que no sea a localhost
 * (salvo las librerías y fuentes estáticas de CDN, que no llevan datos) y la
 * anota en linea.json → `salidas`. El servidor, además, corre en un sandbox.
 */
import { createRequire } from 'node:module'
import { mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { createHash } from 'node:crypto'
import { homedir } from 'node:os'

const require = createRequire(join(process.env.PLAYWRIGHT_DIR ?? process.cwd(), 'package.json'))
const { chromium } = require('playwright')

export const RAIZ = new URL('../..', import.meta.url).pathname
export const BASE = process.env.VIDEOS_BASE ?? 'http://localhost:8077'

/** Escritorio: 1280×720 CSS a 1,5 = 1920×1080. Móvil: 360×640 a 3 = 1080×1920. */
export const FORMATOS = {
  escritorio: { viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1.5, ancho: 1920, alto: 1080 },
  movil: { viewport: { width: 360, height: 640 }, deviceScaleFactor: 3, ancho: 1080, alto: 1920, isMobile: true, hasTouch: true },
}

function leerEnv(archivo) {
  const ruta = join(RAIZ, archivo)
  if (!existsSync(ruta)) return {}
  return Object.fromEntries(readFileSync(ruta, 'utf8').split('\n')
    .filter((l) => l.includes('=') && !l.startsWith('#'))
    .map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1).trim()]))
}

/** Cédula (o NIT) y clave de cada rol: `.env.videos` (lo escribe demo/sembrar.php). */
export function cuentaDe(rol) {
  const v = leerEnv('.env.videos')
  return { cedula: v[`VIDEOS_${rol}_CEDULA`], clave: v[`VIDEOS_${rol}_CLAVE`] }
}

/** Lo único que el navegador puede pedir afuera: librerías y fuentes estáticas. */
const PERMITIDOS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'unpkg.com']

/**
 * Lo que se dibuja encima de la aplicación. Va colgado de <html> y no de <body>
 * para que el zoom (que escala el body) no lo agrande también.
 */
const CAPA = String.raw`
(() => {
  if (window.__capa) return
  const montar = () => {
    if (document.getElementById('__capa-v')) return
    const s = document.createElement('style'); s.id = '__capa-estilo'
    s.textContent = ${'`'}
      #__capa-v{position:fixed;inset:0;pointer-events:none;z-index:2147483647;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
      #__cursor{position:fixed;left:0;top:0;width:22px;height:22px;transform:translate(-3px,-2px);transition:opacity .2s;opacity:0}
      .__onda{position:fixed;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;border:3px solid #f59e0b;background:rgba(245,158,11,.25);animation:__onda .6s ease-out forwards}
      @keyframes __onda{from{transform:scale(.3);opacity:1}to{transform:scale(1.4);opacity:0}}
      #__sub{position:fixed;left:0;right:0;margin:0 auto;width:fit-content;bottom:28px;max-width:min(86vw,980px);padding:10px 20px;border-radius:14px;background:rgba(15,23,42,.86);color:#fff;font-size:21px;line-height:1.35;font-weight:500;text-align:center;opacity:0;transition:opacity .25s;box-shadow:0 8px 30px rgba(0,0,0,.25)}
      #__sub.__ve{opacity:1}
      #__sub{z-index:3}
      #__sub.__arriba{bottom:auto;top:64px}
      #__marca{position:fixed;top:12px;right:14px;padding:4px 10px;border-radius:999px;background:rgba(15,23,42,.6);color:#fff;font-size:11px;letter-spacing:.04em;opacity:0;transition:opacity .3s}
      #__marca.__ve{opacity:1}
      #__titulo{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:32px;text-align:center;color:#fff;background:radial-gradient(120% 90% at 20% 10%,#2563eb 0%,#1e3a8a 45%,#0a1628 100%);opacity:0;transition:opacity .45s}
      #__titulo.__ve{opacity:1}
      #__titulo .__eti{font-size:14px;letter-spacing:.18em;text-transform:uppercase;opacity:.8}
      #__titulo .__t{font-size:52px;font-weight:700;line-height:1.1;max-width:900px}
      #__titulo .__s{font-size:22px;opacity:.9;max-width:760px;line-height:1.4}
      #__titulo .__logo{width:64px;height:64px;border-radius:18px;background:#fff;color:#1e3a8a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:30px;margin-bottom:6px}
      #__titulo .__items{display:flex;flex-wrap:wrap;justify-content:center;gap:12px 14px;max-width:1040px;margin-top:10px}
      #__titulo .__it{padding:12px 20px;border-radius:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.28);font-size:20px;font-weight:600;opacity:0;transform:translateY(14px);animation:__entra .5s ease-out forwards}
      #__titulo .__it b{color:#93c5fd;font-weight:700}
      @keyframes __entra{to{opacity:1;transform:none}}
      #__titulo .__cifra{font-size:84px;font-weight:800;line-height:1;color:#fff}
      #__foco{position:fixed;border:3px solid #f59e0b;border-radius:12px;box-shadow:0 0 0 9999px rgba(15,23,42,.35);opacity:0;transition:all .35s}
      #__foco.__ve{opacity:1}
      @media (max-width:500px){
        #__sub{font-size:15px;bottom:18px;max-width:92vw;padding:8px 14px;border-radius:12px}
        #__titulo .__t{font-size:30px}
        #__titulo .__s{font-size:15px}
        #__titulo .__logo{width:52px;height:52px;font-size:24px}
        #__titulo .__it{font-size:14px;padding:9px 13px}
        #__titulo .__cifra{font-size:52px}
      }
    ${'`'}
    const v = document.createElement('div'); v.id = '__capa-v'
    v.innerHTML = '<div id="__foco"></div><div id="__sub"></div><div id="__marca"></div>'
      + '<svg id="__cursor" viewBox="0 0 22 22"><path d="M3 2 L3 18 L7.5 13.8 L10.6 20.5 L13.4 19.2 L10.4 12.7 L16.5 12.7 Z" fill="#111" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>'
      + '<div id="__titulo"></div>'
    document.documentElement.append(s, v)
    const c = v.querySelector('#__cursor')
    addEventListener('mousemove', (e) => { window.__y = e.clientY; c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; if (!window.__tactil) c.style.opacity = 1 }, true)
    const onda = (x, y) => { const o = document.createElement('div'); o.className = '__onda'; o.style.left = x + 'px'; o.style.top = y + 'px'; v.append(o); setTimeout(() => o.remove(), 700) }
    addEventListener('pointerdown', (e) => { window.__y = e.clientY; onda(e.clientX, e.clientY) }, true)
    if (window.__estado) window.__pintar(window.__estado)
  }
  window.__pintar = (st) => {
    window.__estado = st
    const v = document.getElementById('__capa-v'); if (!v) return
    const sub = v.querySelector('#__sub'); if (st.sub) sub.textContent = st.sub; sub.classList.toggle('__ve', !!st.sub)
    // Abajo, salvo que el cursor o el dedo estén trabajando en la parte baja: ahí taparía lo que se muestra.
    if (st.sub) sub.classList.toggle('__arriba', st.arriba ?? ((window.__y ?? 0) > innerHeight * 0.6))
    const m = v.querySelector('#__marca'); m.textContent = st.marca ?? ''; m.classList.toggle('__ve', !!st.marca)
    const t = v.querySelector('#__titulo')
    if (st.titulo) t.innerHTML = '<div class="__logo">B</div>' + (st.titulo.eti ? '<div class="__eti"></div>' : '') + '<div class="__t"></div>' + (st.titulo.sub ? '<div class="__s"></div>' : '')
    if (st.titulo) { if (st.titulo.eti) t.querySelector('.__eti').textContent = st.titulo.eti; t.querySelector('.__t').textContent = st.titulo.t; if (st.titulo.sub) t.querySelector('.__s').textContent = st.titulo.sub }
    // Tarjeta con lista: cada elemento entra uno detrás de otro (para el comercial).
    if (st.titulo && st.titulo.cifra) { const c = document.createElement('div'); c.className = '__cifra'; c.textContent = st.titulo.cifra; t.querySelector('.__t').before(c) }
    if (st.titulo && st.titulo.items) {
      const caja = document.createElement('div'); caja.className = '__items'
      st.titulo.items.forEach((x, i) => { const d = document.createElement('div'); d.className = '__it'; d.style.animationDelay = (0.35 + i * (st.titulo.ritmo ?? 0.32)) + 's'; d.textContent = x; caja.append(d) })
      t.append(caja)
    }
    t.classList.toggle('__ve', !!st.titulo)
    const f = v.querySelector('#__foco')
    if (st.foco) Object.assign(f.style, { left: st.foco.x + 'px', top: st.foco.y + 'px', width: st.foco.w + 'px', height: st.foco.h + 'px' })
    f.classList.toggle('__ve', !!st.foco)
  }
  window.__capa = true
  if (document.readyState === 'loading') addEventListener('DOMContentLoaded', () => setTimeout(montar, 0))
  else montar()
  // Si algo quita lo que cuelga de <html> (un reemplazo de página), se vuelve a montar.
  setInterval(montar, 300)
})()
`

/**
 * La narración de cada frase, si ya se generó (`voz.mjs generar`): un WAV por
 * frase, nombrado por el hash del texto. Vive fuera del repositorio (pesa):
 * ~/Movies/Brynex-videos/voz-cache, o VIDEOS_VOZ.
 */
export const CACHE_VOZ = process.env.VIDEOS_VOZ ?? join(homedir(), 'Movies/Brynex-videos/voz-cache')
export const hashDe = (texto) => createHash('sha1').update(texto).digest('hex').slice(0, 16)
function duracionDeVoz(texto) {
  const j = join(CACHE_VOZ, hashDe(texto) + '.json')
  return existsSync(j) ? JSON.parse(readFileSync(j, 'utf8')).ms : null
}

/** Segundos que se deja leer un subtítulo: ~15 caracteres por segundo, mínimo 2,2 s. */
export const duracionDe = (texto) => Math.max(2200, Math.round((texto.length / 15) * 1000) + 600)

export async function grabar({ nombre, formato, rol, escena, salida }) {
  const f = FORMATOS[formato]
  const dir = join(salida, `${nombre}-${formato}`)
  rmSync(dir, { recursive: true, force: true })
  mkdirSync(join(dir, 'cuadros'), { recursive: true })

  const navegador = await chromium.launch({ executablePath: process.env.CHROME || undefined })
  const ctx = await navegador.newContext({
    viewport: f.viewport, deviceScaleFactor: f.deviceScaleFactor, isMobile: !!f.isMobile, hasTouch: !!f.hasTouch,
    locale: 'es-CO', timezoneId: 'America/Bogota', colorScheme: 'light',
    userAgent: f.isMobile
      ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36'
      : undefined,
  })
  await ctx.addInitScript(`window.__tactil = ${!!f.isMobile};` + CAPA)
  // Nada sale del Mac: lo que no es localhost (o una librería estática de CDN) se aborta y se anota.
  const salidas = new Set()
  await ctx.route('**/*', (r) => {
    const host = new URL(r.request().url()).hostname
    if (host === 'localhost' || host === '127.0.0.1' || PERMITIDOS.includes(host)) return r.continue()
    salidas.add(r.request().url().slice(0, 140))
    return r.abort()
  })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)

  // Entra fuera de cámara (salvo la escena de entrada, que muestra el login).
  // Sin sesión, la página arranca ya en la puerta: si no, el primer cuadro sale en blanco.
  if (rol && rol !== 'NADIE') await entrar(page, cuentaDe(rol))
  else await page.goto(BASE + '/login', { waitUntil: 'networkidle' }).catch(() => {})

  // ── La grabación ───────────────────────────────────────────────────────
  const cdp = await ctx.newCDPSession(page)
  // Un solo reloj para cuadros y marcas: la hora de llegada de cada cuadro.
  const cuadros = []
  let t0 = null
  cdp.on('Page.screencastFrame', async ({ data, sessionId }) => {
    cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {})
    const t = Date.now() / 1000
    if (t0 === null) t0 = t
    writeFileSync(join(dir, 'cuadros', `${String(cuadros.length).padStart(6, '0')}.jpg`), Buffer.from(data, 'base64'))
    cuadros.push(t - t0)
  })
  const ahora = () => (t0 === null ? 0 : Date.now() / 1000 - t0)
  await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: f.ancho, maxHeight: f.alto, everyNthFrame: 1 })
  for (let i = 0; i < 60 && t0 === null; i++) await page.waitForTimeout(50)

  const estado = { sub: '', marca: '', titulo: null, foco: null }
  const pintar = async () => {
    await page.evaluate((st) => window.__pintar?.(st), estado).catch(() => {})
    // Anota las pantallas que se salen de lado (en el celular, un error de la app).
    const ancho = await page.evaluate(() => document.documentElement.scrollWidth).catch(() => 0)
    if (ancho > f.viewport.width + 1) desbordes.add(`${new URL(page.url()).pathname} (${ancho}px)`)
  }
  const tareas = []
  const abiertas = []
  const dichos = []
  const clics = []
  const desbordes = new Set()
  // Si el Mac se suspende, las esperas se congelan y la toma queda con un hueco:
  // un latido por segundo; si el reloj salta más de 5 s, se anota y la toma no sirve.
  const congelado = []
  let latido = Date.now()
  const vigia = setInterval(() => { const ya = Date.now(); if (ya - latido > 5000) congelado.push({ t: +ahora().toFixed(1), s: Math.round((ya - latido) / 1000) }); latido = ya }, 1000)
  const tactil = !!f.isMobile
  // Hasta cuándo está hablando Avi (segundos del video). La acción sigue mientras habla.
  let hablaHasta = 0
  const silencio = async (margen = 0) => {
    const falta = (hablaHasta - ahora()) * 1000 + margen
    if (falta > 0) await page.waitForTimeout(falta)
  }

  const e = {
    page, formato, movil: tactil, base: BASE,
    async esperar(ms) { await page.waitForTimeout(ms) },
    /** La tarjeta (el contenedor redondeado más cercano) que contiene un texto. */
    caja(texto, { exacto = false } = {}) {
      return page.getByText(texto, { exact: exacto }).first()
        .locator('xpath=ancestor::*[contains(@class,"rounded-2xl") or contains(@class,"rounded-xl") or contains(@class,"rounded-3xl")][1]')
    },
    /**
     * Avi dice una frase (voz + subtítulo) y la escena SIGUE mientras habla:
     * así lo que se explica pasa en pantalla al mismo tiempo, como lo haría una
     * persona. La frase siguiente, un cambio de pantalla o un título esperan a
     * que termine. Dura lo que su audio (voz.mjs) o, sin audio, lo que toma
     * leerla; `ms` es un mínimo en pantalla. `espera: true` bloquea hasta el final.
     */
    async dice(texto, ms, { arriba, espera = false } = {}) {
      await silencio(120)
      for (const t of abiertas) (t.pasos ??= []).push(texto)
      estado.sub = texto; estado.arriba = arriba; await pintar()
      const voz = duracionDeVoz(texto)
      const dura = Math.max(voz ? voz + 250 : duracionDe(texto), ms ?? 0)
      dichos.push({ t: ahora(), texto, ms: dura })
      hablaHasta = ahora() + dura / 1000
      // Un respiro para que la acción no arranque encima de la primera palabra.
      await page.waitForTimeout(espera ? dura : Math.min(dura, 650))
    },
    /** Espera a que Avi termine la frase y quita el subtítulo. */
    async callar() { await silencio(); estado.sub = ''; await pintar() },
    /** Espera a que Avi termine, sin quitar el subtítulo. */
    async terminar(margen = 200) { await silencio(margen) },
    async marca(texto) { estado.marca = texto; await pintar() },
    /** Tarjeta de título a pantalla completa. */
    async titulo(t, sub, eti, ms = 3200) {
      await silencio(300)
      estado.titulo = { t, sub, eti }; estado.sub = ''; await pintar()
      await page.waitForTimeout(ms)
      estado.titulo = null; await pintar(); await page.waitForTimeout(500)
    },
    /** Tarjeta del comercial: etiqueta, título, subtítulo y una lista que entra animada. */
    async tarjeta({ t, sub, eti, items, cifra, ritmo, ms = 4200 }) {
      await silencio(200)
      estado.titulo = { t, sub, eti, items, cifra, ritmo }; estado.sub = ''; await pintar()
      await page.waitForTimeout(ms)
    },
    /** Una tarjeta mientras Avi habla encima (la frase no espera a que la tarjeta termine). */
    async tarjetaHablada(tarjeta, frase, ms) {
      await silencio(200)
      estado.titulo = { ...tarjeta }; await pintar()
      estado.sub = frase; estado.arriba = false; await pintar()
      const voz = duracionDeVoz(frase)
      const dura = Math.max(voz ? voz + 250 : duracionDe(frase), ms ?? 0)
      dichos.push({ t: ahora(), texto: frase, ms: dura })
      for (const x of abiertas) (x.pasos ??= []).push(frase)
      hablaHasta = ahora() + dura / 1000
      await page.waitForTimeout(dura + 400)
      estado.titulo = null; estado.sub = ''; await pintar(); await page.waitForTimeout(450)
    },
    async tituloFijo(t, sub, eti) { estado.titulo = { t, sub, eti }; estado.sub = ''; await pintar(); await page.waitForTimeout(700) },
    async quitarTitulo() { estado.titulo = null; await pintar(); await page.waitForTimeout(500) },
    tarea(id, titulo, extra = {}) { abiertas.push({ id, titulo, desde: ahora(), ...extra }) },
    finTarea() { const t = abiertas.pop(); if (t) tareas.push({ ...t, hasta: Math.max(ahora(), hablaHasta) }) },
    async ir(ruta, { esperar = 900 } = {}) {
      await silencio(150)
      estado.sub = ''
      await page.goto(BASE + ruta, { waitUntil: 'domcontentloaded' })
      await listo(page); await pintar(); await page.waitForTimeout(esperar)
    },
    async listo() { await listo(page); await pintar() },
    /** Lleva el cursor (o el dedo) hasta el elemento y hace clic. */
    async clic(objetivo, { espera = 500, forzar = false } = {}) {
      const l = loc(page, objetivo)
      await l.scrollIntoViewIfNeeded().catch(() => {})
      const b = await l.boundingBox()
      if (b && !tactil) {
        await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 18 })
        await page.waitForTimeout(180)
      }
      clics.push(ahora())
      if (tactil) await l.tap({ force: forzar }); else await l.click({ force: forzar })
      await page.waitForTimeout(150); await listo(page); await pintar(); await page.waitForTimeout(espera)
    },
    async mover(objetivo) {
      const b = await loc(page, objetivo).boundingBox()
      if (b && !tactil) await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 22 })
      await page.waitForTimeout(300)
    },
    /** Escribe como una persona, letra por letra. */
    async escribir(objetivo, texto, { retraso = 50 } = {}) {
      const l = loc(page, objetivo)
      await e.clic(l, { espera: 200 })
      await l.fill('')
      await page.keyboard.type(texto, { delay: retraso })
      await page.waitForTimeout(300)
    },
    async elegir(objetivo, valor) { await e.mover(objetivo); await loc(page, objetivo).selectOption(valor); await page.waitForTimeout(450) },
    /** Recuadro que resalta un elemento, con el resto en penumbra. */
    async resaltar(objetivo, ms = 1900, margen = 6) {
      const l = loc(page, objetivo)
      await l.scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {})
      // Si no está a la vista no se espera 20 s: la toma quedaría quieta.
      const b = await l.boundingBox({ timeout: 3000 }).catch(() => null)
      if (!b) return
      estado.foco = { x: b.x - margen, y: b.y - margen, w: b.width + 2 * margen, h: b.height + 2 * margen }
      await pintar(); await page.waitForTimeout(ms)
      estado.foco = null; await pintar(); await page.waitForTimeout(350)
    },
    /** Acerca la cámara a un elemento (escala el body con una transición). */
    async zoom(objetivo, factor = 1.6, ms = 3000) {
      const b = await loc(page, objetivo).boundingBox()
      if (!b) return
      const cx = b.x + b.width / 2, cy = b.y + b.height / 2
      await page.evaluate(({ cx, cy, factor }) => {
        const s = document.body.style
        s.transition = 'transform .7s cubic-bezier(.3,.1,.2,1)'
        s.transformOrigin = `${cx + scrollX}px ${cy + scrollY}px`
        s.transform = `scale(${factor})`
      }, { cx, cy, factor })
      await page.waitForTimeout(ms)
      await page.evaluate(() => { document.body.style.transform = 'scale(1)' })
      await page.waitForTimeout(800)
      await page.evaluate(() => { const s = document.body.style; s.transform = ''; s.transition = ''; s.transformOrigin = '' })
    },
    /** Desliza la página despacio. */
    async bajar(px = 400, pasos = 12) {
      for (let i = 0; i < pasos; i++) { await page.mouse.wheel(0, px / pasos); await page.waitForTimeout(40) }
      await page.waitForTimeout(400)
    },
    /**
     * Navega por el menú real de Brynex, como lo haría la persona, eligiendo el
     * ítem por el final de su `href` (`ruta`, p. ej. '/admin/clientes').
     *  - Escritorio: la barra de íconos (`nav.menu-iconos`). Lo de «Admin» vive
     *    en un desplegable que se abre al pasar el mouse (`admin: true`).
     *  - Celular: la hamburguesa (#btnHamburguesa) y el cajón #mobileDrawer.
     * Hay ítems que solo existen en uno de los dos (Cotizaciones y Comisiones
     * solo en el cajón; Parámetros solo en escritorio): si en escritorio no hay
     * ítem, se va por la tarjeta del inicio o por la dirección (`ir`).
     */
    async menu({ ruta, admin = false }) {
      await e.callar()
      const fin = `[href$="${ruta}"]`
      if (!tactil) {
        if (admin) {
          await e.mover('nav.menu-iconos .menu-dropdown:not(.brynex) .menu-dropdown-trigger')
          await page.hover('nav.menu-iconos .menu-dropdown:not(.brynex) .menu-dropdown-trigger')
          await page.waitForTimeout(450)
          await e.clic(`nav.menu-iconos .menu-dropdown:not(.brynex) a.panel-item${fin}`, { espera: 900 })
          return
        }
        const item = page.locator(`nav.menu-iconos > a.menu-item${fin}`).first()
        if (await item.count()) { await e.clic(item, { espera: 900 }); return }
        await e.ir(ruta)
        return
      }
      await e.clic('#btnHamburguesa', { espera: 550 })
      const item = page.locator(`#mobileDrawer a.drawer-item${fin}`).first()
      if (await item.count()) { await e.clic(item, { espera: 900 }); return }
      await page.keyboard.press('Escape'); await page.waitForTimeout(300)
      await e.ir(ruta)
    },
    async subir() { await page.evaluate(() => scrollTo({ top: 0, behavior: 'smooth' })); await page.waitForTimeout(600) },
  }

  try {
    await escena(e)
    await silencio(400)
  } finally {
    clearInterval(vigia)
    if (congelado.length) console.error(`  ⚠️  ${nombre}-${formato}: el Mac se durmió durante la toma (${congelado.map((c) => `${c.s} s en el ${c.t}`).join(', ')}). Repetirla.`)
    while (abiertas.length) e.finTarea()
    await page.waitForTimeout(400)
    const fin = ahora()
    await cdp.send('Page.stopScreencast').catch(() => {})
    await page.waitForTimeout(300)
    writeFileSync(join(dir, 'linea.json'), JSON.stringify({ nombre, formato, rol, fin, tareas, dichos, clics, desbordes: [...desbordes], salidas: [...salidas], congelado, cuadros, ancho: f.ancho, alto: f.alto }, null, 1))
    await navegador.close()
  }
  return dir
}

function loc(page, objetivo) {
  if (typeof objetivo === 'string') return page.locator(objetivo).first()
  if (objetivo && typeof objetivo.boundingBox === 'function') return objetivo
  if (objetivo?.rol) return page.getByRole(objetivo.rol, { name: objetivo.nombre, exact: !!objetivo.exacto }).first()
  if (objetivo?.texto) return page.getByText(objetivo.texto, { exact: !!objetivo.exacto }).first()
  throw new Error('Objetivo no reconocido: ' + JSON.stringify(objetivo))
}

async function listo(page) {
  await page.waitForLoadState('domcontentloaded').catch(() => {})
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {})
  // La capa de carga del panel (logo a los 500 ms) y los componentes que llegan después.
  await page.waitForTimeout(300)
}

/**
 * Entra con la cédula (o el NIT de la empresa, para el portal) fuera de cámara.
 * Si aparece el aviso de tratamiento de datos, se acepta (las cuentas de la demo
 * ya lo traen aceptado; esto es por si cambia la versión del aviso).
 */
export async function entrar(page, { cedula, clave }) {
  if (!cedula || !clave) throw new Error('Falta la cuenta en .env.videos: corre scripts/videos/base-videos.sh rehacer')
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' })
  await page.fill('input[name=cedula]', cedula)
  await page.fill('input[name=password]', clave)
  await page.click('button[type=submit]')
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 })
  if (page.url().includes('/tratamiento-datos')) {
    await page.check('input[name=acepto]')
    await page.click('button[type=submit]')
    await page.waitForURL((u) => !u.pathname.startsWith('/tratamiento-datos'), { timeout: 30000 })
  }
  await listo(page)
}
