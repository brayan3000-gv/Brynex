<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\BancoCuenta;
use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Variables con nombre para las plantillas de cobro por WhatsApp.
 *
 * Las plantillas viejas (cobro_con_valor2, cobro_cierre…) arman sus parámetros
 * por posición, con moldes fijos en el job y en CobrosController. Una plantilla
 * con `variables_mapa` hecho SOLO de los campos de aquí se llena desde el mapa,
 * con los datos del aliado que envía: así una misma plantilla de la cuenta
 * compartida sirve a todos, cada uno con su nombre, su mora y su día de retiros.
 *
 * Nació con «aviso_retiro_eps», la que pidió Grupo Fecop (21-oct-2026).
 */
class WhatsappVariablesCobro
{
    /** Campo => qué pone. Lo que no esté aquí deja la plantilla en el molde viejo. */
    public const CAMPOS = [
        'cliente.nombre' => 'Nombre corto del cliente (o contacto de la empresa)',
        'aliado.nombre' => 'Nombre del aliado',
        'cobro.valor' => 'Valor a pagar con mora estimada ($150.000)',
        'cobro.dia_pago' => 'Día hábil de pago del aliado (10 si no lo configuró)',
        'cobro.cuentas' => 'Cuentas para consignar del aliado',
        'aliado.celular_soporte' => 'WhatsApp donde reciben comprobantes',
        'mora.minimo' => 'Mora mínima del aliado ($2.000)',
        'eps.tiempo_retiros' => 'Cuánto falta para los retiros de la EPS («3 días», «horas»)',
    ];

    /** Lo que se pone en «faltan solo … para los retiros» si no hay día configurado o ya pasó. */
    public const TIEMPO_RETIROS_SIN_DIA = 'pocos días';

    /** Por aliado: no se repiten consultas para cada destinatario del lote. */
    private array $datosAliado = [];

    public static function aplica(WhatsappPlantilla $plantilla): bool
    {
        $mapa = $plantilla->variables_mapa;

        return is_array($mapa) && $mapa !== []
            && array_diff(array_values($mapa), array_keys(self::CAMPOS)) === [];
    }

    /**
     * Parámetros del cuerpo en el orden de {{1}}, {{2}}…
     *
     * @param  float|null  $valorCobro  Solo hace falta si la plantilla usa cobro.valor.
     */
    public function parametros(WhatsappPlantilla $plantilla, int $aliadoId, string $nombreCliente, ?float $valorCobro = null, ?Carbon $hoy = null): array
    {
        $mapa = $plantilla->variables_mapa;
        ksort($mapa, SORT_NUMERIC);

        $datos = $this->datosAliado($aliadoId);

        return array_values(array_map(fn (string $campo) => match ($campo) {
            'cliente.nombre' => $nombreCliente ?: 'Hola',
            'aliado.nombre' => $datos['nombre'],
            'cobro.valor' => self::pesos($valorCobro ?? 0),
            'cobro.dia_pago' => $datos['dia_pago'],
            'cobro.cuentas' => $datos['cuentas'],
            'aliado.celular_soporte' => $datos['celular'],
            'mora.minimo' => self::pesos($datos['mora_minimo']),
            'eps.tiempo_retiros' => self::tiempoRetiros($datos['dia_retiros'], $hoy ?? today()),
        }, $mapa));
    }

    /**
     * «3 días», «1 día», o «horas» el mismo día. Si el aliado no tiene el día o
     * ya pasó este mes, «pocos días»: el aviso se sigue leyendo bien.
     */
    public static function tiempoRetiros(?int $diaRetiros, Carbon $hoy): string
    {
        if (! $diaRetiros) {
            return self::TIEMPO_RETIROS_SIN_DIA;
        }

        // Día 31 en un mes de 30 = el último del mes.
        $faltan = min($diaRetiros, $hoy->daysInMonth) - $hoy->day;

        return match (true) {
            $faltan < 0 => self::TIEMPO_RETIROS_SIN_DIA,
            $faltan === 0 => 'horas',
            $faltan === 1 => '1 día',
            default => "{$faltan} días",
        };
    }

    private static function pesos(float $valor): string
    {
        return '$'.number_format($valor, 0, ',', '.');
    }

    private function datosAliado(int $aliadoId): array
    {
        if (isset($this->datosAliado[$aliadoId])) {
            return $this->datosAliado[$aliadoId];
        }

        $config = WhatsappConfig::where('aliado_id', $aliadoId)->first();
        $cfg = DB::table('configuracion_aliado')
            ->where('aliado_id', $aliadoId)
            ->whereNull('plan_id')
            ->first(['mora_dia_habil_inicio', 'mora_minimo', 'dia_retiros_eps']);

        return $this->datosAliado[$aliadoId] = [
            'nombre' => $config?->nombre_cuenta ?: (Aliado::find($aliadoId)?->nombre ?? 'BryNex'),
            'dia_pago' => $cfg?->mora_dia_habil_inicio ? (string) $cfg->mora_dia_habil_inicio : '10',
            'cuentas' => BancoCuenta::textoCobro($aliadoId),
            'celular' => $config?->numero_telefono ?: 'no tiene configurado',
            'mora_minimo' => (float) ($cfg->mora_minimo ?? 2000),
            'dia_retiros' => $cfg?->dia_retiros_eps ? (int) $cfg->dia_retiros_eps : null,
        ];
    }
}
