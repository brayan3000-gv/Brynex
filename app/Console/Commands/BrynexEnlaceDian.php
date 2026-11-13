<?php

namespace App\Console\Commands;

use App\Models\Bitacora;
use App\Models\BrynexRepresentante;
use App\Services\BrynexRepresentanteService;
use App\Services\Correo\CorreoDianRepresentante;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Trae del buzón el enlace de acceso que la DIAN acaba de mandar para entrar
 * al portal de facturación electrónica como representante legal de UNA
 * empresa. Se usa justo después de pulsar «Entrar» en
 * catalogo-vpfe(-hab).dian.gov.co/User/CompanyLogin (ver el skill
 * facturacion-electronica-dian).
 *
 * El enlace da acceso a la empresa durante 60 minutos: cada lectura queda en
 * la bitácora, igual que en la pantalla de Representantes.
 */
class BrynexEnlaceDian extends Command
{
    protected $signature = 'brynex:enlace-dian
                            {cedula : Cédula del representante legal}
                            {nit : NIT de la empresa, sin dígito de verificación}
                            {--desde= : Solo enlaces llegados después de esta hora (p. ej. "14:12" o "2026-11-13 14:12:30")}
                            {--esperar=90 : Segundos esperando a que llegue el correo}';

    protected $description = 'Trae del buzón el enlace de la DIAN (representante legal) de una empresa';

    public function handle(CorreoDianRepresentante $correo): int
    {
        $cedula = BrynexRepresentanteService::normalizarCedula($this->argument('cedula'));
        $nit = preg_replace('/\D/', '', (string) $this->argument('nit'));

        $rep = $cedula ? BrynexRepresentante::where('cedula', $cedula)->first() : null;
        if (! $rep) {
            $this->error("La cédula {$this->argument('cedula')} no está en Representantes.");

            return self::FAILURE;
        }

        // Sin --desde, cualquier enlace de los últimos 60 minutos: si la DIAN
        // mandó dos, se toma el más reciente.
        $desde = $this->option('desde') ? Carbon::parse($this->option('desde')) : null;
        $hasta = now()->addSeconds(max(0, (int) $this->option('esperar')));

        do {
            $enlace = $correo->enlaceDeEmpresa($rep, $nit, $desde);
            if ($enlace || now()->gte($hasta)) {
                break;
            }
            sleep(10);
        } while (true);

        if (! $enlace) {
            $this->warn("No llegó un enlace vigente de la DIAN para {$rep->nombre} / NIT {$nit} en {$correo->buzonDe($rep)['buzon']->cuenta()}.");

            return self::FAILURE;
        }

        Bitacora::registrar(
            'enlace_dian_leido', 'BrynexRepresentante', $rep->id,
            "Se leyó el enlace de acceso de la DIAN de {$rep->nombre} (NIT {$nit}) por consola",
            ['nit' => $nit, 'cuenta' => $enlace['cuenta'], 'fecha_correo' => $enlace['fecha']]
        );

        $this->line("Llegó {$enlace['fecha']} a {$enlace['cuenta']}:");
        $this->line($enlace['enlace']);

        return self::SUCCESS;
    }
}
