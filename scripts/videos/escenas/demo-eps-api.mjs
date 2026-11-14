/**
 * Radicar en la EPS por API, grabado en la DEMO PÚBLICA (scripts/demo) con su
 * radicación simulada. Clip nuevo de la demo (no reemplaza ninguno del manual).
 *
 * Necesita la demo local corriendo con un contrato cuyo radicado de EPS esté
 * pendiente: VIDEOS_DEMO_CEDULA (52987654 por defecto). Ver demo-arl-api.mjs.
 */
export const rol = 'SUPERADMIN'

const CEDULA = process.env.VIDEOS_DEMO_CEDULA ?? '52987654'

export default async function (e) {
  await e.ir(`/admin/afiliaciones?buscar=${CEDULA}`, { esperar: 1200 })
  await e.page.evaluate(() => document.getElementById('demo-panel')?.classList.remove('abierto'))

  e.tarea('demo-eps-api', 'Radicar en la EPS por API', { tema: 'Afiliaciones', buscar: 'eps radicar novedad ingreso portal api sura nueva eps salud total sanitas' })
  await e.titulo('Radicar en la EPS por API', 'La novedad de ingreso, sin entrar al portal de la EPS.', 'BryNex · Afiliaciones', 2600)

  await e.dice('La EPS también se radica desde aquí. Este radicado está pendiente.')
  const eps = e.page.locator(`tr:has-text("${CEDULA}") button.btn-rad[data-rad*='"tipo":"eps"']`).first()
  await e.resaltar(eps, 1800, 6).catch(() => {})
  await e.clic(eps, { espera: 1500 })

  await e.dice('BryNex entra al portal de empleadores de la EPS por ti.')
  await e.resaltar('#btnRadicarDemo', 1800, 6).catch(() => {})
  await e.clic('#btnRadicarDemo', { espera: 2200 })

  await e.dice('Revisa los datos del trabajador y del empleador antes de radicar.')
  await e.resaltar('#demoRad .dr-grid', 2400, 8).catch(() => {})
  await e.callar()

  await e.clic('#dr-ir', { espera: 400 })
  await e.dice('Consulta al afiliado, llena la novedad de ingreso, adjunta el formulario y radica.', 7000, { arriba: false })
  await e.page.locator('#demoRad .dr-ok').waitFor({ timeout: 20000 })
  await e.callar()

  await e.dice('Queda radicado, con su número y la constancia en PDF.', undefined, { arriba: false })
  await e.resaltar('#demoRad .dr-ok', 2400, 6).catch(() => {})
  await e.dice('Lo mismo funciona con la caja de compensación y el fondo de pensión.', 3400, { arriba: false })
  await e.callar()
  e.finTarea()
}
