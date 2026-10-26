/**
 * Incapacidades: registrar una, seguir su trámite ante la EPS (gestiones y
 * estados), prórrogas y el enlace para que la persona suba sus documentos.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia). Las personas son inventadas.
 */
export const rol = 'USUARIO'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Incapacidades', 'Registrar cada incapacidad, radicarla ante la entidad y seguirla hasta que se pague.', 'Brynex · Módulo')

  e.tarea('incapacidades-lista', 'Ver las incapacidades', { tema: 'Incapacidades', buscar: 'incapacidades lista activas semáforo sin gestión estado entidad' })
  await e.menu({ ruta: '/admin/incapacidades' })
  await e.dice('Incapacidades muestra todas las activas, con su estado, la entidad y cuántos días lleva sin gestión.')
  await e.resaltar(e.page.getByText('Sin gestión', { exact: false }).first().locator('xpath=..'), 2400, 6).catch(() => {})
  e.finTarea()

  e.tarea('incapacidades-registrar', 'Registrar una incapacidad', { tema: 'Incapacidades', buscar: 'nueva incapacidad registrar enfermedad general licencia accidente días fecha diagnóstico' })
  await e.dice('Para registrar una nueva: la cédula, y Brynex trae su contrato y su razón social.')
  await e.clic('button[onclick="abrirModalCrear()"]', { espera: 1000 })
  await e.escribir('#cedulaInput', '9985100012', { retraso: 70 })
  await e.esperar(1500)
  await e.clic({ texto: '9985100012 —' }, { espera: 1200 }).catch(() => {})
  await e.elegir('#contratoSelect', { index: 1 }).catch(() => {})
  await e.dice('Luego el tipo, los días, desde cuándo, y la entidad que la paga.')
  await e.elegir('#tipoIncapacidadSelect', { index: 1 })
  await e.escribir('input[name=dias_incapacidad]', '4')
  await e.page.locator('input[name=fecha_inicio]').fill('2026-10-22')
  await e.esperar(500)
  await e.elegir('#tipoEntidadSelect', { label: 'EPS' })
  await e.esperar(800)
  await e.elegir('#entidadSelect', { index: 1 }).catch(() => {})
  await e.escribir('input[name=diagnostico]', 'J06.9 Infección respiratoria aguda').catch(() => {})
  await e.clic({ rol: 'button', nombre: 'Guardar y Subir Documentos' }, { espera: 2500 })
  await e.dice('Queda registrada como recibida, y se le adjuntan la incapacidad y la epicrisis.', 4000)
  await e.page.keyboard.press('Escape')
  e.finTarea()

  e.tarea('incapacidades-gestion', 'Seguir el trámite ante la EPS', { tema: 'Incapacidades', buscar: 'gestión incapacidad radicar eps portal estado liquidación pagada prórroga' })
  await e.ir('/admin/incapacidades', { esperar: 900 })
  await e.dice('Cada incapacidad tiene su historia: los datos, los documentos y las gestiones.')
  await e.clic('button[onclick="verDetalle(4)"]', { espera: 1800 })
  await e.clic({ texto: 'Gestiones (', exacto: false }, { espera: 1500 })
  await e.dice('Cada llamada, correo o radicado en el portal queda anotado, y mueve el estado: radicada, en liquidación, pagada.', 5200)
  await e.esperar(1200)
  await e.clic('button.tab-btn:has-text("Datos")', { espera: 900 }).catch(() => {})
  await e.dice('Si el médico la extiende, se agrega la prórroga y queda unida a la original.')
  await e.resaltar(e.page.getByRole('button', { name: /Agregar Prórroga/ }).first(), 2400, 6).catch(() => {})
  e.finTarea()
}
