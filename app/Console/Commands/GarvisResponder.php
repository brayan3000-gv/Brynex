<?php

namespace App\Console\Commands;

use App\Services\AlertaOperativaService;
use App\Services\GarvisService;
use Illuminate\Console\Command;

/**
 * Le manda a Brayan por WhatsApp la respuesta de GARVIS, que llega por la
 * entrada estándar. Lo corre /usr/local/sbin/garvis-responder-gh, el comando
 * forzado de la llave con que el workflow del repo garvis entra al servidor.
 *
 *   echo "hola" | php artisan garvis:responder
 */
class GarvisResponder extends Command
{
    protected $signature = 'garvis:responder {--gastos : Desde la línea de gastos y no desde la de Brygar}';

    protected $description = 'Envía a Brayan por WhatsApp la respuesta de GARVIS (texto por stdin)';

    public function handle(GarvisService $garvis, AlertaOperativaService $alertas): int
    {
        $texto = trim((string) stream_get_contents(STDIN));

        if ($texto === '') {
            $this->error('Sin texto.');

            return self::FAILURE;
        }

        // Un envoltorio viejo en el servidor manda cualquier cosa como texto, también una
        // captura. Bytes que no son texto no se le mandan a Brayan como mensaje.
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $this->error('Lo que llegó no es texto.');

            return self::FAILURE;
        }

        if ($garvis->responder($texto, $this->option('gastos') ? GarvisService::CANAL_GASTOS : GarvisService::CANAL_BRYGAR)) {
            $this->info('Respuesta enviada.');

            return self::SUCCESS;
        }

        // Fuera de la ventana de 24 h Meta no deja mandar texto libre; la
        // plantilla sí sale siempre, recortada, y el resto queda en el issue. Sale
        // por el número de Brygar aunque la pregunta haya llegado por la de gastos.
        if ($alertas->enviarA($garvis->numero(), 'GARVIS', $texto)) {
            $this->warn('No salió como texto; se mandó recortada por la plantilla.');

            return self::SUCCESS;
        }

        $this->error('No se pudo enviar. El detalle quedó en el log.');

        return self::FAILURE;
    }
}
