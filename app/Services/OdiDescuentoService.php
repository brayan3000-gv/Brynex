<?php

namespace App\Services;

use App\Models\OdiArl;
use App\Models\OdiDescuento;
use App\Models\OperadorPlanillaApi;
use Illuminate\Support\Facades\DB;

/**
 * Detecta lo que una planilla le descontó a las ODI de la ARL.
 *
 * El API de Enlace no tiene dónde meter la ODI (solo recibe el TXT): se digita
 * en el portal, en el cotizante → novedades → «ODI (Descuentos de ARL)». Lo que
 * sí devuelve el API son los totales por administradora. Si el aporte a la ARL
 * que cobró el operador es menor que el que sale de los planos, la diferencia
 * es lo que se descontó por ODI.
 *
 * El cálculo es el mismo del TXT (PilaCotizanteCalculator): IBC proporcional a
 * los días × tarifa del nivel de riesgo, redondeado a la centena de arriba.
 * Contra las planillas de BRYGAR jul-oct 2026 cuadró al peso en las que no
 * tenían descuento.
 */
class OdiDescuentoService
{
    /** Diferencias menores se tratan como redondeo, no como descuento. */
    public const UMBRAL = 1000;

    /**
     * En simulación no se escribe nada, así que el saldo de cada ODI no baja:
     * se lleva aquí lo que ya "consumieron" las planillas anteriores.
     *
     * @var array<int, float>
     */
    private array $simulado = [];

    /** Aporte a la ARL que sale de unas filas de `planos`. */
    public static function arlDePlanos(iterable $planos): int
    {
        $total = 0;
        foreach ($planos as $p) {
            if (trim((string) ($p->cod_arl ?? '')) === '') {
                continue;
            }
            $salario = (int) ($p->salario_basico ?? 0);
            $dias = (int) ($p->num_dias ?? 30);
            $ibc = $dias < 30 ? (int) round($salario * $dias / 30) : $salario;
            $nivel = max(1, min(5, (int) ($p->nivel_riesgo ?? 1)));
            $total += PilaCotizanteCalculator::roundPila($ibc * PilaCotizanteCalculator::TARIFAS_ARL[$nivel]);
        }

        return $total;
    }

    /**
     * Compara lo esperado con lo que cobró el operador.
     *
     * @return array{esperada:int, cobrada:int, descuento:int}|null  null si no
     *         hay con qué comparar: sin totales, sin planos, planilla en cero
     *         (corrección) o sin ARL en el operador.
     */
    public function analizar(OperadorPlanillaApi $registro): ?array
    {
        if (! $registro->total_administradoras || (float) $registro->valor_total <= 0) {
            return null;
        }

        $arp = collect(json_decode($registro->total_administradoras, true) ?: [])
            ->where('tipoAdministradora', 'ARP');
        // Sin la ARL en el desglose no hay descuento posible: es una planilla
        // sin riesgos (independiente sin ARL), no una ODI del 100 %.
        if ($arp->isEmpty()) {
            return null;
        }
        $cobrada = (int) $arp->sum('valorSinMora');

        $planos = DB::table('planos')
            ->where('aliado_id', $registro->aliado_id)
            ->whereNull('deleted_at')
            ->where('numero_planilla', $registro->numero_planilla)
            ->get(['salario_basico', 'num_dias', 'nivel_riesgo', 'cod_arl']);
        if ($planos->isEmpty()) {
            return null;
        }

        $esperada = self::arlDePlanos($planos);

        // El 1 % del aporte es del Fondo de Riesgos Laborales y nunca se
        // descuenta: si el operador cobró cero, no es una ODI.
        $descuento = ($cobrada > 0 && $esperada - $cobrada >= self::UMBRAL) ? $esperada - $cobrada : 0;

        return ['esperada' => $esperada, 'cobrada' => $cobrada, 'descuento' => $descuento];
    }

    /**
     * Aplica el descuento de la planilla a las ODI con saldo de su razón
     * social, de la más vieja a la más nueva.
     *
     * Idempotente: una planilla que ya tiene descuentos registrados no se
     * vuelve a aplicar (los cargados a mano o por la corrección cuentan).
     *
     * @return array{analisis:?array, aplicado:float, sin_odi:float, detalle:array<int,array{numero:string,valor:float}>, ya_aplicada:bool}
     */
    public function aplicar(OperadorPlanillaApi $registro, bool $ejecutar = true): array
    {
        $resultado = ['analisis' => null, 'aplicado' => 0.0, 'sin_odi' => 0.0, 'detalle' => [], 'ya_aplicada' => false];

        $analisis = $this->analizar($registro);
        $resultado['analisis'] = $analisis;
        if (! $analisis || $analisis['descuento'] <= 0) {
            return $resultado;
        }

        $yaAplicada = OdiDescuento::where('aliado_id', $registro->aliado_id)
            ->where('numero_planilla', (string) $registro->numero_planilla)
            ->exists();
        if ($yaAplicada) {
            $resultado['ya_aplicada'] = true;

            return $resultado;
        }

        $porAplicar = (float) $analisis['descuento'];
        // Solo cuentan las ODI que BryNex ya tenía registradas cuando se liquidó
        // la planilla, no basta con la fecha de la orden: un descuento anterior
        // al registro puede ser de otra ODI que nunca se cargó (Work at Home,
        // ago/sep-2026: $240.000 y $17.000 que no eran de la 1849379).
        $liquidada = (string) $registro->created_at;
        $odis = OdiArl::conSaldo((int) $registro->aliado_id, (int) $registro->razon_social_id)
            ->filter(fn ($o) => (string) $o->created_at <= $liquidada);

        $aplicar = function () use ($odis, $registro, &$porAplicar, &$resultado, $ejecutar) {
            foreach ($odis as $odi) {
                if ($porAplicar <= 0) {
                    break;
                }
                $saldo = $odi->saldo - ($ejecutar ? 0 : ($this->simulado[$odi->id] ?? 0));
                $valor = min($porAplicar, $saldo);
                if ($valor <= 0) {
                    continue;
                }

                if ($ejecutar) {
                    $odi->descuentos()->create([
                        'aliado_id' => $registro->aliado_id,
                        'numero_planilla' => (string) $registro->numero_planilla,
                        'periodo' => $registro->periodo_cotizacion,
                        'valor' => $valor,
                        'fecha' => substr((string) $registro->created_at, 0, 10),
                        'origen' => 'api',
                        'observacion' => 'Detectado al comparar el aporte ARL cobrado por el operador con el de los planos',
                    ]);
                    $odi->recalcularDescontado();
                } else {
                    $this->simulado[$odi->id] = ($this->simulado[$odi->id] ?? 0) + $valor;
                }

                $resultado['detalle'][] = ['numero' => $odi->numero, 'valor' => $valor];
                $resultado['aplicado'] += $valor;
                $porAplicar -= $valor;
            }
        };

        $ejecutar ? DB::transaction($aplicar) : $aplicar();

        // Lo que sobra es un descuento sin ODI registrada: se reporta, no se
        // inventa una orden.
        $resultado['sin_odi'] = max(0.0, $porAplicar);

        return $resultado;
    }
}
