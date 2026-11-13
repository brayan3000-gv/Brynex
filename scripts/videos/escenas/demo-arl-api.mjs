/**
 * Afiliar a la ARL por API, grabado en la DEMO PÚBLICA (scripts/demo), con su
 * radicación simulada: el mismo botón y los mismos pasos que ve un prospecto.
 * Reemplaza en la demo al clip «afiliaciones-arl-api» del manual, que se grabó
 * sin conexión y se quedaba en «pide la clave del portal».
 *
 * Necesita la demo local corriendo (scripts/demo/demo.sh servir) con un contrato
 * cuyo radicado de ARL (SURA) esté pendiente: VIDEOS_DEMO_CEDULA (52987654 por
 * defecto, Luis Fernando Castillo, el del recorrido).
 *
 *   VIDEOS_BASE=http://localhost:8078 VIDEOS_SIN_RESTABLECER=1 \
 *   VIDEOS_CEDULA=9985000001 VIDEOS_CLAVE=… node scripts/videos/grabar.mjs <salida> demo-arl-api
 */
export const rol = 'SUPERADMIN'

const CEDULA = process.env.VIDEOS_DEMO_CEDULA ?? '52987654'

export default async function (e) {
  await e.ir(`/admin/afiliaciones?buscar=${CEDULA}`, { esperar: 1200 })
  // El panel DEMO no sale en el video.
  await e.page.evaluate(() => document.getElementById('demo-panel')?.classList.remove('abierto'))

  e.tarea('afiliaciones-arl-api', 'Afiliar a la ARL por API', { tema: 'Afiliaciones', buscar: 'afiliar arl sura colmena api automático sin portal certificado radicado' })
  await e.titulo('Afiliar a la ARL por API', 'Sin entrar al portal de la ARL: BryNex lo hace por ti.', 'BryNex · Afiliaciones', 2600)

  await e.dice('Cada contrato nuevo trae su radicado de ARL. Aquí está pendiente, en naranja.')
  const arl = e.page.locator(`tr:has-text("${CEDULA}") button.btn-rad[data-rad*='"tipo":"arl"']`).first()
  await e.resaltar(arl, 1800, 6).catch(() => {})
  await e.clic(arl, { espera: 1500 })

  await e.dice('Al abrirlo, BryNex ofrece afiliar por API, sin entrar al portal de la ARL.')
  await e.resaltar('#btnRadicarDemo', 1800, 6).catch(() => {})
  await e.clic('#btnRadicarDemo', { espera: 2200 })

  await e.dice('Primero revisa lo que pide la ARL: el trabajador, el empleador, el riesgo, el cargo y el salario.')
  await e.resaltar('#demoRad .dr-grid', 2600, 8).catch(() => {})
  await e.dice('Si algo falta, lo avisa antes de enviar. Aquí está todo; solo se elige desde cuándo cubre.')
  await e.resaltar('#dr-fecha', 1800, 6).catch(() => {})
  await e.callar()

  await e.clic('#dr-ir', { espera: 400 })
  await e.dice('BryNex entra al API, valida al trabajador, envía la afiliación y descarga el certificado.', 7000, { arriba: false })
  await e.page.locator('#demoRad .dr-ok').waitFor({ timeout: 20000 })
  await e.callar()

  await e.dice('Listo: el radicado queda en OK, con su número y el certificado adjunto.', undefined, { arriba: false })
  await e.resaltar('#demoRad .dr-ok', 2400, 6).catch(() => {})
  await e.dice('En la demo todo es simulado: el PDF dice muestra y nada sale a la ARL.', 3600, { arriba: false })
  await e.callar()
  e.finTarea()
}
