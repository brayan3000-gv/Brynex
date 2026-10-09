<?php

namespace App\Services\Ia;

use App\Models\IaConsumo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a texto las notas de voz que llegan por WhatsApp, con Gemini.
 *
 * Existe porque quien contesta un anuncio desde el celular manda audio, y hasta sep-2026 la
 * IA respondía "no puedo escuchar notas de voz" y dejaba la conversación congelada esperando
 * a que alguien abriera el panel. El primer asesor que llegó por la pieza #90 respondió con
 * una nota de voz y estuvo un día sin respuesta.
 *
 * Se manda el audio en la misma petición (inline) en vez de subirlo a la Files API: las notas
 * de WhatsApp son de segundos y pesan poco, y una sola llamada es una cosa menos que pueda
 * fallar a mitad de camino.
 *
 * Modelo: Gemini 3.1 Flash-Lite (GEMINI_MODELO_TRANSCRIBIR para cambiarlo). En la prueba del
 * 8-oct-2026 con 8 notas reales sacó el mismo texto que 2.5 Flash (98,9 % de parecido), más
 * rápido (1,3 s frente a 1,7 s) y a menos de la mitad del precio: 2.5 Flash «piensa» antes de
 * transcribir y esos tokens se cobran como salida. 2.5 Flash queda de respaldo si el principal
 * no responde. Claude no sirve aquí: no oye audio.
 */
class TranscripcionAudioService
{
    private const RESPALDO = 'gemini-2.5-flash';

    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /** Más que esto no se manda inline; la API rechaza peticiones grandes. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    /** Un modelo saturado (429/5xx) se reintenta una vez antes de pasar al respaldo. */
    private const REINTENTABLE = [429, 500, 502, 503, 504];

    private const PROMPT = 'Transcribe literalmente este audio en español. '
        .'Devuelve SOLO la transcripción, sin comillas, sin comentarios y sin '
        .'describir el audio. Si no se entiende nada o no hay voz, responde '
        .'exactamente: SIN_VOZ';

    /**
     * @return array{ok: bool, texto: ?string, error: ?string, modelo: ?string, uso: array{audio: int, entrada: int, salida: int}}
     */
    public static function transcribir(string $apiKey, string $rutaAudio, ?string $mimeType = null): array
    {
        $vacio = ['audio' => 0, 'entrada' => 0, 'salida' => 0];

        if (! is_file($rutaAudio)) {
            return ['ok' => false, 'texto' => null, 'error' => 'El archivo de audio no está en disco.', 'modelo' => null, 'uso' => $vacio];
        }

        if (filesize($rutaAudio) > self::MAX_BYTES) {
            return ['ok' => false, 'texto' => null, 'error' => 'El audio pesa demasiado para transcribirlo.', 'modelo' => null, 'uso' => $vacio];
        }

        // WhatsApp manda las notas de voz en ogg/opus, pero el mime llega con parámetros
        // ("audio/ogg; codecs=opus") que la API no acepta.
        $mime = $mimeType ? trim(explode(';', $mimeType)[0]) : 'audio/ogg';
        $audio = base64_encode(file_get_contents($rutaAudio));

        $modelos = array_values(array_unique([
            (string) config('services.gemini.modelo_transcribir', 'gemini-3.1-flash-lite'),
            self::RESPALDO,
        ]));

        $error = null;
        foreach ($modelos as $modelo) {
            for ($intento = 0; $intento < 2; $intento++) {
                try {
                    $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])
                        // Los dos modelos tienen que caber en los 150 s del job; una nota de
                        // minuto y medio tardó 1,5 s en la prueba, así que 50 s sobra.
                        ->timeout(50)
                        ->post(self::BASE.'/models/'.$modelo.':generateContent', [
                            'contents' => [[
                                'parts' => [
                                    ['text' => self::PROMPT],
                                    ['inline_data' => ['mime_type' => $mime, 'data' => $audio]],
                                ],
                            ]],
                            // Transcribir no es opinar: sin creatividad se pega más a lo que se dijo.
                            'generationConfig' => ['temperature' => 0],
                        ]);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    break; // timeout o red: el respaldo, no el mismo otra vez
                }

                if ($resp->successful()) {
                    $uso = self::uso($resp->json('usageMetadata') ?? []);
                    $texto = trim((string) $resp->json('candidates.0.content.parts.0.text'));

                    if ($texto === '' || $texto === 'SIN_VOZ') {
                        return ['ok' => false, 'texto' => null, 'error' => 'El audio no traía voz entendible.', 'modelo' => $modelo, 'uso' => $uso];
                    }

                    return ['ok' => true, 'texto' => $texto, 'error' => null, 'modelo' => $modelo, 'uso' => $uso];
                }

                $error = 'Gemini ('.$modelo.') respondió '.$resp->status().': '.mb_substr($resp->body(), 0, 200);

                if (in_array($resp->status(), self::REINTENTABLE, true) && $intento === 0) {
                    sleep(1);

                    continue;
                }

                // 404 = el modelo ya no existe para esta llave; 429/5xx tras reintentar: al respaldo.
                // Un 400 es el audio o la llave, y el respaldo no lo va a arreglar.
                if ($resp->status() === 400) {
                    return ['ok' => false, 'texto' => null, 'error' => $error, 'modelo' => $modelo, 'uso' => $vacio];
                }

                break;
            }
        }

        return ['ok' => false, 'texto' => null, 'error' => $error, 'modelo' => null, 'uso' => $vacio];
    }

    /**
     * Deja la transcripción en ia_consumo. Nunca lanza: que falle el registro no puede
     * impedir que el texto llegue a quien lo espera.
     *
     * @param  string  $canal  whatsapp_audio | garvis_audio
     */
    public static function registrarConsumo(int $aliadoId, string $canal, array $resultado): void
    {
        if (empty($resultado['modelo'])) {
            return;
        }

        $uso = $resultado['uso'];
        try {
            IaConsumo::create([
                'aliado_id' => $aliadoId,
                'canal' => $canal,
                'proveedor' => 'gemini',
                'modelo' => $resultado['modelo'],
                'tokens_entrada' => $uso['audio'] + $uso['entrada'],
                'tokens_salida' => $uso['salida'],
                'costo_estimado_usd' => PreciosIa::costo($resultado['modelo'], $uso['entrada'], $uso['salida'], audio: $uso['audio']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('IA: no se pudo registrar el consumo de la transcripción', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{audio: int, entrada: int, salida: int} */
    private static function uso(array $u): array
    {
        $audio = 0;
        foreach ($u['promptTokensDetails'] ?? [] as $d) {
            if (($d['modality'] ?? '') === 'AUDIO') {
                $audio += (int) ($d['tokenCount'] ?? 0);
            }
        }

        return [
            'audio' => $audio,
            'entrada' => max(0, (int) ($u['promptTokenCount'] ?? 0) - $audio),
            'salida' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0),
        ];
    }
}
