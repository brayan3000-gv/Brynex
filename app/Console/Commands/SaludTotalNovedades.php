<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\Radicado;
use App\Services\SaludTotal\SaludTotalNovedadService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Radica en lote las novedades de inicio laboral pendientes en Salud Total.
 *
 * El botón del modal hace una sola persona; esto hace una empresa entera. Va
 * agrupado por NIT y reusando la sesión, que el login es lo más lento del
 * trámite: con eso, cada persona cuesta unos pocos segundos.
 *
 * Solo radica lo que el servicio ya sabe hacer —dependientes, con fecha de
 * ingreso dentro del plazo del portal— y lo demás lo informa sin tocarlo.
 */
class SaludTotalNovedades extends Command
{
    protected $signature = 'eps:salud-total-novedades
                            {--aliado=* : Solo estos aliados (por omisión todos: la clave es de la empresa, no del aliado)}
                            {--nit=* : Solo estas empresas}
                            {--contrato=* : Solo estos contratos}
                            {--limite= : Cuántas personas como máximo}
                            {--usuario=2 : Usuario de BryNex al que se atribuyen los cambios}
                            {--simular : Revisa y consulta, pero no radica}';

    protected $description = 'Radica las novedades de inicio laboral pendientes de Salud Total, empresa por empresa';

    public function handle(SaludTotalNovedadService $servicio): int
    {
        $aliados = collect($this->option('aliado'))->map('intval')->filter()->all();
        $usuarioId = (int) $this->option('usuario');
        $simular = (bool) $this->option('simular');
        $nits = collect($this->option('nit'))->map(fn ($n) => preg_replace('/\D/', '', $n))->filter()->all();
        $contratos = collect($this->option('contrato'))->map('intval')->filter()->all();

        // Sin --aliado van todos: la clave del portal es de la razón social, y
        // una misma empresa suele tener gente en varios aliados (Global Contact
        // tiene en BRYGAR y en SS Faga). Partir el lote por aliado obligaría a
        // repetir el login de la misma empresa en cada corrida.
        $pendientes = Contrato::query()
            ->when($aliados, fn ($q) => $q->whereIn('aliado_id', $aliados))
            ->where('estado', 'vigente')
            ->whereHas('eps', fn ($e) => $e->where('codigo', SaludTotalNovedadService::CODIGO_EPS))
            ->whereHas('razonSocial', fn ($rs) => $rs->where('es_independiente', false)
                ->when($nits, fn ($q) => $q->whereIn('nit', $nits)))
            ->when($contratos, fn ($q) => $q->whereIn('id', $contratos))
            ->whereHas('radicados', fn ($r) => $r->where('tipo', Radicado::TIPO_EPS)
                ->whereIn('estado', [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_ERROR]))
            ->with(['cliente', 'razonSocial', 'eps', 'plan', 'tipoModalidad'])
            ->orderBy('razon_social_id')->orderBy('id')
            ->get();

        if ($this->option('limite')) {
            $pendientes = $pendientes->take((int) $this->option('limite'));
        }

        if ($pendientes->isEmpty()) {
            $this->info('No hay novedades de Salud Total por radicar.');

            return self::SUCCESS;
        }

        $porEmpresa = $pendientes->groupBy(fn (Contrato $c) => preg_replace('/\D/', '', (string) $c->razonSocial->nit));
        $this->info($pendientes->count().' personas en '.$porEmpresa->count().' empresas'.($simular ? ' (simulación)' : '').'.');

        $cuenta = ['radicadas' => 0, 'ya_estaban' => 0, 'saltadas' => 0, 'fallidas' => 0];
        $arranque = microtime(true);

        foreach ($porEmpresa as $nit => $grupo) {
            $empresa = $grupo->first()->razonSocial->razon_social;
            $this->newLine();
            $this->line("<fg=cyan>{$empresa}</> (NIT {$nit}) — {$grupo->count()} personas");

            // Si la empresa no tiene clave o el portal la rechaza, no se insiste
            // persona por persona: es la misma respuesta para todas.
            try {
                $servicio->sesion((string) $nit);
            } catch (Throwable $e) {
                $cuenta['saltadas'] += $grupo->count();
                $this->warn('  '.$e->getMessage().' Se salta la empresa.');

                continue;
            }

            foreach ($grupo as $contrato) {
                $quien = str_pad(trim(($contrato->cliente?->primer_nombre ?? '').' '.($contrato->cliente?->primer_apellido ?? '')), 28);
                $desde = microtime(true);

                $prep = $servicio->preparar($contrato);

                if ($prep['problemas']) {
                    $cuenta['saltadas']++;
                    $this->line("  <fg=yellow>↷</> {$quien} ".implode(' ', $prep['problemas']));

                    continue;
                }

                try {
                    if ($simular) {
                        $r = $servicio->consultar($contrato);
                        $this->line("  <fg=blue>?</> {$quien} ".($r['nombre_eps'] ?: 'no está en Salud Total')
                            .($r['novedad'] ? ' · ya tiene formulario '.$r['novedad']['numero'] : '')
                            .($r['activo_empresa'] ? ' · '.$r['activo_empresa']['estado'].' con la empresa' : '')
                            .' ('.round(microtime(true) - $desde, 1).'s)');

                        continue;
                    }

                    $r = $servicio->registrar($contrato, $usuarioId);
                    $segundos = round(microtime(true) - $desde, 1);

                    if ($r['ya_existia'] ?? false) {
                        $cuenta['ya_estaban']++;
                        $this->line("  <fg=blue>=</> {$quien} ya tenía formulario {$r['radicado']} ({$r['estado_eps']}) — {$segundos}s");
                    } elseif ($r['ya_activo'] ?? false) {
                        $cuenta['ya_estaban']++;
                        $this->line("  <fg=blue>=</> {$quien} ya activo con la empresa desde {$r['desde']}: radicado en OK — {$segundos}s");
                    } else {
                        $cuenta['radicadas']++;
                        $this->line("  <fg=green>✓</> {$quien} formulario {$r['radicado']}".(($r['pdf'] ?? false) ? ' con PDF' : ' SIN PDF')." — {$segundos}s");
                    }
                } catch (Throwable $e) {
                    $cuenta['fallidas']++;
                    $this->line("  <fg=red>✗</> {$quien} ".mb_substr($e->getMessage(), 0, 160));
                }
            }
        }

        $total = round(microtime(true) - $arranque, 1);
        $hechas = max(1, $cuenta['radicadas'] + $cuenta['ya_estaban'] + $cuenta['fallidas']);
        $this->newLine();
        $this->info("Radicadas: {$cuenta['radicadas']} · ya estaban: {$cuenta['ya_estaban']} · falladas: {$cuenta['fallidas']} · saltadas: {$cuenta['saltadas']}"
            ." · {$total}s (".round($total / $hechas, 1).'s por persona)');

        return self::SUCCESS;
    }
}
