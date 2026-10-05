#!/usr/bin/env bash
# Se instala como /usr/local/sbin/garvis-responder-gh y es el command= forzado de la
# llave gh-actions-garvis. Lo único que puede hacer esa llave es esto: tomar lo que llega
# por la entrada estándar y mandárselo a Brayan por WhatsApp con Brynex.
#
# El comando que pida el cliente llega en SSH_ORIGINAL_COMMAND y solo se mira la primera
# palabra, contra una lista cerrada:
#   responder  un texto (lo de siempre; también si no pide nada)
#   imagen     una captura, JPEG o PNG
#   voz        un texto corto que Gemini lee y sale como nota de voz
#   gasto      gastos que Brayan confirmó, en JSON: se registran en Finanzas (garvis:gasto)
#   catalogo   nombres de las categorías y cuentas de Finanzas, para que GARVIS proponga
#              una que exista. Solo nombres: ni saldos ni montos.
set -euo pipefail

modo="${SSH_ORIGINAL_COMMAND:-responder}"
modo="${modo%% *}"

cd /var/www/brynex

case "$modo" in
  responder)
    # Tope de tamaño: una respuesta de GARVIS es un WhatsApp, no un archivo.
    texto="$(head -c 20000)"

    if [ -z "$texto" ]; then
      echo "Sin texto." >&2
      exit 1
    fi

    printf '%s' "$texto" | sudo -u www-data php artisan garvis:responder
    ;;

  voz)
    texto="$(head -c 4000)"

    if [ -z "$texto" ]; then
      echo "Sin texto." >&2
      exit 1
    fi

    printf '%s' "$texto" | sudo -u www-data php artisan garvis:voz
    ;;

  gasto)
    # Lo que imprime el comando es lo que le llega a Brayan, así que sale por stdout.
    entrada="$(head -c 20000)"

    if [ -z "$entrada" ]; then
      echo "Sin gastos." >&2
      exit 1
    fi

    printf '%s' "$entrada" | sudo -u www-data php artisan garvis:gasto
    ;;

  catalogo)
    sudo -u www-data php artisan garvis:gasto --catalogo
    ;;

  imagen)
    # 5 MB es el tope de Meta para una imagen; un byte más y se rechaza aquí.
    max=$((5 * 1024 * 1024))
    dir=storage/app/garvis
    sudo -u www-data mkdir -p "$dir"
    archivo="$dir/captura-$(date +%s)-$$.img"
    trap 'rm -f "$archivo"' EXIT

    head -c $((max + 1)) | sudo -u www-data tee "$archivo" > /dev/null
    tam="$(stat -c %s "$archivo")"
    if [ "$tam" -eq 0 ] || [ "$tam" -gt "$max" ]; then
      echo "La imagen está vacía o pasa de 5 MB." >&2
      exit 1
    fi

    # Qué es de verdad el archivo lo decide el comando, mirando sus bytes.
    sudo -u www-data php artisan garvis:imagen "garvis/$(basename "$archivo")"
    ;;

  *)
    echo "Modo no permitido." >&2
    exit 1
    ;;
esac
