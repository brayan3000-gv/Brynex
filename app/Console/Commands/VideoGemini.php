<?php

namespace App\Console\Commands;

use App\Models\IaConfiguracionAliado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Genera un video corto con Veo (Gemini API) y lo guarda en disco. Igual que
 * brynex:imagen-gemini, es para material de páginas públicas y la llave nunca
 * se imprime. Veo cobra por segundo generado: usar con medida.
 *
 *   php artisan brynex:video-gemini --listar
 *   php artisan brynex:video-gemini "la cámara se acerca despacio..." --imagen=public/img/aliados/carga.png --salida=storage/app/video.mp4
 */
class VideoGemini extends Command
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    protected $signature = 'brynex:video-gemini
        {prompt? : Qué debe pasar en el video}
        {--imagen= : Imagen de partida (el video arranca desde ella)}
        {--salida= : Ruta del .mp4 a escribir (relativa a la raíz del proyecto)}
        {--modelo= : Modelo de Veo; sin indicarlo se usa el primero «fast» disponible}
        {--ratio=16:9 : Relación de aspecto (16:9 o 9:16)}
        {--listar : Solo muestra los modelos de video disponibles con esta llave}';

    protected $description = 'Genera un video corto con Veo (Gemini) y lo guarda en disco';

    public function handle(): int
    {
        // Primero la llave global; si no hay, la configurada en la IA de Brygar (aliado 2).
        $apiKey = config('services.gemini.api_key')
            ?: optional(IaConfiguracionAliado::where('aliado_id', 2)->first())->gemini_api_key;
        if (! $apiKey) {
            $this->error('No hay llave de Gemini: ni en services.gemini.api_key ni en la configuración de IA del aliado 2 (Brygar).');

            return self::FAILURE;
        }
        $http = fn () => Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(120);

        $modelos = collect($http()->get(self::BASE.'/models', ['pageSize' => 200])->json('models', []))
            ->filter(fn ($m) => str_contains($m['name'] ?? '', 'veo'))
            ->map(fn ($m) => str_replace('models/', '', $m['name']))
            ->values();

        if ($this->option('listar')) {
            $modelos->isEmpty() ? $this->warn('Esta llave no tiene modelos de video (Veo).') : $this->line($modelos->implode("\n"));

            return self::SUCCESS;
        }
        if (! $this->argument('prompt')) {
            $this->error('Falta el prompt.');

            return self::FAILURE;
        }

        $modelo = $this->option('modelo')
            ?: $modelos->sortDesc()->first(fn ($m) => str_contains($m, 'fast'))
            ?: $modelos->sortDesc()->first();
        if (! $modelo) {
            $this->error('Esta llave no tiene modelos de video (Veo).');

            return self::FAILURE;
        }

        $instancia = ['prompt' => $this->argument('prompt')];
        if ($img = $this->option('imagen')) {
            $ruta = base_path($img);
            if (! is_file($ruta)) {
                $this->error("No existe la imagen: $img");

                return self::FAILURE;
            }
            $instancia['image'] = ['bytesBase64Encoded' => base64_encode(file_get_contents($ruta)), 'mimeType' => mime_content_type($ruta)];
        }

        $this->line("Modelo: $modelo");
        $resp = $http()->post(self::BASE."/models/$modelo:predictLongRunning", [
            'instances' => [$instancia],
            'parameters' => ['aspectRatio' => $this->option('ratio')],
        ]);
        if (! $resp->ok() || ! $resp->json('name')) {
            $this->error('Veo respondió '.$resp->status().': '.mb_substr($resp->body(), 0, 500));

            return self::FAILURE;
        }
        $operacion = $resp->json('name');

        // Tarda de uno a varios minutos.
        for ($i = 0; $i < 60; $i++) {
            sleep(10);
            $estado = $http()->get(self::BASE.'/'.$operacion)->json();
            if (! empty($estado['error'])) {
                $this->error('Veo falló: '.mb_substr(json_encode($estado['error']), 0, 500));

                return self::FAILURE;
            }
            if (! empty($estado['done'])) {
                $uri = data_get($estado, 'response.generateVideoResponse.generatedSamples.0.video.uri')
                    ?? data_get($estado, 'response.generatedVideos.0.video.uri');
                if (! $uri) {
                    $this->error('Veo terminó sin video: '.mb_substr(json_encode($estado['response'] ?? $estado), 0, 500));

                    return self::FAILURE;
                }
                $salida = $this->option('salida') ?: 'storage/app/video-gemini-'.date('YmdHis').'.mp4';
                $destino = base_path($salida);
                if (! is_dir(dirname($destino))) {
                    mkdir(dirname($destino), 0775, true);
                }
                $video = Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(300)->withOptions(['allow_redirects' => true])->get($uri);
                if (! $video->ok()) {
                    $this->error('No se pudo descargar el video: '.$video->status());

                    return self::FAILURE;
                }
                file_put_contents($destino, $video->body());
                $this->info("Video guardado en $salida (".round(filesize($destino) / 1024).' KB)');

                return self::SUCCESS;
            }
        }
        $this->error('Veo no terminó en 10 minutos. Operación: '.$operacion);

        return self::FAILURE;
    }
}
