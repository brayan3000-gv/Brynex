---
name: facturacion-brynex
description: >
  Lógica de negocio del módulo de Facturación de Brynex. Actívate cuando el usuario
  mencione: factura, facturación, cobro mensual, mora, anticipo, distribución de factura,
  FacturacionController, cálculo de administración, generar factura, estado de factura.
---

# Skill: Facturación Brynex

## Arquitectura del Módulo

```
FacturacionController.php (~167KB)   ← Controlador principal
├── Generación de facturas por mes/aliado
├── Cálculo de administración por contrato
├── Aplicación de anticipos
├── Cálculo de mora
├── Distribución de cobros (encargado / asesor)
└── Exportación Excel / PDF

Modelos relacionados:
├── Factura.php          ← Modelo central
├── Contrato.php         ← Fuente de datos del cotizante  
├── Anticipo.php         ← Anticipos aplicables a facturas
├── Abono.php            ← Pagos parciales
├── Consignacion.php     ← Comprobantes bancarios
└── MoraClienteService   ← Servicio de cálculo de mora
```

## Estructura de la Tabla `facturas`

Columnas clave:
| Columna | Tipo | Descripción |
|---|---|---|
| `aliado_id` | bigint | FK al aliado propietario |
| `contrato_id` | bigint | FK al contrato del cotizante |
| `empresa_id` | bigint | FK a la empresa |
| `mes` | int | Mes de facturación (1-12) |
| `anio` | int | Año de facturación |
| `administracion` | decimal | Valor de administración |
| `admon_asesor` | decimal | Parte del asesor |
| `valor_planilla` | decimal | Valor planilla seguridad social |
| `mora` | decimal | Mora acumulada |
| `anticipo_aplicado` | decimal | Anticipo descontado |
| `otros_ingresos` | decimal | Otros cobros adicionales |
| `estado` | string | `pendiente`, `pagada`, `vencida` |
| `dist_encargado` | decimal | Distribución al encargado |
| `fe_marcada` | boolean | Marcada para facturación electrónica |
| `deleted_at` | timestamp | Soft delete |

## Reglas de Negocio

1. **Generación**: Una factura por contrato por mes. No duplicar.
2. **Administración**: Calculada desde `contrato.administracion` (puede variar por plan y modalidad)
3. **Anticipo**: Se aplica automáticamente si `anticipo.saldo > 0` al generar la factura
4. **Mora**: Calculada por `MoraClienteService` — depende de días de atraso y config del aliado
5. **Distribución**: `dist_encargado` + `admon_asesor` debe ser ≤ `administracion`
6. **Tiempo Parcial**: El IBC se calcula proporcional a `dias_tiempo_parcial / 30`
7. **IVA**: única fuente de verdad en `App\Services\IvaService`. Si el cliente
   pertenece a una empresa (`clientes.cod_empresa`), **manda `empresas.iva`**: en
   'SI' cobra IVA a todos sus clientes y en 'No' los exime a todos, sin importar
   `clientes.iva` — la cuenta de cobro se le emite a la empresa. Solo cuando el
   cliente no tiene empresa decide su propia marca. La base gravada es `admon + admon_asesor + afiliacion` de esa factura: en planilla
   es solo la administración, en afiliación pura es solo el costo de afiliación, y en
   I ACT primer mes son ambas. El seguro y la seguridad social no se gravan.
   Nunca recalcular el IVA a mano en un controlador nuevo — usar `IvaService::deFactura()`.

## Patrones de Query Frecuentes

```php
// Facturas de un aliado por mes
Factura::where('aliado_id', $alidoId)
    ->where('mes', $mes)
    ->where('anio', $anio)
    ->with(['contrato.cliente', 'contrato.razonSocial'])
    ->get();

// Factura única por contrato/mes
Factura::where('contrato_id', $contratoId)
    ->where('mes', $mes)
    ->where('anio', $anio)
    ->first();

// Suma de deuda pendiente
Factura::where('aliado_id', $alidoId)
    ->where('estado', 'pendiente')
    ->sum(DB::raw('administracion + mora - anticipo_aplicado'));
```

## Vistas del Módulo

```
resources/views/admin/facturacion/
├── index.blade.php          ← Lista de facturas por empresa/mes
├── empresa.blade.php        ← Detalle de empresa con sus facturas
├── show.blade.php           ← Detalle individual de factura
├── recibo.blade.php         ← Recibo: estilos + layout de la hoja
├── _recibo_cuerpo.blade.php ← Cuerpo del recibo (grupo NP e individual)
├── _recibo_desglose_empresa.blade.php ← Desglose de la copia empresa
└── partials/                ← Componentes reutilizables
```

## Recibo: doble copia por hoja

`aliados.recibo_doble_copia` (switch en Configuración → Parámetros
Especiales) hace que el recibo salga **dos veces en la misma hoja carta**:
copia CLIENTE arriba, copia EMPRESA abajo, para partirla por la mitad.

- El cuerpo del recibo se incluye dos veces desde `recibo.blade.php`, con
  `['copia' => 'cliente'|'empresa']`. Dentro del partial eso es `$esCopiaEmp`.
- La copia cliente respeta el botón "Vista detallada"; la copia empresa
  lleva `.det` fijo (siempre detallada).
- En la copia empresa se omiten la **nota legal**, el **Resumen Financiero**
  y la franja de **Forma de Pago**: todo eso lo absorbe el desglose del
  final, que tiene 4 columnas (SS · Servicios · Saldos · Forma de pago).
  Tampoco repite "Total factura", que ya sale en el bloque TOTAL A PAGAR.
- El escalado lo hace `ajustarCopias()` en JS. No usa un ancho fijo: prueba
  varios anchos de diseño (`ANCHOS_DISENO`) y elige el que da mayor escala,
  **descartando** los que hacen crecer el alto — señal de que la tabla ya
  está partiendo palabras ("INDEPENDIENT/E"). A igualdad de escala toma el
  más ancho. Con eso un recibo de pocas filas sube de ~0.66 a ~0.93.
- La geometría en pantalla y en `@media print` es la misma, así que lo que
  se ve es lo que sale. Para que eso se cumpla:
  - Las reglas de `@media print` que reacomodan el recibo (quitar márgenes,
    `font-size: .58rem` en la tabla) están acotadas a `.hoja-fondo`, o sea
    solo al modo simple. En doble copia el recibo se imprime tal cual se ve.
  - `ajustarCopias()` mide con la clase `.midiendo`, que oculta los
    `.no-print` (el link 👤 de cada fila) igual que hará la impresora.
  - `@page margin` es 6mm en doble copia y 8mm en simple (condicional en
    Blade). El modo simple depende de 199.9mm para su `scale(0.721)`.
- **No poner `id=` dentro de `_recibo_cuerpo.blade.php`**: se renderiza dos
  veces en la misma página y los ids quedarían duplicados.

### ¿A nombre de quién sale el recibo?

Lo decide **`facturas.empresa_id`**, nunca `clientes.cod_empresa` — ese
último es el canal/referido comercial del cliente y no dice a quién se le
factura. Usarlo hacía que un recibo de una sola persona saliera encabezado
con una empresa ajena.

- Con `empresa_id` → encabezado "Empresa": nombre, NIT y datos de entrega.
- Sin `empresa_id` y 1 fila (`$unaPersona`) → "Afiliado": nombre, C.C. y
  badge Dependiente/Independiente. Además:
  - En la **copia cliente** la tabla se reemplaza por `.liq-grid`: las
    entidades como etiqueta/valor en dos columnas (EPS/ARL, Pensión/Caja,
    Días) más una barra `.liq-total`. Una tabla de 6 columnas y una sola
    fila es muy ancha y baja, y obliga a escalar el recibo hacia abajo
    dejando media hoja vacía; el grid es más angosto y sube la escala.
  - En la **copia empresa** se mantiene la tabla (hacen falta los valores
    por entidad), pero **sin las columnas No / Nombre / Razón Social**.
    Ojo con el `colspan` del `tfoot`: cambia de 8 a 5.
- Sin `empresa_id` y varias filas → la razón social si todas comparten una,
  si no "N trabajadores".

El 99,6% de los lotes sin `empresa_id` son de una sola fila.

### Saldos: solo existe `saldo_proximo`

`saldo_a_favor` y `saldo_pendiente` se eliminaron de `facturas` en la
migración `2026_04_20_220000`. Queda una sola columna:

```
saldo_proximo = (valor_efectivo + valor_consignado + anticipo_aplicado) − total
    negativo → quedó PENDIENTE      positivo → quedó A FAVOR
```

El *saldo anterior acumulado* no está guardado: se calcula sumando los
`saldo_proximo` de las facturas previas (por `empresa_id` si la factura es
de empresa, si no por `cedula` con `empresa_id IS NULL`). Lo hace
`FacturacionController@recibo` y lo pasa como `$saldoAnterior`.

### Cuadre del desglose

`SS + servicios` debe dar `total`. A nivel de **lote** (`numero_factura`)
siempre cuadra; en filas sueltas puede no cuadrar porque hay registros con
`total = 0` cuyo valor quedó consolidado en otra fila del lote. Por eso el
desglose calcula la diferencia y la muestra como fila **"Ajuste"** en vez de
dejar el recibo descuadrado.

## Corregir pago de un recibo (oct-2026)

Botón «💳 Corregir pago» del recibo (admin y superadmin) →
`CorreccionPagoController` + `CorreccionPagoService`. Cambia forma de pago,
consignaciones y fecha de pago **sin anular**, para recibos que ya tienen
planilla pagada (pedido de Formalizate: antes anulaban y re-facturaban).

- Opera sobre todo el `numero_factura`. El **total pagado no cambia**: el
  consignado se reparte por fila en proporción a lo que pagó cada una (resto
  mayor), así `saldo_proximo`, mora y planilla quedan intactos.
- Fecha: solo consignación → la consignación más reciente; con efectivo → la
  que escriben (es la que cuenta en la caja). Igual que `_fechaPagoRecibo`.
- Bloquea: préstamos, pre-facturas, recibos pagados solo con anticipo, y si el
  día de caja de quien facturó (fecha vieja o nueva) ya está **cuadrado** —
  un superadmin lo reabre primero. Las consignaciones validadas contra el
  extracto no se tocan.
- Las consignaciones que cambian se borran (soft) y se crean de nuevo, para
  que el observer de facturación electrónica vea la plata nueva.
- Bitácora: `accion = pago_corregido`, con antes/después de filas y consignaciones.

## Rutas Principales

```
GET  /admin/facturacion                           → index
GET  /admin/facturacion/empresa/{empresa}         → empresa (con ?mes=&anio=)
POST /admin/facturacion/generar                   → generar facturas del mes
POST /admin/facturacion/{factura}/marcar-pagada   → cambiar estado
```

## Facturación electrónica (Dataico)

Hay **dos caminos** hacia Dataico, y comparten la marca `facturas.fe_marcada`
para no emitir dos veces lo mismo:

| Camino | Controlador | Qué hace |
|---|---|---|
| Manual (viejo) | `FacturacionElectronicaController` | Exporta el Excel `importacion-facturas-multiples` y marca `fe_marcada` a mano |
| API (nuevo) | `DataicoController` + `App\Services\Dataico\*` | Emite por `POST /direct/dataico_api/v2/invoices` y marca `fe_marcada` al recibir el OK |

### ⚠️ Estado: bloqueado en la numeración (24-ago-2026)

El payload ya es correcto y el API acepta cuenta, cliente e ítems, pero
responde **401 «No se encuentra numeración … en la cuenta de DATAICO»** con las
dos resoluciones que el propio API reporta en las facturas ya emitidas de
BRYGAR (`FE/18764092296384` de mayo y `FE/18764110623435` de agosto). Se probó
el número como texto y entero, `prefix`+`resolution_number` sueltos, `numbering`
como texto, `numbering_range_id` y solo el prefijo: todas dan lo mismo.

Sospecha: la numeración existe pero no está habilitada para emisión por API.
Es configuración del lado de Dataico. **Nada se ha emitido**: la última factura
de la cuenta sigue siendo FE1184.

### La forma del cuerpo — tres trampas, todas confirmadas a golpes

```jsonc
{
  "invoice": {
    "dataico_account_id": "…",      // ← DENTRO de invoice.
    "numbering": { "prefix": "FE", "resolution_number": "…" },
    "customer": { /* dirección PLANA, no anidada en address */ },
    "items": [ { "sku": "SKU 0001", "description": "0001-…", … } ],
    "notes": ["JULIO", "Brynex 13069"]   // ← array, no string
  },
  "actions": { "send_dian": true, "send_email": false }  // ← en la RAÍZ
}
```

1. El cuerpo admite **solo** `invoice` y, opcional, `actions`. Cualquier otra
   llave en la raíz → HTTP 500 diciendo justamente eso.
2. `actions` va en la **raíz**, no dentro de `invoice`.
3. `dataico_account_id` va **dentro de `invoice`**. En la raíz, como header
   (`Dataico_account_id`, `dataico-account-id`, `Dataico-Account-Id`) o como
   parámetro de URL, todas responden 500 «Debe enviar un dataico account id
   válido» — aunque el mismo valor sí sirve como parámetro en el **GET** de
   consulta. Los dos endpoints no lo reciben igual.

Los enums salieron de facturas reales, no de la documentación:
`PERSONA_NATURAL`/`PERSONA_JURIDICA`, `CC`/`NIT`, `DEBITO`, `BANK_TRANSFER`,
`SIMPLIFICADO`, `FACTURA_VENTA`. `PERSONA_NATURAL` usa `first_name` +
`family_name`; `PERSONA_JURIDICA` usa `company_name`. Nunca los dos. Los ítems
no llevan impuestos ni descuento: BRYGAR no cobra IVA.

Único campo sin confirmar: el formato de `issue_date`. Se manda ISO
(`2026-07-01`) porque así lo lleva el QR de la DIAN, pero la respuesta lo
devuelve `15/08/2026 13:31:57`.

### Consultar lo ya emitido

```
GET /direct/dataico_api/v2/invoices?dataico_account_id=…&number=FE1184
```

Devuelve la factura completa con `cufe`, `uuid`, `dian_status`, `numbering` y
`pdf_url`. Es la única forma de saber qué se emitió: el Excel viejo no dejaba
ninguna referencia hacia Brynex.

### Qué se emite

**Solo `admon + afiliacion`.** La seguridad social (`v_eps`, `v_arl`, `v_afp`,
`v_caja`), la `mora`, el `seguro` y la `mensajeria` NO se facturan: son plata
que el aliado recauda y traslada, no ingreso propio.

El criterio de selección es la **cuenta bancaria**, no la razón social.
`dataico_configuraciones.banco_cuenta_id` apunta a la cuenta de la razón social
emisora y se emite todo grupo de `numero_factura` con al menos una consignación
ahí.

⚠️ `facturas.razon_social_id` **no sirve** para esto: guarda la razón social por
la que está *afiliado* el cliente (la de la planilla PILA), no la que emite.
En BRYGAR, filtrar por ahí dejaría fuera el 97% de la facturación.

Consecuencias conscientes: el **efectivo puro** no tiene consignación, así que
no se emite. Y el dato de cuenta **solo es confiable desde mayo-2026**: la
migración del legacy metió los 32.084 pagos de 2020-2025 en una sola cuenta
genérica (id 137) y sin `factura_id`. Ver [[legacy-sigue-vivo-desfase]].

### Adquirientes

La factura de un lote empresarial sale **a nombre de la empresa, por la suma de
todos sus afiliados** — una sola factura, no una por trabajador. Verificado
contra FE1184: $92.000 = 2 × $46.000 a nombre del empleador.

`empresas` NO son todas personas jurídicas: en BRYGAR, de 227 registros solo 15
tienen NIT de verdad. Se clasifica por la forma del documento (9-10 dígitos
empezando en 8 o 9 → NIT), no por estar en esa tabla.

Sin documento utilizable manda `dataico_configuraciones.consumidor_final`:
apagado se retienen y se listan en el panel, encendido salen con
`222222222222`. Va como interruptor porque en las 1.128 facturas que BRYGAR ya
tiene ante la DIAN **no hay una sola** con esa figura.

Un `numero_factura` que agrupe filas de empresas distintas queda **fuera** y se
lista aparte: emitirlo cargaría a una empresa plata de otra. Son 4 en toda la
historia de BRYGAR.

### Doble emisión

Es el riesgo real: una factura emitida dos veces ante la DIAN no se deshace,
toca nota crédito. Tres defensas, en orden de importancia:

1. Índice único `(aliado_id, numero_factura)` en `dataico_envios`.
2. Reclamo atómico: `EmisionService::reclamar()` pasa la fila a `enviando` con
   un UPDATE condicional antes de tocar el API. Quien afecte 0 filas se retira.
3. `ShouldBeUnique` en el job (lock de caché, por servidor — la más débil).

### `dataico:conciliar` — y por qué es delicado

Reconstruye qué se emitió antes de que existiera este módulo. `fe_marcada`
estaba en **cero en las 1.393 filas** de BRYGAR pese a tener 1.128 facturas ante
la DIAN: nadie usó nunca el botón de marcar del flujo viejo.

Cruza por **documento + valor exacto + coherencia de fechas**. Las dos reglas
que costaron sangre:

- **Un 429 NO es un 404.** Dataico responde `429 Demasiadas peticiones
  paralelas` a las ráfagas. Tratarlo como «esa factura no existe» deja
  facturas ya emitidas sin marcar → se reemiten. Va con 350 ms entre llamadas
  y backoff; los fallos se cuentan aparte y el comando avisa.
- **La factura de Brynex no puede ser posterior a la emisión.** Sin esa
  condición, 189 de 1.111 cruces fueron falsos: el valor $46.000 se repite
  cientos de veces y la cédula de quien paga todos los meses cruza con
  cualquiera de sus facturas. El daño va en la dirección peligrosa — marcada
  como emitida sin estarlo, no se emite nunca.

Va en tres fases: precarga (3 consultas), consulta a Dataico (~20 min sin tocar
la BD, porque el túnel SSH se cae en corridas largas) y escritura en tandas.
Con `--cache` guarda lo traído y un recruce tarda segundos.

### Anular un recibo con FE: nota crédito (sep-2026)

Una FE aceptada por la DIAN **no se borra**: se anula con una nota crédito que
la referencia. Antes, anular el recibo dejaba viva la FE y al re-facturar salía
otra por la misma plata (FE2314/FE2326 y FE2321/FE2327); se corrigieron con NC1
y NC2 el 25-sep-2026.

- `FacturacionController::anular` busca la FE vigente del recibo
  (`NotaCreditoService::feVigente`). Si la hay, pide confirmación (409 con
  `campo_confirmacion: confirmar_fe`), emite la nota **antes** de anular y, si
  Dataico o la DIAN la rechazan, **no anula**. Anular una sola fila de un lote
  con FE se bloquea: la FE cubre el recibo completo.
- `restaurar` se niega si la FE ya se anuló con nota: el recibo quedaría cobrado
  sin factura y el envío automático no lo reemite.
- Numeración NC creada en el portal (prefijo NC, desde 1, sin resolución DIAN).
  El consecutivo lo lleva Brynex en `dataico_configuraciones.nc_ultimo_numero`.
- Cuerpo: `credit_note` con `invoice_id` (uuid de la FE), `reason: ANULACION`,
  `numbering {prefix, flexible}`, los mismos `items` de la FE (salen de
  `dataico_envios.payload`) y `issue_date` en `d/m/Y H:i:s`; `actions` en la raíz.
  Queda la notificación CBF02 «no se informó el número de la factura
  referenciada» aunque se mande `invoice_number`: es aviso, no rechazo.
- Rastro en `dataico_notas_credito` (índice único por `dataico_envio_id`).

```bash
php artisan dataico:nota-credito 13809 --motivo="…" --sin-correo --simular
```
El comando es para recibos que ya se anularon sin nota; se niega si el recibo
sigue vigente (para eso está el botón Anular).

### Piezas

```
app/Services/Dataico/NotaCreditoService.php       ← nota crédito (anulación) de una FE
app/Services/Dataico/SeleccionFacturasService.php  ← qué se emite y a nombre de quién
app/Services/Dataico/PayloadBuilder.php            ← el JSON; único archivo que
                                                     conoce el contrato de Dataico
app/Services/Dataico/ApiClient.php                 ← HTTP; nunca reintenta un 4xx
app/Services/Dataico/EmisionService.php            ← orquesta y deja rastro
app/Jobs/EmitirFacturaDataicoJob.php               ← modo 'factura'
app/Observers/ConsignacionObserver.php             ← dispara al entrar la plata
app/Console/Commands/DataicoEmitir.php             ← modo 'diario' + reintentos
app/Console/Commands/DataicoConciliar.php          ← cruce con lo ya emitido
app/Console/Commands/DataicoNumeraciones.php       ← busca el numbering_range_id
```

```bash
php artisan dataico:emitir --aliado=2 --simular --limite=1   # ver el JSON sin enviar
php artisan dataico:emitir --aliado=2 --limite=10 --forzar   # tanda manual con la config apagada
php artisan dataico:conciliar --aliado=2 --cache=/tmp/dc.json # cruce con lo ya emitido
```

`activo` gobierna solo la emisión **automática**; `--forzar` es una persona
apretando el botón, y existe para poder probar de a diez sin dejar programado
el barrido nocturno.
