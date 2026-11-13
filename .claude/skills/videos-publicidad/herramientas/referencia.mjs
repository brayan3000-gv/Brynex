/**
 * Foto de referencia de un personaje (o de un lugar) para que salga igual en todas las tomas.
 *
 *   node referencia.mjs <slug> <id-referencia>
 *
 * Lee `referencias[]` del proyecto.json y deja refs/<id>.jpg. Si ya existe, no la rehace
 * (bórrala para pedir otra). Cuesta ~US$0,07.
 */
import { existsSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { ff, gasto, PRECIOS, post, proyecto } from './gemini.mjs'

const [slug, id] = process.argv.slice(2)
const pr = proyecto(slug)
const ref = (pr.referencias ?? []).find((r) => r.id === id)
if (!ref) throw new Error(`No hay referencia «${id}» en el proyecto. Hay: ${(pr.referencias ?? []).map((r) => r.id).join(', ')}`)
const salida = join(pr.media, 'refs', `${id}.jpg`)
if (existsSync(salida)) { console.log(`Ya existe ${salida} (bórrala para pedir otra).`); process.exit(0) }

const j = await post('models/gemini-3.1-flash-image:generateContent', {
  contents: [{ parts: [{ text: `${ref.prompt} Photorealistic reference photo, natural soft light, plain background, full body visible, real people, documentary style, no text.` }] }],
  generationConfig: { responseModalities: ['IMAGE'], imageConfig: { aspectRatio: ref.aspecto ?? '9:16' } },
})
const parte = j.candidates?.[0]?.content?.parts?.find((p) => p.inlineData)
if (!parte) { console.error('No devolvió imagen:', JSON.stringify(j.error ?? j.promptFeedback ?? j).slice(0, 500)); process.exit(1) }
writeFileSync(salida, Buffer.from(parte.inlineData.data, 'base64'))
gasto(pr, `referencia ${id}`, PRECIOS.imagen)
ff(['-i', salida, '-vf', 'scale=480:-1', join(pr.media, 'revision', `ref-${id}.jpg`)])
console.log(`Listo: ${salida}\nVista para revisar: ${join(pr.media, 'revision', `ref-${id}.jpg`)}`)
