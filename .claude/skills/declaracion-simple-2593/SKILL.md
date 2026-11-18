---
name: declaracion-simple-2593
description: >
  Presentar el anticipo bimestral del Régimen Simple (Recibo electrónico SIMPLE, formulario 2593)
  de las razones sociales de Brygar en MUISCA: en cero para las que no facturan, y con ingresos,
  descuento por pensión e ICA para BRYGAR y MEGA TRANSPORTES JUAMVI. Incluye el recibo de pago 490
  y el pago por PSE. Actívate cuando el usuario mencione: anticipo bimestral, bimestre, recibo
  electrónico SIMPLE, formulario 2593, 490, declarar en cero, declaración bimensual, RST simple,
  régimen simple, «declarar las razones sociales», «pagar el bimestre», WebFormSimple2593.
---

# Anticipo bimestral del SIMPLE (formulario 2593)

Las sociedades del Régimen Simple presentan un **Recibo electrónico SIMPLE** (2593) por cada
bimestre, en MUISCA. Este skill es el mapa del trámite. El estado de cada empresa va en la tabla
del final: actualizarla al avanzar.

Ver también [[razones-sociales-brynex]] (fichas y checklist de obligaciones),
[[facturacion-electronica-dian]] (representantes y login de MUISCA) y [[permisos-brynex]].

## Reglas antes de empezar

- **Lo que hace la persona, nunca Claude:** la contraseña de MUISCA, la firma electrónica y el
  pago por PSE. Claude deja el formulario lleno y el 490 generado.
- **El 2593 no se corrige.** Las diferencias se ajustan en el bimestre siguiente del mismo año o en
  la declaración anual (260). Revisar los valores contra este skill antes de firmar.
- **Las sociedades lo presentan siempre, aunque salga en cero.** La excepción de 3.500 UVT es solo
  para personas naturales. Sin los seis recibos del año, la DIAN no deja presentar la 260.
- **Va en orden.** Al pulsar «Crear» en un bimestre cuyo anterior no está presentado, la DIAN responde
  «No se encontró el recibo Electrónico Simple del periodo anterior» y abre el formulario vacío
  (visto con Brygar, bimestre 3 de 2026, 17-nov-2026). Se hace B1 → B2 → B3…
- **Tarde no hay sanción**, solo intereses de mora sobre el valor a pagar (en cero, nada). Lo
  peligroso es no pagar un anticipo con valor: más de un mes de mora puede sacar a la empresa del
  SIMPLE (art. 914 E.T.).
- Decisión del dueño (17-nov-2026): de Brygar y JUAMVI se trabaja **2026**; 2025 ya lo hizo el contador.

## Quién va en cero y quién no

| Empresa | Ingresos | Pensión (casilla 45) |
|---|---|---|
| Todas menos las dos de abajo | **0** | **0**: no se declara la pensión de trabajadores (decisión del dueño) |
| BRYGAR SAS (901918923) | solo la **administración** facturada | sí, solo la de los afiliados **directos** de la razón social Brygar (~10) |
| MEGA TRANSPORTES JUAMVI (901856351) | todo lo que factura (Dataico, cuenta propia) | sí |

En cero se deja igual el **panel de ICA**: Valle del Cauca → Cali, CIIU principal de la empresa
y tarifa 10,62 por mil, con ingresos 0. Así lo hizo el contador en todos los recibos en cero de Brygar.

## 1. Entrar a MUISCA

`https://muisca.dian.gov.co/WebArquitectura/DefLogin.faces` → **A nombre de un tercero** → NIT
de la empresa (sin DV), «Cédula de ciudadanía», cédula del representante. **La contraseña, la
casilla de datos personales e «Ingresar» los hace la persona.**

Antes de tocar nada, mirar el encabezado amarillo del tablero: debe decir el nombre de la empresa.
MUISCA admite una sola sesión por navegador, y Chrome a veces cambia el NIT al autocompletar.

Tablero → link `vistaDashboard:frmDashboard:link490` («Recibo electrónico SIMPLE»; con JS:
`document.getElementById('vistaDashboard:frmDashboard:link490').click()` dentro de un
`setTimeout`) → `muisca.dian.gov.co/WebDilIngresoFormSimple2593/` (Angular, tarda ~4 s).

## 2. La pantalla del 2593

Tres secciones:

- **Nuevo borrador**: Año → Periodicidad (Bimestral) → Periodo («3) Mayo - Junio»…) → **Crear**.
- **Consulta - Borradores Definitivos**: recibos firmados que **tienen valor a pagar y aún no se
  pagan**. Botones Descargar, **Pagar**, Otros. Un recibo con plata queda aquí hasta que se paga.
- **Consulta - Presentados**: los ya presentados, con fecha. Un recibo en cero pasa directo aquí al firmar.

Para bajar el PDF hay que pulsar el **ícono** del PDF, por coordenadas. El texto «Descargar» no hace
nada con clic por referencia ni por JS. Los PDF caen en `~/Downloads/<número de formulario>.pdf`.

## 3. El formulario

«Crear» abre `WebFormSimple2593v6/?concepto=inicial&anio=2026&periodicidad=bimestral&periodo=N`
con **«Número no asignado»**: no existe en la DIAN hasta pulsar Guardar. Se puede llenar y revisar
sin dejar rastro. Cada casilla es `input#cs_id_<renglón>`.

**Editables:** 26-35 (ingresos por grupo), 38, 40, 45, 50, 58, 59, 66, 67, 68, 70. El resto lo
calcula la DIAN, incluidos los intereses (54) y el arrastre de pensión (46).

| Casilla (país / exterior) | Grupo | Qué es |
|---|---|---|
| 26 / 27 | Grupo 1 | tiendas pequeñas, minimercados, peluquería |
| **28 / 29** | **Grupo 2** | comercio, servicios técnicos, construcción, industria y **todo lo no incluido en otro grupo** |
| **30 / 31** | **Grupo 3** | expendio de comidas y bebidas, **transporte** (3,4 % en el primer tramo) |
| 32 / 33 | Grupo 3 «sic» | servicios profesionales, consultoría, profesiones liberales |
| 34 / 35 | Grupo 6 | CIIU 4665, 3830, 3811 |

Lo que ha usado el contador:

- **JUAMVI**: casilla **30**, CIIU 4923, tarifa consolidada 3,4 %.
- **Brygar 2026-B2**: casilla **28**, tarifa 1,6 %, CIIU 8211 (actividad 2: 8299).
- **Brygar 2025-B5**: lo puso en la 30, con 3,1 %. Para 2026 se sigue con la **28**.

**Pensión (45 → 48):**
- 45 = aporte **a cargo del empleador**, pagado en el bimestre. Tiene que estar pagado antes de presentar.
- 46 = la casilla 48 del bimestre anterior: se llena sola si el anterior está presentado.
- 47 = 45 + 46, limitado al componente SIMPLE nacional (casilla 44).
- 48 = el sobrante, que pasa al bimestre siguiente.
- Ejemplo: Brygar B2 2026 puso 420.000, descontó 5.000 y le sobraron 415.000.

**ICA (hoja 2):** `select#dpto` y `select#mupio` (Angular: cambiar `selectedIndex` y disparar
`change`) → botón `add` → panel del municipio → «AGREGAR ACTIVIDAD» → 91 CIIU (select), 92
ingresos, 93 tarifa por mil (Cali 10,62). El ICA se paga dentro del mismo 490: en Brygar B2, de
$15.000 de anticipo, $10.000 eran ICA.

## 4. Guardar y presentar (LALA CONFECCIONES 2026-B5, 17-nov-2026)

1. Menú naranja → **Guardar**. Si la sección de municipios está abierta, sale «Antes de guardar,
   cierre la sección de información por Municipios y Distritos y ubíquese en la sección de
   Liquidación anticipo…»: colapsar «Información por Municipio y Distritos», abrir «Liquidación
   anticipo impuesto unificado Régimen Simple» y volver a Guardar.
2. «El borrador fue guardado»: ya tiene número (25936616716013) y el menú suma **PDF** y un candado,
   **«Presentar»**.
3. **Presentar lo pulsa la persona, o Claude solo con su visto bueno explícito para ese recibo**: el
   permiso de la sesión bloquea el clic de Claude sin esa autorización, por ser un envío a la DIAN.
4. Al pulsar Presentar sale una ADVERTENCIA: hay que generar el 490 con «Pagar» y pagarlo el mismo día
   para que cuente. → **Confirmar** → «Documento guardado» / «El borrador definitivo fue guardado».
   **No pide firma ni clave.**
5. El recibo pasa a «Consulta - Borradores Definitivos». En cero, «Pagar» responde «No hay valores
   monetario…»: no hay 490. La Resolución 57 de 2019 (art. 2) dice que el recibo «quedará formalizado
   con la realización del pago, cuando resulten valores a cargo»: en cero no hay pago que hacer. Los de
   Angela de la mañana ya salían en «Presentados» con fecha del día. Los de la tarde (ELITES, ELITEX)
   pasaron solos a «Presentados» esa misma noche (vistos a las 21:00 del 17-nov-2026, con la casilla 81
   = fecha del día). **Volver a mirar unas horas después y bajar el PDF ya presentado** (el del
   definitivo dice «490 asociado: 0» y no sirve de soporte).

**Varios bimestres seguidos** (LALA EXPRESS B2→B5, 17-nov-2026): abrir cada uno directo por
`WebFormSimple2593v6/?concepto=inicial&anio=2026&periodicidad=bimestral&periodo=N`. Desde el segundo
sale «No se pudo obtener información de las declaraciones anteriores» porque el anterior sigue en
definitivo: se cierra y se sigue, porque en cero no hay nada que arrastrar. Los vencidos sacan al
Guardar un «Mensaje de Extemporaneidad» («debe incluir un valor de sanción»): el 2593 no tiene casilla
de sanción y en cero no aplica → Confirmar. Si se pulsa Cerrar en vez de Confirmar, la sección de
municipios se vuelve a abrir y hay que cerrarla de nuevo. Al cerrar la sección de municipios con
JS no funciona: clic real en el título «Información por Municipio y Distritos». Todo lo demás (ICA,
Guardar, Presentar, Confirmar) sí funciona por JS.

Para el panel de ICA: `select#dpto` → «Valle del Cauca», `select#mupio` → «Cali» (índice 9), botón
`add`, desplegar el panel «Valle del Cauca/Cali» → «Discriminación Actividades Gravadas» → «+»
(AGREGAR ACTIVIDAD) → desplegar «Actividad 1» → `select#cs_id_91` con el CIIU del RUT, 92 = 0. La
tarifa 93 (10,62) la pone la DIAN. El CIIU sale del RUT en `documentos_cliente` (casilla 46).

## 5. Pagar (490), cuando hay valor

1. En «Borradores Definitivos» → **Pagar**. Abre el recibo de pago con la fecha de hoy, concepto
   «27 - ANTICIPO REGIMEN SIMPLE», valor, **intereses calculados a hoy** y total.
2. Menú naranja (abajo a la derecha) → **Guardar**. Aviso: «debe realizar el pago hoy mismo»; si no,
   se genera otro 490 mañana → **Confirmar** → «Generando recibo» → queda con número (491…).
3. Menú → **PDF** (baja `<número 490>.pdf`) → **Pagar** → «Pago electrónico» → Entidad de Pago → PSE.
   **El banco, el pago y la clave los hace la persona.**
4. PSE dice «Su transacción financiera fue exitosa». **El recibo no pasa a Presentados en el
   momento**: unos 10 minutos después del pago seguía como borrador definitivo. Como el bimestre
   siguiente exige el anterior presentado, **contar con esperar hasta el día hábil siguiente**
   (según el dueño, 17-nov-2026; confirmar cuánto tarda con Brygar B2 y anotarlo aquí). Por eso,
   en una empresa con plata, se paga un bimestre por día; las que van en cero no esperan.

**Definitivos en cero que no pasan a Presentados** (ELITES 2026-B1 a B5, 17-nov-2026): «Pagar» responde
«No hay valores monetario para generar el recibo de Pago», y abrir el formulario
(`WebFormSimple2593v6/?…&periodo=N` carga el definitivo existente) solo ofrece PDF y Pagar: no hay firmar
ni presentar. Las bandejas de tareas y alarmas están vacías. Un presentado en cero lleva en la casilla 81
la fecha del 490 asociado; el definitivo, «490 asociado: 0». **Confirmado:** la DIAN los pasa sola a
Presentados unas horas después (ELITES y ELITEX B1–B5, el mismo 17-nov). No hacer nada; **no Anular**.
Al bajarlos de nuevo, Chrome los guarda como «<número> (1).pdf» si quedó el del definitivo en
Descargas: apartar los viejos antes.

Los botones del menú naranja se encuentran por `mattooltip` (Guardar, PDF, Pagar, «Crédito en
línea», Regresar). Mientras el menú se anima, las coordenadas cambian: pulsar por JS con `setTimeout`.

## 5b. Dónde está cada soporte (para completar el checklist de BryNex)

| Obligación en BryNex | Dónde se saca |
|---|---|
| 2593 (anticipo) | `muisca.dian.gov.co/WebDilIngresoFormSimple2593/` → Presentados |
| 260 (anual SIMPLE) | `muisca.dian.gov.co/WebDilIngresoFormSimple260/` → Presentados (misma app Angular, entra directo por URL con la sesión abierta) |
| Qué tiene declarado la empresa | Menú → «Obligación Financiera Contribuyente» → lupa: lista por impuesto y año. En GLOBAL CONTACT solo salen SIMPLE, consumo y ganancias ocasionales de la 260; **ni IVA ni retención** |
| Renovación de matrícula | la cámara de comercio de `documentos_cliente` (cc_cliente = NIT, tipo «Cámara de Comercio», disco local `documentos_razones/{aliado}/{nit}/…`). Las compradas en nov-2026 dicen «Último año renovado: 2026 – Fecha de renovación: 31 de marzo de 2026» |

«Diligenciar / Presentar» con código 260 responde «Servicio no disponible»: no sirve para consultar.

⚠️ **«Obligación Financiera» (`WebRecaudacion`) puede mostrar otra empresa de la misma sesión del
navegador**: cada recarga cae en un nodo distinto. Recargar hasta que el encabezado diga la empresa
correcta y volver a mirarlo después de cada clic (así se leyó CONSTRUTECH el 17-nov-2026). La
empresa nueva puede salir 1 de cada 20 cargas: más rápido con `fetch(location.href)` en bucle hasta
que el HTML traiga el nombre, y leer las filas `tr` del consolidado con `DOMParser`. El detalle
(lupa) es otro postback y puede caer en otra empresa aunque el encabezado diga la correcta: si no
cuadra, quedarse con el consolidado. Una renta omisa no sale como deuda (no hay obligación sin
declaración). Las apps
2593, 260, 300 y 110 sí cambian bien. Mirar siempre el encabezado antes de leer datos.

**Reglas para cerrar el checklist** (aprobadas por el dueño el 17-nov-2026). Se aplican solo después de
mirar la empresa en MUISCA. Cada renglón lleva su razón en `observacion`:

| Caso | Estado | Observación |
|---|---|---|
| IVA anual (300) sin obligación de IVA en «Obligación Financiera» | no_aplica | art. 601 E.T., sin operaciones gravadas |
| Retención mensual (350) sin declaraciones | no_aplica | «Sin movimientos ni nómina en el mes… (art. 606 par. 2 ET)», mismo texto que ya tenía CIT |
| Exógena con ingresos en cero en las 260 | no_aplica | no supera los topes |
| 2593 de un bimestre anterior a la entrada al SIMPLE (no existe en la DIAN) | no_aplica | p. ej. GLOBAL 2023-B2 |
| Cámara del año de constitución | no_aplica | se renueva desde el año siguiente |
| Cámara 2026 con certificado «último año renovado 2026» | pagada, 31-mar-2026 | + el certificado como soporte |
| Cámara de años intermedios | pagada, 31-mar-2026 | la cámara no deja renovar 2026 sin los anteriores; se aclara que la fecha real no sale en el certificado |

Lo que vence en 2027 (B6, 260, IVA y exógena de 2026, retención de nov/dic) se deja pendiente.
Aplicado el 17-nov-2026: GLOBAL CONTACT completa, y la cámara de todas las fichas que tienen certificado.
La cámara sigue pendiente en BRYGAR 2026 (su archivo es de 2025 y escaneado), AVAPP, CIT y JUAMVI (sin
certificado en BryNex).

Para bajar muchos 2593 de una vez: `document.body.style.zoom='0.45'` y quitar `overflow`/`height`
a los contenedores de las tarjetas (`container-cards`, `mat-sidenav-content`, `mat-sidenav-container`)
para que todas quepan en pantalla; luego clic real en cada ícono, con 4 s de espera.

## 5c. Empresas del régimen ordinario (revisión del 17-nov-2026)

- **Apps por formulario** (todas Angular, cambian bien de empresa, se bajan igual que el 2593):
  renta `WebDilIngresoFormRenta110`, IVA `WebDilIngresoFormIva300`, retención
  `WebDilIngresoFormReteFuente350`. La lista completa está en el «Selector de formularios»
  (`WebDilGestorFormularios`, menú ☰ de cualquiera de estas apps). Es la forma confiable de ver qué
  presentó cada empresa; mejor que «Obligación Financiera».
- La renta está en `muisca.dian.gov.co/WebDilIngresoFormRenta110/`: Presentados, se baja igual que
  el 2593. El enlace «Presentar Declaración de Renta» del tablero lleva a la **210** (personas
  naturales), no sirve. `WebDilIngresoForm110` y `WebDilIngresoForm350` dan 404.
- ⚠️ **«Pagar» NO dice si algo está pagado.** Arma el 490 con los valores de la declaración más
  intereses a hoy, aunque ya se haya pagado (con CONSTRUTECH 2022-C2 dio $6.644.000 y estaba al día).
  Solo «No hay valores monetarios» es útil: la declaración salió en cero.
- **Para saber si algo está pagado: «Obligación Financiera»** (`WebRecaudacion`). Agrupa por DEUDA
  VENCIDA / AL DÍA / SALDO A FAVOR con la lupa de cada grupo. Cada recarga puede caer en otro nodo
  con otra empresa de la sesión: **recargar hasta que el encabezado diga la empresa correcta y
  volver a mirarlo después de cada clic**. Con LOGIC CENTER/LOGISTICS se rindió antes de saber esto.
  **Verificar el NIT dentro del PDF** de cada documento antes de subirlo (casilla 5).
- Una DEUDA VENCIDA no siempre es plata que se debe: si la declaración imputó el saldo a favor del
  período anterior (casilla 84 del 300) y la DIAN no lo cruzó, el período anterior queda como SALDO A
  FAVOR y este como deuda por el mismo valor. Comparar con las casillas 84/88/89 del PDF antes de
  pagar; si cuadra, no se paga: se pide a la DIAN la corrección de la imputación.
- Al entrar como tercero, Chrome cambia el NIT si se elige la contraseña guardada (le pasó a Karla):
  escribirla a mano y mirar el encabezado antes de leer nada.
- El ICA anual de Cali es municipal: no aparece en MUISCA; queda pendiente con nota.

Resultado de las tres de Karla: LOGIC CENTER, rentas 2022-2025 en $0 (sanciones de 2022 y 2024
pagadas), IVA y retención no aplican. LOGISTICS HOUSE: **renta AG2024 omisa**; las demás en $0.
CONSTRUTECH: ingresos de $34,7M (2022) y $30,9M (2023). IVA presentado de 2022-C2 a 2024-C1, todo el
21-22 may-2024 con sanción de $471.000 cada uno. 2022-C2 ($3.412.000) está pagado: Obligación
Financiera lo da AL DÍA. Los saldos a favor se encadenan: 2022-C3 $178.000 → 2023-C1 $1.740.000 →
2023-C2 $1.269.000 → 2023-C3 (lo usa en la casilla 84 y queda en $798.000) → 2024-C1 $798.000. La DIAN
no cruzó el paso de 2023-C2 a 2023-C3: muestra **deuda vencida de $1.269.000 en 2023-C3** (obligación
100223016407057) y saldo a favor del mismo valor en 2023-C2. No se debe: hay que pedir la corrección
de la imputación. Queda un saldo a favor real de $798.000 (2024-C1). Desde 2024-C2 sin IVA (ingresos
$0). **Ningún 350** presentado y la DIAN no le tiene obligación de retención: si el contador dice que
se pagaron retenciones, pedirle los formularios. La renta 2024 se presentó tarde (28-jul-2025) sin
sanción. La corrección de la imputación se pidió por PQSR el 17-nov-2026: radicado 2026DP000416552,
código M5D9C6 (anotado en el renglón 335 de BryNex).

**Petición PQSR a la DIAN** (`muisca.dian.gov.co/WebSolicitudesexternas/DefInicioSolicitudNS.faces`,
sin login): Diligenciar solicitud → «Con datos de identificación» → solicitante = representante legal,
«¿Representa un tercero?» Sí → Persona Jurídica con NIT y DV. Para un cruce de cartera:
«Petición de interés particular» → Tributario → temática «Obligaciones con la DIAN» → asunto
«Errores en cuenta corriente», sede del RUT. La sección de la sede se colapsa al elegir temática:
clic en su título. Cloudflare a veces se traba en la pestaña de la extensión y recarga la página (se
pierde todo); en otra pestaña pasó solo. En las listas, clic en la opción (escribir y Enter elige la
primera). Anexos: el `input[type=file]` aparece al pulsar «Cargar archivo» por JS (`button.click()`),
luego `file_upload`, descripción y «Adjuntar archivo a la solicitud», uno por uno. Envía con «Enviar
solicitud» y muestra Nro. de solicitud + código de autenticación: guardarlos. La observación de una
obligación en BryNex admite máximo 500 caracteres.

## 6. Después

- Subir el PDF del 2593 y el del 490 al checklist de la ficha en BryNex (Razones sociales → ficha →
  obligación «Anticipo bimestral SIMPLE» del período) y marcarlo presentado o pagado.
  En lote (así se subieron 18 el 17-nov-2026): abrir cualquier ficha en `brynex.co/brynex/razones-sociales/{id}`
  en Chrome, agregar por JS un `<input type=file multiple>`, cargarle los PDF con `file_upload` y
  mandar cada uno con `fetch` a `POST /brynex/razones-sociales/obligaciones/{obligación}/documento`
  (`_token` del meta csrf, campo `archivo`), con nombre «2593 2026-B2 EMPRESA - <número>.pdf».
  Al subirlo, el renglón pendiente pasa solo a «presentada». Para «pagada»: `PUT` (vía `_method`)
  a `/brynex/razones-sociales/obligaciones/{id}` con `estado`, `valor_pagado`, `fecha_pago` y
  `observacion`. Los ids salen de `brynex_obligaciones` (ficha_id, anio, obligacion_codigo
  `rst_anticipo_bimestral`, periodo).
- La descarga del PDF en MUISCA **necesita un clic real**: `button.click()` por JS no baja nada.
  Y no hacer ráfagas de descargas: después de 16 clics por JS seguidos, Chrome dejó de guardar las
  de muisca.dian.gov.co. La DIAN respondía 200 en `…/formularios/{número}/descargar`, pero no llegaba
  ningún archivo (bloqueo de descargas automáticas múltiples). Recargar la pestaña no lo quita: lo
  destraba la persona en `chrome://settings/content/automaticDownloads`, permitiendo
  muisca.dian.gov.co (hecho el 17-nov-2026). Aun así, bajar de a uno con clic real y esperar ~5 s
  entre clics.
- **Antes de llenar una empresa, mirar «Consulta - Presentados»**: el 17-nov-2026 WORK AT HOME y
  GLOBAL CONTACT ya tenían 2026-B1 a B5 presentados. **Los está presentando Angela** (dicho por el
  dueño, 17-nov): coordinar con ella qué empresas faltan antes de entrar con la clave de cada
  representante.
- Actualizar la tabla de estado de abajo.

## Brygar: de dónde salen los números

- **Ingresos = la administración facturada, por fecha de factura** (decisión del dueño, 17-nov).
- **En BryNex las fechas de FE100 a FE1184 no sirven.** FE100–FE999 (930 facturas, $47,5M) están
  todas con `enviado_at` del 31-may-2026, y FE1001–FE1184 (180, $14,6M) con el 15-ago. Fueron
  cargas de una vez, sin `payload`. FE1 a FE99 no están. Desde FE1185 (27-ago) `payload.invoice.issue_date`
  sí es la fecha real.
- En mayo se facturaron varios meses juntos para ponerse al día. Por eso el plan de Brygar 2026 se
  arma con la fecha real de cada factura, sacada de la DIAN o de Dataico, y **se cuadra contra la
  contabilidad**.
- El contador puso **$962.000 en el bimestre 2** (marzo–abril), aunque Dataico no tiene facturas de
  esos meses. Ese valor sale de su contabilidad: entenderlo antes de hacer el 3.
- Pensión: contratos de las razones sociales 42 (aliado BRYGAR: 9 vigentes y 6 retirados al 17-nov) y
  11282 (aliado BryNex: 1). Solo el 12 % del empleador, de planillas **pagadas** dentro del bimestre.

## JUAMVI

- Factura solo por **Dataico, en una cuenta distinta a la de Brygar**. Las credenciales van en
  Razones sociales → JUAMVI → **Claves** (tipo «Otro portal», entidad «Dataico»: account id y token).
  **No** en la configuración de Dataico de Facturación: hay una por aliado, y JUAMVI está en el mismo
  aliado que Brygar, así que pisaría la que emite las facturas de Brygar.
- Recibos de 2025 del contador: pensión fija de $1.709.000 por bimestre, casilla 30, ICA Cali 10,62.
- No se ha revisado su 2026 en MUISCA (falta la sesión de Julia, CC del representante en la ficha 12).

## Representantes (cada uno firma lo suyo)

| Representante | Empresas |
|---|---|
| Brayan García (1143944458) | BRYGAR |
| Julia Amparo Vidal Pérez | MEGA TRANSPORTES JUAMVI |
| Sebastián (1006168815) | GLOBAL CONTACT, HOMENURSE, STRATEGY, WORK AT HOME |
| Yuly (1143961945) | ELITES CREACIONES, CONSTRUCTORA ELITEX |
| Luis (1130666712) | LALA GROUP, LALA CONFECCIONES, LALA CONSTRUCCIONES, LALA EXPRESS |
| Yesenia (1144132276) | YEVI CONSTRUCCIONES, YEVI EXPRESS |

## Estado 2026 (actualizar al avanzar)

El primer bimestre de cada empresa es el de su constitución (`fecha_constitucion` de la ficha).
El bimestre 5 vence en noviembre según el último dígito del NIT: 1→11, 2→12, 3→13, 4→17, 5→18,
6→19, 7→20, 8→23, 9→24, 0→25.

| Empresa | NIT | Desde | Estado al 17-nov-2026 |
|---|---|---|---|
| BRYGAR | 901918923 | B1 | B1 presentado en 0 (27-ago, form. 25936614962304). B2: definitivo 25936616614861, $962k de ingresos, pensión 420k; **490 n.º 4911202382625 por $11.000 pagado por PSE el 17-nov**, a la espera de que pase a Presentados (en BryNex ya figura pagada). B3–B6 pendientes. Soportes de 2025-B2 a B6 y 2026-B1/B2 subidos a BryNex; el 2025-B1 (25936605170005) no tiene renglón porque la ficha arranca en abril de 2025 |
| MEGA TRANSPORTES JUAMVI | 901856351 | B1 | 2026 sin revisar en MUISCA. Soportes 2024-B4 y 2025-B1, B2, B3, B4, B6 subidos a BryNex (salieron de las Descargas de Brayan); faltan 2024-B5/B6 y 2025-B5 |
| GLOBAL CONTACT | 901709476 | B1 | **Checklist completo al 17-nov** (2593 2023-2026, 260 2023-2025, cámara; IVA, retención y exógena «no aplica»). **B1–B5 presentados el 14-nov** (25936616624019, …624610, …624818, …624950, …625056). Los presentó Angela, en cero con ICA Cali CIIU 8220. Soportes subidos a BryNex. Falta B6 |
| HOMENURSE | 901753748 | B1 | **Checklist completo al 17-nov**: 2593 de 2023-B5 a 2026-B5 y 260 de 2023-2025, todo en cero (CIIU 8699), subidos; IVA y exógena «no aplica». Falta B6 |
| STRATEGY | 901711321 | B1 | **Checklist completo al 17-nov**: 2593 de 2023-B3 a 2026-B5 y 260 de 2023-2025, todo en cero, subidos; IVA, exógena, retención (33 meses) y 2023-B2 «no aplica». No existe app 350 en MUISCA (`WebDilIngresoForm350` da 404). Falta B6 |
| WORK AT HOME | 901716074 | B1 | **B1–B5 presentados en cero el 17-nov** (25936616694771, …694898, …695065, …695176, …695311), con ICA Cali CIIU 6810. Los presentó Angela. **Checklist completo al 17-nov**: 2593 2023-B3 a 2026-B5 y 260 2023-2025 (todo en cero) subidos; IVA, exógena y retención (32 meses) «no aplica». Falta B6 (enero) |
| ELITES CREACIONES | 901904750 | B1 | 2025 completo (2593 B1–B6 y 260 en cero, subidos; IVA, exógena y retención «no aplica»). 2026 B1–B5 en cero **presentados el 17-nov-2026** (25936616700691, …701111, …701261, …701405, …701490), PDF subidos |
| CONSTRUCTORA ELITEX | 901906958 | B1 | 2025 completo (2593 B1–B6 y 260 en cero, subidos; IVA «no aplica»; su exógena 2025 está borrada en BryNex desde ago-2026). 2026 B1–B5 en cero **presentados el 17-nov-2026** (25936616708031, …708631, …708878, …709061, …709797), PDF subidos |
| YEVI CONSTRUCCIONES | 902062049 | B2 | **2026 B2–B5 hechos por Claude el 17-nov** en cero (ICA Cali CIIU 4111): definitivos 25936616720255, …720420, …720549, …720674 |
| YEVI EXPRESS | 902077391 | **B3** | **2026 B3–B5 hechos por Claude el 17-nov** en cero (ICA Cali CIIU 4923): definitivos 25936616721436, …721593, …721744. B2 «no aplica» (rechazado por la DIAN) |
| LALA CONSTRUCCIONES | 902061990 | B2 | **2026 B2–B5 hechos por Claude el 17-nov** en cero (ICA Cali CIIU 4111): definitivos 25936616719000, …719129, …719301, …719451 |
| LALA EXPRESS | 902062055 | B2 | **2026 B2–B5 hechos por Claude el 17-nov** en cero (ICA Cali CIIU 4923): definitivos 25936616717566, …717907, …718066, …718231. A la espera de verlos en Presentados |
| LALA GROUP | 902103391 | B5 | **2026-B5 hecho por Claude el 17-nov** en cero (ICA Cali CIIU 4791): definitivo 25936616719870 (extemporáneo, vencía el 11-nov) |
| LALA CONFECCIONES | 902103184 | B5 | **2026-B5 hecho por Claude el 17-nov** (día del vencimiento): borrador definitivo 25936616716013 en cero, ICA Cali CIIU 1410. A la espera de verlo en Presentados |

**Desde qué bimestre presenta una empresa nueva**: desde el bimestre de su **inscripción en el RUT con
la responsabilidad 47** (casilla 76 «Fecha de registro» del RUT; códigos en la casilla 53), no desde la
fecha del documento privado. MUISCA ofrece los bimestres 1 a 5 a cualquiera: **no filtra**, así que hay
que revisarlo antes de crear (pedido del dueño, 17-nov-2026). Verificado con los RUT y las cámaras:

| Empresa | Constitución (doc. privado) | Matrícula / registro RUT | 47 en el RUT | Primer bimestre |
|---|---|---|---|---|
| LALA EXPRESS | 16-abr-2026 | 30-abr-2026 | sí (47, 42) | **B2** |
| LALA CONSTRUCCIONES | 14-abr-2026 | 30-abr-2026 | sí (47, 48, 42) | **B2** |
| LALA CONFECCIONES | 05-sep-2026 | 07-sep-2026 | sí | **B5** |
| LALA GROUP | 05-sep-2026 | 08-sep-2026 | sí | **B5** |
| YEVI CONSTRUCCIONES | 16-abr-2026 | 30-abr-2026 (RUT sin actualizaciones) | sí (47, 48, 42) | **B2** |
| YEVI EXPRESS | 16-abr-2026 | registro 30-abr-2026, pero **RUT actualizado el 18-jun-2026** (6 folios) | sí desde junio (7, 14, 16, 42, 47, 55) | **B3**: la DIAN rechazó el B2 |

**La DIAN valida el primer bimestre**: al crear uno anterior a la entrada al SIMPLE, sale «Error en el
servidor» y en el detalle «Según su fecha de inscripción en el RUT, no esta obligado a presentar esta
declaración» (YEVI EXPRESS B2, 17-nov-2026). Si la DIAN deja crear el formulario, el bimestre sí
corresponde. Aun así, conviene revisar el RUT antes.

La copia del RUT se baja del tablero de MUISCA («Obtener copia RUT»). La casilla 61 dice la fecha del
último formulario; si es posterior a la 76, el RUT tuvo una actualización y la 47 pudo llegar después.

El primer bimestre de una empresa nueva se crea sin que MUISCA pida el anterior (LALA CONFECCIONES B5).
