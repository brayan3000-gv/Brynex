<?php

namespace App\Services\Ia\Providers;

use App\Services\Ia\IaProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements IaProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function chat(string $apiKey, string $modelo, string|array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'model'    => $modelo,
            'messages' => array_merge(
                [['role' => 'system', 'content' => implode("\n\n", (array) $systemPrompt)]],
                $this->traducirMensajes($messages)
            ),
        ];

        if (!empty($tools)) {
            $payload['tools'] = array_map(fn ($t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'],
                    'parameters'  => $t['input_schema'],
                ],
            ], $tools);
        }

        $response = Http::withToken($apiKey)->timeout(30)->post(self::API_URL, $payload);

        if (!$response->successful()) {
            Log::error('IA OpenAI: error en la API', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error consultando OpenAI: ' . $response->status());
        }

        $data = $response->json();
        $mensaje = $data['choices'][0]['message'] ?? [];

        $toolCalls = [];
        foreach ($mensaje['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['function']['name'],
                'input' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ];
        }

        // prompt_tokens incluye lo que salió de la caché; aquí van separados, como en Claude.
        $cache = (int) ($data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);

        return [
            'content'                => $mensaje['content'] ?? null,
            'tool_calls'             => $toolCalls,
            'tokens_entrada'         => max(0, (int) ($data['usage']['prompt_tokens'] ?? 0) - $cache),
            'tokens_salida'          => (int) ($data['usage']['completion_tokens'] ?? 0),
            'tokens_cache_lectura'   => $cache,
            'tokens_cache_escritura' => 0,
        ];
    }

    private function traducirMensajes(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'user') {
                $out[] = ['role' => 'user', 'content' => $m['content']];
            } elseif ($m['role'] === 'assistant') {
                $msg = ['role' => 'assistant', 'content' => $m['content']];
                if (!empty($m['tool_calls'])) {
                    $msg['tool_calls'] = array_map(fn ($tc) => [
                        'id'       => $tc['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tc['name'],
                            'arguments' => json_encode($tc['input']),
                        ],
                    ], $m['tool_calls']);
                }
                $out[] = $msg;
            } elseif ($m['role'] === 'tool_result') {
                $out[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $m['tool_call_id'],
                    'content'      => $m['content'],
                ];
            }
        }
        return $out;
    }
}
