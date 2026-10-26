/**
 * La narración de los videos, con la voz de Gemini (texto a voz).
 *
 *   GEMINI_API_KEY=… node scripts/videos/voz.mjs generar <grabaciones>
 *   node scripts/videos/voz.mjs mezclar <grabaciones>
 *
 * `generar` lee lo que dice cada escena (los subtítulos que `motor.mjs` anota
 * en linea.json → `dichos`) y le pide a Gemini un audio por frase. Quedan en
 * voz/cache/<hash>.wav con su duración: la próxima grabación hace durar cada
 * subtítulo lo que dura su audio, y así voz y pantalla no se separan.
 *
 * `mezclar` pone cada frase en el segundo exacto en que apareció su subtítulo
 * y deja <grabaciones>/../voz/<escena>-<formato>.wav, que armar.mjs le pega al
 * video. La clave va en el entorno o en .env.videos (GEMINI_API_KEY), nunca en el código.
 * La API de Claude no genera audio: por eso la voz es de Gemini.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { CACHE_VOZ, hashDe, RAIZ } from './motor.mjs'

// El primero que responda: el 3.1 es el que muestra la documentación de Firebase AI
// Logic; el 2.5 queda de respaldo si el 3.1 no existe para la clave (404).
const MODELOS = process.env.GEMINI_TTS_MODEL ? [process.env.GEMINI_TTS_MODEL] : ['gemini-3.1-flash-tts-preview', 'gemini-2.5-flash-preview-tts']
let MODELO = MODELOS[0]
const VOZ = process.env.GEMINI_TTS_VOZ ?? 'Aoede'
// Quien narra es Avi: mujer, colombiana, amable y fluida (Brayan, 23-oct-2026 en LiderApp; mismo nombre en Brynex, 24-oct-2026).
const ESTILO = process.env.GEMINI_TTS_ESTILO ?? 'Eres Avi, una asistente colombiana de Cali, amable y cercana. Dilo con acento colombiano natural, tono cálido y amigable, sonriendo, a ritmo conversacional y fluido, sin pausas largas, como quien le muestra algo a un compañero mientras lo hace:'

/**
 * La llave de Gemini: del entorno o de `.env.videos` (ignorado por git). NUNCA
 * del .env de Brynex ni de la base: las llaves de Gemini que Brynex guarda por
 * aliado son de los clientes, y el .env es el de producción.
 */
const NOMBRES_LLAVE = ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GENERATIVE_AI_API_KEY', 'GOOGLE_AI_API_KEY']
export function claveGemini() {
  for (const n of NOMBRES_LLAVE) if (process.env[n]) return process.env[n]
  const env = join(RAIZ, '.env.videos')
  if (!existsSync(env)) return null
  const lineas = readFileSync(env, 'utf8').split('\n').map((x) => x.replace(/^export\s+/, ''))
  for (const n of NOMBRES_LLAVE) {
    const l = lineas.find((x) => x.startsWith(n + '='))
    if (l) return l.slice(n.length + 1).trim().replace(/^["']|["']$/g, '')
  }
  return null
}

const ff = (args) => execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' })
const lineas = (dir) => readdirSync(dir).filter((d) => existsSync(join(dir, d, 'linea.json')))
  .map((d) => ({ d, l: JSON.parse(readFileSync(join(dir, d, 'linea.json'), 'utf8')) }))

async function decir(texto, clave, { estilo = ESTILO, modelo } = {}) {
  const usar = modelo ?? MODELO
  for (let intento = 1; intento <= 6; intento++) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${usar}:generateContent`
    // Un corte de red (ECONNRESET, DNS) no tumba la tanda: se espera y se reintenta.
    let r
    try {
      r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': clave },
        body: JSON.stringify({
          contents: [{ parts: [{ text: `${estilo} ${texto}` }] }],
          generationConfig: { responseModalities: ['AUDIO'], speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: VOZ } } } },
        }),
      })
    } catch (err) {
      if (intento === 6) throw err
      await new Promise((ok) => setTimeout(ok, 5000 * intento)); continue
    }
    if (r.ok) {
      const j = await r.json()
      const parte = j.candidates?.[0]?.content?.parts?.find((p) => p.inlineData)
      if (!parte) { const e = new Error('Gemini no devolvió audio: ' + JSON.stringify(j.promptFeedback ?? j).slice(0, 200)); e.bloqueo = true; throw e }
      return { pcm: Buffer.from(parte.inlineData.data, 'base64'), tipo: parte.inlineData.mimeType }
    }
    if (r.status === 429) {
      // La cuota del DÍA (100 voces por modelo en el plan gratis) no se recupera
      // reintentando: se avisa y la tanda sigue mañana con las que falten.
      const cuerpo = await r.text()
      if (/per ?day|PerDay|daily/i.test(cuerpo)) { const e = new Error('Se acabó la cuota del día de Gemini (' + usar + ').'); e.cuota = true; throw e }
      await new Promise((ok) => setTimeout(ok, 15000 * intento)); continue
    }
    if (r.status >= 500) { await new Promise((ok) => setTimeout(ok, 4000 * intento)); continue }
    if (r.status === 404 && !modelo && MODELOS.indexOf(MODELO) < MODELOS.length - 1) { MODELO = MODELOS[MODELOS.indexOf(MODELO) + 1]; return decir(texto, clave, { estilo }) }
    throw new Error(`Gemini respondió ${r.status}: ${(await r.text()).slice(0, 300)}`)
  }
  throw new Error('Gemini no respondió después de 6 intentos')
}

/** Todas las frases: las de las escenas (e.dice y tarjetas habladas) y las de las tomas ya grabadas. */
function frases(dir) {
  // Las frases salen de las escenas mismas (e.dice('…')) y de la instalación, así
  // la primera grabación ya dura lo que dura cada audio; más lo que haya en las tomas.
  const textos = new Set()
  const carpeta = join(RAIZ, 'scripts/videos/escenas')
  // VIDEOS_EXCLUIR=comercial,roles deja escenas por fuera (p. ej. mientras se aprueba su guion).
  const excluir = (process.env.VIDEOS_EXCLUIR ?? '').split(',').filter(Boolean).map((x) => x + '.mjs')
  // En el orden de los capítulos: si la cuota del día se acaba, quedan completos los primeros.
  const orden = ['comercial', 'entrada', 'clientes', 'contratos', 'afiliaciones', 'facturacion', 'cobros', 'visita', 'caja', 'planos',
    'incapacidades', 'tareas', 'informes', 'comunicacion', 'cotizador', 'administracion', 'portal-empresas', 'roles']
  const pos = (f) => { const i = orden.indexOf(f.replace(/\.mjs$/, '')); return i === -1 ? 99 : i }
  for (const f of readdirSync(carpeta).filter((x) => x.endsWith('.mjs') && !excluir.includes(x)).sort((a, b) => pos(a) - pos(b))) {
    const codigo = readFileSync(join(carpeta, f), 'utf8')
    for (const m of codigo.matchAll(/\.dice\(\s*'((?:[^'\\]|\\.)*)'/g)) textos.add(m[1].replace(/\\'/g, "'"))
    // La frase de una tarjeta hablada es el segundo argumento: va sola en su línea.
    for (const m of codigo.matchAll(/^\s*'((?:[^'\\]|\\.)*)'\)\s*$/gm)) textos.add(m[1].replace(/\\'/g, "'"))
  }
  if (dir && existsSync(dir)) for (const { l } of lineas(dir)) for (const x of l.dichos ?? []) textos.add(x.texto)
  return textos
}

async function generar(dir) {
  const clave = claveGemini()
  if (!clave) { console.error('Falta GEMINI_API_KEY (en el entorno o en .env.videos).'); process.exit(1) }
  mkdirSync(CACHE_VOZ, { recursive: true })
  const textos = frases(dir)
  let nuevos = 0
  const bloqueadas = []
  for (const texto of textos) {
    const h = hashDe(texto)
    const wav = join(CACHE_VOZ, h + '.wav')
    if (existsSync(wav)) continue
    // Gemini a veces bloquea frases (PROHIBITED_CONTENT) sin razón aparente:
    // se reintenta con una indicación neutra y con el otro modelo; si nada, queda solo el subtítulo.
    let audio = null
    // Se reintenta con indicaciones neutras y el MISMO modelo: con el de respaldo la voz sonaría distinta.
    for (const intento of [{}, { estilo: 'Lee en voz alta, en español de Colombia, con tono amable:' }, { estilo: 'Lee en voz alta, con tono amable:' }]) {
      try { audio = await decir(texto, clave, intento); break } catch (e) {
        if (e.cuota) { console.log(`  ${e.message} Faltan ${[...textos].filter((t) => !existsSync(join(CACHE_VOZ, hashDe(t) + '.wav'))).length} frases: vuelve a correr «generar» mañana.`); return }
        if (!e.bloqueo) throw e
      }
    }
    if (!audio) { bloqueadas.push(texto); console.log(`  BLOQUEADA · ${texto.slice(0, 70)}`); continue }
    const { pcm, tipo } = audio
    const tasa = Number(/rate=(\d+)/.exec(tipo ?? '')?.[1] ?? 24000)
    const crudo = join(CACHE_VOZ, h + '.pcm')
    writeFileSync(crudo, pcm)
    // PCM 16 bits mono → WAV 48 kHz estéreo, sin silencios de más al principio y al final.
    ff(['-f', 's16le', '-ar', String(tasa), '-ac', '1', '-i', crudo,
      '-af', 'silenceremove=start_periods=1:start_threshold=-50dB,areverse,silenceremove=start_periods=1:start_threshold=-50dB,areverse,loudnorm=I=-16:TP=-1.5',
      '-ar', '48000', '-ac', '2', wav])
    execFileSync('rm', [crudo])
    const ms = Math.round(Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', wav]).toString()) * 1000)
    writeFileSync(join(CACHE_VOZ, h + '.json'), JSON.stringify({ texto, ms, modelo: MODELO, voz: VOZ }))
    nuevos++
    console.log(`  ${(ms / 1000).toFixed(1)} s · ${texto.slice(0, 70)}`)
  }
  console.log(`Listo: ${textos.size} frases (${nuevos} nuevas, ${bloqueadas.length} bloqueadas por Gemini). Vuelve a grabar para que los subtítulos duren lo que su voz.`)
}

/** Un «tic» corto y suave para cada clic: se genera una vez, sin archivos de afuera. */
function tic() {
  const wav = join(CACHE_VOZ, 'tic.wav')
  if (!existsSync(wav)) {
    mkdirSync(CACHE_VOZ, { recursive: true })
    ff(['-f', 'lavfi', '-i', 'sine=frequency=1700:duration=0.045', '-af', 'afade=t=out:st=0.005:d=0.04,volume=0.18', '-ar', '48000', '-ac', '2', wav])
  }
  return wav
}

function mezclar(dir) {
  const salida = join(dir, '..', 'voz')
  mkdirSync(salida, { recursive: true })
  for (const { d, l } of lineas(dir)) {
    // La voz de cada frase, en el segundo en que apareció su subtítulo, y un tic en cada clic.
    const sonidos = [
      ...(l.dichos ?? []).filter((x) => existsSync(join(CACHE_VOZ, hashDe(x.texto) + '.wav')))
        .map((x) => ({ t: x.t, archivo: join(CACHE_VOZ, hashDe(x.texto) + '.wav') })),
      ...(l.clics ?? []).map((t) => ({ t, archivo: tic() })),
    ]
    if (sonidos.length === 0) { console.log(`  ${d}: sin sonido`); continue }
    const entradas = sonidos.flatMap((x) => ['-i', x.archivo])
    const filtro = sonidos.map((x, i) => `[${i}:a]adelay=${Math.round(x.t * 1000)}|${Math.round(x.t * 1000)}[a${i}]`).join(';')
      + ';' + sonidos.map((_, i) => `[a${i}]`).join('') + `amix=inputs=${sonidos.length}:normalize=0,apad=whole_dur=${l.fin.toFixed(2)}[out]`
    ff([...entradas, '-filter_complex', filtro, '-map', '[out]', '-ar', '48000', '-ac', '2', join(salida, `${d}.wav`)])
    console.log(`  ${d}: ${(l.dichos ?? []).length} frases, ${(l.clics ?? []).length} clics`)
  }
}

/** Unas frases de prueba en un solo WAV, para aprobar la voz antes de generar la tanda. */
const MUESTRA = [
  '¡Hola! Soy Avi, y te voy a mostrar cómo funciona Brynex.',
  'Escribes la cédula y Brynex trae del registro oficial el nombre, la EPS y el fondo de pensión, sin digitar nada.',
  'Con un clic, la afiliación a la ARL queda hecha por API, sin entrar al portal de la aseguradora.',
]
async function muestra() {
  const clave = claveGemini()
  if (!clave) { console.error('Falta GEMINI_API_KEY en .env.videos.'); process.exit(1) }
  const dir = join(CACHE_VOZ, '..', 'muestras-voz')
  mkdirSync(dir, { recursive: true })
  const partes = []
  for (const [i, texto] of MUESTRA.entries()) {
    const { pcm, tipo } = await decir(texto, clave)
    const tasa = Number(/rate=(\d+)/.exec(tipo ?? '')?.[1] ?? 24000)
    const crudo = join(dir, `${i}.pcm`)
    writeFileSync(crudo, pcm)
    const wav = join(dir, `${i}.wav`)
    ff(['-f', 's16le', '-ar', String(tasa), '-ac', '1', '-i', crudo, '-af', 'loudnorm=I=-16:TP=-1.5,apad=pad_dur=0.6', '-ar', '48000', '-ac', '2', wav])
    execFileSync('rm', [crudo])
    partes.push(wav)
  }
  const salida = join(dir, `muestra-avi-${MODELO}.m4a`)
  ff([...partes.flatMap((p) => ['-i', p]), '-filter_complex', partes.map((_, i) => `[${i}:a]`).join('') + `concat=n=${partes.length}:v=0:a=1[a]`,
    '-map', '[a]', '-c:a', 'aac', '-b:a', '160k', salida])
  console.log(`Muestra (${MODELO}, voz ${VOZ}): ${salida}`)
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const [accion, dir] = process.argv.slice(2)
  if (accion === 'muestra') await muestra()
  else if (accion === 'listar') {
    const t = [...frases(dir)]
    const faltan = t.filter((x) => !existsSync(join(CACHE_VOZ, hashDe(x) + '.wav')))
    faltan.forEach((x) => console.log('  falta · ' + x))
    console.log(`${t.length} frases, ${t.length - faltan.length} con voz, ${faltan.length} por generar.`)
  }
  else if (accion === 'generar') await generar(dir)
  else if (accion === 'mezclar' && dir) mezclar(dir)
  else { console.error('Uso: voz.mjs generar|mezclar <grabaciones>'); process.exit(1) }
}
