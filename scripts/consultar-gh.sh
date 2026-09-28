#!/bin/bash
# Lo único que puede hacer la llave del botón «Consultar datos» de GitHub:
# leer, con un SELECT, las bases de las apps.
#
# Va instalado en /usr/local/sbin/consultar-gh (copiado, no enlazado a este
# repositorio: si viviera aquí, el que cambie main cambiaría también qué deja
# hacer la llave). En /root/.ssh/authorized_keys la llave lleva
#
#     restrict,command="/usr/local/sbin/consultar-gh" ssh-ed25519 ... gh-actions-consultar
#
# y así no abre una consola. Lo que GitHub mande llega en SSH_ORIGINAL_COMMAND
# como «<app>», contra una lista fija, y el SELECT por la entrada estándar.
#
# **No puede escribir, aunque el SQL lo intente.** Corre como el rol
# `datos_lectura`, que solo tiene `pg_read_all_data` y transacciones de solo
# lectura por defecto: no es una regla de este script sino un permiso que la
# base niega. Tiene BYPASSRLS porque sin eso las tablas con RLS (LiderApp,
# Bahía) le responden vacías. Entra por el socket local con peer, así que no
# hay clave guardada en ninguna parte.
#
# Lo que devuelve no se imprime en el log de GitHub Actions: el workflow lo
# cifra con age antes de guardarlo (ver .github/workflows/consultar-datos.yml).
#
# PostgreSQL: liderapp, bahia, avappi. SQL Server: brynex, cuentafacil,
# megatransportes. En SQL Server entra con el login `datos_lectura`, que solo
# tiene db_datareader y db_denydatawriter (y EXECUTE negado) en cada base; su
# clave vive solo en /etc/consultar-gh/mssql.clave (root, 600) y la lee PHP del
# archivo, nunca por la línea de comandos. BryNex_Finanzas queda fuera a
# propósito: son las finanzas personales del dueño.
#
# Instalación: ver scripts/CONSULTAR.md.
set -uo pipefail

read -r APP EXTRA <<<"${SSH_ORIGINAL_COMMAND:-}"

falla() { echo "$*" >&2; exit 2; }

[ -z "${EXTRA:-}" ] || falla "Sobran palabras: el comando es solo el nombre de la app; el SQL va por la entrada estándar."

# Lee una variable del .env de una app, sin comillas.
leer_env() {
  grep -E "^$2=" "$1" 2>/dev/null | tail -1 | cut -d= -f2- \
    | sed -E 's/^[[:space:]]*["'"'"']?//; s/["'"'"']?[[:space:]]*$//'
}

MOTOR=postgres
case "${APP:-}" in
  liderapp) BASE=liderapp ;;
  bahia) BASE=bahia ;;
  brynex|cuentafacil|megatransportes)
    MOTOR=mssql
    case "$APP" in
      brynex) ENVF=/var/www/brynex/.env ;;
      cuentafacil) ENVF=/var/www/cf/.env ;;
      megatransportes) ENVF=/var/www/megatransportes/.env ;;
    esac
    BASE=$(leer_env "$ENVF" DB_DATABASE)
    HOST=$(leer_env "$ENVF" DB_HOST); HOST=${HOST:-127.0.0.1}
    PUERTO=$(leer_env "$ENVF" DB_PORT); PUERTO=${PUERTO:-1433}
    [[ "$BASE" =~ ^[A-Za-z0-9_]+$ ]] || falla "No pude leer DB_DATABASE en $ENVF."
    [[ "$HOST" =~ ^[A-Za-z0-9.-]+$ && "$PUERTO" =~ ^[0-9]+$ ]] || falla "DB_HOST o DB_PORT raros en $ENVF."
    CLAVE_ARCHIVO=/etc/consultar-gh/mssql.clave
    [ -r "$CLAVE_ARCHIVO" ] || falla "Falta $CLAVE_ARCHIVO: correr scripts/consultar-mssql-instalar.sh."
    ;;
  avappi)
    # Solo el nombre de la base, del final de DATABASE_URL.
    BASE=$(grep -E '^DATABASE_URL=' /var/www/avappi/.env 2>/dev/null | tail -1 \
      | sed -E 's#.*/([A-Za-z0-9_]+)(\?[^"'"'"']*)?["'"'"']?[[:space:]]*$#\1#')
    [[ "$BASE" =~ ^[A-Za-z0-9_]+$ ]] || falla "No pude leer el nombre de la base de Avappi en su .env."
    ;;
  *) falla "App no permitida: '${APP:-}'. Opciones: liderapp bahia avappi brynex cuentafacil megatransportes" ;;
esac

SQL=$(head -c 20000)
# Los comentarios se quitan y todo queda en un renglón, que es como \copy lo
# exige. Una sola consulta: con punto y coma en medio serían varias.
SQL=$(printf '%s\n' "$SQL" | sed -E 's/--.*$//' | tr '\n\r\t' '   ' \
  | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//; s/;$//; s/ $//')
[ -n "$SQL" ] || falla "No llegó ningún SQL."
[[ "$SQL" != *";"* ]] || falla "Una sola consulta, sin punto y coma en medio."
[[ "$SQL" =~ ^([Ss][Ee][Ll][Ee][Cc][Tt]|[Ww][Ii][Tt][Hh])[[:space:]] ]] || falla "Solo SELECT o WITH."

echo "app=$APP base=$BASE · $(date '+%F %T %Z')" >&2

if [ "$MOTOR" = mssql ]; then
  # PHP con pdo_sqlsrv, el mismo que usa Laravel: sqlcmd no sabe sacar un CSV
  # bien entrecomillado. La clave la lee PHP del archivo, así que no aparece en
  # `ps` ni en el entorno. SQL Server no deja meter un WITH dentro de otra
  # consulta, así que el tope de filas se aplica al leer y no envolviendo.
  exec env CONSULTA_SQL="$SQL" CONSULTA_BASE="$BASE" CONSULTA_HOST="$HOST" \
    CONSULTA_PUERTO="$PUERTO" CONSULTA_CLAVE="$CLAVE_ARCHIVO" \
    php -d display_errors=stderr -d log_errors=0 <<'PHP'
<?php
try {
    $clave = trim((string) file_get_contents(getenv('CONSULTA_CLAVE')));
    $dsn = 'sqlsrv:Server=' . getenv('CONSULTA_HOST') . ',' . getenv('CONSULTA_PUERTO')
        . ';Database=' . getenv('CONSULTA_BASE') . ';TrustServerCertificate=1';
    $pdo = new PDO($dsn, 'datos_lectura', $clave, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 120);
    $st = $pdo->query(getenv('CONSULTA_SQL'));
    $out = fopen('php://stdout', 'w');
    $columnas = [];
    for ($i = 0; $i < $st->columnCount(); $i++) {
        $columnas[] = $st->getColumnMeta($i)['name'] ?? "col$i";
    }
    fputcsv($out, $columnas, ',', '"', '');
    $n = 0;
    while (($fila = $st->fetch(PDO::FETCH_NUM)) !== false && $n++ < 20000) {
        fputcsv($out, $fila, ',', '"', '');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP
fi

[[ "$SQL" != *"\\"* ]] || falla "Sin barras invertidas: psql las lee como comandos."
# El tope de filas y de tiempo no son de seguridad —el rol ya no escribe—
# sino para no dejar un log de cien megas ni una consulta pegada en la base.
sudo -u datos_lectura psql -X -q -d "$BASE" -v ON_ERROR_STOP=1 \
  -c "SET statement_timeout = '120s'" \
  -c "\\copy (SELECT * FROM ($SQL) AS q LIMIT 20000) TO STDOUT WITH (FORMAT csv, HEADER)"
