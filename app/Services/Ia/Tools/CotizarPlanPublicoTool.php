<?php

namespace App\Services\Ia\Tools;

/**
 * Versión "de cara al cliente" de cotizar_plan, usada únicamente en el canal WhatsApp.
 * Delega el cálculo real en CotizarPlanTool (misma lógica, mismos precios) pero devuelve
 * solo el valor final: nunca expone el desglose interno (EPS/ARL/pensión/caja) ni la
 * comisión del asesor, que son datos operativos internos del aliado.
 */
class CotizarPlanPublicoTool implements IaToolInterface
{
    private CotizarPlanTool $interno;

    public function __construct()
    {
        $this->interno = new CotizarPlanTool();
    }

    public function nombre(): string
    {
        return 'cotizar_plan';
    }

    public function descripcion(): string
    {
        return $this->interno->descripcion();
    }

    public function schema(): array
    {
        $schema = $this->interno->schema();

        // En WhatsApp la gente dice lo que GANA, no su base de cotización. Ver ibcIndependiente().
        $schema['properties']['salario']['description'] = 'Lo que el cliente dice que gana o le pagan al mes, en pesos. '
            .'Si es empleado, es su salario. Si es independiente o contratista, pon lo que GANA o el valor mensual del '
            .'contrato: la herramienta calcula sola la base de cotización (el 40 %, nunca menos del mínimo). No hagas tú '
            .'esa cuenta. Si no da un valor, OMÍTELO: se cotiza sobre el salario mínimo.';
        $schema['properties']['salario_es_ibc'] = [
            'type' => 'boolean',
            'description' => 'true SOLO si el cliente independiente dice que ese valor YA es su base de cotización '
                .'(«cotizo sobre 2 millones», «mi IBC es…»), no lo que gana. Por defecto, omítelo.',
        ];

        return $schema;
    }

    /**
     * Base de cotización de un independiente a partir de lo que gana.
     *
     * El independiente y el contratista cotizan sobre el 40 % de lo que ganan, y nunca por debajo
     * del salario mínimo. Antes la herramienta tomaba lo que ganaba como base: el 15-oct-2026 un
     * asesor preguntó por un contratista de $2.500.000 —y aclaró «yo sé que cotiza bajo el salario
     * mínimo»— y recibió $819.400 al mes en riesgo 3, cuando sobre el mínimo son $587.800. Le
     * cotizamos $231.600 de más al mes a alguien que estaba midiendo si sus clientes lo pagan.
     *
     * No aplica desde el exterior (cotiza voluntario sobre lo que elija) ni a la salud pagada
     * por otra persona, ni cuando el cliente dice que el valor ya es su IBC.
     *
     * @return array{0: float, 1: ?string} [base, nota para el modelo]
     */
    private function ibcIndependiente(array $input): array
    {
        $ingreso = (float) $input['salario'];
        $minimo = (float) \App\Models\ConfiguracionBrynex::salarioMinimo();
        $base = max(round($ingreso * 0.4), $minimo);

        $nota = 'Lo que gana ($'.number_format($ingreso, 0, ',', '.').') no es la base: un independiente cotiza sobre el 40 % '
            .($base > $ingreso * 0.4
                ? '($'.number_format($ingreso * 0.4, 0, ',', '.').'), que queda por debajo del mínimo, así que se cotizó sobre el salario mínimo.'
                : '($'.number_format($base, 0, ',', '.').').')
            .' Si lo explicas, dilo así, en una frase.';

        return [$base, $nota];
    }

    public function ejecutar(array $input, array $contexto): array
    {
        $notaIbc = null;
        $esIndependiente = ! empty($input['es_independiente'])
            && empty($input['desde_exterior'])
            && empty($input['paga_por_otra_persona'])
            && empty($input['tiempo_parcial_dias']);
        if ($esIndependiente && ! empty($input['salario']) && (float) $input['salario'] > 0 && empty($input['salario_es_ibc'])) {
            [$input['salario'], $notaIbc] = $this->ibcIndependiente($input);
        }
        unset($input['salario_es_ibc']);

        $resultado = $this->interno->ejecutar($input, $contexto);

        if (isset($resultado['error'])) {
            return $resultado;
        }

        $salida = [
            'plan'                      => $resultado['plan_nombre'] ?? null,
            'modalidad'                 => $resultado['tipo_modalidad_nombre'] ?? null,
            'fecha_afiliacion'          => $resultado['fecha_afiliacion'] ?? null,
            'valor_mensual_total'       => $resultado['total'] ?? null,
            'costo_afiliacion_sugerido' => $resultado['costo_afiliacion_sugerido'] ?? null,
            'dias_cotizados'            => $resultado['dias'] ?? null,
            'salario_usado'             => $resultado['salario_usado'] ?? null,
            'nivel_arl_usado'           => $resultado['nivel_arl_usado'] ?? null,
            'nivel_arl_default'         => $resultado['nivel_arl_default'] ?? null,
            'coincidencia_exacta'       => $resultado['coincidencia_exacta'] ?? true,
        ];

        if (!empty($resultado['plan_pago_inicial'])) {
            $salida['plan_pago_inicial'] = $resultado['plan_pago_inicial'];
            $salida['nota'] = 'valor_mensual_total es el cobro recurrente desde mes_3_nombre en adelante (ya incluye '
                . 'seguridad social y administración). Por defecto menciona SOLO costo_afiliacion_sugerido (pago '
                . 'único de afiliación) y valor_mensual_total (mensual) — NUNCA hables de precios proporcionales ni '
                . 'de "primer mes más económico" por iniciativa propia: confunde al cliente con cifras que no pidió. '
                . 'La ÚNICA excepción: si pregunta puntualmente cuánto pagaría el próximo mes si se afilia hoy (o '
                . 'desde una fecha específica), dale solo ese valor puntual (mes_2_valor, con el nombre real del '
                . 'mes: mes_2_nombre) — no el resto del desglose ni los demás meses. Preséntalo como valor de '
                . 'referencia habitual, no como cifra cerrada — el asesor confirma el valor final al afiliar '
                . '(hablar_con_asesor).';
        } else {
            $salida['nota'] = 'valor_mensual_total ya incluye seguridad social y administración — es el cobro '
                . 'recurrente de cada mes. costo_afiliacion_sugerido es un cobro APARTE y de una sola vez, '
                . 'preséntalo siempre como un valor de referencia habitual, nunca como una cifra cerrada — el valor '
                . 'final lo confirma un asesor humano al momento de afiliar (hablar_con_asesor).';
        }

        if (!empty($resultado['nota_plan'])) {
            $salida['nota_plan'] = $resultado['nota_plan'];
        }
        if (!empty($resultado['nota_afp'])) {
            $salida['nota_afp'] = $resultado['nota_afp'];
        }

        if ($notaIbc) {
            $salida['nota_base_cotizacion'] = $notaIbc;
        }

        return $salida;
    }
}
