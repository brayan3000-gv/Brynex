/**
 * Administración del aliado: usuarios y permisos, el centro de configuración,
 * tarifas, razones sociales, la auditoría y las cartas laborales.
 * Cuenta: Carolina Andrade Méndez (superadmin del aliado demo, ficticia; no es
 * de BryNex, así que no ve el Hub BryNex).
 */
export const rol = 'SUPERADMIN'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Administración del aliado', 'Quién entra y qué puede hacer, la configuración, las tarifas y la auditoría.', 'Brynex · Módulo')

  e.tarea('usuarios-roles', 'Usuarios y roles', { tema: 'Administración', buscar: 'usuarios crear usuario rol superadministrador administrador usuario contable asesor desactivar' })
  await e.menu({ ruta: '/admin/usuarios', admin: true })
  await e.dice('En Usuarios está el equipo del aliado, cada uno con su rol: superadministrador, administrador, usuario, contable o asesor.', 5600)
  e.finTarea()

  e.tarea('usuarios-permisos', 'Dar permisos a un usuario', { tema: 'Administración', buscar: 'permisos usuario otorgar módulo planos anular facturas quién lo tiene' })
  await e.dice('Además del rol, se le pueden dar permisos de uno en uno, como pagar planillas o anular facturas.')
  await e.clic('a[href$="/admin/usuarios/3/permisos"]', { espera: 1500 })
  await e.dice('A la derecha de cada permiso se ve quién más del equipo ya lo tiene.', 3600)
  await e.bajar(500)
  await e.esperar(1200)
  e.finTarea()

  e.tarea('configuracion', 'El centro de configuración', { tema: 'Administración', buscar: 'configuración parámetros cuentas bancarias seguros operadores planilla razones sociales asesores niveles' })
  await e.menu({ ruta: '/admin/configuracion', admin: true })
  await e.dice('El centro de configuración reúne lo del aliado: parámetros, cuentas bancarias, seguros, operadores de planilla y razones sociales.', 5800)
  await e.bajar(400)
  await e.esperar(1200)
  e.finTarea()

  e.tarea('tarifas', 'Las tarifas del aliado', { tema: 'Administración', buscar: 'parámetros precios tarifas administración afiliación plan modalidad riesgo salario mínimo' })
  await e.ir('/admin/configuracion/parametros', { esperar: 1300 })
  await e.dice('En Parámetros y precios están las tarifas: la administración y la afiliación por plan, modalidad y riesgo.', 5200)
  await e.bajar(500)
  await e.esperar(1500)
  e.finTarea()

  e.tarea('razones-sociales', 'Las razones sociales', { tema: 'Administración', buscar: 'razón social empresa aportante nit arl caja operador documentos cámara rut' })
  await e.ir('/admin/configuracion/razones-sociales', { esperar: 1200 })
  await e.dice('Las razones sociales son las empresas por las que se cotiza: su NIT, su ARL, su caja, su operador y sus documentos.', 5400)
  await e.clic('a[href$="/razones-sociales/998500101/editar"]', { espera: 1800 })
  await e.bajar(500)
  await e.esperar(1500)
  e.finTarea()

  e.tarea('auditoria', 'La auditoría: quién hizo qué', { tema: 'Administración', buscar: 'auditoría bitácora quién hizo qué cuándo historial cambios accesos' })
  await e.menu({ ruta: '/admin/bitacora', admin: true })
  await e.dice('Nada pasa en silencio: la auditoría registra quién hizo qué, cuándo y desde dónde.')
  await e.clic('button:has-text("Ver JSON")', { espera: 1500 })
  await e.dice('Y cada registro guarda el detalle de lo que cambió.', 3200)
  await e.page.keyboard.press('Escape')
  e.finTarea()

  e.tarea('cartas-laborales', 'Generar una carta laboral', { tema: 'Administración', buscar: 'carta laboral certificado laboral firma empresa trabajador generar pdf' })
  await e.ir('/admin/cartas-laborales', { esperar: 1200 })
  await e.dice('Las cartas laborales se generan aquí: se busca al trabajador y sale la carta con la firma de la empresa.', 5000)
  const buscar = e.page.locator('input[type=text]:visible, input[type=search]:visible').first()
  if (await buscar.count()) {
    await e.escribir(buscar, '9985100001')
    await e.clic(e.page.getByRole('button', { name: /Buscar/ }).first(), { espera: 1800 }).catch(() => {})
  }
  await e.esperar(1800)
  e.finTarea()
}
