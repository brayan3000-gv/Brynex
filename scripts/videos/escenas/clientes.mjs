/**
 * Clientes: buscar, crear a alguien desde su cédula (consulta del registro
 * oficial), la ficha, beneficiarios, documentos y claves.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia).
 *
 * La consulta por cédula se muestra con la ÚNICA persona real de los videos,
 * Yuly Paola Garcia Vidal, autorizada por el dueño: su respuesta oficial es la
 * guardada el 21-ago-2026 (la app la encuentra en caché; no sale a internet).
 * Para que la escena la cree desde cero, `antes` quita su ficha y aparta su
 * historia sembrada (se le cambia la cédula solo en esta toma).
 */
export const rol = 'USUARIO'

export const antes = `
  DELETE FROM clientes WHERE cedula = '1143961945';
  UPDATE contratos SET cedula = '9985199999' WHERE cedula = '1143961945';
  UPDATE facturas SET cedula = 9985199999 WHERE cedula = 1143961945;`

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Clientes', 'La ficha de cada persona: sus datos, su seguridad social, beneficiarios, documentos y claves.', 'Brynex · Módulo')

  e.tarea('clientes-buscar', 'Buscar un cliente', { tema: 'Clientes', buscar: 'buscar cliente cédula nombre celular listado base de datos' })
  await e.menu({ ruta: '/admin/clientes' })
  await e.dice('En Clientes está toda la base de personas del aliado. Se busca por cédula, nombre o celular.')
  await e.escribir('input[name=buscar]', 'Mosquera')
  await e.page.keyboard.press('Enter')
  await e.listo()
  await e.esperar(900)
  await e.dice('Cada fila dice la empresa, el estado y desde cuándo está vigente.', 3600)
  e.finTarea()

  e.tarea('clientes-crear', 'Crear un cliente con su cédula', { tema: 'Clientes', buscar: 'nuevo cliente crear registrar cédula ruaf bdua registro oficial eps pensión' })
  await e.ir('/admin/clientes', { esperar: 600 })
  await e.dice('Para crear a alguien, se empieza por la cédula.')
  await e.clic({ rol: 'button', nombre: 'Nuevo Cliente' }, { espera: 700 })
  await e.dice('Brynex revisa si ya existe y consulta el registro oficial de salud y pensión.')
  await e.escribir('input[placeholder="Número de documento"]', '1143961945', { retraso: 80 })
  await e.clic('input[placeholder="Número de documento"] + button', { espera: 1800 })
  await e.dice('Esta es una persona real, que nos autorizó a mostrarla: llegan su nombre, su EPS, su fondo de pensión y el régimen.', 5200)
  await e.resaltar(e.page.getByText('Encontrado', { exact: false }).first().locator('xpath=..'), 3200, 8)
  await e.dice('Si es la persona, se crea con esos datos ya puestos. No hay que digitarlos.')
  await e.clic({ rol: 'button', nombre: 'Sí, crear cliente' }, { espera: 1800 })
  await e.dice('Solo falta completar el celular y el correo, y guardar.')
  await e.escribir('#inputCelular', '3995199999')
  await e.escribir('input[name=correo]', 'yuly.demo@example.com')
  await e.clic({ rol: 'button', nombre: 'GUARDAR' }, { espera: 2200 })
  e.finTarea()

  e.tarea('clientes-ficha', 'La ficha del cliente', { tema: 'Clientes', buscar: 'ficha cliente perfil datos personales eps pensión contratos resumen' })
  await e.dice('La ficha reúne los datos personales, el contacto y la seguridad social.')
  await e.resaltar(e.page.locator('h3:has-text("Seguridad Social")').first().locator('xpath=..'), 2600, 6)
  await e.dice('A un lado, los accesos a beneficiarios, incapacidades, historial, documentos y claves; y abajo, sus contratos.', 4600)
  await e.resaltar(e.page.locator('a.icono-lateral').first().locator('xpath=..'), 2400, 6)
  await e.bajar(e.movil ? 900 : 400)
  e.finTarea()

  e.tarea('clientes-beneficiarios', 'Agregar beneficiarios', { tema: 'Clientes', buscar: 'beneficiarios hijos cónyuge familia eps incluir parentesco' })
  await e.ir('/admin/clientes/1/edit', { esperar: 800 })
  await e.dice('Los beneficiarios, como los hijos o el cónyuge, se ven y se agregan desde la ficha.')
  await e.clic({ texto: 'Benefic.', exacto: true }, { espera: 1500 })
  await e.esperar(1800)
  e.finTarea()

  e.tarea('clientes-documentos', 'Documentos y claves de la persona', { tema: 'Clientes', buscar: 'documentos cédula formularios soportes claves portales eps caja contraseña' })
  await e.ir('/admin/clientes/1/edit', { esperar: 800 })
  await e.dice('En Documentos se guardan la cédula, los formularios y los soportes de la persona.')
  await e.clic({ texto: 'Docum.', exacto: true }, { espera: 1500 })
  await e.esperar(1500)
  await e.ir('/admin/clientes/1/edit', { esperar: 800 })
  await e.dice('Y en Claves, los usuarios de los portales de su EPS o su caja, a la mano del equipo.')
  await e.clic({ texto: 'Claves', exacto: true }, { espera: 1500 })
  await e.esperar(1800)
  e.finTarea()
}
