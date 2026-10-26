/**
 * Arma los MP4 a partir de lo que grabó `motor.mjs`.
 *
 *   node scripts/videos/armar.mjs <carpeta de grabaciones> <carpeta de salida>
 *
 * Por cada grabación (`<escena>-<formato>/` con `cuadros/` y `linea.json`):
 *   1. los cuadros, cada uno con lo que duró en pantalla, → MP4 a 30 cuadros
 *      por segundo (H.264, yuv420p, faststart: se reproduce en cualquier lado);
 *   2. si hay narración (`voz/<escena>-<formato>.wav`), se le mezcla;
 *   3. cada tarea marcada se corta como clip del manual, en dos tamaños: el
 *      original (1920×1080 o 1080×1920) y uno liviano para la web.
 * Luego une los capítulos de cada formato en el video completo.
 */
import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { homedir } from 'node:os'

/** Donde musica.mjs deja la música (sin importar ese módulo, que trae la llave de Gemini). */
const CARPETA_MUSICA = process.env.VIDEOS_MUSICA ?? join(homedir(), 'Movies/Brynex-videos/musica')

const ff = (args) => execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' })
const dur = (archivo) => Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', archivo]).toString().trim())

const X264 = ['-c:v', 'libx264', '-preset', 'slow', '-pix_fmt', 'yuv420p', '-movflags', '+faststart']

/** Cuadros con su duración → MP4 de cuadros constantes. */
export function cuadrosAMp4(dir, salida) {
  const l = JSON.parse(readFileSync(join(dir, 'linea.json'), 'utf8'))
  const ts = l.cuadros
  if (ts.length === 0) throw new Error(`Sin cuadros en ${dir}`)
  const lineas = ['ffconcat version 1.0']
  for (let i = 0; i < ts.length; i++) {
    const hasta = i + 1 < ts.length ? ts[i + 1] : Math.max(l.fin, ts[i] + 0.1)
    lineas.push(`file 'cuadros/${String(i).padStart(6, '0')}.jpg'`, `duration ${Math.max(0.001, hasta - ts[i]).toFixed(4)}`)
  }
  // El último se repite: el demuxer ignora la duración del último archivo.
  lineas.push(`file 'cuadros/${String(ts.length - 1).padStart(6, '0')}.jpg'`)
  writeFileSync(join(dir, 'lista.ffconcat'), lineas.join('\n'))
  ff(['-f', 'concat', '-safe', '0', '-i', join(dir, 'lista.ffconcat'),
    // Los cuadros son JPEG (rango completo): se pasan a rango de video (tv), o QuickTime y
    // Safari en iPhone los muestran lavados.
    '-vf', `fps=30,scale=${l.ancho}:${l.alto}:flags=lanczos:in_range=pc:out_range=tv,format=yuv420p`,
    '-color_range', 'tv', '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709',
    ...X264, '-crf', '18', '-r', '30', '-an', salida])
  return l
}

/**
 * Le pone el sonido al video: la narración (voz + clics, de voz.mjs) y, si hay,
 * la música de fondo (musica.mjs) en bucle, bajita y agachándose cuando Avi
 * habla (sidechain), con entrada y salida suaves. Sin nada, un silencio, para
 * que todos los videos tengan pista de audio y se puedan unir.
 */
/** Volumen de la música (1 = como sale de Lyria). */
const VOLUMEN_MUSICA = Number(process.env.VIDEOS_VOLUMEN_MUSICA ?? 0.32)

export function conVoz(video, voz, salida) {
  const d = dur(video)
  const musica = process.env.VIDEOS_SIN_MUSICA ? null : join(CARPETA_MUSICA, 'fondo.mp3')
  const hayVoz = voz && existsSync(voz)
  const hayMusica = musica && existsSync(musica)
  const comun = ['-map', '0:v', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2', '-t', d.toFixed(3), '-movflags', '+faststart', salida]
  if (hayVoz && hayMusica) {
    ff(['-i', video, '-i', voz, '-stream_loop', '-1', '-i', musica, '-filter_complex',
      `[1:a]apad,atrim=0:${d.toFixed(3)},asplit[v][llave];`
      + `[2:a]atrim=0:${d.toFixed(3)},volume=${VOLUMEN_MUSICA},afade=t=in:d=1.5,afade=t=out:st=${Math.max(0, d - 1.5).toFixed(2)}:d=1.5[m];`
      // Se agacha poco (unos 6 dB) cuando Avi habla: la música suena todo el video.
      + '[m][llave]sidechaincompress=threshold=0.06:ratio=2.5:attack=40:release=600[mb];'
      + '[v][mb]amix=inputs=2:normalize=0:duration=first[a]', '-map', '[a]', ...comun])
  } else if (hayVoz) {
    ff(['-i', video, '-i', voz, '-filter_complex', `[1:a]apad,atrim=0:${d.toFixed(3)}[a]`, '-map', '[a]', ...comun])
  } else {
    ff(['-i', video, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-map', '1:a', ...comun])
  }
}

/** Un tramo del video, re-codificado para que empiece justo donde debe. */
export function cortar(video, desde, hasta, salida, { liviano = false, ancho, alto } = {}) {
  const escala = liviano ? (ancho > alto ? 'scale=1280:720' : 'scale=720:1280') + ':flags=lanczos,' : ''
  ff(['-ss', desde.toFixed(2), '-to', hasta.toFixed(2), '-i', video,
    '-vf', `${escala}fade=t=in:st=0:d=0.3,fade=t=out:st=${Math.max(0, hasta - desde - 0.4).toFixed(2)}:d=0.4,format=yuv420p`,
    '-af', `afade=t=in:d=0.3,afade=t=out:st=${Math.max(0, hasta - desde - 0.4).toFixed(2)}:d=0.4`,
    ...X264, '-crf', liviano ? '28' : '20', '-c:a', 'aac', '-b:a', liviano ? '96k' : '160k', salida])
}

/** Une videos del mismo tamaño, uno tras otro. */
export function unir(videos, salida) {
  const lista = salida + '.txt'
  writeFileSync(lista, videos.map((v) => `file '${v}'`).join('\n'))
  ff(['-f', 'concat', '-safe', '0', '-i', lista, '-c', 'copy', '-movflags', '+faststart', salida])
}

export { dur }

/** El orden del manual completo (un capítulo por módulo). El comercial va aparte. */
export const ORDEN = ['entrada', 'clientes', 'contratos', 'afiliaciones', 'facturacion', 'cobros', 'visita', 'caja', 'planos', 'incapacidades',
  'tareas', 'informes', 'comunicacion', 'cotizador', 'administracion', 'portal-empresas', 'roles']

/**
 * El comercial en vertical (1080×1920) para redes, a partir de la toma de
 * escritorio: el video a lo ancho en el centro, sobre el fondo de Brynex, con la
 * marca arriba y una frase abajo. Los textos los dibuja Chrome como PNG (el
 * FFmpeg de este Mac no trae drawtext).
 */
export async function verticalDesde(video, salida) {
  const { createRequire } = await import('node:module')
  const require = createRequire(join(process.env.PLAYWRIGHT_DIR ?? join(homedir(), 'brynex-videos-pw'), 'package.json'))
  const { chromium } = require('playwright')
  const fondo = salida.replace(/\.mp4$/, '-fondo.png')
  const b = await chromium.launch({ executablePath: process.env.CHROME || undefined })
  const p = await b.newPage({ viewport: { width: 1080, height: 1920 } })
  await p.setContent(`<!doctype html><html><body style="margin:0;width:1080px;height:1920px;font-family:Inter,-apple-system,'Segoe UI',sans-serif;color:#fff;
    background:radial-gradient(120% 80% at 20% 0%,#2563eb 0%,#1e3a8a 45%,#0a1628 100%)">
    <div style="position:absolute;top:200px;left:0;right:0;text-align:center">
      <div style="width:120px;height:120px;margin:0 auto 34px;border-radius:30px;background:#fff;color:#1e3a8a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:64px">B</div>
      <div style="font-size:30px;letter-spacing:.2em;text-transform:uppercase;opacity:.8">Brynex</div>
      <div style="font-size:74px;font-weight:800;line-height:1.08;margin-top:18px">Tu seguridad social,<br>en orden</div></div>
    <div style="position:absolute;bottom:250px;left:80px;right:80px;text-align:center;font-size:40px;line-height:1.35;opacity:.92">
      Afiliaciones automáticas, planillas PILA por API y todo el aliado en un solo lugar.</div></body></html>`)
  await p.screenshot({ path: fondo })
  await b.close()
  const d = dur(video)
  ff(['-loop', '1', '-t', d.toFixed(3), '-i', fondo, '-i', video, '-filter_complex',
    '[1:v]scale=1080:608:flags=lanczos[v];[0:v][v]overlay=0:656,fps=30,format=yuv420p[o]', '-map', '[o]', '-map', '1:a',
    ...X264, '-crf', '19', '-r', '30', '-c:a', 'copy', '-t', d.toFixed(3), salida])
}

// ── Uso por línea de comandos ─────────────────────────────────────────────
if (import.meta.url === `file://${process.argv[1]}`) {
  const [grabaciones, salida, ...solo] = process.argv.slice(2)
  if (!grabaciones || !salida) { console.error('Uso: armar.mjs <grabaciones> <salida> [escena-formato…]'); process.exit(1) }
  for (const d of ['capitulos', 'clips', 'web/clips']) mkdirSync(join(salida, d), { recursive: true })
  const manual = []
  const carpetas = readdirSync(grabaciones).filter((d) => existsSync(join(grabaciones, d, 'linea.json')))
    .filter((d) => solo.length === 0 || solo.includes(d)).sort()
  for (const d of carpetas) {
    const dir = join(grabaciones, d)
    const mudo = join(dir, 'mudo.mp4')
    const l = cuadrosAMp4(dir, mudo)
    const cap = join(salida, 'capitulos', `${d}.mp4`)
    conVoz(mudo, join(grabaciones, '..', 'voz', `${d}.wav`), cap)
    console.log(`  ${d}: ${dur(cap).toFixed(1)} s, ${l.tareas.length} tareas`)
    for (const t of l.tareas) {
      const nombre = `${t.id}-${l.formato}`
      // El comercial se ve entero en la página (con su portada y su saludo).
      const entero = t.id === 'comercial'
      const desde = entero ? 0 : Math.max(0, t.desde - 0.2), hasta = entero ? dur(cap) : Math.min(dur(cap), t.hasta + 0.3)
      cortar(cap, desde, hasta, join(salida, 'clips', `${nombre}.mp4`))
      cortar(cap, desde, hasta, join(salida, 'web/clips', `${nombre}.mp4`), { liviano: true, ancho: l.ancho, alto: l.alto })
      manual.push({ id: t.id, titulo: t.titulo, rol: l.rol, escena: l.nombre, formato: l.formato, segundos: +(hasta - desde).toFixed(1),
        tema: t.tema ?? null, pasos: t.pasos ?? null, buscar: t.buscar ?? null,
        archivo: `clips/${nombre}.mp4`, kb: Math.round(statSync(join(salida, 'web/clips', `${nombre}.mp4`)).size / 1024) })
    }
  }
  // El comercial: además de su toma de escritorio, la versión vertical para redes.
  const comercial = join(salida, 'capitulos', 'comercial-escritorio.mp4')
  if (existsSync(comercial) && (solo.length === 0 || solo.includes('comercial-escritorio'))) {
    const vertical = join(salida, 'Brynex-comercial-vertical.mp4')
    await verticalDesde(comercial, vertical)
    execFileSync('cp', [comercial, join(salida, 'Brynex-comercial-escritorio.mp4')])
    cortar(vertical, 0, dur(vertical), join(salida, 'web/clips', 'comercial-movil.mp4'), { liviano: true, ancho: 1080, alto: 1920 })
    const base = manual.find((m) => m.id === 'comercial')
    if (base) manual.push({ ...base, formato: 'movil', segundos: +dur(vertical).toFixed(1), archivo: 'clips/comercial-movil.mp4',
      kb: Math.round(statSync(join(salida, 'web/clips', 'comercial-movil.mp4')).size / 1024) })
    // La portada del recuadro «Conoce Brynex» de la página.
    ff(['-ss', '4', '-i', comercial, '-frames:v', '1', '-vf', 'scale=1280:720', join(salida, 'web', 'comercial-portada.jpg')])
    console.log(`  comercial: ${dur(comercial).toFixed(0)} s en escritorio y vertical`)
  }
  const previo = existsSync(join(salida, 'web/manual.json')) ? JSON.parse(readFileSync(join(salida, 'web/manual.json'), 'utf8')) : []
  const nuevos = new Set(manual.map((m) => m.archivo))
  writeFileSync(join(salida, 'web/manual.json'), JSON.stringify([...previo.filter((m) => !nuevos.has(m.archivo)), ...manual], null, 1))
  console.log(`Listo: ${manual.length} clips`)

  // El video completo de cada formato: los capítulos en el orden del recorrido.
  for (const formato of ['escritorio', 'movil']) {
    const caps = ORDEN.map((e) => join(salida, 'capitulos', `${e}-${formato}.mp4`)).filter(existsSync)
    if (formato === 'movil' && caps.length < 4) continue   // casi todo es de escritorio: no hay «manual completo» de celular
    if (caps.length < 2) continue
    const lista = join(salida, `completo-${formato}.txt`)
    writeFileSync(lista, caps.map((v) => `file '${v}'`).join('\n'))
    const fin = join(salida, `Brynex-manual-completo-${formato}.mp4`)
    ff(['-f', 'concat', '-safe', '0', '-i', lista, '-c:v', 'libx264', '-preset', 'slow', '-crf', '20', '-pix_fmt', 'yuv420p',
      '-r', '30', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2', '-movflags', '+faststart', fin])
    console.log(`  completo ${formato}: ${dur(fin).toFixed(0)} s, ${caps.length} capítulos`)
  }
}
