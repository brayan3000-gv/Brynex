<?php

namespace App\Services\Caja;

use App\Models\Anticipo;
use App\Models\CajaMovimiento;
use App\Models\Gasto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La única regla de cuánto efectivo entró y salió.
 *
 * El cuadre diario, el consolidado, las facturas del día y el informe
 * financiero contaban lo mismo con filtros distintos (estados, préstamos,
 * abonos, OPI), y por eso la suma de los días del cuadre nunca daba el mes
 * del informe. Todos leen de aquí.
 *
 * Entra al efectivo:
 *   facturas      `valor_efectivo` de facturas pagadas, en abono o préstamo,
 *                 por `fecha_pago` (el pago parcial de un préstamo es plata
 *                 que sí llegó a la caja)
 *   abonos        `abonos.valor_efectivo` a cualquier factura, por la fecha
 *                 del abono — menos los que nacen de cobrar cartera junto con
 *                 una factura nueva («Cobrado con factura #…»): esa plata ya
 *                 está en el pago de la factura nueva
 *   anticipos     efectivo o nequi, sin los devueltos ni el maestro
 *                 distribuido (su plata está en los hijos)
 *   opi           incapacidades que la ARL pagó en efectivo
 *   recibidas     entregas de otra persona (caja_movimientos)
 *
 * Sale del efectivo:
 *   gastos        gastos en efectivo de la empresa
 *   traslados     efectivo llevado al banco
 *   incapacidades pagos de incapacidad al afiliado en efectivo
 *   dadas         entregas a otra persona
 *   retiros       retiros del dueño
 *
 * Con la caja de todos, una entrega suma igual en recibidas y en dadas y se
 * anula; el retiro del dueño sí baja el total.
 */
class FlujoEfectivo
{
    const ENTRADAS = ['facturas', 'abonos', 'anticipos', 'opi', 'recibidas'];
    const SALIDAS  = ['gastos', 'traslados', 'incapacidades', 'dadas', 'retiros'];

    /** Marca de los abonos que crea FacturacionController::_liquidarCartera. */
    const OBS_CARTERA_CRUZADA = 'Cobrado con factura #%';

    private static ?bool $hayFormaRecepcion = null;

    /**
     * Movimientos por persona y día entre dos fechas (ambas incluidas).
     *
     * @param  int[]|null  $usuarioIds  null = todas las personas
     * @return array<string, array<string,int>>  llave "uid|AAAA-MM-DD"
     */
    public static function porUsuarioDia(int $aliadoId, ?array $usuarioIds, string $desde, string $hasta): array
    {
        $filas = [];
        $sumar = function ($rows, string $componente) use (&$filas) {
            foreach ($rows as $r) {
                $llave = (int) $r->uid . '|' . substr((string) $r->dia, 0, 10);
                $filas[$llave][$componente] = ($filas[$llave][$componente] ?? 0) + (int) $r->t;
            }
        };
        $deUsuarios = fn ($q, string $col) => $usuarioIds === null ? $q : $q->whereIn($col, $usuarioIds);
        $rango = fn ($q, string $col) => $q->whereDate($col, '>=', $desde)->whereDate($col, '<=', $hasta);

        $sumar($deUsuarios($rango(DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->where('valor_efectivo', '>', 0), 'fecha_pago'), 'usuario_id')
            ->groupBy('usuario_id', DB::raw('CAST(fecha_pago AS date)'))
            ->selectRaw('usuario_id AS uid, CAST(fecha_pago AS date) AS dia, SUM(CAST(valor_efectivo AS BIGINT)) AS t')
            ->get(), 'facturas');

        $sumar($deUsuarios($rango(DB::table('abonos AS a')
            ->join('facturas AS f', 'f.id', '=', 'a.factura_id')
            ->where('f.aliado_id', $aliadoId)
            ->whereNull('f.deleted_at')
            ->where('a.valor_efectivo', '>', 0)
            ->where(fn ($q) => $q->whereNull('a.observacion')->orWhere('a.observacion', 'not like', self::OBS_CARTERA_CRUZADA)),
            'a.fecha'), 'a.usuario_id')
            ->groupBy('a.usuario_id', DB::raw('CAST(a.fecha AS date)'))
            ->selectRaw('a.usuario_id AS uid, CAST(a.fecha AS date) AS dia, SUM(CAST(a.valor_efectivo AS BIGINT)) AS t')
            ->get(), 'abonos');

        $sumar($deUsuarios($rango(DB::table('anticipos')
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->whereIn('forma_pago', ['efectivo', 'nequi'])
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO]), 'fecha_pago'), 'usuario_id')
            ->groupBy('usuario_id', DB::raw('CAST(fecha_pago AS date)'))
            ->selectRaw('usuario_id AS uid, CAST(fecha_pago AS date) AS dia, SUM(CAST(valor AS BIGINT)) AS t')
            ->get(), 'anticipos');

        if (self::$hayFormaRecepcion ??= Schema::hasColumn('abonos_incapacidades', 'forma_recepcion')) {
            $sumar($deUsuarios($rango(DB::table('abonos_incapacidades')
                ->where('aliado_id', $aliadoId)
                ->where('tipo', 'entrada_incapacidad')
                ->where('forma_recepcion', 'opi_efectivo'), 'fecha'), 'usuario_id')
                ->groupBy('usuario_id', DB::raw('CAST(fecha AS date)'))
                ->selectRaw('usuario_id AS uid, CAST(fecha AS date) AS dia, SUM(CAST(valor AS BIGINT)) AS t')
                ->get(), 'opi');
        }

        $incapacidades = "'" . implode("','", Gasto::TIPOS_INCAPACIDAD) . "'";
        $gastos = $deUsuarios($rango(DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where(fn ($q) => $q->where('forma_pago', 'efectivo')->orWhere('tipo', 'efectivo_banco')), 'fecha'), 'usuario_id')
            ->groupBy('usuario_id', DB::raw('CAST(fecha AS date)'))
            ->selectRaw("usuario_id AS uid, CAST(fecha AS date) AS dia,
                SUM(CASE WHEN tipo = 'efectivo_banco' THEN CAST(valor AS BIGINT) ELSE 0 END) AS traslados,
                SUM(CASE WHEN tipo IN ($incapacidades) THEN CAST(valor AS BIGINT) ELSE 0 END) AS incapacidades,
                SUM(CASE WHEN tipo <> 'efectivo_banco' AND tipo NOT IN ($incapacidades) THEN CAST(valor AS BIGINT) ELSE 0 END) AS gastos")
            ->get();
        foreach (['gastos', 'traslados', 'incapacidades'] as $col) {
            $sumar($gastos->map(fn ($r) => (object) ['uid' => $r->uid, 'dia' => $r->dia, 't' => $r->$col]), $col);
        }

        $movs = $rango(CajaMovimiento::where('aliado_id', $aliadoId)
            ->whereIn('tipo', [CajaMovimiento::TIPO_ENTREGA, CajaMovimiento::TIPO_RETIRO_DUENO]), 'fecha')
            ->get(['tipo', 'fecha', 'usuario_origen_id', 'usuario_destino_id', 'valor']);
        $quiere = fn ($uid) => $usuarioIds === null || in_array((int) $uid, array_map('intval', $usuarioIds), true);
        foreach ($movs as $m) {
            $dia = $m->fecha->toDateString();
            if ($m->tipo === CajaMovimiento::TIPO_ENTREGA) {
                if ($quiere($m->usuario_destino_id)) {
                    $sumar([(object) ['uid' => $m->usuario_destino_id, 'dia' => $dia, 't' => $m->valor]], 'recibidas');
                }
                if ($quiere($m->usuario_origen_id)) {
                    $sumar([(object) ['uid' => $m->usuario_origen_id, 'dia' => $dia, 't' => $m->valor]], 'dadas');
                }
            } elseif ($quiere($m->usuario_origen_id)) {
                $sumar([(object) ['uid' => $m->usuario_origen_id, 'dia' => $dia, 't' => $m->valor]], 'retiros');
            }
        }

        return $filas;
    }

    /**
     * Totales de una persona (o de todas con null) entre dos fechas, con
     * `recibido` (cobros), `salidas` y `neto` ya calculados.
     */
    public static function totales(int $aliadoId, ?int $usuarioId, string $desde, string $hasta): array
    {
        $filas = self::porUsuarioDia($aliadoId, $usuarioId === null ? null : [$usuarioId], $desde, $hasta);

        return self::resumir($filas);
    }

    /**
     * Lo que una persona traía al empezar el día, sin la base de caja menor.
     *
     * Parte del último saldo inicial contado con fecha <= $fecha (que es el
     * saldo al empezar ese día) y le suma lo que se movió desde entonces hasta
     * el día anterior. null si la persona nunca tuvo saldo inicial: entonces
     * no se sabe cuánto tiene.
     */
    public static function saldoPersonaAntesDe(int $aliadoId, int $usuarioId, string $fecha): ?int
    {
        $inicial = CajaMovimiento::saldoInicialVigente($aliadoId, $usuarioId, $fecha);
        if (! $inicial) {
            return null;
        }

        $desde = $inicial->fecha->toDateString();
        if ($desde === $fecha) {
            return (int) $inicial->valor;
        }

        $ayer = \Carbon\Carbon::parse($fecha)->subDay()->toDateString();

        return (int) $inicial->valor + self::totales($aliadoId, $usuarioId, $desde, $ayer)['neto'];
    }

    /** Personas con saldo inicial contado con fecha <= $fecha: las que arrastran caja. */
    public static function personasConSaldo(int $aliadoId, string $fecha): array
    {
        return CajaMovimiento::where('aliado_id', $aliadoId)
            ->where('tipo', CajaMovimiento::TIPO_SALDO_INICIAL)
            ->whereDate('fecha', '<=', $fecha)
            ->distinct()
            ->pluck('usuario_destino_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Suma un conjunto de filas de porUsuarioDia. */
    public static function resumir(array $filas): array
    {
        $t = array_fill_keys(array_merge(self::ENTRADAS, self::SALIDAS), 0);
        foreach ($filas as $f) {
            foreach ($t as $k => $_) {
                $t[$k] += (int) ($f[$k] ?? 0);
            }
        }
        $t['recibido'] = $t['facturas'] + $t['abonos'] + $t['anticipos'] + $t['opi'];
        $t['salidas']  = $t['gastos'] + $t['traslados'] + $t['incapacidades'];
        $t['neto']     = $t['recibido'] + $t['recibidas'] - $t['salidas'] - $t['dadas'] - $t['retiros'];

        return $t;
    }
}
