<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\Cache;

/**
 * El operador de planilla (Enlace / Simple) de la demo.
 *
 * SuaporteApiService le pasa aquí cada llamada que haría al operador. Se
 * contesta con la misma forma que la respuesta real, así que la liquidación
 * corre por el controlador de siempre: arma el plano, lo «envía», guarda el
 * número de planilla y el valor a pagar, y abre un pago PSE de mentiras.
 *
 * El valor a pagar sale del mismo archivo plano (suma de los aportes de cada
 * registro tipo 2), para que cuadre con lo que BryNex calculó.
 */
class PilaSimulada
{
    /** Posiciones (1-based, inclusive) de los aportes en el registro tipo 2. */
    private const APORTES_TIPO2 = [
        [272, 280], // total pensión
        [281, 289], // FSP solidaridad
        [290, 298], // FSP subsistencia
        [315, 323], // salud
        [399, 407], // riesgos laborales
        [415, 423], // caja de compensación
        [431, 439], // SENA
        [447, 455], // ICBF
    ];

    public static function autenticar(): array
    {
        return ['success' => true, 'token' => 'demo', 'demo' => true];
    }

    public static function aportante(string $tipoDocumento, string $numeroDocumento): array
    {
        return [
            'success' => true,
            'id' => 900000 + ((int) substr(preg_replace('/\D/', '', $numeroDocumento), -5)),
            'aportante' => [
                'tipoIdentificacion' => $tipoDocumento,
                'numeroIdentificacion' => $numeroDocumento,
                'estado' => 'ACTIVE',
                'demo' => true,
            ],
        ];
    }

    public static function fichaAportante(int $aportanteId): array
    {
        return ['success' => true, 'ficha' => ['id' => $aportanteId, 'demo' => true]];
    }

    public static function crearAportante(array $datos): array
    {
        return self::aportante($datos['tipo_documento'] ?? 'CC', (string) ($datos['documento'] ?? '0'));
    }

    public static function autorizar(): array
    {
        return ['success' => true];
    }

    /** La validación del plano: siempre limpia, con número de planilla. */
    public static function validar(string $contenidoTxt): array
    {
        $numero = random_int(88000000, 88999999);
        $codigo = random_int(9000000, 9999999);
        $total = self::totalDelPlano($contenidoTxt);

        Cache::put("demo_pila_{$numero}", ['total' => $total, 'codigo' => $codigo], now()->addDays(40));

        return [
            'success' => true,
            'liquidada' => true,
            'estado_validacion' => 'VALIDADA',
            'codigo_planilla' => $codigo,
            'numero_planilla' => $numero,
            'total_errores' => 0,
            'errores_cotizante' => [],
            'errores_empresa' => [],
            'advertencias' => [],
            'response' => ['demo' => true, 'mensaje' => 'Planilla liquidada en el operador de PRUEBA (simulada).'],
        ];
    }

    public static function corregir(int $codigoPlanilla): array
    {
        return [
            'success' => true,
            'liquidada' => false,
            'estado_validacion' => 'VALIDADA',
            'codigo_planilla' => $codigoPlanilla,
            'numero_planilla' => 0,
            'total_errores' => 0,
            'errores_cotizante' => [],
            'errores_empresa' => [],
            'advertencias' => [],
            'response' => ['demo' => true],
        ];
    }

    public static function inconsistencias(): array
    {
        return ['success' => true, 'inconsistencias' => []];
    }

    public static function totales(int $numeroPlanilla): array
    {
        $guardado = Cache::get("demo_pila_{$numeroPlanilla}");

        if (! $guardado) {
            return [
                'success' => false,
                'message' => "La planilla {$numeroPlanilla} no existe en el operador de prueba.",
            ];
        }

        $limite = now()->addDays(5)->toDateString();
        // GU = guardada sin pagar; OK = pagada (ver SuaporteApiService::ESTADOS_PAGADA).
        $estado = ! empty($guardado['pagada']) ? 'OK' : 'GU';

        return [
            'success' => true,
            'total_pagar' => $guardado['total'],
            'total_sin_mora' => $guardado['total'],
            'valor_mora' => 0,
            'fecha_limite' => $limite,
            'estado' => $estado,
            'totales' => [
                'numeroPlanilla' => $numeroPlanilla,
                'totalPagar' => $guardado['total'],
                'valorMora' => 0,
                'fechaLimite' => $limite,
                'estadoPlanilla' => $estado,
                'demo' => true,
            ],
        ];
    }

    public static function urlPago(int $numeroPlanilla): array
    {
        $guardado = Cache::get("demo_pila_{$numeroPlanilla}");

        if (! $guardado) {
            return ['success' => false, 'message' => 'Planilla no encontrada en el operador de prueba.'];
        }
        if (! empty($guardado['pagada'])) {
            return ['success' => false, 'message' => 'La planilla ya fue pagada (pago de prueba).'];
        }

        return ['success' => true, 'url_pago' => route('demo.pago-pse', $numeroPlanilla)];
    }

    /** consultarAfiliacion() del operador: el registro oficial simulado. */
    public static function afiliacion(string $tipoDocumento, string $numeroDocumento): array
    {
        $r = RegistroOficialSimulado::consultar($numeroDocumento, $tipoDocumento);

        return ['success' => true, 'registrado' => $r['encontrado'], 'afiliacion' => $r['raw']];
    }

    /** Suma de los aportes de todos los registros tipo 2 del plano. */
    public static function totalDelPlano(string $contenidoTxt): int
    {
        $total = 0;

        foreach (preg_split('/\r\n|\r|\n/', $contenidoTxt) as $linea) {
            if (! str_starts_with($linea, '02')) {
                continue;
            }

            foreach (self::APORTES_TIPO2 as [$desde, $hasta]) {
                $total += (int) substr($linea, $desde - 1, $hasta - $desde + 1);
            }
        }

        return $total;
    }
}
