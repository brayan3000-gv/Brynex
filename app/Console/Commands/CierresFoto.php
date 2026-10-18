<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\InformeController;
use App\Services\Caja\CierreMes;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Guarda la foto del cierre de un mes (ver App\Services\Caja\CierreMes).
 *
 * Corre sola el día 1 por el mes que acaba de terminar, en cada aliado que
 * tuvo facturas ese mes. El primer cierre de un aliado es su apertura: la
 * diferencia que traiga no se busca, se absorbe una sola vez.
 *
 * El informe se arma como el usuario 2 (el de los comandos), en el aliado
 * de cada vuelta: así la foto tiene exactamente las cifras del estado
 * financiero.
 */
class CierresFoto extends Command
{
    protected $signature = 'cierres:foto
        {--aliado=* : solo estos aliados}
        {--mes= : AAAA-MM; por defecto el mes anterior}
        {--rehacer : reemplazar la foto si ya existe}';

    protected $description = 'Guarda la foto del cierre de mes de cada aliado';

    /** Usuario con que se atribuyen los cambios hechos por comando. */
    private const USUARIO_COMANDOS = 2;

    public function handle(): int
    {
        $mes = $this->option('mes')
            ? Carbon::createFromFormat('Y-m-d', $this->option('mes') . '-01')
            : now('America/Bogota')->startOfMonth()->subMonth();
        if ($mes->copy()->endOfMonth()->isFuture()) {
            $this->error('Ese mes todavía no termina.');

            return self::FAILURE;
        }

        $aliados = $this->option('aliado')
            ? array_map('intval', $this->option('aliado'))
            : DB::table('facturas')->whereNull('deleted_at')
                ->whereYear('fecha_pago', $mes->year)->whereMonth('fecha_pago', $mes->month)
                ->distinct()->pluck('aliado_id')->map(fn ($a) => (int) $a)->all();

        Auth::loginUsingId(self::USUARIO_COMANDOS);
        $ctrl = app(InformeController::class);

        foreach ($aliados as $aid) {
            session(['aliado_id_activo' => $aid]);
            try {
                $antes = \App\Models\CierreMes::de($aid, $mes->year, $mes->month)?->foto_at;
                $vivo  = InformeController::cierreVivoDe($ctrl, $mes->year, $mes->month);
                $fila  = CierreMes::guardarFoto($aid, $mes->year, $mes->month, $vivo, (bool) $this->option('rehacer'));
                $this->line(sprintf('aliado %d %s: %s%s', $aid, $mes->format('Y-m'),
                    $antes && $fila->foto_at->equalTo($antes) ? 'ya tenía foto' : 'foto guardada',
                    $fila->es_apertura ? ' (apertura)' : ''));
            } catch (\Throwable $e) {
                $this->error("aliado {$aid}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
