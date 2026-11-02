<?php

namespace App\Services\Caja;

use App\Models\Gasto;
use App\Models\GastoFijo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Qué gastos fijos faltan por reportar en un mes, y cuáles sugerir.
 *
 * Un fijo cuenta como reportado con los gastos del mes (por `periodo`, o por
 * `fecha` si no tiene) que se registraron desde su aviso (`gasto_fijo_id`) o,
 * a mano, del mismo tipo y con su palabra clave en «pagado a» o en la
 * descripción. El quincenal pide dos; uno solo por el valor del mes también
 * lo cubre. «No aplica este mes» lo saca del mes (gastos_fijos_omitidos).
 */
class GastosFijos
{
    /** Tipos que no son gasto del negocio: los mueve otro módulo. */
    const TIPOS_FUERA = ['pago_planilla', 'efectivo_banco', 'banco_banco', 'ajuste_apertura'];

    public static function hayTablas(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('gastos_fijos');
    }

    /**
     * Los fijos que faltan en el mes. En el mes en curso solo los que ya
     * pasaron su día de pago.
     *
     * @return Collection<int, array{fijo:GastoFijo, veces:int, reportados:int, faltan:int, valor:int}>
     */
    public static function pendientes(int $aliadoId, int $anio, int $mes): Collection
    {
        if (! self::hayTablas()) {
            return collect();
        }

        $inicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
        $fin    = $inicio->copy()->endOfMonth();
        if ($inicio->lt(Carbon::parse(SaldosBanco::PRIMER_MES)) || $inicio->isFuture()) {
            return collect();
        }
        $enCurso = $fin->isFuture();

        $fijos = GastoFijo::where('aliado_id', $aliadoId)->where('activo', true)
            ->where(fn ($q) => $q->whereNull('desde')->orWhereDate('desde', '<=', $fin->toDateString()))
            ->orderBy('dia')->orderBy('concepto')->get();
        if ($fijos->isEmpty()) {
            return collect();
        }

        $omitidos = DB::table('gastos_fijos_omitidos')->whereIn('gasto_fijo_id', $fijos->pluck('id'))
            ->where('anio', $anio)->where('mes', $mes)->pluck('gasto_fijo_id')->map(fn ($id) => (int) $id)->all();

        return self::calcularPendientes($fijos, self::gastosDelMes($aliadoId, $inicio, $fin), $omitidos, $anio, $mes, $enCurso);
    }

    /** El cálculo de pendientes() sin consultas, para poder probarlo. */
    public static function calcularPendientes(Collection $fijos, Collection $gastos, array $omitidos, int $anio, int $mes, bool $enCurso): Collection
    {
        return $fijos->map(function (GastoFijo $f) use ($gastos, $omitidos, $enCurso, $anio, $mes) {
            $veces = $f->vecesEnMes($anio, $mes);
            if ($veces === 0 || in_array((int) $f->id, $omitidos, true)) {
                return null;
            }
            if ($enCurso && $f->dia && $f->dia > today()->day) {
                return null;
            }

            $suyos = $gastos->filter(fn ($g) => self::esDelFijo($g, $f));
            $porValor   = $f->valor > 0 ? intdiv((int) $suyos->sum('valor'), $f->valor) : 0;
            $reportados = max($suyos->count(), $porValor);
            $faltan     = max(0, $veces - $reportados);

            return $faltan > 0 ? [
                'fijo'       => $f,
                'veces'      => $veces,
                'reportados' => min($reportados, $veces),
                'faltan'     => $faltan,
                'valor'      => $faltan * (int) $f->valor,
            ] : null;
        })->filter()->values();
    }

    /**
     * Gastos que se repiten en los últimos tres meses cerrados y que no están
     * en la lista: arranca la configuración llena.
     *
     * @return Collection<int, array>
     */
    public static function sugeridos(int $aliadoId): Collection
    {
        if (! self::hayTablas()) {
            return collect();
        }

        $fin    = today()->startOfMonth()->subDay();
        $inicio = $fin->copy()->subMonths(2)->startOfMonth();

        return self::sugerirDe(self::gastosDelMes($aliadoId, $inicio, $fin), GastoFijo::where('aliado_id', $aliadoId)->get());
    }

    /** El cálculo de sugeridos() sin consultas, para poder probarlo. */
    public static function sugerirDe(Collection $gastos, Collection $fijos): Collection
    {
        return $gastos
            ->reject(fn ($g) => $fijos->contains(fn ($f) => self::esDelFijo($g, $f)))
            ->groupBy(fn ($g) => $g->tipo . '|' . self::normal($g->pagado_a ?: $g->descripcion))
            ->map(function (Collection $items) {
                $porMes = $items->groupBy('mes_ref');
                // Fijo es lo que se repite en al menos dos meses y no más de dos
                // veces al mes (las renovaciones de Cámara de Comercio no lo son).
                if ($porMes->count() < 2 || $porMes->max->count() > 2) {
                    return null;
                }
                $primero = $items->first();
                $ultimo  = $porMes->sortKeys()->last();
                $vecesMes = $porMes->map->count()->avg();
                $meses   = $porMes->keys()->sort()->values()
                    ->map(fn ($m) => (int) substr($m, 0, 4) * 12 + (int) substr($m, 5, 2));
                $saltos  = $meses->slice(1)->values()->map(fn ($m, $i) => $m - $meses[$i])->unique();
                $frecuencia = $vecesMes >= 1.75 ? 'quincenal' : ($saltos->count() === 1 && $saltos->first() === 2 ? 'bimestral' : 'mensual');
                $nombre  = trim($primero->pagado_a ?: $primero->descripcion);
                $dias    = $items->map(fn ($g) => (int) substr((string) $g->fecha, 8, 2))->countBy()->sortDesc();

                return [
                    'concepto'      => Str::limit(self::concepto($primero->tipo, $nombre), 150, ''),
                    'tipo'          => $primero->tipo,
                    'pagado_a'      => $primero->pagado_a,
                    'palabra_clave' => Str::limit($nombre, 100, ''),
                    'valor'         => (int) round($ultimo->sum('valor') / max(1, $frecuencia === 'quincenal' ? 2 : 1)),
                    'frecuencia'    => $frecuencia,
                    'dia'           => (int) $dias->keys()->first(),
                    'forma_pago'    => $primero->forma_pago,
                    'banco_origen_id' => $primero->banco_origen_id,
                    'meses'         => $porMes->count(),
                ];
            })
            ->filter()->sortBy('tipo')->values();
    }

    /** Gastos del negocio de un rango de meses, con el mes al que corresponden. */
    public static function gastosDelMes(int $aliadoId, Carbon $inicio, Carbon $fin): Collection
    {
        $tiposFuera = array_merge(Gasto::TIPOS_INCAPACIDAD, self::TIPOS_FUERA);

        return DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->whereNotIn('tipo', $tiposFuera)
            ->where(fn ($q) => $q->whereNull('forma_pago')->orWhere('forma_pago', '!=', 'banco_banco'))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('periodo')
                    ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString()))
                ->orWhere(fn ($q) => $q->whereDate('periodo', '>=', $inicio->toDateString())->whereDate('periodo', '<=', $fin->toDateString())))
            ->get(array_merge(['id', 'fecha', 'periodo', 'tipo', 'descripcion', 'pagado_a', 'valor', 'forma_pago', 'banco_origen_id'],
                self::hayTablas() ? ['gasto_fijo_id'] : []))
            ->each(function ($g) {
                $g->mes_ref = substr((string) ($g->periodo ?: $g->fecha), 0, 7);
                $g->gasto_fijo_id ??= null;
            });
    }

    private static function esDelFijo(object $g, GastoFijo $f): bool
    {
        if ($g->gasto_fijo_id !== null) {
            return (int) $g->gasto_fijo_id === (int) $f->id;
        }
        if ($g->tipo !== $f->tipo) {
            return false;
        }
        $clave = self::normal($f->palabra_clave ?: ($f->pagado_a ?: $f->concepto));

        return $clave !== '' && (str_contains(self::normal($g->pagado_a), $clave) || str_contains(self::normal($g->descripcion), $clave));
    }

    /** «Salarios · Angela Ortiz»; solo «Arriendo» cuando el nombre ya lo dice. */
    private static function concepto(string $tipo, string $nombre): string
    {
        $etiqueta = Gasto::TIPOS[$tipo] ?? ucfirst($tipo);
        $nombre   = Str::title(mb_strtolower($nombre));
        $a = self::normal($etiqueta);
        $b = self::normal($nombre);

        return str_contains($a, $b) || str_contains($b, $a) ? $nombre : "{$etiqueta} · {$nombre}";
    }

    private static function normal(?string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper(Str::ascii((string) $texto))));
    }
}
