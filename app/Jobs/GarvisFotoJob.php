<?php

namespace App\Jobs;

use App\Services\GarvisService;
use App\Services\WhatsappApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Una foto de Brayan para GARVIS, casi siempre la captura de un error.
 *
 * GARVIS corre en GitHub Actions, donde no llegan ni la foto ni las credenciales de Meta.
 * La foto se baja aquí y se deja detrás de un enlace firmado que vence en unas horas
 * (GarvisFotoController); el enlace va en el issue y el workflow de GARVIS la descarga
 * para mirarla. Así GARVIS ve la imagen real, no una descripción de otro modelo.
 */
class GarvisFotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CARPETA = 'garvis/fotos';

    /** Lo que tarda GARVIS en empezar es un par de minutos; tres horas sobran. */
    private const VIGENCIA_HORAS = 3;

    public int $tries = 3;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [10, 30];
    }

    public function __construct(protected string $mediaId, protected ?string $mimeType = null, protected string $leyenda = '', protected string $canal = GarvisService::CANAL_BRYGAR) {}

    public function handle(GarvisService $garvis, WhatsappApiService $whatsappApi): void
    {
        $this->limpiarViejas();

        $bajada = $whatsappApi->descargarMedia($this->mediaId, $garvis->config($this->canal));

        if (! $bajada) {
            // Meta a veces tarda en servir el archivo; los reintentos lo cubren.
            throw new \RuntimeException('GARVIS: Meta no entregó la foto.');
        }

        $disco = Storage::disk('local');
        $extension = pathinfo($bajada, PATHINFO_EXTENSION) ?: 'jpg';
        // El nombre es la mitad del secreto del enlace: que no se pueda adivinar.
        $nombre = Str::random(32).'.'.$extension;
        $disco->move($bajada, self::CARPETA.'/'.$nombre);

        // Firma relativa: se firma la ruta y no el dominio, para que la firma siga valiendo
        // si APP_URL dice http y Apache redirige a https.
        $enlace = url(URL::temporarySignedRoute('garvis.foto', now()->addHours(self::VIGENCIA_HORAS), ['archivo' => $nombre], false));

        $garvis->pasarAGarvis(trim('📱 🖼️ '.$this->leyenda)."\n\nFoto: ".$enlace, $this->canal);
    }

    /** Pasado un día nadie va a volver a mirarlas, y son fotos del celular de Brayan. */
    private function limpiarViejas(): void
    {
        $disco = Storage::disk('local');
        $limite = now()->subDay()->getTimestamp();

        foreach ($disco->files(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GARVIS: la foto no llegó', ['error' => $e->getMessage()]);
        app(GarvisService::class)->responder('No pude bajar tu foto de WhatsApp. ¿Me la mandas otra vez?', $this->canal);
    }
}
