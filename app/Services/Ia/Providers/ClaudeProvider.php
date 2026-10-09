<?php

namespace App\Services\Ia\Providers;

use App\Services\Ia\IaProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeProvider implements IaProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function chat(string $apiKey, string $modelo, string|array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'model'      => $modelo,
            // 1024 se quedaba corto para el JSON del piloto de marketing (tema+título+copy+
            // prompt de imagen detallado) y Claude cortaba la respuesta a la mitad —
            // confirmado viendo la respuesta cruda truncada. Un techo más alto no fuerza
            // respuestas más largas, solo evita el corte cuando de verdad hacen falta.
            'max_tokens' => 4096,
            'system'     => $this->bloquesSistema($systemPrompt),
            'messages'   => $this->traducirMensajes($messages),
        ];

        if (!empty($tools)) {
            $payload['tools'] = array_map(fn ($t) => [
                'name'         => $t['name'],
                'description'  => $t['description'],
                'input_schema' => $this->esquema($t['input_schema']),
            ], $tools);
            // Las herramientas van antes que el system en la caché de Claude: marcar la última
            // las guarda a todas, y así cada llamada solo paga completo lo que cambió.
            $payload['tools'][count($payload['tools']) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ])->timeout(30)->post(self::API_URL, $payload);

        if (!$response->successful()) {
            Log::error('IA Claude: error en la API', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error consultando Claude: ' . $response->status());
        }

        $data = $response->json();

        $texto = null;
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $texto = ($texto ?? '') . $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        return [
            'content'                => $texto,
            'tool_calls'             => $toolCalls,
            // input_tokens de Claude ya excluye lo que salió de la caché o se escribió en ella.
            'tokens_entrada'         => (int) ($data['usage']['input_tokens'] ?? 0),
            'tokens_salida'          => (int) ($data['usage']['output_tokens'] ?? 0),
            'tokens_cache_lectura'   => (int) ($data['usage']['cache_read_input_tokens'] ?? 0),
            'tokens_cache_escritura' => (int) ($data['usage']['cache_creation_input_tokens'] ?? 0),
        ];
    }

    /**
     * Un bloque por trozo del system, con marca de caché en todos menos el último: ese es el
     * que cambia en cada conversación (quién escribe, de qué anuncio llegó…). Claude acepta
     * hasta 4 marcas y una ya la usa la última herramienta, así que como mucho se marcan 3.
     */
    private function bloquesSistema(string|array $systemPrompt): array
    {
        $trozos = array_values(array_filter((array) $systemPrompt, fn ($t) => trim((string) $t) !== ''));
        $ultimo = count($trozos) - 1;

        $bloques = [];
        foreach ($trozos as $i => $texto) {
            $bloque = ['type' => 'text', 'text' => $texto];
            // Un system de un solo trozo es fijo (los generadores de publicidad): se guarda entero.
            if (($i < $ultimo || $ultimo === 0) && $i < 3) {
                $bloque['cache_control'] = ['type' => 'ephemeral'];
            }
            $bloques[] = $bloque;
        }

        return $bloques;
    }

    /**
     * Una tool sin parámetros (ej. enviar_tabla_planes) trae properties=[], que PHP serializa
     * como lista JSON; Claude lo rechaza con 400 "input_schema.properties: Input should be an
     * object" y con eso TODA la llamada falla. Visto en la batería del 8-oct-2026: el
     * GeminiProvider ya tenía este mismo arreglo, el de Claude no, y nadie lo notó porque
     * ningún aliado activo usaba Claude.
     */
    private function esquema(array $schema): array
    {
        if (array_key_exists('properties', $schema) && empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * Búsqueda en internet usando el server tool nativo de Anthropic (web_search).
     * Se ejecuta como una llamada aislada de una sola vuelta: Claude busca y sintetiza
     * la respuesta en el mismo request, así que no hay que gestionar el round-trip
     * de bloques cifrados (web_search_tool_result) que exige la API para continuar
     * una conversación multi-turno con esta tool.
     *
     * @return array{texto: string, tokens_entrada: int, tokens_salida: int}
     */
    public function buscarWeb(string $apiKey, string $modelo, string $consulta): array
    {
        $payload = [
            'model'      => $modelo,
            'max_tokens' => 1024,
            'system'     => 'Responde en español, de forma breve y clara (máx. 200 palabras), basándote en '
                . 'resultados de búsqueda actuales. Al final incluye una sección "Fuentes:" con enlaces markdown '
                . 'a las páginas usadas.',
            'messages'   => [['role' => 'user', 'content' => $consulta]],
            'tools'      => [[
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                'max_uses' => 3,
            ]],
        ];

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ])->timeout(45)->post(self::API_URL, $payload);

        if (!$response->successful()) {
            Log::error('IA Claude: error en búsqueda web', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error buscando en internet: ' . $response->status());
        }

        $data = $response->json();

        $texto = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $texto .= $block['text'];
            }
            // Los bloques 'server_tool_use' y 'web_search_tool_result' se ignoran:
            // Claude ya sintetizó su contenido dentro de los bloques de texto finales.
        }

        return [
            'texto'          => trim($texto) ?: 'No encontré resultados relevantes en internet para esta consulta.',
            'tokens_entrada' => (int) ($data['usage']['input_tokens'] ?? 0),
            'tokens_salida'  => (int) ($data['usage']['output_tokens'] ?? 0),
        ];
    }

    private function traducirMensajes(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'user') {
                $out[] = ['role' => 'user', 'content' => $m['content']];
            } elseif ($m['role'] === 'assistant') {
                $blocks = [];
                if (!empty($m['content'])) {
                    $blocks[] = ['type' => 'text', 'text' => $m['content']];
                }
                foreach ($m['tool_calls'] ?? [] as $tc) {
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'],
                        'name'  => $tc['name'],
                        // Mismo problema que con el esquema: una llamada sin argumentos llega
                        // con input=[] y en el turno siguiente Claude responde 400 "tool_use.input:
                        // Input should be an object". Pasaba en 5 de 31 casos de la batería.
                        'input' => empty($tc['input']) ? new \stdClass() : $tc['input'],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
            } elseif ($m['role'] === 'tool_result') {
                $out[] = [
                    'role'    => 'user',
                    'content' => [[
                        'type'        => 'tool_result',
                        'tool_use_id' => $m['tool_call_id'],
                        'content'     => $m['content'],
                    ]],
                ];
            }
        }
        return $out;
    }
}
