#!/bin/bash
# Deja listo el botón «Consultar datos» para las bases de SQL Server (Brynex,
# Cuenta Fácil, MegaTransportes). Se corre una vez en netcup, como root:
#
#     ssh -t netcup 'cd /var/www/brynex && git pull -q && bash scripts/consultar-mssql-instalar.sh'
#
# Pide la clave de `sa` por teclado (no se ve ni queda en el historial) y:
#   1. genera la clave del login `datos_lectura` en /etc/consultar-gh/mssql.clave
#      (root, 600) si no existe. Nadie la tiene que ver ni copiar;
#   2. crea el login, o le vuelve a poner la clave del archivo si ya existía;
#   3. en cada base lo deja con db_datareader + db_denydatawriter y EXECUTE
#      negado: lee todo y no puede escribir, ni por un procedimiento almacenado;
#   4. reinstala /usr/local/sbin/consultar-gh desde este repo;
#   5. prueba que lee y que NO puede escribir.
#
# BryNex_Finanzas no entra: son las finanzas personales del dueño.
# Es idempotente: correrlo otra vez no daña nada.
set -euo pipefail

SQLCMD=/opt/mssql-tools18/bin/sqlcmd
DIR=/etc/consultar-gh
CLAVE_ARCHIVO=$DIR/mssql.clave
REPO=$(cd "$(dirname "$0")/.." && pwd)

[ "$(id -u)" = 0 ] || { echo "Correr como root."; exit 1; }
[ -x "$SQLCMD" ] || { echo "No encuentro $SQLCMD."; exit 1; }
php -m | grep -qi '^pdo_sqlsrv$' || { echo "El php de consola no tiene pdo_sqlsrv."; exit 1; }

leer_env() {
  grep -E "^$2=" "$1" 2>/dev/null | tail -1 | cut -d= -f2- \
    | sed -E 's/^[[:space:]]*["'"'"']?//; s/["'"'"']?[[:space:]]*$//'
}

# Las bases salen de los .env de cada app, igual que en consultar-gh.
BASES=()
for par in brynex:/var/www/brynex/.env cuentafacil:/var/www/cf/.env megatransportes:/var/www/megatransportes/.env; do
  app=${par%%:*}; envf=${par#*:}
  base=$(leer_env "$envf" DB_DATABASE)
  if [[ "$base" =~ ^[A-Za-z0-9_]+$ ]]; then
    BASES+=("$app:$base")
  else
    echo "Aviso: no pude leer DB_DATABASE de $envf; $app queda por fuera."
  fi
done
[ ${#BASES[@]} -gt 0 ] || { echo "No encontré ninguna base."; exit 1; }
echo "Bases: ${BASES[*]}"

# 1. La clave del login, generada aquí y guardada solo aquí.
install -d -m 700 -o root -g root "$DIR"
if [ ! -s "$CLAVE_ARCHIVO" ]; then
  umask 077
  # Alfanumérica (nada que escapar en SQL) y con mayúscula, minúscula y
  # número, que es lo que pide CHECK_POLICY.
  printf '%sAa7\n' "$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 28)" > "$CLAVE_ARCHIVO"
  chmod 600 "$CLAVE_ARCHIVO"
  echo "Clave nueva guardada en $CLAVE_ARCHIVO."
fi
CLAVE=$(tr -d '\n' < "$CLAVE_ARCHIVO")
[[ "$CLAVE" =~ ^[A-Za-z0-9]+$ ]] || { echo "La clave de $CLAVE_ARCHIVO no es alfanumérica."; exit 1; }

# 2 y 3. El SQL va en un archivo temporal de root que se borra al salir.
umask 077
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
{
  echo "USE master;"
  echo "IF SUSER_ID('datos_lectura') IS NULL"
  echo "  CREATE LOGIN datos_lectura WITH PASSWORD = '$CLAVE', CHECK_POLICY = ON, DEFAULT_DATABASE = master;"
  echo "ELSE"
  echo "  ALTER LOGIN datos_lectura WITH PASSWORD = '$CLAVE';"
  echo "GO"
  for par in "${BASES[@]}"; do
    base=${par#*:}
    echo "USE [$base];"
    echo "IF USER_ID('datos_lectura') IS NULL CREATE USER datos_lectura FOR LOGIN datos_lectura;"
    echo "ALTER ROLE db_datareader ADD MEMBER datos_lectura;"
    echo "ALTER ROLE db_denydatawriter ADD MEMBER datos_lectura;"
    echo "DENY EXECUTE TO datos_lectura;"
    echo "GO"
  done
} > "$TMP"

read -rsp "Clave de sa: " SQLCMDPASSWORD; echo
export SQLCMDPASSWORD
"$SQLCMD" -S 127.0.0.1 -U sa -C -b -i "$TMP" >/dev/null
unset SQLCMDPASSWORD
echo "Login datos_lectura listo."

# 4. El script del botón, copiado (no enlazado) desde este repo.
install -m 755 -o root -g root "$REPO/scripts/consultar-gh.sh" /usr/local/sbin/consultar-gh

# 5. Pruebas: tiene que leer, y tiene que fallar al escribir. El DELETE lleva
# WHERE 1 = 0: aunque tuviera permiso no borraría nada, pero SQL Server revisa
# el permiso antes y lo rechaza.
bien=0
for par in "${BASES[@]}"; do
  app=${par%%:*}; base=${par#*:}
  echo "--- $app ($base)"
  if echo "SELECT COUNT(*) AS tablas FROM sys.tables" | SSH_ORIGINAL_COMMAND=$app /usr/local/sbin/consultar-gh 2>&1; then
    :
  else
    echo "FALLA: no pudo leer $app."; bien=1
  fi
  if SQLCMDPASSWORD="$CLAVE" "$SQLCMD" -S 127.0.0.1 -U datos_lectura -C -b -d "$base" \
      -Q "DECLARE @t nvarchar(300) = (SELECT TOP 1 QUOTENAME(s.name) + '.' + QUOTENAME(t.name) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id); EXEC('DELETE FROM ' + @t + ' WHERE 1 = 0');" >/dev/null 2>&1; then
    echo "FALLA: datos_lectura PUDO escribir en $base. Revisar antes de usar el botón."; bien=1
  else
    echo "Bien: no puede escribir en $base."
  fi
done

[ $bien = 0 ] && echo "Todo en orden." || { echo "Hubo fallas, ver arriba."; exit 1; }
