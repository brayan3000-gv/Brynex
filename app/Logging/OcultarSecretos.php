<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Tap de los canales de log: tacha los tokens de Meta antes de escribir.
 *
 * Los servicios de Meta (pauta, WhatsApp, Instagram, métricas) mandan el
 * access_token en la URL. Cuando una petición falla por red, Laravel lanza
 * una ConnectionException cuyo mensaje trae la URL completa, y el token
 * terminaba en storage/logs (5-oct-2026, y al menos seis días antes).
 *
 * Se tacha el texto ya formateado y no el LogRecord porque el mensaje de la
 * excepción solo se vuelve texto dentro del formateador.
 */
class OcultarSecretos
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(self::envolver($handler->getFormatter()));
            }
        }
    }

    public static function tachar(string $texto): string
    {
        return preg_replace([
            // ?access_token=EAA…&  (URL, también urlencoded)
            '/(access_token=)[^&\s"\'\\\\]+/i',
            // "access_token":"…"  y su versión escapada dentro de JSON
            '/("access_token\\\\?"\s*:\s*\\\\?")[^"\\\\]+/i',
            // Cualquier token de Meta suelto: Bearer, contexto, cuerpos
            '/\bEAA[A-Za-z0-9]{30,}/',
        ], ['$1[oculto]', '$1[oculto]', '[token-oculto]'], $texto) ?? $texto;
    }

    private static function envolver(FormatterInterface $interno): FormatterInterface
    {
        return new class($interno) implements FormatterInterface
        {
            public function __construct(private FormatterInterface $interno) {}

            public function format(LogRecord $record)
            {
                $salida = $this->interno->format($record);

                return is_string($salida) ? OcultarSecretos::tachar($salida) : $salida;
            }

            public function formatBatch(array $records)
            {
                $salida = $this->interno->formatBatch($records);

                return is_string($salida) ? OcultarSecretos::tachar($salida) : $salida;
            }
        };
    }
}
