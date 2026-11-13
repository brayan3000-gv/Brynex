<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Mete lo de la demo en las pantallas de siempre sin tocar sus vistas: la
 * franja de «estás en la demo», los datos de entrada en el login y, en
 * Afiliaciones, el botón de radicación simulada dentro del modal del radicado.
 *
 * Solo lo registra DemoServiceProvider, y solo en páginas HTML completas.
 */
class InyectarDemo
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $response instanceof Response || $response->getStatusCode() !== 200
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();
        $cierre = strripos($html, '</body>');

        if ($cierre === false) {
            return $response;
        }

        $extra = view('demo.partials.franja', ['enLogin' => $request->routeIs('login')])->render();

        if (str_contains($html, 'id="modalRadicado"')) {
            $extra .= view('demo.partials.radicar')->render();
        }

        $response->setContent(substr($html, 0, $cierre).$extra.substr($html, $cierre));

        return $response;
    }
}
