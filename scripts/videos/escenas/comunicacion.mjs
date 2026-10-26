/**
 * Comunicación: la bandeja de WhatsApp, una conversación, marketing, el
 * generador de publicidad y la página web del aliado.
 * Cuenta: Julián Esteban Rojas (rol admin, ficticio): el rol usuario no entra
 * a Publicidad ni a Página web (sus controladores exigen admin o superadmin).
 *
 * Las conversaciones son inventadas (sembrar.php). La demo no está conectada a
 * WhatsApp ni a ninguna IA: no se envía nada, y el video lo dice.
 */
export const rol = 'ADMIN'

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Comunicación', 'WhatsApp del aliado, campañas, piezas para redes y la página web con su cotizador.', 'Brynex · Módulo')

  e.tarea('whatsapp-bandeja', 'La bandeja de WhatsApp', { tema: 'Comunicación', buscar: 'whatsapp bandeja conversaciones chat esperando míos ia clientes prospectos' })
  await e.menu({ ruta: '/admin/whatsapp/chat' })
  await e.dice('WhatsApp reúne en una bandeja todas las conversaciones del aliado: las que esperan respuesta, las tuyas y las que atiende la IA.')
  await e.dice('Cada chat dice si es cliente, prospecto o excliente.')
  await e.clic('a[href*="/admin/whatsapp/chat/3"]', { espera: 1800 })
  await e.dice('Desde la conversación se asigna a alguien del equipo, se abre la ficha del cliente o se marca atendida.', 4800)
  await e.resaltar(e.page.getByRole('button', { name: /Asignar/ }).first().locator('xpath=..'), 2600, 6).catch(() => {})
  await e.dice('Esta demo no está conectada a WhatsApp: aquí no se envía ningún mensaje.', 3600)
  e.finTarea()

  e.tarea('marketing', 'Marketing: campañas y reactivación', { tema: 'Comunicación', buscar: 'marketing campañas whatsapp listas reactivación retirados envío masivo' })
  await e.ir('/admin/marketing', { esperar: 1000 })
  await e.dice('Marketing tiene las campañas por WhatsApp, la reactivación de los que se retiraron y las piezas para redes.', 4800)
  e.finTarea()

  e.tarea('publicidad', 'Crear una pieza para redes', { tema: 'Comunicación', buscar: 'publicidad pieza imagen redes facebook instagram promo plan aprobar publicar ia' })
  await e.ir('/admin/publicidad/crear', { esperar: 1200 })
  await e.dice('El generador de publicidad arma piezas con la marca del aliado: una promo de precio, una tarjeta de plan o un dato útil.')
  await e.clic({ rol: 'button', nombre: /Promo de precio/ }, { espera: 1200 }).catch(() => {})
  await e.escribir('#fTitulo', 'Plan independiente de octubre')
  await e.escribir('#ppNombrePlan', 'Plan independiente completo').catch(() => {})
  await e.escribir('#ppValor', '189900').catch(() => {})
  await e.esperar(1800)
  await e.dice('Con IA también genera la imagen o el video; esta demo no tiene IA conectada. Cada pieza se aprueba antes de publicarse.', 5600)
  e.finTarea()

  e.tarea('pagina-web', 'La página web del aliado', { tema: 'Comunicación', buscar: 'página web pública aliado editar textos planes preguntas frecuentes leads cotizador' })
  await e.ir('/admin/pagina', { esperar: 1200 })
  await e.dice('La página web del aliado se edita aquí: el título, los planes, las preguntas frecuentes y los leads que llegan.', 5200)
  await e.resaltar(e.page.getByText('Encabezado principal', { exact: false }).first().locator('xpath=ancestor::div[1]'), 2600, 6).catch(() => {})
  e.finTarea()
}
