<?php

namespace App\Services\Caja;

use App\Models\CierreMes as CierreMesModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El cierre de un mes: cómo terminó y qué plata pasa al siguiente.
 *
 *   A · Utilidad       lo que ganó la empresa en el mes (del informe)
 *   B · Pasa al mes    plata que entró pero no es de la empresa todavía
 *   C · Por cobrar     lo que ya contó como ingreso y falta que entre
 *   D · Dónde está     saldo de cada cuenta y efectivo de cada persona
 *
 * Cuadra cuando  D = utilidad acumulada + B − C. La utilidad acumulada parte
 * del primer cierre del aliado (la apertura, cuya diferencia se absorbe una
 * sola vez) y cada mes suma su utilidad y resta los retiros del dueño.
 *
 * Las cuentas personales (uso_personal) solo llevan lo del negocio: si quedan
 * en negativo el negocio le debe a la cuenta («pendiente a la cuenta», va en
 * B); si quedan en positivo es plata del negocio que está ahí (va en D).
 *
 * Reglas acordadas con el dueño el 16/17-oct-2026 (ver memoria
 * cierre-mensual-reglas): administración y afiliaciones cuentan en el mes en
 * que se pagan; la SS pasa planilla por planilla; el préstamo cuenta en el mes
 * en que se presta y lo no cobrado pasa como cartera.
 */
class CierreMes
{
    /**
     * Los bloques del mes con lo que hay hoy en la base.
     *
     * @param  array  $inf  cifras que ya calculó InformeController::estadoFinanciero
     */
    public static function calcular(int $aliadoId, int $anio, int $mes, array $inf): array
    {
        $inicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
        $fin    = $inicio->copy()->endOfMonth();
        $corte  = $fin->isFuture() ? today() : $fin;
        $sig    = $corte->copy()->addDay()->toDateString();

        $retiros = FlujoEfectivo::totales($aliadoId, null, $inicio->toDateString(), $corte->toDateString())['retiros'];

        $a = [
            'ingresos'  => [
                ['Administración', (int) $inf['ing_planillas']],
                ['Afiliaciones (queda a la empresa)', (int) $inf['ing_afiliaciones']],
                ['Trámites', (int) $inf['ing_tramites']],
                ['Excedente de planilla', (int) $inf['sobrante']],
                ['Administración de incapacidades', (int) $inf['admon_inc']],
                ['Otros descuentos de incapacidades', (int) $inf['otros_inc']],
            ],
            'egresos'   => [
                ['Gastos operativos', (int) $inf['gastos_op']],
                ['Comisiones pagadas', (int) $inf['comisiones']],
            ],
            'total_ingresos' => (int) $inf['ingresos_total'],
            'total_egresos'  => (int) $inf['egresos_total'],
            'utilidad'       => (int) $inf['utilidad'],
            'retiros'        => $retiros,
        ];

        // ── D: dónde está la plata ───────────────────────────────────────
        $bancos = [];
        $pendientePersonales = 0;
        foreach ($inf['bancos'] as $b) {
            $saldo    = (int) $b->saldo_actual;
            $personal = (bool) ($b->uso_personal ?? false);
            if ($personal && $saldo < 0) {
                $pendientePersonales += -$saldo;
            }
            if ($saldo == 0 && (int) $b->ing_mes == 0 && (int) $b->sal_mes == 0) {
                continue;
            }
            $bancos[] = [
                'id'       => (int) $b->id,
                'nombre'   => trim(($b->banco ?? '') . ' ' . ($b->nombre ?? '')),
                'tipo'     => $personal ? 'personal' : (($b->cobro ?? false) ? 'cobro' : 'razon_social'),
                'saldo'    => $personal && $saldo < 0 ? 0 : $saldo,
                'saldo_cuenta' => $saldo,
                'entro'    => (int) $b->ing_mes,
                'salio'    => (int) $b->sal_mes,
            ];
        }

        $personas = FlujoEfectivo::personasConSaldo($aliadoId, $sig);
        $nombres  = User::whereIn('id', $personas ?: [0])->pluck('nombre', 'id');
        $efectivo = array_map(fn ($uid) => [
            'id'     => $uid,
            'nombre' => $nombres[$uid] ?? "Usuario $uid",
            'saldo'  => (int) FlujoEfectivo::saldoPersonaAntesDe($aliadoId, $uid, $sig),
        ], $personas);

        // ── B: lo que pasa al mes siguiente; C: lo que falta por cobrar ──
        [$aFavor, $porCobrar] = self::saldosClientes($aliadoId, $corte->toDateString());

        $b = [
            ['ss',            'Seguridad social por pagar', 'cobrada para planillas que aún no se pagan', (int) $inf['ss_por_pagar']],
            ['anticipos',     'Anticipos sin aplicar', 'pagaron por adelantado; todavía no hay factura', self::anticiposSinAplicar($aliadoId, $corte->toDateString())],
            ['retiros',       'Reserva de retiros', 'apartada de las afiliaciones para pagar retiros', (int) $inf['reserva_retiros']],
            ['asesores',      'Comisiones de asesores por pagar', 'se pagan cuando las piden', (int) $inf['asesores']],
            ['incapacidades', 'Saldo de incapacidades', 'plata de los afiliados que entró y no se ha liquidado', (int) round($inf['incapacidades'])],
            ['a_favor',       'Saldos a favor de clientes', 'pagaron de más; pasa hasta que se aplique', $aFavor],
            ['personales',    'Pendiente a tus cuentas personales', 'el negocio pagó con tu plata: transfiere para dejarlas en 0', $pendientePersonales],
        ];

        return [
            'anio'      => $anio,
            'mes'       => $mes,
            'corte'     => $corte->toDateString(),
            'en_curso'  => $fin->isFuture(),
            'a'         => $a,
            'b'         => $b,
            'total_b'   => (int) array_sum(array_column($b, 3)),
            'c'         => [['cartera', 'Cartera de clientes', 'préstamos y facturas que quedaron debiendo', $porCobrar]],
            'total_c'   => $porCobrar,
            'bancos'    => $bancos,
            'efectivo'  => $efectivo,
            'efectivo_contado' => ! empty($personas),
            // Sin caja por persona no hay efectivo contado: queda el cálculo
            // viejo del informe, que suma todo lo cobrado sin restar lo entregado.
            'efectivo_viejo'   => (int) $inf['efectivo_viejo'],
        ];
    }

    /**
     * El cierre que se muestra: la foto si el mes ya cerró (lo que pasó al
     * siguiente no cambia solo), los saldos reales anotados, la utilidad
     * acumulada y si cuadra.
     */
    public static function componer(int $aliadoId, int $anio, int $mes, array $vivo): array
    {
        $fila  = CierreMesModel::de($aliadoId, $anio, $mes);
        $datos = $fila?->foto ?: $vivo;
        $reales = $fila?->saldos_reales ?? [];

        // D con lo real donde se anotó.
        $totalBancos = 0;
        foreach ($datos['bancos'] as &$bk) {
            $bk['real'] = isset($reales['bancos'][$bk['id']]) ? (int) $reales['bancos'][$bk['id']] : null;
            $totalBancos += $bk['real'] ?? $bk['saldo'];
        }
        unset($bk);
        $totalEfectivo = 0;
        foreach ($datos['efectivo'] as &$ef) {
            $ef['real'] = isset($reales['efectivo'][$ef['id']]) ? (int) $reales['efectivo'][$ef['id']] : null;
            $totalEfectivo += $ef['real'] ?? $ef['saldo'];
        }
        unset($ef);
        if (! $datos['efectivo_contado']) {
            $totalEfectivo = $datos['efectivo_viejo'];
        }
        $totalD = $totalBancos + $totalEfectivo;

        $neto = $datos['a']['utilidad'] - $datos['a']['retiros'];
        [$utilAntes, $apertura, $faltan] = self::utilidadAntesDe($aliadoId, $anio, $mes, $fila);
        if ($apertura) {
            // Primer cierre: la utilidad con que arranca es la que hace cuadrar.
            $utilAntes = $totalD - $datos['total_b'] + $datos['total_c'] - $neto;
        }
        $utilDespues = $utilAntes + $neto;
        $esperado    = $utilDespues + $datos['total_b'] - $datos['total_c'];

        $anterior = CierreMesModel::anteriorConFoto($aliadoId, $anio, $mes);

        // Si el mes ya tiene foto, ¿cambió algo después?
        $cambios = [];
        if ($fila?->foto) {
            foreach ([
                'Utilidad'             => [$fila->foto['a']['utilidad'], $vivo['a']['utilidad']],
                'Lo que pasa al mes'   => [$fila->foto['total_b'], $vivo['total_b']],
                'Por cobrar'           => [$fila->foto['total_c'], $vivo['total_c']],
                'Saldo de bancos'      => [array_sum(array_column($fila->foto['bancos'], 'saldo')), array_sum(array_column($vivo['bancos'], 'saldo'))],
            ] as $nombre => [$antes, $ahora]) {
                if (abs($ahora - $antes) >= 1) {
                    $cambios[] = [$nombre, $ahora - $antes];
                }
            }
        }

        return $datos + [
            'fila_id'        => $fila?->id,
            'foto_at'        => $fila?->foto_at,
            'saldos_at'      => $fila?->saldos_at,
            'hay_reales'     => ! empty($reales),
            'total_bancos'   => $totalBancos,
            'total_efectivo' => $totalEfectivo,
            'total_d'        => $totalD,
            'es_apertura'    => $apertura,
            'faltan_fotos'   => $faltan,
            'util_antes'     => $utilAntes,
            'util_despues'   => $utilDespues,
            'esperado'       => $esperado,
            'diferencia'     => $totalD - $esperado,
            'llego'          => $anterior?->foto['total_b'] ?? null,
            'llego_detalle'  => $anterior?->foto['b'] ?? [],
            'cambios'        => $cambios,
        ];
    }

    /** Guarda la foto del mes. Sin $rehacer no toca una foto que ya existe. */
    public static function guardarFoto(int $aliadoId, int $anio, int $mes, array $vivo, bool $rehacer = false): CierreMesModel
    {
        $fila = CierreMesModel::firstOrNew(['aliado_id' => $aliadoId, 'anio' => $anio, 'mes' => $mes]);
        if ($fila->foto && ! $rehacer) {
            return $fila;
        }

        $fila->foto    = $vivo;
        $fila->foto_at = now();
        $fila->es_apertura = ! CierreMesModel::where('aliado_id', $aliadoId)
            ->whereNotNull('foto')
            ->where(fn ($q) => $q->where('anio', '<', $anio)->orWhere(fn ($w) => $w->where('anio', $anio)->where('mes', '<', $mes)))
            ->exists();
        $fila->save();

        self::recalcularApertura($fila);

        return $fila;
    }

    /** Guarda lo que el superadmin anotó que hay de verdad al cierre. */
    public static function guardarSaldosReales(int $aliadoId, int $anio, int $mes, array $reales, int $usuarioId): CierreMesModel
    {
        $fila = CierreMesModel::firstOrNew(['aliado_id' => $aliadoId, 'anio' => $anio, 'mes' => $mes]);
        $fila->saldos_reales = $reales;
        $fila->saldos_por    = $usuarioId;
        $fila->saldos_at     = now();
        $fila->save();

        self::recalcularApertura($fila);

        return $fila;
    }

    /**
     * La utilidad acumulada al terminar la apertura es la que la hace cuadrar,
     * con sus saldos reales si ya se anotaron. Los meses siguientes parten de
     * ahí y no la guardan: se suman sus fotos.
     */
    private static function recalcularApertura(CierreMesModel $fila): void
    {
        if (! $fila->es_apertura || ! $fila->foto) {
            return;
        }
        $c = self::componer($fila->aliado_id, $fila->anio, $fila->mes, $fila->foto);
        $fila->utilidad_acumulada = $c['util_despues'];
        $fila->save();
    }

    /**
     * Utilidad acumulada al empezar el mes: la de la apertura más la utilidad
     * menos los retiros de cada foto que vino después.
     *
     * @return array{0:int, 1:bool, 2:array}  [utilidad, es la apertura, meses sin foto]
     */
    private static function utilidadAntesDe(int $aliadoId, int $anio, int $mes, ?CierreMesModel $fila): array
    {
        if ($fila?->es_apertura) {
            return [0, true, []];
        }

        $apertura = CierreMesModel::where('aliado_id', $aliadoId)->where('es_apertura', true)
            ->whereNotNull('foto')
            ->where(fn ($q) => $q->where('anio', '<', $anio)->orWhere(fn ($w) => $w->where('anio', $anio)->where('mes', '<', $mes)))
            ->orderByDesc('anio')->orderByDesc('mes')->first();
        if (! $apertura) {
            return [0, true, []];
        }

        $util  = (int) $apertura->utilidad_acumulada;
        $faltan = [];
        $cursor = Carbon::createFromDate($apertura->anio, $apertura->mes, 1)->addMonth();
        $hasta  = Carbon::createFromDate($anio, $mes, 1);
        while ($cursor->lt($hasta)) {
            $f = CierreMesModel::de($aliadoId, $cursor->year, $cursor->month);
            if ($f?->foto) {
                $util += (int) $f->foto['a']['utilidad'] - (int) $f->foto['a']['retiros'];
            } else {
                $faltan[] = $cursor->format('Y-m');
            }
            $cursor->addMonth();
        }

        return [$util, false, $faltan];
    }

    /** Anticipos recibidos hasta el corte que no se habían aplicado a una factura de antes del corte. */
    public static function anticiposSinAplicar(int $aliadoId, string $corte): int
    {
        $recibido = (int) DB::table('anticipos')
            ->where('aliado_id', $aliadoId)->whereNull('deleted_at')
            ->whereNotIn('estado', ['distribuido', 'devuelto'])
            ->whereDate('fecha_pago', '<=', $corte)
            ->sum('valor');

        $aplicado = (int) DB::table('anticipos AS a')
            ->join('facturas AS f', 'f.id', '=', 'a.factura_id')
            ->where('a.aliado_id', $aliadoId)->whereNull('a.deleted_at')->whereNull('f.deleted_at')
            ->whereNotIn('a.estado', ['distribuido', 'devuelto'])
            ->whereDate('a.fecha_pago', '<=', $corte)
            ->whereDate('f.fecha_pago', '<=', $corte)
            ->sum('a.valor_aplicado');

        return max(0, $recibido - $aplicado);
    }

    /**
     * Saldo de cada cliente: la suma de sus `saldo_proximo` (por empresa en
     * las facturas de empresa, por cédula en las individuales), como lo hace
     * el recibo. Positivo es plata a su favor (pasa al mes siguiente);
     * negativo es lo que debe, préstamos incluidos (por cobrar). A los
     * individuales se les restan los ajustes de saldo, como en
     * Factura::saldoClienteMesPrevio.
     *
     * @return array{0:int, 1:int}  [a favor, por cobrar]
     */
    public static function saldosClientes(int $aliadoId, string $corte): array
    {
        $filas = DB::table('facturas')
            ->where('aliado_id', $aliadoId)->whereNull('deleted_at')
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->whereNotNull('saldo_proximo')
            ->whereDate('fecha_pago', '<=', $corte)
            ->groupByRaw("CASE WHEN empresa_id IS NOT NULL THEN 'e' + CAST(empresa_id AS varchar(20)) ELSE 'c' + CAST(cedula AS varchar(20)) END")
            ->selectRaw("CASE WHEN empresa_id IS NOT NULL THEN 'e' + CAST(empresa_id AS varchar(20)) ELSE 'c' + CAST(cedula AS varchar(20)) END AS llave,
                         SUM(CAST(saldo_proximo AS BIGINT)) AS saldo")
            ->pluck('saldo', 'llave');

        $ajustes = DB::table('saldo_ajustes')->where('aliado_id', $aliadoId)->whereNull('deleted_at')
            ->whereDate('created_at', '<=', $corte)
            ->groupBy('cedula')->selectRaw('cedula, SUM(CAST(valor AS BIGINT)) AS v')->pluck('v', 'cedula');

        $aFavor = 0;
        $debe   = 0;
        foreach ($filas as $llave => $saldo) {
            $saldo = (int) $saldo;
            if ($saldo > 0 && str_starts_with($llave, 'c')) {
                $saldo = max(0, $saldo - (int) ($ajustes[substr($llave, 1)] ?? 0));
            }
            $saldo > 0 ? $aFavor += $saldo : $debe += -$saldo;
        }

        return [$aFavor, $debe];
    }
}
