/**
 * Contratos: crear uno desde la ficha (razón social, modalidad, plan,
 * entidades, salario y la cotización en vivo), la lista y marcar un retiro.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia).
 *
 * `antes` crea a Camila Andrea Rojas Pérez (inventada, CC 9985100099) sin
 * contrato, para que la escena le haga el primero.
 */
export const rol = 'USUARIO'

export const antes = `
  INSERT INTO clientes (id, aliado_id, cod_empresa, tipo_doc, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
    genero, fecha_nacimiento, celular, correo, departamento_id, municipio_id, direccion_vivienda, barrio, eps_id, pension_id, created_at, updated_at)
  VALUES (900, 100, 4, 'CC', '9985100099', 'CAMILA', 'ANDREA', 'ROJAS', 'PÉREZ', 'F', '1996-04-12', '3995100099', 'camila.demo@example.com',
    76, 76001, 'Carrera 28 # 72-15', 'El Vergel', 2, 9, GETDATE(), GETDATE());`

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Contratos', 'Cada afiliación es un contrato: razón social, modalidad, plan, entidades, salario y tarifas.', 'Brynex · Módulo')

  e.tarea('contratos-crear', 'Crear un contrato', { tema: 'Contratos', buscar: 'nuevo contrato afiliar crear modalidad plan dependiente independiente eps arl pensión caja salario' })
  await e.ir('/admin/clientes/900/edit', { esperar: 900 })
  await e.dice('El contrato se crea desde la ficha de la persona, con Nuevo contrato.')
  await e.clic('a[href*="contratos/create"]', { espera: 1500 })
  await e.dice('Primero la razón social. Luego la modalidad: dependiente, independiente, tiempo parcial, y las demás.')
  await e.elegir('#sel_rs', { label: 'SERVICIOS INTEGRALES DEMO S.A.S.' })
  await e.esperar(700)
  await e.elegir('#sel_modalidad', { index: 1 })
  await e.esperar(700)
  await e.dice('Después el plan, que dice qué cubre: salud, riesgos laborales, pensión y caja.')
  await e.elegir('#sel_plan', { label: 'EPS + ARL + AFP + CCF' })
  await e.esperar(900)
  await e.escribir('#inp_cargo', 'AUXILIAR ADMINISTRATIVO')
  await e.dice('Las entidades: EPS, fondo de pensión, la ARL con su nivel de riesgo y la caja de compensación.')
  await e.elegir('#sel_caja', { label: 'COMFANDI' }).catch(() => {})
  await e.esperar(600)
  await e.dice('Con el salario, la cotización de la derecha calcula cada aporte y el total del mes, al instante.')
  await e.escribir('#inp_salario', '1850000')
  await e.page.keyboard.press('Tab')
  await e.esperar(1200)
  await e.resaltar(e.page.getByText('Cotizacion', { exact: false }).first().locator('xpath=ancestor::div[1]'), 3200, 6)
  await e.dice('Las tarifas de administración y afiliación salen solas de la configuración del aliado.')
  await e.resaltar('#inp_admon', 2000, 6)
  await e.clic('#btn-guardar-contrato', { espera: 2500 })
  await e.dice('Al crearlo, Brynex deja listos los radicados de afiliación ante cada entidad.', 3800)
  e.finTarea()

  e.tarea('contratos-lista', 'Ver todos los contratos', { tema: 'Contratos', buscar: 'contratos lista filtros vigentes retirados razón social modalidad' })
  await e.ir('/admin/contratos', { esperar: 900 })
  await e.dice('En Contratos están todos, con filtros por estado, razón social y modalidad.')
  await e.bajar(e.movil ? 700 : 350)
  await e.esperar(1200)
  e.finTarea()

  e.tarea('contratos-retiro', 'Marcar el retiro de un contrato', { tema: 'Contratos', buscar: 'retiro retirar contrato desafiliar fecha motivo novedad' })
  await e.ir('/admin/contratos/5/edit', { esperar: 900 })
  await e.dice('Para retirar a alguien, se abre su contrato y se marca el retiro.')
  await e.clic({ rol: 'button', nombre: 'Marcar Retiro' }, { espera: 1200 })
  await e.dice('Se elige el motivo y la fecha; Brynex calcula qué se cobra del último mes.')
  await e.elegir('#mr-motivo', { index: 1 })
  await e.esperar(1500)
  await e.resaltar('#modal-retiro form', 2600, 4).catch(() => {})
  await e.clic('#modal-retiro button:has-text("Cancelar")', { espera: 800 })
  e.finTarea()
}
