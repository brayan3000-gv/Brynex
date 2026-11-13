<?php

namespace App\Services\Demo;

use App\Models\WhatsappPlantilla;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Lado de la demo de los WhatsApp reales: convierte lo que la app le iba a
 * mandar a Meta (texto, documento o plantilla, al celular del cliente inventado)
 * en un pedido a producción, que lo manda al WhatsApp verificado de quien está
 * probando. Ver RelevoDemo.
 *
 * Las plantillas se vuelven texto: dentro de las 24 h en que el prospecto
 * escribió se puede mandar texto libre, y así no hace falta que la plantilla
 * exista en la cuenta de Meta de BryNex.
 */
class WhatsappDeLaDemo
{
    /** El prospecto de esta sesión (lo pone DemoController::registro). */
    public static function prospecto(): ?array
    {
        try {
            return session('demo_prospecto');
        } catch (Throwable) {
            return null;
        }
    }

    /** Guarda el archivo de un `/media` y devuelve un id de mentiras para él. */
    public static function guardarArchivo(RequestInterface $r): string
    {
        $id = 'DEMO-MEDIA-'.Str::random(12);
        $archivo = self::archivoDelMultipart($r);

        if ($archivo && self::prospecto()) {
            Cache::put("demo_media_{$id}", $archivo, now()->addHour());
        }

        return $id;
    }

    /** El wa_message_id real si salió, null si no (sin prospecto, sin envíos o falló). */
    public static function enviar(array $payload): ?string
    {
        $p = self::prospecto();
        if (! $p || ! RelevoDemo::activo()) {
            return null;
        }

        // Lo que no es un mensaje (marcar leído, «escribiendo…») no se reenvía.
        if (! isset($payload['type']) || isset($payload['status'])) {
            return null;
        }

        $pedido = self::pedidoDesde($payload);
        if (! $pedido) {
            return null;
        }

        $r = RelevoDemo::pedir('enviar', ['id' => $p['id'], 'pedido' => $pedido]);

        if (isset($r['envios'])) {
            session(['demo_prospecto' => ['envios' => $r['envios'], 'max' => $r['max'] ?? 5] + $p]);
        }

        if (! empty($r['ok'])) {
            session(['demo_ultimo_envio_real' => now()->timestamp]);

            return $r['wa_message_id'] ?? 'wamid.DEMO-REAL';
        }

        return null;
    }

    private static function pedidoDesde(array $payload): ?array
    {
        return match ($payload['type']) {
            'text' => ['tipo' => 'texto', 'texto' => (string) ($payload['text']['body'] ?? '')],
            'document', 'image' => self::conArchivo(
                $payload[$payload['type']]['id'] ?? null,
                (string) ($payload[$payload['type']]['caption'] ?? ''),
                $payload[$payload['type']]['filename'] ?? null
            ),
            'template' => self::desdePlantilla($payload['template'] ?? []),
            default => null,
        };
    }

    /** La plantilla convertida en texto (y su documento del encabezado, si trae). */
    private static function desdePlantilla(array $t): ?array
    {
        $plantilla = WhatsappPlantilla::where('nombre', $t['name'] ?? '')->first();
        $texto = (string) ($plantilla?->cuerpo ?? ($t['name'] ?? 'Mensaje'));
        $documento = null;

        foreach ($t['components'] ?? [] as $c) {
            foreach (($c['parameters'] ?? []) as $i => $param) {
                if (($c['type'] ?? '') === 'body') {
                    $texto = str_replace('{{'.($i + 1).'}}', (string) ($param['text'] ?? ''), $texto);
                }
                if (($param['type'] ?? '') === 'document') {
                    $documento = $param['document'] ?? null;
                }
            }
        }

        return $documento
            ? self::conArchivo($documento['id'] ?? null, $texto, $documento['filename'] ?? null)
            : ['tipo' => 'texto', 'texto' => $texto];
    }

    private static function conArchivo(?string $mediaId, string $texto, ?string $nombre): array
    {
        $archivo = $mediaId ? Cache::get("demo_media_{$mediaId}") : null;

        if (! $archivo) {
            return ['tipo' => 'texto', 'texto' => $texto ?: 'Te enviamos un documento (en la demo no se pudo adjuntar).'];
        }

        return [
            'tipo' => 'documento',
            'texto' => $texto,
            'archivo' => base64_encode($archivo['contenido']),
            'nombre_archivo' => $nombre ?: $archivo['nombre'],
            'mime' => $archivo['mime'],
        ];
    }

    /** El campo `file` del multipart con que WhatsappApiService::subirMedia sube el archivo. */
    private static function archivoDelMultipart(RequestInterface $r): ?array
    {
        if (! preg_match('/boundary="?([^";]+)"?/', $r->getHeaderLine('Content-Type'), $m)) {
            return null;
        }

        $tipo = 'application/pdf';
        $archivo = null;

        foreach (explode('--'.$m[1], (string) $r->getBody()) as $parte) {
            $corte = strpos($parte, "\r\n\r\n");
            if ($corte === false) {
                continue;
            }
            $cabecera = substr($parte, 0, $corte);
            $cuerpo = substr($parte, $corte + 4, -2); // sin el \r\n antes del siguiente límite

            if (str_contains($cabecera, 'name="type"')) {
                $tipo = trim($cuerpo);
            } elseif (str_contains($cabecera, 'name="file"')) {
                preg_match('/filename="([^"]*)"/', $cabecera, $f);
                $archivo = ['contenido' => $cuerpo, 'nombre' => $f[1] ?? 'documento.pdf'];
            }
        }

        return $archivo ? $archivo + ['mime' => $tipo] : null;
    }
}
