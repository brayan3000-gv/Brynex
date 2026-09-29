<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Services\MiPlanilla\MiPlanillaPortalService;
use Illuminate\Console\Command;

/**
 * Recorrido de solo lectura del robot de Mi Planilla con la cuenta de un
 * independiente: entra, guarda las pantallas de generar y administrar
 * planillas (y sus scripts) y sale. No crea nada. Sirve para mapear el portal
 * antes de automatizar cada paso. Ver MiPlanillaPortalService.
 */
class MiPlanillaDiagnostico extends Command
{
    protected $signature = 'miplanilla:diagnostico
                            {contrato : id del contrato del independiente}
                            {--periodo= : período a revisar en Generar planilla (AAAA-MM), ej. 2026-08}';

    protected $description = 'Entra a Mi Planilla con la clave del independiente y guarda las pantallas, sin crear nada';

    public function handle(): int
    {
        $contrato = Contrato::find((int) $this->argument('contrato'));
        if (! $contrato) {
            $this->error('No existe ese contrato.');

            return self::FAILURE;
        }

        $carpeta = storage_path('app/miplanilla/diagnostico/'.$contrato->cedula.'/'.now()->format('Ymd_His'));
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        try {
            $robot = MiPlanillaPortalService::paraCedula((int) $contrato->aliado_id, (string) $contrato->cedula);
            $periodo = $this->option('periodo') ? $this->option('periodo').'-01' : null;
            $guardados = $robot->diagnostico($carpeta, $periodo);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Entró y salió de Mi Planilla. Pantallas guardadas en {$carpeta}:");
        foreach ($guardados as $archivo => $bytes) {
            $this->line("  {$archivo} (".number_format($bytes / 1024, 1).' KB)');
        }

        return self::SUCCESS;
    }
}
