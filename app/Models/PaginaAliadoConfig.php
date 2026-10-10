<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaginaAliadoConfig extends BaseModel
{
    protected $table = 'pagina_aliado_config';

    protected $fillable = [
        'aliado_id',
        'activo',
        'hero_titulo',
        'hero_subtitulo',
        'hero_cta_texto',
        'hero_imagen',
        'seo_titulo',
        'seo_descripcion',
        'mostrar_precios',
        'precios_modo',
        'secciones',
        'whatsapp_mensaje_base',
        'estadisticas_visibles',
    ];

    protected $casts = [
        'activo'                => 'boolean',
        'mostrar_precios'       => 'boolean',
        'estadisticas_visibles' => 'boolean',
        'secciones'             => 'array',
    ];

    // Defaults espejados: create() no refresca el modelo con los DEFAULT que aplicó la BD
    // (mismo problema documentado en MarketingCampana), así que un registro recién creado en
    // memoria debe verse igual que uno recién leído de la BD sin un ->fresh() explícito.
    protected $attributes = [
        'activo'                => false,
        'mostrar_precios'       => true,
        'precios_modo'          => 'exacto',
        'estadisticas_visibles' => true,
    ];

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    /**
     * Secciones activas por defecto si el aliado no ha personalizado nada todavía.
     * hero/servicios/pasos/faq/contacto ya existen en la vista pública (Fase 0-2);
     * planes/cotizador/ahorro/promos son claves reservadas para cuando Fase 1/4 las construyan
     * — se guardan igual para no requerir una migración nueva cuando eso pase.
     */
    public static function seccionesPorDefecto(): array
    {
        return [
            'hero'      => true,
            'servicios' => true,
            'planes'    => true,
            'cotizador' => true,
            'ahorro'    => true,
            'pasos'     => true,
            'faq'       => true,
            'promos'    => true,
            'contacto'  => true,
        ];
    }

    /** Claves de sección editables desde el CMS admin hoy (las demás aún no tienen UI que las use). */
    public static function seccionesEditables(): array
    {
        return ['servicios', 'pasos', 'faq'];
    }

    public function seccionActiva(string $nombre): bool
    {
        $secciones = $this->secciones ?: self::seccionesPorDefecto();
        return (bool) ($secciones[$nombre] ?? true);
    }
}
