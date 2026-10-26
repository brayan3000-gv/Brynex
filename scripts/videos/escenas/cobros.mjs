/**
 * Cobros y cartera: la mora del mes (individuales y empresas), registrar una
 * gestión de cobro y los préstamos con sus abonos (el cobro en visita va en `visita`).
 * Cuenta: Daniela Restrepo (rol usuario, ficticia). No se envía ningún
 * WhatsApp ni se hace ninguna llamada: la demo no tiene red.
 */
export const rol = 'USUARIO'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Cobros y cartera', 'Quién debe el mes, cuánto lleva de mora, a quién se le fió y el cobro en la calle.', 'Brynex · Módulo')

  e.tarea('cobros-cartera', 'Ver quién debe el mes', { tema: 'Cobros', buscar: 'cobros cartera mora pendientes deben mes semáforo gestión llamar' })
  await e.menu({ ruta: '/admin/cobros' })
  await e.dice('Cobros muestra quién no ha pagado el mes: cuánto debe, cuánta mora lleva y si ya se le llamó.')
  await e.resaltar(e.page.getByText('Sin llamar', { exact: false }).first().locator('xpath=..'), 2600, 6).catch(() => {})
  await e.dice('Desde cada fila se registra la llamada o se le factura ahí mismo.')
  await e.clic('tbody button:has-text("📞")', { espera: 1500 })
  await e.esperar(1800)
  await e.page.keyboard.press('Escape')
  await e.esperar(400)
  e.finTarea()

  e.tarea('cobros-empresas', 'La cartera de empresas', { tema: 'Cobros', buscar: 'cobros empresas cartera pendientes empresa mes' })
  await e.ir('/admin/cobros/empresas?mes=10&anio=2026', { esperar: 1000 })
  await e.dice('En Empresas, las que no han pagado el mes, con su total y su encargado.', 3800)
  e.finTarea()

  e.tarea('prestamos-abonar', 'Préstamos: registrar un abono', { tema: 'Cobros', buscar: 'préstamos fiado cartera abono abonar deuda cliente' })
  await e.menu({ ruta: '/admin/prestamos' })
  await e.dice('Cuando se le fía el mes a alguien, queda en Préstamos, y se va abonando.')
  await e.clic(e.page.getByRole('button', { name: /Abonar/ }).first(), { espera: 1300 })
  await e.dice('Se escribe el abono y queda en su historia, con su recibo.')
  const valor = e.page.locator('input[type=number]:visible, input[name=valor]:visible, input[id*=valor]:visible').first()
  await e.escribir(valor, '200000')
  await e.esperar(1500)
  await e.page.keyboard.press('Escape')
  e.finTarea()

}
