<?php

namespace App\Services;

use App\Models\Plano;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correcciones N de novedades: una línea ya pagada que vuelve a la planilla
 * con una novedad que le faltó —hoy, el retiro del traslado de razón social—.
 *
 * Se reconocen por `tipo_p = 16` y van en SU PROPIO número de plano, uno por
 * cada planilla que corrigen: una N corrige una sola planilla, y metidas en la
 * tanda original el archivo arrastraba a todos los que ya pagaron y el pago
 * confirmado les pisaba el número. Separadas, Planos SS las liquida y confirma
 * como cualquier tanda; el archivo sale N con el número y la fecha de pago de
 * la planilla corregida (campos 9 y 10 del registro tipo 1).
 */
class CorreccionNovedadesService
{
    public const TIPO_P = 16;

    /**
     * Correcciones sin pagar de una tanda de Planos SS.
     *
     * @param  int  $mesPago  el mes que muestra el filtro (mes de PAGO)
     */
    public static function pendientes(
        int $aliadoId,
        int $razonSocialId,
        int $mesPago,
        int $anioPago,
        int $nPlano,
        array $tiposModalidad = []
    ): Collection {
        return DB::table('planos AS p')
            ->where('p.aliado_id', $aliadoId)
            ->where('p.razon_social_id', $razonSocialId)
            ->where('p.n_plano', $nPlano)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->whereNull('p.deleted_at')
            ->where('p.tipo_p', self::TIPO_P)
            ->where(fn ($q) => $q->whereNull('p.numero_planilla')->orWhere('p.numero_planilla', ''))
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mesPago, $anioPago))
            ->when($tiposModalidad, fn ($q) => $q->whereIn('p.tipo_modalidad_id', array_map('intval', $tiposModalidad)))
            ->get(['p.id', 'p.contrato_id', 'p.razon_social_id', 'p.n_plano', 'p.mes_plano', 'p.anio_plano', 'p.paga_mes_actual']);
    }

    /**
     * La planilla que corrigen: la que pagó esas mismas líneas. Tiene que ser
     * una sola —una N corrige UNA planilla— y tener su pago registrado, porque
     * el operador valida la fecha contra el pago real.
     *
     * @param  Collection  $correcciones  filas con contrato_id, n_plano, mes_plano, anio_plano
     * @return array{numero: string, fecha_pago: string, operador: ?string, operador_planilla_id: ?int}
     *
     * @throws \RuntimeException con el motivo, para mostrarlo tal cual
     */
    public static function planillaAsociada(int $aliadoId, Collection $correcciones): array
    {
        $primera = $correcciones->first();

        $numeros = DB::table('planos')
            ->where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $correcciones->pluck('contrato_id')->unique()->values())
            ->where('mes_plano', $primera->mes_plano)
            ->where('anio_plano', $primera->anio_plano)
            // Sin el número de plano: la corrección vive en el suyo propio.
            ->whereIn('tipo_reg', ['planilla', 'retiro'])
            ->whereNull('deleted_at')
            ->whereRaw('ISNULL(tipo_p, 0) <> ?', [self::TIPO_P])
            ->whereNotNull('numero_planilla')
            ->where('numero_planilla', '<>', '')
            ->pluck('numero_planilla')
            ->map(fn ($n) => trim((string) $n))
            ->unique()
            ->values();

        if ($numeros->isEmpty()) {
            throw new \RuntimeException('No se encontró la planilla pagada que corrigen estas correcciones.');
        }

        if ($numeros->count() > 1) {
            throw new \RuntimeException(
                'Estas correcciones son de planillas distintas (' . $numeros->implode(', ') . '), '
                . 'y cada planilla N corrige una sola. Hay que liquidarlas por separado.'
            );
        }

        $numero = $numeros->first();

        $gasto = DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where('tipo', 'pago_planilla')
            ->where('numero_planilla', $numero)
            ->orderBy('fecha')
            ->first(['fecha', 'pagado_a']);

        if (! $gasto) {
            throw new \RuntimeException(
                "La planilla {$numero} no tiene el pago registrado (el gasto que la pagó), y la corrección N "
                . 'necesita su fecha de pago. Confírmelo primero en Planos SS.'
            );
        }

        // Dónde se pagó: la corrección solo se puede liquidar en ese operador.
        $operadorId = DB::table('operador_planillas_api')
            ->where('aliado_id', $aliadoId)
            ->where('numero_planilla', $numero)
            ->where('estado', 'validada')
            ->orderByDesc('id')
            ->value('operador_planilla_id');

        if (! $operadorId && $gasto->pagado_a) {
            $operadorId = DB::table('operadores_planilla')
                ->whereNull('aliado_id')
                ->where('nombre', trim((string) $gasto->pagado_a))
                ->value('id');
        }

        return [
            'numero'               => $numero,
            'fecha_pago'           => substr((string) $gasto->fecha, 0, 10),
            'operador'             => $gasto->pagado_a ? trim((string) $gasto->pagado_a) : null,
            'operador_planilla_id' => $operadorId ? (int) $operadorId : null,
        ];
    }

    /**
     * Número de plano libre para una corrección del período dado: por encima
     * de todo lo que ya se paga ese mes en la razón social y de su número
     * actual, para no caer en la tanda de nadie.
     *
     * @param  int[]  $ocupados  números ya tomados en esta misma corrida
     */
    public static function siguienteNPlano(
        int $aliadoId,
        int $razonSocialId,
        int $mesPlano,
        int $anioPlano,
        bool $pagaMesActual,
        array $ocupados = []
    ): int {
        // El filtro de Planos SS es por mes de PAGO.
        $pago = \Carbon\Carbon::create($anioPlano, $mesPlano, 1);
        if (! $pagaMesActual) {
            $pago->addMonth();
        }

        $maxTanda = (int) DB::table('planos AS p')
            ->where('p.aliado_id', $aliadoId)
            ->where('p.razon_social_id', $razonSocialId)
            ->whereNull('p.deleted_at')
            ->where('p.n_plano', '<>', 100)   // el 100 es de Ingreso-Retiro
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $pago->month, $pago->year))
            ->max('p.n_plano');

        $actual = (int) DB::table('razones_sociales')->where('id', $razonSocialId)->value('n_plano');

        $n = max($maxTanda, $actual, $ocupados ? max($ocupados) : 0) + 1;
        if ($n === 100) {
            $n++;
        }

        return $n;
    }

    /**
     * La línea A de una N la arma el operador con lo que QUEDÓ PAGADO, y al
     * pagar el operador ajusta la EPS contra la BDUA: si BryNex tenía otra, la
     * C (que sale del plano) no coincide y rechaza con eo.val.2.085 ("La
     * Administradora de Salud EPS010 de la línea A, es diferente a la ... de la
     * linea C"). El error dice cuál se pagó: se lleva al plano de corrección.
     *
     * @param  \Illuminate\Support\Collection  $correcciones  filas con id (planos de la tanda)
     * @return int planos ajustados
     */
    public static function alinearEpsConLoPagado(Collection $correcciones, array $erroresCotizante): int
    {
        $ajustados = 0;

        foreach ($erroresCotizante as $error) {
            if (($error['idRegla'] ?? '') !== 'eo.val.2.085') {
                continue;
            }
            if (! preg_match('/Salud\s+(\S+)\s+de la l[ií]nea A/iu', (string) ($error['descripcion'] ?? ''), $m)) {
                continue;
            }

            $numero = preg_replace('/^\D+/', '', (string) ($error['identificacion'] ?? ''));
            $eps    = DB::table('eps')->where('codigo', $m[1])->orderBy('id')->first(['nit', 'nombre']);
            if ($numero === '' || ! $eps) {
                continue;
            }

            $ajustados += DB::table('planos')
                ->whereIn('id', $correcciones->pluck('id'))
                ->where(DB::raw('CAST(no_identifi AS VARCHAR(20))'), $numero)
                ->where('tipo_p', self::TIPO_P)
                ->where(fn ($q) => $q->whereNull('numero_planilla')->orWhere('numero_planilla', ''))
                ->update(['cod_eps' => (string) $eps->nit, 'nombre_eps' => $eps->nombre, 'updated_at' => now()]);
        }

        return $ajustados;
    }
}
