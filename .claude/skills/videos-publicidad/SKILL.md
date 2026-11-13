---
name: videos-publicidad
description: >
  Guionista y productor de videos publicitarios de BRYGAR y BryNex para Reels, TikTok y la
  pauta de Meta: escribe el guion (gancho, historia, oferta), lo hace revisar, genera las
  tomas con personas (Gemini Omni), la voz colombiana, la música y arma el video final con
  subtítulos, tarjetas y el cierre de marca. Actívate cuando el usuario pida: video, comercial,
  reel, anuncio en video, guion, guion con personas, historia, voz en off, locución, narradora,
  música para video, pieza de pauta en video, «haz un video como la #116», Veo, Omni,
  generar tomas, animación por código.
---

# Videos publicitarios (BRYGAR / BryNex)

Dos formas de hacer un video, que se pueden mezclar:

| Tipo | Cuándo | Cómo | Costo |
|---|---|---|---|
| **Historia con personas** | Emoción, urgencia, confianza (planes para personas) | Esta carpeta: Omni + voz + música + capa | ~US$3–5 por video de 40 s |
| **Animación por código** | Precio, funciones, listas, cierre de marca (#114–#116) | `resources/publicidad/video-brynex-3/` (HTML + Playwright) | Gratis |

El cierre de las historias se saca de una animación por código (`cierre` en el proyecto).

## Antes de empezar, lee

- [guiones.md](guiones.md): cómo escribir el guion y las reglas de la casa. Son obligatorias.
- [produccion.md](produccion.md): tomas, voz, música, armado, qué bloquea Omni y trampas conocidas.
- La memoria del producto que se anuncia (p. ej. `plan-eps-sin-pension-125`). El video y el bot
  de WhatsApp deben decir lo mismo.

## Flujo (no saltarse pasos que gastan plata sin el visto bueno)

1. **Brief.** Producto, precio exacto, a quién, qué emoción, dónde se pauta y duración. Si
   falta algo que cambie el guion, se pregunta. Si es un plan nuevo, se pregunta cómo funciona
   por dentro y qué se puede decir.
2. **Guion: 2 o 3 opciones** con ganchos distintos, en tabla (tiempo · imagen · voz).
   Cada opción con su costo estimado.
3. **Revisor.** Lanzar el agente `revisor-guiones` (`.claude/agents/revisor-guiones.md`) con el
   guion. Si no aparece como tipo de agente, usa uno general que lea ese archivo y lo siga.
   Aplicar lo que tenga razón y decirle al usuario qué se cambió y por qué.
4. **El usuario elige** y aprueba el gasto.
5. **Proyecto.** `resources/publicidad/historias/<slug>/proyecto.json` (ver el de
   `mateo-eps-sin-pension`). Lo pesado va en `storage/app/aliados-video/historias/<slug>/`,
   que git ignora.
6. **Muestra barata primero:** foto de referencia (`referencia.mjs`) y UNA toma (`toma.mjs`)
   con la voz de esa escena. Se manda con SendUserFile y se espera el visto bueno.
7. **Producción:**
   1. `voz.mjs`. La voz va primero, porque las tomas se piden del largo de su frase + ~1,5 s.
   2. Las demás tomas, en paralelo.
   3. `musica.mjs`.
   4. Revisar cada toma con su hoja de cuadros (Read sobre `revision/<toma>.jpg`). Una toma
      mala se repite con el prompt corregido. Ninguna toma sin mirar llega al armado.
8. **Armado:** `armar.mjs`. Revisar `revision/final-vN.jpg` y sacar cuadros a tamaño real en
   las escenas con tarjeta: textos cortados, palabras sueltas, tapar caras. Corregir y rearmar.
   Medir: −14 LUFS y pico ≤ −1 dBFS (armar.mjs lo imprime).
9. **Entrega:** SendUserFile con el MP4 y lo gastado (`gastos.log`). Publicar o pautar SOLO si
   el usuario lo pide. El cómo está en la memoria `videos-por-codigo-publicidad`: pieza con
   `origen='subida'` y `PublicacionPublisher::publicar`.

## Herramientas (`herramientas/`, Node, sin dependencias nuevas)

```bash
cd .claude/skills/videos-publicidad/herramientas
node referencia.mjs <slug> <id-ref>          # foto de referencia (~US$0,07)
node toma.mjs <slug> <escena>                # muestra prompt y costo, NO gasta
node toma.mjs <slug> <escena> --si           # genera (~US$0,07/s en 720p) → tomas/<escena>-vN.mp4
node voz.mjs <slug> [--solo <escena>]        # voz por escena (centavos)
node musica.mjs <slug> [--otra]              # música con Lyria
node armar.mjs <slug> [--rapido]             # video final (--rapido: sin textos, para revisar el corte)
```

La llave de Gemini sale de `.env.videos` y nunca del `.env` de Brynex. Cada gasto queda en
`<media>/gastos.log`.

## Lo que el usuario ya decidió

- **Letra:** Poppins Bold/SemiBold. Las palabras entran una a una con rebote y las clave van
  con degradado dorado (`capa.html`). Es la de los videos #114–#116: «me gustó mucho más que
  como se hacían antes con las fuentes» (12-nov-2026). No cambiarla sin preguntar.
- **Ahorrar USD:** primero la opción barata. Veo Lite ya no existe; Omni a 720p es lo
  equivalente.
- **Marca correcta:** BRYGAR para afiliaciones a personas, BryNex para el software de aliados.
- **Cero datos de clientes reales** y cero cifras inventadas en los videos. El número de
  WhatsApp va en el copy, no en el video.
