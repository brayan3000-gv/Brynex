/**
 * Lo común de las herramientas: la llave de Gemini, las llamadas a Google, el
 * proyecto (proyecto.json) y la cuenta de lo gastado.
 *
 * La llave sale del entorno o de `.env.videos` (ignorado por git). NUNCA del .env
 * de Brynex ni de la base: esas llaves son de los aliados y el .env es el de producción.
 */
import { execFileSync } from 'node:child_process'
import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const AQUI = dirname(fileURLToPath(import.meta.url))
export const RAIZ = resolve(AQUI, '../../../..')
export const API = 'https://generativelanguage.googleapis.com/v1beta'

/** Precios en USD (aistudio.google.com/docs/pricing, 12-nov-2026). */
export const PRECIOS = {
  omniSeg: { '360p': 0.023, '720p': 0.07, '1080p': 0.104 },
  imagen: 0.067, // gemini-3.1-flash-image a 1K
  vozSeg: 0.000225, // gemini-3.8-flash-tts hasta 31-dic-2026 (se duplica en 2027)
}

export function llave() {
  for (const n of ['GEMINI_API_KEY', 'GOOGLE_API_KEY']) if (process.env[n]) return process.env[n]
  const env = join(RAIZ, '.env.videos')
  if (existsSync(env)) {
    const l = readFileSync(env, 'utf8').split('\n').find((x) => /^(export\s+)?GEMINI_API_KEY=/.test(x))
    if (l) return l.split('=').slice(1).join('=').trim().replace(/^["']|["']$/g, '')
  }
  throw new Error('Falta GEMINI_API_KEY (en el entorno o en .env.videos).')
}

/** POST a Google con reintentos ante 429/5xx y cortes de red. */
export async function post(ruta, cuerpo, { intentos = 4 } = {}) {
  let ultimo
  for (let i = 1; i <= intentos; i++) {
    try {
      const r = await fetch(`${API}/${ruta}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': llave() },
        body: JSON.stringify(cuerpo),
        signal: AbortSignal.timeout(600_000),
      })
      const texto = await r.text()
      let j; try { j = JSON.parse(texto) } catch { j = { error: { message: texto.slice(0, 400) } } }
      if (r.ok) return j
      ultimo = j
      // El filtro de seguridad no se arregla reintentando: hay que reescribir el prompt.
      if (j.error?.code === 'safety' || (r.status !== 429 && r.status < 500)) return j
    } catch (e) { ultimo = { error: { message: String(e.message ?? e) } } }
    await new Promise((ok) => setTimeout(ok, 8000 * i))
  }
  return ultimo
}

export async function get(url, { binario = false } = {}) {
  const r = await fetch(url.startsWith('http') ? url : `${API}/${url}`, { headers: { 'x-goog-api-key': llave() } })
  if (!r.ok) throw new Error(`GET ${url} → ${r.status}`)
  return binario ? Buffer.from(await r.arrayBuffer()) : r.json()
}

/**
 * Carga resources/publicidad/historias/<slug>/proyecto.json. Lo pesado (tomas, voz,
 * música, video final) va en storage/app/aliados-video/historias/<slug>/, que git ignora.
 */
export function proyecto(slug) {
  if (!slug) throw new Error('Falta el slug del proyecto (carpeta en resources/publicidad/historias/).')
  const dir = join(RAIZ, 'resources/publicidad/historias', slug)
  const p = JSON.parse(readFileSync(join(dir, 'proyecto.json'), 'utf8'))
  const media = join(RAIZ, 'storage/app/aliados-video/historias', slug)
  for (const d of ['refs', 'tomas', 'voz', 'musica', 'capa', 'revision']) mkdirSync(join(media, d), { recursive: true })
  return { ...p, slug, dir, media }
}

/** Anota cada gasto: así se sabe cuánto costó cada video (gastos.log en la carpeta de media). */
export function gasto(pr, concepto, usd) {
  appendFileSync(join(pr.media, 'gastos.log'), `${new Date().toISOString()}\t${usd.toFixed(3)}\t${concepto}\n`)
  return usd
}
export function totalGastado(pr) {
  const f = join(pr.media, 'gastos.log')
  if (!existsSync(f)) return 0
  return readFileSync(f, 'utf8').trim().split('\n').filter(Boolean).reduce((s, l) => s + Number(l.split('\t')[1]), 0)
}

export const ff = (args) => execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' })
export const duracion = (f) => Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).toString())
export const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
export const bandera = (k) => process.argv.includes('--' + k)
