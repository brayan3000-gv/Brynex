/**
 * Facturación (Empresas): la lista de empresas, facturar el mes de una empresa
 * en lote, el detalle de lo que se cobra, el pago, el recibo y el historial.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia). La empresa es el Taller
 * Mecánico El Pistón (inventada), que en la demo debe octubre.
 */
export const rol = 'USUARIO'

const pesos = (t) => Number(String(t).replace(/\D/g, '')) || 0

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Facturación', 'Facturar el mes de cada empresa o persona, cobrar y entregar el recibo.', 'Brynex · Módulo')

  e.tarea('facturacion-empresas', 'La lista de empresas', { tema: 'Facturación', buscar: 'empresas facturación clientes empresa nit lista contratos activos' })
  await e.menu({ ruta: '/admin/facturacion' })
  await e.dice('En Empresas están las empresas cliente, con cuántos contratos activos tiene cada una.')
  await e.esperar(800)
  e.finTarea()

  e.tarea('facturacion-lote', 'Facturar el mes de una empresa', { tema: 'Facturación', buscar: 'facturar lote empresa mes planilla seguridad social cobrar pago efectivo consignación' })
  await e.clic({ texto: 'TALLER MECÁNICO EL PISTÓN' }, { espera: 1500 })
  await e.dice('Dentro de la empresa está cada trabajador con lo que cuesta su mes: salud, riesgos, pensión, caja y administración.')
  await e.bajar(e.movil ? 500 : 200)
  await e.esperar(800)
  await e.subir()
  await e.dice('Se marcan los que se van a cobrar y se toca Facturar.')
  await e.clic('#chkAll', { espera: 500 })
  await e.clic('#btnFacturarSel', { espera: 2200 })
  await e.dice('El detalle muestra cada aporte, la administración, las afiliaciones del mes y la mora, si la hay.', 4800)
  await e.resaltar(e.page.getByText('Detalle a cobrar', { exact: false }).first().locator('xpath=..'), 3000, 6).catch(() => {})
  await e.dice('Se escribe cómo pagó: en efectivo o por consignación, a la cuenta del aliado.')
  const saldo = pesos(await e.page.getByText('Saldo a pagar', { exact: false }).first().locator('xpath=ancestor::div[2]').innerText().catch(() => '0'))
  await e.escribir('#mf-efectivo', String(saldo || 0))
  await e.esperar(700)
  await e.clic('#mf-btn-guardar', { espera: 2500 })
  await e.dice('Listo: la factura queda pagada, y con ella las planillas de seguridad social de ese mes.', 4200)
  e.finTarea()

  e.tarea('facturacion-recibo', 'El recibo de pago', { tema: 'Facturación', buscar: 'recibo de pago imprimir pdf comprobante factura' })
  await e.dice('Y sale el recibo, con el detalle de cada trabajador, para imprimirlo o enviarlo.', 4200)
  await e.bajar(300)
  await e.esperar(1500)
  e.finTarea()

  e.tarea('facturacion-historial', 'El historial de pagos', { tema: 'Facturación', buscar: 'historial pagos facturas meses pagados saldo a favor anuladas' })
  await e.ir('/admin/facturacion/empresa/3', { esperar: 900 })
  await e.dice('Y en Historial están todos los meses pagados de la empresa, con su saldo.')
  await e.clic(e.page.locator('a, button').filter({ hasText: /Historial/ }).first(), { espera: 1800 })
  await e.bajar(e.movil ? 600 : 300)
  await e.esperar(1500)
  e.finTarea()
}
