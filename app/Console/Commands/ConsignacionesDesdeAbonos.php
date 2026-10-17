<?php

namespace App\Console\Commands;

use App\Models\Abono;
use App\Models\Consignacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea la consignación que les faltó a los abonos consignados a préstamos.
 *
 * Hasta octubre de 2026 un abono pagado por consignación vivía solo en la
 * tabla `abonos`: la plata entraba a la cuenta pero el saldo del banco y el
 * informe no la veían. Desde ahora cada abono nuevo crea la suya
 * (Consignacion::registrarDeAbono); este comando hace lo mismo con los viejos.
 *
 * Solo toma abonos con la cuenta anotada: sin ella no se sabe a qué banco
 * entró la plata. No crea dos veces la del mismo abono.
 *
 * Sube el saldo de los bancos de cada aliado. Si un aliado cuadró a mano su
 * saldo contra el extracto con un saldo inicial posterior al abono, esta
 * entrada ya estaba dentro de ese saldo y la consignación lo descuadra.
 *
 * Sin `--ejecutar` solo reporta.
 */
class ConsignacionesDesdeAbonos extends Command
{
    protected $signature = 'consignaciones:desde-abonos
        {--aliado= : solo este aliado}
        {--desde=2026-07-01 : fecha del abono desde la que se buscan}
        {--ejecutar : crear las consignaciones (sin esto solo reporta)}';

    protected $description = 'Crea la consignación de los abonos consignados a préstamos que no la tienen';

    public function handle(): int
    {
        $abonos = Abono::query()
            ->join('facturas AS f', 'f.id', '=', 'abonos.factura_id')
            ->whereNull('f.deleted_at')
            ->where('abonos.valor_consignado', '>', 0)
            ->whereNotNull('abonos.banco_cuenta_id')
            ->where('abonos.fecha', '>=', $this->option('desde'))
            ->when($this->option('aliado'), fn ($q, $a) => $q->where('f.aliado_id', (int) $a))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('consignaciones AS c')
                ->whereColumn('c.abono_id', 'abonos.id'))
            ->orderBy('abonos.fecha')
            ->get(['abonos.*', 'f.aliado_id AS aliado_factura']);

        if ($abonos->isEmpty()) {
            $this->info('No hay abonos consignados sin consignación.');

            return self::SUCCESS;
        }

        $this->table(
            ['Aliado', 'Cuenta', 'Abonos', 'Valor'],
            $abonos->groupBy(fn ($a) => $a->aliado_factura.'|'.$a->banco_cuenta_id)
                ->map(fn ($g, $k) => [
                    explode('|', $k)[0],
                    explode('|', $k)[1],
                    $g->count(),
                    number_format($g->sum('valor_consignado'), 0, ',', '.'),
                ])->values()->all()
        );
        $this->line('Total: '.$abonos->count().' abonos, $'.number_format($abonos->sum('valor_consignado'), 0, ',', '.'));

        if (! $this->option('ejecutar')) {
            $this->warn('Solo reporte. Corre con --ejecutar para crearlas.');

            return self::SUCCESS;
        }

        $creadas = 0;
        DB::transaction(function () use ($abonos, &$creadas) {
            foreach ($abonos as $abono) {
                if (Consignacion::registrarDeAbono($abono, (int) $abono->aliado_factura)?->wasRecentlyCreated) {
                    $creadas++;
                }
            }
        });

        $this->info("Creadas {$creadas} consignaciones.");

        return self::SUCCESS;
    }
}
