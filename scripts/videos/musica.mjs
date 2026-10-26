/**
 * La música de fondo de los videos, generada con Lyria (Gemini API, Interactions).
 *
 *   node scripts/videos/musica.mjs [clip|cancion] [pop|tropical|corporativa|lofi|ukelele] [nombre]
 *
 * `clip` (lyria-3-clip-preview) da 30 s, para probar el estilo; `cancion`
 * (lyria-3.5) da una pieza larga que armar.mjs repite bajo cada capítulo,
 * bajita y agachándose cuando Avi habla. Sale en ~/Movies/Brynex-videos/musica.
 * Instrumental siempre: una canción con letra le pelearía a la narración.
 * Lyria marca su audio como generado por IA (marca de agua inaudible).
 */
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { join } from 'node:path'
import { claveGemini } from './voz.mjs'

export const CARPETA_MUSICA = process.env.VIDEOS_MUSICA ?? join(homedir(), 'Movies/Brynex-videos/musica')

const COMUN = 'Música de fondo para un video tutorial de una aplicación, constante y sin sobresaltos, sin silencios '
  + 'ni partes lentas, que se pueda repetir en bucle. Instrumental only, no vocals.'
/** Los estilos que se le pueden pedir a Lyria. `pop` es el de la primera versión animada. */
export const ESTILOS = {
  pop: 'Pop acústico alegre y optimista: guitarra rítmica con rasgueo, ukelele, palmas, bajo con groove y batería ligera, energía positiva y moderna con un toque latino, unos 115 BPM.',
  tropical: 'Tropical latina colombiana moderna y alegre: percusión latina (congas, guacharaca, maracas), guitarra tropical, bajo bailable, toque de cumbia suave, unos 105 BPM.',
  corporativa: 'Electrónica corporativa moderna tipo video de startup tecnológica: sintetizadores brillantes y suaves, beat limpio, piano pulsante, optimista e inspiradora, unos 118 BPM.',
  lofi: 'Lo-fi cálido y relajado: beat suave de hip hop, piano eléctrico, guitarra limpia, ambiente amable y tranquilo, unos 85 BPM.',
  // La que eligió Brayan (24-oct-2026): lo acústico del pop con lo moderno de la corporativa.
  popcorporativa: 'Pop corporativo moderno y optimista: guitarra acústica rasgueada y ukelele por encima de sintetizadores brillantes y suaves, piano pulsante, beat limpio con palmas y bajo con groove, inspirador y amable, unos 112 BPM.',
  ukelele: 'Ukelele y silbido alegre, palmas y glockenspiel, tono amable y divertido como de comercial feliz, unos 110 BPM.',
}
const estiloDe = (clave) => `${ESTILOS[clave] ?? ESTILOS.pop} ${COMUN}`

const MODELOS = { clip: 'lyria-3-clip-preview', cancion: 'lyria-3.5' }

export async function componer(tipo = 'clip', nombre = tipo, estilo = 'pop') {
  const clave = claveGemini()
  if (!clave) throw new Error('Falta GEMINI_API_KEY en el .env')
  const entrada = tipo === 'cancion'
    ? `${estiloDe(estilo)} Duración de unos 3 minutos, con una sola atmósfera constante de principio a fin.`
    : estiloDe(estilo)
  // Lyria a veces responde 429/500 o se cae la red: se reintenta con espera creciente.
  let r
  for (let intento = 1; intento <= 5; intento++) {
    try {
      r = await fetch('https://generativelanguage.googleapis.com/v1beta/interactions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': clave },
        body: JSON.stringify({ model: MODELOS[tipo], input: entrada }),
      })
    } catch (err) {
      if (intento === 5) throw err
      await new Promise((ok) => setTimeout(ok, 6000 * intento)); continue
    }
    if (r.ok || (r.status !== 429 && r.status < 500)) break
    await new Promise((ok) => setTimeout(ok, 6000 * intento))
  }
  if (!r.ok) throw new Error(`Lyria respondió ${r.status}: ${(await r.text()).slice(0, 400)}`)
  const j = await r.json()
  const audio = (j.steps ?? []).flatMap((s) => s.content ?? []).find((c) => c.type === 'audio')
  if (!audio?.data) throw new Error('Lyria no devolvió audio: ' + JSON.stringify(j).slice(0, 400))
  mkdirSync(CARPETA_MUSICA, { recursive: true })
  const mp3 = join(CARPETA_MUSICA, `${nombre}.mp3`)
  writeFileSync(mp3, Buffer.from(audio.data, 'base64'))
  const seg = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp3]).toString())
  return { mp3, segundos: seg }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const [tipo = 'clip', estilo = 'pop', nombre = `${tipo}-${estilo}`] = process.argv.slice(2)
  const r = await componer(tipo, nombre, estilo)
  console.log(`Listo: ${r.mp3} (${r.segundos.toFixed(1)} s)`)
}
