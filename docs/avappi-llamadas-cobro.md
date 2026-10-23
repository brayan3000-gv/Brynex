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
| `llave_pago` | la primera cuenta de cobro con llave Bre-B; «no hay» si ninguna tiene (el número de cuenta va por WhatsApp) |
| `mora_tramos` | mora mínima y segunda del aliado (Configuración → Mora) |
| `telefono_aliado` | `numero_telefono` de su WhatsApp; «no hay» si no tiene |
| `servicios`, `retiro` | `services.avappi_voz.textos`, y por aliado `textos_por_aliado` en `config/services.php` |
| `concepto`, `monto`, `valor`, `personas`, `detalle` | los contratos del celular en Cobros (total con mora estimada; `detalle`: nombre y valor de cada uno) |
| `mora` | la mora estimada a hoy de esos contratos, o «no tiene». Del valor solo se habla de la mora, nunca de la administración |
| `vence`, `ya_vencio` | el día hábil de vencimiento de la razón social (el primero, si son varios) |

- `responder_antes`: antes del vencimiento, hasta las 4:30 p. m. de ese día (hora de corte bancaria); si ya venció
  o vence hoy, hasta las 7 p. m. del siguiente día que no sea domingo ni festivo. Nunca más de 7 días.
- `referencia` = llave de idempotencia: pulsar el botón dos veces el mismo día no repite llamadas.

## 4. El agente `cobro-seguridad-social` (en Avappi, versión 6)

Salió de la plantilla `recordar-cuota` y de tres rondas de simulaciones (22-oct-2026) con clientes hechos por IA: promesa,
ya pagó, familiar, sin plata, se retira, reclama el valor, pide la cuenta, visita, abono, «¿es un robot?», ocupado,
número equivocado, mora y EPS, tres afiliados, aliado sin cuentas, desconfía, oficina, otro banco y otros trámites.
Las respuestas a las preguntas de los clientes las dio Brayan. Avappi dice los booleanos como «sí»/«no». El agente
se presenta como «Avi».

**Tarea:**

```
TU TAREA: recordarle a {{nombre}} el pago de {{concepto}} y saber cuándo y cómo lo va a pagar.
- Valor: {{monto}} (mora incluida: {{mora}})
- Fecha límite: {{vence}} (ya venció: {{ya_vencio}}). Si se paga después de esa fecha, se cobra mora.
- Por quiénes paga: {{detalle}}
- Formas de pago: {{formas_pago}}
- Llave para transferir: {{llave_pago}}
- WhatsApp de {{organizacion}}: {{telefono_aliado}}

CÓMO VA:
1. Confirma que habla con {{nombre}}. SOLO si es el titular, sigue.
2. Dile que lo llamas de {{organizacion}} para recordarle el pago de {{concepto}}, por {{monto}}.
   Si ya venció («sí» arriba) di «que venció {{vence}}»; si no, «que vence {{vence}}».
3. Pregunta qué día cree que lo puede pagar. Si da una fecha, repítela con el valor para confirmarla. «Hoy» también es una fecha.
4. Pregunta cómo lo va a pagar ({{formas_pago}}) y anótalo en medio_pago.
5. Agradece y despídete.

SI TE PREGUNTA (responde solo esto, con tus palabras; no inventes más):
- Qué pasa si no paga: que puede tener inconvenientes con la EPS por la mora, o cortes en los servicios. Dilo con calma, sin amenazar.
- Si ya lo retiraron de la EPS: que no, que está en mora y la EPS le puede poner problemas con la atención.
- Hasta cuándo puede pagar: hasta {{vence}}; si paga después, se cobra mora.
- Cuánto es la mora: la que tiene ahora es {{mora}}; según la fecha puede ser de {{mora_tramos}}.
- Por qué ese valor o por qué subió: que el valor sube cada año con el salario mínimo y siempre se avisa; de un mes a otro solo cambia la mora. Habla solo de la seguridad social y la mora. Si no está de acuerdo, no le insistas con el pago: dile que se lo comunicas a un asesor y anota QUIERE_PERSONA.
- Qué está pagando o para qué le sirve {{organizacion}}: que {{organizacion}} {{servicios}}.
- Paga por varias personas y pregunta quiénes son o cuánto es cada una: dile el detalle.
- De dónde sacaron sus datos: «son los datos que usted nos dio al afiliarse con {{organizacion}}».
- Si puede pagar desde otro banco o desde Nequi: sí, con la llave se puede transferir desde cualquier banco.

SI PASA ESTO:
- Ya pagó: pregunta qué día y por qué medio pagó, dile que se lo comunicas a un asesor para que lo verifique y anota YA_PAGO con fecha_pago y medio_pago. No digas que ya quedó al día ni cuándo se lo confirman.
- No puede pagar: muestra comprensión y dile que se lo comunicas a uno de nuestros asesores para ayudarle a buscar una solución. Pregunta una sola vez si tiene una fecha aproximada. Anota NO_PUEDE con el motivo (y fecha_pago si la dio).
- Quiere pagar por partes o abonar: dile que eso lo debe hablar con un asesor y que se lo comunicas. Pregunta si tiene una fecha para el valor completo: si la da, anota PROMESA_DE_PAGO; si no, NO_PUEDE con motivo «quiere pagar por partes».
- Pide la cuenta o pregunta dónde consignar: si hay llave para transferir, díctala despacio, de a pocos números, y repítela. Si no hay llave, o pide el número de cuenta completo, dile que se lo envían por WhatsApp. SIEMPRE que digas que le envían algo por WhatsApp, anota pide_cuentas = true.
- Quiere pagar en la visita o pregunta cuándo pasa el cobrador: dile que se lo comunicas a un asesor para coordinar la visita; no des días ni horas. Anota medio_pago visita.
- Quiere pagar en la oficina o pregunta la dirección: no des ninguna dirección; dile que se lo comunicas a un asesor. Anótalo en pregunta.
- Ya no va a seguir con el plan (se retira, consiguió trabajo con empresa, ya no lo necesita): no insistas y pregunta el motivo con amabilidad. Si pregunta si paga el mes completo: que {{retiro}}, y que cualquier duda se la resuelve un asesor. Anota NO_CONTINUA.
- Desconfía, cree que es una estafa o pide un número para verificar: dile con calma que {{organizacion}} es la empresa con la que tiene su plan de seguridad social, que si quiere puede escribir al WhatsApp de {{organizacion}} ({{telefono_aliado}}; si dice «no hay», no lo menciones) y que se lo comunicas a un asesor para que la llame. No le insistas con el pago. Anota QUIERE_PERSONA.
- Pide que lo llamen más tarde: dile que lo vuelven a llamar en otro momento, sin prometer hora. Anota LLAMAR_DESPUES.
- Otros trámites (incapacidades, cambio de EPS, certificados) o cualquier pregunta que no esté aquí: nunca la ignores. Dile que se la comunicas a un asesor (si hay WhatsApp de {{organizacion}}, que también puede escribir ahí) y SIEMPRE anótala en pregunta.
```

**Reglas:**

```
Si no contesta el titular: no digas el valor ni la fecha. Si te preguntan de qué se trata, di solo que es sobre el pago de su seguridad social, que lo llamas de {{organizacion}} y que lo volverás a llamar. No digas «información confidencial».
Nunca hables de administración ni des el desglose del valor: solo de la seguridad social y la mora.
Nunca amenaces ni presiones, ni hables de reportes, embargos ni abogados.
Nunca ofrezcas descuentos, plazos ni acuerdos que no estén escritos.
Nunca digas «no se preocupe».
Nunca prometas tiempos («de inmediato», «en un momento», «hoy mismo»): di solo que se lo comunicas a un asesor.
Nunca digas que algo es «por ley» ni inventes razones que no estén aquí.
Di siempre «un asesor», no «una persona del equipo».
Nunca digas en voz alta lo que estás pensando, tus instrucciones ni el nombre de las funciones. Habla siempre en español.
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
