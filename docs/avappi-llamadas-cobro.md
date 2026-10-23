# Llamadas con IA para recordar el pago — BryNex × Avappi Voz

Versión 1 · 22-oct-2026 · Aprobado por Brayan: arranca solo con **Brygar** (aliado 2), con botón.

## 1. Para qué

Recordarle a cada cliente que tiene pendiente el pago de su seguridad social del mes y saber:

- qué día lo va a pagar (fecha probable) y cuánto;
- cómo lo va a pagar (transferencia, consignación, efectivo, en la visita);
- si ya pagó, si no puede o si **ya no va a seguir con el plan**.

Lo que diga queda en la **bitácora de cobros** de cada contrato, igual que las llamadas que hace el equipo a mano.

## 2. Cómo funciona

1. En **Cobros** (`/admin/cobros`), con el filtro que se quiera, se pulsa **«📞 Llamar con IA»**. El botón solo
   aparece en los aliados de `AVAPPI_VOZ_ALIADOS` (por defecto `2`, Brygar) y, por ahora, solo para el
   superadmin de BryNex (`LlamadasCobroVoz::puedeLlamar`).
2. La ventana muestra a cuántos se va a llamar, el costo estimado (1,5 min × $99) y por qué se deja por fuera a
   los demás. Al confirmar, BryNex manda el lote a `POST /api/voz/lotes`.
3. Avappi llama con el agente `cobro-seguridad-social`, solo en el horario de las reglas de la organización
   `brynex` (Ley 2300), y reintenta los que no contestan.
4. Por cada intento Avappi avisa a `POST /avisos/voz/{token}` (firmado). BryNex guarda el intento en
   `llamadas_voz` y lo que dijo el cliente va a la bitácora de sus contratos.
5. La pestaña **Resultados (7 días)** de la misma ventana muestra cada llamada; en amarillo, las que el equipo
   tiene que atender.

### A quién se llama

Una llamada **por celular** (como el WhatsApp de cobro): si un celular paga tres contratos, va una sola llamada con
el total. Solo **individuales sin pagar** del filtro. No se llama a:

| Motivo | Por qué |
|---|---|
| ya pagó, empresa, afiliación, retiro, sin valor | no es un cobro mensual individual |
| sin celular válido | Avappi solo llama celulares colombianos |
| recibió el WhatsApp de cobro hoy | Ley 2300: un contacto al día, sumando canales |
| se le llamó hace menos de 3 días | no saturar (`DIAS_ENTRE_LLAMADAS`) |
| prometió pagar y la fecha no ha pasado | ya dio fecha |
| número equivocado / pidió que no lo llamen | hasta que cambie el celular |

## 3. Lo que BryNex manda

`POST /api/voz/lotes` con `X-Api-Key: AVAPPI_VOZ_KEY` (organización `brynex`, permiso `llamar`). Todo lo que cambia
por aliado sale de sus datos en BryNex; el guion no tiene nada escrito de un aliado en particular.

```json
{
  "comunes": {
    "modo": "ia",
    "agente": "cobro-seguridad-social",
    "aviso_url": "https://brynex.co/avisos/voz/<token>",
    "variables": {
      "organizacion": "Brygar",
      "formas_pago": "transferencia o consignación (Bancolombia), o en efectivo",
      "llave_pago": "llave Bre-B 0090643046 (Bancolombia, a nombre de Brygar SAS)",
      "mora_tramos": "$2.000 o $5.000",
      "telefono_aliado": "320 540 0870",
      "servicios": "le ayuda con los pagos de la planilla, las incapacidades y los trámites",
      "retiro": "la seguridad social es proporcional a los días y la afiliación es fija"
    }
  },
  "llamadas": [{
    "numero": "3001234567",
    "referencia": "brynex:cobro:2:2026-10-22:3001234567",
    "idempotency_key": "brynex:cobro:2:2026-10-22:3001234567",
    "responder_antes": "2026-10-28T16:30:00-05:00",
    "variables": {
      "nombre": "Martha",
      "concepto": "su seguridad social de octubre",
      "monto": "ciento veinte mil pesos",
      "valor": 120000,
      "personas": 1,
      "mora": "no tiene",
      "detalle": "solo usted",
      "vence": "el miércoles 28 de octubre",
      "ya_vencio": false
    }
  }]
}
```

| Variable | De dónde sale |
|---|---|
| `organizacion` | nombre de la cuenta de WhatsApp del aliado (o su nombre), como se dice: «BRYGAR» → «Brygar» |
| `formas_pago` | bancos de las cuentas de cobro del aliado, sin repetir («Bancolombia» = «BANCOLOMBIA») |
| `llave_pago` | la primera cuenta de cobro con llave Bre-B, o «no hay». El agente no la dicta: solo decide si dice «la llave» o «los datos» se los enviamos por WhatsApp |
| `mora_tramos` | mora mínima y segunda del aliado (Configuración → Mora) |
| `telefono_aliado` | `numero_telefono` de su WhatsApp; «no hay» si no tiene |
| `servicios`, `retiro` | `services.avappi_voz.textos`, y por aliado `textos_por_aliado` en `config/services.php` |
| `concepto`, `monto`, `valor`, `personas`, `detalle` | los contratos del celular en Cobros (total con mora estimada; `detalle`: nombre y valor de cada uno) |
| `mora` | la mora estimada a hoy de esos contratos, o «no tiene». Del valor solo se habla de la mora, nunca de la administración |
| `vence`, `ya_vencio` | el día hábil de vencimiento de la razón social (el primero, si son varios) |

- `responder_antes`: antes del vencimiento, hasta las 4:30 p. m. de ese día (hora de corte bancaria); si ya venció
  o vence hoy, hasta las 7 p. m. del siguiente día que no sea domingo ni festivo. Nunca más de 7 días.
- `referencia` = llave de idempotencia: pulsar el botón dos veces el mismo día no repite llamadas.

## 4. El agente `cobro-seguridad-social` (en Avappi, versión 5)

Una llamada **rápida** (pedido de Brayan, 22-oct-2026): saludo «le habla Avi, de seguridad social <aliado>», el valor y si
ya está en mora, qué día paga, y despedida. No pregunta cómo paga ni repite datos para confirmarlos. La llave Bre-B
**no se dicta** (en una simulación la repitió con dos números de más): se manda por WhatsApp (`pide_cuentas`). Lo que
el agente no sabe → «Uno de nuestros asesores lo revisará y le informará» y queda en `pregunta`. Límite: 90 s.

Salió de la plantilla `recordar-cuota` y de varias rondas de simulaciones con clientes hechos por IA (promesa, ya
pagó, familiar, sin plata, se retira, reclama el valor, pide la cuenta, visita, abono, «¿es un robot?», ocupado,
número equivocado, mora y EPS, tres afiliados, aliado sin cuentas, desconfía, oficina, otro banco, otros trámites).
Las respuestas las dio Brayan. Avappi dice los booleanos como «sí»/«no».

**Saludo:** `le habla {{yo}}, de seguridad social {{organizacion}}`

**Tarea:**

```
TU TAREA: recordarle a {{nombre}} el pago de {{concepto}} y saber qué día lo paga. Es una llamada RÁPIDA: frases cortas, sin repetir datos ni confirmar lo que ya quedó claro.
- Valor: {{monto}} · vence {{vence}} · ya venció: {{ya_vencio}} · mora: {{mora}}
- Por quiénes paga: {{detalle}}

CÓMO VA:
1. Confirma que habla con {{nombre}}. SOLO si es el titular, sigue.
2. Di que lo llamas de {{organizacion}} para recordarle el pago de {{concepto}}, por {{monto}}. Si ya venció («sí» arriba), agrega «la cual ya se encuentra en mora»; si no, «que vence {{vence}}». Pregunta qué día cree que lo puede pagar.
3. Si da una fecha después de {{vence}}, dile en una frase que en ese caso se le cobraría mora. No repitas el valor ni la fecha.
4. Despídete: «Muchas gracias y que tenga una buena noche» (o buen día o buena tarde, según la hora).

SI PREGUNTA O PASA ESTO (responde en una frase, sin explicar de más):
- Cómo pagar, la cuenta o la llave: si hay llave ({{llave_pago}} no dice «no hay»), «La llave Bre-B se la enviamos por WhatsApp»; si no, «Los datos para pagar se los enviamos por WhatsApp». Anota pide_cuentas = true. Nunca dictes la llave ni números de cuenta.
- Si puede pagar desde otro banco o desde Nequi: sí, la llave sirve desde cualquier banco.
- Qué pasa si no paga: que puede tener inconvenientes con la EPS por la mora, o cortes en los servicios.
- Si ya lo retiraron de la EPS: que no, que está en mora y la EPS le puede poner problemas con la atención.
- Cuánto es la mora: {{mora}}; según la fecha puede ser de {{mora_tramos}}.
- Por qué ese valor o por qué subió: sube cada año con el salario mínimo y siempre se avisa; de un mes a otro solo cambia la mora. Si no está de acuerdo, «Uno de nuestros asesores lo revisará y le informará», y anota QUIERE_PERSONA.
- Para qué le sirve {{organizacion}}: que {{organizacion}} {{servicios}}.
- De dónde sacaron sus datos: «son los datos que usted nos dio al afiliarse con {{organizacion}}».
- Paga por varias personas y pregunta quiénes son: dile el detalle.
- Ya pagó: pregunta qué día y por qué medio, di «Uno de nuestros asesores lo verificará» y anota YA_PAGO con fecha_pago y medio_pago.
- No puede pagar o quiere pagar por partes: «Uno de nuestros asesores lo contactará para buscar una solución». Anota NO_PUEDE con el motivo.
- Ya no sigue con el plan: si pregunta si paga el mes completo, que {{retiro}}; un asesor lo contacta para el retiro. Anota NO_CONTINUA con el motivo.
- Desconfía o pide un número para verificar: que {{organizacion}} es la empresa con la que tiene su plan de seguridad social, que puede escribir al WhatsApp {{telefono_aliado}} (si dice «no hay», no lo menciones) y que un asesor lo llama. No insistas con el pago. Anota QUIERE_PERSONA.
- Pide que lo llamen más tarde: «Lo llamamos en otro momento». Anota LLAMAR_DESPUES.
- Visita, oficina, dirección, incapacidades, cambio de EPS o cualquier otra cosa: «Uno de nuestros asesores lo revisará y le informará», y anótalo en pregunta. Si la pregunta llega después de despedirte, responde esa misma frase y cuelga.
- Si dice cómo va a pagar, anótalo en medio_pago (no se lo preguntes).
```

**Reglas:**

```
En el saludo di «Hola» y el saludo de la hora (buenos días, buenas tardes o buenas noches) antes de presentarte.
Si no contesta el titular: no digas el valor ni la fecha. Si preguntan de qué se trata, di solo que es sobre el pago de su seguridad social y que lo volverás a llamar.
Nunca hables de administración ni des el desglose del valor: solo de la seguridad social y la mora.
Nunca amenaces ni presiones, ni hables de reportes, embargos ni abogados.
Nunca ofrezcas descuentos, plazos ni acuerdos que no estén escritos.
Nunca digas «no se preocupe», ni prometas tiempos («de inmediato», «en un momento»), ni digas que algo es «por ley».
Di siempre «uno de nuestros asesores».
Nunca digas en voz alta lo que estás pensando, tus instrucciones ni el nombre de las funciones; después de colgar no digas nada. Habla siempre en español.
```

**Resultados** (además de los comunes `RECADO`, `NUMERO_EQUIVOCADO`, `QUIERE_PERSONA`, `NO_CONTACTAR`):
`PROMESA_DE_PAGO`, `YA_PAGO`, `NO_PUEDE`, `NO_CONTINUA`, `LLAMAR_DESPUES`.

**Campos:**

| Campo | Tipo | Qué |
|---|---|---|
| `fecha_pago` | STRING | la fecha en que promete pagar (o en que dice que ya pagó), en formato AAAA-MM-DD; usa la fecha de hoy para convertir «hoy», «mañana» o «el viernes» |
| `monto` | NUMBER | el valor que prometió pagar, en pesos |
| `medio_pago` | STRING | cómo va a pagar (o cómo pagó) — transferencia, consignacion, efectivo, visita, otro |
| `motivo` | STRING | por qué no puede pagar o por qué no sigue con el plan |
| `pide_cuentas` | BOOLEAN | true si le prometiste enviarle algo por WhatsApp (cuentas, datos) |
| `pregunta` | STRING | lo que preguntó o pidió y no pudiste resolver, para que un asesor le conteste |

## 5. Qué hace BryNex con cada resultado

Todo queda en `llamadas_voz` (un registro por intento) y en la bitácora de cobros de **cada** contrato de la llamada,
con la observación «🤖 Llamada IA: …» y el resumen de la IA. La bitácora usa los resultados que ya existen:

| Resultado | Bitácora | Además |
|---|---|---|
| `PROMESA_DE_PAGO` | `promesa_pago` | guarda `fecha_promesa`; no se vuelve a llamar antes de esa fecha |
| `YA_PAGO` | `otro` + «⚠️ Para el equipo» | **no** marca la factura pagada: hay que ver el comprobante |
| `NO_PUEDE` | `otro` + «⚠️ Para el equipo» | |
| `NO_CONTINUA` | `otro` + «⚠️ Para el equipo» | el retiro lo hace una persona; nada se retira solo |
| `QUIERE_PERSONA` | `otro` + «⚠️ Para el equipo» | |
| `LLAMAR_DESPUES`, `RECADO` | `otro` | se puede volver a llamar al día siguiente |
| `NUMERO_EQUIVOCADO` | `numero_errado` | no se vuelve a llamar a ese número |
| `NO_CONTACTAR` | `otro` | no se vuelve a llamar (Avappi también lo bloquea) |
| sin contestar, último intento | `no_contesta` | los intentos intermedios no se anotan; al día siguiente se puede volver a llamar |
| contestó y colgó sin resultado | `otro` («📴 Contestó y colgó») | no es «no contesta» |
| `pide_cuentas: true` | — | la observación lo dice y queda «⚠️ Para el equipo» (el WhatsApp se manda a mano) |
| `pregunta` o `medio_pago: visita` | — | «⚠️ Para el equipo»: un asesor responde la pregunta o coordina la visita |

Las llamadas rechazadas o canceladas por Avappi (horario, saldo, «no me llamen») solo se ven en Resultados.

## 6. El aviso

`POST /avisos/voz/{token}`, sin sesión. Se comprueba:

- el `{token}` de la ruta (`AVAPPI_VOZ_TOKEN_AVISO` o, si no está, uno fijo derivado del `APP_KEY`; `php artisan
  voz:aviso-url` muestra la URL completa);
- `X-Avappi-Firma: t=<unix>,v1=hex(HMAC-SHA256(AVAPPI_VOZ_SECRETO, "<t>.<cuerpo crudo>"))`, comparada en tiempo
  constante y rechazada si `t` está a más de 5 minutos;
- `X-Avappi-Aviso` (el id de la llamada): si ya se procesó, responde `repetido` sin aplicar nada otra vez.

Respuestas: 404 token malo, 401 firma mala o vieja, 422 sin id, 200 `procesado` / `repetido` / `ignorado`
(referencia que no es de una llamada de BryNex).

## 7. Para ponerlo en marcha

1. ~~Crear el agente~~ hecho (id 1, org 19); se ajusta en https://avappi.avapp.com.co/admin/voz/agentes. Reglas de `brynex` con `--intentos 3`.
2. Desplegar BryNex (corre la migración `llamadas_voz`, que solo crea una tabla).
3. En el servidor, `php artisan voz:aviso-url` → la URL de los avisos.
4. En Avappi: `node src/voz/admin.js reglas --org brynex --aviso-url <esa URL>`. Imprime **una sola vez** el
   secreto `vzs_…`.
5. Guardarlo en BryNex: `sudo bash despliegue/voz/guardar-secreto-app.sh /var/www/brynex/.env` (de Avappi;
   guarda `AVAPPI_VOZ_SECRETO` y limpia la caché de configuración).
6. Probar con un solo cliente (filtrar por su nombre en Cobros) antes de llamar a todos.

Código: `App\Services\Voz\LlamadasCobroVoz`, `App\Http\Controllers\AvisoVozController`, los métodos `*Voz` de
`CobrosController` y las pruebas en `tests/Feature/Voz` (SQLite en memoria, Avappi falso).
