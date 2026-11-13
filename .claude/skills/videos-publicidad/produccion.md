# Producción: tomas, voz, música y armado

## Modelos (12-nov-2026)

| Qué | Modelo | Precio | Notas |
|---|---|---|---|
| Video | `gemini-omni-1.2-flash` (Interactions API) | US$0,023/s en 360p, **0,07/s en 720p**, 0,104/s en 1080p | Toma de 3–15 s con audio ambiente. Hasta 6 imágenes de referencia. Responde en 30–60 s. |
| Foto de referencia | `gemini-3.1-flash-image` | ~US$0,07 | 9:16 |
| Voz | `gemini-2.5-pro-preview-tts`, voz **Aoede** | centavos | Sigue la indicación de tono sin leerla. |
| Música | `lyria-3.5` (Interactions API) | sin precio publicado | ~60 s; se recorta |

- **Veo está muerto:** Google apagó Veo 3.1 Preview (y su Lite) el 22-oct-2026. El
  `VeoVideoGenerator` del panel de BryNex quedó roto por eso.
- **El TTS 3.8 no sirve para esto:** lee en voz alta la indicación de tono (una frase de 5 s
  salía de 25 s) y rechaza `systemInstruction`.
- **La llave de `.env.videos`** (la de los videos demo) sí tiene Omni, imagen, voz y Lyria.

## Qué bloquea Omni (y no se cobra)

Google filtra la toma entera y responde `{"error":{"code":"safety"}}`. Pasó con:
- Un niño **enfermo con angustia**: «feverish son, sweaty, breathing heavily».
- Un primer plano de la mamá **«worried face in the dark»** con el niño en cama.

Lo que sí pasó: el niño **descansando, tranquilo, con un pañito en la frente** y la mamá con
expresión «concerned, thoughtful». **Regla:** la angustia la carga la voz; la imagen sugiere,
con ternura, sin sufrimiento visible. Evitar también sangre, accidentes, armas, marcas reales y
logos (WhatsApp incluido: «typing a message on her phone»).

## Consistencia de personajes

1. Una foto de referencia por personaje o grupo, de cuerpo entero sobre fondo neutro y con ropa
   característica (la mamá de amarillo mostaza y el niño con pijama azul de estrellas).
2. Todas las tomas la reciben en `refs`. La herramienta antepone «use the people from the
   reference image as the exact same characters».
3. Repetir la ropa en el texto de cada toma («same mustard-yellow t-shirt»).
4. Resultado en Mateo: la misma cara en 5 de 5 tomas. Cambian un poco el peinado y la luz, y no
   se nota.

## Armado (`armar.mjs`)

- **Duración por escena:** respiro previo (0,5 s la primera, 0,25 s las demás) + voz ÷ `tempo`
  + 0,45 s. La toma se recorta a eso. Si se queda corta, se ralentiza hasta ×1,35 y luego se
  congela. Por eso conviene pedir tomas ~1,5 s más largas que su frase.
- **Voz:** `voz.tempo` (1,04 en Mateo) la acelera sin cambiar el tono.
- **Música:** `musica.giro` es el segundo en que la pista pasa de triste a esperanzadora. Se
  mide con la energía por segundo:
  ```bash
  ffmpeg -i musica.mp3 -af "aresample=8000,asetnsamples=8000,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=-" -f null -
  ```
  Se alinea con la escena que tiene `"giro": true`. Si el giro de la música llega antes, la
  música entra tarde y el video abre solo con el ambiente y la voz (queda bien).
  La música baja sola cuando habla la narradora (sidechain).
- **Ambiente:** el audio propio de cada toma a `ambiente.volumen` (0,3).
- **Volumen final:** −14 LUFS, pico −1,5 dBFS.
- **Textos (`capa.html`):** el ffmpeg de la Mac no tiene drawtext ni libass. La capa es HTML
  fotografiado cuadro a cuadro con fondo transparente. Tarda unos 3 min para 42 s.
  - Subtítulos en y=1250, o en y=880 cuando la escena tiene tarjeta.
  - Tarjeta en y=1110.
  - Etiqueta arriba a la izquierda, en y=290.
  - Zona segura de Reels: entre y=270 e y=1540.
- **Cortes de subtítulos:**
  - Máximo ~18–26 caracteres por trozo.
  - Se corta en la puntuación.
  - Nunca después de una palabra débil («el», «de», «y»…).
  - Las palabras sueltas cortas se pegan al trozo anterior.
  - «100 %» no se parte.
  - `text-wrap: balance`.
- **Cierre:** cuadros de una animación por código (`cierre.dir`, `desde` y `hasta`), cacheados
  en `capa/`.

## Revisar antes de entregar

- La hoja `revision/final-vN.jpg` (8 cuadros) y, además, cuadros a 540 px de ancho en cada
  tarjeta. Los textos que se salen de la tarjeta y las palabras sueltas solo se ven ahí.
- Que la cara de nadie quede tapada por un subtítulo o una tarjeta.
- Que lo que dice la voz y lo que dicen las tarjetas coincidan con el bot de WhatsApp.

## Costo real del primer video (Mateo, 12-nov-2026)

- Referencia: US$0,07.
- 6 tomas generadas (42 s): US$2,94.
- Voz: centavos.
- Música: sin precio publicado.
- Total: **≈US$3,6**, más 2 tomas que el filtro bloqueó y no se cobraron.
