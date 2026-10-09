<?php

namespace App\Services\Ia\Providers;

use App\Services\Ia\IaProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements IaProviderInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function chat(string $apiKey, string $modelo, string|array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            // Los trozos van pegados en orden: la caché automática de Gemini cobra a una décima
            // parte el comienzo que se repite, así que lo fijo tiene que ir primero.
            'systemInstruction' => ['parts' => [['text' => implode("\n\n", (array) $systemPrompt)]]],
            'contents'          => $this->traducirMensajes($messages),
        ];

        if (!empty($tools)) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn ($t) => [
                    'name'        => $t['name'],
                    'description' => $t['description'],
                    'parameters'  => $this->traducirSchema($t['input_schema']),
                ], $tools),
            ]];
        }

        $response = Http::timeout(30)
            ->post(self::BASE_URL . '/' . $modelo . ':generateContent?key=' . $apiKey, $payload);

        if (!$response->successful()) {
            Log::error('IA Gemini: error en la API', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error consultando Gemini: ' . $response->status());
        }

        $data = $response->json();
        $parts = data_get($data, 'candidates.0.content.parts', []);

        $texto = null;
        $toolCalls = [];
        foreach ($parts as $i => $part) {
            if (isset($part['text'])) {
                $texto = ($texto ?? '') . $part['text'];
            } elseif (isset($part['functionCall'])) {
                // Gemini no da un id de llamada (a diferencia de Claude/OpenAI) — se sintetiza uno
                // solo para el turno actual, usado únicamente para emparejar el tool_result que sigue.
                $toolCalls[] = [
                    'id'    => 'gemini_call_' . $i,
                    'name'  => $part['functionCall']['name'],
                    'input' => $part['functionCall']['args'] ?? [],
                    // Firma opaca que Gemini 3.x exige reenviar junto al functionCall en el turno
                    // siguiente (si no, responde 400 "missing a thought_signature") — comprobado
                    // contra la API real. Claude/OpenAI ignoran esta clave extra sin problema.
                    '_thought_signature' => $part['thoughtSignature'] ?? null,
                ];
            }
        }

        // promptTokenCount incluye lo que salió de la caché; aquí van separados, como en Claude.
        $cache = (int) data_get($data, 'usageMetadata.cachedContentTokenCount', 0);

        return [
            'content'                => $texto,
            'tool_calls'             => $toolCalls,
            'tokens_entrada'         => max(0, (int) data_get($data, 'usageMetadata.promptTokenCount', 0) - $cache),
            // Los tokens de "pensamiento" (thinking) se facturan como salida, igual que Claude.
            'tokens_salida'          => (int) data_get($data, 'usageMetadata.candidatesTokenCount', 0)
                + (int) data_get($data, 'usageMetadata.thoughtsTokenCount', 0),
            'tokens_cache_lectura'   => $cache,
            'tokens_cache_escritura' => 0,
        ];
    }

    private function traducirMensajes(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'user') {
                $out[] = ['role' => 'user', 'parts' => [['text' => $m['content']]]];
            } elseif ($m['role'] === 'assistant') {
                $parts = [];
                if (!empty($m['content'])) {
                    $parts[] = ['text' => $m['content']];
                }
                foreach ($m['tool_calls'] ?? [] as $tc) {
                    // Mismo problema que con "properties": una tool sin parámetros (ej.
                    // enviar_tabla_planes) llega con input=[] — PHP lo serializa como [] (lista)
                    // en vez de {} (objeto), y Gemini rechaza "args" con "Proto field is not
                    // repeating, cannot start list" al reconstruir el historial (comprobado
                    // contra la API real, log de producción 2026-07-29 11:59:55).
                    $args = empty($tc['input']) ? new \stdClass() : $tc['input'];
                    $part = ['functionCall' => ['name' => $tc['name'], 'args' => $args]];
                    if (!empty($tc['_thought_signature'])) {
                        $part['thoughtSignature'] = $tc['_thought_signature'];
                    }
                    $parts[] = $part;
                }
                $out[] = ['role' => 'model', 'parts' => $parts];
            } elseif ($m['role'] === 'tool_result') {
                // La API rechaza role:"function" con INVALID_ARGUMENT ("Role 'function' is not
                // supported") — comprobado contra la API real, no solo con la doc. El rol válido
                // para reenviar un functionResponse en esta generación de modelos es "user".
                $out[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => $m['name'],
                            'response' => ['result' => json_decode($m['content'], true)],
                        ],
                    ]],
                ];
            }
        }
        return $out;
    }

    /** Traduce un JSON Schema (tipos en minúscula) al formato que espera Gemini (tipos en MAYÚSCULA). */
    private function traducirSchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if ($key === 'type' && is_string($value)) {
                $out[$key] = strtoupper($value);
            } elseif ($key === 'properties' && is_array($value)) {
                $props = array_map(fn ($p) => $this->traducirSchema($p), $value);
                // Una tool sin parámetros (ej. enviar_tabla_planes) llega con properties=[] — PHP
                // serializa un array vacío como [] (lista JSON), pero Gemini exige un objeto/mapa
                // ahí incluso vacío: sin este cast, la API rechaza TODAS las tools con 400
                // "Cannot bind a list to map for field 'properties'" (comprobado contra la API real).
                $out[$key] = empty($props) ? new \stdClass() : $props;
            } elseif ($key === 'items' && is_array($value)) {
                $out[$key] = $this->traducirSchema($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
