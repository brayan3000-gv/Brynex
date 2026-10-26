/**
 * Valida todo lo que armó armar.mjs, antes de entregar.
 *
 *   node scripts/videos/validar.mjs <carpeta de salida> [<carpeta de grabaciones>]
 *
 * Por cada MP4 (capítulos, completos, comercial y clips):
 *   - se decodifica entero (ffmpeg -f null): si un cuadro está roto, falla;
 *   - H.264, yuv420p, rango tv, 30 fps, AAC, la resolución que le toca;
 *   - tiene sonido de verdad (volumedetect y la sonoridad de ebur128).
 * Por cada capítulo, una hoja de cuadros (salida/hojas/) para revisarla a ojo:
 * nada en blanco ni cargando, ningún dato real, ninguna credencial.
 * Con la carpeta de grabaciones, además repasa los linea.json: pantallas que se
 * salen de lado en el celular, peticiones que intentaron salir del Mac y tomas
 * en las que el Mac se durmió.
 */
import { execFileSync, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, basename } from 'node:path'

const [salida, grabaciones] = process.argv.slice(2)
if (!salida) { console.error('Uso: validar.mjs <salida> [<grabaciones>]'); process.exit(1) }

const probe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_streams', '-show_format', '-of', 'json', f]).toString())
const ffLog = (args) => spawnSync('ffmpeg', ['-hide_banner', '-nostats', ...args], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

function mp4s(dir) {
  if (!existsSync(dir)) return []
  return readdirSync(dir, { withFileTypes: true }).flatMap((d) => d.isDirectory()
    ? (d.name === 'hojas' ? [] : mp4s(join(dir, d.name)))
    : d.name.endsWith('.mp4') ? [join(dir, d.name)] : [])
}

const filas = []
let malos = 0
for (const f of mp4s(salida)) {
  const p = probe(f)
  const v = p.streams.find((s) => s.codec_type === 'video')
  const a = p.streams.find((s) => s.codec_type === 'audio')
  const problemas = []
  const liviano = f.includes('/web/')
  const vertical = v && v.height > v.width
  const esperado = liviano ? (vertical ? [720, 1280] : [1280, 720]) : (vertical ? [1080, 1920] : [1920, 1080])
  if (!v) problemas.push('sin video')
  else {
    if (v.codec_name !== 'h264') problemas.push(`códec ${v.codec_name}`)
    if (v.pix_fmt !== 'yuv420p') problemas.push(`pix_fmt ${v.pix_fmt}`)
    if (v.width !== esperado[0] || v.height !== esperado[1]) problemas.push(`${v.width}×${v.height} (esperaba ${esperado.join('×')})`)
    const [n, dd] = (v.avg_frame_rate || '0/1').split('/').map(Number)
    if (Math.abs(n / dd - 30) > 0.05) problemas.push(`${(n / dd).toFixed(2)} fps`)
    if (v.color_range && v.color_range !== 'tv') problemas.push(`rango ${v.color_range}`)
  }
  if (!a) problemas.push('sin audio')
  else if (a.codec_name !== 'aac') problemas.push(`audio ${a.codec_name}`)
  // Decodificar todo: un MP4 cortado o con cuadros rotos falla aquí.
  const dec = ffLog(['-v', 'error', '-i', f, '-f', 'null', '-'])
  if (dec.status !== 0 || dec.stderr.trim()) problemas.push('no decodifica limpio: ' + dec.stderr.trim().split('\n')[0]?.slice(0, 120))
  let media = null, sonoridad = null
  if (a) {
    const vd = ffLog(['-i', f, '-vn', '-af', 'volumedetect', '-f', 'null', '-']).stderr
    media = Number(/mean_volume: (-?[\d.]+) dB/.exec(vd)?.[1] ?? -99)
    const eb = ffLog(['-i', f, '-vn', '-af', 'ebur128', '-f', 'null', '-']).stderr
    sonoridad = Number([...eb.matchAll(/I:\s+(-?[\d.]+) LUFS/g)].at(-1)?.[1] ?? -99)
    if (media < -45) problemas.push(`casi mudo (${media} dB)`)
  }
  const fila = { archivo: f.replace(salida + '/', ''), seg: +Number(p.format.duration).toFixed(1), res: v ? `${v.width}×${v.height}` : '—',
    mb: +(Number(p.format.size) / 1048576).toFixed(1), media_db: media, lufs: sonoridad, problemas }
  if (problemas.length) malos++
  filas.push(fila)
  console.log(`${problemas.length ? '✗' : '✓'} ${fila.archivo}  ${fila.res}  ${fila.seg}s  ${fila.mb}MB  ${media ?? '—'}dB  ${sonoridad ?? '—'}LUFS  ${problemas.join('; ')}`)
}

// Hojas de cuadros: 24 cuadros parejos de cada capítulo y del comercial.
const hojas = join(salida, 'hojas')
mkdirSync(hojas, { recursive: true })
for (const f of mp4s(join(salida, 'capitulos'))) {
  const v = probe(f).streams.find((s) => s.codec_type === 'video')
  const d = Number(probe(f).format.duration)
  const vertical = v.height > v.width
  const [w, h, cols, k] = vertical ? [180, 320, 6, 18] : [320, 180, 6, 24]
  const entradas = []
  for (let i = 0; i < k; i++) entradas.push('-ss', (d * (i + 0.5) / k).toFixed(2), '-i', f)
  const flt = Array.from({ length: k }, (_, i) => `[${i}:v]scale=${w}:${h},setsar=1[v${i}];`).join('')
    + Array.from({ length: k }, (_, i) => `[v${i}]`).join('') + `xstack=inputs=${k}:layout=` + Array.from({ length: k }, (_, i) => `${(i % cols) * w}_${Math.floor(i / cols) * h}`).join('|')
  ffLog(['-y', ...entradas, '-filter_complex', flt, '-frames:v', '1', join(hojas, basename(f, '.mp4') + '.jpg')])
}

// Lo que anotó el motor en cada toma.
const avisos = []
if (grabaciones && existsSync(grabaciones)) {
  for (const d of readdirSync(grabaciones)) {
    const j = join(grabaciones, d, 'linea.json')
    if (!existsSync(j)) continue
    const l = JSON.parse(readFileSync(j, 'utf8'))
    if (l.desbordes?.length) avisos.push(`${d}: se sale de lado en ${l.desbordes.join(', ')}`)
    if (l.salidas?.length) avisos.push(`${d}: ${l.salidas.length} peticiones bloqueadas hacia afuera (${l.salidas.slice(0, 3).join(', ')})`)
    if (l.congelado?.length) avisos.push(`${d}: el Mac se durmió durante la toma → repetirla`)
  }
}
avisos.forEach((x) => console.log('⚠️  ' + x))
writeFileSync(join(salida, 'validacion.json'), JSON.stringify({ fecha: new Date().toISOString(), archivos: filas, avisos }, null, 1))
console.log(`\n${filas.length} MP4, ${malos} con problemas. Hojas de cuadros en ${hojas}`)
process.exit(malos ? 1 : 0)
