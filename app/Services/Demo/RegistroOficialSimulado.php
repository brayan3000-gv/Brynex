<?php

namespace App\Services\Demo;

use App\Models\Cliente;
use Illuminate\Support\Facades\DB;

/**
 * La consulta del registro oficial (BDUA / RUAF) de la demo.
 *
 * Si la cédula ya es de alguien de la demo, devuelve sus datos: así la consulta
 * «confirma» lo que hay. Si no, inventa una persona estable para esa cédula
 * (la misma cédula da siempre la misma respuesta) con una EPS y un fondo que
 * existen en el catálogo, para que la ficha se llene como con la consulta real.
 *
 * El operador se llama «Registro oficial (simulado)»: la pantalla lo muestra y
 * nadie puede confundirlo con una consulta de verdad.
 */
class RegistroOficialSimulado
{
    private const NOMBRES = [
        ['MARIA', 'FERNANDA'], ['LUZ', 'DARY'], ['PAOLA', 'ANDREA'], ['JUAN', 'CARLOS'], ['LUIS', 'FERNANDO'],
        ['DIANA', 'MARCELA'], ['CARLOS', 'ANDRES'], ['ANGIE', 'CAROLINA'], ['JORGE', 'IVAN'], ['NATALIA', ''],
        ['KEVIN', 'SANTIAGO'], ['SANDRA', 'LILIANA'], ['CRISTIAN', 'CAMILO'], ['GLORIA', 'AMPARO'], ['HAROLD', ''],
    ];

    private const APELLIDOS = [
        'GOMEZ', 'RODRIGUEZ', 'VALENCIA', 'MOSQUERA', 'CASTILLO', 'ZAPATA', 'OCAMPO', 'HURTADO', 'RENTERIA',
        'QUINTERO', 'ARBOLEDA', 'CUERO', 'MONTAÑO', 'BERMUDEZ', 'SAAVEDRA', 'LASSO', 'MUÑOZ', 'CAICEDO',
    ];

    /** Solo entidades que operan hoy (las mismas del sembrado): nada de SALUCOOP. */
    private const EPS_VIGENTES = [2, 5, 9, 12, 18, 59, 60];   // SURA, Salud Total, Sanitas, S.O.S., Emssanar, Nueva EPS, Coosalud

    private const FONDOS_VIGENTES = [5, 8, 9, 14];             // Porvenir, Colfondos, Protección, Colpensiones

    public static function consultar(string $cedula, string $tipoDoc = 'CC'): array
    {
        $cedula = preg_replace('/\D/', '', $cedula);
        $semilla = crc32($tipoDoc.$cedula);

        $cliente = Cliente::where('aliado_id', (int) config('demo.aliado_id'))
            ->where('cedula', $cedula)
            ->first();

        if ($cliente) {
            [$n1, $n2, $a1, $a2] = [$cliente->primer_nombre, $cliente->segundo_nombre, $cliente->primer_apellido, $cliente->segundo_apellido];
            $epsId = $cliente->eps_id;
            $pensionId = $cliente->pension_id;
        } else {
            [$n1, $n2] = self::NOMBRES[$semilla % count(self::NOMBRES)];
            $a1 = self::APELLIDOS[intdiv($semilla, 7) % count(self::APELLIDOS)];
            $a2 = self::APELLIDOS[intdiv($semilla, 131) % count(self::APELLIDOS)];
            $epsId = self::elegir('eps', self::EPS_VIGENTES, $semilla);
            $pensionId = self::elegir('pensiones', self::FONDOS_VIGENTES, intdiv($semilla, 3));
        }

        $eps = $epsId ? DB::table('eps')->where('id', $epsId)->first(['nombre', 'codigo']) : null;
        $pension = $pensionId ? DB::table('pensiones')->where('id', $pensionId)->first(['razon_social', 'codigo']) : null;
        $desde = now()->subYears(2 + $semilla % 12)->startOfMonth()->toDateString();

        $raw = [
            'primerNombre' => $n1, 'segundoNombre' => $n2, 'primerApellido' => $a1, 'segundoApellido' => $a2,
            'administradoraBDUA' => $eps?->codigo, 'administradoraRUAF' => $pension?->codigo,
            'estado' => 'ACTIVO', 'regimen' => 'CONTRIBUTIVO', 'fechaAfiliacionRUAF' => $desde,
            'fuente' => 'SIMULADA — demo de BryNex, no se consultó ninguna entidad',
        ];

        return [
            'encontrado' => true,
            'operador' => 'Registro oficial (simulado)',
            'tipo_doc' => $tipoDoc,
            'primer_nombre' => (string) $n1,
            'segundo_nombre' => (string) $n2,
            'primer_apellido' => (string) $a1,
            'segundo_apellido' => (string) $a2,
            'eps_id' => $epsId,
            'eps_nombre' => $eps?->nombre,
            'eps_codigo' => $eps?->codigo,
            'pension_id' => $pensionId,
            'pension_nombre' => $pension?->razon_social,
            'pension_codigo' => $pension?->codigo,
            'estado' => 'ACTIVO',
            'regimen' => 'CONTRIBUTIVO',
            'en_ruaf' => true,
            'ruaf_desde' => $desde,
            'raw' => $raw,
        ];
    }

    /** Una de las vigentes que exista en el catálogo, siempre la misma para la misma semilla. */
    private static function elegir(string $tabla, array $vigentes, int $semilla): ?int
    {
        $ids = DB::table($tabla)->whereIn('id', $vigentes)->orderBy('id')->pluck('id')->all();

        return $ids ? (int) $ids[$semilla % count($ids)] : null;
    }
}
