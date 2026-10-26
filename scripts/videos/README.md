# Videos demostrativos de Brynex

Dos tipos de video, grabados en Brynex de verdad con un aliado de demostración:

- **Manual**: un capítulo por módulo (escritorio 1920×1080) y un clip corto por
  tarea, para la página del manual en `public/manual/` → **brynex.co/manual/**.
- **Comercial**: las bondades de Brynex en unos 2½ minutos (escritorio y una
  versión vertical 1080×1920 para redes).

Brynex se usa sobre todo en el computador: cada escena declara en `formatos`
dónde se graba (por defecto solo escritorio). El celular queda para la entrada
y el Cobro en visita, que está hecho para el celular.

Adaptado del sistema de videos de LiderApp (`LiderApp/scripts/videos`).

## Nada real, nada sale del Mac

- **Base aparte.** SQL Server 2022 en Docker (`brynex-videos-sql`), solo en
  `127.0.0.1:14333`: la base `BryNex_Videos` y su plantilla. Nunca la de
  producción ni el túnel (1433) que usan las otras sesiones.
- **Lo único que se lee de producción** (en solo lectura, por el túnel) es la
  ESTRUCTURA (`demo/esquema.php`, del catálogo del sistema) y los CATÁLOGOS de
  una lista blanca (`demo/catalogos.php`: EPS, ARL, AFP, cajas, ciudades,
  modalidades, planes, tarifas, permisos), más la tabla de precios de BRYGAR.
  Queda una copia local en `~/Movies/Brynex-videos/base/catalogos-produccion.json`
  (fuera del repo) y los siguientes `rehacer` no tocan producción.
- **Gente inventada** (`demo/sembrar.php`): aliado «Seguridad Social Demo» (id
  100), cédulas 9985…, celulares 3995…, correos `@example.com`, NIT 998500xxx.
- **La única persona real** es **Yuly Paola Garcia Vidal (CC 1143961945)**,
  autorizada por el dueño para mostrar que la consulta por cédula funciona.
  Se ve su nombre, su cédula, su EPS, su fondo y su régimen: su respuesta del
  registro oficial guardada el 21-ago-2026 (`ruaf_consultas`), que se deja en
  la caché de la app. **No se consulta en vivo.** Sus contratos y planillas son
  inventados (razón social ficticia), igual que su celular, correo y dirección.
- **La red está cerrada.** La copia de la app (`~/brynex-videos-app`, un
  worktree de `origin/main`) tiene su propio `.env` sin una sola llave, con los
  proxys a `127.0.0.1:9`, y se sirve dentro de `sandbox-exec`, que no deja
  conectar a nada fuera de localhost (`app-videos.sh probar` lo comprueba).
  Chrome, además, aborta toda petición que no sea local o una librería de CDN,
  y la anota en `linea.json → salidas`.
- Lo que depende de afuera (afiliar por API a la ARL, liquidar la planilla con
  el operador, enviar WhatsApp, la IA) **se muestra hasta donde llega y el
  video lo dice**.

## Piezas

| Archivo | Qué hace |
|---|---|
| `base-videos.sh` | `contenedor` (una vez), `rehacer` (todo de cero), `restablecer` (antes de cada toma), `plantilla`, `sql "…"`. |
| `demo/esquema.php` | La estructura de producción, sin datos, como SQL (162 tablas, sin las copias de respaldo). |
| `demo/catalogos.php` | Catálogos + el aliado demo + la tabla de precios de BRYGAR + la respuesta oficial de Yuly. |
| `demo/sembrar.php` | Cuentas por rol (claves → `.env.videos`), empresas, clientes, y la historia **hecha por la app** (contratos, facturas, planos, pagos, radicados, incapacidades, tareas…) con fechas relativas al día en que se siembra. |
| `app-videos.sh` | `preparar` (worktree + `.env` + build), `servir` (en el sandbox, puerto 8077), `sembrar`, `cache`, `probar`. |
| `roles.mjs` | Entra con cada rol y anota qué módulos abren y qué muestra el menú → `~/Movies/Brynex-videos/roles.md`. |
| `motor.mjs` | Graba por CDP cuadro a cuadro y dibuja en la página el cursor, los clics, los subtítulos, los títulos, las tarjetas, el resaltado y el zoom. Anota tareas, frases, clics, desbordes, salidas y si el Mac se durmió. |
| `escenas/*.mjs` | Una por capítulo, más `comercial.mjs`. Exportan `rol`, `formatos`, `antes` (SQL tras restablecer) y la escena. |
| `grabar.mjs` | `node grabar.mjs <salida> <escena> [formato]`: restablece la base, corre `antes` y graba. |
| `voz.mjs` | `muestra`, `listar`, `generar` (Gemini TTS, voz Aoede, Avi), `mezclar` (voz en su segundo + un tic por clic). |
| `musica.mjs` | Lyria: `clip <estilo>` (30 s) y `cancion corporativa fondo` (la pieza larga). |
| `armar.mjs` | Cuadros → MP4 (H.264 yuv420p, rango tv, 30 fps), voz y música, clips por tarea (original y liviano), `manual.json`, el manual completo y el comercial en vertical. |
| `validar.mjs` | Decodifica todo, revisa formato y sonido, hace las hojas de cuadros y repasa los avisos de las tomas. |

## Paso a paso

```bash
# 0. Una vez
npm i --prefix ~/brynex-videos-pw playwright@1.56.1   # aparte: no es dependencia del proyecto
export PLAYWRIGHT_DIR=~/brynex-videos-pw
export CHROME="$HOME/Library/Caches/ms-playwright/chromium-1243/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing"
open -a Docker
scripts/videos/base-videos.sh contenedor
# La llave de Gemini va en .env.videos, NO en .env (Brynex la usaría para transcribir audios contra producción):
grep '^GEMINI_API_KEY=' ../LiderApp/.env >> .env.videos

# 1. El día en que se graba: la demo de cero (las fechas son relativas a hoy)
scripts/videos/app-videos.sh preparar
scripts/videos/base-videos.sh rehacer      # la primera vez necesita el túnel (ssh -fN netcup-db)
scripts/videos/app-videos.sh servir &      # http://localhost:8077, dentro del sandbox
scripts/videos/app-videos.sh probar        # localhost sí, afuera no

# 2. Voz y música ANTES de grabar (cada subtítulo dura lo que su audio)
node scripts/videos/voz.mjs listar
node scripts/videos/voz.mjs generar        # 100 voces por día y por modelo: repetir al otro día si falta
node scripts/videos/musica.mjs cancion corporativa fondo

# 3. Grabar, mezclar, armar y validar
for e in comercial entrada clientes contratos afiliaciones facturacion cobros visita caja planos incapacidades \
         tareas informes comunicacion cotizador administracion portal-empresas roles; do
  node scripts/videos/grabar.mjs ~/Movies/Brynex-videos/grab $e
done
node scripts/videos/voz.mjs mezclar ~/Movies/Brynex-videos/grab
node scripts/videos/armar.mjs ~/Movies/Brynex-videos/grab ~/Movies/Brynex-videos/salida
node scripts/videos/validar.mjs ~/Movies/Brynex-videos/salida ~/Movies/Brynex-videos/grab

# 4. A la web (solo lo liviano)
cp ~/Movies/Brynex-videos/salida/web/clips/*.mp4 public/manual/clips/
cp ~/Movies/Brynex-videos/salida/web/manual.json ~/Movies/Brynex-videos/salida/web/comercial-portada.jpg public/manual/
```

Originales, capítulos, completos y el comercial quedan en `~/Movies/Brynex-videos/salida`
(fuera del repo). Los cuadros de cada toma pesan varios GB: se pueden borrar
(`grab/*/cuadros`) una vez armado y validado el capítulo.

## Cuidados

- **El Mac no puede dormirse.** Con la tapa cerrada y en batería se suspende y
  la toma se congela: `linea.json → congelado` lo anota y la toma se repite.
- **Fechas.** La demo calcula mora, semáforos y la caja del día contra la
  fecha real: si se graba otro día, `rehacer` antes.
- **El driver de SQL Server necesita OpenSSL 3** (Homebrew dejó `openssl` en la 4):
  los scripts ponen `DYLD_LIBRARY_PATH=/opt/homebrew/opt/openssl@3/lib`.
  `sandbox-exec`, `env` y composer la borran por ser binarios del sistema: por
  eso `app-videos.sh` la vuelve a poner dentro del sandbox y sirve con `php -S`.
- **Cuota de Gemini (plan gratis): 100 voces por día y por modelo.** Todo con el
  mismo modelo (`gemini-3.1-flash-tts-preview`), para que la voz suene igual. La
  caché (`~/Movies/Brynex-videos/voz-cache`) va por texto: cambiar una frase
  gasta una voz.
- **Roles.** Lo que dice el manual de quién puede qué sale de `roles.mjs`
  (entrando con cada cuenta), no del seeder: varios controladores exigen un rol
  fijo además del permiso (ver «Hallazgos»).

## Qué afirma el comercial (y su respaldo)

| Afirmación | Respaldo |
|---|---|
| Consulta el registro oficial con la cédula | `RegistroOficialService` (BDUA/RUAF por el API de Enlace) |
| ARL Sura y Colmena por API | `ArlSuraApiService`, `ColmenaApiService`, gate `automatizar-arl` |
| Novedades en «las principales EPS» y cajas (sin nombrarlas, decisión del dueño 26-oct-2026) | EPS Sura, Nueva EPS, Salud Total, S.O.S., Sanitas, Comfenalco, Comfandi (`app/Services/*`, gate `automatizar-portales`; S.O.S. y Sanitas con la extensión de Chrome) |
| Planilla PILA por API con Enlace y Simple | `SuaporteApiService` (misma plataforma, dos hosts) |
| Plano TXT y Excel por operador | `PlanoPilaTxtService`, `Excel*Service` |
| Soportes de pago que se descargan solos | `DescargarSoportesPlanillaJob` |
| Validaciones antes de pagar | salario mínimo y nivel de riesgo (`ContratoController::validar`), inconsistencias del operador (`autocorregir`), cuadre de caja |
| Cobro en visita, préstamos, anticipos, portal de empresas, página con cotizador, WhatsApp con IA | sus módulos (`/visita`, `/admin/prestamos`, `/portal`, `/aliado/{slug}`, `AsistenteIaService`) |
| **No se menciona**: factura electrónica por API de Dataico | bloqueada por Dataico (401 de numeración) |

## Hallazgos de la app (reportados, sin tocar)

Al entrar con cada rol (24-oct-2026):

- `ContratoController`, `AsesorController`: `role:superadmin|admin|usuario` en el
  constructor → contable y asesor tienen `contratos.ver` pero reciben 403.
- `PublicidadController`, `PaginaAliadoAdminController`, `RedesSocialesController`,
  `TrasladoRazonSocialController`: `role:superadmin|admin` → el rol usuario tiene
  sus permisos de ver, pero recibe 403.
- `AnticipoController::informe`: `hasRole(admin|superadmin)`.
- `GastoAdminController::store`: «Solo el superadmin», aunque el admin trae `gastos.gestionar`.
- `admin.razones.cargos.store` (`routes/web.php`): escritura sin ningún permiso, solo `auth`.
- El inicio muestra «Planos SS» al rol usuario, que no tiene `planos.ver`.
