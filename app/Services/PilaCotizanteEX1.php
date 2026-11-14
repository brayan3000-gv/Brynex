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
     * salud. Vive en su propia modalidad (TipoModalidad::ID_EX1_TP), que sí está
     * marcada como tiempo parcial, así que el motor lo arma sin ayuda de aquí.
     */
    public const PLAN_ARL_CCF = 'ARL_CCF';

    public const PLANES = [
        self::PLAN_SOLO_EPS, self::PLAN_EPS_ARL, self::PLAN_EPS_ARL_CCF, self::PLAN_ARL_CCF,
    ];

    /** Código PILA del único operador que liquida esta modalidad. */
    public const OPERADOR_PILA = '88';

    /**
     * Clave de configuración del antiguo cargo de planilla ($9.500 prorrateado
     * que se facturaba en `otros`). Ya no se lee: desde nov-2026 el cargo es el
     * día de pensión, en `v_afp`. Se deja para que quien encuentre la fila en
     * configuración sepa qué era.
     */
    public const CONFIG_OTROS = 'otros_planilla_ex1';

    /**
     * Un día de pensión sobre el salario mínimo vigente (16 %, redondeo PILA):
     * $9.400 con el mínimo de 2026. Es la misma cuenta de PilaCotizanteE1.
     *
     * La X1 liquida sin pensión (subtipo 04). Si Simple deja de aceptar ese
     * subtipo, el respaldo es la doble planilla de la E-1 —planilla E de 1 día
     * con pensión + corrección N— y ese día ya queda cobrado. Se factura como
     * pensión (`v_afp`, dentro de la seguridad social), igual que el día
     * simbólico de las modalidades de dos pasos, para que al aliado le cuente
     * como SS y no como ingreso suyo.
     *
     * El plano y el TXT NO cambian: la planilla que se liquida sigue sin
     * pensión. La vista de planos lo muestra aparte, como referencia, y lo saca
     * de los totales que se le pagan al operador.
     */
    public static function diaPension(): int
    {
        $smmlv = (int) \App\Models\ConfiguracionBrynex::salarioMinimo();

        return PilaCotizanteCalculator::roundPila(PilaCotizanteE1::ibcUnDia($smmlv) * 0.16);
    }

    /** ¿Es una de las dos caras de Tipo E - X1? */
    public static function esX1(?int $tipoModalidadId): bool
    {
        return in_array((int) $tipoModalidadId, TipoModalidad::IDS_EX1, true);
    }

    /**
     * Pensión que se cobra en un mes de `$dias` cotizados: el día completo si
     * se cotiza al menos un día —no se prorratea, «siempre $9.400»—, cero si no
     * (mes de pura afiliación) o si no es X1. Lo usa Contrato::calcularCotizacion(),
     * que es de donde lo toman la factura, cobros, WhatsApp, visita y cuenta de
     * cobro, y el cotizador del contrato.
     */
    public static function penParaDias(?int $tipoModalidadId, int $dias): int
    {
        return (self::esX1($tipoModalidadId) && $dias > 0) ? self::diaPension() : 0;
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
            if (in_array((int) $tipo, TipoModalidad::IDS_EX1, true)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Este plano es de alguna de las dos caras de Tipo E - X1? */
    public static function aplica(object $p): bool
    {
        return in_array((int) ($p->tipo_modalidad_id ?? 0), TipoModalidad::IDS_EX1, true);
    }
}
