<?php

namespace App\Services;

use App\Models\ConfiguracionBrynex;
use App\Models\TipoModalidad;

/**
 * PilaCotizanteCalculator
 *
 * Centraliza TODAS las reglas de negocio PILA para cotizantes:
 *   - Tipo / subtipo cotizante
 *   - tienePension (reglas v_afp + edad + cod_afp)
 *   - Planilla K (tipo_modalidad_id = -1): solo ARL, tipo 23
 *   - IBC proporcional por días trabajados
 *   - CCF68 (sin caja propia)
 *   - Exonerado SENA/ICBF
 *
 * Uso en cualquier servicio de exportación PILA:
 *   $c = PilaCotizanteCalculator::calcular($planoRow);
 *   // $c['tipoCotizante'], $c['ibcProp'], etc.
 */
class PilaCotizanteCalculator
{
    // ── Constantes de umbrales de edad ──────────────────────────────────────
    private const EDAD_EXENTO_M = 55; // hombres ≥ 55 → subtipo 03
    private const EDAD_EXENTO_F = 50; // mujeres ≥ 50 → subtipo 03

    // ── tipo_modalidad_id especiales ────────────────────────────────────────
    private const TIPO_K_MATRIZ        = -1; // Planilla K: solo ARL, tipo cotizante 23
    private const TIPOS_INDEPENDIENTE  = [10, 11]; // tipo cotizante 3 (o 59 con ARL)
    /**
     * Tiempo Parcial Independiente → tipo cotizante 76 (Resolución 1529 de 2026).
     * La señal es la modalidad y no la razón social: hay planos de tiempo
     * parcial de dependientes colgando de razones sociales marcadas como
     * independientes, y esos siguen siendo 51.
     */
    private const TIPO_TP_INDEPENDIENTE = 18;
    public  const TIPO_E1              = -4; // E-1: salud sin pensión, en dos planillas (ver PilaCotizanteE1)
    // Tiempo parcial: detectado por tm.es_tiempo_parcial = 1, tipo_cot viene de la tabla

    // ── Tarifas ARL por nivel de riesgo ────────────────────────────────────
    public const TARIFAS_ARL = [
        1 => 0.00522, 2 => 0.01044, 3 => 0.02436,
        4 => 0.04350, 5 => 0.06960,
    ];

    /**
     * Calcula todos los valores necesarios para generar un registro PILA.
     *
     * @param  object $p  Fila del plano con sus JOINs ya resueltos.
     *                    Dos campos opcionales los usa solo la modalidad E-1
     *                    (ver PilaCotizanteE1): `paso_e1` (1|2) elige entre la
     *                    planilla de un día y la corrección, y `cod_afp_cliente`
     *                    es la AFP de la ficha del cliente, que sirve de
     *                    respaldo cuando el plano va sin fondo de pensión.
     * @return array {
     *   tipoCotizante: int,       // 1=dep, 3=indep, 59=indep con ARL, 23=K
     *   subtipoCotizante: int,    // 0, 3, 4
     *   tienePension: bool,
     *   esKMatriz: bool,          // true si tipo_modalidad_id = -1
     *   esIndependiente: bool,
     *   exonerado: string,        // 'S' o 'N'  (exonerado SENA/ICBF)
     *   ibcFull: int,             // salario completo (campo 40)
     *   ibcProp: int,             // IBC proporcional (EPS/ARL/AFP)
     *   ibcAfp: int,              // 0 si !tienePension o K
     *   ibcEps: int,              // 0 si K
     *   ibcArl: int,              // ibcFull si K (30 días), ibcProp si normal
     *   ibcCcf: int,              // 100 si CCF68, 0 si K
     *   dias: int,                // días reales del plano
     *   diasPension: int,         // 0 si !tienePension o K
     *   diasSalud: int,           // 0 si K
     *   diasArl: int,             // 30 si K, dias si normal
     *   diasCcf: int,             // 0 si K
     *   vAfp: int,
     *   vEps: int,
     *   vArl: int,
     *   vCcf: int,
     *   codAfpPila: string,       // código PILA de AFP (campo 31)
     *   codEpsPila: string,       // código PILA de EPS (campo 33)
     *   codCcfPila: string,       // código PILA de CCF (campo 35), 'CCF68' si sin caja
     *   codArlPila: string,
     *   tarifaAfpDecimal: float,  // 0.16 o 0.0 si no aplica
     *   tarifaEpsStr: string,     // '0.04000' o '0.12500' (para TXT)
     *   tarifaSenaStr: string,
     *   tarifaIcbfStr: string,
     *   tarifaArlDecimal: float,  // para Excel
     *   nivelRiesgo: int,
     *   ibcOtros: int,            // IBC parafiscales (SENA/ICBF)
     *   vSena: int,
     *   vIcbf: int,
     *   tipoSalarioAplica: bool,  // false en 3, 23, 51 y 59: PILA prohíbe marcarlo
     *   horasLaboradas: int,      // 0 cuando no hay aporte a CCF (23 y modalidad 8)
     *   sinCaja: bool,            // true si codCcfPila='CCF68' (sin caja propia)
     *   ibcCcf: int,              // 100 si sin caja, ibcProp si tiene caja, 0 si K
     *   vCcf: int,                // 100 si sin caja, calculado si tiene, 0 si K
     *   depCod: string,           // '99' si sin caja o K, real si tiene caja
     *   munCod: string,           // '001' si sin caja o K
     * }
     */
    public static function calcular(object $p): array
    {
        $tipoModalidad = (int)($p->tipo_modalidad_id ?? 0);

        // ── Flags de tipo de cotizante ────────────────────────────────────────
        $esKMatriz       = ($tipoModalidad === self::TIPO_K_MATRIZ);
        $esIndep         = in_array($tipoModalidad, self::TIPOS_INDEPENDIENTE);
        $esTiempoParcial = (bool)($p->es_tiempo_parcial ?? false);

        $esIndependiente = (bool)($p->razonSocial?->es_independiente ?? false);

        // La ARL distingue los dos tipos de independiente: quien la lleva
        // tiene contrato de prestación de servicios de más de un mes, y ese
        // contrato obliga a afiliarlo a riesgos (Decreto 723/2013) → tipo 59.
        // Sin ARL es un independiente por cuenta propia → tipo 3. El 2 es
        // "Servicio doméstico" en el tipo de cotizante; el 2 que significa
        // independiente es el del tipo de aportante, que es otro campo.
        $codArlRaw = trim((string)($p->cod_arl ?? ''));
        $llevaArl  = $codArlRaw !== '' && $codArlRaw !== '0';

        // ── Tipo cotizante ───────────────────────────────────────────────────
        if ($esKMatriz) {
            $tipoCotizante = 23;
        } elseif ($esTiempoParcial) {
            $tipoCotizante = 51;
        } elseif ($esIndependiente || $esIndep) {
            $tipoCotizante = $llevaArl ? 59 : 3;
        } else {
            $tipoCotizante = 1;
        }

        // ── Tipo documento y regla de extranjero PILA ────────────────────────
        // Regla 1: extranjero CON AFP → NO marcar X (cotiza normal, sin excepción pensión)
        // Regla 2: extranjero SIN AFP → marcar X extranjero, subtipo = 0 (no 3 ni 4)
        $docsCol         = ['CC', 'TI', 'NUIP', 'RC', 'SC'];
        $tipoDocNorm     = strtoupper(trim($p->tipo_doc ?? 'CC'));
        $esExtranjeroDoc = !in_array($tipoDocNorm, $docsCol);

        // Normalizar códigos: '0' → null
        $codAfpRaw = (trim((string)($p->cod_afp  ?? '')) === '0') ? null : ($p->cod_afp  ?? null);
        $codEpsRaw = (trim((string)($p->cod_eps  ?? '')) === '0') ? null : ($p->cod_eps  ?? null);
        $codCajRaw = (trim((string)($p->cod_caja ?? '')) === '0') ? null : ($p->cod_caja ?? null);

        // ── Edad y género ───────────────────────────────────────────────────
        $genero = strtoupper(trim($p->genero ?? ''));
        $edad   = isset($p->edad_calculada) ? (int)$p->edad_calculada : null;

        // ── Días e IBC ──────────────────────────────────────────────────────
        // p.num_dias es la fuente de verdad (f.dias_cotizados puede estar mal por migración)
        $ibcFull = (int)($p->salario_basico ?? 0);
        $dias    = (int)($p->num_dias ?? $p->dias_cotizados ?? 30);
        $ibcProp = $dias < 30 ? (int)round($ibcFull * $dias / 30) : $ibcFull;

        // ── Nivel de riesgo ARL ─────────────────────────────────────────────
        $nivel    = max(1, min(5, (int)($p->nivel_riesgo ?? 1)));
        $tasaArl  = self::TARIFAS_ARL[$nivel];

        // ── Planilla K: solo ARL 30 días ────────────────────────────────────
        if ($esKMatriz) {
            $vArl = self::roundPila($ibcFull * $tasaArl);
            return [
                'tipoCotizante'    => 23,
                'subtipoCotizante' => 0,
                'tienePension'     => false,
                'esKMatriz'        => true,
                'esIndependiente'  => false,
                'esExtranjero'     => false,
                'exonerado'        => 'N',
                'ibcFull'          => $ibcFull,
                'ibcProp'          => $ibcFull,
                'ibcAfp'           => 0,
                'ibcEps'           => 0,
                'ibcArl'           => $ibcFull,
                'ibcCcf'           => 0,
                'dias'             => $dias,
                'diasPension'      => 0,
                'diasSalud'        => 0,
                'diasArl'          => 30,
                'diasCcf'          => 0,
                'vAfp'             => 0,
                'vEps'             => 0,
                'vArl'             => $vArl,
                'vCcf'             => 0,
                'codAfpPila'       => '',
                'codEpsPila'       => '',
                'codCcfPila'       => '',
                'codArlPila'       => $p->cod_arl_pila ?? ($p->codigo_arl_pila ?? ''),
                'tarifaAfpDecimal' => 0.0,
                'tarifaEpsStr'     => '0.00000',
                'tarifaCcfStr'     => '0.04000',
                'tarifaSenaStr'    => '0.00000',
                'tarifaIcbfStr'    => '0.00000',
                'tarifaArlStr'     => sprintf('%.5f', $tasaArl),
                'tarifaArlDecimal' => $tasaArl,
                'nivelRiesgo'      => $nivel,
                'ibcOtros'         => 0,
                'vSena'            => 0,
                'vIcbf'            => 0,
                'sinCaja'          => false,
                'esTiempoParcial'  => false,
                'depCod'           => '94',
                'munCod'           => '1',
                // PILA prohíbe marcar el tipo de salario en el cotizante 23
                // (error `eo.val.2.237` de Enlace).
                'tipoSalarioAplica' => false,
                // Las horas laboradas son el insumo del aporte a caja, y el
                // estudiante K no aporta a CCF (días, IBC y código de caja van
                // en cero). Reportarlas es el error `eo.val.2.636` de Enlace:
                // "registra horas laboradas pero no realiza aportes a CCF".
                'horasLaboradas'   => 0,
            ];
        }

        // ── Tiempo parcial (tipo cotizante 51 dependiente / 76 independiente) ──
        if ($esTiempoParcial) {
            // El 76 es el mismo cálculo del 51 con tres diferencias: el tipo de
            // cotizante, la tarifa de caja (voluntaria al 0,6% o 2%, no el 4%
            // obligatorio del dependiente) y que sin caja no se reporta nada.
            $esTpIndependiente = ($tipoModalidad === self::TIPO_TP_INDEPENDIENTE);

            $diasArlTp  = 30;

            // En retiro (o ingreso tardío), num_dias puede ser menor que los días
            // base del plan (ej: TP 7-14 con retiro a 7 días → $dias=7).
            // Se usa min() para que el retiro recorte correctamente cada subsistema.
            $diasAfpTp  = min((int)($p->dias_afp  ?? 30), $dias);
            $diasCajaTp = min((int)($p->dias_caja ?? 30), $dias);

            $smmlv    = (int) ConfiguracionBrynex::salarioMinimo();
            $ibcArlTp = $smmlv;

            // Calcular semanas e IBC por semanas (Decreto 2616 de 2013)
            $semanasAfp  = self::calcularSemanasTp($diasAfpTp);
            $semanasCaja = self::calcularSemanasTp($diasCajaTp);

            $salarioSemanal = $smmlv / 4;

            // El IBC semanal casi siempre queda fraccionado (SMMLV/4). Los
            // operadores PILA redondean hacia arriba, no al más cercano: con
            // round() Enlace rechaza la planilla con eo.val.2.325 / eo.val.2.326
            // ("el IBC reportado no es correcto, deberá ser X") por 1 peso.
            $ibcAfpTp  = (int)ceil($salarioSemanal * $semanasAfp);
            $ibcCajaTp = (int)ceil($salarioSemanal * $semanasCaja);

            $tienePension = !empty($codAfpRaw);

            // Extranjero: mismas reglas que bloque normal
            $esExtranjero = $esExtranjeroDoc && !$tienePension;

            // Subtipo cotizante para tipo 51:
            //   - Extranjero sin AFP → subtipo 0 (igual que bloque normal)
            //   - Sin AFP, no extranjero → subtipo 3 si exento por edad (H≥55/F≥50), 4 si no exento
            //   - Con AFP → subtipo 0
            $subtipoCotizante = 0;
            if (!$tienePension && !$esExtranjero && $edad !== null) {
                $isExento         = ($genero === 'M' && $edad >= self::EDAD_EXENTO_M)
                                 || ($genero === 'F' && $edad >= self::EDAD_EXENTO_F);
                $subtipoCotizante = $isExento ? 3 : 4;
            }

            // Caja
            $codCcfFin = 'CCF68';
            if (!empty($codCajRaw)) {
                $nitCajaLimpio = preg_replace('/[^0-9]/', '', (string)$codCajRaw);
                $cajaObj = \App\Models\Caja::where('nit', $nitCajaLimpio)->orWhere('codigo', $codCajRaw)->first();
                $codCcfFin = ($cajaObj && !empty($cajaObj->codigo)) ? $cajaObj->codigo : $codCajRaw;
            }
            $sinCaja    = ($codCcfFin === 'CCF68');

            // Tarifa de caja: 4% obligatorio en el dependiente; en el 76 la
            // afiliación es voluntaria y el trabajador elige 0,6% o 2%, que es
            // lo que ya guarda contratos.porcentaje_caja.
            $tarifaCcfTp = 0.04;
            if ($esTpIndependiente) {
                $pctCajaTp   = $p->porcentaje_caja ?? null;
                $tarifaCcfTp = ($pctCajaTp !== null && (float) $pctCajaTp > 0)
                    ? (float) $pctCajaTp / 100
                    : 0.02;
            }

            // En el 76 la caja es voluntaria: la Resolución 1529 de 2026 lo dice
            // sin ambigüedad — "Los aportes al Sistema de Subsidio Familiar para
            // este tipo de cotizante son voluntarios, y podrá reportar la tarifa
            // 0.6% o 2%".
            //
            // Lo que no es voluntario es reportar la BASE: con el IBC de caja en
            // cero Enlace devuelve eo.val.2.050 y eo.val.2.326 ("debería ser
            // <IBC de las semanas>"). Así que la base va siempre y lo que queda
            // en cero cuando no se aporta es la cotización.
            //
            // OJO: hoy Enlace además exige el aporte (eo.val.2.066, "está
            // obligado cotizar a Cajas de Compensación Familiar"), lo cual
            // contradice la resolución. Es un error de su malla de validación,
            // no de este cálculo: no replicarlo aquí.
            $sinAporteCcf = $sinCaja && ($esIndependiente || $esTpIndependiente);

            $ibcCcfTp = match (true) {
                ! $sinCaja || $esTpIndependiente => $ibcCajaTp,
                $esIndependiente                 => 0,
                default                          => 100,
            };
            $vCcfTp = $sinCaja
                ? ($sinAporteCcf ? 0 : 100)
                : self::roundPila($ibcCajaTp * $tarifaCcfTp);

            $vArlTp = self::roundPila($ibcArlTp * $tasaArl);
            $vAfpTp = $tienePension ? self::roundPila($ibcAfpTp * 0.16) : 0;

            $codArlPila = $p->cod_arl_pila ?? $p->codigo_arl_pila ?? '';
            $codAfpPila = '';
            if ($tienePension) {
                $nitAfp     = preg_replace('/[^0-9]/', '', (string)$codAfpRaw);
                $codAfpDb   = $p->cod_afp_pila ?? $p->codigo_afp ?? null;
                $codAfpPila = !empty($codAfpDb) ? $codAfpDb : $nitAfp;
            }

            if ($sinCaja) {
                $depCod = '94'; $munCod = '1';
            } else {
                $depCod = str_pad((string)($p->dep_id ?? $p->cod_departamento ?? ''), 2, '0', STR_PAD_LEFT);
                $munCod = str_pad((string)($p->mun_id ?? $p->cod_municipio    ?? ''), 3, '0', STR_PAD_LEFT);
            }

            return [
                'tipoCotizante'    => $esTpIndependiente ? 76 : 51,
                'subtipoCotizante' => $subtipoCotizante, // 0 con AFP o extranjero; 3 exento edad; 4 sin AFP sin edad exenta
                'tienePension'     => $tienePension,
                'esKMatriz'        => false,
                'esTiempoParcial'  => true,
                'esIndependiente'  => $esTpIndependiente,
                'esExtranjero'     => $esExtranjero,
                // Ni el 51 ni el 76 están exonerados de parafiscales (PILA Res. 2388)
                'exonerado'        => 'N',
                'ibcFull'          => $ibcCajaTp, // Para tiempo parcial, el salario mensual reportado es la base de caja por semanas
                'ibcProp'          => $ibcCajaTp,
                'ibcAfp'           => $tienePension ? $ibcAfpTp : 0,
                'ibcEps'           => 0,
                'ibcArl'           => $ibcArlTp,
                'ibcCcf'           => $ibcCcfTp,
                'dias'             => $dias,
                'diasPension'      => $tienePension ? $diasAfpTp : 0,
                'diasSalud'        => 0,
                'diasArl'          => $diasArlTp,
                'diasCcf'          => $diasCajaTp,
                'vAfp'             => $vAfpTp,
                'vEps'             => 0,
                'vArl'             => $vArlTp,
                'vCcf'             => $vCcfTp,
                'codAfpPila'       => $codAfpPila,
                'codEpsPila'       => '',
                'codCcfPila'       => $codCcfFin,
                'codArlPila'       => (string)$codArlPila,
                'sinCaja'          => $sinCaja,
                'tarifaAfpDecimal' => $tienePension ? 0.16 : 0.0,
                'tarifaEpsStr'     => '0.00000',
                'tarifaCcfStr'     => sprintf('%.5f', $sinAporteCcf ? 0.0 : $tarifaCcfTp),
                'tarifaSenaStr'    => '0.00000',
                'tarifaIcbfStr'    => '0.00000',
                'tarifaArlStr'     => sprintf('%.5f', $tasaArl),
                'tarifaArlDecimal' => $tasaArl,
                'nivelRiesgo'      => $nivel,
                'ibcOtros'         => 0,
                'vSena'            => 0,
                'vIcbf'            => 0,
                'depCod'           => $depCod,
                'munCod'           => $munCod,
                // PILA prohíbe marcar el tipo de salario en los cotizantes 51 y 76.
                'tipoSalarioAplica' => false,
                // Horas = dias_caja (días reales trabajados) × 8, NO num_dias.
                // Sin aporte a CCF van en cero: reportar horas sin aportar es el
                // eo.val.2.636 de Enlace, el mismo que rompía al estudiante K.
                'horasLaboradas'   => $sinAporteCcf ? 0 : $diasCajaTp * 8,
            ];
        }

        // ── Lógica normal (dependiente / independiente) ──────────────────────

        // ── tienePension ────────────────────────────────────────────────────
        // La ÚNICA señal es cod_afp: si el plan facturó con AFP → siempre cotiza pensión
        // independientemente de la edad del cliente.
        // Solo cuando cod_afp está vacío (plan sin AFP) se aplica la regla de edad:
        //   subtipo 3 → exento por edad (hombre ≥55 / mujer ≥50)
        //   subtipo 4 → reclamó pensión anticipada (cualquier edad, sin cod_afp)
        $tienePension = !empty($codAfpRaw);

        // ── Extranjero PILA ──────────────────────────────────────────────────
        // Regla 1: extranjero CON AFP → NO marcar X (cotiza pensión normalmente)
        // Regla 2: extranjero SIN AFP → marcar X, subtipo=0 (no aplican reglas de edad)
        $esExtranjero = $esExtranjeroDoc && !$tienePension;

        // ── Subtipo cotizante ─────────────────────────────────────────────────
        $subtipoCotizante = 0;
        if (!$tienePension && !$esExtranjero && $edad !== null) {
            // Solo aplica regla de edad para NO extranjeros sin pensión
            $isExento         = ($genero === 'M' && $edad >= self::EDAD_EXENTO_M)
                             || ($genero === 'F' && $edad >= self::EDAD_EXENTO_F);
            $subtipoCotizante = $isExento ? 3 : 4;
        }

        // ── Exonerado SENA/ICBF ────────────────────────────────────────────────
        // Dependientes → S (empresa paga 4% EPS, exonerado de SENA/ICBF)
        // Independientes/K/TP → N
        //
        // Salvo que el aportante no tenga derecho a la exoneración del art. 114-1
        // del ET: un sindicato o cualquier entidad del art. 23, que al no ser
        // contribuyente de renta paga la salud al 12,5% y encima SENA e ICBF. Lo
        // dice la ficha de la empresa (`empresas.exonerado_parafiscales`), que la
        // inyecta PlanoPilaTxtService; sin el dato se asume exonerado, que es como
        // se venía liquidando.
        $aportanteNoExonerado = ($p->exonerado_parafiscales ?? null) !== null
            && ! (bool) $p->exonerado_parafiscales;
        $exonerado = (!$esIndep && !$esKMatriz && !$esTiempoParcial && !$esIndependiente && !$aportanteNoExonerado) ? 'S' : 'N';

        // SENA e ICBF son aportes del empleador. El independiente cotiza por su
        // cuenta y no los paga nunca: no es que esté exonerado (ahí sigue en N,
        // que es lo que le fija la salud en 12,5%), es que no le aplican. Con
        // valores, Enlace rechaza con "El tipo de cotizante 02 no puede
        // realizar aportes a Sena/Icbf".
        $pagaParafiscales = $exonerado === 'N' && !$esIndep && !$esIndependiente;

        // ── Código CCF ─────────────────────────────────────────────────────
        $codCcfFin = 'CCF68';
        if (!empty($codCajRaw)) {
            $nitCajaLimpio = preg_replace('/[^0-9]/', '', (string)$codCajRaw);
            $cajaObj = \App\Models\Caja::where('nit', $nitCajaLimpio)->orWhere('codigo', $codCajRaw)->first();
            $codCcfFin = ($cajaObj && !empty($cajaObj->codigo)) ? $cajaObj->codigo : $codCajRaw;
        }

        // ── IBC por subsistema ──────────────────────────────────────────────
        $ibcAfp = $tienePension ? $ibcProp : 0;
        $ibcEps = $ibcProp;
        $ibcArl = $ibcProp;
        $ibcCcf = ($codCcfFin === 'CCF68') ? ($esIndependiente ? 0 : 100) : $ibcProp;

        // ── Tarifa de caja ─────────────────────────────────────────────────
        // El dependiente aporta el 4% de ley. El independiente se afilia a la
        // caja de forma voluntaria y su tarifa es la que quedó pactada en el
        // contrato: 2% o 0,6%. Cobrarle el 4% le liquida de más lo que no se
        // le facturó (35.000 al mes con un IBC de un salario mínimo).
        //
        // La señal es la razón social, no la modalidad: hay 431 planos de
        // razones sociales de independientes con modalidades distintas a
        // 10/11, y 35 de modalidad 10/11 en razones sociales normales.
        // `rs_es_independiente` lo inyecta PlanoPilaTxtService — se usa SOLO
        // para esto y no se mezcla con `$esIndependiente`, que gobierna
        // exoneración y tipo de cotizante y no se puede reactivar sin mover
        // la tarifa de salud de esos 431.
        $rsEsIndependiente = (bool)($p->rs_es_independiente ?? false);
        $pctCaja           = $p->porcentaje_caja ?? null;
        $tarifaCcf         = 0.04;
        if ($rsEsIndependiente) {
            $tarifaCcf = ($pctCaja !== null && (float)$pctCaja > 0)
                ? (float)$pctCaja / 100
                : 0.02;
        }

        // ── Cotizaciones ───────────────────────────────────────────────────
        $vAfp = $tienePension ? self::roundPila($ibcProp * 0.16) : 0;
        $vEps = self::roundPila($ibcProp * ($exonerado === 'S' ? 0.04 : 0.125));
        $vArl = self::roundPila($ibcProp * $tasaArl);
        $vCcf = ($codCcfFin === 'CCF68')
            ? ($esIndependiente ? 0 : 100)
            : self::roundPila($ibcProp * $tarifaCcf);

        // ── Tarifas EPS/SENA/ICBF ──────────────────────────────────────────
        $tarifaEpsStr  = $exonerado === 'S' ? '0.04000' : '0.12500';
        $tarifaCcfStr  = sprintf('%.5f', $codCcfFin === 'CCF68' ? 0.04 : $tarifaCcf);
        $tarifaSenaStr = $pagaParafiscales ? '0.02000' : '0.00000';
        $tarifaIcbfStr = $pagaParafiscales ? '0.03000' : '0.00000';

        // ── Parafiscales ────────────────────────────────────────────────────
        $ibcOtros = $pagaParafiscales ? $ibcProp : 0;
        $vSena    = $pagaParafiscales ? self::roundPila($ibcProp * 0.02) : 0;
        $vIcbf    = $pagaParafiscales ? self::roundPila($ibcProp * 0.03) : 0;

        // ── Código AFP PILA ─────────────────────────────────────────────────
        $codAfpPila = '';
        if ($tienePension) {
            $nitAfp     = preg_replace('/[^0-9]/', '', (string)$codAfpRaw);
            $codAfpDb   = $p->cod_afp_pila ?? $p->codigo_afp ?? null; // TXT=cod_afp_pila, Excel=codigo_afp
            $codAfpPila = !empty($codAfpDb) ? $codAfpDb : $nitAfp;
        }

        // ── Código EPS / ARL ────────────────────────────────────────────────
        $codEpsPila = $p->cod_eps_pila ?? $p->codigo_eps ?? ($codEpsRaw ?? '');
        $codArlPila = $p->cod_arl_pila ?? $p->codigo_arl_pila ?? '';

        // Sin ARL no hay a quién reportarle el aporte de riesgos. El registro
        // salía con cotización pero sin código de administradora —ni en el
        // tipo 2 ni en el tipo 1—, que es lo que el operador rechaza con
        // "está realizando aportes a riesgos y debe ingresar un código de
        // riesgos en el registro tipo 01". La factura tampoco lo cobra: en la
        // razón social de independientes, los 9 sin ARL tienen v_arl en cero y
        // los 7 con ARL la pagan, sin un solo caso cruzado.
        $diasArl     = $dias;
        $tasaArlFin  = $tasaArl;
        if (! $llevaArl) {
            $ibcArl     = 0;
            $vArl       = 0;
            $diasArl    = 0;
            $tasaArlFin = 0.0;
            $codArlPila = '';
        }

        // ── Departamento / Municipio ─────────────────────────────────────────
        // Sin caja propia (CCF68): departamento 94 por defecto, municipio 1
        $sinCaja = ($codCcfFin === 'CCF68');
        if ($tipoModalidad === 8) {
            $depCod = str_pad((string)($p->dep_id ?? $p->cod_departamento ?? ''), 2, '0', STR_PAD_LEFT);
            $munCod = str_pad((string)($p->mun_id ?? $p->cod_municipio    ?? ''), 3, '0', STR_PAD_LEFT);
        } elseif ($sinCaja) {
            $depCod = '94'; $munCod = '1';
        } else {
            $depCod = str_pad((string)($p->dep_id ?? $p->cod_departamento ?? ''), 2, '0', STR_PAD_LEFT);
            $munCod = str_pad((string)($p->mun_id ?? $p->cod_municipio    ?? ''), 3, '0', STR_PAD_LEFT);
        }

        $res = [
            'tipoCotizante'    => $tipoCotizante,
            'subtipoCotizante' => $subtipoCotizante,
            'tienePension'     => $tienePension,
            'esKMatriz'        => false,
            'esIndependiente'  => $esIndep,
            'esExtranjero'     => $esExtranjero,
            'exonerado'        => $exonerado,
            'ibcFull'          => $ibcFull,
            'ibcProp'          => $ibcProp,
            'ibcAfp'           => $ibcAfp,
            'ibcEps'           => $ibcEps,
            'ibcArl'           => $ibcArl,
            'ibcCcf'           => $ibcCcf,
            'dias'             => $dias,
            'diasPension'      => $tienePension ? $dias : 0,
            'diasSalud'        => $dias,
            'diasArl'          => $diasArl,
            'diasCcf'          => $dias,
            'vAfp'             => $vAfp,
            'vEps'             => $vEps,
            'vArl'             => $vArl,
            'vCcf'             => $vCcf,
            'codAfpPila'       => $codAfpPila,
            'codEpsPila'       => (string)$codEpsPila,
            'codCcfPila'       => $codCcfFin,
            'codArlPila'       => (string)$codArlPila,
            'tarifaAfpDecimal' => 0.16,
            'tarifaEpsStr'     => $tarifaEpsStr,
            'tarifaCcfStr'     => $tarifaCcfStr,
            'tarifaSenaStr'    => $tarifaSenaStr,
            'tarifaIcbfStr'    => $tarifaIcbfStr,
            'tarifaArlStr'     => sprintf('%.5f', $tasaArlFin),
            'tarifaArlDecimal' => $tasaArlFin,
            'nivelRiesgo'      => $nivel,
            'ibcOtros'         => $ibcOtros,
            'vSena'            => $vSena,
            'vIcbf'            => $vIcbf,
            'sinCaja'          => $sinCaja,
            'esTiempoParcial'  => false,
            'depCod'           => $depCod,
            'munCod'           => $munCod,
            // PILA prohíbe marcar el tipo de salario en el cotizante 59
            // (independiente con contrato de prestación de servicios), venga
            // de la planilla Y o de la I: error `eo.val.2.237` de Enlace. Al
            // independiente 3 tampoco le aplica: el campo 41 solo se llena
            // para los cotizantes 1, 2, 20, 22, 32 y 58 (Anexo Técnico 2).
            'tipoSalarioAplica' => ! in_array($tipoCotizante, [3, 59], true),
            'horasLaboradas'   => $dias * 8,    // Normal: num_dias × 8
        ];

        if ($tipoModalidad === 8) {
            $res['tipoCotizante']    = 59;
            $res['subtipoCotizante'] = 0;
            $res['exonerado']         = 'N';
            $res['diasPension']      = 0;
            $res['diasSalud']        = 0;
            $res['diasCcf']          = 0;
            $res['codAfpPila']       = '';
            $res['tarifaAfpDecimal'] = 0.0;
            $res['vAfp']             = 0;
            $res['ibcAfp']           = 0;
            $res['codEpsPila']       = '';
            $res['tarifaEpsStr']     = '0.00000';
            $res['ibcEps']           = 0;
            $res['vEps']             = 0;
            $res['codCcfPila']       = '';
            $res['ibcCcf']           = 0;
            $res['vCcf']             = 0;
            $res['tarifaSenaStr']    = '0.00000';
            $res['tarifaIcbfStr']    = '0.00000';
            $res['ibcOtros']         = 0;
            $res['vSena']            = 0;
            $res['vIcbf']            = 0;
            // El tipo de cotizante se fuerza a 59 arriba, así que el tipo de
            // salario tiene que quedar en blanco aunque la rama normal lo
            // hubiera calculado como dependiente.
            $res['tipoSalarioAplica'] = false;
            // Las horas laboradas son el insumo del aporte a caja, y la
            // planilla Y no aporta a CCF (diasCcf, ibcCcf y código en cero).
            // Reportarlas es el error `eo.val.2.636`: "registra horas
            // laboradas pero no realiza aportes a CCF".
            $res['horasLaboradas']    = 0;
        }

        // ── Fondo de Solidaridad (PSAP): cotizante 33 ───────────────────────
        if ($tipoModalidad === TipoModalidad::ID_FONDO_SOLIDARIDAD) {
            $res = self::ajustarFondoSolidaridad($res, $p, $ibcProp);
        }

        // ── Modalidad E-1: salud sin pensión, partida en dos planillas ──────
        // Esquema temporal y con reglas propias que no comparte con ninguna
        // otra modalidad: vive aparte para que el día que se deje de usar
        // baste con borrar la clase y esta línea. Ver PilaCotizanteE1.
        if ($tipoModalidad === self::TIPO_E1) {
            $res = PilaCotizanteE1::ajustar($res, $p, $ibcFull, $codAfpPila, $sinCaja);
        }

        // ── Solo Caja y Solo Pensión: un subsistema, en dos planillas ───────
        // Misma forma que la E-1 y por la misma razón: reglas propias que no
        // comparte con nadie más. Ver PilaCotizanteDosPasos.
        if (in_array($tipoModalidad, TipoModalidad::IDS_DOS_PASOS, true)) {
            $res = PilaCotizanteDosPasos::ajustar($res, $p, $ibcFull, $codAfpPila);
        }

        return $res;
    }

    /**
     * Cotizante 33 — beneficiario del Fondo de Solidaridad Pensional (PSAP).
     *
     * Parte del registro normal del independiente y cambia lo que la malla de
     * Enlace exige para el 33, probado contra ARUS el 16-sep-2026:
     *   - Pensión a la tarifa del grupo (0,8 %, 3,2 %, 4 % o 4,8 %) en vez del 16 %.
     *   - Salud obligatoria al 12,5 %, con los mismos días e IBC que pensión
     *     (eo.val.2.066, 2.244.1 y 2.198.1). La tarifa cero la rechaza (2.058).
     *   - Sin ARL, sin caja y sin parafiscales: todo en cero, horas incluidas.
     *   - Departamento y municipio reales, no el 94/001 de quien no tiene caja.
     *
     * Con el plan Solo AFP la salud queda en cero y Enlace rechaza el registro;
     * se deja así por decisión del negocio (ver TipoModalidad).
     *
     * Enlace además valida que la persona esté en el reporte de beneficiarios
     * del Fondo (eo.val.2.504): quien no esté inscrito no liquida.
     */
    private static function ajustarFondoSolidaridad(array $res, object $p, int $ibcProp): array
    {
        $pctPension = TipoModalidad::pctPensionFondoSolidaridad($p->grupo_fondo_solidaridad ?? null) / 100;
        $conSalud   = $res['codEpsPila'] !== '';

        $res['tipoCotizante']     = 33;
        $res['subtipoCotizante']  = 0;
        $res['exonerado']         = 'N';
        $res['tipoSalarioAplica'] = false;

        if ($res['tienePension']) {
            $res['vAfp']             = self::roundPila($ibcProp * $pctPension);
            $res['tarifaAfpDecimal'] = $pctPension;
            // Los archivos escriben la tarifa de pensión fija en 0.16000. Esta
            // clave solo existe en el 33, para no tocar lo que ya liquida bien.
            $res['tarifaAfpStr']     = sprintf('%.5f', $pctPension);
        }

        $res['ibcEps']       = $conSalud ? $ibcProp : 0;
        $res['diasSalud']    = $conSalud ? $res['dias'] : 0;
        $res['vEps']         = $conSalud ? self::roundPila($ibcProp * 0.125) : 0;
        $res['tarifaEpsStr'] = $conSalud ? '0.12500' : '0.00000';

        $res['ibcArl']           = 0;
        $res['vArl']             = 0;
        $res['diasArl']          = 0;
        $res['tarifaArlDecimal'] = 0.0;
        $res['tarifaArlStr']     = '0.00000';

        $res['codCcfPila']     = '';
        $res['ibcCcf']         = 0;
        $res['vCcf']           = 0;
        $res['diasCcf']        = 0;
        $res['tarifaCcfStr']   = '0.00000';
        $res['horasLaboradas'] = 0;

        $res['tarifaSenaStr'] = '0.00000';
        $res['tarifaIcbfStr'] = '0.00000';
        $res['ibcOtros']      = 0;
        $res['vSena']         = 0;
        $res['vIcbf']         = 0;

        $res['depCod'] = str_pad((string) ($p->dep_id ?? $p->cod_departamento ?? ''), 2, '0', STR_PAD_LEFT);
        $res['munCod'] = str_pad((string) ($p->mun_id ?? $p->cod_municipio ?? ''), 3, '0', STR_PAD_LEFT);

        return $res;
    }

    /**
     * Calcula la cantidad de semanas a cotizar según los días laborados (Decreto 2616 de 2013).
     */
    private static function calcularSemanasTp(int $dias): int
    {
        if ($dias >= 1 && $dias <= 7) {
            return 1;
        } elseif ($dias >= 8 && $dias <= 14) {
            return 2;
        } elseif ($dias >= 15 && $dias <= 21) {
            return 3;
        } elseif ($dias > 21) {
            return 4;
        }
        return 0;
    }

    /**
     * Redondea al múltiplo de 100 superior (requerimiento PILA Resolution 2388).
     */
    public static function roundPila(float $val): int
    {
        return (int)(ceil($val / 100) * 100);
    }
}
