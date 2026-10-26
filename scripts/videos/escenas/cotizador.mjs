/**
 * La página pública del aliado y su cotizador «Arma tu plan»: el visitante
 * elige su caso, ve el precio y deja sus datos; el lead entra a Prospectos.
 * Sin sesión. Los datos del visitante son inventados (celular 3995…).
 * El botón de WhatsApp de la página no se toca: abriría wa.me.
 */
export const rol = 'NADIE'

// Sin dirección no hay mapa de Google embebido: nada intenta salir del Mac.
export const antes = "UPDATE aliados SET direccion = '' WHERE id = 100"

export default async function (e) {
  await e.ir('/aliado/demo', { esperar: 1200 })
  await e.titulo('La página del aliado', 'Su sitio web con el cotizador: el cliente arma su plan, ve el precio y deja sus datos.', 'Brynex · Módulo')

  e.tarea('pagina-publica', 'La página web pública del aliado', { tema: 'Página y cotizador', buscar: 'página web pública aliado sitio planes servicios preguntas frecuentes' })
  await e.dice('Cada aliado tiene su página web, con su marca, sus servicios y sus planes.')
  await e.bajar(700, 16)
  await e.esperar(900)
  await e.bajar(700, 16)
  await e.esperar(900)
  e.finTarea()

  e.tarea('cotizador-publico', 'Cotizar en línea con Arma tu plan', { tema: 'Página y cotizador', buscar: 'cotizador arma tu plan cotizar precio independiente empleado riesgo salario en línea' })
  await e.dice('En Arma tu plan, el visitante elige su caso: empleado, independiente, tiempo parcial o solo ARL.')
  await e.clic({ rol: 'button', nombre: 'Independiente', exacto: true }, { espera: 1200 })
  await e.dice('Su nivel de riesgo y su ingreso, y el precio sale al instante, con lo que incluye.')
  await e.clic({ rol: 'button', nombre: 'I', exacto: true }, { espera: 700 }).catch(() => {})
  await e.clic({ rol: 'button', nombre: '$2 M', exacto: true }, { espera: 1800 }).catch(() => {})
  await e.esperar(1500)
  e.finTarea()

  e.tarea('cotizador-lead', 'Dejar los datos para afiliarse', { tema: 'Página y cotizador', buscar: 'lead prospecto datos contacto afiliarme formulario página cotización' })
  await e.dice('Si le sirve, deja su nombre y su celular. Ese prospecto le llega al aliado en Brynex, listo para llamarlo.', 5400)
  await e.clic({ rol: 'button', nombre: 'Cotizar este plan' }, { espera: 1500 }).catch(() => {})
  const nombre = e.page.locator('input[name=nombre]:visible, input[placeholder*="ombre"]:visible').first()
  if (await nombre.count()) {
    await e.escribir(nombre, 'Andrea Lucía Morales')
    await e.escribir(e.page.locator('input[name=celular]:visible, input[type=tel]:visible').first(), '3995300099')
    await e.esperar(1200)
  }
  e.finTarea()
}
