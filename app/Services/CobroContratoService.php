<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\Factura;
use App\Services\IvaService;
use App\Services\MoraClienteService;

/**
 * Calcula cuánto debe pagar un contrato en un mes/año dado, cuando todavía NO ha sido
 * facturado para ese período (afiliación, planilla, IVA y mora estimada incluidos).
 *
 * Extraído de FacturacionController::cotizacionContrato para reutilizarse también desde
 * la tool de IA (consultar_cliente) sin duplicar esta lógica — la misma que alimenta la
 * pantalla /admin/cobros.
 */
class CobroContratoService
{
    /**
     * @return array{
     *   tipo: string, eps: int, arl: int, afp: int, caja: int, ss: int,
     *   admon: int, seguro: int, afiliacion: int, iva: int, mora: int,
     *   total: int, dias: int
     * }
     */
    public static function calcular(Contrato $contrato, int $mes, int $anio): array
    {
        // Solo seguro: no cotiza nada, no paga administración y no genera planilla.
        // El mes vale lo que valga el seguro que se le vendió, y punto.
        if ($contrato->esSoloSeguro()) {
            $seguro = (int) ($contrato->seguro ?? 0);

            return [
                'tipo'       => 'planilla',   // es el cobro mensual, no una afiliación
                'eps'        => 0,
                'arl'        => 0,
                'afp'        => 0,
                'caja'       => 0,
                'parafiscales' => 0,
                'ss'         => 0,
                'admon'      => 0,
                'seguro'     => $seguro,
                'afiliacion' => 0,
                'iva'        => 0,
                'mora'       => 0,            // sin planilla que pagar tarde no hay mora
                'total'      => $seguro,
                'dias'       => 0,
            ];
        }

        // Detectar tipo
        $esIndependiente = $contrato->tipoModalidad?->esIndependiente() ?? false;
        $esIndAct        = (bool) ($contrato->paga_mes_actual ?? false);
        $esArl           = (int) $contrato->tipo_modalidad_id === 15;
        $esMesIngreso    = false;

        if ($esArl) {
            // Gestión ARL siempre es cobro de afiliación, no planilla
            $esMesIngreso = true;
        } elseif ($contrato->fecha_ingreso) {
            $esMesIngreso = (int) $contrato->fecha_ingreso->month === $mes
                && (int) $contrato->fecha_ingreso->year === $anio;
        }

        // El primer mes es afiliación pura salvo que el contrato cotice el mes en
        // curso: ese cobra afiliación y planilla juntas. Lo decide el flag y no la
        // modalidad — la Y de solo ARL también puede pagar mes actual y no está
        // entre las independientes, así que preguntar por la modalidad la mandaba
        // a afiliación pura ignorando el flag.
        $esAfiliacion = false;
        if ($esMesIngreso && ($esArl || ! $esIndAct)) {
            $esAfiliacion = true;
        }
        $esIndActPrimerMes = $esIndAct && $esMesIngreso;

        if ($esArl && !$esMesIngreso) {
            $diasCotizar = 0;
            $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0, 'ss' => 0];
            $afiliacion  = 0;
            $seguro      = 0;
            $admon       = 0;
            $admonAsesor = 0;
            $iva         = 0;
            $total       = 0;
        } else {
            // Calcular días
            if ($esIndActPrimerMes) {
                $diasCotizar = max(1, 30 - (int) $contrato->fecha_ingreso->day + 1);
            } elseif ($esAfiliacion) {
                $diasCotizar = 0;
            } else {
                $diasCotizar = self::calcularDias($contrato, $mes, $anio);
            }

            // Calcular SS
            if ($esAfiliacion && !$esIndActPrimerMes) {
                $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0, 'ss' => 0];
            } else {
                $cotizacion = $contrato->calcularCotizacion($diasCotizar);
                $calcSS = [
                    'eps'  => (int) ($cotizacion['eps']  ?? 0),
                    'arl'  => (int) ($cotizacion['arl']  ?? 0),
                    'afp'  => (int) ($cotizacion['pen']  ?? 0),
                    'caja' => (int) ($cotizacion['caja'] ?? 0),
                    'parafiscales' => (int) ($cotizacion['parafiscales'] ?? 0),
                    'ss'   => (int) ($cotizacion['ss']   ?? 0),
                ];
            }

            $afiliacion  = ($esAfiliacion || $esIndActPrimerMes) ? (int) ($contrato->costo_afiliacion ?? 0) : 0;
            $seguro      = (int) ($contrato->seguro ?? 0);
            $admon       = ($esAfiliacion && !$esIndActPrimerMes) ? 0 : (int) ($contrato->administracion ?? 0);
            $admonAsesor = ($esAfiliacion && !$esIndActPrimerMes) ? 0 : (int) ($contrato->admon_asesor ?? 0);

            // IVA — grava administración y costo de afiliación (ver IvaService)
            $iva = IvaService::deFactura(
                IvaService::aplicaContrato($contrato),
                $admon,
                $admonAsesor,
                $afiliacion
            );

            $total = $calcSS['ss'] + $admon + $admonAsesor + $seguro + $afiliacion + $iva;
        }

        // Mora estimada. No la generan las afiliaciones (no hay pago de planilla)
        // ni quien cotiza el mes en curso: ese paga por adelantado el período que
        // está corriendo, así que no hay plazo vencido que penalizar.
        $mora = 0;
        if (! $esAfiliacion && ! $esIndAct) {
            try {
                $rsNit   = $contrato->nitParaMora();
                $rsDia   = $contrato->diaHabilParaMora();
                if ($rsNit && $calcSS['ss'] > 0) {
                    $moraInfo = MoraClienteService::calcular($contrato->aliado_id, $rsNit, $rsDia, $calcSS['ss'], $mes, $anio);
                    $mora = (int) ($moraInfo['mora'] ?? 0);
                }
            } catch (\Throwable) {
                // Mora es solo estimada; si falla, se omite sin bloquear el cálculo principal.
            }
        }

        return [
            'tipo'       => $esAfiliacion ? 'afiliacion' : 'planilla',
            'eps'        => $calcSS['eps'],
            'arl'        => $calcSS['arl'],
            'afp'        => $calcSS['afp'],
            'caja'       => $calcSS['caja'],
            'parafiscales' => $calcSS['parafiscales'],
            'ss'         => $calcSS['ss'],
            'admon'      => $admon + $admonAsesor,
            'seguro'     => $seguro,
            'afiliacion' => $afiliacion,
            'iva'        => $iva,
            'mora'       => $mora,
            'total'      => $total + $mora,
            'dias'       => $diasCotizar,
        ];
    }

    /** @return bool Si el contrato ya tiene una factura (no anulada) para ese mes/año. */
    public static function yaFacturado(Contrato $contrato, int $mes, int $anio): bool
    {
        return Factura::where('aliado_id', $contrato->aliado_id)
            ->where('cedula', $contrato->cedula)
            ->where('razon_social_id', $contrato->razon_social_id)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNotIn('estado', ['anulada'])
            ->exists();
    }

    public static function calcularDias(Contrato $contrato, int $mes, int $anio): int
    {
        // ── GESTIÓN ARL (id=15): SIEMPRE días=0, nunca planilla ─────────────
        if ((int) $contrato->tipo_modalidad_id === 15) {
            return 0;
        }

        // Tiempo Parcial: se devuelve 30 porque ARL cotiza mensual completo.
        $mod = $contrato->tipoModalidad;
        if ($mod && $mod->esTiempoParcial()) {
            return 30;
        }

        if (!$contrato->fecha_ingreso) return 30;
        $fIng        = $contrato->fecha_ingreso;
        $mesIngreso  = (int) $fIng->month;
        $anioIngreso = (int) $fIng->year;

        // ⓪ Ingreso en mes futuro → el contrato aún no inicia en este período
        $periodoIngreso = $anioIngreso * 100 + $mesIngreso;
        $periodoActual  = $anio * 100 + $mes;
        if ($periodoIngreso > $periodoActual) {
            return 0;
        }

        // ① Mes de ingreso → afiliación, no hay días de planilla.
        //    Salvo que el contrato cotice el mes en curso: ese paga afiliación y
        //    planilla juntas desde el mes de ingreso, y su planilla es proporcional
        //    a los días que alcanza a estar activo (ingresa el 28 → 3 días).
        //    Devolver 0 aquí dejaba al modal de facturar cotizando el mes completo:
        //    el JS lee dias_sugeridos con `parseInt(x) || 30`, así que el 0 se volvía
        //    30 y pisaba los días que el cotizador ya había calculado bien.
        if ($mesIngreso === $mes && $anioIngreso === $anio) {
            if ((bool) ($contrato->paga_mes_actual ?? false)) {
                return max(1, 30 - $fIng->day + 1);
            }

            return 0;
        }

        // ② Mes siguiente al ingreso → primera planilla: días activos del mes de ingreso
        $mesAnterior  = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;
        if ($mesIngreso === $mesAnterior && $anioIngreso === $anioAnterior) {
            // EXCEPCIÓN 1: Independiente Activo (11) ya cobró su planilla en el mes de ingreso.
            if ((bool) ($contrato->paga_mes_actual ?? false)) {
                return 30;
            }
            // EXCEPCIÓN 2: Si ya existe una factura de tipo 'planilla' pagada para el mes de ingreso.
            $existePlanillaIngreso = Factura::where('aliado_id', $contrato->aliado_id)
                ->where('contrato_id', $contrato->id)
                ->where('mes', $mesIngreso)
                ->where('anio', $anioIngreso)
                ->where('tipo', 'planilla')
                ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
                ->exists();
            if ($existePlanillaIngreso) {
                return 30;
            }

            return max(1, 30 - $fIng->day + 1);
        }

        // ③ Mes normal
        return 30;
    }
}
