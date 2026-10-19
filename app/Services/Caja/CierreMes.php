<?php

namespace App\Services\Caja;

use App\Models\CierreMes as CierreMesModel;
use App\Models\Factura;
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
 * Cada mes se revisa solo (19-oct-2026): cada cuenta y el efectivo de cada
 * persona arrancan con lo VERIFICADO al cierre anterior (o 0 «sin
 * verificar»; las de razón social siguen con su saldo, ver SaldosBanco) y la
 * diferencia entre lo real anotado y lo que dice BryNex es lo que no se
 * registró en ESE mes. Lo que se debe (B y C) sí sigue hasta que se pague.
 *
 * Las cuentas personales (uso_personal) arrancan cada mes en 0 y solo llevan
 * lo del negocio del mes: si quedan en negativo el negocio le debe a la cuenta
 * («pendiente a la cuenta», va en B); en positivo es plata del negocio (D).
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
        $cuentasEnNegativo = [];
        foreach ($inf['bancos'] as $b) {
            $saldo    = (int) $b->saldo_actual;
            $personal = (bool) ($b->uso_personal ?? false);
            if ($personal && $saldo < 0) {
                $pendientePersonales += -$saldo;
                $cuentasEnNegativo[] = trim(($b->banco ?? '') . ' ' . ($b->nombre ?? '')) . ' $' . number_format(-$saldo, 0, ',', '.');
            }
            if ($saldo == 0 && (int) $b->ing_mes == 0 && (int) $b->sal_mes == 0) {
                continue;
            }
            $bancos[] = [
                'id'       => (int) $b->id,
                'nombre'   => trim(($b->banco ?? '') . ' ' . ($b->nombre ?? '')),
                'tipo'     => $personal ? 'personal' : (($b->incapacidad ?? false) ? 'razon_social' : (($b->cobro ?? false) ? 'cobro' : 'negocio')),
                'inicial'  => (int) ($b->saldo_inicial ?? 0),
                'origen'   => $b->origen_saldo ?? 'sin_verificar',
                'saldo'    => $personal && $saldo < 0 ? 0 : $saldo,
                'saldo_cuenta' => $saldo,
                'entro'    => (int) $b->ing_mes,
                'salio'    => (int) $b->sal_mes,
            ];
        }

        $c = $corte->toDateString();
        $personas = FlujoEfectivo::personasDelMes($aliadoId, $c);
        $nombres  = User::whereIn('id', $personas ?: [0])->pluck('nombre', 'id');
        $efectivo = array_map(function ($uid) use ($aliadoId, $c, $nombres, $inicio) {
            $apertura = FlujoEfectivo::aperturaPersona($aliadoId, $uid, $c);
            $mes      = FlujoEfectivo::totales($aliadoId, $uid, $apertura['fecha'] ?? $inicio->toDateString(), $c);

            return [
                'id'         => $uid,
                'nombre'     => $nombres[$uid] ?? "Usuario $uid",
                'inicial'    => (int) ($apertura['valor'] ?? 0),
                'verificado' => $apertura !== null,
                'entro'      => $mes['recibido'] + $mes['recibidas'],
                'salio'      => $mes['salidas'] + $mes['dadas'] + $mes['retiros'],
                'saldo'      => (int) ($apertura['valor'] ?? 0) + $mes['neto'],
            ];
        }, $personas);

        // ── B: lo que pasa al mes siguiente; C: lo que falta por cobrar ──
        [$aFavor, $porCobrar] = self::saldosClientes($aliadoId, $corte->toDateString());

        $b = [
            ['ss',            'Seguridad social por pagar', 'cobrada para planillas que aún no se pagan', (int) $inf['ss_por_pagar']],
            ['anticipos',     'Anticipos sin aplicar', 'pagaron por adelantado; todavía no hay factura', self::anticiposSinAplicar($aliadoId, $corte->toDateString())],
            ['retiros',       'Reserva de retiros', 'apartada de las afiliaciones para pagar retiros', (int) $inf['reserva_retiros']],
            ['asesores',      'Comisiones de asesores por pagar', 'se pagan cuando las piden', (int) $inf['asesores']],
            ['incapacidades', 'Saldo de incapacidades', 'plata de los afiliados que entró y no se ha liquidado', (int) round($inf['incapacidades'])],
            ['a_favor',       'Saldos a favor de clientes', 'pagaron de más; pasa hasta que se aplique', $aFavor],
            ['personales',    'Prestado por el dueño', $cuentasEnNegativo
                ? 'el negocio pagó con tu plata desde ' . implode(', ', $cuentasEnNegativo) . ': pendiente por recuperar'
                : 'tus cuentas personales no están en negativo', $pendientePersonales],
        ];

        // Lo que falta que entre, separado: los préstamos del mes (lo que
        // muestra la tarjeta de préstamos), los de meses anteriores y lo demás
        // que deben los clientes, neto de sus saldos a favor.
        // saldosClientes suma el saldo_proximo de los préstamos sin sus abonos:
        // lo ya abonado se quita del total para que no cuente dos veces.
        [$prestMes, $prestAnt, $yaAbonado] = self::prestamosPendientes($aliadoId, $inicio->toDateString(), $corte->toDateString());
        $porCobrar = max(0, $porCobrar - $yaAbonado);
        $otras = $porCobrar - $prestMes - $prestAnt;

        return [
            'anio'      => $anio,
            'mes'       => $mes,
            'corte'     => $corte->toDateString(),
            'en_curso'  => $fin->isFuture(),
            'a'         => $a,
            'b'         => $b,
            'total_b'   => (int) array_sum(array_column($b, 3)),
            'c'         => [
                ['prestamos_mes', 'Préstamos de ' . $inicio->locale('es')->monthName, 'los que muestra la tarjeta de préstamos', $prestMes],
                ['prestamos_ant', 'Préstamos de meses anteriores', 'siguen sin pagar', $prestAnt],
                ['otras',         'Otras deudas de clientes', 'facturas que quedaron debiendo, menos sus saldos a favor', $otras],
            ],
            'total_c'   => $porCobrar,
            'bancos'    => $bancos,
            'efectivo'  => $efectivo,
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

        // D con lo real donde se anotó: la diferencia de cada fila es lo que no
        // se registró en el mes en esa cuenta o con esa persona.
        $totalBancos = 0; $totalEfectivo = 0; $diferencia = 0; $verificadas = 0; $porVerificar = 0;
        foreach (['bancos', 'efectivo'] as $grupo) {
            foreach ($datos[$grupo] as &$item) {
                $real = $reales[$grupo][$item['id']] ?? null;
                $item['real'] = $real === null ? null : (int) $real;
                $item['diferencia'] = $real === null ? null : (int) $real - $item['saldo'];
                $valor = $item['real'] ?? $item['saldo'];
                $grupo === 'bancos' ? $totalBancos += $valor : $totalEfectivo += $valor;
                if ($real === null) { $porVerificar++; } else { $verificadas++; $diferencia += $item['diferencia']; }
            }
            unset($item);
        }

        $anterior = CierreMesModel::anteriorConFoto($aliadoId, $anio, $mes);

        // Si el mes ya tiene foto, ¿cambió algo después?
        $cambios = [];
        if ($fila?->foto) {
            foreach ([
                'Utilidad'             => [$fila->foto['a']['utilidad'], $vivo['a']['utilidad']],
                'Lo que pasa al mes'   => [$fila->foto['total_b'], $vivo['total_b']],
                'Por cobrar'           => [$fila->foto['total_c'], $vivo['total_c']],
                'Saldo de bancos'      => [array_sum(array_column($fila->foto['bancos'], 'saldo')), array_sum(array_column($vivo['bancos'], 'saldo'))],
                'Efectivo'             => [array_sum(array_column($fila->foto['efectivo'] ?? [], 'saldo')), array_sum(array_column($vivo['efectivo'], 'saldo'))],
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
            'hay_reales'     => ! empty($reales['bancos'] ?? []) || ! empty($reales['efectivo'] ?? []),
            'total_bancos'   => $totalBancos,
            'total_efectivo' => $totalEfectivo,
            'total_d'        => $totalBancos + $totalEfectivo,
            'verificadas'    => $verificadas,
            'por_verificar'  => $porVerificar,
            'diferencia'     => $diferencia,
            'llego'          => $anterior?->foto['total_b'] ?? null,
            'llego_detalle'  => $anterior?->foto['b'] ?? [],
            'cambios'        => $cambios,
        ];
    }

    /**
     * Cómo está la plata de la empresa (todas las cuentas y cajas, no una
     * cuenta): lo que hay, lo que no es suyo, lo que prestó y lo que debería
     * ser según el cierre anterior más la ganancia del mes.
     *
     * Un saldo de incapacidades en negativo es plata que la empresa le
     * adelantó al afiliado y que la EPS o la ARL todavía debe: va con lo
     * prestado, no resta de lo ajeno.
     */
    public static function plata(int $aliadoId, int $anio, int $mes, array $c): array
    {
        $ajeno  = (int) $c['total_b'];
        $cobrar = (int) $c['total_c'];
        $adelantado = 0;
        foreach ($c['b'] as $it) {
            if ($it[0] === 'incapacidades' && $it[3] < 0) {
                $adelantado = -$it[3];
            }
        }
        $ajeno  += $adelantado;
        $cobrar += $adelantado;
        $es = (int) $c['total_d'] - $ajeno + $cobrar;

        // Lo que debería ser: lo que era de la empresa al cierre anterior
        // (su foto, con lo real anotado) + la ganancia del mes − los retiros.
        $deberia = null;
        $ant = Carbon::createFromDate($anio, $mes, 1)->subMonth();
        $filaAnt = CierreMesModel::de($aliadoId, $ant->year, $ant->month);
        if ($filaAnt?->foto) {
            $cAnt = self::componer($aliadoId, $ant->year, $ant->month, $filaAnt->foto);
            $deberia = self::plataSinAnterior($cAnt) + (int) $c['a']['utilidad'] - (int) $c['a']['retiros'];
        }

        $sinVerificar = array_values(array_filter(
            array_merge($c['bancos'], $c['efectivo']),
            fn ($f) => $f['real'] === null && ! in_array($f['origen'] ?? (($f['verificado'] ?? false) ? 'verificado' : 'sin_verificar'), ['verificado', 'manual', 'arrastre', 'personal'], true)
                && (int) $f['saldo'] !== 0
        ));

        return [
            'bancos'        => (int) $c['total_bancos'],
            'efectivo'      => (int) $c['total_efectivo'],
            'hay'           => (int) $c['total_d'],
            'ajeno'         => $ajeno,
            // Todo lo que suma al total, también lo negativo (la reserva de
            // retiros en déficit), para que los renglones den el total. El
            // saldo de incapacidades en negativo no: ese va con lo prestado.
            'ajeno_detalle' => array_values(array_filter($c['b'], fn ($it) => (int) $it[3] !== 0
                && ! ($it[0] === 'incapacidades' && $it[3] < 0))),
            'cobrar'        => $cobrar,
            'cobrar_detalle' => array_values(array_filter(array_merge($c['c'], $adelantado
                ? [['incapacidades', 'Incapacidades adelantadas al afiliado', 'la EPS o la ARL todavía debe', $adelantado]] : []),
                fn ($it) => (int) $it[3] !== 0)),
            'adelantado'    => $adelantado,
            'es'            => $es,
            'deberia'       => $deberia,
            'diferencia'    => $deberia === null ? null : $es - $deberia,
            'mes_anterior'  => $ant->locale('es')->monthName,
            'sin_verificar' => count($sinVerificar),
        ];
    }

    /** Lo que es de la empresa según un cierre, sin mirar el anterior. */
    private static function plataSinAnterior(array $c): int
    {
        $adelantado = 0;
        foreach ($c['b'] as $it) {
            if ($it[0] === 'incapacidades' && $it[3] < 0) {
                $adelantado = -$it[3];
            }
        }

        return (int) $c['total_d'] - ((int) $c['total_b'] + $adelantado) + ((int) $c['total_c'] + $adelantado);
    }

    /**
     * Aviso para quien registra un movimiento con fecha de un mes cuyo cierre
     * ya se verificó (se anotaron los saldos reales). Se deja registrar —lo
     * decidió el dueño—, pero la diferencia de ese mes cambia; el mes actual
     * no, porque arranca de lo verificado. null si no aplica.
     */
    public static function avisoMesVerificado(int $aliadoId, $fecha): ?string
    {
        $f = Carbon::parse($fecha);
        if ($f->isSameMonth(today()) || $f->isFuture()) {
            return null;
        }
        $fila = CierreMesModel::de($aliadoId, $f->year, $f->month);
        if (! $fila || empty($fila->saldos_reales['bancos'] ?? []) && empty($fila->saldos_reales['efectivo'] ?? [])) {
            return null;
        }

        return '⚠️ ' . ucfirst($f->locale('es')->monthName) . ' ya tiene el cierre verificado: este registro cambia su diferencia. '
            . 'El mes actual no se toca porque arranca de lo verificado.';
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
        $fila->save();

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

        return $fila;
    }

    /**
     * Lo que falta por cobrar de los préstamos: [hechos en el mes, hechos antes,
     * lo que ya abonaron].
     *
     * Misma regla que el listado de /admin/prestamos: el `saldo_proximo`
     * negativo de la factura MENOS sus abonos, y en las de empresa por lote
     * (Factura::deudaLote). El abono no toca `saldo_proximo` mientras el
     * préstamo no se salde, así que sin restarlo el informe contaba dos veces
     * la plata: en el banco y como «falta que entre» (Brygar, oct-2026:
     * $18,3M aquí contra $8,7M en el listado).
     */
    public static function prestamosPendientes(int $aliadoId, string $inicioMes, string $corte): array
    {
        $filas = DB::table('facturas')
            ->where('aliado_id', $aliadoId)->whereNull('deleted_at')
            ->where('estado', 'prestamo')
            ->whereDate('fecha_pago', '<=', $corte)
            ->get(['id', 'numero_factura', 'empresa_id', 'fecha_pago', 'saldo_proximo', 'valor_prestamo']);

        $abonos = DB::table('abonos')
            ->whereIn('factura_id', $filas->pluck('id'))
            ->whereDate('fecha', '<=', $corte)
            ->groupBy('factura_id')
            ->selectRaw('factura_id, SUM(CAST(valor AS BIGINT)) AS v')
            ->pluck('v', 'factura_id');

        $mes = $antes = $bruto = 0;
        $sumar = function (int $pendiente, $fila) use ($inicioMes, &$mes, &$antes) {
            substr((string) $fila->fecha_pago, 0, 10) >= $inicioMes ? $mes += $pendiente : $antes += $pendiente;
        };

        foreach ($filas->whereNull('empresa_id') as $f) {
            $deuda = max(0, -(int) $f->saldo_proximo);
            $bruto += $deuda;
            $sumar(max(0, $deuda - (int) ($abonos[$f->id] ?? 0)), $f);
        }

        $lotes = $filas->whereNotNull('empresa_id')->groupBy(fn ($f) => $f->empresa_id . '|' . $f->numero_factura);
        foreach ($lotes as $lote) {
            $bruto += (int) $lote->sum(fn ($f) => max(0, -(int) $f->saldo_proximo));
            $abonado = (int) $lote->sum(fn ($f) => (int) ($abonos[$f->id] ?? 0));
            $sumar(max(0, Factura::deudaLote($lote) - $abonado), $lote->first());
        }

        return [$mes, $antes, max(0, $bruto - $mes - $antes)];
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
