<?php

namespace App\Services\Caja;

use App\Models\BancoCuenta;
use App\Models\CierreMes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La única regla del saldo de una cuenta de banco (17/19-oct-2026).
 *
 * Cada mes arranca con lo VERIFICADO y suma solo los movimientos del mes:
 *
 *   saldo = inicial del mes + consignaciones del mes − salidas del mes
 *
 * El inicial depende de la cuenta:
 *   - cuenta del negocio (cobro y demás): el saldo real que el superadmin
 *     anotó al cierre del mes anterior; si no se anotó, 0 «sin verificar».
 *     Un mes sin cierre ya no arrastra un saldo calculado con errores.
 *   - cuenta de razón social (`incapacidad`): sigue con su saldo, porque la
 *     plata de una incapacidad entra un mes y sale al afiliado otro. Toma el
 *     real anotado si lo hay; si no, el saldo calculado al cierre anterior.
 *   - cuenta personal (`uso_personal`): siempre 0. El extracto mezcla plata
 *     del dueño, así que solo se lleva lo del negocio en el mes.
 *
 * Un saldo inicial anotado a mano en `saldos_banco` dentro del mes manda
 * desde su fecha (es el saldo al empezar ese día).
 *
 * Reemplaza las tres fórmulas que había en Consignacion (saldoBanco,
 * saldosBancosOptimizados, saldoBancoAlFin), que no coincidían entre sí.
 */
class SaldosBanco
{
    /** Antes de esto no hay libro confiable: los meses previos arrancan en 0. */
    const PRIMER_MES = '2026-07-01';

    /**
     * Saldo de cada cuenta al terminar el día $hasta.
     *
     * @return array<int, array{saldo:int, inicial:int, origen:string, entradas:int, salidas:int}>
     *   origen: verificado | arrastre | personal | sin_verificar | manual
     */
    public static function al(int $aliadoId, array $bancoIds, string $hasta): array
    {
        if (empty($bancoIds)) {
            return [];
        }

        $cuentas = BancoCuenta::whereIn('id', $bancoIds)->where('aliado_id', $aliadoId)
            ->get(['id', 'incapacidad', 'uso_personal'])->keyBy('id');

        return self::calcular($aliadoId, $cuentas, Carbon::parse($hasta)->startOfDay(), []);
    }

    /** Saldo de una cuenta hoy. */
    public static function hoy(int $aliadoId, int $bancoId): int
    {
        return self::al($aliadoId, [$bancoId], today()->toDateString())[$bancoId]['saldo'] ?? 0;
    }

    private static function calcular(int $aliadoId, $cuentas, Carbon $hasta, array $memo): array
    {
        $inicio = $hasta->copy()->startOfMonth();
        $ant    = $inicio->copy()->subMonth();

        $cierreAnt = CierreMes::de($aliadoId, $ant->year, $ant->month);
        $reales    = $cierreAnt?->saldos_reales['bancos'] ?? [];

        // Saldo inicial anotado a mano dentro del mes (saldos_banco).
        $manuales = DB::table('saldos_banco')
            ->where('aliado_id', $aliadoId)
            ->whereIn('banco_cuenta_id', $cuentas->keys())
            ->where('tipo', 'saldo_inicial')
            ->whereDate('fecha', '>=', $inicio->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->orderBy('fecha')->orderBy('id')
            ->get(['banco_cuenta_id', 'fecha', 'saldo_acumulado'])
            ->keyBy('banco_cuenta_id');   // queda el último del mes

        // Las cuentas de razón social sin real anotado arrastran el cierre anterior.
        $arrastran = $cuentas->filter(fn ($c) => $c->incapacidad && ! isset($reales[$c->id]) && ! isset($manuales[$c->id]));
        $previos = [];
        if ($arrastran->isNotEmpty() && $inicio->gt(Carbon::parse(self::PRIMER_MES))) {
            $previos = self::calcular($aliadoId, $arrastran, $inicio->copy()->subDay(), $memo);
        }

        $out = [];
        $desdePorCuenta = [];
        foreach ($cuentas as $id => $c) {
            if (isset($manuales[$id])) {
                $inicial = (int) $manuales[$id]->saldo_acumulado;
                $origen  = 'manual';
                $desde   = substr((string) $manuales[$id]->fecha, 0, 10);
            } elseif ($c->uso_personal) {
                [$inicial, $origen, $desde] = [0, 'personal', $inicio->toDateString()];
            } elseif (isset($reales[$id])) {
                [$inicial, $origen, $desde] = [(int) $reales[$id], 'verificado', $inicio->toDateString()];
            } elseif ($c->incapacidad) {
                [$inicial, $origen, $desde] = [(int) ($previos[$id]['saldo'] ?? 0), 'arrastre', $inicio->toDateString()];
            } else {
                [$inicial, $origen, $desde] = [0, 'sin_verificar', $inicio->toDateString()];
            }
            $out[$id] = ['inicial' => $inicial, 'origen' => $origen];
            $desdePorCuenta[$id] = $desde;
        }

        // Movimientos del mes, desde el inicio de cada cuenta hasta $hasta.
        $ids = array_keys($out);
        $minDesde = min($desdePorCuenta);
        $entradas = DB::table('consignaciones')
            ->where('aliado_id', $aliadoId)->whereNull('deleted_at')
            ->whereIn('banco_cuenta_id', $ids)
            ->whereDate('fecha', '>=', $minDesde)->whereDate('fecha', '<=', $hasta->toDateString())
            ->groupBy('banco_cuenta_id', DB::raw('CAST(fecha AS date)'))
            ->selectRaw('banco_cuenta_id AS id, CAST(fecha AS date) AS dia, SUM(CAST(valor AS BIGINT)) AS t')
            ->get();
        $salidas = DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->whereIn('banco_origen_id', $ids)
            // El traslado de efectivo trae la cuenta de destino en banco_origen_id:
            // su entrada ya es la consignación de tipo traslado.
            ->whereNotIn('tipo', ['efectivo_banco', 'ajuste_apertura'])
            ->whereDate('fecha', '>=', $minDesde)->whereDate('fecha', '<=', $hasta->toDateString())
            ->groupBy('banco_origen_id', DB::raw('CAST(fecha AS date)'))
            ->selectRaw('banco_origen_id AS id, CAST(fecha AS date) AS dia, SUM(CAST(valor AS BIGINT)) AS t')
            ->get();

        foreach ($out as $id => &$o) {
            $desde = $desdePorCuenta[$id];
            $o['entradas'] = (int) $entradas->where('id', $id)->filter(fn ($r) => substr((string) $r->dia, 0, 10) >= $desde)->sum('t');
            $o['salidas']  = (int) $salidas->where('id', $id)->filter(fn ($r) => substr((string) $r->dia, 0, 10) >= $desde)->sum('t');
            $o['saldo']    = $o['inicial'] + $o['entradas'] - $o['salidas'];
        }
        unset($o);

        return $out;
    }
}
