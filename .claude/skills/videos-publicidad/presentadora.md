# Formato «presentadora a cámara» (estilo creador de contenido)

Aprendido de 3 videos de referencia del 25-nov-2026: la enfermera de GLP-1 (IMG_3885) y dos
de Agro Blonde (carnicería de Cali). Al parecer son de la misma agencia: comparten los
destellos, los subtítulos y el ritmo. Es el formato que más se ve en Reels de negocios locales.

## Qué tienen en común (medido)

| | GLP-1 | Blonde A | Blonde B |
|---|---|---|---|
| Duración | 42 s | 39 s | 40 s |
| Palabras | ~110 | ~105 | ~115 |
| Cortes | 24 (1 cada 1,7 s) | 29 (1 cada 1,3 s) | 25 (1 cada 1,6 s) |
| Volumen | −13 LUFS | −9 LUFS | −10 LUFS |
| Cuadro | 1080×1920, 50 fps | 1080×1920, 60 fps | 1080×1920, 60 fps |

- **Una persona real le habla a la cámara** todo el video. Ella es la historia: no hay
  personajes ni narradora en off.
- **Sin un solo silencio:** se cortan las respiraciones y las pausas (ninguna pausa pasa de
  0,15 s). Eso da el ritmo rápido.
- **Corte a saltos con zoom alterno:** cada 1,3–1,7 s se cambia entre plano abierto y plano
  cerrado (un acercamiento del 115–130 % de la misma toma). En Blonde, además, cambia de
  rincón de la tienda en cada bloque.
- **Insertos (b-roll) de 2–3 s sobre la voz:** ilustran la palabra que se está diciendo (la
  inyección, el formulario, la comida; el corte de carne en la mano). Son 4–12 por video.
- **Destellos cálidos** (naranja/amarillo, «light leak») en 2–3 cambios de tema.
- **Blanco y negro** para el recuerdo («cuando la mamá les decía…»).
- **Un sticker que aparece de golpe** con el producto (el frasco de GLP-1).
- **Logo pequeño arriba en el centro** todo el video (Blonde) y cierre con el logo animado
  sobre negro (2 s).

## Efectos, uno por uno (mirados cuadro a cuadro)

| Efecto | Cómo es | Cómo lo hacemos | Dificultad |
|---|---|---|---|
| **Destello cálido** | 2–4 cuadros quemados en naranja o amarillo justo en el corte; luego entra el plano nuevo. 3 por video. | ffmpeg: subir la exposición y mezclar un degradado naranja en esos cuadros | fácil |
| **Zoom de rebote** | El plano entra más cerrado y se abre suave en ~0,4 s, o al revés. | ffmpeg `zoompan` o recorte animado | fácil |
| **Salto abierto/cerrado** | El mismo plano al 100 % y al ~125 %, alternado en cada corte. | recorte | fácil |
| **Blanco y negro** | Solo en el recuerdo (la mamá), con más contraste y un plano más abierto. | `hue=s=0`, `eq=contrast` | fácil |
| **Inserto en tarjeta** | El b-roll va a pantalla completa con las esquinas redondeadas y un borde negro, como una ventana. | máscara en la capa | fácil |
| **Sticker que salta** | Foto del producto recortada que aparece con un rebote de tamaño. | PNG generado + escala animada | fácil |
| **Texto que se arma** | Las palabras aparecen una por una; la clave entra grande en la 2.ª línea. | whisper por palabra + `capa.html` | fácil |
| **Texto gigante del gancho** | Ocupa todo el ancho desde el cuadro 1 y cambia con el producto que ella levanta (IMPORTADA → NACIONAL). | capa | fácil |
| **Texto detrás de la persona** | En Blonde, el título queda detrás de la cabeza. | recortar a la persona con un modelo de segmentación | medio |
| **Logo fijo arriba** | Pequeño, en el centro, todo el video. Entra con un «pop» en el primer segundo. | capa | fácil |
| **Cierre** | Destello blanco-amarillo, negro y el logo que crece con brillo, sonando un golpe. | animación por código + golpe sintetizado | fácil |
| **Cámara en movimiento** | Acercamiento de grúa al inicio, foco que pasa del producto (en primer plano, desenfocado) a la cara, y producto cerca del lente. | en el prompt de Omni: «slow dolly-in», «rack focus from the product in the foreground to her face», «shallow depth of field» | depende de Omni |
| **Final en bucle** | El último plano repite el gancho, para que al repetirse empalme. | guion | — |

En las transiciones NO hay «whoosh» claro: los agudos no suben en los destellos. El único
efecto de sonido evidente es el golpe con el logo final (Blonde B, +10 dB a 0,5 s de que termina
la voz).

## Subtítulos

- De 1 a 3 palabras, sincronizados **palabra por palabra** con la voz.
- Dos tamaños: la frase en letra pequeña y **la palabra clave grande** en la segunda línea
  («se tira», «clínica», «alimentación», «"plan"», «CARNE NACIONAL», «mis cortes favoritos»).
- Blancos, sin caja, con una sombra suave, a la altura del pecho (~55 % de la altura), nunca
  sobre la cara.
- Letra según la marca: serif fina y elegante (salud y estética) o sans gruesa redondeada
  (Blonde, la más parecida a Poppins Bold/ExtraBold).
- El primer cuadro ya trae el texto grande del gancho («CARNE IMPORTADA / CARNE NACIONAL»),
  para quien ve sin sonido.

## Audio

- Voz al frente, de micrófono cercano y limpia.
- Música de fondo **muy baja y continua**: el piso nunca baja de −23 a −33 dB, así que no hay
  ningún hueco en silencio.
- Golpes o «whoosh» suaves en los destellos.
- Se masteriza fuerte (−9 a −13 LUFS). Para BRYGAR usar **−12 LUFS**, con pico −1 dBFS.

## Guion

Hablado, de ~2,7 palabras por segundo, en el dialecto del público. Es caleño con voseo:
«vení», «una cosita», «¿y vos cuál elegís?».

Tres esqueletos que funcionan:

1. **Refrán o recuerdo → ironía → «pero vení, una cosita» → qué revisar (3 cosas) → qué sí
   (3 cosas) → vuelve al refrán → palabra clave.**
   Es el de GLP-1: «¿Se acuerdan cuando la mamá les decía…?» y termina en «Escríbeme PLAN».
2. **Dilema en el gancho → defender las dos opciones → «no se trata de escoger, se trata de…»
   → pregunta final.**
   Es Blonde A: «¿Importada o nacional?» y termina en «¿Y vos cuál elegís?». La pregunta busca
   comentarios.
3. **«¿Sabes elegir…?» → «hoy te muestro mis favoritos» → 3 casos con nombre propio → «te
   ayudamos a elegir».**
   Es Blonde B: educativo, en forma de lista.

**Llamado con palabra clave** («Escríbeme PLAN»): se mide qué video trajo a quién y el bot de
WhatsApp puede reconocer la palabra.

## Qué cambia para BRYGAR

- **Regla de Meta de atributos personales:** la enfermera dice «tú también quieres ponerte…».
  Eso NO lo podemos decir («¿No tienes EPS?»). Hablar de «la gente», «a más de uno», «su
  vecina», y dejar el «tú/vos» para la oferta («te afiliamos»).
- Las reglas de verdad de [guiones.md](guiones.md) siguen iguales: garantía con su condición,
  nada de «sin EPS no te atienden».
- Persona real o generada:
  - **Real (recomendado):** una persona del equipo graba de corrido con el iPhone, y la edición
    (cortes, zoom, subtítulos, destellos, insertos, música) la hace el código.
  - **Generada con Omni:** sí habla. En la prueba del 25-nov-2026, una toma de 8 s a 360p
    (US$0,18) dijo la frase exacta, con voseo y buen gesto, a −22 LUFS. El riesgo es que la voz
    cambie entre tomas. Probar 2 tomas con la misma foto de referencia antes de hacer un video
    completo. Meta puede marcarlo como «Hecho con IA». Nunca presentarla como una empleada
    real ni como profesional de salud.

## Cómo se hace (herramientas, desde el 25-nov-2026)

Primer video: `resources/publicidad/historias/presentadora-prevenir-eps/`, 42 s, US$6,50 en
total contando las pruebas. Rehecho con todo a 360p, costaría ~US$1,50.

- **Tomas habladas:** en `toma.mjs`, `toma.habla` = la frase exacta (Omni hace la voz y los
  labios), y `toma.mira` para que mire de lado («She looks at an interviewer…»).
  - Una frase por toma, de 3–5 s.
  - **A 360p** (lo pidió Brayan: «hacer los videos con bajo costo»): US$0,023/s.
- **Ángulos de verdad:** Omni copia el encuadre de la foto de referencia, así que pedir «close-up» o
  «45 degrees» en el texto NO cambia el ángulo. Hay que hacer **una foto de referencia por
  ángulo** (`referencia.mjs` con `base`: la foto de frente como imagen + «new photo from a
  different camera position…»): `ang-cerca`, `ang-perfil` (mira al entrevistador) y
  `ang-abierto`. Con eso sí salen primer plano, perfil y plano abierto, con la misma persona y
  el mismo set.
- **Nada de saltos de zoom** sobre la misma toma: a Brayan no le gustó que «la cámara se moviera
  brusco sin cambiar la posición de la persona». El corte es un cambio de ángulo, y cada
  pedazo lleva solo un acercamiento lento y continuo.
- **Montaje** (`montaje` en el proyecto.json): lista de pedazos `{de, desde, hasta, bn, destello,
  claves, pantalla, tarjetas}`. `desde`/`hasta` son frases del guion. Ojo: se busca la primera
  aparición, así que `desde: "te"` cortó en «no **te** dan» y repitió media frase; usar 2+
  palabras.
- **Armado:** `node armar-presentadora.mjs <slug>`. Whisper local da el tiempo de cada palabra,
  quita silencios, repeticiones («y tus hijos y tus hijos») y lo que sobre, pone los insertos en
  tarjeta redondeada, la capa (`capa-presentadora.html`), la música y −12 LUFS.
- **Gancho:** va debajo de la cara (`gancho.y` ≈ 1060) y mientras está no hay subtítulos. Arriba
  tapaba la cara.
- **Sin logo arriba** (no se veía bien); el logo va solo en el cierre.
- **Pronunciación:** Omni lee «BRYGAR» como «Brai-gar». En `habla` se escribe **«Brigar»** y en
  `pantalla` `{"Brigar": "BRYGAR"}`.
- **Lo que bloquea Omni** (no se cobra): el llamado «escribinos la palabra PREVENIR», «escribinos
  PREVENIR por WhatsApp» y «te afiliamos el mismo día que pagás». Pasó «Escribinos y hablamos».
  La palabra clave va en pantalla, no hablada.
- **Whisper se equivoca en los cortes** («Si estás» → «Un mal día»), aunque el audio esté
  completo: revisar el envolvente antes de culpar al corte.
- **Duración de cada toma:** Omni empieza a hablar como al segundo 1,3, y si la toma se acaba
  antes, la frase queda mocha («pa' preve-»). Pedir `dur` = 1,5 + palabras ÷ 2,7 + 0,5, redondeado
  hacia arriba. Revisar que la toma no termine con voz: los últimos 0,3 s deben estar por debajo
  de −45 dB.
- **Mirar a cámara:** en la oferta y en el llamado ella mira al lente, también en los planos de lado
  (la foto `ang-lado` dice «looks DIRECTLY INTO THE LENS»). Mirar a un entrevistador fuera de
  cuadro (`ang-perfil`) solo en una frase del problema.
- **Siglas:** «ARL» se escribe «a ere ele» (si no, dice «a erre ele») con
  `pantalla: {"a ere ele": "ARL"}`. Lo mismo con cualquier sigla que suene mal.
- **Límite de Omni:** 40 pedidos al día en el plan Tier 1. Al pasarlo, responde «Rate limit
  exceeded… retry in 8h» o «fetch failed», pero a ratos deja pasar alguno: pedir de a una y no
  en paralelo.
