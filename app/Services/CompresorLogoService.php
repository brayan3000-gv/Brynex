<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reduce los logos de los aliados al tamaño que de verdad se usa.
 *
 * Los logos llegan como exportaciones de diseño de 3000-5000 px y varios MB,
 * pero se pintan a 54 px en el panel, a ~150 px en los PDF y, como marca de
 * agua, al 24 % del ancho de la pieza (≈260 px en un flyer de 1080). Guardar el
 * original solo hace más pesadas cada página y cada PDF que lo incrustan.
 *
 * A diferencia de CompresorDocumentoService, aquí NUNCA se pasa a JPEG: un logo
 * necesita su transparencia (va sobre fondos claros, oscuros y fotos). Las
 * imágenes con canal alfa (png/webp) salen como PNG; los JPG se quedan JPG.
 * Los SVG se guardan tal cual: son vectoriales y ya pesan poco.
 *
 * Si algo falla, o el resultado no pesa menos que el original, se guarda el
 * original: la compresión es una optimización, nunca un requisito para subir.
 */
class CompresorLogoService
{
    /** Lado mayor para el ícono cuadrado (`logo`): panel, selector, recibos, web. */
    public const LADO_ICONO = 512;

    /** Lado mayor para los logos de marca (marca de agua de la publicidad). */
    public const LADO_MARCA = 1000;

    private const CALIDAD_JPEG = 88;

    /**
     * Guarda el logo en public/storage/{carpeta} y devuelve la ruta relativa
     * a public/storage (lo que va en la columna, p. ej. "logos/1785..._x.png").
     */
    public function guardar(UploadedFile $file, string $carpeta, string $prefijo, int $ladoMax): string
    {
        $ext    = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
        $nombre = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'logo';
        $base   = time() . $prefijo . $nombre;
        $dir    = public_path('storage/' . $carpeta);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $comprimido = $ext === 'svg' ? null : $this->comprimir($file->getRealPath(), $ext, $ladoMax);

        if ($comprimido === null || strlen($comprimido['bytes']) >= $file->getSize()) {
            $file->move($dir, "{$base}.{$ext}");

            return "{$carpeta}/{$base}.{$ext}";
        }

        file_put_contents("{$dir}/{$base}.{$comprimido['ext']}", $comprimido['bytes']);

        return "{$carpeta}/{$base}.{$comprimido['ext']}";
    }

    /** @return array{bytes: string, ext: string}|null */
    private function comprimir(string $ruta, string $ext, int $ladoMax): ?array
    {
        try {
            $img = @imagecreatefromstring((string) @file_get_contents($ruta));
            if (! $img) {
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $escala = min(1, $ladoMax / max($w, $h));

            if ($escala < 1) {
                $nw = max(1, (int) round($w * $escala));
                $nh = max(1, (int) round($h * $escala));

                $nueva = imagecreatetruecolor($nw, $nh);
                imagealphablending($nueva, false);
                imagesavealpha($nueva, true);
                imagefilledrectangle($nueva, 0, 0, $nw, $nh, imagecolorallocatealpha($nueva, 0, 0, 0, 127));
                imagecopyresampled($nueva, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $nueva;
            }

            ob_start();
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                imagejpeg($img, null, self::CALIDAD_JPEG);
                $salida = 'jpg';
            } else {
                imagesavealpha($img, true);
                imagepng($img, null, 9);
                $salida = 'png';
            }
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            return $bytes === '' ? null : ['bytes' => $bytes, 'ext' => $salida];
        } catch (\Throwable $e) {
            Log::warning('CompresorLogoService: no se pudo comprimir el logo, se guarda el original', [
                'ruta'  => $ruta,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
