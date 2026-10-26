/**
 * El video comercial: las bondades de Brynex en unos 2½ minutos. Tarjetas
 * animadas (dibujadas en la página) intercaladas con tomas reales de la demo.
 * Se graba en escritorio; armar.mjs compone la versión vertical para redes.
 * Cuenta: Carolina Andrade Méndez (superadmin del aliado demo, ficticia).
 *
 * Cada afirmación tiene respaldo en el código o en lo ya probado en producción
 * (ver README → «Qué afirma el comercial»). La factura electrónica por API de
 * Dataico NO se menciona: está bloqueada por Dataico (401 de numeración).
 * Lo que la demo no puede mostrar sin conexión (la afiliación por API, el bot de
 * WhatsApp) se muestra hasta donde llega y se dice.
 */
export const rol = 'SUPERADMIN'

// Como en Clientes: Yuly (la persona real autorizada) se crea desde su cédula, y
// Camila (inventada) no tiene contrato todavía.
export const antes = `
  DELETE FROM clientes WHERE cedula = '1143961945';
  UPDATE contratos SET cedula = '9985199999' WHERE cedula = '1143961945';
  UPDATE facturas SET cedula = 9985199999 WHERE cedula = 1143961945;
  INSERT INTO clientes (id, aliado_id, cod_empresa, tipo_doc, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
    genero, fecha_nacimiento, celular, correo, departamento_id, municipio_id, direccion_vivienda, barrio, eps_id, pension_id, created_at, updated_at)
  VALUES (900, 100, 4, 'CC', '9985100099', 'CAMILA', 'ANDREA', 'ROJAS', 'PÉREZ', 'F', '1996-04-12', '3995100099', 'camila.demo@example.com',
    76, 76001, 'Carrera 28 # 72-15', 'El Vergel', 2, 9, GETDATE(), GETDATE());`

export default async function (e) {
  await e.ir('/dashboard', { esperar: 400 })
  e.tarea('comercial', 'Brynex en dos minutos', { tema: 'Conoce Brynex', buscar: 'comercial presentación bondades afiliaciones automáticas api validaciones' })
  await e.tarjeta({ eti: 'Brynex', t: 'Tu seguridad social, en orden', sub: 'La plataforma de los aliados de seguridad social en Colombia.', ms: 2600 })
  await e.dice('¡Hola! Soy Avi, y te presento Brynex.', 2600, { espera: true })
  await e.quitarTitulo()

  await e.tarjetaHablada({ eti: 'El día a día de un aliado', t: 'Mucho trabajo a mano',
    items: ['Afiliar en cada portal', 'Digitar cédulas', 'Pagar planillas', 'Cuadrar la caja', 'Perseguir incapacidades'] },
  'Afiliar en cada portal, digitar datos, pagar planillas, cuadrar la caja y perseguir incapacidades: todos los días, a mano.')

  // ── La cédula trae los datos del registro oficial (persona real, autorizada) ──
  await e.ir('/admin/clientes', { esperar: 700 })
  await e.dice('Con Brynex, escribes una cédula y consulta el registro oficial: nombre, EPS y fondo de pensión, sin digitar nada.')
  await e.clic({ rol: 'button', nombre: 'Nuevo Cliente' }, { espera: 600 })
  await e.escribir('input[placeholder="Número de documento"]', '1143961945', { retraso: 60 })
  await e.clic('input[placeholder="Número de documento"] + button', { espera: 2200 })
  await e.resaltar(e.page.getByText('Encontrado', { exact: false }).first().locator('xpath=..'), 2600, 8).catch(() => {})

  // ── El contrato se cotiza solo ──
  await e.ir('/admin/contratos/create?cedula=9985100099', { esperar: 900 })
  await e.dice('El contrato calcula cada aporte y el total del mes al instante, con las tarifas del aliado.')
  await e.elegir('#sel_rs', { label: 'SERVICIOS INTEGRALES DEMO S.A.S.' })
  await e.esperar(500)
  await e.elegir('#sel_modalidad', { index: 1 })
  await e.esperar(500)
  await e.elegir('#sel_plan', { label: 'EPS + ARL + AFP + CCF' })
  await e.escribir('#inp_salario', '1850000')
  await e.page.keyboard.press('Tab')
  await e.resaltar(e.page.getByText('Cotizacion', { exact: false }).first().locator('xpath=ancestor::div[1]'), 2400, 6).catch(() => {})

  // ── Afiliaciones automáticas ──
  await e.tarjetaHablada({ eti: 'Afiliaciones automáticas', t: 'Sin entrar a cada portal',
    items: ['ARL Sura por API', 'ARL Colmena por API', 'Novedades en las principales EPS', 'Cajas de compensación'] },
  'La ARL se afilia por API con Sura y Colmena, y las novedades en los portales de las EPS y las cajas se automatizan.')
  await e.ir('/admin/afiliaciones', { esperar: 900 })
  await e.dice('Desde cada radicado, un clic: Brynex arma los datos del trabajador y lo afilia. Esta demo no tiene conexión con la ARL.')
  await e.clic('tbody tr:first-child button.badge-estado.badge-pendiente', { espera: 1300 })
  await e.clic('#btnAfiliarApi', { espera: 2200 })
  await e.page.keyboard.press('Escape')

  // ── Planillas PILA ──
  await e.tarjetaHablada({ eti: 'Planillas PILA', t: 'Liquidar sin subir archivos',
    items: ['Liquidación por API con Enlace y Simple', 'Plano TXT para cualquier operador', 'Excel de Asopagos, Mi Planilla y Aportes en Línea', 'Soportes de pago que se descargan solos'] },
  'La planilla PILA se liquida por API con Enlace y Simple; para los demás operadores, el plano sale listo.')
  await e.ir('/admin/planos', { esperar: 900 })
  await e.clic('button[onclick="toggleRs()"]', { espera: 600 })
  await e.clic({ texto: 'SERVICIOS INTEGRALES DEMO S.A.S.' }, { espera: 2200 })
  await e.dice('Cada mes, la planilla de cada razón social ya viene armada, con sus novedades.', 3600)

  // ── Validaciones ──
  await e.tarjetaHablada({ eti: 'Validaciones', t: 'El error se ataja antes de que cueste',
    items: ['Cédula contra el registro oficial', 'Reglas PILA antes de pagar', 'Salario mínimo y nivel de riesgo', 'Caja cuadrada al peso', 'Radicados con semáforo', 'Auditoría de cada cambio'] },
  'Brynex valida antes de pagar: la cédula, las reglas PILA, el salario mínimo, el riesgo y cada peso de la caja.')

  // ── Facturación y caja ──
  await e.ir('/admin/facturacion/empresa/3', { esperar: 900 })
  await e.dice('Facturar el mes de una empresa es marcar y cobrar.')
  await e.clic('#chkAll', { espera: 400 })
  await e.clic('#btnFacturarSel', { espera: 2000 })
  await e.page.keyboard.press('Escape')
  // La caja de Daniela (usuario 3, la que cobró hoy), no la de la jefa, que recibe las entregas de cada día.
  await e.ir('/cuadre-diario?usuario_id=3', { esperar: 900 })
  await e.dice('Y la caja del día se arma sola: lo facturado, el efectivo y lo consignado, por canal.', 4000)

  // ── Cobro y relación con el cliente ──
  await e.tarjetaHablada({ eti: 'Cobro y clientes', t: 'Del cobro a la conversación',
    items: ['Cobro en visita desde el celular', 'Préstamos y anticipos', 'Portal para cada empresa', 'Página web con cotizador', 'WhatsApp con asistente de IA', 'Incapacidades hasta el pago'] },
  'Se cobra en la calle desde el celular, cada empresa consulta su portal, y por WhatsApp un asistente de IA atiende a los clientes.')

  await e.tarjeta({ eti: 'Brynex', t: 'Todo tu aliado, en un solo lugar', sub: 'Afiliaciones, contratos, facturación, cobros, planillas e incapacidades.', ms: 1200 })
  await e.dice('Brynex: todo tu aliado en un solo lugar. ¿Lo vemos juntos?', 3400, { espera: true })
  e.finTarea()
  await e.esperar(800)
}
