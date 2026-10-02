<?php

namespace App\Console\Commands;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\NuevaEps\NuevaEpsRetirosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compara los retiros de BryNex con los que tiene registrados la EPS.
 *
 * Es la vigilancia previa a la mora: un retiro que no le llegó a la EPS no
 * avisa, se cobra en silencio mes a mes. Aquí se ve el primer mes, cuando
 * todavía no ha costado nada.
 */
class EpsConciliarRetiros extends Command
{
    protected $signature = 'eps:conciliar-retiros
                            {--nit= : Solo esta empresa}
                            {--simular : Consulta el portal pero no crea ni cierra tareas}';

    protected $description = 'Cruza los retiros de BryNex con los de Nueva EPS y abre tarea donde no coincidan';

    public function handle(NuevaEpsRetirosService $retiros): int
    {
        $empresas = $this->empresas();

        if (! $empresas) {
            $this->warn('Ninguna empresa tiene clave de Nueva EPS en el módulo de claves.');

            return self::SUCCESS;
        }

        $simular = (bool) $this->option('simular');
        $fallos = 0;

        foreach ($empresas as $empresa) {
            $this->line("{$empresa->nit} {$empresa->razon_social}…");

            $r = $retiros->revisar($empresa->nit, $simular);

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

            $this->info('  '.$r['cotizantes'].' cotizante(s) en la EPS · '.($simular ? 'abriría ' : '').$r['nuevas'].' tarea(s) · '
                .$r['cerradas'].' cerrada(s)'.($porCausa ? "  [{$porCausa}]" : '  todo cuadra'));
        }

        return $fallos && $fallos === count($empresas) ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, object> */
    private function empresas(): array
    {
        $consulta = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, 'nueva_eps', 'EPS', '%NUEVA%'))
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
            ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
            ->whereRaw('LEN(rs.nit) >= 9');

        if ($nit = $this->option('nit')) {
            $consulta->where('rs.nit', preg_replace('/\D/', '', $nit));
        }

        return $consulta->distinct()->orderBy('rs.nit')->get(['rs.nit', 'rs.razon_social'])
            ->unique('nit')->values()->all();
    }
}
