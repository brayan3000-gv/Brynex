<?php

namespace App\Console\Commands;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\EpsSura\EpsSuraCarteraService;
use App\Services\NuevaEps\NuevaEpsMoraService;
use App\Services\SaludTotal\SaludTotalCarteraService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revisa en los portales de las EPS qué trabajadores quedaron con aportes mal y
 * abre la tarea que corresponda.
 *
 * Va por empresa porque la clave es de la empresa y el reporte que se pide es
 * de la empresa entera: una sola entrada cubre toda su nómina.
 *
 * Cada EPS reporta a su manera. Nueva EPS solo dice quién está en mora y el
 * resto hay que deducirlo cruzando con BryNex; Salud Total ya separa además lo
 * que cobró de más. Por eso cada una tiene su servicio y aquí solo se recorren.
 */
class EpsRevisarMora extends Command
{
    protected $signature = 'eps:revisar-mora
                            {--eps= : Solo esta EPS (NUEVA_EPS o SALUD_TOTAL)}
                            {--nit= : Solo esta empresa}
                            {--corte= : Nueva EPS: primer día del mes de corte (AAAA-MM-01)}
                            {--meses=4 : Salud Total y EPS SURA: cuántos meses hacia atrás, sin contar el actual}
                            {--simular : Consulta el portal pero no crea ni cierra tareas}';

    protected $description = 'Revisa la mora y los aportes mal cobrados en los portales de las EPS, y abre las tareas';

    /**
     * Qué EPS se revisan: su portal en el catálogo (PortalesEntidades) y el
     * patrón con que se buscan las claves que aún no están clasificadas.
     */
    private const EPS = [
        'NUEVA_EPS' => ['nombre' => 'Nueva EPS', 'portal' => 'nueva_eps', 'patron' => '%NUEVA%'],
        'SALUD_TOTAL' => ['nombre' => 'Salud Total', 'portal' => 'salud_total', 'patron' => '%SALUD%TOTAL%'],
        'EPS_SURA' => ['nombre' => 'EPS SURA', 'portal' => 'eps_sura', 'patron' => '%SURA%'],
    ];

    public function handle(NuevaEpsMoraService $nuevaEps, SaludTotalCarteraService $saludTotal, EpsSuraCarteraService $epsSura): int
    {
        $simular = (bool) $this->option('simular');
        $pedida = strtoupper((string) $this->option('eps'));

        if ($pedida && ! isset(self::EPS[$pedida])) {
            $this->error('EPS desconocida. Las que se revisan: '.implode(', ', array_keys(self::EPS)).'.');

            return self::FAILURE;
        }

        $corridas = 0;
        $fallos = 0;

        foreach (self::EPS as $clave => $eps) {
            if ($pedida && $pedida !== $clave) {
                continue;
            }

            $empresas = $this->empresas($eps['portal'], $eps['patron']);

            if (! $empresas) {
                $this->warn("{$eps['nombre']}: ninguna empresa tiene clave en el módulo de claves.");

                continue;
            }

            $this->line('');
            $this->info("── {$eps['nombre']} ──");

            foreach ($empresas as $empresa) {
                $this->line("{$empresa->nit} {$empresa->razon_social}…");
                $corridas++;

                $r = match ($clave) {
                    'NUEVA_EPS' => $nuevaEps->revisar($empresa->nit, $simular, $this->option('corte')),
                    'SALUD_TOTAL' => $saludTotal->revisar($empresa->nit, $simular, (int) $this->option('meses')),
                    'EPS_SURA' => $epsSura->revisar($empresa->nit, $simular, (int) $this->option('meses')),
                };

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

                $this->info('  '.($simular ? 'abriría ' : '').$r['nuevas'].' tarea(s) · '.$r['cerradas'].' cerrada(s)'
                    .($porCausa ? "  [{$porCausa}]" : '  sin novedades'));
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
