<?php

namespace App\Services\ArlColmena;

use Illuminate\Support\Facades\Cache;

/**
 * Lo que va haciendo el robot mientras una petición larga está en curso.
 *
 * Abrir sesión en el portal de Colmena y radicar pueden tardar más de un
 * minuto, y la pantalla no puede enterarse de nada hasta que la petición
 * termina. La pantalla manda un id al empezar, el servidor va anotando aquí
 * cada paso bajo ese id y la pantalla los lee cada segundo. Son frases, nunca
 * datos del trabajador ni claves.
 */
class ColmenaProgreso
{
    private const TTL_SEGUNDOS = 600;

    /** Los ids los inventa el navegador: solo se aceptan los que parecen uuid. */
    public static function valido(?string $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) === 1;
    }

    public static function paso(?string $id, string $texto): void
    {
        if (! self::valido($id)) {
            return;
        }

        $clave = self::clave($id);
        $actual = Cache::get($clave, ['pasos' => [], 'fin' => false]);
        $actual['pasos'][] = $texto;

        Cache::put($clave, $actual, self::TTL_SEGUNDOS);
    }

    public static function terminar(?string $id): void
    {
        if (! self::valido($id)) {
            return;
        }

        $clave = self::clave($id);
        $actual = Cache::get($clave, ['pasos' => [], 'fin' => false]);
        $actual['fin'] = true;

        Cache::put($clave, $actual, self::TTL_SEGUNDOS);
    }

    /** @return array{pasos: string[], fin: bool} */
    public static function leer(?string $id): array
    {
        return self::valido($id)
            ? Cache::get(self::clave($id), ['pasos' => [], 'fin' => false])
            : ['pasos' => [], 'fin' => false];
    }

    private static function clave(string $id): string
    {
        return 'colmena:progreso:'.$id;
    }
}
