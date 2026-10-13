# Modalidad E-1: pagar salud sin pensión

Estado: **suspendida** (ago-2026), y **cerrada por el lado técnico** tras una
segunda ronda de pruebas el 13-oct-2026 — ver la segunda mitad del documento.
El código existe y el paso 1 funciona, pero el esquema no se puede completar.

Esto explica por qué, para no volver a recorrer el camino: son dos sesiones de
pruebas contra el validador real de dos operadores.

## Qué se quería

Un dependiente que compra un plan **EPS + ARL** o **EPS + ARL + CCF** sin
pensión. El operador rechaza la planilla directa, así que un cliente de otro
aliado lo resolvía en dos planillas encadenadas y nos pasó su archivo para
replicarlo:

- **Paso 1** — planilla `E`, días `1/0/1/1` (afp/eps/arl/ccf): un día de
  pensión y uno de caja, salud en cero, ARL con su día pero tarifa cero.
  Marca `colombiano en el exterior = X`, novedad `VAC-LR = L` e `ING = X`.
- **Paso 2** — planilla `N` que corrige la anterior: sube **solo la salud** a
  30 días, cambiando el subtipo de cotizante de `0` a `4`.

## Qué funciona

El **paso 1 liquida y se paga** en Simple y en ARUS Enlace. Probado con pagos
reales el 24-ago-2026 (planillas `87700422` y `1084593675`).

## Qué lo bloquea

El paso 2. La corrección necesita el subtipo 4 para que la pensión quede
exenta de la regla de coherencia, y ese subtipo no se puede usar.

| Lo que se intentó | Regla del operador |
|---|---|
| Salud 30 con pensión 1, sin subtipo | `eo.val.2.244` / `2.198` — días e IBC de pensión, salud y riesgos deben coincidir |
| Invocar subtipo 3 o 4 | `eo.val.2.655` — el cotizante no puede usar ese subtipo (registro UGPP) |
| Invocar subtipo 5, 6 o 9 | `eo.val.2.506` — debe estar en el Reporte de Personas Pensionadas |
| Invocar subtipo 11 o 12 (taxista) | `eo.val.2.355` / `2.655` |
| Meter el subtipo en la corrección | `eo.val.2.746` — el cambio de subtipo no está permitido |
| Quitar la pensión en la corrección | `eo.val.2.333` / `2.488` — una corrección solo puede sumar, nunca restar |
| Marcarla como solo novedades | `eo.val.5.012` — no aplica a planillas con valores |
| Cobrar la ARL en la corrección | `eo.val.2.447` + `2.090.10` — la novedad de ausentismo obliga a tarifa cero y debe ir en las dos líneas |
| Otros tipos de planilla (`F`, `X`, `M`, `J`, `A`, `S`, `T`, `H`, `U`) | `F` y `X` derogadas (`1.009`), `M` solo pre-feb-2014 (`1.161`), `J` exige sentencia (`1.179`), el resto repite `2.066` |
| Los flags `planillaUGPP` y `tipoArchivo` | Sin efecto; `tipoArchivo` solo acepta `I` |

Probado por **API de Simple, API de ARUS y el portal web de Simple** — los tres
devuelven lo mismo.

## La conclusión

**Toda exención de pensión está detrás de un registro externo.** No hay forma
de afirmarla desde el archivo. El propio error lo dice: *"puede dirigirse a los
canales de atención dispuestos por la UGPP o al correo contactenos@ugpp.gov.co"*.

Se verificó con una de las 12 personas del archivo del cliente
(CC 1064437945): enviada bajo nuestro NIT, recibe el mismo `eo.val.2.655`. O
sea la habilitación va atada a la persona **y probablemente al aportante**, no
al archivo. El cliente no encontró un truco de formato: hizo un trámite.

## Lo que sí resuelve el caso

Para quien **está exento** —pensionado, hombre 55+, mujer 50+, requisitos
cumplidos ante la UGPP, o documento CE/PT/PP/PE/PA— la planilla **directa**
liquida de una, sin E-1 y sin corrección. Verificado el 24-ago-2026 con un
cotizante de 73 años: planillas `87718511` (ARUS) y `1084594988` (Simple), por
$150.900, con modalidad normal (Dependiente E) y plan EPS+ARL+CCF.

## Para retomarlo

1. Averiguar con el cliente **qué trámite** hizo ante la UGPP: por persona o
   por aportante, por cuál canal, cuánto tarda, si se renueva.
2. Si el trámite es replicable, el contrato necesita una marca de "habilitado
   ante UGPP" para saber a quién se le puede vender el plan.
3. Y ojo: **con la exención registrada ya no hacen falta los dos pasos** — la
   planilla directa basta.

El código vive en `app/Services/PilaCotizanteE1.php` con una sola línea de
delegación en `PilaCotizanteCalculator`. La constante
`OPERADORES_PERMITEN_CAMBIO_SUBTIPO` está vacía a propósito: si algún operador
llega a permitir el cambio de subtipo, se agrega su código PILA ahí.

---

# Segunda ronda (13-oct-2026): se cierra por el lado técnico

Todo lo de abajo se midió contra el validador real de Simple, con un **freno**
en cada archivo —un segundo cotizante con error propio— para que nada pudiera
liquidarse. Ninguna prueba radicó una planilla.

## La pensión no se declara, se recalcula

Es el hallazgo que explica los dos meses anteriores. Al intentar abaratar la
pensión manteniendo la salud completa, el operador cierra cada puerta por
separado:

| Intento | Regla |
|---|---|
| IBC de pensión en cero | `eo.val.2.050` — "El IBC de Pensión no puede ser cero" |
| Tarifa de pensión en cero | `eo.val.2.058` — "las correctas son 0.16" |
| Cotización en cero | `eo.val.2.061` — "**debe ser 280200**" |
| Días 1 de pensión con 30 de salud | `eo.val.2.673` — los días deben ser iguales |

El `2.061` es el que zanja: el operador **calcula** el aporte a pensión a partir
de días e IBC y exige ese número exacto. No hay campo que reportar de menos.

## Los 18 tipos de cotizante de la planilla E

El propio operador los lista en `eo.val.2.005`:

```
01, 12, 15, 18, 19, 20, 21, 22, 30, 31, 32, 40, 51, 54, 55, 62, 68, 71
```

Probados uno por uno con salud 30 días y pensión en cero. Solo cinco se saltan
la pensión, y **ninguno paga salud al 4 %**:

| Tipo | Qué es | Por qué no sirve |
|---|---|---|
| 12 / 19 | Aprendices SENA | `eo.val.2.654` — registro "Reporte de aprendices". El 12 además solo vale para períodos anteriores a 202507 |
| 21 | Estudiante de posgrado en salud y residente | `eo.val.2.577` — el aportante debe estar en la base de "Aportantes con estudiantes de posgrado en salud y residentes". Y la tarifa de salud es **12,5 %** |
| 40 | Funcionario de entidades del Estado | El operador lo trata como Beneficiario UPC: salario básico en cero, salud obligatoria en cero y **valor de UPC mayor a cero** |
| 32 | Beneficiario UPC adicional | Pensión opcional, pero con **tarifa UPC**, no porcentaje sobre el salario |

Los otros trece exigen pensión; 51, 54 y 62 además no pueden aportar salud.

## El tipo de aportante no cambia nada

Se barrieron los 17 tipos por archivo, y **uno de ellos con registro real**
(misión diplomática, aportante y sucursal en 6, verificado porque desapareció
el `eo.val.1.027`). Los errores de pensión salieron **idénticos** a los de
Empleador: `2.046`, `2.673`, `2.198`, `2.016`.

Dos trampas del portal de Simple que costaron dos corridas en falso:

- El `tipoAportanteId` vive en **dos sitios** —el aportante y cada sucursal— y
  el validador lee **el de la sucursal**. Cambiar solo la cabecera no surte
  efecto y el `1.027` sigue diciendo el valor viejo.
- Cada bloque de la ficha guarda con su propio botón.

La ayuda del propio operador ("¿Qué tipo de aportante soy?") deja claro que
ninguna figura es de libre elección: o exigen autorización del Ministerio
(agremiaciones, cooperativas, contrato sindical), o son de una entidad concreta
(prestación humanitaria, servicio social para la paz), o **solo pagan ARL**
(voluntarios, recicladores), o están obsoletas (hogares de bienestar, solo
anterior a marzo de 2014).

## `validacionExtra` no es el truco

La ficha del aportante en Simple trae un bloque con interruptores que no están
en la norma PILA. Leído completo para HOMENURSE:

```
duplicacionPlanilla · exoneradoPagoParafiscal · novedadIngresoRetiro
reemplazaAdministradoraSaludCotizante · reemplazaNombresCotizante
reemplazaValorUpcCotizante · valoresComprobante
tipoComprobantePagoAsistidoId · beneficioCajaCompensacionFamiliar
```

**Ninguno toca la pensión.** Son sustituciones de datos del cotizante, permiso
de planilla duplicada y tipo de comprobante. Con esto queda descartada la
hipótesis de que el servicio que vende Mega Tech viva en la configuración del
aportante.

## El camino que sigue vivo: es administrativo

La **Resolución 1740 de 2019** cambió el procedimiento del subtipo 4: la novedad
ya no viaja por la cadena trámite pensional → Asofondos → Ministerio → RUAF,
sino que **el afiliado la informa directamente al operador de información**.

Y el Consejo de Estado (Sección Segunda, Subsección A, sentencia del 17-mar-2021)
declaró la **nulidad parcial del numeral 8 de la Resolución 3559 de 2018**,
anulando el aparte que obligaba al operador a validar al cotizante contra un
reporte externo — por imponer un requisito que el artículo 17 de la Ley 100 no
establece. Esa es justamente la validación que hoy devuelve el `eo.val.2.655`.

Pendiente de verificar antes de construir encima:

- El articulado oficial de la 1740 (la parte del "el afiliado informa" viene de
  una comunicación de un fondo, no del texto).
- Si la **Resolución 1271 de 2023** reintrodujo alguna validación.
- Lectura de un abogado: es terreno jurídico, no técnico.

El portal de Simple **no tiene** opción para reportar esa novedad, y es
coherente: el trámite es del afiliado, no del aportante. El canal sería
Contáctenos o atención al cotizante.

Pregunta sugerida al operador:

> ¿Cuál es el procedimiento para que un cotizante reporte ante ustedes la
> novedad de subtipo 4, conforme a la Resolución 1740 de 2019? El rechazo
> `eo.val.2.655` remite a la UGPP, pero la validación del operador fue anulada
> parcialmente por el Consejo de Estado (nulidad parcial del numeral 8 de la
> Resolución 3559 de 2018).

## Lo que sí funciona hoy, sin nada de esto

Para quien **está exento** —pensionado, hombre 55+, mujer 50+, requisitos
cumplidos ante la UGPP, o documento CE/PT/PP/PE/PA— la planilla **E directa sin
pensión liquida**, en Simple y en Enlace, sin corrección ni dos pasos.
Verificado el 24-ago-2026 con un cotizante de 73 años: planillas `87718511` y
`1084594988`, $150.900, modalidad normal y plan EPS+ARL+CCF.

## Un defecto propio que salió de paso

`PilaCotizanteCalculator` emite **cotizante 1 con subtipo 4** para la modalidad
13 (UPC). La figura propia de la UPC adicional es el **cotizante 32**, donde la
pensión es opcional por diseño y no hay registro externo de por medio. El
subtipo 4 es justo el que el operador rechaza sin inscripción en la UGPP, así
que las planillas de UPC podrían estar fallando por esto. Sin corregir.

## Los tipos de planilla, ahora sí completos

El primer barrido se quedó corto. Catálogo entero probado: **E, A, B, F, H, I,
J, K, M, N, O, S, T, U, X, Y, Z**. Las que no son la E mueren así:

| Tipo | Qué lo cierra |
|---|---|
| **B** Piso Protección Social | `eo.val.2.067` — el cotizante 65 **no puede aportar a salud**: solo va a BEPS (código 251471), y la salud del Piso es del régimen subsidiado, fuera de PILA. Además `eo.val.1.009`: no es válida según la normatividad vigente |
| **O** Beneficios tributarios | Es la única donde la salud es **opcional** para el cotizante 01, pero `eo.val.1.173` y `2.530` exigen que **aportante y cotizante** estén en la base de la UGPP, y `2.588` manda la salud a **MIN001 - ADRES**, no a la EPS. Es la planilla de las obligaciones que la UGPP determina en fiscalización |
| **Z** Cálculo actuarial | Para pasivos pensionales |
| **F**, **X** | `eo.val.1.009` — derogadas |
| **M** | `eo.val.1.161` — solo períodos anteriores a febrero de 2014 |
| **J** | `eo.val.1.179` — exige seleccionar una sentencia judicial |
| **K**, **Y** | Solo riesgos laborales |
| **A**, **S**, **T**, **H** | Repiten el `eo.val.2.066` de la E |
| **U** | No existe: el operador devuelve respuesta vacía sin crear planilla |

## Cómo se ejerce el derecho (procedimiento, oct-2026)

No es un trámite ante el operador ni ante la UGPP: ocurre **entre el trabajador
y el empleador**.

1. El trabajador **notifica por escrito al empleador** que ejercerá el derecho
   del inciso 2 del artículo 17 de la Ley 100 de dejar de cotizar a pensión.
2. Entrega los **documentos que acreditan el cumplimiento** de requisitos —
   distintos de los que acreditan el inicio del trámite pensional.
3. Declara que son veraces y **mantiene indemne al empleador**.
4. El empleador **marca el subtipo 4** y cesa el aporte a pensión.

Borrador del formato en `formato-notificacion-subtipo-4.md`, junto con la
respuesta a dónde se radica (en ninguna parte: se entrega al empleador) y los
tiempos del camino alterno.

El nudo sigue siendo este: la Resolución 1740 de 2019 dice que **no se puede
exigir** tramitar la solicitud de pensión, pero el operador valida contra el
"Reporte de Información de Personas que están en Trámite de Solicitud de
Pensión" — alimentado por las administradoras vía PISIS, con corte el día 24 de
cada mes. Quien no tramitó no está en el reporte, y sale `eo.val.2.655`. Esa es
justamente la validación que el Consejo de Estado anuló.
