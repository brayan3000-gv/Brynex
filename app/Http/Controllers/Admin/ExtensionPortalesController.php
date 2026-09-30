<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Entrega la extensión BryNex Portales para instalarla en Chrome.
 *
 * La extensión vive en el repositorio (`extensiones/brynex-portales`), así que
 * lo que se descarga es siempre la del código desplegado: no hay un zip suelto
 * que se quede viejo. Chrome no instala un .zip de un tirón —la Web Store no
 * cabe aquí, porque la extensión opera portales con la sesión de la persona—,
 * de modo que se descomprime y se carga con «Cargar descomprimida».
 */
class ExtensionPortalesController extends Controller
{
    /** Carpeta de la extensión dentro del repositorio. */
    private const CARPETA = 'extensiones/brynex-portales';

    /**
     * Solo lo que la extensión necesita para correr. Una lista blanca y no el
     * volcado de la carpeta, para no empaquetar por descuido algo que llegue
     * ahí después (notas, respaldos, un .env de pruebas).
     */
    private const ARCHIVOS = [
        'manifest.json',
        'background.js',
        'puente.js',
        'puente-renta.js',
        'renta.js',
        'sanitas-sin-aviso.js',
        'LEEME.md',
    ];

    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Versión publicada, para contrastarla con la que tiene instalada el navegador. */
    public function version(): JsonResponse
    {
        return response()->json([
            'version' => $this->versionPublicada(),
            'archivo' => $this->nombreArchivo(),
        ]);
    }

    /** Arma el zip al vuelo y lo entrega. */
    public function descargar(): BinaryFileResponse
    {
        $destino = tempnam(sys_get_temp_dir(), 'brynex-portales-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de la extensión.');
        }

        foreach ($this->archivosDelZip() as $archivo) {
            $ruta = base_path(self::CARPETA.'/'.$archivo);

            if (! is_file($ruta)) {
                continue;
            }

            // Con la carpeta dentro del zip, al descomprimir queda
            // «brynex-portales/» lista para «Cargar descomprimida».
            $zip->addFile($ruta, 'brynex-portales/'.$archivo);
        }

        if ($zip->numFiles === 0) {
            $zip->close();
            @unlink($destino);
            throw new RuntimeException('No se encontraron los archivos de la extensión en el servidor.');
        }

        $zip->close();

        return response()->download($destino, $this->nombreArchivo())->deleteFileAfterSend();
    }

    /**
     * La lista blanca más todo script que el manifiesto declare: sin ellos Chrome
     * rechaza la extensión entera («No se ha podido cargar JavaScript … para el
     * script», 30-sep-2026, cuando se agregó un content script y faltó sumarlo aquí).
     *
     * @return string[]
     */
    private function archivosDelZip(): array
    {
        $manifest = base_path(self::CARPETA.'/manifest.json');
        $datos = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        $declarados = array_merge(
            [$datos['background']['service_worker'] ?? null],
            ...array_map(fn ($c) => $c['js'] ?? [], $datos['content_scripts'] ?? [])
        );

        $seguros = array_filter($declarados, fn ($f) => is_string($f) && preg_match('/^[\w.-]+\.js$/', $f));

        return array_values(array_unique(array_merge(self::ARCHIVOS, $seguros)));
    }

    private function versionPublicada(): string
    {
        $manifest = base_path(self::CARPETA.'/manifest.json');
        $datos = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        return (string) ($datos['version'] ?? '0.0.0');
    }

    private function nombreArchivo(): string
    {
        return 'brynex-portales-'.$this->versionPublicada().'.zip';
    }
}
