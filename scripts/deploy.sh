#!/usr/bin/env bash
#
# Despliegue de BryNex en el servidor.
#
# Este script corre EN netcup, pero no se ejecuta desde una copia guardada allá:
# se envía por stdin desde el Mac (ver scripts/desplegar.sh), así que siempre se
# usa la versión que está en el repo local y nunca se reescribe a sí mismo a la
# mitad de un `git pull`.
#
#   bash deploy.sh [--dry-run] [--migrate] [--reverb] [--dir RUTA] [--branch RAMA]
#
#   --dry-run   Solo muestra qué se desplegaría. No toca nada.
#   --migrate   Corre también las migraciones que BORRAN datos. Sin esta
#               bandera, las que solo agregan (tablas, campos, índices) corren
#               solas, y si alguna borra —quita una tabla o un campo, vacía o
#               borra filas, cambia el tipo de un campo— el script se detiene
#               ANTES del pull y avisa por WhatsApp (la base es la de
#               producción — ver CLAUDE.md). Así puede correr solo al mergear.
#   --reverb    Además reinicia el proceso de Reverb (corta los websockets
#               abiertos, por eso no se hace por defecto).
#
set -euo pipefail

APP_DIR="/var/www/brynex"
BRANCH="main"
WEB_USER="www-data"
DRY_RUN=0
CORRER_MIGRACIONES=0
REINICIAR_REVERB=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --migrate) CORRER_MIGRACIONES=1; shift ;;
        --reverb)  REINICIAR_REVERB=1; shift ;;
        --dir)     APP_DIR="$2"; shift 2 ;;
        --branch)  BRANCH="$2"; shift 2 ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done

WEB_HOME="$(getent passwd "$WEB_USER" | cut -d: -f6)"

# Aviso por WhatsApp al terminar, salga bien o mal. Sale por Brynex, que ya
# tiene las credenciales de Meta y la plantilla aprobada `notificar_brynex`
# (php artisan whatsapp:alerta-backup): este guion no guarda ningún secreto.
# Si Brynex no logra mandarlo, el despliegue termina igual.
PASO="Comprobaciones"
RESULTADO=""
MOTIVO=""
DETENIDO=0
avisar_whatsapp() {
    local codigo=$1 texto
    if [ "$DETENIDO" -eq 1 ]; then
        texto="⏸️ Detenido sin tocar nada · $MOTIVO"
    elif [ "$codigo" -eq 0 ]; then
        texto="✅ ${RESULTADO:-terminó bien}"
    else
        texto="❌ Falló en «$PASO» (código $codigo)${MOTIVO:+ · $MOTIVO}"
    fi
    [ -f /var/www/brynex/artisan ] || return 0
    (cd /var/www/brynex && sudo -u www-data HOME=/var/www timeout 60 \
        php artisan whatsapp:alerta-backup "Despliegue Brynex" "$texto" >/dev/null 2>&1) || true
}
trap 'avisar_whatsapp $?' EXIT

titulo() { PASO="$*"; printf '\n\033[1m== %s\033[0m\n' "$*"; }
aviso()  { printf '\033[33m!! %s\033[0m\n' "$*"; }
error()  { MOTIVO="$*"; printf '\033[31mXX %s\033[0m\n' "$*" >&2; }

# psysh (tinker) y composer escriben en $HOME/.config, y el home de www-data
# (/var/www) es de root: sin esto, `sudo -u www-data php artisan tinker` muere
# con "Writing to directory /var/www/.config/psysh is not allowed". Se rehace en
# cada despliegue para que el arreglo no dependa de que alguien lo recuerde si
# se reconstruye el servidor. Ningún vhost sirve $WEB_HOME a secas (todos
# apuntan a subdirectorios), así que este directorio no queda expuesto por web.
asegurar_home_web() {
    install -d -o "$WEB_USER" -g "$WEB_USER" -m 750 "$WEB_HOME/.config"
}

# Corre un comando como el usuario de Apache para no dejar archivos de root.
como_web() {
    sudo -u "$WEB_USER" HOME="$WEB_HOME" "$@"
}

# ---------------------------------------------------------------- comprobaciones

[[ -d "$APP_DIR/.git" ]] || { error "$APP_DIR no es un repositorio git."; exit 1; }
cd "$APP_DIR"

if [[ $EUID -ne 0 ]]; then
    error "Hay que correr esto como root: necesita chown y sudo -u $WEB_USER."
    exit 1
fi

rama_actual="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$rama_actual" != "$BRANCH" ]]; then
    error "El servidor está en la rama '$rama_actual' y se esperaba '$BRANCH'."
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    error "El servidor tiene cambios sin commitear. Resuélvelos antes de desplegar:"
    git status --short
    exit 1
fi

titulo "Buscando cambios en origin/$BRANCH"
git fetch origin "$BRANCH" --quiet
commit_antes="$(git rev-parse HEAD)"
pendientes="$(git rev-list --count "HEAD..origin/$BRANCH")"

if [[ "$pendientes" -eq 0 ]]; then
    echo "Ya está al día en $(git rev-parse --short HEAD). No hay nada que desplegar."
    RESULTADO="sin cambios, ya estaba en $(git rev-parse --short HEAD)"
    exit 0
fi

echo "$pendientes commit(s) por desplegar:"
git --no-pager log --oneline "HEAD..origin/$BRANCH"

archivos_cambiados="$(git diff --name-only "HEAD..origin/$BRANCH")"
echo
echo "Archivos que cambian:"
echo "$archivos_cambiados" | sed 's/^/  /'

# ------------------------------------------------------- migraciones y librerías

migraciones_nuevas="$(echo "$archivos_cambiados" | grep '^database/migrations/' || true)"
cambio_composer="$(echo "$archivos_cambiados" | grep '^composer\.\(json\|lock\)$' || true)"

# Lo que cuenta como borrar datos, buscado solo dentro de up(): el down() de
# cualquier migración que crea una tabla trae su dropIfExists, y eso no corre.
# Cambiar el tipo de un campo (->change(), ALTER COLUMN de SQL Server) también
# cuenta: desde aquí no se sabe si lo agranda o lo corta. Una migración que se
# sabe segura (agrandar un NVARCHAR, volver un campo nullable) lo declara con
# un comentario `// no-borra-datos: <por qué>` y no detiene nada.
PATRON_BORRA='dropColumns?\(|dropIfExists\(|Schema::drop\(|->drop\(\)|->change\(\)|->truncate\(|->delete\(|dropSoftDeletes|dropTimestamps|dropMorphs|dropRememberToken|DROP[[:space:]]+(TABLE|COLUMN|DATABASE|SCHEMA)|TRUNCATE[[:space:]]+TABLE|DELETE[[:space:]]+FROM|ALTER[[:space:]]+COLUMN'
migraciones_que_borran() {
    local f contenido hallado
    while IFS= read -r f; do
        [[ -n "$f" ]] || continue
        contenido="$(git show "origin/$BRANCH:$f")"
        grep -q 'no-borra-datos' <<<"$contenido" && continue
        hallado="$(awk '/function[[:space:]]+up[[:space:]]*\(/{f=1} /function[[:space:]]+down[[:space:]]*\(/{f=0} f' <<<"$contenido" \
            | sed 's#//.*$##' | grep -oiE "$PATRON_BORRA" | tr -d "()" | sort -u | paste -sd, - || true)"
        [[ -n "$hallado" ]] && echo "$(basename "$f") ($hallado)"
    done
    return 0
}

destructivas=""
if [[ -n "$migraciones_nuevas" ]]; then
    echo
    aviso "Este despliegue trae migraciones:"
    echo "$migraciones_nuevas" | sed 's/^/  /'
    destructivas="$(git diff --name-only --diff-filter=A "HEAD..origin/$BRANCH" -- database/migrations/ | migraciones_que_borran)"
    if [[ -n "$destructivas" ]]; then
        aviso "Estas borran datos:"
        echo "$destructivas" | sed 's/^/  /'
    else
        echo "  Ninguna borra datos: se aplican solas."
    fi
fi

if [[ $DRY_RUN -eq 1 ]]; then
    echo
    echo "(--dry-run: hasta aquí llega, no se tocó nada)"
    RESULTADO="dry-run: $pendientes commit(s) por desplegar${migraciones_nuevas:+, con migraciones}${destructivas:+ (alguna borra datos)}"
    exit 0
fi

if [[ -n "$destructivas" && $CORRER_MIGRACIONES -eq 0 ]]; then
    DETENIDO=1
    error "Hay migraciones que borran datos y la base es la de producción. Revísalas y vuelve a lanzar con --migrate."
    MOTIVO="migración que borra datos: $(echo "$destructivas" | paste -sd';' - | sed 's/;/; /g'). Revísala y lánzala a mano: Actions → Desplegar → desplegar + migrar"
    exit 1
fi

# --------------------------------------------------------------------- despliegue

titulo "Actualizando el código"
git pull --ff-only origin "$BRANCH"
commit_nuevo="$(git rev-parse HEAD)"

# Si algo falla de aquí en adelante, el código ya quedó actualizado: se avisa
# cómo devolverse en vez de hacerlo solo.
trap 'error "Falló el despliegue. Para devolver el código: cd '"$APP_DIR"' && git reset --hard '"$commit_antes"'"' ERR

asegurar_home_web

titulo "Devolviendo los archivos a $WEB_USER"
# git corre como root, así que lo que escribe queda de root y Apache pierde acceso.
# Sin los borrados (--diff-filter=d): chown sobre un archivo que ya no existe
# tumbaba el despliegue a la mitad, con el código ya actualizado.
git diff --name-only --diff-filter=d "$commit_antes" "$commit_nuevo" | tr '\n' '\0' \
    | xargs -0 --no-run-if-empty chown "$WEB_USER":"$WEB_USER"
chown -R "$WEB_USER":"$WEB_USER" .git

if [[ -n "$cambio_composer" ]]; then
    titulo "Instalando dependencias (cambió composer.lock)"
    como_web composer install --no-dev --optimize-autoloader --no-interaction
fi

if [[ -n "$migraciones_nuevas" ]]; then
    titulo "Corriendo migraciones"
    como_web php artisan migrate --force
fi

titulo "Limpiando cachés"
# No se compilan assets: el proyecto no usa @vite en ninguna vista y public/build
# no existe en el servidor. Si algún día se usa, hay que agregar el npm run build.
como_web php artisan config:clear
como_web php artisan route:clear
como_web php artisan view:clear
# Recompilar las vistas de una vez sirve de chequeo: si una quedó con un error de
# sintaxis Blade, el despliegue lo grita ahora y no cuando entre un usuario.
como_web php artisan view:cache

titulo "Reiniciando procesos en segundo plano"
# Los workers tienen el código viejo cargado en memoria hasta que se reinician.
como_web php artisan queue:restart
if [[ $REINICIAR_REVERB -eq 1 ]]; then
    supervisorctl restart brynex-reverb
fi
supervisorctl status | sed 's/^/  /'

# La demo pública (demo.brynex.co) va con el mismo código. Si no está montada no
# hace nada, y si falla no toca producción. Ver scripts/demo/servidor.
if [[ -d /var/www/brynex-demo/.git ]]; then
    titulo "Actualizando la demo pública"
    bash "$APP_DIR/scripts/demo/servidor/actualizar.sh" || echo "  ⚠️  La demo no se pudo actualizar (producción quedó bien)."
fi

# ------------------------------------------------------------------------ cierre

titulo "Listo"
RESULTADO="desplegado $(git rev-parse --short "$commit_antes") → $(git rev-parse --short "$commit_nuevo") · $(git --no-pager log -1 --format=%s | cut -c1-80)"
echo "  $(git rev-parse --short "$commit_antes") -> $(git rev-parse --short "$commit_nuevo")"
echo "  $(git --no-pager log --oneline -1)"

log_de_hoy="storage/logs/laravel-$(date +%F).log"
if [[ -f "$log_de_hoy" ]]; then
    recientes="$(grep -c 'production.ERROR' "$log_de_hoy" || true)"
    echo "  Errores en el log de hoy: ${recientes:-0} (revisa $log_de_hoy si el número te extraña)"
fi
