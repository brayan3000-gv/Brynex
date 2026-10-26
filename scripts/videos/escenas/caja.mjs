/**
 * Caja del día: lo cobrado hoy (facturado, efectivo y consignado), el resumen
 * por canal, registrar un gasto y entregar la plata al cierre.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia), que en la demo ya cobró
 * tres facturas hoy (sembrar.php, sección 17).
 */
export const rol = 'USUARIO'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Caja del día', 'Lo que entró hoy, cómo entró, lo que salió y a quién se entregó.', 'Brynex · Módulo')

  e.tarea('caja-dia', 'La caja del día', { tema: 'Caja', buscar: 'cuadre diario caja del día efectivo consignado facturado canal administración seguridad social' })
  await e.menu({ ruta: '/cuadre-diario' })
  await e.dice('La caja del día suma lo que cobraste hoy: lo facturado, el efectivo que tienes y lo consignado en cuentas.')
  await e.resaltar(e.page.getByText('Efectivo en caja', { exact: false }).first().locator('xpath=ancestor::div[2]'), 2800, 6).catch(() => {})
  await e.dice('Debajo, cada peso repartido por canal: administración, afiliaciones y seguridad social.')
  await e.bajar(450)
  await e.esperar(1200)
  await e.subir()
  e.finTarea()

  e.tarea('caja-gasto', 'Registrar un gasto', { tema: 'Caja', buscar: 'registrar gasto caja papelería arriendo transporte efectivo recibo' })
  await e.dice('Si sale plata de la caja, se registra el gasto: el tipo, a quién se pagó y cuánto.')
  await e.clic('button[onclick="abrirModalGasto()"]', { espera: 900 })
  await e.elegir('#modal-gasto-tipo', { index: 3 })
  await e.escribir('input[name=descripcion]:visible', 'Resma de papel y tinta para la impresora')
  await e.escribir('#modal-gasto-pagado-a', 'Papelería El Centro')
  await e.escribir('input[name=valor]:visible', '48000')
  // El botón queda debajo del borde del modal: se trae a la vista y se pulsa.
  const registrar = e.page.locator('button:has-text("Registrar Gasto"):visible').first()
  await registrar.evaluate((b) => b.scrollIntoView({ block: 'center' })).catch(() => {})
  await e.clic(registrar, { espera: 2000, forzar: true })
  e.finTarea()

  e.tarea('caja-entregar', 'Entregar la plata al cierre', { tema: 'Caja', buscar: 'entregar plata cierre caja efectivo jefe responsable movimiento' })
  await e.dice('Al cierre, el efectivo se le entrega a quien responde por él, y queda el registro de quién a quién.')
  await e.ir('/cuadre-diario', { esperar: 900 })
  await e.clic("button[onclick=\"abrirModalCaja('entrega')\"]", { espera: 900 })
  await e.elegir('select[name=usuario_destino_id]', { label: 'Carolina Andrade Méndez' })
  await e.esperar(1600)
  await e.clic({ rol: 'button', nombre: 'Cancelar' }, { espera: 600 }).catch(() => {})
  await e.dice('Y en Facturas del día está el detalle de cada cobro, para cuadrar con el recibo.', 4000)
  e.finTarea()
}
