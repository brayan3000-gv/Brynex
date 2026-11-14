/**
 * Liquidar la planilla por API y pagarla por PSE, grabado en la DEMO PÚBLICA
 * (scripts/demo) con el operador simulado. Reemplaza en la demo al clip
 * «planos-descargar» del manual (el paso 4 del recorrido).
 *
 * Necesita la demo local corriendo con la planilla del mes sin liquidar para
 * VIDEOS_DEMO_RS (998500102, «Independientes demo», por defecto).
 */
export const rol = 'SUPERADMIN'

const RS = process.env.VIDEOS_DEMO_RS ?? '998500102'

export default async function (e) {
  const hoy = new Date()
  await e.ir(`/admin/planos?anio=${hoy.getFullYear()}&mes=${hoy.getMonth() + 1}&razon_social_id=${RS}`, { esperar: 1500 })
  await e.page.evaluate(() => document.getElementById('demo-panel')?.classList.remove('abierto'))

  e.tarea('planos-descargar', 'Liquidar la planilla por API y pagar por PSE', { tema: 'Planos SS', buscar: 'planilla pila liquidar api enlace simple operador pse pagar plano' })
  await e.titulo('Liquidar la planilla por API', 'Sin descargar ni subir el archivo al operador.', 'BryNex · Planillas', 2600)

  await e.dice('En Planos está la planilla del mes de cada razón social, con lo de cada persona.')
  await e.clic(e.page.getByRole('button', { name: /Descargar Plano/ }).first(), { espera: 1400 })

  await e.dice('BryNex arma el plano con los valores que ya calculó: aquí, el total a pagar.')
  await e.resaltar(e.page.getByText(/Valor plano/i).first().locator('xpath=ancestor::div[1]'), 2200, 6).catch(() => {})
  await e.dice('Con un clic lo envía al operador de planilla y recibe el número de planilla.')
  await e.clic(e.page.getByRole('button', { name: /Liquidar en (Simple|Enlace)/ }).first(), { espera: 600 })
  await e.page.getByText(/liquidada en/i).first().waitFor({ timeout: 20000 })
  await e.callar()

  await e.dice('Liquidada: con su número de planilla y el valor a pagar.')
  await e.resaltar(e.page.getByText(/liquidada en/i).first().locator('xpath=ancestor::div[1]'), 2600, 6).catch(() => {})
  await e.callar()

  const pagar = await e.page.locator('a[href*="api-operador/pagar"]').first().getAttribute('href')
  await e.dice('El pago va por PSE, desde el mismo botón.')
  await e.resaltar(e.page.locator('a[href*="api-operador/pagar"]').first(), 1800, 6).catch(() => {})
  await e.callar()
  await e.ir(new URL(pagar).pathname, { esperar: 1200 })
  await e.dice('En la demo es una pasarela de prueba: aquí no se mueve plata.')
  await e.clic(e.page.getByRole('button', { name: /Pagar \(simulado\)/ }), { espera: 1500 })
  await e.dice('Pagada. Después, el soporte de cada persona se le envía por WhatsApp.', 3600)
  await e.callar()
  e.finTarea()
}
