<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * La sucursal con la que va una razón social en el plano.
 *
 * Una empresa prestada tiene una sucursal por aliado ante el operador (01
 * Brygar, 02 Fecop, 03 Luis López…), y es siempre la misma para todas las
 * empresas de ese aliado. Por eso manda la del aliado (`aliados.codigo_sucursal`
 * y `nombre_sucursal`); solo si el aliado no tiene una se usa la de la razón
 * social, como siempre.
 *
 * La razón social de independientes no tiene sucursal: cada persona se liquida
 * como aportante único, así que ahí no se pone la del aliado.
 */
class SucursalOperador
{
    /**
     * @return array{codigo: string, nombre: string, origen: 'aliado'|'razon_social'}
     */
    public static function de(object $rs): array
    {
        $aliado = empty($rs->es_independiente) ? self::delAliado((int) ($rs->aliado_id ?? 0)) : null;

        if ($aliado) {
            return ['codigo' => $aliado->codigo_sucursal, 'nombre' => (string) $aliado->nombre_sucursal, 'origen' => 'aliado'];
        }

        return [
            'codigo' => trim((string) ($rs->codigo_sucursal ?? '')),
            'nombre' => trim((string) ($rs->nombre_sucursal ?? '')),
            'origen' => 'razon_social',
        ];
    }

    /**
     * Copia de la razón social con la sucursal que corresponde, para pasarla
     * tal cual a los generadores de planos. No toca el objeto original.
     */
    public static function aplicar(object $rs): object
    {
        $sucursal = self::de($rs);
        $copia = clone $rs;
        $copia->codigo_sucursal = $sucursal['codigo'];
        $copia->nombre_sucursal = $sucursal['nombre'];

        return $copia;
    }

    /**
     * La sucursal del aliado, o null si no tiene código. Sin caché: los
     * workers de las colas viven horas y no verían un cambio en la ficha.
     */
    private static function delAliado(int $aliadoId): ?object
    {
        $fila = $aliadoId
            ? DB::table('aliados')->where('id', $aliadoId)->first(['codigo_sucursal', 'nombre_sucursal'])
            : null;

        return $fila && trim((string) $fila->codigo_sucursal) !== ''
            ? (object) ['codigo_sucursal' => trim($fila->codigo_sucursal), 'nombre_sucursal' => trim((string) $fila->nombre_sucursal)]
            : null;
    }
}
