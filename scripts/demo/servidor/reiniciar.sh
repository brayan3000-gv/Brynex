#!/usr/bin/env bash
# Vuelve la demo pública a cero: restaura la plantilla (estructura + catálogos +
# el aliado demo, sin gente), corre las migraciones que haya traído el código
# desde que se armó la plantilla y siembra la gente inventada con fechas de HOY
# (la mora, los semáforos y la caja del día se calculan contra la fecha real).
#
# Lo corre el cron de /etc/cron.d/brynex-demo a las 3:30, como root. Todo lo que
# toca es de la demo: su base (con el login brynex_demo, que solo es dueño de
# ella) y /var/www/brynex-demo.
set -euo pipefail

DEMO=/var/www/brynex-demo
USUARIO=brynexdemo
BAK=/var/opt/mssql/backup/BryNex_Demo_Catalogos.bak
SQLCMD=/opt/mssql-tools18/bin/sqlcmd

echo "== $(date '+%F %T') reinicio de la demo"
leer() { grep -E "^$1=" "$DEMO/.env" | head -1 | cut -d= -f2-; }

# Seguro: solo con el .env de la demo y su base.
[[ "$(leer BRYNEX_DEMO)" == "true" && "$(leer DB_DATABASE)" == "BryNex_Demo" && "$(leer DB_USERNAME)" == "brynex_demo" ]] \
    || { echo "El .env de $DEMO no es el de la demo: no toco nada."; exit 1; }

como_demo() { sudo -u "$USUARIO" env HOME=/tmp TZ=America/Bogota "$@"; }

SQLCMDPASSWORD="$(leer DB_PASSWORD)" "$SQLCMD" -S 127.0.0.1 -U brynex_demo -C -b -Q "
    ALTER DATABASE BryNex_Demo SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    RESTORE DATABASE BryNex_Demo FROM DISK = '$BAK' WITH REPLACE,
        MOVE 'BryNex_Demo' TO '/var/opt/mssql/data/BryNex_Demo.mdf',
        MOVE 'BryNex_Demo_log' TO '/var/opt/mssql/data/BryNex_Demo_log.ldf';
    ALTER DATABASE BryNex_Demo SET MULTI_USER;" >/dev/null

# Lo que la gente subió o generó ayer (PDF de radicados, documentos, sesiones).
rm -rf "$DEMO/storage/app/radicados" "$DEMO/storage/app/documentos" "$DEMO/storage/framework/sessions/"* \
       "$DEMO/storage/framework/cache/data/"* 2>/dev/null || true

cd "$DEMO"
como_demo php artisan migrate --force --no-interaction
como_demo env DEMO_PUBLICO=1 DEMO_CLAVE="$(leer DEMO_CLAVE)" VIDEOS_APP="$DEMO" \
    php "$DEMO/scripts/videos/demo/sembrar.php"
echo "== $(date '+%F %T') listo"
