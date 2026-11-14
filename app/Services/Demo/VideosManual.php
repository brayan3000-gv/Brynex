<?php

namespace App\Services\Demo;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Los videos del manual (public/manual, ver scripts/videos) puestos al alcance
 * de quien prueba la demo: la presentación de bienvenida, el «¿Cómo funciona?»
 * de cada módulo y el «ver cómo» de cada paso del recorrido.
 *
 * Lee public/manual/manual.json, el mismo índice de la página del manual, así
 * que un video nuevo que se publique ahí aparece solo en su módulo.
 */
class VideosManual
{
    /** Qué módulo del manual corresponde a cada pantalla (patrón de Request::is). */
    private const RUTAS = [
        'dashboard' => 'entrada',
        'admin/clientes*' => 'clientes',
        'admin/contratos*' => 'contratos',
        'admin/afiliaciones*' => 'afiliaciones',
        'admin/gestion-arl*' => 'afiliaciones',
        'admin/planos*' => 'planos',
        'admin/cobros*' => 'cobros',
        'admin/prestamos*' => 'cobros',
        'admin/facturacion*' => 'facturacion',
        'admin/incapacidades*' => 'incapacidades',
        'admin/tareas*' => 'tareas',
        'cuadre-diario*' => 'caja',
        'visita*' => 'visita',
        'admin/informes*' => 'informes',
        'admin/whatsapp*' => 'comunicacion',
        'admin/marketing*' => 'comunicacion',
        'admin/publicidad*' => 'comunicacion',
        'admin/cotizaciones*' => 'cotizador',
        'admin/configuracion*' => 'administracion',
        'admin/usuarios*' => 'administracion',
        'admin/cartas-laborales*' => 'administracion',
    ];

    private const TITULOS = [
        'entrada' => 'El inicio', 'clientes' => 'Clientes', 'contratos' => 'Contratos',
        'afiliaciones' => 'Afiliaciones', 'planos' => 'Planillas', 'cobros' => 'Cobros',
        'facturacion' => 'Facturación', 'incapacidades' => 'Incapacidades', 'tareas' => 'Tareas',
        'caja' => 'Cuadre de caja', 'visita' => 'Cobro en visita', 'informes' => 'Informes',
        'comunicacion' => 'WhatsApp y marketing', 'cotizador' => 'Cotizador', 'administracion' => 'Administración',
    ];

    /** El video de cada paso del recorrido (resources/views/demo/partials/franja). */
    public const PASOS = [
        'persona' => 'clientes-crear',
        'contrato' => 'contratos-crear',
        'radicar' => 'afiliaciones-arl-api',
        'planilla' => 'planos-descargar',
        'whatsapp' => 'whatsapp-bandeja',
    ];

    /** Todos los clips por id, prefiriendo el de escritorio cuando hay dos formatos. */
    public static function todos(): array
    {
        $enDemo = (bool) config('demo.activo');

        return Cache::remember('videos_manual_v4_'.($enDemo ? 'demo' : 'app'), 600, function () use ($enDemo) {
            $json = json_decode((string) @file_get_contents(public_path('manual/manual.json')), true) ?: [];
            $clips = [];

            foreach ($json as $c) {
                if (empty($c['id']) || empty($c['archivo'])) {
                    continue;
                }
                if (isset($clips[$c['id']]) && ($c['formato'] ?? '') !== 'escritorio') {
                    continue;
                }
                $clips[$c['id']] = [
                    'id' => $c['id'],
                    'titulo' => $c['titulo'] ?? '',
                    'escena' => $c['escena'] ?? '',
                    'segundos' => (int) round((float) ($c['segundos'] ?? 0)),
                    'pasos' => array_values(array_filter((array) ($c['pasos'] ?? []))),
                    'url' => asset('manual/'.ltrim($c['archivo'], '/')),
                ];
            }

            // Las versiones grabadas en la demo (public/manual/demo, scripts/videos/
            // escenas/demo-*.mjs) reemplazan al clip del manual con el mismo id:
            // muestran el flujo que ve el prospecto (p. ej. la afiliación simulada).
            // Solo en la demo: en brynex.co se ve el flujo real del manual.
            // Los que no existen en el manual (p. ej. demo-eps-api) entran como
            // clips nuevos del módulo de su `escena`, de primeros.
            $propios = $enDemo ? (json_decode((string) @file_get_contents(public_path('manual/demo/demo.json')), true) ?: []) : [];
            $nuevos = [];
            foreach ($propios as $id => $c) {
                if (empty($c['archivo']) || ! is_file(public_path('manual/'.$c['archivo']))) {
                    continue;
                }
                $base = $clips[$id] ?? ['id' => $id, 'titulo' => '', 'escena' => $c['escena'] ?? '', 'segundos' => 0];
                if (! isset($clips[$id]) && $base['escena'] === '') {
                    continue;
                }
                $clip = [
                    'titulo' => $c['titulo'] ?? $base['titulo'],
                    'segundos' => (int) round((float) ($c['segundos'] ?? $base['segundos'])),
                    'pasos' => array_values(array_filter((array) ($c['pasos'] ?? []))),
                    'url' => asset('manual/'.ltrim($c['archivo'], '/')),
                ] + $base;
                if (isset($clips[$id])) {
                    $clips[$id] = $clip;
                } else {
                    $nuevos[$id] = $clip;
                }
            }

            return $nuevos + $clips;
        });
    }

    public static function clip(string $id): ?array
    {
        return self::todos()[$id] ?? null;
    }

    public static function comercial(): ?array
    {
        $c = self::clip('comercial');

        return $c ? $c + ['portada' => asset('manual/comercial-portada.jpg')] : null;
    }

    /**
     * Los capítulos completos del manual (uno por módulo, narrado de principio a
     * fin). No van en git: los sube `scripts/demo/demo.sh capitulos` a
     * public/manual/capitulos con su capitulos.json. Si no están, la demo usa
     * solo los clips.
     */
    public static function capitulos(): array
    {
        return Cache::remember('demo_capitulos_manual', 600, function () {
            $json = json_decode((string) @file_get_contents(public_path('manual/capitulos/capitulos.json')), true) ?: [];
            $caps = [];

            foreach ($json as $escena => $c) {
                if (! empty($c['archivo']) && is_file(public_path('manual/capitulos/'.$c['archivo']))) {
                    $caps[$escena] = [
                        'id' => 'cap-'.$escena,
                        'titulo' => (self::TITULOS[$escena] ?? ucfirst($escena)).', de principio a fin',
                        'escena' => $escena,
                        'segundos' => (int) ($c['segundos'] ?? 0),
                        'pasos' => [],
                        'capitulo' => true,
                        'url' => asset('manual/capitulos/'.$c['archivo']),
                    ];
                }
            }

            return $caps;
        });
    }

    /**
     * Un clip que enseña algo por sí solo. Los de una sola frase o de menos de
     * 14 segundos son recortes para buscar en el manual, no para aprender el
     * módulo: en la demo no se muestran (el capítulo ya los cubre).
     */
    private static function sirve(array $c): bool
    {
        return in_array($c['id'], self::PASOS, true) || ($c['segundos'] >= 14 && count($c['pasos']) >= 2);
    }

    /** Los videos del módulo de esta pantalla: primero el capítulo, luego las tareas. Null si no hay. */
    public static function deLaPagina(Request $request): ?array
    {
        foreach (self::RUTAS as $patron => $escena) {
            if ($request->is($patron)) {
                $clips = array_values(array_filter(self::todos(), fn ($c) => $c['escena'] === $escena && self::sirve($c)));
                $capitulo = self::capitulos()[$escena] ?? null;

                return ($capitulo || $clips) ? [
                    'titulo' => self::TITULOS[$escena] ?? ucfirst($escena),
                    'capitulo' => $capitulo,
                    'clips' => $clips,
                ] : null;
            }
        }

        return null;
    }

    /** El clip de cada paso del recorrido, por clave del paso. */
    public static function deLosPasos(): array
    {
        return array_filter(array_map(fn ($id) => self::clip($id), self::PASOS));
    }
}
