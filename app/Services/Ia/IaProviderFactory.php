<?php

namespace App\Services\Ia;

use App\Services\Ia\Providers\ClaudeProvider;
use App\Services\Ia\Providers\GeminiProvider;
use App\Services\Ia\Providers\OpenAiProvider;

class IaProviderFactory
{
    public static function make(string $proveedor): IaProviderInterface
    {
        if (config('demo.activo')) { return new \App\Services\Demo\IaSimulada(); }
        return match ($proveedor) {
            'openai' => new OpenAiProvider(),
            'gemini' => new GeminiProvider(),
            default  => new ClaudeProvider(),
        };
    }
}
