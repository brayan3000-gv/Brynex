<?php

namespace App\Console\Commands;

use App\Services\RazonSocialCompartida;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liga a la original las razones sociales que otros aliados ya tenían creadas
 * a mano con el mismo NIT (antes de existir «Habilitar en aliado»).
 *
 * Al ligarlas, los datos de la empresa de la copia se reemplazan por los de la
 * original; la sucursal, la planilla y el estado no se tocan. Sin --aplicar
 * solo muestra qué cambiaría.
 *
 *   php artisan razones:vincular-copias            # Brygar (2) como original
 *   php artisan razones:vincular-copias --aplicar
 */
class RazonesVincularCopias extends Command
{
    protected $signature = 'razones:vincular-copias
                            {--origen=2 : Aliado dueño de las originales}
                            {--aliados= : Solo estos aliados, separados por coma (p. ej. 6,9)}
                            {--aplicar : Guardar (sin esto solo muestra)}';

    protected $description = 'Liga a la original las razones sociales que otros aliados tienen con el mismo NIT';

    public function handle(): int
    {
        $origen = (int) $this->option('origen');
        $originales = DB::table('razones_sociales')
            ->where('aliado_id', $origen)->whereNull('origen_id')
            ->whereRaw('LEN(nit) >= 6')
            ->orderBy('razon_social')
            ->get();

        $ligadas = 0;
        $empresas = 0;

        foreach ($originales as $o) {
            $copias = DB::table('razones_sociales')
                ->where('nit', $o->nit)
                ->whereNotIn('aliado_id', [$origen, 1])
                ->when($this->option('aliados'), fn ($q, $lista) => $q->whereIn('aliado_id', array_map('intval', explode(',', $lista))))
                ->whereNull('origen_id')
                ->get();

            if ($copias->isEmpty()) {
                continue;
            }

            $empresas++;
            $this->line('');
            $this->line("<options=bold>{$o->razon_social}</> · NIT {$o->nit}");

            foreach ($copias as $c) {
                $aliado = DB::table('aliados')->where('id', $c->aliado_id)->value('nombre');
                // Lo vacío en la original no se pasa (ver RazonSocialCompartida::datosEmpresa).
                $cambios = collect(RazonSocialCompartida::EMPRESA)
                    ->filter(fn ($campo) => ! in_array(trim((string) ($o->{$campo} ?? '')), ['', in_array($campo, ['arl_nit', 'caja_nit'], true) ? '0' : ''], true))
                    ->filter(fn ($campo) => trim((string) ($c->{$campo} ?? '')) !== trim((string) ($o->{$campo} ?? '')))
                    ->map(fn ($campo) => "{$campo}: «".mb_strimwidth((string) ($c->{$campo} ?? ''), 0, 30, '…').'» → «'.mb_strimwidth((string) ($o->{$campo} ?? ''), 0, 30, '…').'»');

                $this->line("   #{$c->id} {$aliado} (suc. ".($c->codigo_sucursal ?: '—').') '
                    .($cambios->isEmpty() ? '<fg=gray>sin diferencias</>' : '<fg=yellow>'.$cambios->count().' cambio(s)</>'));
                foreach ($cambios as $linea) {
                    $this->line("        {$linea}");
                }

                if ($this->option('aplicar')) {
                    RazonSocialCompartida::habilitar($o, (int) $c->aliado_id);
                }
                $ligadas++;
            }
        }

        $this->line('');
        $this->info("{$ligadas} copia(s) de {$empresas} empresa(s).");
        $this->line($this->option('aplicar') ? 'Ligadas.' : 'Solo vista previa: --aplicar para ligarlas.');

        return self::SUCCESS;
    }
}
