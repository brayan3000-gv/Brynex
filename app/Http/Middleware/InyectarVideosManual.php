<?php

namespace App\Http\Middleware;

use App\Services\Demo\VideosManual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * En brynex.co (no en la demo, que trae su propia versión con InyectarDemo):
 * un ícono 🎬 al lado del botón de la IA que abre los videos del manual del
 * módulo de esta pantalla. Mismo reproductor de la demo, sin bienvenida ni
 * recorrido.
 *
 * Se apaga con VIDEOS_MANUAL_EN_APP=false. Lo registra DemoServiceProvider.
 */
class InyectarVideosManual
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $response instanceof Response || $response->getStatusCode() !== 200 || ! auth()->check()
            || ! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $modulo = VideosManual::deLaPagina($request);
        $html = (string) $response->getContent();
        $cierre = strripos($html, '</body>');

        if (! $modulo || $cierre === false) {
            return $response;
        }

        $extra = view('demo.partials.videos', [
            'modulo' => $modulo,
            'comercial' => null,
            'pasos' => [],
            'bienvenida' => false,
            'nombre' => '',
            'icono' => true,
        ])->render();

        $response->setContent(substr($html, 0, $cierre).$extra.substr($html, $cierre));

        return $response;
    }
}
