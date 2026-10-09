<?php

namespace App\Services\Ia;

use App\Models\IaConfiguracionAliado;
use App\Models\IaConsumo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee las fotos y los PDF que los clientes mandan por WhatsApp, con el mismo modelo con el que
 * conversa el aliado (Claude o Gemini: los dos ven imágenes y PDF).
 *
 * Hasta oct-2026 toda imagen recibía un mensaje fijo y pasaba a una persona, que tenía que
 * abrirla para saber si era un comprobante, una cédula o la cotización de otro lado. Ahora el
 * modelo la lee y lo que entendió queda como texto del mensaje, igual que la transcripción de
 * una nota de voz: lo ve la persona en el inbox y lo lee la IA para seguir la conversación.
 *
 * Un comprobante se lee pero no se juzga: el modelo no dice si el pago es válido y nada aquí
 * registra plata. Lo confirma una persona (ver WhatsappLeerImagenJob).
 */
class LecturaImagenService
{
    public const IMAGENES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const PDF = 'application/pdf';

    /** Claude no acepta imágenes de más de 5 MB; WhatsApp las comprime muy por debajo. */
    private const MAX_BYTES_IMAGEN = 5 * 1024 * 1024;

    /** Un PDF más grande o de más páginas casi nunca es un comprobante: lo revisa una persona. */
    private const MAX_BYTES_PDF = 4 * 1024 * 1024;

    private const MAX_PAGINAS_PDF = 5;

    private const PROMPT = <<<'PROMPT'
    Eres el lector de archivos de un asistente de seguridad social en Colombia. Un cliente mandó
    esta imagen o este PDF por WhatsApp. Devuelve SOLO un objeto JSON, sin texto antes ni después:

    {
      "tipo": "comprobante_pago" | "cedula" | "cotizacion" | "incapacidad" | "planilla" | "documento" | "captura" | "foto" | "ilegible",
      "descripcion": "qué es, en máximo 50 palabras en español, transcribiendo EXACTAMENTE los datos útiles que se vean: nombres, números de documento, valores, fechas, entidades (EPS, ARL, fondo, caja), planes",
      "comprobante": null | {"valor": entero en pesos sin puntos ni signos, "fecha": "AAAA-MM-DD", "hora": "HH:MM", "medio": "Nequi, Bancolombia, Daviplata, PSE, efectivo…", "referencia": "número de aprobación o comprobante", "destinatario": "a quién se pagó, solo el nombre", "origen": "quién pagó: nombre o cuenta, en pocas palabras"}
    }

    Reglas:
    - "comprobante" solo si tipo es "comprobante_pago" (transferencia, consignación, pago PSE, recibo de caja); si no, null.
    - Lo que no se lea con claridad va en null. NUNCA inventes ni completes un número.
    - No digas si un pago es válido, si está completo ni si alcanza: solo lo que se ve.
    - Si el cliente escribió un texto junto al archivo, úsalo solo para entender qué es.
    - Sé breve en cada campo: el JSON completo no debería pasar de 120 palabras.
    PROMPT;

    /** ¿Puede este aliado leer este archivo? Solo donde la IA de WhatsApp está activa. */
    public static function puedeLeer(int $aliadoId, ?string $mime): bool
    {
        $mime = self::mime($mime);
        if (! in_array($mime, [...self::IMAGENES, self::PDF], true)) {
            return false;
        }

        $config = IaConfiguracionAliado::where('aliado_id', $aliadoId)->first();
        if (! $config || ! $config->activo_whatsapp) {
            return false;
        }

        $cred = $config->credencialesEfectivas();

        return in_array($cred['proveedor'], ['claude', 'gemini'], true) && ! empty($cred['api_key']);
    }

    /**
     * @return array{ok: bool, error: ?string, tipo: ?string, descripcion: ?string, comprobante: ?array, texto: ?string, modelo: ?string, uso: array{entrada: int, salida: int, cache_lectura: int, cache_escritura: int}}
     */
    public static function leer(int $aliadoId, string $ruta, ?string $mime, ?string $leyenda = null): array
    {
        $fallo = fn (string $error) => ['ok' => false, 'error' => $error, 'tipo' => null, 'descripcion' => null, 'comprobante' => null, 'texto' => null, 'modelo' => null,
            'uso' => ['entrada' => 0, 'salida' => 0, 'cache_lectura' => 0, 'cache_escritura' => 0]];

        $mime = self::mime($mime);
        if (! is_file($ruta)) {
            return $fallo('El archivo no está en disco.');
        }

        $bytes = filesize($ruta);
        if ($mime === self::PDF) {
            if ($bytes > self::MAX_BYTES_PDF || self::paginasPdf($ruta) > self::MAX_PAGINAS_PDF) {
                return $fallo('PDF demasiado largo para leerlo automáticamente.');
            }
        } elseif ($bytes > self::MAX_BYTES_IMAGEN) {
            return $fallo('Imagen demasiado pesada.');
        }

        $cred = IaConfiguracionAliado::paraAliado($aliadoId)->credencialesEfectivas();
        $instruccion = self::PROMPT.(trim((string) $leyenda) !== '' ? "\n\nTexto que escribió el cliente junto al archivo: «".trim($leyenda).'»' : '');
        $datos = base64_encode(file_get_contents($ruta));

        try {
            $r = match ($cred['proveedor']) {
                'claude' => self::conClaude($cred['api_key'], $cred['modelo'], $mime, $datos, $instruccion),
                'gemini' => self::conGemini($cred['api_key'], $cred['modelo'], $mime, $datos, $instruccion),
                default => null,
            };
        } catch (\Throwable $e) {
            return $fallo($e->getMessage());
        }

        if (! $r) {
            return $fallo('El proveedor de este aliado no lee imágenes.');
        }

        $json = self::json($r['texto']);
        if (! $json || empty($json['tipo'])) {
            Log::warning('IA: la lectura de la imagen no devolvió JSON', ['respuesta' => mb_substr($r['texto'], 0, 300)]);

            return ['ok' => false, 'error' => 'La lectura no devolvió datos.', 'modelo' => $r['modelo'], 'uso' => $r['uso']] + $fallo('');
        }

        $tipo = (string) $json['tipo'];
        $comprobante = $tipo === 'comprobante_pago' && is_array($json['comprobante'] ?? null) ? $json['comprobante'] : null;
        $descripcion = trim((string) ($json['descripcion'] ?? ''));

        return [
            'ok' => $tipo !== 'ilegible',
            'error' => $tipo === 'ilegible' ? 'No se alcanza a leer.' : null,
            'tipo' => $tipo,
            'descripcion' => $descripcion,
            'comprobante' => $comprobante,
            'texto' => self::textoParaElMensaje($tipo, $descripcion, $comprobante, $mime, $leyenda),
            'modelo' => $r['modelo'],
            'uso' => $r['uso'],
        ];
    }

    /** Deja la lectura en ia_consumo. Nunca lanza. */
    public static function registrarConsumo(int $aliadoId, array $resultado): void
    {
        if (empty($resultado['modelo'])) {
            return;
        }

        $u = $resultado['uso'];
        try {
            IaConsumo::create([
                'aliado_id' => $aliadoId,
                'canal' => 'whatsapp_imagen',
                'proveedor' => str_starts_with($resultado['modelo'], 'claude') ? 'claude' : 'gemini',
                'modelo' => $resultado['modelo'],
                'tokens_entrada' => $u['entrada'],
                'tokens_salida' => $u['salida'],
                'tokens_cache_lectura' => $u['cache_lectura'],
                'tokens_cache_escritura' => $u['cache_escritura'],
                'costo_estimado_usd' => PreciosIa::costo($resultado['modelo'], $u['entrada'], $u['salida'], $u['cache_lectura'], $u['cache_escritura']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('IA: no se pudo registrar el consumo de la lectura de imagen', ['error' => $e->getMessage()]);
        }
    }

    /** «$245.600 · 8 de octubre de 2026 · Nequi · ref. 123»: lo que se leyó del comprobante, en una línea. */
    public static function resumenComprobante(array $c): string
    {
        $partes = [];
        if (is_numeric($c['valor'] ?? null) && (int) $c['valor'] > 0) {
            $partes[] = '$'.number_format((int) $c['valor'], 0, ',', '.');
        }
        if (! empty($c['fecha'])) {
            try {
                $partes[] = \Carbon\Carbon::parse($c['fecha'])->translatedFormat('j \d\e F \d\e Y').(! empty($c['hora']) ? ' '.$c['hora'] : '');
            } catch (\Throwable $e) {
                $partes[] = (string) $c['fecha'];
            }
        }
        foreach (['medio' => '', 'referencia' => 'ref. ', 'destinatario' => 'a ', 'origen' => 'de '] as $campo => $prefijo) {
            if (! empty($c[$campo])) {
                $partes[] = $prefijo.trim((string) $c[$campo]);
            }
        }

        return implode(' · ', $partes);
    }

    private static function textoParaElMensaje(string $tipo, string $descripcion, ?array $comprobante, string $mime, ?string $leyenda): string
    {
        $que = $mime === self::PDF ? 'PDF leído' : 'Imagen leída';
        $lectura = $comprobante
            ? '[Comprobante de pago leído por la IA: '.(self::resumenComprobante($comprobante) ?: $descripcion).']'
            : "[{$que} por la IA: {$descripcion}]";

        return trim(trim((string) $leyenda)."\n".$lectura);
    }

    private static function conClaude(string $apiKey, string $modelo, string $mime, string $datos, string $instruccion): array
    {
        $archivo = $mime === self::PDF
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $datos]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $datos]];

        $resp = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $modelo,
                // Un comprobante necesita ~500 tokens de respuesta; con 800, uno de cada diez se
                // cortaba a mitad del JSON en la prueba del 8-oct-2026.
                'max_tokens' => 1500,
                'messages' => [['role' => 'user', 'content' => [$archivo, ['type' => 'text', 'text' => $instruccion]]]],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Claude respondió '.$resp->status().': '.mb_substr($resp->body(), 0, 200));
        }

        $texto = collect($resp->json('content') ?? [])->where('type', 'text')->pluck('text')->implode('');
        $u = $resp->json('usage') ?? [];

        return ['texto' => $texto, 'modelo' => $resp->json('model') ?: $modelo, 'uso' => [
            'entrada' => (int) ($u['input_tokens'] ?? 0), 'salida' => (int) ($u['output_tokens'] ?? 0),
            'cache_lectura' => (int) ($u['cache_read_input_tokens'] ?? 0), 'cache_escritura' => (int) ($u['cache_creation_input_tokens'] ?? 0),
        ]];
    }

    private static function conGemini(string $apiKey, string $modelo, string $mime, string $datos, string $instruccion): array
    {
        $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent", [
                'contents' => [['parts' => [['inline_data' => ['mime_type' => $mime, 'data' => $datos]], ['text' => $instruccion]]]],
                'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini respondió '.$resp->status().': '.mb_substr($resp->body(), 0, 200));
        }

        $u = $resp->json('usageMetadata') ?? [];
        $cache = (int) ($u['cachedContentTokenCount'] ?? 0);

        return ['texto' => (string) $resp->json('candidates.0.content.parts.0.text'), 'modelo' => $modelo, 'uso' => [
            'entrada' => max(0, (int) ($u['promptTokenCount'] ?? 0) - $cache),
            'salida' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0),
            'cache_lectura' => $cache, 'cache_escritura' => 0,
        ]];
    }

    /** El modelo a veces envuelve el JSON en ```json … ```; se toma lo que hay entre las llaves. */
    private static function json(string $texto): ?array
    {
        $ini = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        if ($ini === false || $fin === false || $fin < $ini) {
            return null;
        }
        $json = json_decode(substr($texto, $ini, $fin - $ini + 1), true);

        return is_array($json) ? $json : null;
    }

    /** Cuenta aproximada de páginas sin librerías; 0 si no se puede saber (ahí manda el tamaño). */
    private static function paginasPdf(string $ruta): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page(?!s)/', (string) file_get_contents($ruta));
    }

    /** "image/jpeg; charset=…" → "image/jpeg". */
    private static function mime(?string $mime): string
    {
        return strtolower(trim(explode(';', (string) $mime)[0]));
    }
}
