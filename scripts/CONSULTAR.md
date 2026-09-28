# Botón «Consultar datos»

Corre un SELECT contra las bases de producción de netcup desde GitHub →
*Actions* → *Consultar datos* → *Run workflow*. Lo usa sobre todo un hilo de
Claude: lo lanza, baja el resultado, lo abre con su llave y arma lo que haga
falta (una respuesta, un Excel).

**El resultado nunca queda legible en GitHub**, porque trae celulares, cédulas
y puestos de votación y el repo lo ven sus colaboradores. No se imprime en el
log —ahí queda solo cuántas filas trajo—: sale cifrado con
[age](https://age-encryption.org) para una llave pública, como artifact que
GitHub borra solo al día. La llave privada no está en GitHub; está solo en el
entorno de Claude. El SQL que se pidió sí se ve en la corrida, así que no se
escriben cédulas ni nombres en él: se filtra por ids.

| app | base |
|---|---|
| `liderapp` | `liderapp` (PostgreSQL) |
| `bahia` | `bahia` (PostgreSQL) |
| `avappi` | la de `DATABASE_URL` en `/var/www/avappi/.env` (PostgreSQL) |
| `brynex` | la de `DB_DATABASE` en `/var/www/brynex/.env` (SQL Server) |
| `cuentafacil` | la de `DB_DATABASE` en `/var/www/cf/.env` (SQL Server) |
| `megatransportes` | la de `DB_DATABASE` en `/var/www/megatransportes/.env` (SQL Server) |

`BryNex_Finanzas` no está, a propósito: son las finanzas personales del dueño.

**Solo lee, y no por una regla del script sino de la base.** La consulta corre
como el rol `datos_lectura`: solo tiene `pg_read_all_data`, sus transacciones
son de solo lectura y no puede crear nada. Tiene `BYPASSRLS` porque sin eso
LiderApp y Bahía, que filtran con RLS, le responderían vacías. Entra por el
socket local con peer (el usuario del sistema `datos_lectura`), así que no hay
ninguna clave guardada.

La llave es aparte de la de desplegar y la de Ver logs, y tiene un `command=`
forzado: no abre consola, y lo único que recibe como comando es el nombre de la
app. El SQL llega por la entrada estándar; el script acepta un solo `SELECT` o
`WITH`, sin punto y coma ni comandos de psql, con tope de 20.000 filas y dos
minutos.

## SQL Server (Brynex, Cuenta Fácil, MegaTransportes)

Entra con el login `datos_lectura` de SQL Server, que en cada base tiene
`db_datareader` y `db_denydatawriter` y `EXECUTE` negado: lee todo y no puede
escribir, tampoco a través de un procedimiento almacenado. La consulta la corre
PHP con `pdo_sqlsrv` (el mismo de Laravel) para que el CSV salga bien
entrecomillado. La clave del login la genera el instalador en el servidor, en
`/etc/consultar-gh/mssql.clave` (root, 600), y no sale de ahí: nadie la tiene
que ver ni copiar. Los accesos de `datos_lectura` quedan en la auditoría de
logins exitosos (ver `docs/login-app-sin-sa.md`).

Instalación, una vez, desde la Mac (pide la clave de `sa`):

```bash
ssh -t netcup 'cd /var/www/brynex && git pull -q && bash scripts/consultar-mssql-instalar.sh'
```

Termina en «Todo en orden» después de probar que lee cada base y que **no**
puede borrar en ninguna. Es idempotente, y también reinstala
`/usr/local/sbin/consultar-gh`.

## Instalación en netcup (una sola vez, PostgreSQL)

Desde la Mac con `ssh netcup`, como root. La llave privada **nunca** se pega en
un chat.

```bash
# 1. El usuario del sistema y el rol de solo lectura
useradd --system --no-create-home --shell /usr/sbin/nologin datos_lectura
sudo -u postgres psql -v ON_ERROR_STOP=1 <<'SQL'
CREATE ROLE datos_lectura LOGIN BYPASSRLS;
GRANT pg_read_all_data TO datos_lectura;
ALTER ROLE datos_lectura SET default_transaction_read_only = on;
SQL
for b in liderapp bahia $(grep -E '^DATABASE_URL=' /var/www/avappi/.env | sed -E 's#.*/([A-Za-z0-9_]+).*#\1#'); do
  sudo -u postgres psql -c "GRANT CONNECT ON DATABASE $b TO datos_lectura"
done

# 2. Instalar el script (copiado, no enlazado al repo)
cd /var/www/brynex && git pull -q   # o desplegar primero
install -m 755 -o root -g root /var/www/brynex/scripts/consultar-gh.sh /usr/local/sbin/consultar-gh

# 3. Probarlo a mano
echo "SELECT count(*) FROM personas" | SSH_ORIGINAL_COMMAND=liderapp /usr/local/sbin/consultar-gh
echo "DELETE FROM personas" | SSH_ORIGINAL_COMMAND=liderapp /usr/local/sbin/consultar-gh           # debe decir "Solo SELECT o WITH"
sudo -u datos_lectura psql -d liderapp -c "UPDATE personas SET alias = alias WHERE false"  # debe fallar: solo lectura

# 4. Crear la llave y autorizarla solo para ese script
ssh-keygen -t ed25519 -N '' -C gh-actions-consultar -f /root/gh-actions-consultar
echo "restrict,command=\"/usr/local/sbin/consultar-gh\" $(cat /root/gh-actions-consultar.pub)" >> /root/.ssh/authorized_keys

# 5. Copiar la privada al secret (se ve una sola vez)
cat /root/gh-actions-consultar
```

Pegar ese contenido en GitHub → brayan3000-gv/Brynex → *Settings* → *Secrets and
variables* → *Actions* → *New repository secret*, con el nombre
`NETCUP_CONSULTAR_KEY`. Después borrarla del servidor:

```bash
shred -u /root/gh-actions-consultar /root/gh-actions-consultar.pub
```

## La llave para abrir el resultado (una sola vez, en la Mac)

```bash
brew install age
age-keygen -o ~/consultar-datos.key    # imprime la pública: age1...
```

- La **pública** (`age1...`) va en GitHub → brayan3000-gv/Brynex → *Settings* →
  *Secrets and variables* → *Actions*, como secret `CONSULTAR_AGE_DESTINO`.
- La **privada** (la línea `AGE-SECRET-KEY-...` del archivo) va solo en la
  configuración del proyecto de Claude → *Environment* → variable de entorno
  `CONSULTAR_AGE_LLAVE`. Nunca en un chat ni en el repo. Después se borra el
  archivo de la Mac o se guarda en el llavero.

Si `sudo -u datos_lectura psql` dice *Peer authentication failed*, el
`pg_hba.conf` no tiene la línea `local all all peer` de Debian: se agrega
`local all datos_lectura peer` antes de las demás y `systemctl reload postgresql`.

## Para cambiar lo que puede leer

Se edita `scripts/consultar-gh.sh`, se mergea y se repite el paso 2. Mientras
no se reinstale, el servidor sigue con la copia vieja: es a propósito, para
que un cambio en `main` no cambie solo lo que deja hacer la llave.
