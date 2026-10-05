<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\Asesor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Informe «Cómo van los asesores».
 *
 * No cambia nada: el tipo de cobro y el porcentaje de cada asesor se editan a
 * mano en su ficha. Esto solo cuenta la cartera del mes, dice en qué nivel de la
 * escalera del aliado cae (config/asesores.php) y qué dejó en plata, para que
 * quien decide vea si hay que subirlo, avisarle o dejarlo como está.
 *
 * Cartera del mes = cédulas distintas que tuvieron contrato activo en algún
 * momento del mes o planilla facturada ese mes. Una cédula con tres contratos
 * cuenta una vez. Se unen las dos fuentes porque en ingreso-retiro las fechas
 * del contrato y la factura no siempre caen en el mismo mes.
 */
class AsesorCarteraService
{
    private const ESTADOS_PAGADA = ['pagada', 'abono', 'prestamo'];

    public function comoVan(int $aliadoId, int $anio, int $mes): array
    {
        $periodo = Carbon::create($anio, $mes, 1)->startOfMonth();
        $meses = [$periodo->copy()->subMonths(2), $periodo->copy()->subMonth(), $periodo->copy()];

        $escalera = config("asesores.escaleras.$aliadoId");
        $aliado = Aliado::find($aliadoId);
        $tarifaLista = $this->tarifaLista($aliadoId);

        $carteras = [];
        foreach ($meses as $m) {
            $carteras[$m->format('Y-m')] = $this->carteraDelMes($aliadoId, $m);
        }
        $dinero = $this->dineroDelMes($aliadoId, $anio, $mes);
        $claveMes = $periodo->format('Y-m');

        $filas = Asesor::delAliado($aliadoId)->activos()->orderBy('nombre')->get()
            ->map(function (Asesor $a) use ($carteras, $dinero, $meses, $claveMes, $escalera, $tarifaLista, $periodo, $aliado) {
                $serie = [];
                foreach ($meses as $m) {
                    $c = $carteras[$m->format('Y-m')][$a->id] ?? null;
                    $serie[] = [
                        'label' => $m->locale('es')->isoFormat('MMM'),
                        'n' => $c ? count($c['cedulas']) : 0,
                    ];
                }
                $actual = $carteras[$claveMes][$a->id] ?? ['cedulas' => [], 'facturadas' => 0, 'pagaron' => 0];
                $personas = count($actual['cedulas']);
                $d = $dinero[$a->id] ?? ['admon' => 0, 'com_admon' => 0, 'com_afil' => 0, 'afiliaciones' => 0];

                // La oficina y los que solo refieren no se miden contra la escalera.
                $mide = $escalera && ! in_array($a->tipo_cobro, ['interno', 'referido'], true);
                $nivel = $mide ? $this->nivelPorCartera($escalera['niveles'], $personas) : null;
                $siguiente = $mide ? $this->siguienteNivel($escalera['niveles'], $personas) : null;
                $arranque = $mide ? $this->arranque($escalera, $a, $periodo, $personas) : null;
                $pctActual = $a->comision_admon_tipo === 'porcentaje' ? (float) $a->comision_admon_valor : null;

                return (object) [
                    'asesor' => $a,
                    'serie' => $serie,
                    'personas' => $personas,
                    'facturadas' => (int) $actual['facturadas'],
                    'pagaron' => (int) $actual['pagaron'],
                    'nivel' => $nivel,
                    'siguiente' => $siguiente,
                    'arranque' => $arranque,
                    'tiene' => $this->loQueTiene($a, $pctActual),
                    'estado' => $this->estado($a, $escalera, $personas, $nivel, $siguiente, $arranque, $pctActual, $tarifaLista, $d, $aliado?->nombre ?? 'el aliado'),
                    'admon' => (int) $d['admon'],
                    'comision' => (int) $d['com_admon'] + (int) $d['com_afil'],
                    'afiliaciones' => (int) $d['afiliaciones'],
                ];
            })
            ->sortByDesc('personas')
            ->values();

        return [
            'filas' => $filas,
            'escalera' => $escalera,
            'tarifaLista' => $tarifaLista,
            'periodoLabel' => $periodo->locale('es')->isoFormat('MMMM [de] YYYY'),
            'mesEnCurso' => $periodo->isSameMonth(now()),
            'totales' => [
                'personas' => $filas->where('asesor.tipo_cobro', '!=', 'interno')->sum('personas'),
                'admon' => $filas->sum('admon'),
                'comision' => $filas->sum('comision'),
            ],
        ];
    }

    /** [asesor_id => ['cedulas' => [cedula => true], 'facturadas' => n, 'pagaron' => n]] */
    private function carteraDelMes(int $aliadoId, Carbon $mes): array
    {
        $ini = $mes->copy()->startOfMonth()->toDateString();
        $fin = $mes->copy()->endOfMonth()->toDateString();
        $out = [];

        $activos = DB::table('contratos')
            ->where('aliado_id', $aliadoId)
            ->whereNotNull('asesor_id')
            ->where('fecha_ingreso', '<=', $fin)
            // Hay retirados viejos sin fecha de retiro: sin fecha solo cuenta el que sigue vigente.
            ->where(fn ($q) => $q->where('fecha_retiro', '>=', $ini)
                ->orWhere(fn ($q2) => $q2->whereNull('fecha_retiro')->where('estado', 'vigente')))
            ->select('asesor_id', 'cedula')
            ->distinct()
            ->get();
        foreach ($activos as $r) {
            $out[(int) $r->asesor_id]['cedulas'][(string) $r->cedula] = true;
        }

        $pagada = "'".implode("','", self::ESTADOS_PAGADA)."'";
        $facturadas = DB::table('facturas as f')
            ->join('contratos as c', 'c.id', '=', 'f.contrato_id')
            ->where('f.aliado_id', $aliadoId)
            ->where('c.aliado_id', $aliadoId)
            ->whereNull('f.deleted_at')
            ->where('f.tipo', 'planilla')
            ->where('f.anio', $mes->year)
            ->where('f.mes', $mes->month)
            ->whereNotNull('c.asesor_id')
            ->groupBy('c.asesor_id', 'f.cedula')
            ->selectRaw("c.asesor_id, f.cedula, max(case when f.fecha_pago is not null and f.estado in ($pagada) then 1 else 0 end) as pagada")
            ->get();
        foreach ($facturadas as $r) {
            $id = (int) $r->asesor_id;
            $out[$id]['cedulas'][(string) $r->cedula] = true;
            $out[$id]['facturadas'] = ($out[$id]['facturadas'] ?? 0) + 1;
            $out[$id]['pagaron'] = ($out[$id]['pagaron'] ?? 0) + (int) $r->pagada;
        }

        foreach ($out as &$o) {
            $o += ['cedulas' => [], 'facturadas' => 0, 'pagaron' => 0];
        }

        return $out;
    }

    /** Lo ya pagado del mes: administración que quedó al aliado y comisión del asesor. */
    private function dineroDelMes(int $aliadoId, int $anio, int $mes): array
    {
        $filas = DB::table('facturas as f')
            ->join('contratos as c', 'c.id', '=', 'f.contrato_id')
            ->where('f.aliado_id', $aliadoId)
            ->where('c.aliado_id', $aliadoId)
            ->whereNull('f.deleted_at')
            ->whereNotNull('f.fecha_pago')
            ->whereIn('f.estado', self::ESTADOS_PAGADA)
            ->where('f.anio', $anio)
            ->where('f.mes', $mes)
            ->whereNotNull('c.asesor_id')
            ->groupBy('c.asesor_id')
            ->selectRaw("c.asesor_id,
                sum(case when f.tipo = 'planilla' then cast(f.admon as float) else 0 end) as admon,
                sum(case when f.tipo = 'planilla' then cast(f.admin_asesor as float) else 0 end) as com_admon,
                sum(case when f.tipo = 'afiliacion' then cast(f.dist_asesor as float) else 0 end) as com_afil,
                sum(case when f.tipo = 'afiliacion' then 1 else 0 end) as afiliaciones")
            ->get();

        $out = [];
        foreach ($filas as $r) {
            $out[(int) $r->asesor_id] = [
                'admon' => (float) $r->admon,
                'com_admon' => (float) $r->com_admon,
                'com_afil' => (float) $r->com_afil,
                'afiliaciones' => (int) $r->afiliaciones,
            ];
        }

        return $out;
    }

    /** Lo que más se le cobra de administración a un cliente del aliado (aliado + asesor). */
    private function tarifaLista(int $aliadoId): int
    {
        $r = DB::selectOne(
            "select top 1 cast(administracion + isnull(admon_asesor, 0) as int) v, count(*) n
             from contratos where aliado_id = ? and estado = 'vigente' and administracion > 0
             group by cast(administracion + isnull(admon_asesor, 0) as int) order by n desc",
            [$aliadoId]
        );

        return (int) ($r->v ?? 0);
    }

    private function nivelPorCartera(array $niveles, int $personas): ?array
    {
        $nivel = null;
        foreach ($niveles as $desde => $pct) {
            if ($personas >= $desde) {
                $nivel = ['desde' => (int) $desde, 'pct' => (int) $pct];
            }
        }

        return $nivel;
    }

    private function siguienteNivel(array $niveles, int $personas): ?array
    {
        foreach ($niveles as $desde => $pct) {
            if ($personas < $desde) {
                return ['desde' => (int) $desde, 'pct' => (int) $pct, 'faltan' => (int) $desde - $personas];
            }
        }

        return null;
    }

    /** Meses de arranque: gana el porcentaje más alto mientras cumple las metas. */
    private function arranque(array $escalera, Asesor $a, Carbon $periodo, int $personas): ?array
    {
        if (! $a->fecha_ingreso) {
            return null;
        }
        $inicio = $a->fecha_ingreso->copy()->startOfMonth();
        if ($inicio->gt($periodo)) {
            return null;
        }
        $n = (int) $inicio->diffInMonths($periodo) + 1;
        if ($n > (int) $escalera['arranque_meses']) {
            return null;
        }
        $meta = $escalera['metas_arranque'][$n] ?? null;

        return [
            'mes' => $n,
            'de' => (int) $escalera['arranque_meses'],
            'meta' => $meta,
            'cumple' => $meta === null || $personas >= $meta,
            'pct' => (int) max($escalera['niveles']),
        ];
    }

    private function loQueTiene(Asesor $a, ?float $pctActual): string
    {
        if ($a->tipo_cobro === 'interno') {
            return 'Oficina';
        }
        if ($a->tipo_cobro === 'referido') {
            return $a->comisionAfiliacionLabel().' por afiliación';
        }
        if ($a->tipo_cobro === 'neta') {
            return $a->tarifa_neta ? 'Paga '.$this->cop((float) $a->tarifa_neta).' por persona' : 'Tarifa neta sin valor registrado';
        }
        if ($pctActual !== null) {
            return rtrim(rtrim(number_format($pctActual, 2, ',', '.'), '0'), ',').' % de la administración';
        }

        return (float) $a->comision_admon_valor > 0
            ? $this->cop((float) $a->comision_admon_valor).' fijos por persona'
            : 'Sin comisión de administración';
    }

    /** @return array{tono:string, texto:string} tono: ok | sube | aviso | info | neutro */
    private function estado(Asesor $a, ?array $escalera, int $personas, ?array $nivel, ?array $siguiente, ?array $arranque, ?float $pctActual, int $tarifaLista, array $d, string $aliado): array
    {
        if ($a->tipo_cobro === 'interno') {
            return ['tono' => 'neutro', 'texto' => 'Es la oficina: no aplican niveles.'];
        }
        if ($a->tipo_cobro === 'referido') {
            return ['tono' => 'neutro', 'texto' => 'Solo refiere: gana por cada afiliación que trae. No se mide contra la escalera ni tiene metas.'];
        }
        if (! $escalera) {
            return ['tono' => 'neutro', 'texto' => 'Este aliado no tiene escalera de niveles configurada.'];
        }
        if ($personas === 0 || ! $nivel) {
            return ['tono' => 'neutro', 'texto' => 'Sin personas este mes.'];
        }

        $falta = $siguiente
            ? ($siguiente['faltan'] === 1 ? ' Le falta 1 persona' : ' Le faltan '.$siguiente['faltan'].' personas').' para el '.$siguiente['pct'].' %.'
            : ' Está en el nivel más alto.';

        if ($a->tipo_cobro === 'neta') {
            $hoy = (float) $a->tarifa_neta ?: ($personas ? $d['admon'] / max($personas, 1) : 0);
            $conReparto = $tarifaLista * (1 - $nivel['pct'] / 100);
            $texto = 'Cobra lo suyo por fuera. Si pasara a reparto quedaría en el '.$nivel['pct'].' %: a '.$aliado.' le quedarían '
                .$this->cop($conReparto).' por persona, frente a '.$this->cop($hoy).' de hoy.';
            $texto .= $conReparto < $hoy ? ' Conviene dejarlo en tarifa neta.' : ' En reparto le quedaría más a '.$aliado.'.';

            return ['tono' => 'info', 'texto' => $texto.$falta];
        }

        if ($arranque) {
            $texto = 'Arranque, mes '.$arranque['mes'].' de '.$arranque['de'].': puede tener el '.$arranque['pct'].' % mientras cumpla las metas.';
            if ($arranque['meta'] !== null) {
                $texto .= $arranque['cumple']
                    ? ' Ya cumple la meta de '.$arranque['meta'].' personas al cierre.'
                    : ' Meta al cierre: '.$arranque['meta'].' personas; lleva '.$personas.' (le faltan '.($arranque['meta'] - $personas).').';
            }

            return ['tono' => $arranque['cumple'] ? 'ok' : 'aviso', 'texto' => $texto];
        }

        if ($pctActual === null) {
            $equivale = $tarifaLista ? ' ('.$this->cop($tarifaLista * $nivel['pct'] / 100).' sobre '.$this->cop($tarifaLista).')' : '';

            return ['tono' => 'info', 'texto' => 'Tiene comisión fija. Por cartera le corresponde el '.$nivel['pct'].' %'.$equivale.'.'.$falta];
        }

        if ((int) round($pctActual) === $nivel['pct']) {
            return ['tono' => 'ok', 'texto' => 'Está en su nivel.'.$falta];
        }
        if ($pctActual < $nivel['pct']) {
            return ['tono' => 'sube', 'texto' => 'Puede subir: tiene el '.(int) round($pctActual).' % y por cartera le corresponde el '.$nivel['pct'].' %.'];
        }

        return ['tono' => 'aviso', 'texto' => 'Está por encima de su cartera: tiene el '.(int) round($pctActual).' % y le corresponde el '.$nivel['pct'].' %. Va un mes de aviso antes de bajarlo.'.$falta];
    }

    private function cop(float $v): string
    {
        return '$'.number_format($v, 0, ',', '.');
    }
}
