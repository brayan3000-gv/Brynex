/**
 * Informes: el centro de informes (resumen del mes), el estado financiero por
 * canal, ingresos y retiros, comisiones y la exportación a Excel.
 * Cuenta: Hernán Darío Salazar (rol contable, ficticio): ve y exporta lo
 * financiero, sin poder cambiarlo.
 */
export const rol = 'CONTABLE'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Informes', 'El mes en números: clientes, afiliaciones, retiros, ingresos, gastos y comisiones.', 'Brynex · Módulo')

  e.tarea('informes-centro', 'El centro de informes', { tema: 'Informes', buscar: 'informes centro resumen mes clientes activos afiliaciones retiros tareas incapacidades' })
  await e.menu({ ruta: '/admin/informes' })
  await e.dice('El Centro de informes resume el mes: lo que requiere atención, cómo va hoy y cómo va el mes.')
  await e.resaltar(e.page.getByText('Requiere atención', { exact: false }).first().locator('xpath=ancestor::div[1]'), 2400, 6).catch(() => {})
  await e.dice('Cada cifra abre su detalle: clientes activos, empresas, entidades, ingresos y retiros.')
  await e.bajar(350)
  await e.esperar(1200)
  e.finTarea()

  e.tarea('informes-financiero', 'El estado financiero del mes', { tema: 'Informes', buscar: 'estado financiero ingresos gastos utilidad canal administración afiliaciones seguridad social contable' })
  await e.ir('/admin/informes/financiero', { esperar: 1500 })
  await e.dice('El estado financiero muestra lo cobrado, los gastos y la utilidad del mes.')
  await e.dice('Y cada canal por separado: la administración, las afiliaciones y la seguridad social recaudada.', 4600)
  await e.bajar(500)
  await e.esperar(1500)
  await e.subir()
  e.finTarea()

  e.tarea('informes-ingresos-retiros', 'Ingresos y retiros, mes a mes', { tema: 'Informes', buscar: 'ingresos retiros tendencia seis meses consolidado afiliaciones nuevas reingresos' })
  await e.ir('/admin/informes/consolidado-mensual', { esperar: 1500 })
  await e.dice('Ingresos y retiros dice cuántos entraron y cuántos salieron, con la tendencia de seis meses.', 4400)
  await e.bajar(450)
  await e.esperar(1500)
  e.finTarea()

  e.tarea('informes-comisiones', 'Las comisiones de los asesores', { tema: 'Informes', buscar: 'comisiones asesores pagar distribución afiliaciones' })
  await e.ir('/admin/informes/comisiones', { esperar: 1300 })
  await e.dice('En Comisiones, lo que le toca a cada asesor por sus afiliaciones y su administración.', 4000)
  await e.clic({ texto: 'Andrés Felipe Molina' }, { espera: 1800 }).catch(() => {})
  await e.dice('El contable ve y descarga todo en Excel, pero no puede cambiar nada.', 3600)
  e.finTarea()
}
