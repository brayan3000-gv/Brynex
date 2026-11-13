<?php

namespace App\Services\Demo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El «internet» de la demo para todo lo que usa la fachada Http.
 *
 * Las integraciones que la demo muestra (ARL, EPS, Enlace, IA) se simulan más
 * arriba, en sus propios servicios, con la forma exacta que espera cada
 * pantalla. Lo que llega hasta aquí es todo lo demás —un servicio que nadie
 * enganchó, una tarea de fondo, una llamada nueva que alguien agregue mañana—
 * y se corta: es la red de seguridad, no la simulación.
 */
class SimuladorHttp
{
    public static function responder(Request $peticion)
    {
        Log::info('Demo: petición cortada (Http)', ['metodo' => $peticion->method(), 'url' => $peticion->url()]);

        return Http::response(
            ['error' => 'Demo de BryNex: esta conexión no sale a internet.', 'demo' => true],
            503
        );
    }
}
