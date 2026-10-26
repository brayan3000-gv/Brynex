#!/usr/bin/env bash
# La copia de Brynex con la que se graban los videos: un worktree limpio de
# origin/main FUERA del repo (~/brynex-videos-app), con su propio .env que apunta
# a la base de Docker (base-videos.sh) y sin una sola llave. Se sirve dentro de
# `sandbox-exec`, que le prohíbe al PHP (y a cualquier proceso que lance: robots
# de Node, curl) conectarse a nada que no sea localhost: ninguna cédula, mensaje
# ni consulta sale del Mac aunque una pantalla lo intente.
#
#   scripts/videos/app-videos.sh preparar   # worktree + .env + build de Vite
#   scripts/videos/app-videos.sh servir     # http://localhost:8077, en primer plano
#   scripts/videos/app-videos.sh probar     # comprueba que la red de afuera está cerrada
#
# Nunca usa el .env del repo (que es el de producción).
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
APP="${VIDEOS_APP:-$HOME/brynex-videos-app}"
PUERTO="${VIDEOS_PUERTO:-8077}"
VIDEOS_ENV="$REPO/.env.videos"
# El driver ODBC de Microsoft necesita OpenSSL 3; Homebrew dejó `openssl` en la 4.
# macOS borra las DYLD_* al ejecutar binarios del sistema (sandbox-exec, env,
# el `#!/usr/bin/env php` de composer), así que se vuelve a poner justo antes
# de cada php: `con_ssl php …` dentro del sandbox.
SSL3="/opt/homebrew/opt/openssl@3/lib"
export DYLD_LIBRARY_PATH="$SSL3${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}"
en_sandbox() { sandbox-exec -p "$PERFIL" /bin/bash -c 'export DYLD_LIBRARY_PATH="'"$SSL3"'"; exec "$@"' _ "$@"; }

leer() { grep -E "^$1=" "$VIDEOS_ENV" | head -1 | cut -d= -f2-; }

# Solo localhost: ni la salida directa, ni DNS (el socket de mDNSResponder), nada.
PERFIL='(version 1)
(allow default)
(deny network-outbound)
(allow network-outbound (remote ip "localhost:*"))
(allow network-outbound (remote unix-socket (path-literal "/private/var/run/syslog")))'

case "${1:-}" in
  preparar)
    if [ ! -d "$APP" ]; then
      git -C "$REPO" fetch -q origin
      git -C "$REPO" worktree add --detach "$APP" origin/main
      # Clonados (APFS), no enlazados: con vendor enlazado App\* se carga del repo principal.
      cp -Rc "$REPO/vendor" "$APP/"
      cp -Rc "$REPO/node_modules" "$APP/"
    fi
    CLAVE_SA="$(leer VIDEOS_SQL_SA)"
    [ -n "$CLAVE_SA" ] || { echo "Falta VIDEOS_SQL_SA en .env.videos (base-videos.sh contenedor)"; exit 1; }
    cat > "$APP/.env" <<EOF
# Generado por scripts/videos/app-videos.sh — SOLO para grabar videos.
APP_NAME=BryNex
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=http://localhost:$PUERTO
ASSET_URL=
LOG_CHANNEL=single
LOG_LEVEL=warning

DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=14333
DB_DATABASE=BryNex_Videos
DB_USERNAME=sa
DB_PASSWORD=$CLAVE_SA
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
FINANZAS_DB_HOST=127.0.0.1
FINANZAS_DB_PORT=14333
FINANZAS_DB_DATABASE=BryNex_Finanzas_Videos
FINANZAS_DB_USERNAME=sa
FINANZAS_DB_PASSWORD=$CLAVE_SA
DB_LEGACY_HOST=127.0.0.1
DB_LEGACY_PORT=9
DB_LEGACY_PASSWORD=

SESSION_DRIVER=file
SESSION_LIFETIME=480
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=null
BROADCAST_DRIVER=log
MAIL_MAILER=log

# Todo lo que sale a internet, a un puerto cerrado. El sandbox es la segunda llave.
HTTPS_PROXY=http://127.0.0.1:9
HTTP_PROXY=http://127.0.0.1:9
NO_PROXY=localhost,127.0.0.1
ARL_NODE_BIN=/usr/bin/false
ADRES_WORKER_URL=http://127.0.0.1:9
DATAICO_BASE_URL=http://127.0.0.1:9
SUAPORTE_API_URL=http://127.0.0.1:9
SUAPORTE_AUTH_URL=http://127.0.0.1:9
SUAPORTE_ALIADO_FALLBACK_RUAF=0
AVAPPI_VOZ_URL=http://127.0.0.1:9
AVAPPI_VOZ_KEY=
AVAPPI_VOZ_SECRETO=
AVAPPI_VOZ_ALIADOS=
GARVIS_ACTIVO=false
LUCA_COBROS_URL=
PROXY_COLOMBIA=
NUEVA_EPS_TUNEL=
COMFENALCO_TUNEL=
GEMINI_API_KEY=
WHATSAPP_BRYNEX_TOKEN=
WHATSAPP_BRYNEX_PHONE_NUMBER_ID=
WHATSAPP_BRYNEX_WABA_ID=
REVERB_HOST=127.0.0.1
REVERB_PORT=9
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=9
EOF
    chmod 600 "$APP/.env"
    (cd "$APP" && composer dump-autoload -q --no-scripts && php artisan package:discover -q && php artisan config:clear -q && php artisan view:clear -q && npm run build --silent >/dev/null)
    mkdir -p "$APP/storage/app/public" "$APP/public"
    [ -e "$APP/public/storage" ] || ln -s "$APP/storage/app/public" "$APP/public/storage"
    echo "Lista: $APP (.env propio, base BryNex_Videos en 127.0.0.1:14333)"
    ;;
  servir)
    cd "$APP"
    grep -q '^DB_PORT=14333$' .env || { echo "El .env de $APP no apunta a la base de videos. Corre: $0 preparar"; exit 1; }
    cd public && TZ=America/Bogota PHP_CLI_SERVER_WORKERS=4 en_sandbox php -S "127.0.0.1:$PUERTO" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
    ;;
  sembrar)
    cd "$APP"
    grep -q '^DB_PORT=14333$' .env || { echo "El .env de $APP no apunta a la base de videos."; exit 1; }
    TZ=America/Bogota VIDEOS_APP="$APP" VIDEOS_ENV="$VIDEOS_ENV" en_sandbox php "$REPO/scripts/videos/demo/sembrar.php"
    ;;
  cache)
    cd "$APP"
    VIDEOS_APP="$APP" VIDEOS_ENV="$VIDEOS_ENV" en_sandbox php "$REPO/scripts/videos/demo/sembrar.php" --solo-cache
    ;;
  probar)
    # Dentro del mismo sandbox: localhost sí, afuera no (ni por IP ni por nombre).
    sandbox-exec -p "$PERFIL" curl -s -o /dev/null -m 5 -w 'localhost %{http_code}\n' "http://127.0.0.1:$PUERTO/login" || echo "localhost NO responde (¿está corriendo servir?)"
    if sandbox-exec -p "$PERFIL" curl -s -o /dev/null -m 5 https://1.1.1.1; then echo "FALLA: salió a 1.1.1.1"; exit 1; else echo "afuera por IP: bloqueado ✓"; fi
    if sandbox-exec -p "$PERFIL" curl -s -o /dev/null -m 5 https://graph.facebook.com; then echo "FALLA: salió a graph.facebook.com"; exit 1; else echo "afuera por nombre: bloqueado ✓"; fi
    if en_sandbox php -r 'exit(@file_get_contents("https://api.anthropic.com") === false ? 0 : 1);'; then echo "PHP hacia afuera: bloqueado ✓"; else echo "FALLA: PHP salió"; exit 1; fi
    ;;
  *) echo "Uso: $0 preparar|servir|probar"; exit 1;;
esac
