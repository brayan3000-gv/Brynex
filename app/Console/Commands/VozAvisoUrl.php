<?php

namespace App\Console\Commands;

use App\Services\Voz\LlamadasCobroVoz;
use Illuminate\Console\Command;

/**
 * La dirección a la que Avappi Voz manda los avisos de las llamadas de Brynex,
 * para darla en Avappi: `node src/voz/admin.js reglas --org brynex --aviso-url …`.
 * Cada llamada ya la lleva en `aviso_url`; en Avappi es el respaldo.
 */
class VozAvisoUrl extends Command
{
    protected $signature = 'voz:aviso-url';

    protected $description = 'Muestra la URL de los avisos de Avappi Voz y si falta configurar algo';

    public function handle(): int
    {
        $this->line(LlamadasCobroVoz::urlAviso());
        $this->newLine();
        $this->line('Clave de API (AVAPPI_VOZ_KEY): '.(config('services.avappi_voz.key') ? 'sí' : 'FALTA'));
        $this->line('Secreto de los avisos (AVAPPI_VOZ_SECRETO): '.(config('services.avappi_voz.secreto') ? 'sí' : 'FALTA'));
        $this->line('Agente: '.config('services.avappi_voz.agente'));
        $this->line('Aliados con el botón: '.implode(', ', (array) config('services.avappi_voz.aliados')));

        return self::SUCCESS;
    }
}
