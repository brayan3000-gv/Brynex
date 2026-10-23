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

`POST /api/voz/lotes` con `X-Api-Key: AVAPPI_VOZ_KEY` (organización `brynex`, permiso `llamar`):

```json
{
  "comunes": {
    "modo": "ia",
    "agente": "cobro-seguridad-social",
    "aviso_url": "https://brynex.co/avisos/voz/<token>",
    "variables": {
      "organizacion": "Brygar",
      "formas_pago": "transferencia o consignación (Bancolombia o Nequi), o en efectivo"
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
      "vence": "el miércoles 28 de octubre",
      "ya_vencio": false
    }
  }]
}
```

- `organizacion`: el nombre del aliado (el de su cuenta de WhatsApp), nunca «BryNex»: el cliente conoce al aliado.
- `concepto`: «su seguridad social de octubre» o, si el celular paga varios, «la seguridad social de octubre de las 3
  personas que tiene afiliadas».
- `monto` en letras, para que el agente lo diga bien; `valor` en número. Incluye la mora estimada a hoy.
- `vence`: el día hábil en que empieza la mora de esa razón social (el primero, si son varios contratos).
  `ya_vencio: true` = ya pasó.
- `responder_antes`: antes del vencimiento, hasta las 4:30 p. m. de ese día (hora de corte bancaria); si ya venció
  o vence hoy, hasta las 7 p. m. del siguiente día que no sea domingo ni festivo. Nunca más de 7 días.
- `referencia` = llave de idempotencia: pulsar el botón dos veces el mismo día no repite llamadas.

## 4. El agente `cobro-seguridad-social` (se crea en Avappi)

Parte de la plantilla `recordar-cuota`, con los cambios que pidió Brayan: «seguridad social» en vez de «cuota»,
«venció» si ya pasó, cómo va a pagar, fecha probable y si va a seguir con el plan.

**Tono:** respetuoso y claro, nunca informal ni presionante.

**Tarea:**

```
TU TAREA: recordarle a {{nombre}} el pago de {{concepto}} y saber cuándo y cómo lo va a pagar.
- Valor: {{monto}}
- Fecha límite: {{vence}} (ya_vencio = {{ya_vencio}})
- Formas de pago: {{formas_pago}}

CÓMO VA:
1. Confirma que habla con {{nombre}}. SOLO si es el titular, sigue.
2. Dile que lo llamas de {{organizacion}} para recordarle el pago de {{concepto}}, por {{monto}}.
   Si ya_vencio es true di «que venció {{vence}}»; si no, «que vence {{vence}}».
3. Pregunta qué día cree que lo puede pagar. Si da una fecha, repítela con el valor para confirmarla.
4. Pregunta cómo lo va a pagar ({{formas_pago}}).
5. Si ya pagó, agradece y anota YA_PAGO.
6. Si dice que ya no va a seguir con el plan (se retira, consiguió trabajo con empresa, ya no lo necesita),
   no insistas: pregunta el motivo con amabilidad, agradece y anota NO_CONTINUA.
7. Agradece y despídete.
```

**Reglas:**

```
Si no contesta el titular, NUNCA menciones la deuda, el valor ni la seguridad social: di que lo llamas de
{{organizacion}} y que lo volverás a llamar.
Nunca amenaces, ni presiones, ni hables de reportes, embargos, abogados ni de retiros de la EPS.
Nunca ofrezcas descuentos, plazos ni acuerdos que no estén escritos.
No dictes números de cuenta: si los pide, dile que se los envían por WhatsApp y anota pide_cuentas.
```

**Resultados** (además de los comunes `RECADO`, `NUMERO_EQUIVOCADO`, `QUIERE_PERSONA`, `NO_CONTACTAR`):

| Código | Cuándo |
|---|---|
| `PROMESA_DE_PAGO` | dio fecha (y valor) para pagar |
| `YA_PAGO` | dice que ya pagó |
| `NO_PUEDE` | dice que no puede pagar |
| `NO_CONTINUA` | dice que ya no va a seguir con el plan |
| `LLAMAR_DESPUES` | no era buen momento o no es el titular |

**Campos:**

| Campo | Tipo | Qué |
|---|---|---|
| `fecha_pago` | STRING | la fecha que prometió, **en formato AAAA-MM-DD** (el agente necesita la fecha de hoy para convertir «el viernes») |
| `monto` | NUMBER | el valor que prometió pagar |
| `medio_pago` | STRING | `transferencia`, `consignacion`, `efectivo`, `visita` u `otro` |
| `motivo` | STRING | por qué no puede o por qué no sigue |
| `pide_cuentas` | BOOLEAN | pidió los números de cuenta |

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
| `LLAMAR_DESPUES`, `RECADO` | `otro` | entra en el siguiente botonazo (pasados 3 días) |
| `NUMERO_EQUIVOCADO` | `numero_errado` | no se vuelve a llamar a ese número |
| `NO_CONTACTAR` | `otro` | no se vuelve a llamar (Avappi también lo bloquea) |
| sin contestar, último intento | `no_contesta` | los intentos intermedios no se anotan |
| `pide_cuentas: true` | — | la observación lo dice y queda «⚠️ Para el equipo» (el WhatsApp se manda a mano) |

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

1. Crear el agente `cobro-seguridad-social` en https://avappi.avapp.com.co/admin/voz/agentes (sección 4).
2. Desplegar BryNex (corre la migración `llamadas_voz`, que solo crea una tabla).
3. En el servidor, `php artisan voz:aviso-url` → la URL de los avisos.
4. En Avappi: `node src/voz/admin.js reglas --org brynex --aviso-url <esa URL>`. Imprime **una sola vez** el
   secreto `vzs_…`.
5. Guardarlo en BryNex: `sudo bash despliegue/voz/guardar-secreto-app.sh /var/www/brynex/.env` (de Avappi;
   guarda `AVAPPI_VOZ_SECRETO` y limpia la caché de configuración).
6. Probar con un solo cliente (filtrar por su nombre en Cobros) antes de llamar a todos.

Código: `App\Services\Voz\LlamadasCobroVoz`, `App\Http\Controllers\AvisoVozController`, los métodos `*Voz` de
`CobrosController` y las pruebas en `tests/Feature/Voz` (SQLite en memoria, Avappi falso).
