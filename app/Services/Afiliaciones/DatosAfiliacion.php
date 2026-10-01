<?php

namespace App\Services\Afiliaciones;

use App\Models\Contrato;
use App\Models\RazonSocial;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * De dónde salen los datos de afiliación de un aliado.
 *
 * Brygar (aliado 2) es la empresa principal de las afiliaciones: los aliados que
 * tienen contratado el servicio de afiliaciones de BryNex (módulo `afiliaciones`
 * en `brynex_modulos_aliado`) las hacen con los datos de Brygar —el buzón de
 * Gmail desde el que salen los correos y los datos de contacto y de formularios
 * de la razón social— porque las afiliaciones las hace la gente de BryNex desde
 * Brygar. Lo que es propio del aliado ante el operador, como la sucursal, sigue
 * siendo suyo. Las claves de los portales ya se comparten por NIT (ver
 * ClaveAcceso::visiblesPara).
 *
 * Decisión del dueño, 1-oct-2026: el correo de afiliaciones de un contrato de
 * Fecop fallaba con «El aliado no tiene buzón de Gmail configurado».
 */
class DatosAfiliacion
{
    public const ALIADO_PRINCIPAL = 2;

    /** El aliado de pruebas: nunca entra en lo de los demás. */
    private const ALIADO_PRUEBAS = 1;

    /** Datos de la razón social que se toman de la de Brygar con el mismo NIT. */
    public const CONTACTO = ['correo_formulario', 'tel_formulario', 'dir_formulario', 'correos', 'telefonos', 'direccion'];

    // Memoria de un minuto: los workers de colas viven horas y el módulo o la razón
    // social de Brygar pueden cambiar mientras tanto.
    private static ?array $gestionados = null;

    private static array $principales = [];

    private static int $memoriaDesde = 0;

    private static function memoria(): void
    {
        if (time() - self::$memoriaDesde > 60) {
            self::$gestionados = null;
            self::$principales = [];
            self::$memoriaDesde = time();
        }
    }

    /**
     * Aliados con el servicio de afiliaciones de BryNex, sin el de pruebas.
     *
     * @return int[]
     */
    public static function aliadosGestionados(): array
    {
        self::memoria();

        return self::$gestionados ??= DB::table('brynex_modulos_aliado as ma')
            ->join('brynex_modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->where('ma.activo', true)
            ->where('m.codigo', 'afiliaciones')
            ->where('ma.aliado_id', '<>', self::ALIADO_PRUEBAS)
            ->distinct()
            ->pluck('ma.aliado_id')
            ->map(fn ($id) => (int) $id)
            ->values()->all();
    }

    /** Aliados gestionados que el usuario puede ver (BryNex), o solo el activo. */
    public static function aliadosVisibles(?User $user, int $aliadoActivo, bool $gestionados): array
    {
        if (! $gestionados || ! $user?->es_brynex) {
            return [$aliadoActivo];
        }

        $ids = array_values(array_filter(self::aliadosGestionados(), fn ($id) => $user->puedeAccederAliado($id)));

        return $ids ?: [$aliadoActivo];
    }

    /** ¿Las afiliaciones de este aliado se hacen con los datos de Brygar? */
    public static function usaPrincipal(int $aliadoId): bool
    {
        return $aliadoId !== self::ALIADO_PRINCIPAL && in_array($aliadoId, self::aliadosGestionados(), true);
    }

    /** El aliado cuyo buzón de Gmail usa este: el suyo si lo tiene, si no el de Brygar cuando es gestionado. */
    public static function aliadoBuzon(int $aliadoId): int
    {
        if (config("afiliaciones_correo.buzones.{$aliadoId}")) {
            return $aliadoId;
        }

        return self::usaPrincipal($aliadoId) ? self::ALIADO_PRINCIPAL : $aliadoId;
    }

    /** Cuenta de Gmail desde la que salen los correos de afiliación del aliado. */
    public static function buzon(int $aliadoId): ?string
    {
        return config('afiliaciones_correo.buzones.'.self::aliadoBuzon($aliadoId)) ?: null;
    }

    /**
     * Los aliados cuyos correos maneja el buzón de este: él mismo y los que lo usan
     * prestado. Es donde el agente busca contratos por cédula.
     *
     * @return int[]
     */
    public static function aliadosDelBuzon(int $aliadoBuzon): array
    {
        $ids = array_filter(self::aliadosGestionados(), fn ($id) => self::aliadoBuzon($id) === $aliadoBuzon);

        return array_values(array_unique([$aliadoBuzon, ...$ids]));
    }

    /**
     * La razón social del contrato con los datos de contacto y de formularios de
     * la de Brygar (mismo NIT) cuando el aliado es gestionado. Si Brygar no tiene
     * un dato, queda el del aliado. Es una copia para leer: no se guarda.
     */
    public static function razonSocial(?RazonSocial $rs): ?RazonSocial
    {
        if (! $rs || ! self::usaPrincipal((int) $rs->aliado_id)) {
            return $rs;
        }
        $principal = self::principalDe($rs);
        if (! $principal) {
            return $rs;
        }

        $copia = clone $rs;
        foreach (self::CONTACTO as $campo) {
            if (trim((string) $principal->{$campo}) !== '') {
                $copia->setAttribute($campo, $principal->{$campo});
            }
        }

        return $copia;
    }

    public static function deContrato(Contrato $contrato): ?RazonSocial
    {
        return self::razonSocial($contrato->razonSocial);
    }

    /** La razón social de Brygar con el mismo NIT. */
    private static function principalDe(RazonSocial $rs): ?RazonSocial
    {
        $nit = preg_replace('/\D/', '', (string) $rs->nit);
        if (strlen($nit) < 8) {
            return null;
        }
        self::memoria();
        if (! array_key_exists($nit, self::$principales)) {
            self::$principales[$nit] = RazonSocial::where('aliado_id', self::ALIADO_PRINCIPAL)
                ->whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(nit,''),'-',''),'.',''),' ','') = ?", [$nit])
                ->orderByRaw("CASE WHEN estado = 'Activa' THEN 0 ELSE 1 END")
                ->first();
        }

        return self::$principales[$nit];
    }
}
