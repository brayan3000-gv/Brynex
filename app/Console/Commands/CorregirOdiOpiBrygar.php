<?php

namespace App\Console\Commands;

use App\Models\OdiArl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Corrige las incapacidades de BRYGAR que llegaron por orden de la ARL (ODI u
 * OPI) y se registraron como si la plata hubiera entrado a un banco.
 *
 * Las tarjetas de bancos del informe financiero (oct-2026) mostraban a Work at
 * Home y Megatransportes en negativo: el pago a los afiliados salía de la
 * cuenta de la razón social, pero la entrada no figuraba en ningún banco porque
 * ninguna lo había hecho. Revisado con el dueño el 16-oct-2026:
 *
 *  1. Megatransportes (#4581, #4585-#4588, #4610): era una OPI, no una ODI. Se
 *     cobró en efectivo y a los afiliados se les pagó en efectivo. Las entradas
 *     pasan a «OPI en efectivo», los pagos dejan de salir del banco y se quita
 *     el 4x1000, que en efectivo no existe (queda como sobrante en la caja de
 *     la incapacidad).
 *  2. #458 (Megatransportes, ODI 1816222 de junio): la ARL todavía no la ha
 *     descontado. Queda como ODI con saldo, y el pago al afiliado, que también
 *     fue en efectivo, pasa a efectivo sin 4x1000.
 *  3. Work at Home: cuatro ODI de ARL SURA (PDF «Impresión ODI definitivo»)
 *     con sus incapacidades. De la 1849379 ya se descontaron $90.000 en la
 *     planilla 88867732 (ARL esperada $94.700, pagada $4.700).
 *
 * Todo va en una transacción. Antes de tocar nada se verifica que cada fila
 * siga como estaba al revisarla; si alguna cambió, no se aplica nada.
 */
class CorregirOdiOpiBrygar extends Command
{
    protected $signature = 'incapacidades:corregir-odi-opi-brygar
                            {--ejecutar : Aplica los cambios. Sin este flag solo muestra lo que haría}';

    protected $description = 'Reclasifica las ODI/OPI de BRYGAR que se registraron como entradas sin banco (oct-2026)';

    private const ALIADO = 2;

    private const ARL_SURA = 3;

    private const RS_MEGATRANSPORTES = 31;

    private const RS_WORK_AT_HOME = 34;

    private const BANCO_MEGATRANSPORTES = 240;

    /** Entradas de la OPI de Megatransportes: abono => incapacidad. */
    private const OPI_ENTRADAS = [254 => 4585, 255 => 4586, 256 => 4587, 257 => 4588, 258 => 4581, 259 => 4610];

    /** Pagos en efectivo registrados como transferencia: incapacidad => [gasto pago, gasto 4x1000, abono pago_cliente]. */
    private const PAGOS_EFECTIVO = [
        4585 => [35634, 35635, 297],
        4586 => [35636, 35637, 298],
        4587 => [35638, 35639, 299],
        4588 => [35640, 35641, 300],
        458 => [35642, 35643, 301],
        4581 => [35644, 35645, 302],
        4610 => [35646, 35647, 303],
    ];

    /**
     * ODI a crear. 'entradas' es abono => incapacidad; 'descuentos' lo que ya
     * se descontó en planillas pagadas.
     */
    private const ODIS = [
        [
            'numero' => '1816222', 'rs' => self::RS_MEGATRANSPORTES, 'fecha' => '2026-06-02',
            'incapacidades' => 1750000, 'otros_riesgos' => 0,
            'entradas' => [141 => 458],
            'descuentos' => [],
            'obs' => 'Sin el PDF de la ODI: falta el valor de cotizaciones a otros riesgos.',
        ],
        [
            'numero' => '1849379', 'rs' => self::RS_WORK_AT_HOME, 'fecha' => '2026-08-26',
            'incapacidades' => 350181, 'otros_riesgos' => 71787,
            'entradas' => [217 => 4573, 218 => 4574],
            'descuentos' => [['planilla' => '88867732', 'valor' => 90000]],
            'obs' => null,
        ],
        [
            'numero' => '1850553', 'rs' => self::RS_WORK_AT_HOME, 'fecha' => '2026-08-26',
            'incapacidades' => 525271, 'otros_riesgos' => 107681,
            'entradas' => [222 => 4584, 223 => 4580],
            'descuentos' => [],
            'obs' => null,
        ],
        [
            'numero' => '1853599', 'rs' => self::RS_WORK_AT_HOME, 'fecha' => '2026-09-04',
            'incapacidades' => 641998, 'otros_riesgos' => 131610,
            'entradas' => [221 => 4618, 219 => 4619],
            'descuentos' => [],
            'obs' => null,
        ],
        [
            'numero' => '1853949', 'rs' => self::RS_WORK_AT_HOME, 'fecha' => '2026-09-04',
            'incapacidades' => 408544, 'otros_riesgos' => 83752,
            'entradas' => [220 => 4571],
            'descuentos' => [],
            'obs' => null,
        ],
    ];

    /** @var string[] */
    private array $problemas = [];

    /** La simulación corre aunque la migración no se haya aplicado todavía. */
    private bool $hayTablas = true;

    public function handle(): int
    {
        $ejecutar = (bool) $this->option('ejecutar');

        $this->hayTablas = Schema::hasTable('odis_arl') && Schema::hasColumn('abonos_incapacidades', 'forma_recepcion');

        if (! $this->hayTablas) {
            if ($ejecutar) {
                $this->error('Faltan las tablas de ODI: corre primero php artisan migrate.');

                return self::FAILURE;
            }
            $this->warn('La migración de las ODI aún no corre: la simulación no revisa si ya se aplicó.');
        }

        $this->info($ejecutar ? 'CORRIGIENDO' : 'SIMULACIÓN — no se cambia nada');
        $this->newLine();

        $this->verificar();

        if ($this->problemas) {
            $this->error('Hay filas que ya no están como al revisarlas. No se aplica nada:');
            foreach ($this->problemas as $p) {
                $this->warn('  · '.$p);
            }

            return self::FAILURE;
        }

        $this->mostrarPlan();

        if (! $ejecutar) {
            $this->newLine();
            $this->comment('Simulación: no se cambió nada. Corre con --ejecutar para aplicarlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            $this->corregirOpi();
            $this->corregirPagosEfectivo();
            $this->crearOdis();
        });

        $this->newLine();
        $this->info('Listo.');

        return self::SUCCESS;
    }

    // ── Verificación ─────────────────────────────────────────────────────────

    private function verificar(): void
    {
        foreach (self::OPI_ENTRADAS as $abonoId => $incId) {
            $this->verificarEntrada($abonoId, $incId);
        }

        foreach (self::PAGOS_EFECTIVO as $incId => [$gastoPago, $gasto4x1000, $abonoPago]) {
            $g = DB::table('gastos')->find($gastoPago);
            if (! $g || (int) $g->aliado_id !== self::ALIADO || $g->tipo !== 'pago_incapacidad' || (int) $g->incapacidad_id !== $incId) {
                $this->problemas[] = "Gasto #{$gastoPago} no es el pago de la incapacidad #{$incId}.";
            } elseif ($g->forma_pago !== 'efectivo' && (int) $g->banco_origen_id !== self::BANCO_MEGATRANSPORTES) {
                $this->problemas[] = "Gasto #{$gastoPago}: sale del banco {$g->banco_origen_id}, se esperaba ".self::BANCO_MEGATRANSPORTES.'.';
            }

            $x = DB::table('gastos')->find($gasto4x1000);
            if ($x && ((int) $x->aliado_id !== self::ALIADO || $x->tipo !== 'cuatropormil_incapacidad' || (int) $x->incapacidad_id !== $incId)) {
                $this->problemas[] = "Gasto #{$gasto4x1000} no es el 4x1000 de la incapacidad #{$incId}.";
            }

            $a = DB::table('abonos_incapacidades')->find($abonoPago);
            if (! $a || (int) $a->aliado_id !== self::ALIADO || $a->tipo !== 'pago_cliente' || (int) $a->incapacidad_id !== $incId) {
                $this->problemas[] = "Abono #{$abonoPago} no es el pago al afiliado de la incapacidad #{$incId}.";
            }
        }

        foreach (self::ODIS as $o) {
            $suma = 0;
            foreach ($o['entradas'] as $abonoId => $incId) {
                $a = $this->verificarEntrada($abonoId, $incId);
                $suma += $a ? (float) $a->valor : 0;
            }
            if (abs($suma - $o['incapacidades']) > 1) {
                $this->problemas[] = "ODI {$o['numero']}: las entradas suman {$suma} y la orden dice {$o['incapacidades']}.";
            }

            $existe = $this->hayTablas && DB::table('odis_arl')
                ->where('aliado_id', self::ALIADO)->where('razon_social_id', $o['rs'])->where('numero', $o['numero'])
                ->exists();
            if ($existe) {
                $this->problemas[] = "La ODI {$o['numero']} ya está creada (¿el comando ya se corrió?).";
            }

            foreach ($o['descuentos'] as $d) {
                if (! DB::table('operador_planillas_api')->where('aliado_id', self::ALIADO)->where('numero_planilla', $d['planilla'])->exists()) {
                    $this->problemas[] = "ODI {$o['numero']}: no encuentro la planilla {$d['planilla']}.";
                }
            }
        }
    }

    private function verificarEntrada(int $abonoId, int $incId): ?object
    {
        $a = DB::table('abonos_incapacidades')->find($abonoId);

        if (! $a || (int) $a->aliado_id !== self::ALIADO || $a->tipo !== 'entrada_incapacidad' || (int) $a->incapacidad_id !== $incId) {
            $this->problemas[] = "Abono #{$abonoId} no es la entrada de la incapacidad #{$incId}.";

            return null;
        }
        if ($a->banco_cuenta_id || $a->consignacion_id) {
            $this->problemas[] = "Abono #{$abonoId} (inc. #{$incId}) ya tiene banco o consignación: revisar a mano.";
        }
        if ($this->hayTablas && $a->forma_recepcion) {
            $this->problemas[] = "Abono #{$abonoId} (inc. #{$incId}) ya tiene forma de recepción '{$a->forma_recepcion}'.";
        }

        return $a;
    }

    // ── Plan ─────────────────────────────────────────────────────────────────

    private function mostrarPlan(): void
    {
        $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');

        $this->line('<comment>1. OPI de Megatransportes cobrada en efectivo</comment>');
        $filas = [];
        foreach (self::OPI_ENTRADAS as $abonoId => $incId) {
            $filas[] = ["#{$incId}", "abono {$abonoId}", $fmt(DB::table('abonos_incapacidades')->where('id', $abonoId)->value('valor')), 'ODI sin banco → OPI en efectivo'];
        }
        $this->table(['Incapacidad', 'Registro', 'Valor', 'Cambio'], $filas);

        $this->line('<comment>2. Pagos a afiliados en efectivo (estaban como transferencia del banco '.self::BANCO_MEGATRANSPORTES.')</comment>');
        $filas = [];
        $total4x1000 = 0;
        foreach (self::PAGOS_EFECTIVO as $incId => [$gastoPago, $gasto4x1000]) {
            $x = DB::table('gastos')->find($gasto4x1000);
            $total4x1000 += $x ? (float) $x->valor : 0;
            $filas[] = [
                "#{$incId}",
                $fmt(DB::table('gastos')->where('id', $gastoPago)->value('valor')),
                $x ? 'se quita '.$fmt($x->valor) : 'ya no está',
            ];
        }
        $this->table(['Incapacidad', 'Pago (pasa a efectivo)', '4x1000'], $filas);
        $this->line('   4x1000 que se quita: '.$fmt($total4x1000).' (queda como sobrante en la caja de cada incapacidad)');
        $this->newLine();

        $this->line('<comment>3. ODI con saldo por descontar en planillas</comment>');
        $filas = [];
        $totalSaldo = 0;
        foreach (self::ODIS as $o) {
            $total = $o['incapacidades'] + $o['otros_riesgos'];
            $desc = array_sum(array_column($o['descuentos'], 'valor'));
            $totalSaldo += $total - $desc;
            $filas[] = [
                $o['numero'],
                DB::table('razones_sociales')->where('id', $o['rs'])->value('razon_social'),
                implode(', ', array_map(fn ($i) => "#{$i}", $o['entradas'])),
                $fmt($o['incapacidades']).' + '.$fmt($o['otros_riesgos']),
                $fmt($total),
                $fmt($desc),
                $fmt($total - $desc),
            ];
        }
        $this->table(['ODI', 'Razón social', 'Incapacidades', 'Incap. + otros riesgos', 'Total', 'Descontado', 'Saldo'], $filas);
        $this->line('   Saldo total por descontar: '.$fmt($totalSaldo));
    }

    // ── Cambios ──────────────────────────────────────────────────────────────

    private function corregirOpi(): void
    {
        foreach (self::OPI_ENTRADAS as $abonoId => $incId) {
            $a = DB::table('abonos_incapacidades')->find($abonoId);
            $obs = str_replace('ODI (Orden de la entidad)', 'OPI cobrada en efectivo', (string) $a->observacion);
            if ($obs === $a->observacion) {
                $obs = "OPI cobrada en efectivo — Incapacidad #{$incId}";
            }

            DB::table('abonos_incapacidades')->where('id', $abonoId)->update([
                'forma_recepcion' => 'opi_efectivo',
                'observacion' => mb_substr($obs.' [corregido 16-oct-2026: se había registrado como ODI]', 0, 500),
                'updated_at' => now(),
            ]);
            $this->line("  ✅ #{$incId}: entrada como OPI en efectivo");
        }
    }

    private function corregirPagosEfectivo(): void
    {
        foreach (self::PAGOS_EFECTIVO as $incId => [$gastoPago, $gasto4x1000, $abonoPago]) {
            $x = DB::table('gastos')->find($gasto4x1000);
            $valor4x1000 = $x ? (int) $x->valor : 0;

            if ($x) {
                // Queda escrito en el pago lo que se quitó, ya que el gasto se borra.
                DB::table('gastos')->where('id', $gasto4x1000)->delete();
            }

            $nota = " [corregido 16-oct-2026: se pagó en efectivo, no por transferencia; se quitó el 4x1000 de \${$valor4x1000}]";

            $g = DB::table('gastos')->find($gastoPago);
            DB::table('gastos')->where('id', $gastoPago)->update([
                'forma_pago' => 'efectivo',
                'banco_origen_id' => null,
                'observacion' => mb_substr((string) $g->observacion.$nota, 0, 500),
                'updated_at' => now(),
            ]);

            $a = DB::table('abonos_incapacidades')->find($abonoPago);
            DB::table('abonos_incapacidades')->where('id', $abonoPago)->update([
                'banco_cuenta_id' => null,
                'observacion' => mb_substr((string) $a->observacion.$nota, 0, 500),
                'updated_at' => now(),
            ]);

            $this->line("  ✅ #{$incId}: pago en efectivo, 4x1000 de \${$valor4x1000} quitado");
        }
    }

    private function crearOdis(): void
    {
        foreach (self::ODIS as $o) {
            $odi = OdiArl::create([
                'aliado_id' => self::ALIADO,
                'razon_social_id' => $o['rs'],
                'arl_id' => self::ARL_SURA,
                'numero' => $o['numero'],
                'fecha' => $o['fecha'],
                'valor_incapacidades' => $o['incapacidades'],
                'valor_otros_riesgos' => $o['otros_riesgos'],
                'total' => $o['incapacidades'] + $o['otros_riesgos'],
                'descontado' => 0,
                'observacion' => $o['obs'],
                'usuario_id' => 2,
            ]);

            foreach ($o['entradas'] as $abonoId => $incId) {
                DB::table('abonos_incapacidades')->where('id', $abonoId)->update([
                    'forma_recepcion' => 'odi',
                    'odi_id' => $odi->id,
                    // Algunas entradas viejas quedaron sin razón social (#458).
                    'razon_social_id' => DB::raw('ISNULL(razon_social_id, '.(int) $o['rs'].')'),
                    'updated_at' => now(),
                ]);
            }

            foreach ($o['descuentos'] as $d) {
                $pl = DB::table('operador_planillas_api')
                    ->where('aliado_id', self::ALIADO)->where('numero_planilla', $d['planilla'])
                    ->first() ?? throw new RuntimeException("Planilla {$d['planilla']} no encontrada.");

                $odi->descuentos()->create([
                    'aliado_id' => self::ALIADO,
                    'numero_planilla' => $d['planilla'],
                    'periodo' => $pl->periodo_cotizacion,
                    'valor' => $d['valor'],
                    'fecha' => substr((string) $pl->created_at, 0, 10),
                    'origen' => 'correccion',
                    'observacion' => 'Descuento ya aplicado en la planilla pagada (cargado al crear la ODI)',
                    'usuario_id' => 2,
                ]);
            }

            $odi->recalcularDescontado();
            $this->line("  ✅ ODI {$o['numero']}: saldo \$".number_format($odi->saldo, 0, ',', '.'));
        }
    }
}
