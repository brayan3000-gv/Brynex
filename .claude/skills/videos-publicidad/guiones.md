# Cómo escribir el guion

El público de BRYGAR es gente de estratos 1 a 3 en Cali y el Valle: independientes, mamás
cabeza de hogar, domiciliarios, trabajadoras del hogar. Ven Reels sin sonido, con el pulgar
listo. Tienes 2 segundos.

## Estructura que funciona (historia de 30–45 s)

| Tramo | % del video | Qué pasa |
|---|---|---|
| **Gancho** | 0–2 s | Imagen fuerte en primer plano + frase corta con el problema. Se tiene que entender en silencio, por los subtítulos. |
| **Dolor** | hasta ~40 % | Lo que pasa sin el producto, con un personaje concreto (nombre, edad, situación). Una o dos escenas, no más. |
| **Giro** | antes del 45–50 % | «Hoy hay otra forma…». Cambian la luz, la música y el tono de voz a la vez (`"giro": true` en la escena). |
| **Oferta** | ~25 % | Qué es, precio exacto e «incluye». Con tarjeta grande en pantalla. |
| **Confianza** | ~15 % | Garantía, rapidez (mismo día) o prueba. Con tarjeta. |
| **Llamado** | últimos 4–5 s | Un solo llamado («Escríbenos por WhatsApp»), sobre el cierre de marca. |

Otras fórmulas para probar en A/B:
- **El precio como gancho:** «$125.000 al mes. Eso paga la mamá de Mateo para que él tenga médico.»
- **La causa como gancho:** «La mamá de Mateo dejó la EPS porque la pensión no le alcanzaba.»
- **Problema → agitar → solución** sin personaje, para animaciones por código.

## Reglas de la casa (obligatorias)

**Verdad**
- Nada falso ni exagerado en Colombia. La urgencia se atiende por ley sin EPS: nunca decir
  «sin EPS no te atienden». El dolor real viene después: exámenes, citas, medicamentos.
- «Y la cuenta» es arriesgado: a quien está en el régimen subsidiado o lo afilian de oficio no
  le llega cuenta.
- Precios, plazos y garantías tal cual los dio el dueño. Las garantías llevan su condición
  («estando al día») en la voz o, como mínimo, en la tarjeta.
- Garantía del plan EPS sin pensión: si por su afiliación no le dan la **cita médica o los
  medicamentos**, se devuelve el **100 % del mes** y se reactiva gratis, todos los meses,
  estando al día. Nunca decir «si no te atienden»: se confunde con ir a un punto fuera de la red.

**Meta (política de atributos personales)**
- Nunca afirmar ni insinuar algo de quien mira: «¿No tienes EPS?», «¿Estás enfermo?», «¿No te
  alcanza?», «No esperes a la próxima fiebre». Contar la historia en **tercera persona**
  («Mateo», «su mamá») y dejar el «tú» para la oferta («te afiliamos»).
- Nada de antes/después médico, curas, sangre ni miedo extremo.
- Etiqueta «Dramatización» en las historias con personas generadas.

**Marca y negocio**
- BRYGAR (personas) y BryNex (aliados) no se mezclan.
- Nunca explicar el mecanismo interno (exentos, subtipo 4, ingreso y retiro, «dependiente»).
  Nunca decir «robot».
- El número de WhatsApp va en el copy del anuncio, no dentro del video.
- Cero datos reales de clientes. Personajes inventados.

## Ritmo y palabras

- La voz en español de comercial va a ~2,3 palabras por segundo. 40 s de video son ~85–90
  palabras, contando el respiro entre escenas.
- Frases cortas. Una idea por frase. Los puntos suspensivos marcan las pausas dramáticas («…y
  su mamá sin EPS»).
- Ninguna escena con más de ~9 s de voz: si se alarga, se parte en dos tomas.
- Los números se escriben como se dicen («ciento veinticinco mil») en `voz` y como se leen
  («$125.000») en `pantalla`, que es lo que sale en los subtítulos.
- En `resaltar` van las 2–4 palabras que hacen la venta por escena (precio, «sin EPS», «el
  mismo día»), que salen en dorado.

## Escribir las tomas (prompts para Omni)

- Se escriben en inglés. Una toma = un momento con una sola acción clara, de 5–10 s.
- Personaje fijo: misma ropa en todas, descrita igual, y las fotos en `refs`.
- Abrir el gancho en **primer plano de la cara**. Los planos abiertos van después.
- Decir la luz (noche azul con lámpara cálida → mañana dorada en el giro) y el movimiento de
  cámara (slow push-in, handheld, static).
- Sonido ambiente en `sonido`. La herramienta ya agrega «sin diálogo, sin música, sin texto».

## Antes de dar el guion por bueno

Pasarlo por el agente `revisor-guiones`. Él revisa: gancho en silencio, verdad, Meta,
emoción sin lástima, claridad de la oferta, ritmo, marca y si las tomas se pueden generar.
