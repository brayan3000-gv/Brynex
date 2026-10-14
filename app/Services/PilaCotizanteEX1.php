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

    /**
     * Tiempo parcial: ARL del mes completo + caja de 14 días, sin pensión y sin
     * salud. Es el único plan de la modalidad que NO va con el cotizante 01.
     */
    public const PLAN_ARL_CCF = 'ARL_CCF';

    /** Días de caja del plan de tiempo parcial. La ARL siempre va a 30. */
    public const DIAS_CAJA_TP = 14;

    public const PLANES = [
        self::PLAN_SOLO_EPS, self::PLAN_EPS_ARL, self::PLAN_EPS_ARL_CCF, self::PLAN_ARL_CCF,
    ];

    /** Código PILA del único operador que liquida esta modalidad. */
    public const OPERADOR_PILA = '88';

    /** Clave de configuración del cargo de planilla propio de la modalidad. */
    public const CONFIG_OTROS = 'otros_planilla_ex1';

    /** Valor por defecto del cargo, si nadie lo ha configurado. */
    public const OTROS_POR_DEFECTO = 9500;

    /**
     * Cargo de planilla que llevan TODOS los planes de Tipo E - X1.
     *
     * El cliente lo ve como seguridad social —va dentro del total de S. Social
     * del cotizador, no en la administración— y al facturar aterriza en
     * `facturas.otros`, que es la bolsa que suma al total junto a `total_ss`.
     * `otros_admon` es la otra, la de administración, y no es esta.
     *
     * Ojo: NO es el `otros` del tarifario (`afiliacion_arl_modalidad.otros`).
     * Ese es un reparto de la afiliación —público menos retiro, otros y
     * asesor— y meterle este valor descuadraría esa cuenta.
     *
     * Vive en configuración para poder cambiarlo sin desplegar.
     */
    public static function otrosPlanilla(): int
    {
        return (int) \App\Models\ConfiguracionBrynex::obtener(
            self::CONFIG_OTROS, self::OTROS_POR_DEFECTO
        );
    }

    /** ¿Esta modalidad lleva el cargo de planilla? Los cuatro planes de X1. */
    public static function llevaOtros(?int $tipoModalidadId): bool
    {
        return (int) $tipoModalidadId === TipoModalidad::ID_EX1;
    }

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

    /**
     * ¿Esta fila es el plan de tiempo parcial de Tipo E - X1?
     *
     * La bandera `es_tiempo_parcial` es de la modalidad y Tipo E - X1 es de
     * dependientes, así que no puede estar marcada: quien lo decide es el plan.
     * PilaCotizanteCalculator consulta esto para encender su rama de tiempo
     * parcial, que ya produce la forma correcta sin cambiarle nada.
     *
     * Probado contra Simple el 13-oct-2026: cotizante 51 con subtipo 04, días
     * 0/0/30/14, sin AFP y sin EPS, ARL sobre el mínimo completo. Limpio. Con
     * subtipo 00 el validador exige la pensión (`eo.val.2.066`), y con el
     * cotizante 01 se cae con 16 errores: la salud y los parafiscales son
     * obligatorios para el dependiente.
     */
    public static function esTiempoParcial(object $p): bool
    {
        return self::aplica($p) && self::planVendido($p) === self::PLAN_ARL_CCF;
    }

    /** ¿Este plano es de la modalidad Tipo E - X1? */
    public static function aplica(object $p): bool
    {
        return (int) ($p->tipo_modalidad_id ?? 0) === TipoModalidad::ID_EX1;
    }
}
