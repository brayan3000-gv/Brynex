/**
 * La entrada: la puerta, el aviso de datos, el inicio, el menú y salir.
 * Sin sesión al empezar: entra en cámara con la cuenta de Daniela Restrepo
 * (rol usuario, ficticia) del aliado «Seguridad Social Demo».
 */
import { cuentaDe } from '../motor.mjs'

export const rol = 'NADIE'
export const formatos = ['escritorio', 'movil']

// Para que se vea el aviso de datos de verdad: en esta toma la cuenta todavía no lo ha aceptado.
export const antes = "UPDATE users SET acepto_tratamiento_version = NULL, acepto_tratamiento_at = NULL WHERE cedula = '9985000003'"

export default async function (e) {
  const { cedula, clave } = cuentaDe('USUARIO')
  await e.ir('/login', { esperar: 200 })
  await e.tituloFijo('Conoce Brynex', 'Seguridad social para aliados: afiliaciones, contratos, facturación, cobros e incapacidades en un solo lugar.', 'Manual en video')
  await e.esperar(3800)
  await e.quitarTitulo()

  await e.dice('¡Hola! Soy Avi, y te voy a mostrar cómo funciona Brynex.')
  e.tarea('entrar', 'Cómo entrar a Brynex', { tema: 'Primeros pasos', buscar: 'entrar iniciar sesión login cédula contraseña clave ingresar' })
  await e.dice('Se entra con la cédula y la contraseña que te entrega el administrador de tu empresa.')
  await e.escribir('input[name=cedula]', cedula, { retraso: 70 })
  await e.escribir('input[name=password]', clave, { retraso: 45 })
  await e.clic('button[type=submit]', { espera: 1800 })
  e.finTarea()

  e.tarea('aviso-datos', 'El aviso de tratamiento de datos', { tema: 'Primeros pasos', buscar: 'aviso tratamiento datos personales ley 1581 aceptar primera vez' })
  await e.dice('La primera vez, Brynex pide aceptar el aviso de tratamiento de datos personales, como lo exige la ley.')
  await e.bajar(e.movil ? 1200 : 600)
  await e.clic('input[name=acepto]', { espera: 500 })
  await e.clic({ rol: 'button', nombre: 'Aceptar y continuar' }, { espera: 1500 })
  e.finTarea()

  e.tarea('inicio', 'El inicio y el menú', { tema: 'Primeros pasos', buscar: 'inicio tablero menú módulos empresa activa navegar' })
  await e.dice('En el inicio ves la empresa activa y los módulos que puedes usar.')
  await e.resaltar('.dash-modulos', 2400, 8)
  if (e.movil) {
    await e.dice('En el celular, el menú se abre con este botón.')
    await e.clic('#btnHamburguesa', { espera: 1400 })
    await e.bajar(500)
    await e.esperar(900)
    await e.page.keyboard.press('Escape')
    await e.esperar(500)
  } else {
    await e.dice('Arriba está la barra con cada módulo, y en Admin lo de la configuración.')
    await e.resaltar('nav.menu-iconos', 2600, 4)
  }
  e.finTarea()

  e.tarea('salir', 'Cómo salir', { tema: 'Primeros pasos', buscar: 'salir cerrar sesión logout' })
  await e.dice('Para salir, abre el menú con tu nombre y toca Salir del sistema.')
  if (e.movil) {
    await e.clic('#btnHamburguesa', { espera: 900 })
    await e.resaltar('#mobileDrawer button.drawer-item.danger', 2200)
    await e.page.keyboard.press('Escape')
  } else {
    await e.clic('#userDropdown .user-dropdown-trigger', { espera: 700 })
    await e.resaltar('#userDropdown button.user-panel-item.danger', 2200)
  }
  e.finTarea()
  await e.titulo('Cada quien ve lo suyo', 'El menú depende del rol: superadministrador, administrador, usuario, contable o asesor.', 'Roles', 4200)
}
