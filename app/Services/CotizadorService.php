<?php

namespace App\Services;

use App\Models\ArlTarifa;
use App\Models\Cliente;
use App\Models\ConfiguracionBrynex;
use App\Models\Contrato;
use App\Models\PlanContrato;
use App\Models\TipoModalidad;
use App\Services\PilaCotizanteCalculator;
use App\Services\PilaCotizanteDosPasos;
use Illuminate\Support\Facades\DB;

/**
 * Cálculo de cotización de seguridad social (EPS/ARL/pensión/caja + administración + IVA).
 * Extraído de ContratoController::cotizar para reutilizarse también desde la tool de IA
 * (cotizar_plan) sin duplicar la lógica de tiempo parcial, cargo sin-CCF y prorrateo.
 */
class CotizadorService
{
    /**
     * Memoria de petición para las dos búsquedas fijas del cálculo. Sin esto, cotizar una
     * grilla completa (el tarifario de Parámetros son 193 celdas) dispara 386 consultas contra
     * un SQL Server remoto donde cada una cuesta ~250ms. Ambas tablas son catálogos que no
     * cambian dentro de una misma petición.
     *
     * @var array<string, mixed>
     */
    private static array $catalogo = [];

    /** Limpia la memoria de catálogos. Necesaria en comandos largos y en tests. */
    public static function limpiarCache(): void
    {
        self::$catalogo = [];
    }

    /**
     * Siembra la memoria con catálogos ya cargados, para que cotizar una grilla no tenga que
     * ir a buscar plan por plan y modalidad por modalidad. Lo usa el tarifario de Parámetros,
     * que ya tiene ambas colecciones en memoria antes de empezar a cotizar.
     *
     * @param  iterable<PlanContrato>  $planes
     * @param  iterable<TipoModalidad>  $modalidades
     */
    public static function precargarCatalogo(iterable $planes = [], iterable $modalidades = []): void
    {
        foreach ($planes as $plan) {
            self::$catalogo['p_'.(int) $plan->id] = $plan;
        }

        foreach ($modalidades as $modalidad) {
            // La clave se arma con el id tal cual llega en $p['tipo_modalidad_id'], que puede
            // venir como string desde sqlsrv — de ahí el cast a string en ambos lados.
            self::$catalogo['m_'.(string) $modalidad->id] = $modalidad;
        }
    }

    private static function tipoModalidad(mixed $id): ?TipoModalidad
    {
        $clave = 'm_'.(string) $id;
        if (! array_key_exists($clave, self::$catalogo)) {
            self::$catalogo[$clave] = TipoModalidad::find($id);
        }

        return self::$catalogo[$clave];
    }

    private static function plan(int $id): ?PlanContrato
    {
        $clave = 'p_'.$id;
        if (! array_key_exists($clave, self::$catalogo)) {
            self::$catalogo[$clave] = PlanContrato::find($id);
        }

        return self::$catalogo[$clave];
    }

    /**
     * @param  array  $p  Parámetros: tipo_modalidad_id, plan_id, n_arl, salario, ibc,
     *                    administracion, admon_asesor, seguro, dias, cedula, porcentaje_caja
     */
    public static function calcular(array $p, ?int $alidoId): array
    {
        $tipoModalidad = self::tipoModalidad($p['tipo_modalidad_id'] ?? null);
        $planId = (int) ($p['plan_id'] ?? 0);
        $plan = self::plan($planId);
        $nivelArl = (int) ($p['n_arl'] ?? 1);
        $salario = (float) ($p['salario'] ?? 0);
        $ibc = (float) ($p['ibc'] ?? $salario) ?: $salario; // nunca 0
        $admon = (float) ($p['administracion'] ?? 0);
        $admonAsesor = (float) ($p['admon_asesor'] ?? 0);
        $seguro = (float) ($p['seguro'] ?? 0);
        // Entre 0 y 30. El 0 es un valor legítimo y no un dato faltante: es lo que
        // cotiza un mes de afiliación pura (o un contrato de Gestión ARL, que nunca
        // paga planilla). Subirlo a 1 hacía que esos meses mostraran seguridad
        // social que después no se cobra. Quien no manda el campo sigue en 30.
        $dias = max(0, min(30, (int) ($p['dias'] ?? 30)));
        $cedula = $p['cedula'] ?? null;

        $esIndep = $tipoModalidad && $tipoModalidad->esIndependiente();
        $esTP = $tipoModalidad && $tipoModalidad->esTiempoParcial();

        // Porcentajes
        $pctEps = $esIndep ? ConfiguracionBrynex::pctSaludIndependiente() : ConfiguracionBrynex::pctSaludDependiente();
        $pctPen = $esIndep ? ConfiguracionBrynex::pctPensionIndependiente() : ConfiguracionBrynex::pctPensionDependiente();
        $pctArl = ArlTarifa::porcentajePara($nivelArl, $alidoId);

        // Fondo de Solidaridad: IBC de un mínimo y pensión a la tarifa del grupo.
        // Misma regla que Contrato::calcularCotizacion().
        if ($tipoModalidad && $tipoModalidad->esFondoSolidaridad()) {
            $salario = $ibc = ConfiguracionBrynex::salarioMinimo();
            $pctPen = TipoModalidad::pctPensionFondoSolidaridad($p['grupo_fondo_solidaridad'] ?? null);
        }

        // Caja: empresa siempre 4%; independiente usa el valor enviado (2% o 0.6%)
        if ($esIndep) {
            $pctCajaReq = (float) ($p['porcentaje_caja'] ?? 0);
            $pctCaja = $pctCajaReq ?: ConfiguracionBrynex::pctCajaIndependienteAlto();
        } else {
            $pctCaja = ConfiguracionBrynex::pctCajaDependiente(); // empresa: siempre 4%
        }

        // Redondear HACIA ARRIBA al 100 mas cercano (ceil)
        $r = fn ($v) => ceil($v / 100) * 100;

        $upc = null; // solo se llena si la modalidad es UPC

        if ($esTP) {
            // ── Tiempo Parcial: IBC diferente por entidad, sin EPS ─────────
            $diasP = $tipoModalidad->diasPorEntidad();

            // Tiempo Parcial Independiente: los días no están en el catálogo,
            // los elige el contrato mes a mes y llegan en la petición.
            if ($tipoModalidad->diasEnElContrato()) {
                $diasAfpReq = (int) ($p['dias_tp_afp'] ?? 0);
                $diasCajaReq = (int) ($p['dias_tp_caja'] ?? 0);
                $diasP['afp'] = $diasAfpReq ?: 7;
                $diasP['caja'] = $diasCajaReq ?: $diasP['afp'];
            }
            $factorMap = [7 => 0.25, 14 => 0.50, 21 => 0.75, 30 => 1.00];
            $factorAfp = $factorMap[$diasP['afp']] ?? 1.0;
            $factorCaja = $factorMap[$diasP['caja']] ?? 1.0;

            $sm = (float) ConfiguracionBrynex::obtener('salario_minimo', 1423500);

            $ibcArl = $sm;
            $ibcAfp = round($sm * $factorAfp);
            $ibcCaja = round($sm * $factorCaja);

            $eps = 0;
            $arl = ($plan && $plan->incluye_arl) ? $r($ibcArl * $pctArl / 100) : 0;
            $pen = ($plan && $plan->incluye_pension) ? $r($ibcAfp * $pctPen / 100) : 0;
            $caja = ($plan && $plan->incluye_caja) ? $r($ibcCaja * $pctCaja / 100) : 0;
            $ss = $eps + $arl + $pen + $caja;
            $epsMes = 0;
            $arlMes = $arl;
            $penMes = $pen;
            $cajaMes = $caja;
            $diasArl = $diasP['arl'];
            $diasAfp = $diasP['afp'];
            $diasCaja = $diasP['caja'];
        } elseif ((int) ($p['tipo_modalidad_id'] ?? 0) === Contrato::MODALIDAD_UPC) {
            // ── UPC adicional: el valor de EPS no es % de IBC, es una tarifa
            //    fija por edad/sexo/zona del beneficiario (Resolución 2764/2025).
            //    Siempre es solo salud: nunca lleva ARL, pensión ni caja.
            $upc = ['valor' => null, 'zona' => 'normal', 'edad' => null, 'advertencia' => null];
            $cliente = $cedula ? Cliente::where('cedula', $cedula)->first() : null;

            if (! $cliente) {
                $upc['advertencia'] = 'No se encontró el cliente por cédula: no se puede calcular el valor de UPC adicional.';
            } else {
                $upc = UpcAdicionalService::valorParaCliente($cliente);
            }

            $epsMes = $upc['valor'] ?? 0;
            $arlMes = 0;
            $penMes = 0;
            $cajaMes = 0;
            $eps = $epsMes; // no se prorratea por días: es un valor fijo mensual
            $arl = 0;
            $pen = 0;
            $caja = 0;
            $ss = $eps;
            $diasArl = $dias;
            $diasAfp = $dias;
            $diasCaja = $dias;
        } else {
            // ── Normal: calculos por mes completo ──────────────────────
            $epsMes = ($plan && $plan->incluye_eps) ? $r($ibc * $pctEps / 100) : 0;
            $arlMes = ($plan && $plan->incluye_arl) ? $r($ibc * $pctArl / 100) : 0;
            $penMes = ($plan && $plan->incluye_pension) ? $r($ibc * $pctPen / 100) : 0;
            $cajaMes = ($plan && $plan->incluye_caja) ? $r($ibc * $pctCaja / 100) : 0;

            // ── Cargo sin-CCF: dependiente E (id=0) o Ingreso-Retiro (id=12) sin caja ──
            // Solo cuando hay planilla que pagar: con 0 días no se cotiza nada y el
            // cargo dejaría la caja en $100 sola. Misma condición que en
            // Contrato::calcularCotizacion().
            $tipoModalidadIdInt = (int) ($p['tipo_modalidad_id'] ?? -99);
            if ($cajaMes === 0 && $dias > 0 && in_array($tipoModalidadIdInt, Contrato::IDS_SIN_CCF)
                && $plan && ! $plan->incluye_caja) {
                $cajaMes = Contrato::CARGO_SIN_CCF;
            }

            // Prorratear por dias cotizados (dias/30); admon y seguro siempre completos.
            // ceil al centena superior en TODOS los subsistemas: es la regla que
            // aplica el operador PILA (Res. 2388). Con round() la cotización queda
            // $100 por debajo de lo que liquida Enlace en ARL/AFP/CAJA.
            // Fuente única: Contrato::calcularCotizacion().
            $eps = $dias < 30 ? $r($epsMes * $dias / 30) : $epsMes;
            $arl = $dias < 30 ? $r($arlMes * $dias / 30) : $arlMes;
            $pen = $dias < 30 ? $r($penMes * $dias / 30) : $penMes;
            // Cargo sin-CCF es fijo: NO se prorratea por días
            $caja = ($cajaMes === Contrato::CARGO_SIN_CCF)
                ? $cajaMes
                : ($dias < 30 ? $r($cajaMes * $dias / 30) : $cajaMes);
            // Dos pasos: el día simbólico de pensión que la planilla paga aunque
            // el plan no venda pensión. Misma regla que Contrato::calcularCotizacion().
            if ($dias > 0 && $pen == 0 && in_array((int) ($p['tipo_modalidad_id'] ?? -99), TipoModalidad::IDS_DOS_PASOS, true)) {
                $pen = $penMes = PilaCotizanteCalculator::roundPila(
                    PilaCotizanteDosPasos::ibcUnDia((int) round($ibc)) * $pctPen / 100
                );
            }

            $diasArl = $dias;
            $diasAfp = $dias;
            $diasCaja = $dias;
            $ss = $eps + $arl + $pen + $caja;
        }

        // ── Cargo de planilla de Tipo E - X1 ───────────────────────────────
        // Lo llevan sus cuatro planes. El cliente lo ve como seguridad social,
        // así que suma dentro de $ss y no en la administración; al facturar
        // aterriza en `facturas.otros`, que es la bolsa que suma al total junto
        // a `total_ss` (`otros_admon` es la de administración, y no es esta).
        // Se prorratea por días igual que el resto de la SS en una modalidad
        // normal; en tiempo parcial va completo, como sus demás aportes.
        $otros = 0;
        if (PilaCotizanteEX1::llevaOtros($p['tipo_modalidad_id'] ?? null)) {
            $otrosMes = PilaCotizanteEX1::otrosPlanilla();
            $otros = $esTP ? $otrosMes : (int) $r($otrosMes * $dias / 30);
            $ss += $otros;
        }

        // Admon total = administracion + admon_asesor
        $admonTotal = $admon + $admonAsesor;
        $tieneIva = false;
        if ($cedula) {
            $iva = DB::table('clientes')->where('cedula', (int) $cedula)->value('iva');
            $tieneIva = strtoupper(trim($iva ?? '')) === 'SI';
        }
        $pctIva = $tieneIva ? ConfiguracionBrynex::porcentajeIva() : 0;
        $iva = $tieneIva ? $r($admonTotal * $pctIva / 100) : 0;
        $total = $ss + $seguro + $admonTotal + $iva;

        // El 40% del salario no puede quedar por debajo del piso legal de la modalidad
        // (el minimo, o su fraccion en tiempo parcial; UPC no tiene piso). Sin este max
        // el badge "sug:" del formulario mostraba un IBC ilegal — p.ej. 700.400 sobre un
        // salario minimo — porque esta respuesta pisa el calculo que el JS ya hacia bien.
        $ibcSugerido = null;
        if ($esIndep) {
            // round y no $r(): el IBC es una base de cotizacion, no un valor cobrado.
            // Con el ceil a la centena el badge decia 1.751.000 mientras el campo tenia
            // 1.750.905 — $95 de diferencia que se leen como "te falta subirlo". El JS
            // calcula con round, asi que esta es la misma cuenta de los dos lados.
            $ibcSugerido = max(
                round($salario * ConfiguracionBrynex::pctIbcIndependienteSugerido() / 100),
                $tipoModalidad->salarioMinimoPermitido()
            );
        }

        return [
            'eps' => $eps,
            'arl' => $arl,
            'pen' => $pen,
            'caja' => $caja,
            'ss' => $ss,
            // Cargo propio de Tipo E - X1, ya sumado dentro de 'ss'. Se devuelve
            // aparte para poder pintarlo como una línea más del cotizador.
            'otros' => $otros,
            'seguro' => $seguro,
            'admon' => $admonTotal,
            'admonBase' => $admon,
            'admonAsesor' => $admonAsesor,
            'iva' => $iva,
            'total' => $total,
            'dias' => $dias,
            'epsMes' => $epsMes,
            'arlMes' => $arlMes,
            'penMes' => $penMes,
            'cajaMes' => $cajaMes,
            'ibcSugerido' => $ibcSugerido,
            'pctEps' => $pctEps,
            'pctPen' => $pctPen,
            'pctArl' => $pctArl,
            'pctCaja' => $pctCaja,
            // Tiempo Parcial
            'es_tiempo_parcial' => $esTP,
            'dias_arl' => $esTP ? $diasArl : null,
            'dias_afp' => $esTP ? $diasAfp : null,
            'dias_caja' => $esTP ? $diasCaja : null,
            // Modalidad UPC
            'es_upc' => (int) ($p['tipo_modalidad_id'] ?? 0) === Contrato::MODALIDAD_UPC,
            'upc_zona' => $upc['zona'] ?? null,
            'upc_edad' => $upc['edad'] ?? null,
            'upc_advertencia' => $upc['advertencia'] ?? null,
            // Contexto adicional útil para la IA
            'plan_nombre' => $plan->nombre ?? null,
            'tipo_modalidad_nombre' => $tipoModalidad->nombre ?? null,
        ];
    }
}
