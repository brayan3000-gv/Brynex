/**
 * Música original para el video con Lyria (Google), a partir de `musica.prompt` del proyecto.
 *
 *   node musica.mjs <slug>            → la genera si no existe
 *   node musica.mjs <slug> --otra     → genera otra versión (musica-v2, v3…) y la deja como la elegida
 *
 * `lyria-3-clip-preview` da ~30 s; para videos más largos se pide `lyria-3.5` con la
 * duración en el prompt y se recorta. La música elegida es musica/musica.mp3.
 */
import { copyFileSync, existsSync, readdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { bandera, duracion, gasto, post, proyecto } from './gemini.mjs'

const [slug] = process.argv.slice(2)
const pr = proyecto(slug)
const dir = join(pr.media, 'musica')
const elegida = join(dir, 'musica.mp3')
if (existsSync(elegida) && !bandera('otra')) { console.log(`Ya existe ${elegida} (${duracion(elegida).toFixed(1)} s). Usa --otra para pedir otra.`); process.exit(0) }

const modelo = pr.musica?.modelo ?? 'lyria-3.5'
const j = await post('interactions', { model: modelo, input: `${pr.musica.prompt} Instrumental only, no vocals.` })
const audio = (j.steps ?? []).flatMap((s) => s.content ?? []).find((c) => c.type === 'audio')
if (!audio?.data) { console.error('Lyria no devolvió audio:', JSON.stringify(j.error ?? j).slice(0, 500)); process.exit(1) }
const n = readdirSync(dir).filter((f) => /^musica-v\d+\.mp3$/.test(f)).length + 1
const f = join(dir, `musica-v${n}.mp3`)
writeFileSync(f, Buffer.from(audio.data, 'base64'))
copyFileSync(f, elegida)
gasto(pr, `música v${n} (${modelo})`, 0) // Lyria no tenía precio publicado al escribir esto
console.log(`✓ ${f} (${duracion(f).toFixed(1)} s) → elegida`)
