#!/usr/bin/env bash
# Monta la demo pública (demo.brynex.co) en netcup. Se corre UNA vez, como root:
#
#   ssh netcup
#   bash /var/www/brynex/scripts/demo/servidor/montar.sh
#
# Antes: el DNS de demo.brynex.co apuntando al servidor, y la plantilla de la
# base subida desde el Mac (`scripts/demo/demo.sh subir`). La clave de sa la lee
# de /root/.brynex_credentials; si no está, la pregunta.
#
# Qué deja, y por qué así:
#   - /var/www/brynex-demo: otra copia del MISMO código (clon local de
#     /var/www/brynex), con su propio .env en modo demo (BRYNEX_DEMO=true).
#   - Corre como el usuario `brynexdemo` en su propio pool de php-fpm, no con el
#     mod_php de producción: así ni un fallo de la demo puede leer el .env de
#     producción (que es 600 de www-data) ni escribir en /var/www/brynex.
#   - Su login de SQL (`brynex_demo`) es dueño SOLO de BryNex_Demo y
#     BryNex_Finanzas_Demo: no ve la base de producción. (La restauración de
#     cada noche la hace root con sa: ver reiniciar.sh.)
#   - Un cron a las 3:30 vuelve la base a cero y la siembra con fechas de hoy.
#
# Es idempotente: si algo ya existe, lo deja.
set -euo pipefail
# Producción es de www-data y esto corre como root: git se niega a leer
# /var/www/brynex/.git. Git 2.34 (Ubuntu 22.04) solo acepta esa excepción desde
# la config global, así que se usa una global temporal (la de root + esa ruta)
# sin tocar /root/.gitconfig.
GIT_CONFIG_GLOBAL="$(mktemp)"; export GIT_CONFIG_GLOBAL
trap 'rm -f "$GIT_CONFIG_GLOBAL"' EXIT
printf '[include]
	path = /root/.gitconfig
[safe]
	directory = /var/www/brynex/.git
	directory = /var/www/brynex
	directory = /var/www/brynex-demo
' > "$GIT_CONFIG_GLOBAL"

DEMO=/var/www/brynex-demo
PROD=/var/www/brynex
USUARIO=brynexdemo
DOMINIO=demo.brynex.co
PHPV=8.3
SOCKET=/run/php/brynex-demo.sock
BAK=/var/opt/mssql/backup/BryNex_Demo_Catalogos.bak
SQLCMD=/opt/mssql-tools18/bin/sqlcmd

titulo() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
[[ $EUID -eq 0 ]] || { echo "Como root."; exit 1; }
[[ -f "$BAK" ]] || { echo "Falta $BAK: súbela desde el Mac con scripts/demo/demo.sh subir"; exit 1; }

titulo "Usuario del sistema"
id "$USUARIO" >/dev/null 2>&1 || useradd --system --home-dir "$DEMO" --shell /usr/sbin/nologin "$USUARIO"

titulo "Código (clon local de producción)"
if [[ ! -d "$DEMO/.git" ]]; then
    git clone --quiet --no-hardlinks "$PROD" "$DEMO"
fi
git -C "$DEMO" fetch --quiet origin main
git -C "$DEMO" reset --quiet --hard origin/main
# Mismo composer.lock que producción: se copia el vendor en vez de instalarlo.
rsync -a --delete "$PROD/vendor/" "$DEMO/vendor/"
mkdir -p "$DEMO"/storage/{app/public,framework/{cache/data,sessions,views},logs} "$DEMO/bootstrap/cache"

titulo ".env de la demo"
if [[ ! -f "$DEMO/.env" ]]; then
    CLAVE_SQL="Dm-$(openssl rand -hex 12)"
    CLAVE_DEMO="Demo-$(openssl rand -hex 3)"
    cat > "$DEMO/.env" <<EOF
# Demo pública de BryNex. Generado por scripts/demo/servidor/montar.sh.
APP_NAME="BryNex Demo"
APP_ENV=demo
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://$DOMINIO
LOG_CHANNEL=daily
LOG_LEVEL=info

BRYNEX_DEMO=true
DEMO_ALIADO_ID=100
DEMO_USUARIO=9985000001
DEMO_CLAVE=$CLAVE_DEMO
DEMO_CONTACTO_WHATSAPP=573000000000

DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=BryNex_Demo
DB_USERNAME=brynex_demo
DB_PASSWORD=$CLAVE_SQL
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
FINANZAS_DB_HOST=127.0.0.1
FINANZAS_DB_PORT=1433
FINANZAS_DB_DATABASE=BryNex_Finanzas_Demo
FINANZAS_DB_USERNAME=brynex_demo
FINANZAS_DB_PASSWORD=$CLAVE_SQL
DB_LEGACY_HOST=127.0.0.1
DB_LEGACY_PORT=9
DB_LEGACY_PASSWORD=

SESSION_DRIVER=file
SESSION_LIFETIME=240
SESSION_SECURE_COOKIE=true
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
BROADCAST_DRIVER=log
MAIL_MAILER=log

# Segunda llave: todo lo de afuera a un puerto cerrado y sin una sola clave.
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
EOF
    echo "  .env creado (la clave de la cuenta demo: $CLAVE_DEMO)"
fi
chown root:"$USUARIO" "$DEMO/.env"
chmod 640 "$DEMO/.env"
leer() { grep -E "^$1=" "$DEMO/.env" | head -1 | cut -d= -f2-; }

titulo "Bases de datos (con la clave de sa, una sola vez)"
# La clave de sa vive en /root/.brynex_credentials (desde la migración, ago-2026).
SA=""
if [[ -f /root/.brynex_credentials ]]; then SA="$(. /root/.brynex_credentials; echo "${SA_PASS:-}")"; fi
[[ -n "$SA" ]] || { read -r -s -p "  Clave de sa de SQL Server: " SA; echo; }
sa() { SQLCMDPASSWORD="$SA" "$SQLCMD" -S 127.0.0.1 -U sa -C -b -h -1 -W "$@"; }
sa -Q "IF SUSER_ID('brynex_demo') IS NULL CREATE LOGIN brynex_demo WITH PASSWORD = '$(leer DB_PASSWORD)', CHECK_POLICY = OFF;
       ELSE ALTER LOGIN brynex_demo WITH PASSWORD = '$(leer DB_PASSWORD)';"
sa -Q "IF DB_ID('BryNex_Demo') IS NULL
       RESTORE DATABASE BryNex_Demo FROM DISK = '$BAK'
         WITH MOVE 'BryNex_Demo' TO '/var/opt/mssql/data/BryNex_Demo.mdf',
              MOVE 'BryNex_Demo_log' TO '/var/opt/mssql/data/BryNex_Demo_log.ldf';"
sa -Q "IF DB_ID('BryNex_Finanzas_Demo') IS NULL CREATE DATABASE BryNex_Finanzas_Demo COLLATE Modern_Spanish_CI_AS;"
# Dueño de sus dos bases y de nada más: puede restaurarlas sin ser sysadmin.
sa -Q "ALTER AUTHORIZATION ON DATABASE::BryNex_Demo TO brynex_demo;
       ALTER AUTHORIZATION ON DATABASE::BryNex_Finanzas_Demo TO brynex_demo;"
unset SA

titulo "php-fpm propio (usuario $USUARIO)"
cat > /etc/php/$PHPV/fpm/pool.d/brynex-demo.conf <<EOF
; Demo pública de BryNex: corre aparte de producción (ver scripts/demo/servidor/montar.sh).
[brynex-demo]
user = $USUARIO
group = $USUARIO
listen = $SOCKET
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 30s
php_admin_value[open_basedir] = $DEMO:/tmp:/usr/share/php:/usr/bin
php_admin_value[disable_functions] = exec,shell_exec,system,passthru,popen
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 12M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60
EOF
# Producción usa mod_php: el servicio de php-fpm puede estar apagado.
systemctl enable --now php$PHPV-fpm >/dev/null 2>&1
systemctl reload php$PHPV-fpm

titulo "Apache ($DOMINIO)"
a2enmod -q proxy_fcgi setenvif >/dev/null
cat > /etc/apache2/sites-available/brynex-demo.conf <<EOF
# Demo pública de BryNex (scripts/demo/servidor/montar.sh). PHP va por su
# php-fpm propio (usuario $USUARIO), NO por el mod_php de producción.
<VirtualHost *:80>
    ServerName $DOMINIO
    DocumentRoot $DEMO/public
    php_admin_flag engine off
    <Directory $DEMO/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:$SOCKET|fcgi://localhost"
    </FilesMatch>
    ErrorLog \${APACHE_LOG_DIR}/brynex-demo-error.log
    CustomLog \${APACHE_LOG_DIR}/brynex-demo-access.log combined
</VirtualHost>
EOF
a2ensite -q brynex-demo >/dev/null
apache2ctl configtest
systemctl reload apache2
certbot --apache --non-interactive --redirect -d "$DOMINIO" || echo "  ⚠️  certbot falló: ¿el DNS de $DOMINIO ya apunta aquí? Repetir: certbot --apache -d $DOMINIO"

titulo "Permisos"
chown -R root:root "$DEMO"
chown root:"$USUARIO" "$DEMO/.env"
chown -R "$USUARIO":"$USUARIO" "$DEMO/storage" "$DEMO/bootstrap/cache"

titulo "Reinicio nocturno"
cat > /etc/cron.d/brynex-demo <<EOF
# Demo pública de BryNex: la base vuelve a cero y se siembra con fechas de hoy.
30 3 * * * root /bin/bash $PROD/scripts/demo/servidor/reiniciar.sh >> /var/log/brynex-demo-reinicio.log 2>&1
EOF

titulo "Primera siembra"
bash "$PROD/scripts/demo/servidor/reiniciar.sh"

titulo "Listo"
echo "  https://$DOMINIO — cuenta $(leer DEMO_USUARIO) / $(leer DEMO_CLAVE)"
