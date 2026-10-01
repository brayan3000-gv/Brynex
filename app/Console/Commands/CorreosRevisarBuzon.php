<?php

namespace App\Console\Commands;

use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\Correo\AgenteBuzonAfiliaciones;
use Illuminate\Console\Command;
use Throwable;

/**
 * Revisa el buzón de afiliaciones del aliado: respuestas de los asesores a los
 * correos enviados desde BryNex, radicados que llegan por correo y otros correos
 * de entidades (ver AgenteBuzonAfiliaciones). Con --empresas lee también los
 * correos de formularios de las empresas que tengan contraseña de aplicación.
 */
class CorreosRevisarBuzon extends Command
{
    protected $signature = 'correos:revisar-buzon
                            {--aliado=2 : Aliado dueño del buzón}
                            {--empresas : Lee también los buzones de las empresas (correo de formularios con contraseña de aplicación)}
                            {--dias=2 : Cuántos días atrás leer}
                            {--simular : Muestra lo que haría sin guardar, cambiar radicados ni avisar}';

    protected $description = 'Lee el buzón de afiliaciones y aplica las respuestas de las entidades a los radicados';

    public function handle(AgenteBuzonAfiliaciones $agente): int
    {
        // Un correo con adjuntos grandes se parsea completo en memoria.
        ini_set('memory_limit', '512M');

        $dias = max(1, (int) $this->option('dias'));
        $simular = (bool) $this->option('simular');

        try {
            $this->mostrar('Buzón del aliado', $agente->revisar((int) $this->option('aliado'), $dias, $simular));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Los buzones de las empresas no deben tumbar el del aliado: cada uno por su lado.
        if ($this->option('empresas')) {
            foreach (DatosAfiliacion::buzonesDeEmpresas() as $cuenta) {
                try {
                    $this->mostrar($cuenta, $agente->revisarCuenta($cuenta, $dias, $simular));
                } catch (Throwable $e) {
                    $this->error("{$cuenta}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function mostrar(string $buzon, array $r): void
    {
        $this->line("— {$buzon}");
        foreach ($r['detalle'] as $linea) {
            $this->line('  '.$linea);
        }
        $this->info(sprintf('%sLeídos %d · nuevos de entidades %d · aplicados %d · por revisar %d · informativos %d · vencidos %d',
            $this->option('simular') ? '[SIMULACIÓN] ' : '', $r['leidos'], $r['nuevos'], $r['aplicados'], $r['por_revisar'], $r['informativos'], $r['vencidos']));
    }
}
