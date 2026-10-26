#!/usr/bin/env bash
# La base con la que se graban los videos: SQL Server 2022 en Docker, SOLO en
# 127.0.0.1:14333, con gente inventada. Nada de esto toca la base de producción
# ni el túnel (1433) que usan las otras sesiones.
#
#   scripts/videos/base-videos.sh contenedor   # una vez: crea el contenedor y la clave de sa
#   scripts/videos/base-videos.sh vaciar       # (re)crea BryNex_Videos y BryNex_Finanzas_Videos vacías
#   scripts/videos/base-videos.sh plantilla    # guarda el punto de partida (después de sembrar)
#   scripts/videos/base-videos.sh restablecer  # vuelve al punto de partida antes de cada toma
#   scripts/videos/base-videos.sh sql "SELECT …"
#
# Las escenas crean clientes, contratos y facturas: sin restablecer, la segunda
# toma ya no es igual a la primera.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
VIDEOS_ENV="$REPO/.env.videos"
NOMBRE=brynex-videos-sql
IMAGEN=mcr.microsoft.com/mssql/server:2022-latest
BAK=/var/opt/mssql/backup/BryNex_Videos_Base.bak

clave() { grep -E '^VIDEOS_SQL_SA=' "$VIDEOS_ENV" 2>/dev/null | cut -d= -f2-; }
sql() {
  docker exec -i -e SQLCMDPASSWORD="$(clave)" "$NOMBRE" /opt/mssql-tools18/bin/sqlcmd -I \
    -S localhost -U sa -C -b -h -1 -W "$@"
}

case "${1:-}" in
  contenedor)
    if [ -z "$(clave)" ]; then
      # Letras, números y un símbolo: la política de SQL Server pide los tres.
      echo "VIDEOS_SQL_SA=Vid-$(openssl rand -hex 12)" >> "$VIDEOS_ENV"
      chmod 600 "$VIDEOS_ENV"
    fi
    docker volume create brynex-videos-sql >/dev/null
    docker run -d --name "$NOMBRE" --platform linux/amd64 \
      -e ACCEPT_EULA=Y -e MSSQL_PID=Express -e MSSQL_SA_PASSWORD="$(clave)" -e TZ=America/Bogota \
      -p 127.0.0.1:14333:1433 -v brynex-videos-sql:/var/opt/mssql "$IMAGEN" >/dev/null
    for _ in $(seq 1 60); do sql -Q "SELECT 1" >/dev/null 2>&1 && break; sleep 2; done
    docker exec -u root "$NOMBRE" bash -c 'mkdir -p /var/opt/mssql/backup && chown mssql /var/opt/mssql/backup'
    sql -Q "SELECT @@VERSION"
    ;;
  vaciar)
    for b in BryNex_Videos BryNex_Finanzas_Videos; do
      sql -Q "IF DB_ID('$b') IS NOT NULL BEGIN ALTER DATABASE [$b] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$b]; END; CREATE DATABASE [$b] COLLATE Modern_Spanish_CI_AS;"
    done
    echo "BryNex_Videos y BryNex_Finanzas_Videos vacías"
    ;;
  rehacer)
    # De cero: bases vacías → estructura de producción (sin datos) → catálogos →
    # aliado demo inventado → plantilla. Lo único que lee producción son la
    # estructura y los catálogos (esquema.php y catalogos.php, solo lectura).
    export DYLD_LIBRARY_PATH="/opt/homebrew/opt/openssl@3/lib"
    ESQUEMA="${VIDEOS_DIR:-$HOME/Movies/Brynex-videos}/base/esquema.sql"
    mkdir -p "$(dirname "$ESQUEMA")"
    [ -s "$ESQUEMA" ] && [ "${2:-}" != "--esquema" ] || php "$REPO/scripts/videos/demo/esquema.php" > "$ESQUEMA"
    "$0" vaciar
    docker cp "$ESQUEMA" "$NOMBRE:/tmp/esquema.sql"
    sql -d BryNex_Videos -I -i /tmp/esquema.sql >/dev/null
    php "$REPO/scripts/videos/demo/catalogos.php"
    # El sembrado corre la app de verdad: va dentro del mismo sandbox que el servidor.
    # Con errores también se guarda la plantilla: sembrar.php ya escribió las claves
    # nuevas en .env.videos, y la plantilla tiene que coincidir con ellas.
    "$REPO/scripts/videos/app-videos.sh" sembrar || echo "⚠️  El sembrado tuvo errores (ver arriba)."
    "$0" plantilla
    ;;
  plantilla)
    sql -Q "BACKUP DATABASE BryNex_Videos TO DISK='$BAK' WITH INIT" >/dev/null
    echo "Plantilla guardada ($BAK)"
    ;;
  restablecer)
    sql -Q "ALTER DATABASE BryNex_Videos SET SINGLE_USER WITH ROLLBACK IMMEDIATE; RESTORE DATABASE BryNex_Videos FROM DISK='$BAK' WITH REPLACE; ALTER DATABASE BryNex_Videos SET MULTI_USER;" >/dev/null
    # La caché de archivos de la app (permisos de Spatie, consultas RUAF) también vuelve a cero.
    rm -rf "${VIDEOS_APP:-$HOME/brynex-videos-app}/storage/framework/cache/data/"* 2>/dev/null || true
    "$REPO/scripts/videos/app-videos.sh" cache
    echo "BryNex_Videos restablecida"
    ;;
  sql) shift; sql -d BryNex_Videos -Q "$*" ;;
  *) echo "Uso: $0 contenedor|vaciar|plantilla|restablecer|sql"; exit 1;;
esac
