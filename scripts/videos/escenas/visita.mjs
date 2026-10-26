/**
 * Cobro en visita: la pantalla pensada para el celular, con la que se cobra
 * en la calle o en la empresa. Solo en formato celular: es donde se usa.
 * Cuenta: Daniela Restrepo (rol usuario, ficticia).
 */
export const rol = 'USUARIO'
export const formatos = ['movil']

export default async function (e) {
  await e.ir('/dashboard', { esperar: 200 })
  await e.titulo('Cobro en visita', 'Cobrar en la calle o en la empresa, desde el celular, y entregar el recibo.', 'Brynex · Módulo')

  e.tarea('cobro-visita', 'Cobrar en una visita, desde el celular', { tema: 'Cobros', buscar: 'cobro en visita calle celular moto cobrar recibo efectivo transferencia' })
  await e.menu({ ruta: '/visita' })
  await e.dice('Cobro en visita está hecho para el celular: arriba, lo que llevas cobrado hoy en efectivo y por transferencia.')
  await e.dice('Se busca al cliente por su nombre o su cédula.')
  await e.escribir('input[type=search]', 'Zapata')
  await e.esperar(1800)
  await e.dice('Aparece lo que debe; se cobra en efectivo o por transferencia y se le muestra su recibo.', 4600)
  e.finTarea()
}
