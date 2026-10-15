<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Separa las afiliaciones nuevas de los reingresos. La usan el Resumen
 * Consolidado Mensual, su detalle y la hoja Afiliaciones del Excel de
 * listados del mes, para que los tres den lo mismo.
 */
class ReingresosAfiliacion
{
    /**
     * Qué afiliaciones son reingresos: la cédula tenía otro contrato con el
     * aliado retirado en el mismo mes del ingreso o en el anterior, sea cual
     * sea la razón social. Devuelve [contrato_id nuevo => contrato retirado].
     *
     * Se mira la fecha de retiro, no el período de la factura de retiro: en
     * mes vencido esa factura cae un mes después y cruzaba el ingreso de
     * agosto con un retiro de julio sin decirlo (GiMave, oct-2026).
     *
     * No cuentan:
     * - El contrato retirado que empezó el mismo día o después del nuevo: es
     *   una corrección (contrato mal creado y retirado), no alguien que volvió.
     * - Un mismo retiro para dos afiliaciones del mes: se lo lleva la primera.
     *   Es por mes para que el detalle, que solo ve uno, dé lo mismo.
     */
    public static function porContrato(int $aid, $afiliaciones): array
    {
        if ($afiliaciones->isEmpty()) {
            return [];
        }

        $dia = fn ($f) => substr((string) $f, 0, 10);
        $ingresos = $afiliaciones->map(fn ($a) => $dia($a->fecha_ingreso));
        $desde = Carbon::parse($ingresos->min())->startOfMonth()->subMonth()->toDateString();
        $hasta = Carbon::parse($ingresos->max())->endOfMonth()->toDateString();

        $retirados = DB::table('contratos')
            ->where('aliado_id', $aid)
            ->where('estado', 'retirado')
            ->whereBetween('fecha_retiro', [$desde, $hasta])
            ->orderBy('fecha_retiro')
            ->get(['id', 'cedula', 'fecha_ingreso', 'fecha_retiro'])
            ->groupBy(fn ($r) => trim((string) $r->cedula));

        $usados = [];
        $reingresos = [];
        $orden = $afiliaciones->sortBy(fn ($a) => $dia($a->fecha_ingreso).'|'.str_pad((string) $a->id, 10, '0', STR_PAD_LEFT));
        foreach ($orden as $a) {
            $ingreso = $dia($a->fecha_ingreso);
            $mes = Carbon::parse($ingreso);
            $ventanaIni = $mes->copy()->startOfMonth()->subMonth()->toDateString();
            $ventanaFin = $mes->copy()->endOfMonth()->toDateString();

            foreach ($retirados[trim((string) $a->cedula)] ?? [] as $r) {
                $retiro = $dia($r->fecha_retiro);
                $usado = substr($ingreso, 0, 7).':'.$r->id;
                if ((int) $r->id === (int) $a->id || isset($usados[$usado])
                    || $retiro < $ventanaIni || $retiro > $ventanaFin
                    || $dia($r->fecha_ingreso) >= $ingreso) {
                    continue;
                }
                $usados[$usado] = true;
                $reingresos[$a->id] = $r;
                break;
            }
        }

        return $reingresos;
    }
}
