/**
 * Planos SS (PILA): la planilla del mes por razón social, descargar el plano
 * para el operador y confirmar el pago. Cuenta: Julián Esteban Rojas (rol
 * admin, ficticio): el rol usuario no tiene planos.ver.
 *
 * La liquidación por API (Enlace y Simple) solo aparece cuando la razón social
 * tiene credenciales del operador; la demo no tiene ni credenciales ni red, así
 * que se dice y no se muestra.
 */
export const rol = 'ADMIN'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Planos de seguridad social', 'La planilla PILA de cada razón social: descargarla para el operador y confirmar el pago.', 'Brynex · Módulo')

  e.tarea('planos-ver', 'Ver la planilla del mes', { tema: 'Planos SS', buscar: 'planos pila planilla seguridad social mes razón social operador días ibc aportes' })
  await e.menu({ ruta: '/admin/planos' })
  await e.dice('En Planos SS se paga la seguridad social. Se elige el mes de pago y la razón social.')
  await e.clic('button[onclick="toggleRs()"]', { espera: 800 })
  await e.clic({ texto: 'SERVICIOS INTEGRALES DEMO S.A.S.' }, { espera: 2500 })
  await e.dice('Salen todos los que entran en la planilla, con sus días, su IBC y el aporte a cada entidad.', 4600)
  await e.bajar(400)
  await e.esperar(900)
  await e.subir()
  await e.dice('Las novedades del mes, como ingresos, retiros e incapacidades, ya vienen marcadas.')
  await e.resaltar(e.page.locator('table tbody tr').first(), 2400, 4).catch(() => {})
  e.finTarea()

  e.tarea('planos-descargar', 'Descargar el plano para el operador', { tema: 'Planos SS', buscar: 'descargar plano txt pila excel operador enlace simple asopagos mi planilla aportes en línea soi' })
  await e.dice('Con Descargar plano sale el archivo PILA, que sirve para cualquier operador, o el Excel de cada uno.')
  await e.clic("button[onclick=\"validarCompatibilidadYAbrir('descarga')\"]", { espera: 1500 })
  await e.resaltar(e.page.getByText('Descargar Txt para todos los operadores', { exact: false }).first(), 2600, 8).catch(() => {})
  await e.dice('Con Enlace y Simple, además, Brynex liquida la planilla por API, sin subir archivos. Esta demo no tiene conexión con el operador.', 6200)
  await e.page.keyboard.press('Escape')
  e.finTarea()

  e.tarea('planos-pago', 'Confirmar el pago de la planilla', { tema: 'Planos SS', buscar: 'confirmar pago planilla número operador valor banco soporte' })
  await e.dice('Cuando se paga, se confirma con el operador, el número de planilla, el valor y la cuenta de donde salió.')
  await e.clic("button[onclick=\"validarCompatibilidadYAbrir('pago')\"]", { espera: 1500 })
  await e.elegir('#pago-operador', { label: 'Simple' })
  await e.escribir('#pago-numero', '99850012345')
  await e.elegir('#pago-banco', { index: 1 })
  await e.esperar(600)
  await e.clic('#btn-confirmar-pago', { espera: 2800 })
  await e.dice('La planilla queda pagada, y cada persona puede descargar su soporte.', 3800)
  e.finTarea()
}
