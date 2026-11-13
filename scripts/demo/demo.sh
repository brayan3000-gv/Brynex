#!/usr/bin/env bash
# La demo pública de BryNex (demo.brynex.co) armada y probada en el Mac.
#
# Reutiliza la infraestructura de los videos (scripts/videos): el mismo SQL
# Server en Docker (`brynex-videos-sql`, solo 127.0.0.1:14333), la estructura y
# los catálogos de producción leídos en solo lectura, y el mismo sembrado de
# gente inventada, con dos diferencias:
#   - va a otra base, BryNex_Demo, y la app corre con BRYNEX_DEMO=true
#     (todo lo de afuera se simula, ver config/demo.php);
#   - NO lleva a Yuly (la única persona real de los videos: su autorización es
#     solo para los videos).
#
#   scripts/demo/demo.sh app          # copia aislada de la app (~/brynex-demo-app) con su .env
#   scripts/demo/demo.sh base         # BryNex_Demo de cero: estructura + catálogos + sembrado + .bak
#   scripts/demo/demo.sh base --esquema   # igual, pero vuelve a leer la estructura de producción
#   scripts/demo/demo.sh servir       # http://localhost:8078, dentro del sandbox (solo localhost)
#   scripts/demo/demo.sh sincronizar  # vuelve a copiar el código del repo a la copia
#   scripts/demo/demo.sh subir        # la plantilla sin gente a netcup (ver scripts/demo/servidor)
#   scripts/demo/demo.sh capitulos [--servidor]  # los capítulos del manual para «¿Cómo funciona?»
#   scripts/demo/demo.sh sql "SELECT …"
#
# La clave de la cuenta de la demo va en .env.demo (ignorado por git).
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
APP="${DEMO_APP:-$HOME/brynex-demo-app}"
PUERTO="${DEMO_PUERTO:-8078}"
BASE_DIR="${DEMO_DIR:-$HOME/Movies/Brynex-demo/base}"
DEMO_ENV="$REPO/.env.demo"
VIDEOS_ENV="$REPO/.env.videos"
NOMBRE=brynex-videos-sql
BAK_CATALOGOS=/var/opt/mssql/backup/BryNex_Demo_Catalogos.bak
BAK_BASE=/var/opt/mssql/backup/BryNex_Demo_Base.bak
SSL3="/opt/homebrew/opt/openssl@3/lib"
export DYLD_LIBRARY_PATH="$SSL3${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}"

PERFIL='(version 1)
(allow default)
(deny network-outbound)
(allow network-outbound (remote ip "localhost:*"))
(allow network-outbound (remote unix-socket (path-literal "/private/var/run/syslog")))'
en_sandbox() { sandbox-exec -p "$PERFIL" /bin/bash -c 'export DYLD_LIBRARY_PATH="'"$SSL3"'"; exec "$@"' _ "$@"; }

leer() { { grep -E "^$1=" "$2" 2>/dev/null || true; } | head -1 | cut -d= -f2-; }
clave_sa() { leer VIDEOS_SQL_SA "$VIDEOS_ENV"; }
sql() {
  docker exec -i -e SQLCMDPASSWORD="$(clave_sa)" "$NOMBRE" /opt/mssql-tools18/bin/sqlcmd -I \
    -S localhost -U sa -C -b -h -1 -W "$@"
}
clave_demo() {
  if [ -z "$(leer DEMO_CLAVE "$DEMO_ENV")" ]; then
    echo "DEMO_CLAVE=Demo-$(openssl rand -hex 3)" >> "$DEMO_ENV"
    chmod 600 "$DEMO_ENV"
  fi
  leer DEMO_CLAVE "$DEMO_ENV"
}

# Lo de la demo que todavía no esté commiteado. Una vez commiteado ya viene en
# HEAD y copiarlo de nuevo no cambia nada.
ARCHIVOS_DEMO=(
  config/demo.php config/app.php routes/demo.php
  app/Providers/DemoServiceProvider.php app/Services/Demo app/Http/Controllers/DemoController.php
  app/Http/Middleware/InyectarDemo.php resources/views/demo scripts/demo scripts/videos/demo
  app/Services/RegistroOficialService.php app/Services/SuaporteApiService.php
  app/Services/EnlaceInformeIndividualService.php app/Services/Ia/IaProviderFactory.php
  app/Services/WhatsappApiService.php app/Services/MiPlanilla/MiPlanillaPortalService.php
  app/Services/PortalCorreccionSaludService.php app/Console/Commands/WhatsappPlantillaAvisoRetiro.php
  app/Console/Commands/WhatsappPlantillaRecibo.php app/Console/Commands/WhatsappPlantillasSistema.php
  app/Models/DemoProspecto.php app/Http/Controllers/DemoRelevoController.php routes/demo-relevo.php
  app/Services/WhatsappWebhookService.php database/migrations/2026_11_13_160000_create_demo_prospectos_table.php
)

copiar_codigo() {
  # El código COMMITEADO (HEAD o DEMO_REF), nunca el árbol de trabajo entero: en
  # el repo suele haber cambios a medio hacer de otras sesiones (columnas que
  # producción aún no tiene) y la demo se caería con ellos.
  local tmp; tmp="$(mktemp -d)"
  git -C "$REPO" archive "${DEMO_REF:-HEAD}" | tar -x -C "$tmp"
  (cd "$REPO" && for f in $(git ls-files -mo --exclude-standard -- "${ARCHIVOS_DEMO[@]}"); do
     mkdir -p "$tmp/$(dirname "$f")"; cp "$f" "$tmp/$f"; done)
  mkdir -p "$APP"
  rsync -a --delete \
    --exclude '/.env' --exclude '/storage' --exclude '/node_modules' --exclude '/vendor' \
    --exclude '/public/storage' --exclude '/public/build' --exclude '/public/hot' --exclude '/public/manual/capitulos' \
    "$tmp/" "$APP/"
  rm -rf "$tmp"
}

case "${1:-}" in
  app)
    [ -n "$(clave_sa)" ] || { echo "Falta VIDEOS_SQL_SA en .env.videos (scripts/videos/base-videos.sh contenedor)"; exit 1; }
    copiar_codigo
    # Clonados (APFS), no enlazados: con vendor enlazado App\* se carga del repo principal.
    [ -d "$APP/vendor" ] || cp -Rc "$REPO/vendor" "$APP/"
    [ -d "$APP/node_modules" ] || cp -Rc "$REPO/node_modules" "$APP/"
    mkdir -p "$APP"/storage/{app/public,framework/{cache/data,sessions,views},logs}
    CLAVE="$(clave_demo)"
    # La llave de la app se conserva entre corridas: cifra las credenciales de prueba sembradas.
    LLAVE="$(leer APP_KEY "$APP/.env")"
    [ -n "$LLAVE" ] || LLAVE="base64:$(openssl rand -base64 32)"
    cat > "$APP/.env" <<EOF
# Generado por scripts/demo/demo.sh — la DEMO pública, en el Mac.
APP_NAME="BryNex Demo"
APP_ENV=demo
APP_KEY=$LLAVE
APP_DEBUG=false
APP_URL=http://localhost:$PUERTO
LOG_CHANNEL=single
LOG_LEVEL=info

BRYNEX_DEMO=true
DEMO_ALIADO_ID=100
DEMO_USUARIO=9985000001
DEMO_CLAVE=$CLAVE

DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=14333
DB_DATABASE=BryNex_Demo
DB_USERNAME=sa
DB_PASSWORD=$(clave_sa)
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
FINANZAS_DB_HOST=127.0.0.1
FINANZAS_DB_PORT=14333
FINANZAS_DB_DATABASE=BryNex_Finanzas_Demo
FINANZAS_DB_USERNAME=sa
FINANZAS_DB_PASSWORD=$(clave_sa)
DB_LEGACY_HOST=127.0.0.1
DB_LEGACY_PORT=9
DB_LEGACY_PASSWORD=

SESSION_DRIVER=file
SESSION_LIFETIME=240
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
BROADCAST_DRIVER=log
MAIL_MAILER=log

# Segunda llave (la primera es el modo demo): todo lo de afuera a un puerto cerrado.
ARL_NODE_BIN=/usr/bin/false
ADRES_WORKER_URL=http://127.0.0.1:9
DATAICO_BASE_URL=http://127.0.0.1:9
SUAPORTE_API_URL=http://127.0.0.1:9
SUAPORTE_AUTH_URL=http://127.0.0.1:9
GARVIS_ACTIVO=false
PROXY_COLOMBIA=
NUEVA_EPS_TUNEL=
COMFENALCO_TUNEL=
GEMINI_API_KEY=
WHATSAPP_BRYNEX_TOKEN=
REVERB_HOST=127.0.0.1
REVERB_PORT=9
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=9
EOF
    chmod 600 "$APP/.env"
    # artisan no arranca sin la base (routes/web.php lee `aliados` al cargar): la
    # primera vez falla hasta que se corre `base`, y no importa.
    (cd "$APP" && composer dump-autoload -q --no-scripts && npm run build --silent >/dev/null)
    (cd "$APP" && php artisan config:clear -q && php artisan view:clear -q) 2>/dev/null || true
    [ -e "$APP/public/storage" ] || ln -s "$APP/storage/app/public" "$APP/public/storage"
    echo "Lista: $APP (BryNex_Demo en 127.0.0.1:14333). Cuenta: 9985000001 / $CLAVE"
    ;;
  sincronizar)
    copiar_codigo
    (cd "$APP" && composer dump-autoload -q --no-scripts && npm run build --silent >/dev/null && php artisan view:clear -q)
    echo "Código copiado a $APP"
    ;;
  base)
    [ -f "$APP/.env" ] || { echo "Primero: $0 app"; exit 1; }
    mkdir -p "$BASE_DIR"
    ESQUEMA="$BASE_DIR/esquema.sql"
    # La estructura de producción, solo lectura por el túnel (ssh -fN netcup-db).
    if [ ! -s "$ESQUEMA" ] || [ "${2:-}" = "--esquema" ]; then
      php "$REPO/scripts/videos/demo/esquema.php" > "$ESQUEMA.tmp" && mv "$ESQUEMA.tmp" "$ESQUEMA"
    fi
    for b in BryNex_Demo BryNex_Finanzas_Demo; do
      sql -Q "IF DB_ID('$b') IS NOT NULL BEGIN ALTER DATABASE [$b] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$b]; END; CREATE DATABASE [$b] COLLATE Modern_Spanish_CI_AS;"
    done
    docker cp "$ESQUEMA" "$NOMBRE:/tmp/esquema-demo.sql"
    sql -d BryNex_Demo -I -i /tmp/esquema-demo.sql >/dev/null
    # Catálogos (la copia local de los videos; --refrescar en catalogos.php la relee).
    VIDEOS_DB=BryNex_Demo DEMO_PUBLICO=1 php "$REPO/scripts/videos/demo/catalogos.php" ${REFRESCAR:+--refrescar}
    sql -Q "BACKUP DATABASE BryNex_Demo TO DISK='$BAK_CATALOGOS' WITH INIT" >/dev/null
    rm -rf "$APP/storage/framework/cache/data/"* "$APP/storage/app/radicados" 2>/dev/null || true
    (cd "$APP" && TZ=America/Bogota DEMO_PUBLICO=1 DEMO_CLAVE="$(leer DEMO_CLAVE "$APP/.env")" VIDEOS_APP="$APP" \
      en_sandbox php "$REPO/scripts/videos/demo/sembrar.php") || echo "⚠️  El sembrado tuvo errores (ver arriba)."
    sql -Q "BACKUP DATABASE BryNex_Demo TO DISK='$BAK_BASE' WITH INIT" >/dev/null
    echo "BryNex_Demo lista. Plantillas: $BAK_CATALOGOS (sin gente) y $BAK_BASE (sembrada)"
    ;;
  servir)
    cd "$APP"
    grep -q '^DB_DATABASE=BryNex_Demo$' .env || { echo "El .env de $APP no es el de la demo. Corre: $0 app"; exit 1; }
    cd public && TZ=America/Bogota PHP_CLI_SERVER_WORKERS=4 en_sandbox php -S "127.0.0.1:$PUERTO" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
    ;;
  subir)
    # La plantilla sin gente (estructura + catálogos + aliado demo) al servidor:
    # de ahí la restaura el reinicio nocturno. Solo cuando cambie la estructura
    # a mano; las migraciones nuevas las aplica el propio reinicio.
    tmp="$(mktemp -d)"
    docker cp "$NOMBRE:$BAK_CATALOGOS" "$tmp/BryNex_Demo_Catalogos.bak"
    scp -q "$tmp/BryNex_Demo_Catalogos.bak" netcup:/tmp/BryNex_Demo_Catalogos.bak
    ssh netcup 'install -o mssql -g mssql -m 640 /tmp/BryNex_Demo_Catalogos.bak /var/opt/mssql/backup/ && rm /tmp/BryNex_Demo_Catalogos.bak'
    rm -rf "$tmp"
    echo "Plantilla en netcup:/var/opt/mssql/backup/BryNex_Demo_Catalogos.bak"
    ;;
  capitulos)
    # Los capítulos completos del manual, uno por módulo (los usa el «¿Cómo
    # funciona?» de la demo, ver VideosManual). No van en git: pesan ~40 MB.
    # Se comprimen a 720p desde ~/Movies/Brynex-videos/salida/capitulos.
    SRC="${VIDEOS_DIR:-$HOME/Movies/Brynex-videos}/salida/capitulos"
    CAPS="${VIDEOS_DIR:-$HOME/Movies/Brynex-videos}/salida/demo-capitulos"
    mkdir -p "$CAPS"
    for f in "$SRC"/*-escritorio.mp4 "$SRC"/visita-movil.mp4; do
      n="$(basename "$f")"; n="${n%-escritorio.mp4}"; n="${n%-movil.mp4}"
      [ "$n" = comercial ] && continue
      [ -s "$CAPS/$n.mp4" ] && continue
      vf="scale=1280:-2"; [ "$n" = visita ] && vf="scale=-2:1280"
      ffmpeg -v error -y -i "$f" -vf "$vf" -c:v libx264 -preset slow -crf 26 -pix_fmt yuv420p \
        -c:a aac -b:a 96k -movflags +faststart "$CAPS/$n.mp4"
    done
    (cd "$CAPS" && python3 -c '
import json, os, subprocess
d = {}
for f in sorted(x for x in os.listdir(".") if x.endswith(".mp4")):
    s = float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", f]))
    d[f[:-4]] = {"archivo": f, "segundos": round(s)}
json.dump(d, open("capitulos.json", "w"), ensure_ascii=False, indent=1)')
    mkdir -p "$APP/public/manual/capitulos"
    rsync -a "$CAPS/" "$APP/public/manual/capitulos/"
    if [ "${2:-}" = "--servidor" ]; then
      rsync -a "$CAPS/" netcup:/var/www/brynex-demo/public/manual/capitulos/
      ssh netcup 'cd /var/www/brynex-demo && chown -R root:root public/manual/capitulos && chmod 755 public/manual/capitulos && chmod 644 public/manual/capitulos/* && (grep -qxF "public/manual/capitulos/" .git/info/exclude || echo "public/manual/capitulos/" >> .git/info/exclude) && rm -f storage/framework/cache/data/*/*/* 2>/dev/null; ls public/manual/capitulos | wc -l'
    fi
    echo "Capítulos listos en $APP/public/manual/capitulos${2:+ y en el servidor}"
    ;;
  sql) shift; sql -d BryNex_Demo -Q "$*" ;;
  *) echo "Uso: $0 app|sincronizar|base [--esquema]|servir|subir|capitulos [--servidor]|sql \"…\""; exit 1;;
esac
