<?php

namespace App\Console\Commands;

use App\Models\OperadorPlanillaApi;
use App\Services\OdiDescuentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revisa las planillas que ya tienen totales del operador y registra lo que
 * descontaron de las ODI de la ARL.
 *
 * `planillas:sincronizar-totales` ya lo hace con cada planilla nueva; este
 * comando sirve para las que se trajeron antes, o después de cargar una ODI
 * vieja. No abre sesión con el operador: trabaja con los totales guardados.
 *
 * Una diferencia sin ODI que la explique se reporta y no se toca: puede ser una
 * ODI que nunca se registró en BryNex, y no se inventan órdenes.
 */
class OdiRevisarPlanillas extends Command
{
    protected $signature = 'odi:revisar-planillas
                            {--aliado= : Solo este aliado}
                            {--desde= : Planillas liquidadas desde esta fecha (YYYY-MM-DD)}
                            {--ejecutar : Registra los descuentos. Sin este flag solo muestra lo que haría}';

    protected $description = 'Detecta en los totales del operador lo que cada planilla le descontó a las ODI de la ARL';

    public function handle(OdiDescuentoService $servicio): int
    {
        $ejecutar = (bool) $this->option('ejecutar');

        $planillas = OperadorPlanillaApi::query()
            ->whereNull('deleted_at')
            ->whereNotNull('numero_planilla')
            ->whereNotNull('total_administradoras')
            ->where('valor_total', '>', 0)
            ->when($this->option('aliado'), fn ($q, $a) => $q->where('aliado_id', (int) $a))
            ->when($this->option('desde'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            // De la más vieja a la más nueva: así se consumen las ODI en orden.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->info(($ejecutar ? 'REGISTRANDO' : 'SIMULACIÓN — no se cambia nada')." · {$planillas->count()} planilla(s) con totales");
        $this->newLine();

        $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
        $rs = DB::table('razones_sociales')->whereIn('id', $planillas->pluck('razon_social_id')->unique())->pluck('razon_social', 'id');

        $filas = [];
        $totalAplicado = $totalSinOdi = 0;

        foreach ($planillas as $p) {
            $r = $servicio->aplicar($p, $ejecutar);
            $a = $r['analisis'];
            if (! $a || $a['descuento'] <= 0) {
                continue;
            }

            $odis = collect($r['detalle'])->map(fn ($d) => "{$d['numero']} ({$fmt($d['valor'])})")->implode(', ');
            $filas[] = [
                mb_substr($rs[$p->razon_social_id] ?? '?', 0, 22),
                $p->numero_planilla,
                substr((string) $p->created_at, 0, 10),
                $fmt($a['esperada']),
                $fmt($a['cobrada']),
                $fmt($a['descuento']),
                $r['ya_aplicada'] ? 'ya registrada' : ($odis ?: '—'),
                $r['sin_odi'] > 0 ? $fmt($r['sin_odi']) : '',
            ];
            $totalAplicado += $r['aplicado'];
            $totalSinOdi += $r['sin_odi'];
        }

        if (! $filas) {
            $this->info('Ninguna planilla trae la ARL por debajo de lo esperado.');

            return self::SUCCESS;
        }

        $this->table(
            ['Razón social', 'Planilla', 'Liquidada', 'ARL esperada', 'ARL cobrada', 'Descuento', 'Se aplica a ODI', 'Sin ODI'],
            $filas
        );

        $this->line('A ODI registradas: '.$fmt($totalAplicado));
        if ($totalSinOdi > 0) {
            $this->warn('Sin ODI que lo explique: '.$fmt($totalSinOdi).' — revisar si hay órdenes que no se registraron en BryNex.');
        }

        $this->newLine();
        $ejecutar
            ? $this->info('Listo.')
            : $this->comment('Simulación: no se cambió nada. Corre con --ejecutar para registrarlo.');

        return self::SUCCESS;
    }
}
