---
name: facturacion-electronica-dian
description: >
  Habilitar la facturación electrónica de una razón social de Brygar con la solución gratuita
  de la DIAN: enlace de representante legal leído del buzón central, registrar facturador,
  modo de operación «Software gratuito», certificado de firma gratuito (GSE), cámara de
  comercio, set de pruebas y numeración. Actívate cuando el usuario mencione: facturación
  electrónica, habilitar factura, facturador electrónico, solución gratuita DIAN, catalogo-vpfe,
  enlace de la DIAN, token de la DIAN, representante legal en la DIAN, certificado de firma
  gratuito, GSE, Firmaya, set de pruebas, SETG, numeración de facturación, formulario 1876,
  brynex:enlace-dian, «las empresas de Luis/Sebastián/Karla/Yuly/Yesenia».
---

# Facturación electrónica con la solución gratuita de la DIAN

Las razones sociales del Régimen Simple de Brygar se habilitan con la **solución gratuita de
la DIAN**, no con Dataico (decisión del dueño, 13-nov-2026). Es un trámite por empresa con
unos 15 pasos en cuatro sitios distintos. Este skill es el mapa; el estado de cada empresa
va en la tabla del final, que hay que actualizar al avanzar.

Ver también [[razones-sociales-brynex]] (fichas, documentos) y [[permisos-brynex]].

## Reglas antes de empezar

- **Lo que hace la persona, nunca Claude:** contraseñas (clave del almacén de llaves, clave
  de GSE, iniciar sesión en MUISCA), códigos de un solo uso (Firmaya), captchas que no pasen
  solos y **pagos** (PSE de la cámara). Claude deja todo listo y la persona termina.
- **Pedir visto bueno antes de cada paso que queda registrado:** «Registrar» facturador,
  «Asociar» modo de operación, aceptar los términos de GSE a nombre del representante
  (autoriza datos biométricos y centrales de riesgo), «Finalizar» en GSE, compras.
- **De a una empresa a la vez** con los enlaces de la DIAN: todos llegan al mismo buzón.
- Los datos de la empresa se copian **de la cámara de comercio, no de BryNex**: en BryNex
  hay fichas con dirección o teléfono de otra empresa (LALA CONSTRUCCIONES y LALA EXPRESS
  tenían los de LALA GROUP).

## 0. Requisitos que ya deben existir

| Requisito | Dónde se ve |
|---|---|
| Correo del RUT **personal** del representante = `seguridadsocial.brygar@gmail.com` | BryNex → Razones sociales → 👤 Representantes (tarjeta) |
| El representante en Representantes, con su cédula y RUT personal en **Documentos** | misma tarjeta |
| RUT de la empresa con QR y cámara de comercio | Admin → Configuración → Razones sociales → {id}/editar → pestaña **Archivos** |

Si el correo del RUT personal no es el central, primero se cambia en MUISCA
(Actualiza virtual RUT → Hoja 1 → Cargar documento → **Borrador** → Firmar; la firma la hace
la persona). Después del cambio la DIAN puede tardar en usar el correo nuevo.

## 1. Entrar a la DIAN como representante legal

Portal de habilitación: `https://catalogo-vpfe-hab.dian.gov.co/User/CompanyLogin` →
Empresa → **Representante legal** → tipo CC, cédula del representante, NIT de la empresa
(sin DV) → **esperar ~10 s** a que Cloudflare diga «¡Operación exitosa!» → Entrar.

Si se pulsa antes, la página se recarga vacía y **no manda el enlace**.

La DIAN manda al correo del RUT personal un enlace válido por 60 minutos
(`/User/AuthToken?pk=<id>|<cédula>&rk=<NIT>&token=…`). Para traerlo del buzón:

```bash
php artisan brynex:enlace-dian <cédula> <NIT> --desde="14:12"
```

Espera hasta 90 s (`--esperar`) y deja rastro en la bitácora. Desde el Mac, anteponer
`DYLD_LIBRARY_PATH=/opt/homebrew/opt/openssl@3/lib` y tener el túnel arriba
(`ssh -fN netcup-db`). El endpoint web equivalente es
`GET brynex/razones-sociales/representantes/{id}/enlace-dian?nit=…&desde=…`.

⚠️ Buscar **siempre con NIT**: en el buzón central todos los correos de la DIAN saludan al
mismo correo, así que «menciona al representante» no distingue empresas. Por eso existe
`CorreoDianRepresentante::enlaceDeEmpresa()`, que compara `pk` y `rk` del enlace.

## 2. Registrar facturador

Menú → Registro y habilitación → Documentos electrónicos → «Registrar facturador».

- **Correo para recepción de facturas:** `seguridadsocial.brygar+<NIT>@gmail.com`. El «+NIT»
  llega al mismo buzón pero dice de qué empresa es (el validador de la DIAN lo acepta).
- «Registrar» → modal «¿Está seguro…?» → Aceptar. **Cierra la sesión**: pedir otro enlace.
- Queda en estado **Registrado**.

## 3. Modo de operación: Software gratuito

Documentos electrónicos → Factura electrónica → «Configurar modos de operación»
(`/Contributor/ConfigureOperationModes/<contributorId>`). Viene seleccionado «Software
gratuito» (UAE-Dian) → **Asociar**.

- El menú lateral a veces tapa el botón: usar clic por JS o cerrar el menú.
- En la fila asociada: **⊗ BORRA el modo**, 👁 abre el set de pruebas. No tocar el ⊗.
- Queda «Software gratuito · En proceso». El set de pruebas pide **9 documentos**
  (5 FE, 2 ND, 2 NC) con mínimo 1 FE aceptada; prefijo **SETG**, rango 980000000–985000000.
  Las guías públicas hablan de 4; manda lo que dice el portal.

## 4. Certificado de firma gratuito

Menú → **Ingreso** (`/User/RedirectToBiller`) → `gratis-vpfe-hab.dian.gov.co` → sin
certificado no deja emitir → `/Certificate/RequestCertificate`:

| Campo | Valor |
|---|---|
| Tipo persona / ubicación | Persona jurídica, Valle del Cauca, Cali (verificar en la cámara) |
| Email responsable | `seguridadsocial.brygar+<NIT>@gmail.com` (debe ser **único** por solicitud) |
| Celular | el del representante |
| Representante | nombres, apellidos, CC, número |
| Autoridad | DIAN2025 |
| **Clave del almacén de llaves** | **la inventa y escribe la persona** (≥8, mayúscula, número, especial; igual en las dos casillas). Guardarla en Representantes → tipo «Firma» |

«Generar Solicitud» → «quedó radicada» con un número. GSE manda un correo de activación al
alias +NIT (desde `notificaciones@correogse.com`).

## 5. Cámara de comercio de menos de 30 días

GSE exige el certificado de existencia y representación con **menos de 30 días**. Las
guardadas suelen ser viejas: comprar una nueva ($12.100 en nov-2026).

`https://sedevirtual.ccc.org.co/certificadoElectronico/#/comprar-certificados`
(si queda cargando, recargar) → **«Compra de certificados inmediatos de varias empresas»**
para comprar varias en una sola orden → por cada NIT: buscar, Seleccionar, cantidad 1 en
«Existencia y representación», Aceptar → comprador (cédula del dueño, ya tiene datos) →
correo `seguridadsocial.brygar@gmail.com` → **Ir a pagar UNA sola vez** (pulsarlo dos veces
da «El pago ya ha sido creado» y la orden queda trabada sin cobrar) → el pago PSE lo hace la
persona → Descargar certificados con el código → «validando su pago» dura 1–2 minutos.

Subir la cámara nueva a la ficha (Archivos → Cámara de Comercio): reemplaza sola a la vieja.

## 6. Trámite en GSE

1. Abrir el «Activar cuenta» del correo de GSE (`security-myselfcertification.gse.com.co`).
   La persona crea la clave (12–16, mayúscula, minúscula, número, especial) e inicia sesión
   con el usuario `seguridadsocial.brygar+<NIT>@gmail.com` (Chrome autocompleta otra clave).
2. **Una sola cuenta muestra todas las solicitudes del representante**: basta una sesión
   para todas sus empresas. Se identifican por la hora del correo de GSE y se confirma con el
   NIT del formulario antes de llenarlo.
3. Lápiz «Diligenciar formulario» → aviso → Continuar → **TyC**: bajar ambos textos hasta el
   final, marcar, Guardar y continuar (con visto bueno).
4. **Documentación:** cédula del representante (PDF ambas caras), RUT de la empresa, cámara
   nueva. El CSR ya viene puesto por la DIAN.
5. **Formulario:** Cargo «REPRESENTANTE LEGAL», Dependencia «GERENCIA», Valle del Cauca,
   «SANTIAGO DE CALI», dirección, correo y teléfono **idénticos a la cámara**. Los select son
   React: hay que usar el setter nativo + evento `change`, `form_input` no basta.
6. «Validar Email» → el código llega de **firmaya.co** (no de gse.com.co) → lo pone la persona.
7. **Finalizar** → «Solicitud Radicada Exitosamente». Las preguntas de identidad no salieron
   en ninguna (13-nov): pasó directo a «Identidad Validada – Pendiente de Verificación».
8. Luego «Verificado pendiente por Emisión» y la emisión (~3 días hábiles). El certificado
   aparece solo en la solución gratuita (vigencia 2 años).

## 7. Pendiente de documentar (aún no se ha hecho)

- **Set de pruebas** en la solución gratuita: configurar emisor, cliente y producto de prueba
  y emitir los 9 documentos con prefijo SETG.
- Estado **Habilitado** en el portal.
- **Numeración real** en MUISCA (formulario 1876) y asociar prefijos.

## Estado por empresa

| Empresa | NIT | Representante | DIAN | Certificado (DIAN) | GSE |
|---|---|---|---|---|---|
| LALA GROUP | 902103391 | Luis 1130666712 | Registrado + gratuito | 44e067ee… | 01080061 · Verificado, pendiente por emisión (13-nov) |
| LALA CONSTRUCCIONES | 902061990 | Luis | Registrado + gratuito | 3bf2dcd1… | 01080172 · Identidad validada (13-nov) |
| LALA EXPRESS | 902062055 | Luis | Registrado + gratuito | 64e63c9b… | 01080180 · Identidad validada (13-nov) |
| LALA CONFECCIONES | 902103184 | Luis | Registrado + gratuito | 3eb028b4… | 01080166 · Identidad validada (13-nov) |
| YEVI CONSTRUCCIONES | 902062049 | Yesenia 1144132276 | Registrado + gratuito | d4eee986… | 01080278 · Identidad validada (13-nov) |
| YEVI EXPRESS | 902077391 | Yesenia | Registrado + gratuito | a2c25a58… | 01080295 · Identidad validada (13-nov) |

Las YEVI se radicaron con cámaras del 15-oct (29 días). YEVI EXPRESS tiene la dirección
distinta en RUT (CR 39 43 04) y cámara (CALLE 12 NORTE #6N-8): en GSE se usó la de la cámara.

| ELITES CREACIONES | 901904750 | Yuly 1143961945 | Registrado + gratuito | 979f8f69… | 01080321 · Identidad validada (13-nov, con preguntas) |
| CONSTRUCTORA ELITEX | 901906958 | Yuly | Registrado + gratuito | d07f6436… | 01080323 · Identidad validada (13-nov) |

| GLOBAL CONTACT | 901709476 | Sebastián 1006168815 | Registrado + gratuito | d5a8dae2… | 01080369 · Identidad validada (13-nov) |
| HOMENURSE | 901753748 | Sebastián | Registrado + gratuito | f0265dfa… | 01080384 · Identidad validada (13-nov) |
| WORK AT HOME | 901716074 | Sebastián | Registrado + gratuito | 000dfddf… | 01080390 · Identidad validada (13-nov) |
| STRATEGY | 901711321 | Sebastián | Registrado + gratuito | 53677d1f… | 01080399 · Identidad validada (13-nov) |

Las de Sebastián van en GSE con KR 39 # 43 - 04, Cali, sebastian.reprebyg@gmail.com y
3158204135 (lo de la cámara).

Falta Karla (CONSTRUTECH, LOGIC CENTER, LOGISTICS HOUSE).

## Trampas que aparecieron con Yesenia y Yuly (13-nov)

- **La solución gratuita guarda la sesión de la empresa anterior.** Antes de llenar la
  solicitud del certificado, confirmar que `[name=Code]` sea el NIT esperado; si no, volver a
  pasar por `/User/RedirectToBiller` desde el portal de la empresa correcta.
- **«No fue posible crear el usuario: el correo … ya existe»** (`/account/UserNewEmail`): la
  solución gratuita crea un usuario por empresa y el correo central ya está tomado. Poner
  `seguridadsocial.brygar+<NIT>@gmail.com` y Enviar.
- **GSE tiene una sola sesión por navegador**: si Chrome autocompleta y se entra con otra
  cuenta (otro representante), la solicitud abierta se pierde sin finalizar (los TyC y
  documentos sí quedan guardados). Antes de Finalizar, revisar «SUSCRIPTOR – <nombre>».
- **El código de Firmaya cambia cada vez que se pulsa «Validar Email»** y la ventana se puede
  cerrar sola: sacar siempre el más reciente del buzón (`from:firmaya.co
  to:seguridadsocial.brygar+<NIT>`) y dárselo a la persona; nunca escribirlo Claude.
- **Las 5 preguntas de identidad sí pueden salir** (salieron con Yuly en ELITES, no en ELITEX
  ni con Luis/Yesenia). Las responde solo el representante; 3 intentos.
- Las cámaras guardadas pueden haberse subido recién pero estar **expedidas mucho antes**:
  mirar la «Fecha expedición» del PDF, no la fecha de subida.

## Trampas que aparecieron con Sebastián (13-nov)

- **El nombre de la empresa que precarga GSE desde la DIAN puede traer espacios duros**
  (carácter 160) y el formulario responde «Caracteres permitidos» sin decir cuál. Antes de
  Validar Email, reemplazar `\u00a0` por espacio normal en todos los campos.
- **La sesión de GSE caduca** (y a veces se cierra sola al pulsar Finalizar): si la pestaña
  queda en `/logout`, la persona vuelve a entrar y se revisa el estado en la lista; los
  documentos ya subidos se conservan, pero puede tocar subirlos de nuevo si no se guardaron.
- **Si se cierra justo después de Finalizar**, la solicitud queda «Radicado – Pendiente de
  validación Identidad». No se rehace nada: en la lista, el ícono «Validar identidad» (huella)
  corre la validación y pasa a «Identidad Validada» (STRATEGY, sin preguntas). Si salieran
  las 5 preguntas, las responde el representante.
- **Llamadas largas de JavaScript cortan la conexión con Chrome**: clics con
  `setTimeout(() => btn.click(), 100)` y esperas cortas, en llamadas separadas.
