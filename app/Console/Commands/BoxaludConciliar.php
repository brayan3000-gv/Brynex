<?php

namespace App\Console\Commands;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\Boxalud\BoxaludCruceService;
use App\Services\TareaAutomaticaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cruza con BryNex lo que las EPS de Boxalud tienen afiliado.
 *
 * Va por empresa porque la clave es de la empresa y el portal entrega la
 * nómina entera de una vez: una sola entrada cubre a todos sus trabajadores.
 *
 * Coosalud y Emssanar son el mismo software (config/boxalud.php), así que las
 * dos se recorren igual; lo único distinto es el dominio y de qué códigos de
 * EPS son sus contratos.
 */
class BoxaludConciliar extends Command
{
    protected $signature = 'boxalud:conciliar
                            {--eps= : Solo esta EPS (coosalud o emssanar)}
                            {--nit= : Solo esta empresa}
                            {--simular : Consulta el portal pero no toca radicados ni tareas}';

    protected $description = 'Cruza los afiliados de Coosalud y Emssanar con los contratos y radicados de BryNex';

    public function handle(BoxaludCruceService $cruce): int
    {
        $simular = (bool) $this->option('simular');
        $pedida = strtolower((string) $this->option('eps'));
        $configuradas = array_keys(config('boxalud', []));

        if ($pedida && ! in_array($pedida, $configuradas, true)) {
            $this->error('EPS desconocida. Las configuradas: '.implode(', ', $configuradas).'.');

            return self::FAILURE;
        }

        $corridas = 0;
        $fallos = 0;

        foreach ($configuradas as $eps) {
            if ($pedida && $pedida !== $eps) {
                continue;
            }

            $conf = config("boxalud.{$eps}");
            $empresas = $this->empresas($eps, $conf['clave_entidad']);

            if (! $empresas) {
                $this->warn("{$conf['nombre']}: ninguna empresa tiene clave en el módulo de claves.");

                continue;
            }

            $this->line('');
            $this->info("── {$conf['nombre']} ──");

            foreach ($empresas as $empresa) {
                $this->line("{$empresa->nit} {$empresa->razon_social}…");
                $corridas++;

                $r = $cruce->revisar($eps, $empresa->nit, $simular, TareaAutomaticaService::USUARIO_SISTEMA);

                if (! ($r['ok'] ?? false)) {
                    $fallos++;
                    $this->error('  '.($r['error'] ?? 'sin detalle'));

                    continue;
                }

                $porCausa = collect($r['detalle'] ?? [])
                    ->filter(fn ($d) => isset($d['causa']))
                    ->countBy('causa')
                    ->map(fn ($n, $causa) => "{$causa}: {$n}")
                    ->implode(' · ');

                $this->info('  '.$r['afiliados'].' afiliado(s) en el portal · '
                    .($simular ? 'abriría ' : '').$r['nuevas'].' tarea(s) · '.$r['cerradas'].' cerrada(s)'
                    .($porCausa ? "  [{$porCausa}]" : '  sin novedades'));

                // El vocabulario de estados del portal solo se conoce mirándolo,
                // y de él depende qué radicado se cierra: conviene verlo.
                if ($this->getOutput()->isVerbose() && ($r['estados'] ?? [])) {
                    $this->line('  estados: '.collect($r['estados'])->map(fn ($n, $e) => "{$e} ({$n})")->implode(', '));
                }
            }
        }

        return $fallos && $fallos === $corridas ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Las empresas con clave de esa EPS, una vez cada una.
     *
     * La misma razón social existe en varios aliados y la clave es de la
     * empresa: entrar una vez por aliado sería entrar varias veces al mismo
     * portal con el mismo usuario.
     *
     * @return array<int, object>
     */
    private function empresas(string $portal, string $patron): array
    {
        $consulta = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, $portal, 'EPS', $patron))
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
            ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
            // Los NIT de verdad tienen nueve dígitos: en el llavero hay filas
            // viejas apuntando a razones sociales que no lo son.
            ->whereRaw('LEN(rs.nit) >= 9');

        if ($nit = $this->option('nit')) {
            $consulta->where('rs.nit', preg_replace('/\D/', '', $nit));
        }

        return $consulta->distinct()->orderBy('rs.nit')->get(['rs.nit', 'rs.razon_social'])
            ->unique('nit')->values()->all();
    }
}
