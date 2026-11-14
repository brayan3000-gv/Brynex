<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Cada prospecto de la demo pública trabaja en su propio aliado: lo que crea
 * (personas, empresas, contratos, con nombres y cédulas que a veces son
 * reales) no lo ve el siguiente.
 *
 * Cada noche scripts/demo/servidor/reiniciar.sh siembra el espacio 0 (aliado
 * 100, la cuenta compartida que sale en el login y la de los videos) y los
 * espacios 1…DEMO_ESPACIOS (aliados 101, 102…), todos con la misma gente
 * inventada pero con cédulas, NIT e ids propios (ver prefijo()). Al
 * registrarse, el prospecto recibe el primer espacio libre del día; si vuelve
 * con el mismo celular, el mismo. Si se acaban, entra al compartido.
 */
class EspaciosDemo
{
    public const ALIADO_BASE = 100;

    /** Las cédulas y NIT del espacio empiezan por aquí: 9985 en el 0, 97NN en los demás. */
    public static function prefijo(int $espacio): string
    {
        return $espacio > 0 ? sprintf('97%02d', $espacio) : '9985';
    }

    public static function aliado(int $espacio): int
    {
        return self::ALIADO_BASE + $espacio;
    }

    /** La cuenta superadmin del espacio (la que se abre al registrarse). */
    public static function cedulaUsuario(int $espacio): string
    {
        return self::prefijo($espacio).'000001';
    }

    public static function cuantos(): int
    {
        return min(99, max(0, (int) config('demo.espacios', 0)));
    }

    /**
     * El espacio de este celular hoy: el que ya tenía o el primero libre que
     * esté sembrado. 0 (el compartido) si no hay espacios o se acabaron.
     * La caché se borra en el reinicio de cada noche, así que todo vuelve a quedar libre.
     */
    public static function asignar(string $celular): int
    {
        $total = self::cuantos();
        if ($total === 0 || $celular === '') {
            return 0;
        }

        try {
            return Cache::lock('demo_espacios_lock', 10)->block(5, function () use ($celular, $total) {
                $asignados = Cache::get('demo_espacios', []);
                if (isset($asignados[$celular])) {
                    return (int) $asignados[$celular];
                }
                $ocupados = array_flip(array_map('intval', $asignados));
                for ($n = 1; $n <= $total; $n++) {
                    if (! isset($ocupados[$n]) && self::usuario($n)) {
                        $asignados[$celular] = $n;
                        Cache::forever('demo_espacios', $asignados);

                        return $n;
                    }
                }

                return 0;
            });
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public static function usuario(int $espacio): ?User
    {
        return User::where('cedula', self::cedulaUsuario($espacio))
            ->where('aliado_id', self::aliado($espacio))
            ->where('activo', 1)
            ->first();
    }

    /** Cuántos espacios quedan libres hoy (para el listado y los avisos). */
    public static function libres(): int
    {
        return max(0, self::cuantos() - count(Cache::get('demo_espacios', [])));
    }
}
