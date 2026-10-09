<?php

namespace App\Services\Ia;

/**
 * Contrato que debe cumplir cualquier proveedor de IA (Claude, OpenAI, ...).
 *
 * Formato normalizado de $messages (agnóstico de proveedor):
 *   ['role' => 'user',        'content' => 'texto']
 *   ['role' => 'assistant',   'content' => ?'texto', 'tool_calls' => [['id','name','input'=>array]]]
 *   ['role' => 'tool_result', 'tool_call_id' => string, 'name' => string, 'content' => string]
 *
 * Formato de $tools: [['name','description','input_schema' => JSON Schema array]]
 *
 * $systemPrompt puede ser un texto o una lista de trozos, de lo que nunca cambia a lo que
 * cambia en cada conversación. Claude guarda en caché todo lo que va antes del último trozo
 * (lo lee a una décima parte del precio); Gemini y OpenAI los pegan y aprovechan su caché
 * automática, que también funciona solo si el comienzo se repite igual.
 */
interface IaProviderInterface
{
    /**
     * tokens_entrada son solo los que no salieron de la caché; los de la caché van aparte.
     *
     * @param  string|string[]  $systemPrompt
     * @return array{
     *   content: ?string,
     *   tool_calls: array<int, array{id:string, name:string, input:array}>,
     *   tokens_entrada: int,
     *   tokens_salida: int,
     *   tokens_cache_lectura: int,
     *   tokens_cache_escritura: int
     * }
     */
    public function chat(string $apiKey, string $modelo, string|array $systemPrompt, array $messages, array $tools): array;
}
