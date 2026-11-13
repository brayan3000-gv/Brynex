/**
 * La locución, una frase por escena, con la voz de Gemini (Claude no genera audio).
 *
 *   node voz.mjs <slug>                → genera las que falten (cada frase se guarda por su texto:
 *                                         cambiar una frase solo regenera esa)
 *   node voz.mjs <slug> --solo <id>    → rehace la de una escena aunque exista
 *
 * Se hace por escena y no de un tirón porque así: (1) cada escena dura lo que dura su frase,
 * (2) se puede dar un tono distinto a cada una (angustia → esperanza) y (3) un silencio raro
 * que mete el modelo no descuadra el resto (el 12-nov-2026 una locución entera traía 8 s de
 * silencio en la mitad).
 *
 * Deja voz/<id>.wav (48 kHz estéreo, sin silencio al principio ni al final) y voz/duraciones.json.
 */
import { createHash } from 'node:crypto'
import { copyFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'
import { arg, duracion, ff, gasto, PRECIOS, post, proyecto } from './gemini.mjs'

const [slug] = process.argv.slice(2)
const solo = arg('solo')
const pr = proyecto(slug)
const v = pr.voz ?? {}
// El 2.5 Pro sigue la indicación de tono sin leerla. El 3.8 (nov-2026) la lee en voz alta como
// parte del texto (una frase de 5 s salía de 25 s) y rechaza systemInstruction: no sirve para esto.
const MODELOS = [v.modelo, 'gemini-2.5-pro-preview-tts', 'gemini-2.5-flash-preview-tts'].filter(Boolean)
const cache = join(pr.media, 'voz', 'cache'); mkdirSync(cache, { recursive: true })
const duraciones = {}

async function decir(texto, estilo) {
  for (const modelo of MODELOS) {
    const j = await post(`models/${modelo}:generateContent`, {
      contents: [{ parts: [{ text: `Dilo como ${estilo}: ${texto}` }] }],
      generationConfig: { responseModalities: ['AUDIO'], speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: v.nombre ?? 'Aoede' } } } },
    })
    const p = j.candidates?.[0]?.content?.parts?.find((x) => x.inlineData)
    if (p) return { pcm: Buffer.from(p.inlineData.data, 'base64'), wav: /wav/.test(p.inlineData.mimeType), tasa: Number(/rate=(\d+)/.exec(p.inlineData.mimeType)?.[1] ?? 24000), modelo }
    console.log(`  ${modelo} no sirvió: ${JSON.stringify(j.error ?? j).slice(0, 160)}`)
  }
  throw new Error('Ningún modelo de voz respondió.')
}

for (const e of pr.escenas) {
  if (!e.voz) continue
  const estilo = [v.estilo, e.tono].filter(Boolean).join(', ')
  const h = createHash('sha1').update(`${v.nombre}|${estilo}|${e.voz}`).digest('hex').slice(0, 12)
  const enCache = join(cache, `${h}.wav`)
  if (!existsSync(enCache) || solo === e.id) {
    process.stdout.write(`· ${e.id}: «${e.voz.slice(0, 60)}…» `)
    const { pcm, wav, tasa, modelo } = await decir(e.voz, estilo)
    const crudo = join(cache, `${h}.${wav ? 'wav' : 'pcm'}`)
    writeFileSync(crudo, pcm)
    // Quita el silencio del principio y del final (el del final, dando vuelta el audio).
    const quita = 'silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.05'
    ff([...(wav ? [] : ['-f', 's16le', '-ar', String(tasa), '-ac', '1']), '-i', crudo, '-af', `${quita},areverse,${quita},areverse`, '-ar', '48000', '-ac', '2', enCache])
    gasto(pr, `voz ${e.id} (${modelo})`, PRECIOS.vozSeg * duracion(enCache))
    console.log(`${duracion(enCache).toFixed(2)} s (${modelo})`)
  }
  copyFileSync(enCache, join(pr.media, 'voz', `${e.id}.wav`))
  duraciones[e.id] = Number(duracion(enCache).toFixed(3))
  // Un silencio largo en medio de la frase suele ser un error del modelo: avisar.
  const det = spawnSync('ffmpeg', ['-hide_banner', '-i', enCache, '-af', 'silencedetect=n=-40dB:d=0.9', '-f', 'null', '-']).stderr.toString()
  if (/silence_start/.test(det)) console.log(`  ⚠ ${e.id} tiene un silencio de más de 0,9 s en medio: escúchala o rehazla con --solo ${e.id}`)
}
writeFileSync(join(pr.media, 'voz', 'duraciones.json'), JSON.stringify(duraciones, null, 1))
console.log('\nDuraciones (s):', duraciones, `\nTotal voz: ${Object.values(duraciones).reduce((a, b) => a + b, 0).toFixed(1)} s`)
