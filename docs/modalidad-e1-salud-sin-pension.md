# Salud sin pensión: de la E-1 de dos pasos a Tipo E - X1

Estado: **resuelto el 13-oct-2026**. Se paga en **una sola planilla E**, por
**Simple**, con el subtipo de cotizante 03/04. La modalidad **Tipo E - X1**
(`-13`) es la que lo implementa.

> **Este documento decía que era imposible, y estaba equivocado.** La conclusión
> anterior —"no existe forma de pagar salud sin pensión para quien no
> califica"— se sostuvo dos meses sobre archivos de prueba defectuosos. Se deja
> escrito el error completo, porque entender por qué se sostuvo tanto vale más
> que la conclusión nueva.

---

## 1. La respuesta corta

Un dependiente que compra **EPS + ARL** (con o sin caja) y no aporta a pensión:

```
planilla E · tipo cotizante 01 · SUBTIPO 04 · días 0/30/30/30
AFP vacío · sin novedades · sin marca de colombiano en el exterior
salud  IBC completo · 0.04000       →  $70.100
ARL    IBC completo · tarifa real   →  $42.700   (obligatoria, ver §4)
caja   IBC 100 o completo · 0.04000 →  $100 o $70.100
```

Subtipo **03** si la persona está exenta por edad (hombre 55+, mujer 50+),
**04** si no. `PilaCotizanteCalculator` ya lo emitía solo cuando el contrato no
trae AFP: para los planes EPS+ARL y EPS+ARL+CCF **no hizo falta código nuevo**.

Liquidado de verdad (sin pagar) sobre HOMENURSE el 13-oct-2026:

| Planilla | Persona | Caja | ARL | Total |
|---|---|---|---|---|
| `1085944575` | Yuly García, 32 | completa | $42.700 | $187.600 |
| `1085944610` | Yesenia Vidal, 36 | **$100** | $42.700 | $115.900 |
| `1085945036` | Yesenia Vidal, 36 | $100 | **$0** (§5) | $72.100 |

Cero errores en las tres. **Pensión no aparece como administradora**: ni una
línea, ni en $0.

## 2. La pared era un solo operador

El mismo archivo, cambiando únicamente el operador:

| | Enlace (89) | Simple (88) |
|---|---|---|
| HOMENURSE `901753748` | `eo.val.2.655` | **limpio** |
| CONSTRUTECH `901603738` | `eo.val.2.655` | **limpio** |

`eo.val.2.655` — *"El cotizante no puede hacer uso del subtipo de cotizante 4.
Puede dirigirse a los canales de atención dispuestos por la UGPP"*.

**Enlace consulta una base de la UGPP; Simple no la consulta.** No es la
empresa, ni el tipo de aportante, ni la EPS, ni la ficha del aportante: los
nueve interruptores de `validacionExtra` son idénticos en las dos razones
sociales y ninguno tiene que ver con pensión.

Barrido de 8 personas reales en Simple (23 a 60 años, hombres y mujeres, CC y
PT, cumplan requisitos o no): **8 de 8 limpias**. Y un plano de 10 personas de
26 a 45 años en un solo archivo: **las 10 limpias**. Simple nunca evalúa la
regla.

## 3. Por qué se creyó dos meses que era imposible

Los archivos de prueba de agosto arrastraban tres defectos a la vez, heredados
de la forma de la E-1:

1. la marca de **colombiano en el exterior**, que dispara `2.067.3`;
2. valores de **pensión sobrantes** con días en cero;
3. la novedad **VAC-LR**, que arrastra `2.489` ("el subtipo 04 no puede
   realizar aportes a Pensión").

Con un archivo limpio —sin exterior, sin novedades, sin residuos de pensión— el
`2.655` aparece solo, y solo en Enlace. Además, **un error de formato
corta el resto de la validación**: dos veces se creyó que una pared había
desaparecido cuando lo que pasaba era que el validador ni siquiera llegaba a
evaluarla. Los offsets se verifican contra los comentarios `// NN campo pos` de
`PlanoPilaTxtService`, que son la única fuente confiable.

## 4. Lo que el subtipo 04 libera, y lo que no

**Libera la pensión**: días 0, IBC 0, tarifa `0.00000`. Pasa limpio.

**No libera la ARL.** El plan "solo EPS" literal no existe:

| Intento | Respuesta del validador |
|---|---|
| sin ARL | `2.066` "el tipo de cotizante 01 está obligado cotizar a Riesgos" |
| ARL 0 días | `2.046`, `2.050` "el IBC de Riesgos no puede ser cero" |
| ARL 1 día | `2.244.3` / `2.198.3` los días e IBC de **salud y riesgos** deben ser iguales |
| ARL < 30 días | `2.484` solo lo permiten las novedades **ING o RET** |
| ARL tarifa 0 sin novedad | `2.058.1` "la tarifa 0.0 de Riesgos no es válida para el tipo cotizante 01" |
| sin caja | `2.046.2`, `2.058` la tarifa de caja debe ser 0.04 |

`2.673` amarra días de salud, riesgos y caja. Salud a 30 arrastra las otras dos
a 30, sin salida.

**La caja sí puede ser simbólica**: IBC 100 → aporte $100, con días 30. Es el
comodín `CCF68` que BryNex ya usaba. Deja la advertencia `2.261` ("el IBC
reportado a Caja debería ser 1750905"), que no bloquea.

## 5. El plan Solo EPS: la ARL va en cero, pero va

Con una **novedad de ausentismo** el validador no solo permite la tarifa cero:
la **exige**. `eo.val.2.447` — *"cuando se presenta novedad de ausentismo la
tarifa de ARL debe de ser cero"*. Con la tarifa real y la novedad marcada, la
rechaza.

```
VAC-LR = 'L' (licencia remunerada) · fechas del mes completo
días 0/30/30/30 · ARL IBC completo · tarifa 0.0000000 → $0
```

Dos trampas de formato, las dos probadas:

- el campo VAC-LR **no acepta `'V'`** (`eo.val.2.C027`); solo `'L'` o `'X'`;
- las fechas van en el **período de cotización** (campo 15 del registro tipo 1,
  el mes vencido), no en el de salud: fuera de ahí sale `eo.val.2.429` dos
  veces, una por fecha.

La **SLN** no sirve: `2.011.EPSExo.SLN` pone en 0 % la tarifa de **salud**, que
es justo lo que se quiere pagar.

> **Lo que le cuesta al trabajador.** Tarifa cero es aporte cero: la ARL queda
> reportada con 30 días y sin un peso, y un accidente en ese período se discute
> con el soporte de que el empleador declaró ausentismo del mes completo. El
> plan se llama "Solo EPS" porque eso es lo que el cliente paga — la ARL
> aparece en la planilla, no en la cobertura.

## 6. El archivo del cliente que disparó todo esto

`1262.13102026170012.txt` (TELEC SYSTEM SAS, planilla E por Enlace, subtipo 09,
EPS `MIN001`, salud al 4 %) parecía probar un esquema nuevo: pagar primero a
ADRES y corregir después. No lo era.

- **Es una descarga de la planilla ya pagada, no el archivo que subieron**:
  `modalidad_planilla = 0` (el estándar y BryNex usan 1), fecha de pago llena y
  radicación vacía.
- El titular **es pensionado de verdad**. Replicado sobre HOMENURSE, a él no le
  sale `2.506` —Enlace lo reconoce en el Reporte de Información de Personas
  Pensionadas— y pasa con subtipo 04 sin ningún rodeo. A Yuly sí le sale.
- El subtipo 09 **no admite salud en absoluto**: `2.490`, `2.067` y `2.116` ("el
  código de la administradora de Salud debe estar vacío"). Lo único que pasa
  limpio con 09 es sin salud.

`MIN001` no es la llave: con MIN001 o con su EPS real, el resultado es idéntico.

## 7. Por qué la E-1 de dos pasos ya no hace falta

El esquema era planilla E de un día + corrección N que sube la salud. Su paso 1
liquidaba y se pagaba; el paso 2 moría en `eo.val.2.746` ("el cambio de subtipo
de cotizante no está permitido"), en los dos operadores y también por el portal.

Sigue siendo cierto. Lo que cambió es que **ya no se necesita**: lo que la
corrección intentaba lograr se obtiene poniendo el subtipo correcto desde la
planilla inicial. La modalidad `-4` queda inactiva, y `PilaCotizanteE1` con
ella.

Lo que **sí** sigue vivo de aquella familia es Tipo E - Extras (`-12`), que
vende un subsistema suelto —solo caja, solo pensión— y para eso sí hacen falta
dos planillas. Ver `PilaCotizanteDosPasos`.

## 8. La modalidad: Tipo E - X1 (`-13`)

**La línea divisoria**: quien **está exento** —pensionado, hombre 55+, mujer
50+, documento CE/PT/PP/PE/PA— va por **Tipo E normal (`0`)**, que liquida en
cualquier operador. **X1 es para quien NO cumple requisitos**, y por eso solo
liquida por Simple.

**Son dos modalidades**, porque `es_tiempo_parcial` es una columna de la
modalidad y una no puede ser dependiente y tiempo parcial a la vez — y porque la
pantalla de planos filtra por modalidad, así que juntas no se podían descargar
por separado:

| | Planes | Cotizante |
|---|---|---|
| **Tipo E - X1** (`-13`) | `SOLO_EPS`, `EPS_ARL`, `EPS_ARL_CCF` | 01 |
| **Tipo E - X1 TP14** (`-14`) | `ARL_CCF` | **51** |

Ninguno de los cuatro planes es nuevo. La `-14` es un tiempo parcial normal
—misma configuración de días que TP(14)— así que toda la maquinaria existente la
entiende sin ayuda: días, piso de medio mínimo, cotizante 51 y subtipo 03/04.

El plan `ARL_CCF` va con el **cotizante 51** porque el 01 no puede ir sin salud
ni sin parafiscales: 16 errores, entre ellos `2.043`, `2.066` (Salud, SENA,
ICBF) y `2.158`. Con subtipo 00 el validador exige la pensión (`2.066`).

| Pieza | Dónde |
|---|---|
| Catálogo | `2026_10_13_200000_crear_modalidad_tipo_e_x1` |
| Constantes y tanda aparte | `TipoModalidad::ID_EX1`, `IDS_TANDA_APARTE` |
| Reglas del plan Solo EPS | `PilaCotizanteEX1` |
| Enganche | una línea en `PilaCotizanteCalculator` |
| Fechas de la novedad VAC | `PlanoPilaTxtService`, campos 89/90 |
| Selección en el formulario | `ContratoController` + `form.blade.php` |
| Solo Simple por API | `PlanillaApiController::operadoresParaTanda()` |
| Cargo de planilla ($9.500) | `PilaCotizanteEX1::otrosPlanilla()` → `CotizadorService` → `FacturacionController` |

**No se mezcla con gente normal en un plano** (`IDS_TANDA_APARTE`): toda la
planilla va a un solo operador y basta un cotizante X1 para que Enlace tumbe la
tanda entera con `2.655`.

### El cargo de planilla

Los cuatro planes llevan un cargo propio —$9.500 por defecto, en la clave de
configuración `otros_planilla_ex1`, cambiable sin desplegar—. El cliente lo ve
como **seguridad social**: suma dentro del total de S. Social del cotizador, no
en la administración, y al facturar aterriza en `facturas.otros`, que es la
bolsa que suma al total junto a `total_ss`. Se prorratea por días igual que la
seguridad social, y en un mes de pura afiliación no se cobra.

**No confundir con el `otros` del tarifario** (`afiliacion_arl_modalidad.otros`):
ese es un reparto de la afiliación —`público − retiro − otros − asesor`— y
meterle este valor descuadraría esa cuenta. Tampoco es `facturas.otros_admon`,
que es la bolsa de administración.

En el lote, el cargo **no se reparte**: es de cada contrato y se suma entero al
suyo, antes de que la bolsa se vuelque sobre el total, para que también cuente
en el reparto de la consignación, el efectivo y el saldo a favor.

## 9. Lo que esto declara, y quién responde

El **subtipo 04 de la Resolución 2388 significa "cotizante con requisitos
cumplidos para pensión"**: alguien que ya completó edad y semanas y por eso
puede dejar de aportar. Para una persona de 23 o 32 años eso no es cierto.

Que Simple no lo verifique no lo vuelve válido. Quien audita después es la
UGPP, con sus procesos de determinación y liquidación oficial de aportes, y el
cobro de la pensión no pagada más intereses y sanción le llega **al aportante**
—la empresa—. El subtipo queda reportado en la planilla y en BDUA, así que es
rastreable.

Por eso la regla `2.655` existe en Enlace y no es un capricho del operador.

**La vía formal**, si se quiere quedar en regla: la PQR de
`docs/pqr-subtipo-4-homenurse.md` y la ruta de escalamiento de
`docs/formato-notificacion-subtipo-4.md`. Si la UGPP reconoce la exención para
un cliente, el `2.655` deja de salir y la planilla liquida también en Enlace.

## 10. Un defecto propio, todavía sin corregir

`PilaCotizanteCalculator` emite **cotizante 1 con subtipo 4** para la modalidad
13 (UPC). La figura propia de la UPC adicional es el **cotizante 32**, donde la
pensión es opcional por diseño y no hay registro externo de por medio. El
subtipo 4 es justo el que Enlace rechaza sin inscripción en la UGPP, así que
esas planillas podrían estar fallando por esto. 11 planos afectados.

## 11. Advertencias que aparecen y no bloquean

`2.261` (IBC de caja simbólico) · `2.380` (aporte de salud de los PT) · `2.556`
(no está en BDUA) · `2.679` (debe legalizar su afiliación al contributivo).
