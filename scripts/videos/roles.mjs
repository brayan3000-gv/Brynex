/**
 * Comprueba la matriz de permisos ENTRANDO con una cuenta de cada rol en la
 * copia de videos (nunca en producción): abre cada módulo, anota si abre o
 * da 403, y lee lo que muestra el menú en escritorio y en el celular.
 *
 *   node scripts/videos/roles.mjs [salida.md]
 *
 * Sirve para no afirmar en el manual («¿quién puede hacer esto?») nada que no
 * se haya visto pasar.
 */
import { createRequire } from 'node:module'
import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { homedir } from 'node:os'
import { BASE, cuentaDe } from './motor.mjs'

const require = createRequire(join(process.env.PLAYWRIGHT_DIR ?? join(homedir(), 'brynex-videos-pw'), 'package.json'))
const { chromium } = require('playwright')

const ROLES = ['SUPERADMIN', 'ADMIN', 'USUARIO', 'CONTABLE', 'ASESOR']
const MODULOS = [
  ['Clientes', '/admin/clientes'], ['Contratos', '/admin/contratos'], ['Claves de acceso', '/admin/clave-accesos/global'],
  ['Afiliaciones', '/admin/afiliaciones'], ['Gestión ARL', '/admin/gestion-arl'], ['Incapacidades', '/admin/incapacidades'],
  ['Tareas', '/admin/tareas'], ['Planos SS', '/admin/planos'], ['Cotizaciones', '/admin/cotizaciones'],
  ['Cartas laborales', '/admin/cartas-laborales'], ['Facturación (Empresas)', '/admin/facturacion'], ['Cobro en visita', '/visita'],
  ['Cobros', '/admin/cobros'], ['Cuadre diario', '/cuadre-diario'], ['Caja menor', '/caja-menor'],
  ['Anticipos', '/admin/anticipos/informe'], ['Préstamos', '/admin/prestamos'], ['Informes', '/admin/informes'],
  ['Estado financiero', '/admin/informes/financiero'], ['Comisiones', '/admin/informes/comisiones'], ['Gastos', '/admin/informes/gastos'],
  ['WhatsApp', '/admin/whatsapp/chat'], ['Plantillas WhatsApp', '/admin/whatsapp/plantillas'], ['Marketing', '/admin/marketing'],
  ['Publicidad', '/admin/publicidad'], ['Redes sociales', '/admin/redes-sociales'], ['Página web', '/admin/pagina'],
  ['Asesores', '/admin/asesores'], ['Usuarios', '/admin/usuarios'], ['Configuración (hub)', '/admin/configuracion'],
  ['Parámetros', '/admin/configuracion/parametros'], ['Razones sociales', '/admin/configuracion/razones-sociales'],
  ['Cuentas bancarias', '/admin/configuracion/cuentas'], ['Auditoría', '/admin/bitacora'], ['Traslado de RS', '/admin/traslados-rs'],
  ['Hub BryNex', '/brynex'], ['Aliados', '/admin/aliados'], ['Finanzas personales', '/finanzas'],
]
const PERMITIDOS = ['localhost', '127.0.0.1', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'unpkg.com']

async function entrar(page, rol) {
  const { cedula, clave } = cuentaDe(rol)
  await page.goto(BASE + '/login')
  await page.fill('input[name=cedula]', cedula)
  await page.fill('input[name=password]', clave)
  await page.click('button[type=submit]')
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 })
}

const navegador = await chromium.launch({ executablePath: process.env.CHROME || undefined })
const resultado = {}
const salidas = []
for (const [i, rol] of ROLES.entries()) {
  // El login permite 5 intentos por minuto: se espacian.
  if (i > 0) await new Promise((ok) => setTimeout(ok, 13000))
  const ctx = await navegador.newContext({ viewport: { width: 1280, height: 720 }, locale: 'es-CO' })
  await ctx.route('**/*', (r) => (PERMITIDOS.includes(new URL(r.request().url()).hostname) ? r.continue() : (salidas.push(r.request().url()), r.abort())))
  const page = await ctx.newPage()
  await entrar(page, rol)
  const fila = { modulos: {}, menu: [], menuAdmin: [], movil: [] }
  await page.goto(BASE + '/dashboard')
  fila.menu = await page.$$eval('nav.menu-iconos > a.menu-item .label', (xs) => xs.map((x) => x.textContent.trim()))
  fila.menuAdmin = await page.$$eval('nav.menu-iconos .menu-dropdown a.panel-item', (xs) => xs.map((x) => x.textContent.trim().replace(/\s+/g, ' ')))
  for (const [nombre, ruta] of MODULOS) {
    const r = await page.goto(BASE + ruta, { waitUntil: 'domcontentloaded' }).catch(() => null)
    const codigo = r ? r.status() : 0
    const final = new URL(page.url()).pathname
    fila.modulos[nombre] = codigo === 200 && final !== ruta ? `→ ${final}` : String(codigo)
  }
  // El cajón del celular: mismo usuario, pantalla angosta.
  const movil = await ctx.newPage()
  await movil.setViewportSize({ width: 360, height: 640 })
  await movil.goto(BASE + '/dashboard')
  fila.movil = await movil.$$eval('#mobileDrawer a.drawer-item', (xs) => xs.map((x) => x.textContent.replace(/\s+/g, ' ').trim()))
  resultado[rol] = fila
  await ctx.close()
  console.log(`  ${rol}: listo`)
}
await navegador.close()

const marca = (v) => (v === '200' ? '✅' : v === '403' ? '⛔' : v)
let md = `# Matriz comprobada entrando con cada rol (${new Date().toISOString().slice(0, 16)})\n\n`
md += '| Módulo | ' + ROLES.join(' | ') + ' |\n|---|' + ROLES.map(() => '---').join('|') + '|\n'
for (const [nombre] of MODULOS) md += `| ${nombre} | ` + ROLES.map((r) => marca(resultado[r].modulos[nombre])).join(' | ') + ' |\n'
md += '\n## Menú de escritorio\n\n' + ROLES.map((r) => `- **${r}**: ${resultado[r].menu.join(', ')}${resultado[r].menuAdmin.length ? ' · Admin ▸ ' + resultado[r].menuAdmin.join(', ') : ''}`).join('\n')
md += '\n\n## Menú del celular\n\n' + ROLES.map((r) => `- **${r}**: ${resultado[r].movil.join(', ')}`).join('\n')
md += `\n\nPeticiones bloqueadas hacia afuera: ${salidas.length ? salidas.join(', ') : 'ninguna'}\n`
const destino = process.argv[2] ?? join(homedir(), 'Movies/Brynex-videos/roles.md')
writeFileSync(destino, md)
writeFileSync(destino.replace(/\.md$/, '.json'), JSON.stringify(resultado, null, 1))
console.log(md)
