/**
 * Arma el video final: tomas + voz + música + subtítulos/tarjetas (capa.html) + cierre animado.
 *
 *   node armar.mjs <slug>                 → video completo
 *   node armar.mjs <slug> --rapido        → sin capa de textos (para revisar el corte y el audio)
 *
 * Línea de tiempo: cada escena dura lo que dura su voz (+ un respiro antes y después). La toma
 * se recorta a esa duración; si se queda corta, se ralentiza un poco (hasta ×1,35) o se
 * congela el último cuadro. La música se alinea para que su «giro» (`musica.giro`, segundo en
 * que pasa de triste a esperanzadora) caiga en la escena marcada con `"giro": true`, y baja
 * sola cuando habla la narradora (sidechain). Todo se normaliza a −14 LUFS (lo de Reels).
 *
 * Deja <media>/final-vN.mp4, una copia en storage/app/aliados-video/<slug>.mp4, la portada
 * <slug>.jpg y revision/final-vN.jpg (8 cuadros) para revisarlo antes de entregar.
 */
import { copyFileSync, existsSync, readdirSync, readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { execFileSync, spawnSync } from 'node:child_process'
import { AQUI, RAIZ, bandera, duracion, ff, proyecto, totalGastado } from './gemini.mjs'

const [slug] = process.argv.slice(2)
const pr = proyecto(slug)
const FPS = 30, W = 1080, H = 1920
const tempo = pr.voz?.tempo ?? 1
const durVoz = JSON.parse(readFileSync(join(pr.media, 'voz', 'duraciones.json'), 'utf8'))
const tmp = join(pr.media, 'capa', 'tmp'); rmSync(tmp, { recursive: true, force: true }); mkdirSync(tmp, { recursive: true })

// ── 1. Línea de tiempo ─────────────────────────────────────────────────────────────
let t = 0
const escenas = pr.escenas.map((e, i) => {
  const pre = e.pre ?? (i === 0 ? 0.5 : 0.25), post = e.post ?? 0.45
  const vd = (durVoz[e.id] ?? 0) / tempo
  let dur = pre + vd + post
  let cierreLen = 0
  if (e.cierre) { cierreLen = e.cierre.hasta - e.cierre.desde; dur = Math.max(dur, cierreLen) }
  const r = { ...e, inicio: t, dur, pre, vd, cierreLen }
  t += dur
  return r
})
const TOTAL = t
console.log(escenas.map((e) => `${e.id.padEnd(10)} ${e.inicio.toFixed(2).padStart(6)} → ${(e.inicio + e.dur).toFixed(2).padStart(6)}  (voz ${e.vd.toFixed(2)} s)`).join('\n'), `\nTotal: ${TOTAL.toFixed(2)} s`)

// ── 2. Video base por escena ───────────────────────────────────────────────────────
const tomaDe = (e) => {
  if (e.toma.elegida) return join(pr.media, 'tomas', `${e.toma.elegida}.mp4`)
  const v = readdirSync(join(pr.media, 'tomas')).filter((f) => new RegExp(`^${e.id}-v(\\d+)\\.mp4$`).test(f))
    .sort((a, b) => Number(/v(\d+)/.exec(b)[1]) - Number(/v(\d+)/.exec(a)[1]))
  if (!v.length) throw new Error(`Falta la toma de «${e.id}»: corre toma.mjs ${slug} ${e.id} --si`)
  return join(pr.media, 'tomas', v[0])
}
const escala = `scale=${W}:${H}:force_original_aspect_ratio=increase:flags=lanczos,crop=${W}:${H},fps=${FPS},format=yuv420p`
const segs = []
for (const e of escenas) {
  const out = join(tmp, `seg-${e.id}.mp4`), amb = join(tmp, `amb-${e.id}.wav`)
  if (e.cierre) {
    const frames = join(pr.media, 'capa', `cierre-${e.cierre.desde}-${e.cierre.hasta}`)
    if (!existsSync(frames) || readdirSync(frames).length < Math.round(e.cierreLen * FPS) - 1) {
      console.log(`· renderizando el cierre (${e.cierre.dir} ${e.cierre.desde}–${e.cierre.hasta} s)…`)
      execFileSync('node', ['render.mjs', '--frames', frames, '--from', String(e.cierre.desde), '--to', String(e.cierre.hasta)], { cwd: join(RAIZ, e.cierre.dir), stdio: 'inherit' })
    }
    const n0 = Math.round(e.cierre.desde * FPS)
    ff(['-framerate', String(FPS), '-start_number', String(n0), '-i', join(frames, 'f%05d.jpg'),
      '-vf', `${escala},tpad=stop_mode=clone:stop_duration=${Math.max(0, e.dur - e.cierreLen + 0.1).toFixed(2)}`, '-t', e.dur.toFixed(3),
      '-c:v', 'libx264', '-preset', 'medium', '-crf', '16', out])
    ff(['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-t', e.dur.toFixed(3), amb])
  } else {
    const src = tomaDe(e), desde = e.toma.desde ?? 0.1, hay = duracion(src) - desde
    let factor = 1, congela = 0
    if (hay < e.dur) { factor = Math.min(e.dur / hay, 1.35); congela = Math.max(0, e.dur - hay * factor) }
    const vf = [escala, factor > 1.001 ? `setpts=${factor.toFixed(4)}*PTS` : null, congela > 0 ? `tpad=stop_mode=clone:stop_duration=${(congela + 0.1).toFixed(2)}` : null].filter(Boolean).join(',')
    ff(['-ss', String(desde), '-i', src, '-vf', vf, '-t', e.dur.toFixed(3), '-an', '-c:v', 'libx264', '-preset', 'medium', '-crf', '16', out])
    const af = [factor > 1.001 ? `atempo=${(1 / factor).toFixed(4)}` : null, `apad`, `atrim=0:${e.dur.toFixed(3)}`, 'aresample=48000'].filter(Boolean).join(',')
    ff(['-ss', String(desde), '-i', src, '-af', af, '-ac', '2', amb])
    if (factor > 1.001 || congela > 0) console.log(`  ${e.id}: toma de ${hay.toFixed(1)} s para ${e.dur.toFixed(1)} s → ×${factor.toFixed(2)}${congela ? ` + ${congela.toFixed(1)} s congelada` : ''}`)
  }
  segs.push({ out, amb })
}
writeFileSync(join(tmp, 'lista.txt'), segs.map((s) => `file '${s.out}'`).join('\n'))
const base = join(tmp, 'base.mp4')
ff(['-f', 'concat', '-safe', '0', '-i', join(tmp, 'lista.txt'), '-vf', 'fade=t=in:st=0:d=0.4', '-c:v', 'libx264', '-preset', 'medium', '-crf', '16', base])

// ── 3. Audio: voces + ambiente de las tomas + música con ducking ───────────────────
const voces = escenas.filter((e) => durVoz[e.id])
const entradas = [], filtros = []
voces.forEach((e, i) => {
  entradas.push('-i', join(pr.media, 'voz', `${e.id}.wav`))
  const ms = Math.round((e.inicio + e.pre) * 1000)
  filtros.push(`[${i}:a]${tempo !== 1 ? `atempo=${tempo},` : ''}volume=${pr.voz?.volumen ?? 1.0},adelay=${ms}|${ms}[v${i}]`)
})
filtros.push(`${voces.map((_, i) => `[v${i}]`).join('')}amix=inputs=${voces.length}:normalize=0,apad,atrim=0:${TOTAL.toFixed(3)},asplit=2[voz][llave]`)
let k = voces.length
segs.forEach((s) => entradas.push('-i', s.amb))
filtros.push(`${segs.map((_, i) => `[${k + i}:a]`).join('')}concat=n=${segs.length}:v=0:a=1,volume=${pr.ambiente?.volumen ?? 0.3}[amb]`)
k += segs.length
const musica = join(pr.media, 'musica', 'musica.mp3')
const mezcla = []
if (existsSync(musica)) {
  const giro = escenas.find((e) => e.giro)
  // Si el giro de la música llega antes que el de la historia, la música entra tarde (retraso > 0):
  // el video abre solo con el ambiente de la toma y la voz, que también funciona.
  const desde = pr.musica?.desde ?? (pr.musica?.giro != null && giro ? pr.musica.giro - giro.inicio : 0)
  const retraso = Math.max(0, -desde), ms = Math.round(retraso * 1000)
  entradas.push('-ss', Math.max(0, desde).toFixed(3), '-i', musica)
  filtros.push(`[${k}:a]afade=t=in:st=0:d=${retraso ? 2.5 : 0.6},adelay=${ms}|${ms},atrim=0:${TOTAL.toFixed(3)},asetpts=PTS-STARTPTS,afade=t=out:st=${(TOTAL - 1.4).toFixed(2)}:d=1.4,volume=${pr.musica?.volumen ?? 0.3},aresample=48000[mus0]`)
  filtros.push(`[mus0][llave]sidechaincompress=threshold=0.03:ratio=4:attack=60:release=450[mus]`)
  mezcla.push('[mus]')
  console.log(retraso ? `· la música entra en el segundo ${retraso.toFixed(1)}` : `· música desde su segundo ${desde.toFixed(1)}`)
}
filtros.push(`[voz][amb]${mezcla.join('')}amix=inputs=${2 + mezcla.length}:normalize=0,loudnorm=I=-14:TP=-1.5:LRA=11,aresample=48000[a]`)
const audio = join(tmp, 'audio.wav')
ff([...entradas, '-filter_complex', filtros.join(';'), '-map', '[a]', '-ac', '2', audio])

// ── 4. Capa de textos (Playwright, fondo transparente) ─────────────────────────────
const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\p{L}\p{N}$%]/gu, '')
function trozos(texto, resaltar = []) {
  // «100 %» y «$125.000 al mes» no se parten: el espacio antes de % es duro.
  const ws = texto.replace(/ %/g, '\u00a0%').split(/[ \t\n]+/).filter(Boolean).map((w) => ({ w, hl: false }))
  for (const fr of resaltar) {
    const f = fr.replace(/ %/g, '\u00a0%').split(/[ \t\n]+/).map(norm)
    for (let i = 0; i + f.length <= ws.length; i++) if (f.every((x, j) => norm(ws[i + j].w) === x)) for (let j = 0; j < f.length; j++) ws[i + j].hl = true
  }
  const out = []; let cur = []
  // Trozos de 2 líneas como máximo, cortando en la puntuación. Nunca se corta después de una
  // palabra débil («el», «de», «y»…) ni se deja sola una palabra corta con punto («día.»).
  const debil = /^(el|la|los|las|un|una|de|del|al|a|en|y|o|con|por|para|que|tu|su|mi|no|le|se|te|lo|sin|hay|si)$/i
  for (let i = 0; i < ws.length; i++) {
    const w = ws[i], sig = ws[i + 1]
    cur.push(w)
    const largo = cur.map((x) => x.w).join(' ').length
    const fin = /[.,:;…?!]$/.test(w.w)
    const siguienteCierra = sig && /[.,:;…?!]$/.test(sig.w) && largo + sig.w.length < 26
    if (fin || ((cur.length >= 4 || largo >= 18) && !debil.test(w.w) && !siguienteCierra)) { out.push(cur); cur = [] }
  }
  if (cur.length) out.push(cur)
  // Una palabra corta sola («sí.») se pega al trozo anterior si cabe.
  const largoDe = (c) => c.map((x) => x.w).join(' ').length
  for (let i = out.length - 1; i > 0; i--) if (largoDe(out[i]) <= 9 && largoDe(out[i - 1]) + largoDe(out[i]) <= 32) { out[i - 1].push(...out[i]); out.splice(i, 1) }
  return out
}
const pausa = (c) => { const u = c[c.length - 1].w; return /…$/.test(u) ? 7 : /[.?!]$/.test(u) ? 5 : /[:;]$/.test(u) ? 4 : /,$/.test(u) ? 2 : 0 }
const linea = { subs: [], tarjetas: [], destellos: [], etiqueta: pr.etiqueta ? { t1: escenas.find((e) => e.cierre)?.inicio ?? TOTAL } : null }
for (const e of escenas) {
  if (e.tarjeta) linea.tarjetas.push({ ...e.tarjeta, t0: e.inicio + e.pre + (e.tarjeta.desde ?? 0) / tempo, t1: e.inicio + e.dur, y: e.tarjeta.y ?? 1110 })
  if (e.giro) linea.destellos.push(e.inicio)
  if (!durVoz[e.id] || e.cierre || e.sinSubtitulos) continue
  const cs = trozos(e.pantalla ?? e.voz, e.resaltar)
  const pesos = cs.map((c) => c.map((x) => x.w).join(' ').length + pausa(c))
  const sum = pesos.reduce((a, b) => a + b, 0)
  let t0 = e.inicio + e.pre
  cs.forEach((c, i) => {
    const d = e.vd * pesos[i] / sum
    linea.subs.push({ t0, t1: t0 + d, words: c, y: e.tarjeta ? (e.subY ?? 880) : (e.subY ?? 1250) })
    t0 += d
  })
}
writeFileSync(join(pr.media, 'capa', 'linea.json'), JSON.stringify(linea, null, 1))

const final = (() => { const n = readdirSync(pr.media).filter((f) => /^final-v\d+\.mp4$/.test(f)).length + 1; return join(pr.media, `final-v${n}.mp4`) })()
if (bandera('rapido')) {
  ff(['-i', base, '-i', audio, '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', final])
} else {
  const { chromium } = await import('playwright')
  const nav = await chromium.launch()
  const pag = await nav.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 })
  pag.on('pageerror', (e) => console.error('Error en capa.html:', e.message))
  await pag.goto('file://' + join(AQUI, 'capa.html'))
  await pag.evaluate((l) => window.montar(l), linea)
  const capa = join(tmp, 'capa'); mkdirSync(capa, { recursive: true })
  const N = Math.ceil(TOTAL * FPS)
  for (let n = 0; n < N; n++) {
    await pag.evaluate((x) => window.render(x), n / FPS)
    await pag.screenshot({ path: join(capa, `c${String(n).padStart(5, '0')}.png`), omitBackground: true })
    if (n % 150 === 0) console.log(`  capa ${n}/${N}`)
  }
  await nav.close()
  ff(['-i', base, '-framerate', String(FPS), '-i', join(capa, 'c%05d.png'), '-i', audio,
    '-filter_complex', '[0:v][1:v]overlay=0:0:format=auto,format=yuv420p[v]', '-map', '[v]', '-map', '2:a',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-t', TOTAL.toFixed(3), final])
}

// ── 5. Copia, portada y hoja de revisión ───────────────────────────────────────────
const destino = join(RAIZ, 'storage/app/aliados-video', `${slug}.mp4`)
copyFileSync(final, destino)
ff(['-ss', String(pr.portada ?? Math.min(2.5, TOTAL / 2)), '-i', final, '-frames:v', '1', '-q:v', '2', destino.replace(/\.mp4$/, '.jpg')])
const hoja = join(pr.media, 'revision', final.split('/').pop().replace('.mp4', '.jpg'))
const cs = [...Array(8)].map((_, i) => { const f = join(tmp, `r${i}.jpg`); ff(['-ss', (TOTAL * (i + 0.5) / 8).toFixed(2), '-i', final, '-frames:v', '1', '-vf', 'scale=270:-1', f]); return f })
ff([...cs.flatMap((f) => ['-i', f]), '-filter_complex', 'hstack=8', hoja])
const r128 = spawnSync('ffmpeg', ['-hide_banner', '-i', final, '-af', 'ebur128=peak=true', '-f', 'null', '-']).stderr.toString().split('Summary:').pop()
const lufs = `${/I:\s+(-?[\d.]+) LUFS/.exec(r128)?.[1]} LUFS, pico ${/Peak:\s+(-?[\d.]+) dBFS/.exec(r128)?.[1]} dBFS`
console.log(`\n✓ ${final} (${duracion(final).toFixed(1)} s, ${lufs})\n  copia: ${destino}\n  revisar: ${hoja}\n  gastado en este video: US$${totalGastado(pr).toFixed(2)}`)
