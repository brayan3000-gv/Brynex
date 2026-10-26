/**
 * El portal de empresas: la empresa cliente entra con su NIT y ve su planilla,
 * sus facturas, sus incapacidades y sus retirados, y pide trámites.
 * Cuenta: Confecciones Lucía Moda S.A.S. (inventada, NIT 998500203).
 */
import { cuentaDe } from '../motor.mjs'

export const rol = 'NADIE'

export default async function (e) {
  const { cedula: nit, clave } = cuentaDe('EMPRESA')
  await e.ir('/login', { esperar: 300 })
  await e.titulo('Portal de empresas', 'Cada empresa cliente consulta lo suyo y pide trámites, sin llamar ni escribir.', 'Brynex · Módulo')

  e.tarea('portal-entrar', 'Cómo entra la empresa', { tema: 'Portal de empresas', buscar: 'portal empresas entrar nit clave empresa cliente acceso' })
  await e.dice('La empresa entra por la misma puerta, con su NIT y la clave que le crea el aliado.')
  await e.escribir('input[name=cedula]', nit, { retraso: 70 })
  await e.escribir('input[name=password]', clave, { retraso: 40 })
  await e.clic('button[type=submit]', { espera: 2200 })
  await e.dice('Ve el resumen de su mes: cuántos trabajadores tiene activos, qué debe y qué pagó.', 4200)
  e.finTarea()

  e.tarea('portal-consultar', 'La planilla, las facturas y las incapacidades', { tema: 'Portal de empresas', buscar: 'portal planilla facturas incapacidades retirados descargar soporte' })
  const abrir = async (texto) => {
    const enlace = e.page.locator('a, button').filter({ hasText: new RegExp(`^\\s*\\S*\\s*${texto}`) }).first()
    if (await enlace.count()) await e.clic(enlace, { espera: 1500 })
  }
  await abrir('Planilla')
  await e.dice('En Planilla, quiénes están en la seguridad social del mes y cuánto aporta cada uno.')
  await e.esperar(1200)
  await abrir('Facturas')
  await e.dice('En Facturas, cada mes pagado, con su recibo.')
  await e.esperar(1200)
  await abrir('Incapacidades')
  await e.dice('Y en Incapacidades, cómo va el trámite de las de sus trabajadores.')
  await e.esperar(1200)
  e.finTarea()

  e.tarea('portal-tramites', 'Pedir un trámite desde el portal', { tema: 'Portal de empresas', buscar: 'portal trámites solicitud ingreso retiro incapacidad empresa pedir' })
  await e.ir('/portal/tramites', { esperar: 1200 })
  await e.dice('Y en Trámites pide ingresos, retiros o incapacidades. Cada solicitud le llega al equipo como una tarea.', 5000)
  const nuevo = e.page.locator('a, button').filter({ hasText: /Nuevo|Ingreso/ }).first()
  if (await nuevo.count()) await e.clic(nuevo, { espera: 1500 })
  await e.esperar(1500)
  e.finTarea()
}
