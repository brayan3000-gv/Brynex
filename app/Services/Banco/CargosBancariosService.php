<?php

namespace App\Services\Banco;

use App\Models\BancoCuenta;
use App\Models\BancoMovimiento;
use App\Models\Gasto;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Convierte los cobros del banco del extracto en un gasto por mes.
 *
 * El 4x1000, la cuota de manejo, el IVA de los pagos y el costo de las
 * transferencias nadie los registraba, así que cada mes quedaban como
 * diferencia del cierre (unos $370.000 al mes en BRYGAR SAS). Al cargar el
 * extracto el conciliador ya los marca como costo del banco
 * (config banco.costos_bancarios); aquí se suman por cuenta y mes y quedan
 * en UN gasto `cargos_bancarios`, que se actualiza si se vuelve a cargar.
 *
 * En las cuentas de razón social el 4x1000 de cada pago de incapacidad ya
 * está registrado aparte (cuatropormil_incapacidad): se resta para no
 * contarlo dos veces.
 *
 * Decidido por el dueño el 19-oct-2026.
 */
class CargosBancariosService
{
    /** @return array<string,int> mes AAAA-MM => valor del gasto */
    public function registrar(BancoCuenta $cuenta, Carbon $desde, Carbon $hasta, ?int $usuarioId = null): array
    {
        $out = [];
        $mes = $desde->copy()->startOfMonth();
        while ($mes->lte($hasta)) {
            $ini = $mes->copy()->startOfMonth();
            $fin = $mes->copy()->endOfMonth();

            $costos = DB::table('banco_movimientos AS m')
                ->where('m.banco_cuenta_id', $cuenta->id)
                ->where('m.tipo', BancoMovimiento::TIPO_DEBITO)
                ->where('m.clasificacion', BancoMovimiento::CLASIFICACION_COSTO_BANCO)
                ->whereBetween('m.fecha', [$ini->toDateString(), $fin->toDateString()])
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('banco_movimiento_gasto AS pg')
                    ->whereColumn('pg.banco_movimiento_id', 'm.id'))
                ->selectRaw('ISNULL(SUM(m.valor), 0) AS t, MAX(m.fecha) AS ultimo')
                ->first();

            $valor = (int) round((float) $costos->t);
            if ($cuenta->incapacidad) {
                $valor -= (int) Gasto::where('aliado_id', $cuenta->aliado_id)
                    ->where('banco_origen_id', $cuenta->id)
                    ->where('tipo', 'cuatropormil_incapacidad')
                    ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
                    ->sum('valor');
            }
            $valor = max(0, $valor);

            $gasto = Gasto::where('aliado_id', $cuenta->aliado_id)
                ->where('banco_origen_id', $cuenta->id)
                ->where('tipo', 'cargos_bancarios')
                ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
                ->first();

            if ($valor > 0) {
                $datos = [
                    'fecha'       => $costos->ultimo ? substr((string) $costos->ultimo, 0, 10) : $fin->toDateString(),
                    'periodo'     => $ini->toDateString(),
                    'descripcion' => 'Cargos bancarios ' . $ini->locale('es')->isoFormat('MMMM YYYY') . ' (4x1000, cuota de manejo, IVA, transferencias)',
                    'pagado_a'    => $cuenta->banco,
                    'forma_pago'  => 'transferencia_bancaria',
                    'valor'       => $valor,
                    'observacion' => 'Sumado del extracto del banco; se actualiza al volver a cargarlo.',
                ];
                $gasto
                    ? $gasto->update($datos)
                    : Gasto::create($datos + [
                        'aliado_id'       => $cuenta->aliado_id,
                        'usuario_id'      => $usuarioId,
                        'cuadre_id'       => null,
                        'tipo'            => 'cargos_bancarios',
                        'banco_origen_id' => $cuenta->id,
                    ]);
                $out[$ini->format('Y-m')] = $valor;
            } elseif ($gasto) {
                $gasto->delete();
            }

            $mes->addMonth();
        }

        return $out;
    }
}
