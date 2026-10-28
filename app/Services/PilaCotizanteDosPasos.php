<?php

namespace App\Services;

use App\Models\ConfiguracionBrynex;
use App\Models\TipoModalidad;

/**
 * PilaCotizanteDosPasos — lo que se vende suelto y se paga en dos planillas
 * encadenadas. Hoy es la modalidad **Tipo E - Extras** (-12) y, por herencia,
 * las tres que reemplazó: Solo Caja 30 (-5), Solo Caja 14 (-10) y Solo
 * Pensión 30 (-11), inactivas pero con contratos y planos vivos.
 *
 *   paso 1 — planilla E con un día de pensión y nada más. Se paga.
 *   paso 2 — planilla N que corrige la anterior y anexa lo que se vendió.
 *
 * El paso 1 es **casi el mismo archivo para todos**; lo que cambia es qué sube
 * la corrección. Por eso viven juntos aquí.
 *
 * **Quién manda es el plan del contrato, no la modalidad** (TipoModalidad::
 * PLANES_EXTRAS): la modalidad dice cómo se paga —dos planillas— y el plan dice
 * qué se paga. Los cinco planes:
 *
 *   SOLO_EPS      salud del mes                corrección por el PORTAL
 *   EPS_ARL       salud y riesgos del mes      corrección por el PORTAL
 *   SOLO_CCF_14   caja de 14 días              corrección por API
 *   SOLO_CCF_30   caja del mes                 corrección por API
 *   SOLO_AFP      pensión del mes              corrección por API
 *
 * Los dos planes de salud no se pueden corregir por archivo plano: el
 * validador de la API responde eo.val.2.198 y 2.244 (días e IBC iguales entre
 * pensión, salud y riesgos) aunque el portal web del mismo operador liquide esa
 * misma corrección sin una queja. Probado en Simple el 17-sep-2026.
 *
 * ## Por qué hacen falta dos planillas, y por qué la salud va por el portal
 *
 * La coherencia entre subsistemas no es la misma regla en una planilla nueva
 * que en una corrección, y ahí está todo el juego:
 *
 *   planilla nueva (E) → `eo.val.2.673`: pensión, riesgos y **caja** iguales
 *   corrección    (N) → `eo.val.2.198` / `2.244`: pensión, salud y riesgos iguales
 *
 * La salud queda fuera de las dos gracias a la marca de colombiano en el
 * exterior —que no la exime del cotejo, sino que le permite ir en CERO—. De ahí:
 *
 *   - la **caja** solo se puede vender sola en una corrección, porque en una
 *     planilla nueva el 2.673 obligaría a subir pensión y riesgos con ella;
 *   - la **pensión** arrastra los riesgos, que con la tarifa en cero no cuestan
 *     nada, y obliga a un día de caja porque la línea C no la admite en cero
 *     (`eo.val.2.050` / `2.046`);
 *   - la **salud** no se puede vender sola *por archivo plano*: en cuanto tiene
 *     días, el 2.198/2.244 le arrastra la pensión completa. Por el portal sí,
 *     porque ese cotejo lo hace únicamente el validador de archivos. De ahí que
 *     su paso 1 vaya sin VAC-LR, con la ARL cobrando su día y la caja con el
 *     suyo: es la forma que Simple liquidó y se pagó (planilla 1085268529).
 *
 * Probado contra ARUS Enlace y Simple el 9-sep-2026 sobre planillas de paso 1
 * ya pagadas: las dos correcciones validaron con cero errores y el operador
 * cobró exactamente lo vendido —y en la pensión, solo la diferencia contra el
 * día ya pagado—.
 *
 * ## Cómo se retira el día que deje de servir
 *
 * Es un rodeo a una validación del operador, no una regla PILA: se supone
 * temporal. Para apagarlo **hoy mismo, sin desplegar**, basta vaciar
 * `TipoModalidad::ALIADOS_POR_MODALIDAD` —dejan de ofrecerse en todos los
 * aliados— o poner `activo = 0` en las tres filas del catálogo, que además
 * deja intactos los contratos y planos ya hechos.
 *
 * Para borrarlo de raíz, esto es todo lo que existe:
 *
 *   1. Esta clase y PlanillaDosPasosService.
 *   2. Las constantes ID_CAJA_30 / ID_CAJA_14 / ID_PENSION_30, IDS_SOLO_CAJA,
 *      IDS_SOLO_PENSION, IDS_DOS_PASOS y ALIADOS_POR_MODALIDAD, más el bloque
 *      del `foreach` en TipoModalidad::scopeActivos.
 *   3. El `if (in_array(..., IDS_DOS_PASOS))` de PilaCotizanteCalculator.
 *   4. En PlanoPilaTxtService: el `continue` del valor total de nómina y el
 *      `forzarSinSalud` del código de EPS (el `forzarIng` sin fecha lo usa
 *      también la E-1).
 *   5. En PlanillaApiController: la guarda de tanda mezclada, la rama del paso
 *      2 y el reintento con la fecha del operador.
 *   6. En planos/index.blade.php: el aviso de tanda mezclada y la etiqueta del
 *      botón del paso 2.
 *   7. Las filas -5, -10 y -11 de `tipo_modalidad` y sus `modalidad_planes`.
 *
 * Ninguna planilla normal pasa por aquí: todo lo anterior está detrás de un
 * `in_array($modalidad, IDS_DOS_PASOS)`.
 *
 * ## Por qué vive en su propia clase
 *
 * Misma razón que PilaCotizanteE1: sus valores contradicen los de cualquier
 * otra modalidad —un día de pensión que nadie compró, salud en cero, riesgos
 * reportados con tarifa cero— y no comparte nada con la rama general salvo el
 * punto de partida. No calcula desde cero: recibe el resultado de la rama
 * general y solo pisa lo que cambia.
 */
class PilaCotizanteDosPasos
{
    /** COLPENSIONES: administradora del día simbólico de pensión. */
    private const AFP_POR_DEFECTO = '25-14';

    /**
     * Días de caja de la corrección de Solo Pensión.
     *
     * No es una venta: la línea C rechaza la caja en cero (`eo.val.2.050` y
     * `2.046`, "los días cotizados a Cajas de Compensación Familiar no son
     * válidos (0), deben ser mayores a cero"). Un día es el mínimo que la deja
     * pasar, y son $2.400 sobre el salario mínimo.
     */
    private const DIAS_CAJA_MINIMOS = 1;

    /**
     * IBC de ese día de caja.
     *
     * El operador **deja modificar el IBC de caja**: reportado en 100, el 4%
     * redondeado a la centena son $100 en vez de los $2.400 que costaría el día
     * proporcional. Es la misma convención que BryNex ya usa para quien no tiene
     * caja (CCF68), y arrastra la misma advertencia benigna: `eo.val.2.261`,
     * "el IBC reportado a Caja es 100 y debería ser X". Advertencia, no error:
     * probado y aceptado el 9-sep-2026.
     */
    private const IBC_CAJA_MINIMO = 100;

    /**
     * @param  array  $res  Resultado de PilaCotizanteCalculator (rama general)
     * @param  object  $p  Fila del plano; `paso_e1` (1|2) decide qué planilla
     * @param  int  $ibcFull  Salario completo
     * @param  string  $codAfpPila  Código PILA de la AFP que trae el plano (puede venir vacío)
     */
    public static function ajustar(array $res, object $p, int $ibcFull, string $codAfpPila): array
    {
        // `paso_e1` es la columna que PlanoPilaTxtService usa para decir qué
        // línea de la corrección se está armando: 1 = la A (lo que ya quedó
        // pagado) y 2 = la C (lo corregido). En una planilla E normal siempre
        // vale 1. El nombre viene de la E-1, que fue la primera en necesitarlo.
        $paso = ((int) ($p->paso_e1 ?? 1) === 2) ? 2 : 1;
        $plan = self::planVendido($p);
        $vendeSalud = self::vendeSalud($plan);
        $ibcUnDia = self::ibcUnDia($ibcFull);

        // ── Pensión: un día, salvo que sea justo lo que se vendió ────────
        //
        // El día simbólico no es un aporte que el cliente compró: es el mínimo
        // que el operador exige para aceptar el registro. Sin pensión —o con la
        // pensión en cero días— la planilla se cae con eo.val.2.066 ("el tipo
        // de cotizante 01 está obligado a cotizar a Pensión").
        //
        // La AFP sale de la ficha del cliente y no del contrato: cuando el plan
        // no vende pensión, la del contrato no dice nada. El día simbólico
        // igual necesita una administradora o el registro se rechaza.
        $res['tienePension'] = true;
        $res['codAfpPila'] = self::afpDelCliente($p, $codAfpPila);
        $res['subtipoCotizante'] = 0;
        $res['diasPension'] = 1;
        $res['ibcAfp'] = $ibcUnDia;
        $res['tarifaAfpDecimal'] = 0.16;
        $res['vAfp'] = PilaCotizanteCalculator::roundPila($ibcUnDia * 0.16);

        // ── Salud: en cero, con la marca de colombiano en el exterior ────
        //
        // Es la marca la que hace válida la salud en cero: quien está fuera del
        // país no cotiza salud en Colombia. Sin ella salen cinco errores que
        // dicen lo mismo de cinco formas (2.066, 2.043, 2.046, 2.673 y 2.198).
        // Va en las dos planillas: en estas modalidades la salud no llega nunca.
        $res['colombianoExterior'] = true;
        $res['forzarSinSalud'] = true;
        $res['codEpsPila'] = '';
        $res['diasSalud'] = 0;
        $res['ibcEps'] = 0;
        $res['tarifaEpsStr'] = '0.00000';
        $res['vEps'] = 0;

        // ── Riesgos: se reportan, no se cobran ───────────────────────────
        //
        // Acompañan a la pensión en días e IBC porque el operador los amarra
        // (eo.val.2.244 y 2.198), y la tarifa en cero la obliga la novedad de
        // ausentismo (eo.val.2.447). Así el día —o el mes— de riesgos no cuesta
        // nada en ninguna de las dos modalidades.
        //
        // La tarifa real se guarda antes de pisarla: los planes que venden
        // salud sí la cobran (ver más abajo) y la corrección de pensión no.
        $tarifaArlReal = (string) ($res['tarifaArlStr'] ?? '0.00000');

        $res['diasArl'] = 1;
        $res['ibcArl'] = $ibcUnDia;
        $res['tarifaArlStr'] = '0.00000';
        $res['tarifaArlDecimal'] = 0.0;
        $res['vArl'] = 0;

        // ── Novedades que sostienen el archivo ───────────────────────────
        //
        // ING justifica ante el validador que se coticen menos de 30 días: sin
        // ING ni RET, Enlace rechaza con eo.val.2.151 y su equivalente en
        // riesgos, eo.val.2.484. VAC-LR (licencia remunerada) es la novedad de
        // ausentismo que explica el resto del mes; es también la que obliga a
        // que la tarifa de riesgos vaya en cero.
        $res['forzarIng'] = true;
        $res['novedades'] = array_merge($res['novedades'] ?? [], ['VACLR' => 'L']);
        $res['horasLaboradas'] = 0;

        // ── Caja: depende de si la corrección va a tocarla ───────────────
        //
        // Cuando lo vendido es la caja, va en cero: el aporte entra completo en
        // la corrección y el cliente lo paga una sola vez. Enlace acepta la
        // planilla E con la caja en cero —probado y pagado— aunque en la línea
        // C de una corrección ya no la admita (eo.val.2.050).
        //
        // Cuando lo vendido es la salud, la corrección no vuelve a tocar la
        // caja, así que el paso 1 va con su día: es la forma exacta que Simple
        // liquidó y se pagó el 16-sep-2026 (planilla 1085268529).
        //
        // "Solo EPS" prueba el paso 1 más barato (18-sep-2026): solo el día de
        // pensión, con la caja en cero y la ARL con tarifa cero por VAC-LR,
        // igual que Solo Caja y Solo Pensión. Ver paso1PagaCajaYArl().
        $pagaCajaYArl = self::paso1PagaCajaYArl($plan);

        $res['diasCcf'] = $pagaCajaYArl ? 1 : 0;
        $res['ibcCcf'] = $pagaCajaYArl ? $ibcUnDia : 0;
        $res['vCcf'] = $pagaCajaYArl
            ? PilaCotizanteCalculator::roundPila($ibcUnDia * (float) $res['tarifaCcfStr'])
            : 0;

        // La salud se vende con la ARL cobrando su día: el paso 1 de esos
        // planes no lleva la novedad de ausentismo, que es la que obliga a la
        // tarifa cero (eo.val.2.447). Sin VAC-LR, el archivo pasa igual.
        if ($pagaCajaYArl) {
            $res['novedades'] = array_diff_key($res['novedades'] ?? [], ['VACLR' => null]);
            $res['tarifaArlStr'] = $tarifaArlReal;
            $res['tarifaArlDecimal'] = (float) $tarifaArlReal;
            $res['vArl'] = PilaCotizanteCalculator::roundPila($ibcUnDia * (float) $tarifaArlReal);
        }

        if ($paso === 1) {
            return $res;
        }

        // ── La corrección: aquí se anexa lo que se vendió ────────────────
        //
        // Sin caja propia (la convención CCF68) no hay nada que anexar y los
        // $100 de la rama general se quedan como están: estas modalidades no se
        // le deberían vender a quien no tiene caja.
        $sinCaja = (bool) ($res['sinCaja'] ?? false);

        // ── Salud (y riesgos, si el plan los incluye) ────────────────────
        //
        // Aquí se cae la marca de colombiano en el exterior —que es lo que
        // sostenía la salud en cero— y entra la EPS con el mes completo. La
        // pensión se queda en su día y la caja en lo que ya se pagó.
        //
        // Esta corrección NO la acepta la API: el validador de archivos planos
        // la rechaza con eo.val.2.198 / 2.244 (días e IBC iguales entre
        // pensión, salud y riesgos). El portal web del mismo operador sí la
        // liquida — probado en Simple el 17-sep-2026 con las planillas
        // 1085286603 (solo salud) y 1085286356 (salud y riesgos)—, así que
        // estos dos planes se corrigen por ahí. Ver TipoModalidad::PLANES_EXTRAS.
        if ($vendeSalud) {
            $dias = self::diasDelPlan($plan);

            $res['colombianoExterior'] = false;
            $res['forzarSinSalud'] = false;
            $res['codEpsPila'] = (string) ($p->cod_eps_pila ?? $res['codEpsPila'] ?? '');
            $res['diasSalud'] = $dias;
            $res['ibcEps'] = self::ibcProporcional($ibcFull, $dias);
            $res['tarifaEpsStr'] = '0.04000';
            $res['vEps'] = PilaCotizanteCalculator::roundPila($res['ibcEps'] * 0.04);

            // Los riesgos suben al mes con la salud en los dos planes. Simple
            // guarda una corrección con salud a 30 días y riesgos a 1, pero no
            // deja pagarla: "los días de pensión, salud y riesgos deben ser
            // iguales (1, 30, 1)" (planilla 1085295297, 17-sep-2026). La forma
            // que sí se paga es 1 / 30 / 30 / 1 —pensión, salud, riesgos,
            // caja—: la de Juan Carlos Castro (SUPPLIESALUD, planilla
            // 1084672324, pagada el 27-ago-2026). La pensión sí puede quedarse
            // en su día.
            //
            // La tarifa es la real cuando el paso 1 no llevó VAC-LR; con VAC-LR
            // (paso 1 solo pensión) sigue en cero, que es lo que esa novedad
            // exige (eo.val.2.447).
            $res['diasArl'] = $dias;
            $res['ibcArl'] = $res['ibcEps'];

            if ($pagaCajaYArl) {
                $res['tarifaArlStr'] = $tarifaArlReal;
                $res['tarifaArlDecimal'] = (float) $tarifaArlReal;
                $res['vArl'] = PilaCotizanteCalculator::roundPila($res['ibcArl'] * (float) $tarifaArlReal);
            }

            return $res;
        }

        if ($plan === 'SOLO_AFP') {
            // Pensión al mes vendido, y los riesgos con ella porque el operador
            // los amarra. El operador cobra solo la diferencia contra el día
            // que ya se pagó en el paso 1.
            $dias = self::diasDelPlan($plan, $p, 'dias_afp');

            $res['diasPension'] = $dias;
            $res['ibcAfp'] = self::ibcProporcional($ibcFull, $dias);
            $res['vAfp'] = PilaCotizanteCalculator::roundPila($res['ibcAfp'] * 0.16);

            $res['diasArl'] = $dias;
            $res['ibcArl'] = $res['ibcAfp'];

            // El día de caja que la línea C exige para no ir en cero, al IBC
            // mínimo: son $100, no los $2.400 del día proporcional.
            if (! $sinCaja) {
                $res['diasCcf'] = self::DIAS_CAJA_MINIMOS;
                $res['ibcCcf'] = self::IBC_CAJA_MINIMO;
                $res['vCcf'] = PilaCotizanteCalculator::roundPila(
                    self::IBC_CAJA_MINIMO * (float) $res['tarifaCcfStr']
                );
            }

            return $res;
        }

        // Caja: los días los fija el plan y no el contrato —"Solo CCF 30" y
        // "Solo CCF 14" son la misma cosa con distinto número de días; en las
        // modalidades viejas eso vivía en `tipo_modalidad.dias_caja`—.
        //
        // El IBC es el salario del contrato **tal cual, sin prorratear**: en
        // estas modalidades el salario que se guarda ya es el proporcional a lo
        // que se vende, igual que en Tiempo Parcial —un TP(14) se guarda con
        // 875.453, no con el mínimo completo— y el piso de validación es esa
        // misma fracción (ver TipoModalidad::factorSalario). Prorratearlo otra
        // vez por 14/30 lo partía a la mitad dos veces y el plano salía con
        // 408.545 en lugar de los 875.453 que son media jornada del mínimo
        // (SMMLV/4 × 2 semanas del Decreto 2616).
        if (! $sinCaja) {
            $res['diasCcf'] = self::diasDelPlan($plan, $p, 'dias_caja');
            $res['ibcCcf'] = $ibcFull;
            $res['vCcf'] = PilaCotizanteCalculator::roundPila(
                $res['ibcCcf'] * (float) $res['tarifaCcfStr']
            );

            // Horas del mes que respaldan ese salario, las mismas que se le
            // declaran a la caja al afiliar: Comfandi exige sueldo = salario ×
            // horas / 240, así que "Solo CCF 14" (medio mínimo) son 4 h × 30 =
            // 120. Con el 0 del paso 1 la línea no decía cuánto se trabajó.
            $res['horasLaboradas'] = (int) round(
                240 * (TipoModalidad::FACTOR_SALARIO_POR_DIAS[$res['diasCcf']] ?? $res['diasCcf'] / 30)
            );
        }

        return $res;
    }

    /**
     * IBC del día simbólico de pensión y riesgos. Lo necesita el registro tipo
     * 1 para sumar el valor total de la nómina sin volver a pasar por el
     * calculador.
     *
     * Nunca por debajo del mínimo completo entre 30: en "Solo CCF 14" el
     * salario es medio mínimo (875.453) y su día daba 29.182, que el operador
     * rechaza con eo.val.2.201 ("El IBC de Pensión no puede ser menor al mínimo
     * proporcional a los días que es de 58364") — contrato 57008, 18-sep-2026.
     * La media jornada es de la caja; el día de pensión es un día entero.
     */
    public static function ibcUnDia(int $ibcFull): int
    {
        return (int) round(max($ibcFull, ConfiguracionBrynex::salarioMinimo()) / 30);
    }

    /**
     * Qué vendió el contrato, en el vocabulario de TipoModalidad::PLANES_EXTRAS.
     *
     * Manda el código del plan, que es el dato nuevo. Las tres modalidades
     * viejas —Caja 30, Caja 14 y Pensión 30— quedaron inactivas pero sus
     * contratos y planos siguen vivos, y llegan aquí sin plan de esta familia:
     * se traducen a su equivalente para que sigan liquidando igual que antes.
     */
    public static function planVendido(object $p): string
    {
        $codigo = strtoupper(trim((string) ($p->plan_codigo ?? '')));

        if (isset(TipoModalidad::PLANES_EXTRAS[$codigo])) {
            return $codigo;
        }

        $modalidad = (int) ($p->tipo_modalidad_id ?? 0);

        if (in_array($modalidad, TipoModalidad::IDS_SOLO_PENSION, true)) {
            return 'SOLO_AFP';
        }

        if (in_array($modalidad, TipoModalidad::IDS_SOLO_CAJA, true)) {
            return ((int) ($p->dias_caja ?? 30)) <= 14 ? 'SOLO_CCF_14' : 'SOLO_CCF_30';
        }

        return 'SOLO_CCF_30';
    }

    /** ¿El plan vende salud? Esos son los que solo se corrigen por el portal. */
    /**
     * ¿El paso 1 de este plan paga su día de caja y de ARL (sin VAC-LR)?
     *
     * Es la forma con la que Simple liquidó y se pagó el primer Solo EPS
     * (planilla 1085268529, $12.500). "Solo EPS" pasa a probar la forma más
     * barata —solo el día de pensión—, que es la de Solo Caja y Solo Pensión;
     * si el portal no acepta la corrección de salud sobre ella, basta sacar
     * SOLO_EPS de esta lista. "EPS y ARL" no puede: con VAC-LR la ARL va en
     * tarifa cero (eo.val.2.447) y la corrección no podría subirla al mes.
     */
    public static function paso1PagaCajaYArl(string $plan): bool
    {
        return self::vendeSalud($plan) && ! in_array($plan, self::PASO1_SOLO_PENSION, true);
    }

    /**
     * Planes de salud cuyo paso 1 es solo el día de pensión.
     *
     * Vacío por ahora: Yuly (contrato 57054) pagó su paso 1 con caja y ARL, y
     * su corrección tiene que repetir esa línea A tal cual. Se agrega
     * SOLO_EPS cuando su corrección esté pagada, para probar con Yesenia
     * (contrato 57056).
     */
    private const PASO1_SOLO_PENSION = [];

    public static function vendeSalud(string $plan): bool
    {
        return in_array($plan, ['SOLO_EPS', 'EPS_ARL'], true);
    }

    /** ¿La corrección de este plan se puede liquidar contra la API del operador? */
    public static function correccionPorApi(string $plan): bool
    {
        return (bool) (TipoModalidad::PLANES_EXTRAS[$plan]['api'] ?? true);
    }

    /**
     * Los días que vende el plan. Abrir una variante nueva —caja de 20 días,
     * pensión de 15— es una fila en `planes_contrato` más su entrada en
     * PLANES_EXTRAS, no una línea de lógica.
     *
     * `$columna` es el respaldo de las modalidades viejas, que traen sus días
     * en el catálogo (`tipo_modalidad.dias_caja` / `dias_afp`).
     */
    private static function diasDelPlan(string $plan, ?object $p = null, ?string $columna = null): int
    {
        $dias = TipoModalidad::PLANES_EXTRAS[$plan]['dias'] ?? null;

        if ($dias === null && $p && $columna) {
            $dias = (int) ($p->{$columna} ?? $p->num_dias ?? 30);
        }

        return max(1, min(30, (int) ($dias ?? 30)));
    }

    /** IBC proporcional a los días, exacto: la misma fórmula de la rama general. */
    private static function ibcProporcional(int $ibcFull, int $dias): int
    {
        return $dias < 30 ? (int) round($ibcFull * $dias / 30) : $ibcFull;
    }

    /**
     * La AFP del día simbólico: la de la ficha del cliente, la del plano si la
     * ficha no la tiene, y COLPENSIONES como último recurso —el régimen al que
     * se pertenece por defecto, sin trámite de traslado—.
     */
    private static function afpDelCliente(object $p, string $codAfpDelPlano): string
    {
        foreach ([(string) ($p->cod_afp_cliente ?? ''), $codAfpDelPlano] as $codigo) {
            $codigo = trim($codigo);
            if ($codigo !== '' && $codigo !== '0' && strtoupper($codigo) !== 'N/A') {
                return $codigo;
            }
        }

        return self::AFP_POR_DEFECTO;
    }
}
