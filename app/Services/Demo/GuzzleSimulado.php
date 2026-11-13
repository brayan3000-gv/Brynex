<?php

namespace App\Services\Demo;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * Los clientes Guzzle que se crean a mano (WhatsApp, portales de Enlace y Mi
 * Planilla) no pasan por Http::fake(). En la demo se les cambia el «cable» por
 * este: contesta lo de WhatsApp como si Meta lo hubiera recibido y corta todo
 * lo demás, sin abrir una sola conexión.
 *
 * Uso: `new Client(GuzzleSimulado::opciones([...]))`. Fuera de la demo devuelve
 * las opciones tal cual.
 */
class GuzzleSimulado
{
    public static function opciones(array $opciones = []): array
    {
        if (! config('demo.activo')) {
            return $opciones;
        }

        return ['handler' => HandlerStack::create(fn (RequestInterface $r) => Create::promiseFor(self::responder($r)))] + $opciones;
    }

    private static function responder(RequestInterface $r): Response
    {
        $host = $r->getUri()->getHost();
        $ruta = $r->getUri()->getPath();

        // WhatsApp Cloud API: el mensaje «sale» y la app lo guarda en el chat.
        if ($host === 'graph.facebook.com') {
            if (str_ends_with($ruta, '/messages')) {
                $payload = json_decode((string) $r->getBody(), true) ?: [];
                // Si quien prueba la demo conectó su WhatsApp, le llega de verdad
                // (lo manda producción, ver WhatsappDeLaDemo). Si no, solo queda en el chat.
                $real = WhatsappDeLaDemo::enviar($payload);

                return self::json(['messaging_product' => 'whatsapp', 'messages' => [['id' => $real ?: 'wamid.DEMO'.Str::upper(Str::random(24))]]]);
            }
            if (str_ends_with($ruta, '/media')) {
                // El archivo se guarda un rato: si después se envía, va con el pedido.
                return self::json(['id' => WhatsappDeLaDemo::guardarArchivo($r)]);
            }
            if (str_ends_with($ruta, '/message_templates')) {
                return $r->getMethod() === 'GET'
                    ? self::json(['data' => []])
                    : self::json(['id' => 'DEMO-PLANTILLA-'.Str::random(8), 'status' => 'APPROVED']);
            }
        }

        Log::info('Demo: petición cortada (Guzzle)', ['metodo' => $r->getMethod(), 'url' => (string) $r->getUri()]);

        return self::json(['error' => ['message' => 'Demo de BryNex: esta conexión no sale a internet.']], 503);
    }

    private static function json(array $cuerpo, int $estado = 200): Response
    {
        return new Response($estado, ['Content-Type' => 'application/json'], json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
}
