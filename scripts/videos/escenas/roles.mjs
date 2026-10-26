/**
 * Roles: lo que ve un asesor (solo lectura). Cuenta: Valentina Ortiz
 * Cárdenas (rol asesor, ficticia). Lo que dice sale de entrar con la cuenta
 * (roles.mjs en la carpeta de scripts), no de la lista de permisos.
 */
export const rol = 'ASESOR'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Cada rol ve lo suyo', 'Así ve Brynex un asesor: consulta, sin cambiar nada.', 'Roles')

  e.tarea('rol-asesor', 'Qué ve un asesor', { tema: 'Roles', buscar: 'rol asesor solo lectura consultar permisos qué puede ver' })
  await e.dice('El asesor tiene un menú corto: clientes, empresas, tareas e incapacidades.')
  await e.resaltar(e.movil ? '#btnHamburguesa' : 'nav.menu-iconos', 2600, 4)
  await e.menu({ ruta: '/admin/clientes' })
  await e.dice('Puede consultar; si intenta crear o editar, Brynex no lo deja: no tiene ese permiso.', 4200)
  e.finTarea()

  e.tarea('rol-resumen', 'Los roles de Brynex', { tema: 'Roles', buscar: 'roles superadministrador administrador usuario contable asesor diferencias permisos' })
  await e.titulo('Los cinco roles', 'Superadministrador: todo. Administrador: la operación y lo financiero. Usuario: el día a día. Contable: ver y exportar. Asesor: consultar.', 'Roles', 6500)
  e.finTarea()
}
