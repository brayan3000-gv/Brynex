<?php

namespace App\Services\Ia;

/**
 * Lo que cuesta cada llamada a un modelo, en dólares, para ia_consumo.
 *
 * Precios oficiales por millón de tokens, tomados el 8-oct-2026 de
 * ai.google.dev/gemini-api/docs/pricing y platform.claude.com/docs/en/about-claude/pricing:
 *
 *   entrada:   tokens de entrada que no salieron de la caché
 *   cache:     tokens de entrada leídos de la caché (una décima parte de la entrada)
 *   escritura: (Claude) guardar en la caché cuesta un poco más que la entrada normal
 *   salida:    tokens de salida, incluidos los de «pensar»
 *   audio:     (Gemini) tokens de audio, que algunos modelos cobran aparte
 *
 * Antes la tabla vivía en AsistenteIaService con Gemini 3.6 Flash a 1,50/7,50, que es lo
 * que cuesta desde el 1-ene-2027: hasta oct-2026 ia_consumo registró el doble de lo real.
 */
class PreciosIa
{
    private const FLASH_3 = ['entrada' => 0.75, 'cache' => 0.075, 'salida' => 3.75];

    private const FLASH_3_DESDE_2027 = ['entrada' => 1.50, 'cache' => 0.15, 'salida' => 7.50];

    private const TABLA = [
        'claude-haiku-5-5' => ['entrada' => 0.10, 'cache' => 0.01, 'escritura' => 0.125, 'salida' => 0.50],
        'claude-haiku-4-5' => ['entrada' => 1.00, 'cache' => 0.10, 'escritura' => 1.25, 'salida' => 5.00],
        'claude-sonnet-5-5' => ['entrada' => 2.00, 'cache' => 0.10, 'escritura' => 2.50, 'salida' => 10.00],
        'claude-sonnet' => ['entrada' => 3.00, 'cache' => 0.30, 'escritura' => 3.75, 'salida' => 15.00],
        'gemini-3.5-flash-lite' => ['entrada' => 0.30, 'cache' => 0.03, 'salida' => 2.50],
        'gemini-3.5-flash' => ['entrada' => 1.50, 'cache' => 0.15, 'salida' => 9.00],
        'gemini-3.1-flash-lite' => ['entrada' => 0.25, 'cache' => 0.025, 'salida' => 1.50, 'audio' => 0.50],
        'gemini-2.5-flash-lite' => ['entrada' => 0.10, 'cache' => 0.01, 'salida' => 0.40, 'audio' => 0.30],
        'gemini-2.5-flash' => ['entrada' => 0.30, 'cache' => 0.03, 'salida' => 2.50, 'audio' => 1.00],
        'gemini-2.5-pro' => ['entrada' => 1.25, 'cache' => 0.125, 'salida' => 10.00],
        'gpt-4o-mini' => ['entrada' => 0.15, 'cache' => 0.075, 'salida' => 0.60],
    ];

    /** Lo que se cobra si el modelo no está en la tabla: mejor pasarse que quedarse corto. */
    private const DESCONOCIDO = ['entrada' => 1.00, 'cache' => 0.10, 'salida' => 5.00];

    /**
     * @return array{entrada: float, cache: float, escritura?: float, salida: float, audio?: float}
     */
    public static function de(?string $modelo): array
    {
        $modelo = strtolower((string) $modelo);

        // Gemini 3.6, 3.7 y 3.8 Flash comparten precio, y lo duplican el 1-ene-2027.
        if (preg_match('/^gemini-3\.[678]-flash(?!-lite)/', $modelo)) {
            return now()->lt('2027-01-01') ? self::FLASH_3 : self::FLASH_3_DESDE_2027;
        }

        // La clave más larga primero: "gemini-3.5-flash" también es el comienzo de
        // "gemini-3.5-flash-lite", y "claude-haiku-4-5" el de "claude-haiku-4-5-20251001".
        $claves = array_keys(self::TABLA);
        usort($claves, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($claves as $clave) {
            if (str_starts_with($modelo, $clave)) {
                return self::TABLA[$clave];
            }
        }

        return self::DESCONOCIDO;
    }

    /** Costo estimado en dólares de una llamada (o de la suma de las de un turno). */
    public static function costo(?string $modelo, int $entrada, int $salida, int $cacheLectura = 0, int $cacheEscritura = 0, int $audio = 0): float
    {
        $p = self::de($modelo);

        $usd = $entrada * $p['entrada']
            + $cacheLectura * $p['cache']
            + $cacheEscritura * ($p['escritura'] ?? $p['entrada'])
            + $audio * ($p['audio'] ?? $p['entrada'])
            + $salida * $p['salida'];

        return round($usd / 1_000_000, 5);
    }
}
