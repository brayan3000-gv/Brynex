#!/usr/bin/env bash
# Lleva la demo pública al código que se acaba de desplegar en producción. Lo
# llama scripts/deploy.sh al final, como root; si la demo no está montada no
# hace nada. Un fallo aquí no afecta a producción (deploy.sh lo avisa y sigue).
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

[[ -d "$DEMO/.git" ]] || exit 0

lock_antes="$(git -C "$DEMO" rev-parse HEAD:composer.lock 2>/dev/null || true)"
git -C "$DEMO" fetch --quiet origin main
git -C "$DEMO" reset --quiet --hard origin/main
if [[ "$(git -C "$DEMO" rev-parse HEAD:composer.lock)" != "$lock_antes" ]]; then
    rsync -a --delete "$PROD/vendor/" "$DEMO/vendor/"
fi
chown -R root:root "$DEMO/app" "$DEMO/config" "$DEMO/resources" "$DEMO/routes" "$DEMO/scripts"

cd "$DEMO"
como_demo() { sudo -u "$USUARIO" env HOME=/tmp TZ=America/Bogota "$@"; }
# La base de la demo es desechable: aquí no hay freno para las migraciones que borran.
como_demo php artisan migrate --force --no-interaction
como_demo php artisan config:clear
como_demo php artisan view:clear
systemctl reload php8.3-fpm || true
echo "  demo → $(git -C "$DEMO" rev-parse --short HEAD)"
