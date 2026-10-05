<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WhatsappPlantilla extends BaseModel
{
    use SoftDeletes;

    protected $table = 'whatsapp_plantillas';

    protected $fillable = [
        'aliado_id',
        'nombre',
        'nombre_display',
        'categoria',
        'idioma',
        'estado',
        'meta_template_id',
        'creado_en_meta',
        'header_tipo',
        'header_valor',
        'cuerpo',
        'footer',
        'botones',
        'variables_mapa',
    ];

    /**
     * Plantilla del botón «Reabrir conversación» del chat. La crea
     * `whatsapp:plantillas-sistema` en cada cuenta.
     */
    public const SISTEMA_REABRIR = 'reabrir_conversacion';

    protected $casts = [
        'botones'       => 'array',
        'variables_mapa'=> 'array',
        'creado_en_meta'=> 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────────

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsappMensaje::class, 'plantilla_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeAprobadas($query)
    {
        return $query->where('estado', 'approved');
    }

    public function scopeDelAliado($query, int $alidoId)
    {
        return $query->where('aliado_id', $alidoId);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Retorna el label del estado para mostrar en la UI.
     */
    public function etiquetaEstado(): string
    {
        return match($this->estado) {
            'approved' => '✅ Aprobada',
            'pending'  => '⏳ Pendiente',
            'rejected' => '❌ Rechazada',
            'paused'   => '⏸ Pausada',
            'disabled' => '🚫 Desactivada',
            default    => $this->estado,
        };
    }

    /**
     * Retorna el color CSS del estado para badges.
     */
    public function colorEstado(): string
    {
        return match($this->estado) {
            'approved' => 'success',
            'pending'  => 'warning',
            'rejected' => 'danger',
            'paused'   => 'secondary',
            'disabled' => 'secondary',
            default    => 'secondary',
        };
    }

    /**
     * Determina si la plantilla tiene botones configurados.
     */
    public function tieneBotones(): bool
    {
        return !empty($this->botones) && count($this->botones) > 0;
    }

    /**
     * Plantillas cuyo cuerpo solo lleva destinatario, aliado y —si tiene tres
     * variables— el valor a pagar.
     *
     * Se reconocen por el nombre porque los envíos arman los parámetros por
     * posición, no desde `variables_mapa`: una plantilla de dos o tres variables
     * que no esté marcada aquí recibe los cinco o seis del recordatorio de cobro
     * (plazo, cuentas, celular de soporte) y Meta rechaza el envío por cantidad
     * de parámetros.
     */
    public const NOMBRES_MOLDE_CORTO = ['cierre', 'sismo'];

    public function usaMoldeCorto(): bool
    {
        foreach (self::NOMBRES_MOLDE_CORTO as $marca) {
            if (str_contains((string) $this->nombre, $marca)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuenta las variables {{N}} en el cuerpo de la plantilla.
     */
    public function cantidadVariables(): int
    {
        preg_match_all('/\{\{\d+\}\}/', $this->cuerpo, $matches);
        return count(array_unique($matches[0]));
    }

    /**
     * Construye el payload de componentes para la API de Meta al enviar.
     * Reemplaza {{1}}, {{2}} etc. con los valores del array $parametros.
     *
     * @param array       $parametros     Valores para las variables del BODY en orden.
     * @param string|null $headerImageUrl URL override para el header IMAGE cuando
     *                                    header_valor no está guardado en la plantilla
     *                                    (p.ej. cobro_header_imagen de WhatsappConfig).
     */
    /**
     * El cuerpo con las variables ya reemplazadas — lo que la persona ve en su teléfono.
     *
     * Se guarda junto al mensaje porque, sin esto, el chat del panel muestra los envíos por
     * plantilla en blanco: cuando el cliente responde, el asesor abre la conversación y no
     * sabe a qué le está contestando, que es justo cuando más falta hace el contexto.
     */
    public function cuerpoRenderizado(array $parametros): string
    {
        $texto = (string) $this->cuerpo;

        foreach (array_values($parametros) as $i => $valor) {
            $texto = str_replace('{{' . ($i + 1) . '}}', (string) $valor, $texto);
        }

        return $texto;
    }

    /**
     * Deja un texto como Meta acepta dentro de una variable de plantilla.
     *
     * La API rechaza el mensaje entero —(#100) Invalid parameter— si una
     * variable trae saltos de línea, tabulaciones o más de cuatro espacios
     * seguidos. Pasa en cuanto el texto lo escribió una persona: el reenvío al
     * dueño perdió 18 avisos el 24-sep-2026 por los saltos de línea de un
     * mensaje de WhatsApp. Se aplana a " · " para que siga leyéndose.
     */
    public static function sanearParametro(string $texto): string
    {
        $texto = preg_replace('/[\r\n]+/u', ' · ', trim($texto));
        $texto = preg_replace('/\t+/u', ' ', $texto);
        $texto = preg_replace('/ {2,}/u', ' ', $texto);

        // Tope de Meta por variable: 1024, y los puntos suspensivos cuentan.
        return Str::limit($texto, 1023, '…');
    }

    public function construirComponentes(array $parametros, ?string $headerImageUrl = null): array
    {
        $componentes = [];

        // ── Header ──────────────────────────────────────────────────
        if ($this->header_tipo) {
            $headerValorEfectivo = $this->header_valor ?: $headerImageUrl;

            if ($this->header_tipo === 'TEXT' && $headerValorEfectivo) {
                $componentes[] = [
                    'type'       => 'header',
                    'parameters' => [['type' => 'text', 'text' => self::sanearParametro($headerValorEfectivo)]],
                ];
            } elseif (in_array($this->header_tipo, ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
                $tipoMedia = strtolower($this->header_tipo);
                if ($headerValorEfectivo) {
                    // Hay URL disponible: enviar como link
                    $componentes[] = [
                        'type'       => 'header',
                        'parameters' => [[
                            'type'    => $tipoMedia,
                            $tipoMedia => ['link' => $headerValorEfectivo],
                        ]],
                    ];
                } else {
                    // Sin URL: enviar header vacío de tipo media (Meta lo requiere igual)
                    $componentes[] = [
                        'type'       => 'header',
                        'parameters' => [[
                            'type'    => $tipoMedia,
                            $tipoMedia => [],
                        ]],
                    ];
                }
            }
        }

        // ── Body con parámetros ──────────────────────────────────────
        if (!empty($parametros)) {
            $bodyParams    = array_map(fn($val) => ['type' => 'text', 'text' => self::sanearParametro((string)$val)], $parametros);
            $componentes[] = ['type' => 'body', 'parameters' => $bodyParams];
        }

        // ── Botones ──────────────────────────────────────────────────
        if ($this->tieneBotones()) {
            foreach ($this->botones as $idx => $boton) {
                if ($boton['tipo'] === 'QUICK_REPLY') {
                    $componentes[] = [
                        'type'       => 'button',
                        'sub_type'   => 'quick_reply',
                        'index'      => (string)$idx,
                        'parameters' => [['type' => 'payload', 'payload' => $boton['texto']]],
                    ];
                }
            }
        }

        return $componentes;
    }
}
