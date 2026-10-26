/**
 * Graba escenas.
 *
 *   node scripts/videos/grabar.mjs <salida> <escena> [escritorio|movil|ambos]
 *
 * Brynex se usa sobre todo en el computador (Brayan, 24-oct-2026): cada escena
 * dice en `formatos` en cuáles se graba; si no lo dice, solo en escritorio.
 * Sin tercer argumento se graban los formatos de la escena.
 *
 * Las escenas viven en `escenas/<nombre>.mjs` y exportan `rol`, `default async (e)`
 * y, si hace falta, `antes`: SQL que se corre contra la base de VIDEOS justo
 * después de restablecerla (p. ej. quitar a la persona que la escena va a crear).
 *
 * Antes de cada toma la base vuelve a la plantilla (base-videos.sh restablecer):
 * las escenas crean datos y la segunda toma tiene que arrancar igual que la primera.
 * VIDEOS_SIN_RESTABLECER=1 lo salta (para ensayar selectores rápido).
 */
import { execFileSync } from 'node:child_process'
import { join } from 'node:path'
import { grabar, RAIZ } from './motor.mjs'

const [salida, nombre, cual] = process.argv.slice(2)
if (!salida || !nombre) { console.error('Uso: grabar.mjs <salida> <escena> [escritorio|movil|ambos]'); process.exit(1) }
const mod = await import(`./escenas/${nombre}.mjs`)
const formatos = !cual ? (mod.formatos ?? ['escritorio']) : cual === 'ambos' ? ['escritorio', 'movil'] : [cual]
const base = (...args) => execFileSync(join(RAIZ, 'scripts/videos/base-videos.sh'), args, { stdio: ['ignore', 'pipe', 'inherit'] }).toString().trim()
for (const formato of formatos) {
  if (!process.env.VIDEOS_SIN_RESTABLECER) {
    base('restablecer')
    if (mod.antes) base('sql', mod.antes)
  }
  const t = Date.now()
  const dir = await grabar({ nombre, formato, rol: mod.rol, escena: mod.default, salida })
  console.log(`  ${nombre} · ${formato}: ${((Date.now() - t) / 1000).toFixed(0)} s → ${dir}`)
}
