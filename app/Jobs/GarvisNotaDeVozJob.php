<?php

namespace App\Jobs;

use App\Services\GarvisService;
use App\Services\Ia\TranscripcionAudioService;
use App\Services\WhatsappApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Una nota de voz de Brayan para GARVIS: se baja de Meta, se pasa a texto con el mismo
 * Gemini que transcribe los audios de los aliados, y el texto va al issue del día como
 * cualquier mensaje suyo.
 *
 * GARVIS corre en GitHub Actions y allá no llegan ni el audio ni las credenciales de Meta,
 * así que lo que viaja es el texto. El audio se borra al terminar: ya dijo lo que tenía
 * que decir y es la voz de Brayan en disco.
 */
class GarvisNotaDeVozJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public function backoff(): array
    {
        return [10, 30];
    }

    public function __construct(protected string $mediaId, protected ?string $mimeType = null, protected string $canal = GarvisService::CANAL_BRYGAR) {}

    public function handle(GarvisService $garvis, WhatsappApiService $whatsappApi): void
    {
        $apiKey = $garvis->llaveGemini();

        if (! $apiKey) {
            $garvis->responder('No pude escuchar tu nota de voz: no hay llave de Gemini ni en la IA de Brygar ni en la global de Brynex. Escríbeme el mensaje mientras tanto.', $this->canal);

            return;
        }

        $ruta = $whatsappApi->descargarMedia($this->mediaId, $garvis->config($this->canal));

        if (! $ruta) {
            // Meta a veces tarda en servir el archivo; los reintentos lo cubren.
            throw new \RuntimeException('GARVIS: Meta no entregó la nota de voz.');
        }

        try {
            $r = TranscripcionAudioService::transcribir($apiKey, Storage::disk('local')->path($ruta), $this->mimeType);
        } finally {
            Storage::disk('local')->delete($ruta);
        }

        if (! $r['ok']) {
            Log::warning('GARVIS: no se pudo transcribir la nota de voz', ['error' => $r['error']]);
            $garvis->responder('No te entendí la nota de voz. ¿Me la repites o me la escribes?', $this->canal);

            return;
        }

        // 🎤 le dice a GARVIS que esto se dijo en voz alta: la transcripción puede traer
        // un nombre mal escuchado, y conviene que lo tenga en cuenta antes de actuar.
        $garvis->pasarAGarvis('📱 🎤 '.$r['texto'], $this->canal);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GARVIS: la nota de voz no llegó', ['error' => $e->getMessage()]);
        app(GarvisService::class)->responder('No pude bajar tu nota de voz de WhatsApp. ¿Me la mandas otra vez o me la escribes?', $this->canal);
    }
}
