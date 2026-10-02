#!/usr/bin/env bash
# Genera con Gemini las imágenes de la página pública "Afiliaciones para aliados"
# (brynex.co/aliados/afiliaciones). Necesita GEMINI_API_KEY en el .env.
#
#   ./scripts/imagenes-aliados.sh            # genera las que falten
#   ./scripts/imagenes-aliados.sh --todas    # regenera todas
#
# Las imágenes van a public/img/aliados/ y se suben al repo (storage/app/public
# no se sincroniza a producción).
set -euo pipefail
cd "$(dirname "$0")/.."

ESTILO="Ilustración editorial digital de trazo suave, tipo pintura con luz natural, personas colombianas reales y diversas, oficina pequeña de una empresa de afiliaciones a seguridad social en Colombia. Paleta: azul marino, verde azulado y acentos cálidos ámbar. Composición limpia con aire para texto. Sin texto, sin letras, sin números, sin logos, sin marcas de agua."

generar() {
  local archivo="$1" ratio="$2" prompt="$3"
  if [[ -f "public/img/aliados/$archivo" && "${1:-}" != "--todas" && "${TODAS:-0}" != "1" ]]; then
    echo "· $archivo ya existe, se omite"
    return
  fi
  echo "→ $archivo"
  # Las demás toman carga.png como referencia para que sea la misma persona y el mismo estilo.
  local ref=()
  if [[ "$archivo" != "carga.png" && -f public/img/aliados/carga.png ]]; then
    ref=(--referencia=public/img/aliados/carga.png)
    prompt="Usa la imagen adjunta solo como referencia de estilo y de la misma persona (la auxiliar). $prompt"
  fi
  php artisan brynex:imagen-gemini "$prompt $ESTILO" --salida="public/img/aliados/$archivo" --ratio="$ratio" "${ref[@]}"
}

[[ "${1:-}" == "--todas" ]] && TODAS=1

generar carga.png 16:9 "Escena de noche, lámpara de escritorio encendida. Una auxiliar administrativa de unos 35 años, agotada frente a su computador, se frota los ojos. Sobre el escritorio torres de carpetas y formularios, notas adhesivas por todas partes, un celular con la pantalla iluminada por llamadas, un tinto frío. En el monitor, decenas de ventanas abiertas de portales web. Sensación de sobrecarga y cansancio, con dignidad."

generar pestanas.png 16:9 "Primer plano sobre el hombro: manos tecleando en un computador, la pantalla llena de pestañas y formularios web de distintos colores, y al lado un formulario impreso con una cédula fotocopiada encima. Reloj de pared en el fondo marcando el final de la tarde. Sensación de repetición y tedio."

generar espera.png 9:16 "Un trabajador independiente colombiano (conductor o albañil) sentado en la banca de un parque mirando su celular con cara de impaciencia, esperando una respuesta que no llega. Luz de tarde, tonos cálidos y un poco de melancolía. Formato vertical."

generar entra.png 16:9 "La misma oficina, ahora en la mañana con luz de ventana. La auxiliar sonríe tranquila; en su pantalla hay un solo formulario limpio, y de la pantalla salen líneas de luz suaves que viajan hacia tres edificios estilizados al fondo (una clínica, un edificio corporativo y una caja de compensación). Metáfora visual de que los datos se registran una vez y llegan solos a cada entidad. Esperanza y alivio."

generar firma.png 4:3 "Un trabajador independiente colombiano, sonriente, con casco de obra bajo el brazo, firmando con el dedo en la pantalla de su celular al aire libre en una obra. Luz de día, cercanía y confianza."

generar atencion.png 16:9 "Oficina luminosa de día. La misma auxiliar, ahora relajada y sonriente, atiende cara a cara a una pareja de clientes sentados frente a su escritorio, que la miran con confianza. Escritorio despejado, una sola carpeta, una planta. Calidez humana y servicio."

generar crecer.png 16:9 "Oficina pequeña con la puerta abierta a la calle, varios clientes llegando, la dueña del negocio los recibe con los brazos abiertos y su equipo detrás atiende con calma. Luz de media mañana, sensación de prosperidad y crecimiento sin caos."

generar comprobante.png 1:1 "Primer plano de unas manos sosteniendo un celular; en la pantalla un gran símbolo de visto bueno verde dentro de un círculo y nada más, fondo de pantalla claro. Fondo desenfocado de oficina. Satisfacción y tranquilidad."

echo "Listo. Revisa public/img/aliados/ y súbelas al repo."
