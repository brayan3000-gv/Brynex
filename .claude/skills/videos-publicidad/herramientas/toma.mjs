/**
 * Una toma con Gemini Omni Flash (el reemplazo de Veo, apagado el 22-oct-2026).
 *
 *   node toma.mjs <slug> <id-escena>            → muestra el prompt y lo que costaría, sin gastar
 *   node toma.mjs <slug> <id-escena> --si       → la genera
 *        [--dur 8] [--res 720p]
 *
 * Deja tomas/<id>-v<N>.mp4 (cada corrida es una versión nueva, nunca pisa la anterior) y
 * revision/<id>-v<N>.jpg con 4 cuadros para mirarla antes de usarla. La versión que se usa
 * en el armado es `toma.elegida` en el proyecto.json (por defecto, la última).
 *
 * El personaje sale igual en todas las tomas porque cada una recibe las fotos de
 * `toma.refs` (hasta 6). Si el filtro de Google la bloquea, NO se cobra: reescribir
 * el prompt (ver produccion.md, «Qué bloquea Omni»).
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { arg, bandera, duracion, ff, gasto, get, PRECIOS, post, proyecto, totalGastado } from './gemini.mjs'

const [slug, id] = process.argv.slice(2)
const pr = proyecto(slug)
const esc = pr.escenas.find((e) => e.id === id)
if (!esc?.toma) throw new Error(`La escena «${id}» no existe o no lleva toma generada.`)
const dur = Number(arg('dur', esc.toma.dur ?? 8))
const res = arg('res', pr.resolucion ?? '720p')
const refs = esc.toma.refs ?? []

const prompt = [
  refs.length ? `Use the people from the reference image${refs.length > 1 ? 's' : ''} as the exact same characters (same faces, hair, body and clothes).` : '',
  esc.toma.prompt,
  pr.estiloVisual ?? '',
  `Sound design: ${esc.toma.sonido ?? 'soft natural ambience'}. No dialogue, no speech, no singing, no music. No text, no subtitles, no logos, no watermarks.`,
].filter(Boolean).join(' ')
const costo = PRECIOS.omniSeg[res] * dur

console.log(`Escena ${id} · ${dur}s · ${res} · refs: ${refs.join(', ') || '—'}\n\n${prompt}\n\nCosto: ~US$${costo.toFixed(2)} (gastado en este video: US$${totalGastado(pr).toFixed(2)})`)
if (!bandera('si')) { console.log('\n(No se generó: agrega --si para generarla.)'); process.exit(0) }

const input = refs.map((r) => {
  const f = join(pr.media, 'refs', `${r}.jpg`)
  if (!existsSync(f)) throw new Error(`Falta la referencia ${f}: corre referencia.mjs ${slug} ${r}`)
  return { type: 'image', data: readFileSync(f).toString('base64'), mime_type: 'image/jpeg' }
})
input.push({ type: 'text', text: prompt })

const t0 = Date.now()
const j = await post('interactions', {
  model: pr.modeloVideo ?? 'gemini-omni-1.2-flash',
  input,
  response_format: { type: 'video', aspect_ratio: pr.aspecto ?? '9:16', resolution: res, duration: `${dur}s`, delivery: 'uri' },
})
if (j.error) {
  console.error(`\n✗ ${j.error.code === 'safety' ? 'BLOQUEADA por el filtro de Google (no se cobra). Reescribe el prompt.' : 'Error'}: ${j.error.message}`)
  process.exit(1)
}
const video = (j.steps ?? []).flatMap((s) => s.content ?? []).find((c) => c.type === 'video')
if (!video) { console.error('Omni no devolvió video:', JSON.stringify(j).slice(0, 600)); process.exit(1) }

let mp4
if (video.data) mp4 = Buffer.from(video.data, 'base64')
else {
  const fileId = /files\/([^:/?]+)/.exec(video.uri)[1]
  for (let i = 0; i < 30; i++) {
    const f = await get(`files/${fileId}`)
    if (f.state === 'ACTIVE') break
    if (f.state === 'FAILED') throw new Error('El archivo quedó en FAILED')
    await new Promise((ok) => setTimeout(ok, 5000))
  }
  mp4 = await get(video.uri, { binario: true })
}

const versiones = readdirSync(join(pr.media, 'tomas')).filter((f) => f.startsWith(`${id}-v`)).length
const nombre = `${id}-v${versiones + 1}`
const salida = join(pr.media, 'tomas', `${nombre}.mp4`)
writeFileSync(salida, mp4)
gasto(pr, `toma ${nombre} ${dur}s ${res}`, costo)

// Hoja de revisión: 4 cuadros repartidos en la toma.
const d = duracion(salida), dir = join(pr.media, 'revision')
const cuadros = [0.08, 0.36, 0.64, 0.94].map((k, i) => {
  const f = join(dir, `_c${i}.jpg`)
  ff(['-ss', (d * k).toFixed(2), '-i', salida, '-frames:v', '1', '-vf', 'scale=300:-1', f]); return f
})
ff([...cuadros.flatMap((f) => ['-i', f]), '-filter_complex', 'hstack=4', join(dir, `${nombre}.jpg`)])
console.log(`\n✓ ${salida} (${d.toFixed(1)} s, ${((Date.now() - t0) / 1000).toFixed(0)} s en generarse)\nRevisar: ${join(dir, `${nombre}.jpg`)}\nGastado en este video: US$${totalGastado(pr).toFixed(2)}`)
