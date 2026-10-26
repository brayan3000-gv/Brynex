/**
 * Tareas: el tablero del equipo con su semáforo, crear una tarea, registrar
 * la gestión y cerrarla. Cuenta: Daniela Restrepo (rol usuario, ficticia).
 */
export const rol = 'USUARIO'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Tareas', 'Los pendientes del equipo con cada persona: traslados, beneficiarios, documentos, subsidios.', 'Brynex · Módulo')

  e.tarea('tareas-tablero', 'El tablero de tareas', { tema: 'Tareas', buscar: 'tareas tablero pendientes semáforo vencidas urgentes encargado' })
  await e.menu({ ruta: '/admin/tareas' })
  await e.dice('Tareas reúne los pendientes del equipo, cada uno con su encargado, su fecha límite y un semáforo.')
  await e.resaltar(e.page.locator('table tbody tr').first(), 2400, 4).catch(() => {})
  await e.dice('Rojo es vencida, amarillo urgente y verde al día. Se filtra por encargado, tipo o estado.', 4400)
  e.finTarea()

  e.tarea('tareas-crear', 'Crear una tarea', { tema: 'Tareas', buscar: 'nueva tarea crear traslado eps inclusión beneficiarios encargado cédula' })
  await e.dice('Para crear una: el tipo, el encargado y la persona.')
  await e.clic('button[onclick="abrirModalNueva()"]', { espera: 1000 })
  // Los campos del modal (la lista de arriba tiene filtros con los mismos nombres).
  const modal = 'form:has(#inputCedula)'
  await e.elegir(`${modal} select[name=tipo]`, { index: 2 })
  await e.elegir(`${modal} select[name=encargado_id]`, { label: 'Daniela Restrepo Gómez' }).catch(() => {})
  await e.escribir('#inputCedula', '9985100020', { retraso: 70 })
  await e.esperar(1600)
  await e.clic({ texto: '9985100020 —' }, { espera: 1000 }).catch(() => {})
  await e.escribir('textarea[name=tarea]', 'Incluir al hijo como beneficiario en la EPS. Falta el registro civil.')
  await e.clic({ rol: 'button', nombre: 'Crear Tarea' }, { espera: 2200 })
  await e.dice('Queda en el tablero, con su semáforo desde el primer día.', 3200)
  e.finTarea()

  e.tarea('tareas-gestionar', 'Registrar la gestión y cerrar una tarea', { tema: 'Tareas', buscar: 'gestionar tarea gestión llamada cerrar trasladar encargado' })
  await e.ir('/admin/tareas', { esperar: 900 })
  await e.dice('Cada avance se anota en Gestionar: qué se hizo y qué sigue. Así nadie pierde el hilo.')
  await e.clic('button[onclick^="abrirModalUnico("]:visible', { espera: 1600 })
  await e.esperar(1600)
  await e.dice('En Acciones se traslada a otra persona del equipo o se cierra, con su resultado.', 4200)
  await e.clic('#tabBtnAcciones', { espera: 1500 })
  await e.esperar(1500)
  e.finTarea()
}
