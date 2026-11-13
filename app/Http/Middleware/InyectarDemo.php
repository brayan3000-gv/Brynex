<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Demo\RelevoDemo;
use App\Services\Demo\VideosManual;
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

        $this->anotarPaso($request, $response);
        // Solo cuenta una pantalla abierta de verdad, no las consultas de fondo
        // (p. ej. el contador de mensajes de WhatsApp que pide cada página).
        $esPagina = $request->isMethod('GET') && ! $request->ajax() && ! $request->expectsJson()
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
        $modulo = $esPagina && auth()->check() ? VideosManual::deLaPagina($request) : null;
        if ($modulo && $request->hasSession()) {
            $vistos = (array) $request->session()->get('demo_modulos', []);
            if (! in_array($modulo['titulo'], $vistos, true)) {
                $request->session()->put('demo_modulos', [...$vistos, $modulo['titulo']]);
            }
        }
        if ($request->hasSession() && auth()->check()) {
            RelevoDemo::sincronizarActividad();
        }

        if (! $response instanceof Response || $response->getStatusCode() !== 200
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();
        $cierre = strripos($html, '</body>');

        if ($cierre === false) {
            return $response;
        }

        $enLogin = $request->routeIs('login') || $request->is('login');

        // El cierre: la primera vez que se ve el recorrido completo.
        $mostrarCierre = false;
        if (! $enLogin && auth()->check() && count((array) $request->session()->get('demo_pasos', [])) >= RelevoDemo::PASOS_RECORRIDO
            && ! $request->session()->get('demo_cierre_visto')) {
            $request->session()->put('demo_cierre_visto', true);
            $mostrarCierre = true;
        }

        $extra = view('demo.partials.franja', ['enLogin' => $enLogin, 'cierre' => $mostrarCierre])->render();

        // Los videos del manual: la presentación la primera vez que se llega al
        // inicio y el «¿Cómo funciona?» de cada módulo (ver VideosManual).
        if (! $enLogin && auth()->check()) {
            $bienvenida = $request->is('dashboard') && ! $request->session()->get('demo_bienvenida_vista');
            if ($bienvenida) {
                $request->session()->put('demo_bienvenida_vista', true);
            }
            $extra .= view('demo.partials.videos', [
                'modulo' => $modulo ?? VideosManual::deLaPagina($request),
                'comercial' => VideosManual::comercial(),
                'pasos' => VideosManual::deLosPasos(),
                'bienvenida' => $bienvenida,
                'nombre' => explode(' ', trim((string) (session('demo_prospecto')['nombre'] ?? '')))[0] ?? '',
            ])->render();
        }

        if (str_contains($html, 'id="modalRadicado"')) {
            $extra .= view('demo.partials.radicar')->render();
        }

        $response->setContent(substr($html, 0, $cierre).$extra.substr($html, $cierre));

        return $response;
    }

    /** Lo que marca cada paso del recorrido (resources/views/demo/partials/franja). */
    private const PASOS = [
        'persona' => 'admin/clientes',
        'contrato' => 'admin/contratos',
        'radicar' => 'demo/radicar/*',
        'planilla' => '*api-operador/liquidar*',
        'ia' => 'asistente-ia/chat',
    ];

    private function anotarPaso(Request $request, $response): void
    {
        if (! $request->hasSession() || $request->method() !== 'POST' || $response->getStatusCode() >= 400) {
            return;
        }

        $hechos = (array) $request->session()->get('demo_pasos', []);

        foreach (self::PASOS as $paso => $patron) {
            if ($request->is($patron) && ! $request->is('demo/radicar/*/anular')) {
                $hechos[] = $paso;
            }
        }
        // El WhatsApp cuenta cuando de verdad le llegó (WhatsappDeLaDemo).
        if ($request->session()->pull('demo_ultimo_envio_real')) {
            $hechos[] = 'whatsapp';
        }

        $request->session()->put('demo_pasos', array_values(array_unique($hechos)));
    }
}
