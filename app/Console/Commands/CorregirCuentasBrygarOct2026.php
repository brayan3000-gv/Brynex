<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la cuenta de origen de pagos de BRYGAR que se registraron saliendo
 * de cuentas de razones sociales que en realidad estaban en $0 (oct-2026).
 *
 * Revisado con el dueño el 16-oct-2026 a partir de las tarjetas de bancos del
 * informe financiero:
 *
 *  1. Work at Home: los pagos a los afiliados de las 7 incapacidades por ODI
 *     salieron de la Bancolombia de Brayan García (137), no de la cuenta de
 *     Work at Home (207). A la razón social no le había entrado nada: la ODI
 *     se descuenta en planilla.
 *  2. Center CIT: las dos planillas de independientes del 3-jul también se
 *     pagaron desde la 137, no desde la Bancolombia de Center CIT (142).
 *  3. Work at Home tenía $1.776.528 al 1-jul-2026, fecha desde la que se
 *     calculan los saldos: la #450 (entró el 12-jun) y la #332 entraron antes
 *     y se pagaron después. Se registra como saldo inicial.
 *
 * Se verifica que cada fila siga como estaba al revisarla; si alguna cambió,
 * no se aplica nada.
 */
class CorregirCuentasBrygarOct2026 extends Command
{
    protected $signature = 'bancos:corregir-brygar-oct2026
                            {--ejecutar : Aplica los cambios. Sin este flag solo muestra lo que haría}';

    protected $description = 'Corrige la cuenta de origen de pagos de BRYGAR y el saldo inicial de Work at Home (oct-2026)';

    private const ALIADO = 2;

    private const CUENTA_BRAYAN = 137;

    private const WORK_AT_HOME = 207;

    private const CENTER_CIT = 142;

    /** Gastos del pago al afiliado (neto y 4x1000) de las incapacidades por ODI. */
    private const GASTOS_ODI = [35685, 35686, 35327, 35328, 35323, 35324, 35333, 35334, 35331, 35332, 35687, 35688, 35325, 35326];

    /** Abonos pago_cliente de esas mismas incapacidades. */
    private const ABONOS_ODI = [304, 250, 248, 252, 251, 305, 249];

    private const PLANILLAS_CENTER_CIT = [33228, 33229];

    private const SALDO_INICIAL_WAH = 1776528;

    private const FECHA_CORTE = '2026-07-01';

    public function handle(): int
    {
        $ejecutar = (bool) $this->option('ejecutar');
        $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
        $problemas = [];

        $gastosOdi = DB::table('gastos')->whereIn('id', self::GASTOS_ODI)->get();
        $abonosOdi = DB::table('abonos_incapacidades')->whereIn('id', self::ABONOS_ODI)->get();
        $planillas = DB::table('gastos')->whereIn('id', self::PLANILLAS_CENTER_CIT)->get();

        if ($gastosOdi->count() !== count(self::GASTOS_ODI)) {
            $problemas[] = 'Faltan gastos de los pagos ODI.';
        }
        foreach ($gastosOdi as $g) {
            if ((int) $g->aliado_id !== self::ALIADO || (int) $g->banco_origen_id !== self::WORK_AT_HOME
                || ! in_array($g->tipo, ['pago_incapacidad', 'cuatropormil_incapacidad'], true)) {
                $problemas[] = "Gasto #{$g->id} ya no es un pago ODI desde Work at Home.";
            }
        }
        foreach ($abonosOdi as $a) {
            if ((int) $a->aliado_id !== self::ALIADO || (int) $a->banco_cuenta_id !== self::WORK_AT_HOME || $a->tipo !== 'pago_cliente') {
                $problemas[] = "Abono #{$a->id} ya no es un pago al afiliado desde Work at Home.";
            }
        }
        foreach ($planillas as $p) {
            if ((int) $p->aliado_id !== self::ALIADO || (int) $p->banco_origen_id !== self::CENTER_CIT || $p->tipo !== 'pago_planilla') {
                $problemas[] = "Gasto #{$p->id} ya no es una planilla pagada desde Center CIT.";
            }
        }
        $yaHaySaldo = DB::table('saldos_banco')
            ->where('aliado_id', self::ALIADO)->where('banco_cuenta_id', self::WORK_AT_HOME)
            ->where('tipo', 'saldo_inicial')->where('fecha', '>=', self::FECHA_CORTE)
            ->exists();
        if ($yaHaySaldo) {
            $problemas[] = 'Work at Home ya tiene un saldo inicial desde el 1-jul (¿el comando ya se corrió?).';
        }

        if ($problemas) {
            $this->error('No se aplica nada:');
            foreach ($problemas as $p) {
                $this->warn('  · '.$p);
            }

            return self::FAILURE;
        }

        $this->info($ejecutar ? 'CORRIGIENDO' : 'SIMULACIÓN — no se cambia nada');
        $this->newLine();
        $this->line('1. Pagos ODI de Work at Home → Bancolombia Brayan García: '
            .$gastosOdi->count().' gastos por '.$fmt($gastosOdi->sum('valor')).' y '.$abonosOdi->count().' abonos');
        $this->line('2. Planillas del 3-jul de Center CIT → Bancolombia Brayan García: '
            .$planillas->map(fn ($p) => "#{$p->id} {$fmt($p->valor)}")->implode(', '));
        $this->line('3. Saldo inicial de Work at Home al 1-jul-2026: '.$fmt(self::SALDO_INICIAL_WAH));

        if (! $ejecutar) {
            $this->newLine();
            $this->comment('Simulación: no se cambió nada. Corre con --ejecutar para aplicarlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            $nota = ' [corregido 16-oct-2026: salió de la Bancolombia de Brayan García, no de la cuenta de la razón social]';

            foreach (DB::table('gastos')->whereIn('id', array_merge(self::GASTOS_ODI, self::PLANILLAS_CENTER_CIT))->get() as $g) {
                DB::table('gastos')->where('id', $g->id)->update([
                    'banco_origen_id' => self::CUENTA_BRAYAN,
                    'observacion' => mb_substr((string) $g->observacion.$nota, 0, 500),
                    'updated_at' => now(),
                ]);
            }

            foreach (DB::table('abonos_incapacidades')->whereIn('id', self::ABONOS_ODI)->get() as $a) {
                DB::table('abonos_incapacidades')->where('id', $a->id)->update([
                    'banco_cuenta_id' => self::CUENTA_BRAYAN,
                    'observacion' => mb_substr((string) $a->observacion.$nota, 0, 500),
                    'updated_at' => now(),
                ]);
            }

            DB::table('saldos_banco')->insert([
                'aliado_id' => self::ALIADO,
                'banco_cuenta_id' => self::WORK_AT_HOME,
                'fecha' => self::FECHA_CORTE,
                'tipo' => 'saldo_inicial',
                'descripcion' => 'Saldo al corte del 1-jul-2026: incapacidades #450 y #332, que entraron antes y se pagaron después',
                'usuario_id' => 2,
                'valor' => self::SALDO_INICIAL_WAH,
                'saldo_acumulado' => self::SALDO_INICIAL_WAH,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $saldos = \App\Models\Consignacion::saldosBancosOptimizados(self::ALIADO, [self::WORK_AT_HOME, self::CENTER_CIT]);
        $this->newLine();
        $this->info('Listo. Work at Home queda en '.$fmt($saldos[self::WORK_AT_HOME] ?? 0)
            .' y Center CIT en '.$fmt($saldos[self::CENTER_CIT] ?? 0).'.');

        return self::SUCCESS;
    }
}
