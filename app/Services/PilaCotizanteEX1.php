<?php

namespace App\Services;

use App\Models\TipoModalidad;

/**
 * PilaCotizanteEX1 — Tipo E - X1: salud sin pensión en UNA sola planilla.
 *
 * Es la contraparte de [[PilaCotizanteDosPasos]]. Allá se vende un subsistema
 * suelto y hace falta una planilla E más una corrección N; aquí todo entra en
 * una planilla E ordinaria que el operador liquida de una vez.
 *
 * ## Por qué funciona
 *
 * El subtipo de cotizante 03/04 exime del aporte a pensión, y la rama general
 * de PilaCotizanteCalculator ya lo emite sola cuando el contrato no trae AFP
 * (03 si está exento por edad, 04 si no). O sea que los planes EPS+ARL y
 * EPS+ARL+CCF **no necesitan una línea de código aquí**: el motor ya los arma
 * tal cual el operador los acepta. Probado contra Simple el 13-oct-2026 con
 * las planillas 1085944575 y 1085944610.
 *
 * Lo que esta clase agrega es el tercer plan.
 *
 * ## Solo EPS: la ARL va en cero, pero va
 *
 * El cotizante 01 **no puede dejar de aportar a riesgos** —`eo.val.2.066`— ni
 * reportar menos de 30 días —`2.484` solo admite ING o RET, y `2.673` amarra
 * los días de salud, riesgos y caja—. Así que "Solo EPS" no es una planilla sin
 * ARL: es una planilla con la ARL a 30 días y **tarifa cero**, que es lo que el
 * validador exige cuando hay una novedad de ausentismo (`eo.val.2.447`: "cuando
 * se presenta novedad de ausentismo la tarifa de ARL debe de ser cero").
 *
 * La novedad es VAC-LR = 'L' (licencia remunerada) por el mes completo. Dos
 * trampas de formato, las dos probadas:
 *
 *   - el campo **no acepta 'V'** (`eo.val.2.C027`); solo 'L' o 'X';
 *   - las fechas van en el **período de cotización** (campo 15 del registro
 *     tipo 1, el mes vencido), no en el de salud: fuera de ahí sale
 *     `eo.val.2.429` dos veces, una por fecha.
 *
 * Las escribe PlanoPilaTxtService a partir de `vacMesCompleto`, que es lo único
 * que esta clase necesita decirle.
 *
 * ## Lo que esto le cuesta al trabajador
 *
 * Tarifa cero es aporte cero: la ARL queda reportada con 30 días y sin un peso,
 * y un accidente en ese período se discute con el soporte de que el empleador
 * declaró ausentismo del mes completo. El plan se llama "Solo EPS" justamente
 * porque eso es lo que el cliente paga — la ARL aparece en la planilla, no en la
 * cobertura. Queda escrito aquí para que nadie lo descubra tarde.
 *
 * ## Operador
 *
 * Solo liquida por **Simple (88)**. Enlace consulta la base de la UGPP y
 * rechaza el subtipo 03/04 de quien no figura en ella (`eo.val.2.655`), venga
 * de la empresa que venga.
 */
class PilaCotizanteEX1
{
    /** Los tres planes de la modalidad, en el vocabulario de planes_contrato. */
    public const PLAN_SOLO_EPS = 'SOLO_EPS';

    public const PLAN_EPS_ARL = 'EPS_ARL';

    public const PLAN_EPS_ARL_CCF = 'EPS_ARL_CCF';

    public const PLANES = [self::PLAN_SOLO_EPS, self::PLAN_EPS_ARL, self::PLAN_EPS_ARL_CCF];

    /** Código PILA del único operador que liquida esta modalidad. */
    public const OPERADOR_PILA = '88';

    /**
     * Ajusta lo que la rama general ya calculó. Para EPS+ARL y EPS+ARL+CCF no
     * toca nada: la planilla que el operador acepta es la que el motor ya hace.
     */
    public static function ajustar(array $res, object $p): array
    {
        if (self::planVendido($p) !== self::PLAN_SOLO_EPS) {
            return $res;
        }

        // Los días quedan como están (30 en los tres subsistemas): bajarlos es
        // 2.484 / 2.673. Lo que se pone en cero es la tarifa, y con ella el
        // aporte — la novedad de ausentismo es lo que lo hace válido.
        $res['tarifaArlStr'] = '0.00000';
        $res['tarifaArlDecimal'] = 0.0;
        $res['vArl'] = 0;

        $res['novedades']['VACLR'] = 'L';
        $res['vacMesCompleto'] = true;

        return $res;
    }

    /**
     * Qué vendió el contrato. Sin plan reconocible cae en EPS+ARL, que es el
     * comportamiento de la rama general: el lado que no quita cobertura.
     */
    public static function planVendido(object $p): string
    {
        $codigo = strtoupper(trim((string) ($p->plan_codigo ?? '')));

        return in_array($codigo, self::PLANES, true) ? $codigo : self::PLAN_EPS_ARL;
    }

    /**
     * ¿Esta tanda es de Tipo E - X1?
     *
     * Basta que una modalidad del filtro lo sea: la planilla entera va a un
     * solo operador, así que un cotizante X1 mezclado arrastra a los demás.
     * Que no se mezclen lo impide TipoModalidad::IDS_TANDA_APARTE; esto decide
     * a qué operador puede ir la tanda.
     */
    public static function esTanda(array $tiposModalidad): bool
    {
        foreach ($tiposModalidad as $tipo) {
            if ((int) $tipo === TipoModalidad::ID_EX1) {
                return true;
            }
        }

        return false;
    }

    /** ¿Este plano es de la modalidad Tipo E - X1? */
    public static function aplica(object $p): bool
    {
        return (int) ($p->tipo_modalidad_id ?? 0) === TipoModalidad::ID_EX1;
    }
}
