<?php

use Carbon\Carbon;

if (!function_exists('sqldate')) {
    /**
     * Parsea una fecha de SQL Server ("Apr 1 2026 12:00:00:AM") de forma segura.
     *
     * @param  mixed       $value   Fecha (string, Carbon, null)
     * @param  string|null $format  Si se da, devuelve string formateado
     * @return Carbon|string|null
     */
    function sqldate($value, ?string $format = null)
    {
        if (!$value) return null;
        if ($value instanceof Carbon) {
            return $format ? $value->format($format) : $value;
        }
        $normalized = preg_replace('/:(AM|PM)$/i', ' $1', trim((string) $value));
        try {
            $carbon = Carbon::parse($normalized);
            return $format ? $carbon->format($format) : $carbon;
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (!function_exists('nombre_oracion')) {
    /**
     * Nombre de persona en tipo oración: "EDUARDO ALFONZO" → "Eduardo Alfonzo".
     * Solo para mostrar en pantalla; los planos/PDF oficiales conservan el original.
     */
    function nombre_oracion($valor): string
    {
        $valor = trim((string) $valor);
        if ($valor === '') return '';
        return mb_convert_case(mb_strtolower($valor, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}

if (!function_exists('razon_social_corta')) {
    /**
     * Quita del final de una razón social el tipo de sociedad, solo para mostrar:
     * "CONFECCIONES GAVI SOCIEDAD POR ACCIONES SIMPLIFICADA" → "CONFECCIONES GAVI",
     * "ACME S.A.S." → "ACME", "PEREZ Y CIA LTDA" → "PEREZ Y CIA".
     * Si al quitarlo no queda nada, devuelve el nombre original.
     */
    function razon_social_corta($valor): string
    {
        $original = trim((string) $valor);
        $sufijo = '/[\s,.\-]+(?:'
            . 'SOCIEDAD\s+POR\s+ACCIONES\s+SIMPLIFICADA|SOCIEDAD\s+AN[OÓ]NIMA|SOCIEDAD\s+LIMITADA'
            . '|EMPRESA\s+UNIPERSONAL|BENEFICIO\s+E\s+INTER[EÉ]S\s+COLECTIVO'
            . '|S\.?\s?A\.?\s?S\.?|S\.?\s?A\.?|LTDA\.?|LIMITADA|E\.?\s?U\.?|S\.?\s?EN\s?C\.?(?:\s?S\.?)?|BIC'
            . ')[\s.,]*$/iu';
        $nombre = ' ' . $original;
        do {
            $antes = $nombre;
            $nombre = preg_replace($sufijo, '', $nombre);
        } while ($nombre !== $antes);
        $nombre = trim($nombre, " \t,.-");
        return $nombre !== '' ? $nombre : $original;
    }
}

if (!function_exists('nombre_con_inicial')) {
    /**
     * Primer nombre + inicial del segundo + primer apellido, en tipo oración:
     * ("CANDIDA", "ROSA", "SIERRA") → "Candida R. Sierra".
     */
    function nombre_con_inicial($primerNombre, $segundoNombre, $primerApellido): string
    {
        $segundo = trim((string) $segundoNombre);
        $inicial = $segundo !== '' ? mb_strtoupper(mb_substr($segundo, 0, 1, 'UTF-8'), 'UTF-8') . '.' : '';
        return nombre_oracion(implode(' ', array_filter([
            trim((string) $primerNombre), $inicial, trim((string) $primerApellido),
        ], fn ($x) => $x !== '')));
    }
}
