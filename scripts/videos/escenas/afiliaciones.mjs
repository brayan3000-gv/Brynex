/**
 * Afiliaciones: la bandeja del mes con el estado de cada radicado (EPS, ARL,
 * caja y pensión), mover un radicado, la afiliación a la ARL por API (hasta
 * donde llega sin conexión) y el semáforo de Gestión ARL.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia). El aliado demo tiene
 * activo el módulo de ARL por API, pero no tiene credenciales ni red: el
 * flujo de Sura se muestra hasta que pide la clave del portal, y se dice.
 */
export const rol = 'USUARIO'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Afiliaciones', 'Cada ingreso del mes y el trámite ante la EPS, la ARL, la caja y el fondo de pensión.', 'Brynex · Módulo')

  e.tarea('afiliaciones-bandeja', 'La bandeja de afiliaciones del mes', { tema: 'Afiliaciones', buscar: 'afiliaciones bandeja ingresos mes radicados eps arl caja pensión pendiente trámite ok' })
  await e.menu({ ruta: '/admin/afiliaciones' })
  await e.dice('Afiliaciones muestra los ingresos del mes, y cómo va el trámite ante cada entidad.')
  await e.dice('Naranja es pendiente, azul en trámite y verde confirmado. Abajo, el conteo de cada estado.')
  await e.resaltar(e.page.getByText('Pendientes:', { exact: false }).first().locator('xpath=ancestor::div[1]'), 2600, 6).catch(() => {})
  e.finTarea()

  e.tarea('afiliaciones-radicado', 'Actualizar el estado de un radicado', { tema: 'Afiliaciones', buscar: 'radicado estado trámite número canal portal correo observación enviado al cliente' })
  await e.dice('Al tocar una entidad se abre su radicado: el estado, el canal y lo que se hizo.')
  await e.clic('tbody tr:nth-child(5) button.badge-estado.badge-pendiente', { espera: 1600 })
  await e.elegir('#mrad-estado', { index: 1 })
  await e.elegir('#mrad-canal', { index: 1 })
  await e.escribir('#mrad-observacion', 'Se radicó en el portal de la EPS con el formulario firmado.')
  await e.clic('#btnGuardarRadicado', { espera: 1800 })
  await e.dice('Queda en la historia del contrato, con quién lo hizo y cuándo.', 3200)
  e.finTarea()

  e.tarea('afiliaciones-arl-api', 'Afiliar a la ARL por API', { tema: 'Afiliaciones', buscar: 'afiliar arl sura colmena api automático sin portal certificado' })
  await e.ir('/admin/afiliaciones', { esperar: 900 })
  await e.dice('Con ARL Sura o Colmena, la afiliación se hace por API, desde el mismo radicado.')
  await e.clic('tbody tr:first-child button.badge-estado.badge-pendiente', { espera: 1600 })
  await e.clic('#btnAfiliarApi', { espera: 2500 })
  await e.dice('Brynex arma los datos del trabajador y la radica sin entrar al portal; luego trae el certificado.', 4800)
  await e.dice('En esta demo no hay conexión con la ARL: pide la clave del portal, y hasta aquí llega.', 4600)
  await e.page.keyboard.press('Escape')
  await e.clic('.modal-close:visible, button:has-text("✕"):visible', { espera: 600 }).catch(() => {})
  e.finTarea()

  e.tarea('gestion-arl', 'El semáforo de Gestión ARL', { tema: 'Afiliaciones', buscar: 'gestión arl semáforo vigencia renovar facturar retirar certificado carné' })
  await e.ir('/admin/gestion-arl', { esperar: 1000 })
  await e.dice('Gestión ARL es para quien solo toma riesgos laborales: el semáforo dice qué vigencias vencen.')
  await e.dice('Desde cada fila se renueva, se factura o se retira, y se baja el certificado al día.', 4400)
  await e.resaltar(e.page.locator('tbody tr').first(), 2400, 4).catch(() => {})
  e.finTarea()
}
