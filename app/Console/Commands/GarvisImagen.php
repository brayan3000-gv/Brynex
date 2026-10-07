<?php

namespace App\Console\Commands;

use App\Services\GarvisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Le manda a Brayan por WhatsApp una captura que tomó GARVIS. El envoltorio
 * /usr/local/sbin/garvis-responder-gh, en modo `imagen`, deja el archivo en
 * storage/app/garvis/ y llama a este comando; al terminar lo borra.
 *
 *   php artisan garvis:imagen garvis/captura-123.img
 */
class GarvisImagen extends Command
{
    protected $signature = 'garvis:imagen {ruta : Ruta en el disco local, dentro de garvis/} {--gastos : Desde la línea de gastos y no desde la de Brygar}';

    protected $description = 'Envía a Brayan por WhatsApp una captura de GARVIS (JPEG o PNG)';

    /** Lo único que Meta muestra como imagen. */
    private const TIPOS = ['image/jpeg', 'image/png'];

    public function handle(GarvisService $garvis): int
    {
        $ruta = (string) $this->argument('ruta');

        // El envoltorio solo escribe ahí; cualquier otra ruta es alguien probando.
        if (! preg_match('#^garvis/[A-Za-z0-9._-]+$#', $ruta) || ! Storage::disk('local')->exists($ruta)) {
            $this->error('Ruta no válida.');

            return self::FAILURE;
        }

        // El tipo sale de los bytes, no del nombre: lo que llega por la llave no se cree.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk('local')->path($ruta));

        if (! in_array($mime, self::TIPOS, true)) {
            $this->error("No es una imagen JPEG ni PNG ({$mime}).");

            return self::FAILURE;
        }

        // Meta toma el tipo del nombre del archivo que se sube, así que lleva su extensión.
        $conNombre = preg_replace('/\.img$/', '', $ruta).($mime === 'image/png' ? '.png' : '.jpg');
        Storage::disk('local')->move($ruta, $conNombre);

        try {
            $enviada = $garvis->enviarImagen($conNombre, $mime, $this->option('gastos') ? GarvisService::CANAL_GASTOS : GarvisService::CANAL_BRYGAR);
        } finally {
            // Ya está en el issue; aquí no tiene por qué quedarse.
            Storage::disk('local')->delete($conNombre);
        }

        if ($enviada) {
            $this->info('Captura enviada.');

            return self::SUCCESS;
        }

        // Fuera de la ventana de 24 h Meta no deja mandar imágenes; la captura
        // igual quedó en el issue.
        $this->error('No se pudo enviar. El detalle quedó en el log.');

        return self::FAILURE;
    }
}
