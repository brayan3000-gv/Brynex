<?php

namespace App\Console\Commands;

use App\Services\GarvisService;
use Illuminate\Console\Command;

/**
 * Le manda a Brayan por WhatsApp una nota de voz con el texto que llega por la entrada
 * estándar. GARVIS la usa para contestarle con voz cuando él le habló con una nota de voz.
 *
 *   echo "Ya quedó desplegado Brynex" | php artisan garvis:voz
 */
class GarvisVoz extends Command
{
    protected $signature = 'garvis:voz {--gastos : Desde la línea de gastos y no desde la de Brygar}';

    protected $description = 'Envía a Brayan por WhatsApp una nota de voz de GARVIS (texto por stdin)';

    /** Una nota de voz de GARVIS es un resumen hablado; lo largo va escrito. */
    private const MAX_CARACTERES = 1500;

    public function handle(GarvisService $garvis): int
    {
        $texto = trim((string) stream_get_contents(STDIN));

        if ($texto === '' || ! mb_check_encoding($texto, 'UTF-8')) {
            $this->error('Sin texto.');

            return self::FAILURE;
        }

        if ($garvis->enviarVoz(mb_substr($texto, 0, self::MAX_CARACTERES), $this->option('gastos') ? GarvisService::CANAL_GASTOS : GarvisService::CANAL_BRYGAR)) {
            $this->info('Nota de voz enviada.');

            return self::SUCCESS;
        }

        $this->error('No se pudo mandar la nota de voz. El detalle quedó en el log; la respuesta escrita sale igual.');

        return self::FAILURE;
    }
}
