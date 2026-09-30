# BryNex Portales (extensión de Chrome)

Hace los trámites de afiliación de BryNex en los portales de las EPS usando la
sesión que la persona abre en su propio Chrome. Hoy: S.O.S. (novedad de inicio
laboral: consultar, radicar, adjuntar el lado B y bajar el certificado) y Sanitas
(Estado de Afiliación para la conciliación y cambio de empleador por el formulario
web de novedades).

Existe porque el login de S.O.S. pide reCAPTCHA y Google no deja pasar un Chrome
automatizado desde el servidor. La extensión no ve ni guarda claves: la persona
inicia sesión como siempre.

## Instalar (una vez por equipo)

1. Copia esta carpeta `brynex-portales` al equipo.
2. Chrome → `chrome://extensions` → activa **Modo de desarrollador**.
3. **Cargar descomprimida** → elige la carpeta.
4. Recarga BryNex.

## Uso

**Conciliar radicados de S.O.S.:** Afiliaciones → **🩺 Conciliar EPS** → pestaña
**S.O.S.** → escoge la empresa, **Abrir S.O.S.**, inicia sesión ahí (con captcha) y
vuelve a BryNex. La extensión busca en el portal solo a la gente con radicado
abierto y BryNex pone cada radicado como lo dejó S.O.S.; las **devueltas** abren
una tarea con el motivo que dio el portal.

Afiliaciones → radicado de EPS de un contrato con S.O.S. → **🏥 Novedad S.O.S.**
Con Sanitas → **🏥 Radicar Sanitas**: abre el formulario de novedades, la extensión lo
llena y adjunta el formulario (firmado por el trabajador), el robot pulsa Enviar y BryNex guarda el radicado.
La pestaña de Sanitas trabaja de fondo y BryNex sigue al frente; solo se trae la de Sanitas
si pide verificación de Radware o el formulario marca un error.
Después de actualizar la carpeta hay que pulsar **Recargar** en `chrome://extensions`.
El modal pide abrir S.O.S. en otra pestaña; se inicia sesión ahí (con captcha) y
se vuelve a BryNex. Mientras corre el trámite no hay que usar la pestaña de S.O.S.

## Cómo funciona

- `puente.js` corre dentro de BryNex y reenvía los pedidos de la página
  (`window.postMessage`, canal `brynex-portales`) al service worker.
- `background.js` solo acepta pedidos de brynex.co y localhost:8000, busca la
  pestaña de S.O.S. y ejecuta los pasos en ella con `chrome.scripting`.
- BryNex (`SosController`) prepara los datos, entrega el lado B y registra en el
  radicado lo que la extensión trajo.

## Módulo Renta (BryNex Renta)

`renta.js` + `puente-renta.js`: trae la exógena de la DIAN ("Información reportada
por terceros") a BryNex Renta (renta.brynex.co / localhost:8010) con la sesión que la
persona abre en MUISCA. En la ficha de la persona → **Traer de la DIAN**: aceptar las
condiciones de la DIAN, **Abrir MUISCA** (iniciar sesión ahí) y **Traer mi exógena**.

Va en archivos propios (canal `brynex-renta`, cargado con `importScripts`) para poder
armar un paquete **solo de renta** cuando BryNex Renta se abra al público: alguien de
afuera no debe instalar una extensión con permisos sobre los portales de las EPS.
