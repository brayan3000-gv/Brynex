<?php

namespace App\Console\Commands;

use App\Models\WhatsappConversacion;
use App\Services\ProspectoAliadoService;
use Illuminate\Console\Command;

/**
 * Marca a mano una conversación de WhatsApp como prospecto aliado (asesor,
 * empresa o empleador) con las personas que dijo manejar. Sirve para los que
 * escribieron antes de que la IA los marcara sola. Sin --avisar no le escribe
 * a nadie; solo deja el perfil para el informe.
 *
 *   php artisan whatsapp:perfil-aliado 1160 asesor 40
 *   php artisan whatsapp:perfil-aliado 1123 empresa 70 --avisar
 */
class WhatsappPerfilAliado extends Command
{
    protected $signature = 'whatsapp:perfil-aliado
        {conversacion : Id de la conversación}
        {tipo : asesor, empresa o empleador}
        {personas : Cuántas personas dijo manejar}
        {--avisar : Avisa por WhatsApp a quien lo atiende según el tamaño}';

    protected $description = 'Marca una conversación como prospecto aliado (asesor o empresa) con sus personas';

    public function handle(ProspectoAliadoService $servicio): int
    {
        $conv = WhatsappConversacion::withTrashed()->find((int) $this->argument('conversacion'));
        if (! $conv) {
            $this->error('No existe esa conversación.');

            return self::FAILURE;
        }
        $tipo = strtolower(trim($this->argument('tipo')));
        if (! array_key_exists($tipo, ProspectoAliadoService::TIPOS)) {
            $this->error('El tipo debe ser asesor, empresa o empleador.');

            return self::FAILURE;
        }

        $r = $servicio->aplicar($conv, $tipo, (int) $this->argument('personas'), avisar: (bool) $this->option('avisar'));
        $this->info("#{$conv->id} ".$conv->nombreMostrar().' → '.ProspectoAliadoService::TIPOS[$tipo].', '.(int) $this->argument('personas').' personas · '.$r['sugerencia']
            .($this->option('avisar') && $r['responsable'] ? ' · avisado a '.$r['responsable']['nombre'] : ''));

        return self::SUCCESS;
    }
}
