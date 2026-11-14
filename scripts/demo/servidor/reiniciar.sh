#!/usr/bin/env bash
# Vuelve la demo pública a cero: restaura la plantilla (estructura + catálogos +
# el aliado demo, sin gente), corre las migraciones que haya traído el código
# desde que se armó la plantilla y siembra la gente inventada con fechas de HOY
# (la mora, los semáforos y la caja del día se calculan contra la fecha real).
#
# Lo corre el cron de /etc/cron.d/brynex-demo a las 3:30, como root. Todo lo que
# toca es de la demo: la base BryNex_Demo y /var/www/brynex-demo.
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

# Restaura con sa (root, /root/.brynex_credentials): la copia trae a sa como
# dueño, así que después de cada RESTORE hay que devolverle la base a
# brynex_demo, y eso solo lo puede hacer un sysadmin. Todo va fijo a BryNex_Demo.
SA="$(. /root/.brynex_credentials; echo "${SA_PASS:-}")"
[[ -n "$SA" ]] || { echo "Falta SA_PASS en /root/.brynex_credentials"; exit 1; }
salida="$(SQLCMDPASSWORD="$SA" "$SQLCMD" -S 127.0.0.1 -U sa -C -b -Q "
    IF DB_ID('BryNex_Demo') IS NOT NULL ALTER DATABASE BryNex_Demo SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    RESTORE DATABASE BryNex_Demo FROM DISK = '$BAK' WITH REPLACE,
        MOVE 'BryNex_Demo' TO '/var/opt/mssql/data/BryNex_Demo.mdf',
        MOVE 'BryNex_Demo_log' TO '/var/opt/mssql/data/BryNex_Demo_log.ldf';
    ALTER DATABASE BryNex_Demo SET MULTI_USER;
    ALTER AUTHORIZATION ON DATABASE::BryNex_Demo TO brynex_demo;" 2>&1)" \
    || { echo "$salida"; exit 1; }
unset SA

# Lo que la gente subió o generó ayer (PDF de radicados, documentos, sesiones).
rm -rf "$DEMO/storage/app/radicados" "$DEMO/storage/app/documentos" "$DEMO/storage/framework/sessions/"* \
       "$DEMO/storage/framework/cache/data/"* 2>/dev/null || true

cd "$DEMO"
como_demo php artisan migrate --force --no-interaction
como_demo env DEMO_PUBLICO=1 DEMO_CLAVE="$(leer DEMO_CLAVE)" VIDEOS_APP="$DEMO" \
    php "$DEMO/scripts/videos/demo/sembrar.php"

# Los espacios propios de cada prospecto (App\Services\Demo\EspaciosDemo): el
# mismo sembrado como aliados 101, 102… Uno que falle no frena los demás: el
# registro solo asigna espacios cuya cuenta exista, y si no, entra al compartido.
ESPACIOS="$(leer DEMO_ESPACIOS)"
for n in $(seq 1 "${ESPACIOS:-0}"); do
    como_demo env DEMO_PUBLICO=1 DEMO_ESPACIO="$n" VIDEOS_APP="$DEMO" \
        php "$DEMO/scripts/videos/demo/sembrar.php" >/tmp/brynex-demo-espacio.log 2>&1 \
        && echo "   espacio $n listo ($(date '+%T'))" \
        || { echo "   espacio $n FALLÓ:"; tail -5 /tmp/brynex-demo-espacio.log; }
done
echo "== $(date '+%F %T') listo"
