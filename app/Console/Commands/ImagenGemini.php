<?php

namespace App\Console\Commands;

use App\Models\IaConfiguracionAliado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Genera una imagen con Gemini (modelo de imagen) y la guarda en disco.
 * Pensado para material de presentaciones y páginas públicas; la llave
 * nunca se imprime: sale de services.gemini.api_key o, si no está, de la
 * configuración de IA del aliado 1 (BryNex).
 *
 *   php artisan brynex:imagen-gemini "una oficina..." --salida=public/img/aliados/hero.png --ratio=16:9
 */
class ImagenGemini extends Command
{
    protected $signature = 'brynex:imagen-gemini
        {prompt : Descripción de la imagen}
        {--salida= : Ruta del archivo a escribir (relativa a la raíz del proyecto)}
        {--ratio=16:9 : Relación de aspecto (1:1, 16:9, 9:16, 4:3, 3:4)}
        {--modelo=gemini-2.5-flash-image : Modelo de imagen}
        {--referencia=* : Imagen(es) de referencia para mantener el estilo}';

    protected $description = 'Genera una imagen con Gemini y la guarda en disco';

    public function handle(): int
    {
        // Primero la llave global; si no hay, la configurada en la IA de Brygar (aliado 2).
        $apiKey = config('services.gemini.api_key')
            ?: optional(IaConfiguracionAliado::where('aliado_id', 2)->first())->gemini_api_key;

        if (! $apiKey) {
            $this->error('No hay llave de Gemini: ni en services.gemini.api_key ni en la configuración de IA del aliado 2 (Brygar).');

            return self::FAILURE;
        }

        $salida = $this->option('salida') ?: 'storage/app/imagen-gemini-'.date('YmdHis').'.png';
        $ruta = base_path($salida);

        $parts = [['text' => $this->argument('prompt')]];
        foreach ((array) $this->option('referencia') as $ref) {
            $refRuta = base_path($ref);
            if (! is_file($refRuta)) {
                $this->error("No existe la referencia: $ref");

                return self::FAILURE;
            }
            $parts[] = ['inlineData' => [
                'mimeType' => mime_content_type($refRuta),
                'data' => base64_encode(file_get_contents($refRuta)),
            ]];
        }

        $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(180)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$this->option('modelo').':generateContent', [
                'contents' => [['parts' => $parts]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => ['aspectRatio' => $this->option('ratio')],
                ],
            ]);

        if (! $resp->ok()) {
            $this->error('Gemini respondió '.$resp->status().': '.mb_substr($resp->body(), 0, 400));

            return self::FAILURE;
        }

        foreach ($resp->json('candidates.0.content.parts', []) as $part) {
            if (! empty($part['inlineData']['data'])) {
                if (! is_dir(dirname($ruta))) {
                    mkdir(dirname($ruta), 0775, true);
                }
                file_put_contents($ruta, base64_decode($part['inlineData']['data']));
                $this->info("Imagen guardada en $salida (".round(filesize($ruta) / 1024).' KB)');

                return self::SUCCESS;
            }
        }

        $this->error('Gemini no devolvió imagen: '.mb_substr($resp->body(), 0, 400));

        return self::FAILURE;
    }
}
