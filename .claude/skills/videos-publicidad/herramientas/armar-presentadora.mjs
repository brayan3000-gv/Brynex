/**
 * Arma un video del formato «presentadora a cámara» (ver presentadora.md): la edición de
 * creador de contenido que se aprendió de las referencias del 25-nov-2026.
 *
 *   node armar-presentadora.mjs <slug> [--rapido]     (--rapido: sin capa de textos)
 *
 * Toma los bloques hablados (escenas con `toma.habla`, generadas con toma.mjs) y:
 *  1. saca el tiempo de cada palabra con whisper local (gratis) y lo alinea con el guion;
 *  2. quita silencios, respiros, repeticiones («y tus hijos y tus hijos») y lo que sobre al final;
 *  3. corta cada ~1,5 s alternando plano normal y acercado, con un «rebote» de zoom en cada
 *     corte, y blanco y negro donde diga `bn`;
 *  4. mete los insertos (escenas con `inserto`) como tarjeta de esquinas redondeadas sobre la
 *     palabra que digan, sin cortar la voz;
 *  5. pone la capa (capa-presentadora.html): subtítulos palabra por palabra con la clave grande,
 *     gancho gigante, tarjetas, destellos, logo y etiqueta;
 *  6. música bajita y continua, golpe con el logo final y −12 LUFS.
 */
import { copyFileSync, existsSync, readdirSync, readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { execFileSync, spawnSync } from 'node:child_process'
import { AQUI, RAIZ, bandera, duracion, ff, proyecto, totalGastado } from './gemini.mjs'

const [slug] = process.argv.slice(2)
const pr = proyecto(slug)
const FPS = 30, W = 1080, H = 1920
const WHISPER = process.env.WHISPER_MODEL ?? join(process.env.HOME, '.cache/whisper-cpp/ggml-medium.bin')
const [CX, CY] = pr.centro ?? [0.5, 0.35]
const tmp = join(pr.media, 'capa', 'tmp-p'); rmSync(tmp, { recursive: true, force: true }); mkdirSync(tmp, { recursive: true })
const fr = (s) => Math.round(s * FPS) / FPS

const tomaDe = (e) => {
  if (e.toma.elegida) return join(pr.media, 'tomas', `${e.toma.elegida}.mp4`)
  const v = readdirSync(join(pr.media, 'tomas')).filter((f) => new RegExp(`^${e.id}-v(\\d+)\\.mp4$`).test(f))
    .sort((a, b) => Number(/v(\d+)/.exec(b)[1]) - Number(/v(\d+)/.exec(a)[1]))
  if (!v.length) throw new Error(`Falta la toma de «${e.id}»: corre toma.mjs ${slug} ${e.id} --si`)
  return join(pr.media, 'tomas', v[0])
}

// ── Utilidades de texto ─────────────────────────────────────────────────────────────
const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\p{L}\p{N}]/gu, '')
function lev(a, b) {
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)])
  for (let j = 1; j <= b.length; j++) d[0][j] = j
  for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1))
  return d[a.length][b.length]
}
const parecidas = (a, b) => a === b || (a.length >= 4 && b.length >= 4 && 1 - lev(a, b) / Math.max(a.length, b.length) >= 0.6) || (a.length >= 3 && (a.startsWith(b) || b.startsWith(a)) && Math.min(a.length, b.length) >= 3)
/** Busca la frase `fr` en las palabras `ws` (por su forma normalizada); devuelve el índice o -1. */
const buscar = (ws, frase, desde = 0) => {
  const f = frase.split(/\s+/).map(norm).filter(Boolean)
  for (let i = desde; i + f.length <= ws.length; i++) if (f.every((x, j) => norm(ws[i + j].w) === x)) return i
  return -1
}

// ── 1. Por bloque: palabras, regiones con voz y recortes ─────────────────────────────
function envolvente(f) {
  const out = spawnSync('ffmpeg', ['-hide_banner', '-i', f, '-af', 'aresample=16000,asetnsamples=160,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level', '-f', 'null', '-'], { maxBuffer: 1 << 26 }).stderr.toString()
  return [...out.matchAll(/RMS_level=(-?[\d.inf]+)/g)].map((m) => (Number.isFinite(Number(m[1])) ? Number(m[1]) : -120))
}
function regionesConVoz(f) {
  const env = envolvente(f), paso = 0.01
  const orden = [...env].sort((a, b) => a - b), p90 = orden[Math.floor(orden.length * 0.9)]
  const umbral = Math.max(p90 - 30, -55)
  let r = [], ini = null
  env.forEach((v, i) => { if (v > umbral && ini == null) ini = i; if (v <= umbral && ini != null) { r.push([ini * paso, i * paso]); ini = null } })
  if (ini != null) r.push([ini * paso, env.length * paso])
  // Huecos de menos de 0,16 s no son pausa; islas de menos de 0,05 s son ruido.
  const u = []; for (const x of r) { if (u.length && x[0] - u[u.length - 1][1] < 0.16) u[u.length - 1][1] = x[1]; else u.push([...x]) }
  return u.filter(([a, b]) => b - a >= 0.05)
}

const bloques = pr.escenas.filter((e) => e.toma?.habla)
const datos = []
for (const b of bloques) {
  const src = tomaDe(b), dur = duracion(src)
  const w16 = join(tmp, `${b.id}-16k.wav`), wn = join(tmp, `${b.id}-norm.wav`)
  ff(['-i', src, '-ac', '1', '-ar', '16000', w16])
  ff(['-i', src, '-af', 'loudnorm=I=-16:TP=-1.5:LRA=7,aresample=48000', '-ac', '2', wn])
  execFileSync('whisper-cli', ['-m', WHISPER, '-l', 'es', '-f', w16, '-ml', '1', '-sow', '-oj', '-of', join(tmp, `${b.id}-w`), '--dtw', 'medium'], { stdio: 'ignore' })
  let ww = JSON.parse(readFileSync(join(tmp, `${b.id}-w.json`), 'utf8')).transcription
    .map((x) => ({ w: x.text.trim(), t0: x.offsets.from / 1000, t1: x.offsets.to / 1000 })).filter((x) => norm(x.w))
  const cortes = []
  // Repeticiones seguidas («y tus hijos y tus hijos»): se va la primera copia.
  for (let n = 6; n >= 2; n--) for (let i = 0; i + 2 * n <= ww.length; i++) {
    if ([...Array(n)].every((_, j) => norm(ww[i + j].w) === norm(ww[i + n + j].w))) {
      cortes.push([ww[i].t0 - 0.02, ww[i + n].t0 - 0.03]); console.log(`  ${b.id}: repetición quitada «${ww.slice(i, i + n).map((x) => x.w).join(' ')}»`)
      ww.splice(i, n); i--
    }
  }
  // Alineación guion ↔ whisper (LCS con palabras parecidas).
  const sw = b.toma.habla.split(/\s+/).filter(Boolean).map((w) => ({ w }))
  const A = sw.map((x) => norm(x.w)), B = ww.map((x) => norm(x.w))
  const L = Array.from({ length: A.length + 1 }, () => Array(B.length + 1).fill(0))
  for (let i = A.length - 1; i >= 0; i--) for (let j = B.length - 1; j >= 0; j--) L[i][j] = parecidas(A[i], B[j]) ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1])
  const usada = new Set()
  for (let i = 0, j = 0; i < A.length && j < B.length;) {
    if (parecidas(A[i], B[j]) && L[i][j] === L[i + 1][j + 1] + 1) { sw[i].t0 = ww[j].t0; sw[i].t1 = ww[j].t1; usada.add(j); i++; j++ } else if (L[i + 1][j] >= L[i][j + 1]) i++; else j++
  }
  // Palabras del guion sin pareja: se reparten entre las vecinas.
  for (let i = 0; i < sw.length; i++) if (sw[i].t0 == null) {
    let k = i; while (k < sw.length && sw[k].t0 == null) k++
    const a = i ? sw[i - 1].t1 : (k < sw.length ? Math.max(0, sw[k].t0 - 0.3 * (k - i)) : 0)
    const z = k < sw.length ? sw[k].t0 : a + 0.3 * (k - i)
    for (let m = i; m < k; m++) { sw[m].t0 = a + (z - a) * (m - i) / (k - i); sw[m].t1 = a + (z - a) * (m - i + 1) / (k - i) }
    i = k - 1
  }
  // Tramos de 3+ palabras de whisper que no están en el guion (titubeos): se cortan.
  for (let j = 0; j < ww.length;) {
    if (usada.has(j)) { j++; continue }
    let k = j; while (k < ww.length && !usada.has(k)) k++
    if (k - j >= 3 && j > 0 && k < ww.length) { cortes.push([ww[j].t0 - 0.02, ww[k].t0 - 0.03]); console.log(`  ${b.id}: sobra «${ww.slice(j, k).map((x) => x.w).join(' ')}»`) }
    j = k
  }
  // Aquí solo se quitan silencios y titubeos; dónde empieza y termina cada pedazo lo dice el montaje.
  const lo = 0, hi = dur
  let reg = regionesConVoz(w16).map(([a, c]) => [Math.max(lo, a - 0.04), Math.min(hi, c + 0.07)]).filter(([a, c]) => c - a > 0.06)
  for (const [ca, cb] of cortes) reg = reg.flatMap(([a, c]) => (cb <= a || ca >= c) ? [[a, c]] : [[a, Math.max(a, ca)], [Math.min(c, cb), c]]).filter(([a, c]) => c - a > 0.06)
  const fus = []; for (const r of reg) { if (fus.length && r[0] <= fus[fus.length - 1][1] + 0.001) fus[fus.length - 1][1] = Math.max(fus[fus.length - 1][1], r[1]); else fus.push([...r]) }
  const regiones = fus.map(([a, c]) => ({ s: a, e: a + Math.max(1, Math.round((c - a) * FPS)) / FPS }))
  datos.push({ b, src, wn, sw, regiones })
  console.log(`· ${b.id}: ${dur.toFixed(1)} s → ${regiones.reduce((s, r) => s + r.e - r.s, 0).toFixed(1)} s en ${regiones.length} tramos (${ww.length} palabras oídas)`)
}

// ── 1b. Montaje: qué pedazo de qué toma va en qué orden (`pr.montaje`) ──────────────
// Cada pedazo es una toma entera o un tramo (`desde`/`hasta`, por frase del guion). Así se
// alternan ángulos distintos (frente, primer plano, perfil, abierto): el corte es un cambio
// de cámara de verdad, no un zoom sobre la misma toma.
const porId = Object.fromEntries(datos.map((d) => [d.b.id, d]))
/** Punto de corte entre dos palabras: el centro de un silencio si lo hay, si no el medio. */
const corteEntre = (d, a, z) => {
  for (let i = 0; i + 1 < d.regiones.length; i++) { const g0 = d.regiones[i].e, g1 = d.regiones[i + 1].s; if (g1 > a && g0 < z) return (Math.max(g0, a) + Math.min(g1, z)) / 2 }
  return (a + z) / 2
}
const piezas = (pr.montaje ?? datos.map((d) => ({ de: d.b.id }))).map((m, k) => {
  const d = porId[m.de]; if (!d) throw new Error(`El montaje pide «${m.de}» y no es una toma hablada`)
  const n = d.sw.length
  let i0 = 0, i1 = n - 1
  if (m.desde) { i0 = buscar(d.sw, m.desde); if (i0 < 0) throw new Error(`«${m.desde}» no está en ${m.de}`) }
  if (m.hasta) { const j = buscar(d.sw, m.hasta, i0); if (j < 0) throw new Error(`«${m.hasta}» no está en ${m.de}`); i1 = j + m.hasta.split(/\s+/).length - 1 }
  const lo = i0 > 0 ? corteEntre(d, d.sw[i0 - 1].t0 + 0.05, d.sw[i0].t1 - 0.05) : 0 // desde el principio de la toma: el silencio inicial lo quitan las regiones con voz
  const hi = i1 < n - 1 ? corteEntre(d, d.sw[i1].t0 + 0.05, d.sw[i1 + 1].t1 - 0.05) : d.sw[n - 1].t1 + 0.3
  const regiones = d.regiones.map((r) => [Math.max(lo, r.s), Math.min(hi, r.e)]).filter(([a, c]) => c - a > 0.06)
    .map(([a, c]) => ({ s: a, e: a + Math.max(1, Math.round((c - a) * FPS)) / FPS }))
  const sw = d.sw.slice(i0, i1 + 1).map((x) => ({ ...x }))
  const b = { ...d.b, ...m, id: `${m.de}#${k + 1}`, pantalla: { ...(d.b.pantalla ?? {}), ...(m.pantalla ?? {}) } }
  if (m.destello === true) b.destello = [sw[0].w]
  if (m.bn === true) b.bn = [sw[0].w, sw[sw.length - 1].w]
  return { b, src: d.src, wn: d.wn, sw, regiones }
})

// ── 2. Línea de tiempo de salida ────────────────────────────────────────────────────
let T = 0
for (const d of piezas) {
  for (const r of d.regiones) { r.os = T; T += r.e - r.s }
  d.fin = T
  d.mapa = (t) => { for (const r of d.regiones) { if (t < r.s) return r.os; if (t <= r.e) return r.os + t - r.s } return d.fin }
  d.sw.forEach((x) => { x.ot = fr(d.mapa(x.t0)); x.ot1 = d.mapa(x.t1) })
}
const TV = T
const cierre = pr.cierre ? { ...pr.cierre, len: pr.cierre.hasta - pr.cierre.desde } : null
const TOTAL = TV + (cierre?.len ?? 0)
const palabra = (d, frase) => { const i = buscar(d.sw, frase); if (i < 0) throw new Error(`«${frase}» no está en el guion de ${d.b.id}`); return { i, x: d.sw[i] } }
const dDe = (frase) => { for (const d of piezas) if (buscar(d.sw, frase) >= 0) return d; throw new Error(`«${frase}» no está en ningún bloque`) }

// ── 3. Planos: un plano por tramo, con un acercamiento lento y continuo en cada pedazo ──
// (sin saltos de zoom: a Brayan no le gustó que «la cámara se moviera brusco sin cambiar la
// posición de la persona», 25-nov-2026). Blanco y negro donde diga `bn`.
const destellos = []
const planos = []
for (const d of piezas) {
  for (const f of d.b.destello ?? []) destellos.push(fr(d.mapa(palabra(d, f).x.t0 - 0.02)))
  const ini = d.regiones[0]?.os ?? 0, largo = Math.max(0.5, d.fin - ini)
  let bnR = null
  if (d.b.bn) { const a = palabra(d, d.b.bn[0]).x, z = palabra(d, d.b.bn[1]).x; bnR = [a.t0 - 0.15, z.t1 + 0.25] }
  for (const r of d.regiones) {
    const n = Math.round((r.e - r.s) * FPS); if (n < 1) continue
    const medio = (r.s + r.e) / 2
    planos.push({ src: d.src, s: r.s, n, off: r.os - ini, largo, z0: d.b.zoom ?? 1, bn: bnR ? medio > bnR[0] && medio < bnR[1] : false })
  }
}
console.log(`· ${piezas.length} pedazos, ${planos.length} planos en ${TV.toFixed(1)} s`)

const segs = []
planos.forEach((p, i) => {
  const out = join(tmp, `p${String(i).padStart(3, '0')}.mp4`)
  const zz = `${p.z0}*(1+${pr.acercamiento ?? 0.06}*(${p.off.toFixed(3)}+on/${FPS})/${p.largo.toFixed(3)})`
  const vf = [`fps=${FPS}`, 'scale=1620:2880:force_original_aspect_ratio=increase:flags=lanczos,crop=1620:2880,setsar=1',
    `zoompan=z='${zz}':x='max(0\\,min(iw-iw/zoom\\,${CX}*iw-iw/zoom/2))':y='max(0\\,min(ih-ih/zoom\\,${CY}*ih-ih/zoom/2))':d=1:s=${W}x${H}:fps=${FPS}`,
    p.bn ? 'hue=s=0,eq=contrast=1.22:brightness=-0.02,vignette=PI/5' : null, 'format=yuv420p'].filter(Boolean).join(',')
  ff(['-ss', p.s.toFixed(4), '-i', p.src, '-vf', vf, '-frames:v', String(p.n), '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '15', out])
  segs.push(out)
})
if (cierre) {
  const frames = join(pr.media, 'capa', `cierre-${cierre.desde}-${cierre.hasta}`)
  if (!existsSync(frames) || readdirSync(frames).length < Math.round(cierre.len * FPS) - 1) {
    console.log(`· renderizando el cierre (${cierre.dir} ${cierre.desde}–${cierre.hasta} s)…`)
    execFileSync('node', ['render.mjs', '--frames', frames, '--from', String(cierre.desde), '--to', String(cierre.hasta)], { cwd: join(RAIZ, cierre.dir), stdio: 'inherit' })
  }
  const out = join(tmp, 'zcierre.mp4')
  ff(['-framerate', String(FPS), '-start_number', String(Math.round(cierre.desde * FPS)), '-i', join(frames, 'f%05d.jpg'),
    '-vf', `scale=${W}:${H}:force_original_aspect_ratio=increase,crop=${W}:${H},format=yuv420p`, '-frames:v', String(Math.round(cierre.len * FPS)), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '15', out])
  segs.push(out)
  destellos.push(TV)
}
writeFileSync(join(tmp, 'lista.txt'), segs.map((s) => `file '${s}'`).join('\n'))
const base0 = join(tmp, 'base0.mp4')
ff(['-f', 'concat', '-safe', '0', '-i', join(tmp, 'lista.txt'), '-c', 'copy', base0])

// ── 4. Insertos en tarjeta redondeada ───────────────────────────────────────────────
const IW = W - 48, IH = H - 48, R = 46
const mascara = join(tmp, 'mascara.png')
ff(['-f', 'lavfi', '-i', `color=black:s=${IW}x${IH}`, '-vf', `geq=lum='if(lte(pow(max(0\\,max(${R}-X\\,X-(W-1-${R})))\\,2)+pow(max(0\\,max(${R}-Y\\,Y-(H-1-${R})))\\,2)\\,${R * R})\\,255\\,0)',format=gray`, '-frames:v', '1', mascara])
const insertos = []
for (const e of pr.escenas.filter((x) => x.inserto)) {
  const d = dDe(e.inserto.en), t = palabra(d, e.inserto.en).x.ot
  // Un inserto no se monta sobre el siguiente: se acorta hasta donde empieza el otro.
  const sig = pr.escenas.filter((x) => x.inserto && x !== e).map((x) => palabra(dDe(x.inserto.en), x.inserto.en).x.ot).filter((x) => x > t)
  const dur = Math.min(e.inserto.dur ?? 2.2, TV - t - 0.1, ...sig.map((x) => x - t)), n = Math.round(dur * FPS)
  if (n < 10) { console.log(`  inserto ${e.id}: no cabe, se omite`); continue }
  const out = join(tmp, `ins-${e.id}.mp4`)
  ff(['-ss', String(e.toma.desde ?? 0.3), '-i', tomaDe(e), '-loop', '1', '-i', mascara, '-filter_complex',
    `[0:v]fps=${FPS},scale=${IW * 1.5}:${IH * 1.5}:force_original_aspect_ratio=increase,crop=${IW * 1.5}:${IH * 1.5},zoompan=z='1+0.06*on/${n}':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s=${IW}x${IH}:fps=${FPS},format=rgba[v];` +
    `[1:v]format=gray[m];[v][m]alphamerge[a];color=c=0x0b0b0b:s=${W}x${H}:r=${FPS}[bg];[bg][a]overlay=24:24,format=yuv420p[o]`,
    '-map', '[o]', '-frames:v', String(n), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '15', out])
  insertos.push({ out, t, dur })
  console.log(`· inserto ${e.id} en «${e.inserto.en}» (${t.toFixed(2)} s, ${dur.toFixed(1)} s)`)
}
let base = base0
if (insertos.length) {
  base = join(tmp, 'base.mp4')
  const f = []; let prev = '0:v'
  insertos.forEach((x, i) => {
    f.push(`[${i + 1}:v]setpts=PTS-STARTPTS+${x.t.toFixed(4)}/TB[i${i}]`)
    f.push(`[${prev}][i${i}]overlay=enable='between(t,${x.t.toFixed(4)},${(x.t + x.dur - 0.001).toFixed(4)})':eof_action=pass[o${i}]`); prev = `o${i}`
  })
  ff(['-i', base0, ...insertos.flatMap((x) => ['-i', x.out]), '-filter_complex', f.join(';'), '-map', `[${prev}]`, '-c:v', 'libx264', '-preset', 'medium', '-crf', '16', '-pix_fmt', 'yuv420p', base])
}

// ── 5. Audio: voz sin pausas + música bajita + golpe del logo ───────────────────────
const trozos = []
for (const d of piezas) for (const r of d.regiones) {
  const o = join(tmp, `a${String(trozos.length).padStart(3, '0')}.wav`), len = r.e - r.s
  ff(['-i', d.wn, '-af', `atrim=start=${r.s.toFixed(4)}:end=${r.e.toFixed(4)},asetpts=PTS-STARTPTS,afade=t=in:d=0.008,afade=t=out:st=${Math.max(0, len - 0.012).toFixed(4)}:d=0.012`, '-ar', '48000', '-ac', '2', o])
  trozos.push(o)
}
writeFileSync(join(tmp, 'alista.txt'), trozos.map((s) => `file '${s}'`).join('\n'))
const voz = join(tmp, 'voz.wav')
ff(['-f', 'concat', '-safe', '0', '-i', join(tmp, 'alista.txt'), '-af', `apad,atrim=0:${TOTAL.toFixed(3)}`, voz])
const entradas = ['-i', voz], filtros = ['[0:a]asplit=2[v][llave]'], mezcla = ['[v]']
const musica = join(pr.media, 'musica', 'musica.mp3')
if (existsSync(musica)) {
  entradas.push('-i', musica)
  const vol = pr.musica?.volumen ?? 0.16, volC = pr.musica?.volumenCierre ?? 0.55
  filtros.push(`[1:a]atrim=0:${TOTAL.toFixed(3)},asetpts=PTS-STARTPTS,aresample=48000,volume='if(gte(t,${TV.toFixed(3)}),${volC},${vol})':eval=frame,afade=t=out:st=${(TOTAL - 1.2).toFixed(2)}:d=1.2[m0]`)
  filtros.push('[m0][llave]sidechaincompress=threshold=0.05:ratio=3:attack=40:release=350[m]')
  mezcla.push('[m]')
}
if (cierre) {
  const ms = Math.round(TV * 1000)
  filtros.push(`aevalsrc='0.9*sin(2*PI*(42+110*exp(-t*22))*t)*exp(-t*5.5)|0.9*sin(2*PI*(42+110*exp(-t*22))*t)*exp(-t*5.5)':s=48000:d=1.2,adelay=${ms}|${ms}[g]`)
  mezcla.push('[g]')
}
filtros.push(`${mezcla.join('')}amix=inputs=${mezcla.length}:normalize=0,atrim=0:${TOTAL.toFixed(3)},loudnorm=I=-12:TP=-1.5:LRA=9,alimiter=limit=0.84:level=false,aresample=48000[a]`)
const audio = join(tmp, 'audio.wav')
ff([...entradas, '-filter_complex', filtros.join(';'), '-map', '[a]', '-ac', '2', audio])

// ── 6. Capa: subtítulos palabra por palabra, gancho, tarjetas, destellos ───────────
const limpia = (w) => w.replace(/[.,:;…]+$/u, '').replace(/^["«]|["»]$/g, '')
const linea = { grupos: [], tarjetas: [], destellos, cierre: cierre ? TV : null, logo: pr.logoArriba !== false, etiqueta: pr.etiqueta ? { texto: pr.etiqueta } : null }
for (const d of piezas) {
  // Fichas que se muestran (con los reemplazos de `pantalla`, p. ej. «ciento veinticinco mil pesos» → «$125.000»).
  let fichas = d.sw.map((x) => ({ w: x.w, t: x.ot }))
  for (const [frase, por] of Object.entries(d.b.pantalla ?? {})) {
    const i = buscar(fichas, frase); if (i >= 0) fichas.splice(i, frase.split(/\s+/).length, { w: por, t: fichas[i].t })
  }
  const enClave = new Map()
  for (const c of d.b.claves ?? []) { const i = buscar(fichas, c); if (i < 0) { console.log(`  ⚠ clave «${c}» no encontrada en ${d.b.id}`); continue } for (let j = 0; j < c.split(/\s+/).length; j++) enClave.set(i + j, c) }
  const grupos = []; let cur = null
  const cerrar = () => { if (cur && (cur.small.length || cur.big.length)) grupos.push(cur); cur = null }
  fichas.forEach((f, i) => {
    const w = { w: limpia(f.w), t: f.t }
    if (!w.w) return
    if (enClave.has(i)) {
      if (cur?.big.length && enClave.get(i) !== cur.clave) cerrar()
      cur ??= { small: [], big: [] }; cur.big.push(w); cur.clave = enClave.get(i)
      if (!enClave.has(i + 1) || enClave.get(i + 1) !== cur.clave) cerrar()
      return
    }
    if (cur?.big.length) cerrar()
    cur ??= { small: [], big: [] }; cur.small.push(w)
    const largo = cur.small.map((x) => x.w).join(' ').length
    const sigClave = enClave.has(i + 1)
    if (sigClave && largo <= 22) return
    if (/[.,:;…?!]$/.test(f.w) || cur.small.length >= 3 || largo >= 16) cerrar()
  })
  cerrar()
  grupos.forEach((g) => { g.t0 = (g.small[0] ?? g.big[0]).t })
  grupos.forEach((g, i) => { g.t1 = i + 1 < grupos.length ? grupos[i + 1].t0 : Math.min(d.fin - 0.04, (g.big.at(-1) ?? g.small.at(-1)).t + 0.9) })
  linea.grupos.push(...grupos)
  // La tarjeta puede seguir en los pedazos siguientes: `hasta` se busca desde este en adelante.
  for (const c of d.b.tarjetas ?? []) {
    const resto = piezas.slice(piezas.indexOf(d)), dz = resto.find((x) => buscar(x.sw, c.hasta) >= 0)
    if (!dz) throw new Error(`«${c.hasta}» (fin de la tarjeta) no está desde ${d.b.id}`)
    linea.tarjetas.push({ ...c, t0: palabra(d, c.en).x.ot, t1: Math.min(dz.fin - 0.05, palabra(dz, c.hasta).x.ot1 + 0.5) })
  }
}
if (pr.gancho) {
  const d = dDe(pr.gancho.hasta); linea.gancho = { ...pr.gancho, t1: palabra(d, pr.gancho.hasta).x.ot1 + 0.15 }
  // Mientras está el gancho no hay subtítulos: van en el mismo sitio, debajo de la cara.
  linea.grupos = linea.grupos.filter((g) => g.t0 >= linea.gancho.t1 - 0.05)
}
writeFileSync(join(pr.media, 'capa', 'linea-presentadora.json'), JSON.stringify(linea, null, 1))

const final = (() => { const n = readdirSync(pr.media).filter((f) => /^final-v\d+\.mp4$/.test(f)).length + 1; return join(pr.media, `final-v${n}.mp4`) })()
if (bandera('rapido')) {
  ff(['-i', base, '-i', audio, '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', final])
} else {
  const { chromium } = await import('playwright')
  const nav = await chromium.launch()
  const pag = await nav.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 })
  pag.on('pageerror', (e) => console.error('Error en capa-presentadora.html:', e.message))
  await pag.goto('file://' + join(AQUI, 'capa-presentadora.html'))
  await pag.evaluate((l) => window.montar(l), linea)
  const capa = join(tmp, 'capa'); mkdirSync(capa, { recursive: true })
  const N = Math.round(TOTAL * FPS)
  for (let n = 0; n < N; n++) {
    await pag.evaluate((x) => window.render(x), n / FPS)
    await pag.screenshot({ path: join(capa, `c${String(n).padStart(5, '0')}.png`), omitBackground: true })
    if (n % 300 === 0) console.log(`  capa ${n}/${N}`)
  }
  await nav.close()
  ff(['-i', base, '-framerate', String(FPS), '-i', join(capa, 'c%05d.png'), '-i', audio,
    '-filter_complex', '[0:v][1:v]overlay=0:0:format=auto,format=yuv420p[v]', '-map', '[v]', '-map', '2:a',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-t', TOTAL.toFixed(3), final])
}

// ── 7. Copia, portada y hoja de revisión ───────────────────────────────────────────
const destino = join(RAIZ, 'storage/app/aliados-video', `${slug}.mp4`)
copyFileSync(final, destino)
ff(['-ss', '0.5', '-i', final, '-frames:v', '1', '-q:v', '2', destino.replace(/\.mp4$/, '.jpg')])
const hoja = join(pr.media, 'revision', final.split('/').pop().replace('.mp4', '.jpg'))
ff(['-i', final, '-vf', `fps=${(16 / TOTAL).toFixed(4)},scale=270:-1,tile=8x2`, '-frames:v', '1', hoja])
const r128 = spawnSync('ffmpeg', ['-hide_banner', '-i', final, '-af', 'ebur128=peak=true', '-f', 'null', '-']).stderr.toString().split('Summary:').pop()
console.log(`\n✓ ${final} (${duracion(final).toFixed(1)} s, ${/I:\s+(-?[\d.]+) LUFS/.exec(r128)?.[1]} LUFS, pico ${/Peak:\s+(-?[\d.]+) dBFS/.exec(r128)?.[1]} dBFS)\n  copia: ${destino}\n  revisar: ${hoja}\n  gastado en este video: US$${totalGastado(pr).toFixed(2)}`)
