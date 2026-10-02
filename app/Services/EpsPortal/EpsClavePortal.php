<?php

namespace App\Services\EpsPortal;

use App\Models\EpsPortalEmpresa;
use App\Services\Afiliaciones\PortalesEntidades;
use Illuminate\Support\Facades\DB;

/**
 * La clave del portal de una EPS para una empresa, sacada del módulo de claves.
 *
 * Igual que en ARL Sura y Nueva EPS, la fuente es `clave_accesos` por el NIT de
 * la razón social: cambiarla allí basta. `eps_portal_empresas` recuerda la
 * huella de una clave que el portal rechazó, para no volver a probarla hasta
 * que alguien la cambie — los portales bloquean al usuario tras varios intentos.
 */
class EpsClavePortal
{
    /**
     * @param  string  $patronEntidad  LIKE contra clave_accesos.entidad, p. ej. '%SALUD%TOTAL%'
     * @return array{usuario:string, contrasena:string, empresa:EpsPortalEmpresa}|array{error:string}
     */
    /**
     * @param  string  $tipoClave  tipo en el llavero: 'EPS' o 'CAJA'. Importa
     *                             cuando una entidad tiene las dos —Comfenalco
     *                             es EPS y caja a la vez, con claves distintas—,
     *                             y sin esto la caja recibía la de la EPS.
     * @param  ?\Closure  $aceptaEntidad  criba fina sobre el nombre de la entidad,
     *                                    para cuando el LIKE no alcanza: hay cajas
     *                                    que comparten apellido y no clave
     *                                    —Comfenalco Valle y Comfenalco Cartagena
     *                                    son dos empresas distintas—.
     * @param  ?\Closure  $normalizaUsuario  arregla el usuario antes de usarlo,
     *                                       para portales con un formato propio
     *                                       (Boxalud lo quiere como NIT+P). Va
     *                                       aquí y no en quien llama para que la
     *                                       huella de la clave rechazada se
     *                                       calcule con el usuario que de verdad
     *                                       se prueba.
     */
    public static function para(string $entidad, string $patronEntidad, string $nombre, string $nit, string $tipoClave = 'EPS', ?\Closure $aceptaEntidad = null, ?\Closure $normalizaUsuario = null): array
    {
        $nit     = preg_replace('/\D/', '', $nit);
        $empresa = EpsPortalEmpresa::de($entidad, $nit);

        if ($portal = $empresa->usuarioPortal) {
            $usuario = trim($portal->tipo_documento.' '.$portal->usuario);
            $clave   = (string) $portal->contrasena;
        } else {
            $filas = DB::table('clave_accesos as c')
                ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
                ->where('rs.nit', $nit)
                ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, $entidad, $tipoClave, $patronEntidad))
                ->where('c.activo', true)
                ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
                ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
                ->orderByDesc('c.updated_at')
                ->get(['c.entidad', 'c.entidad_tipo', 'c.usuario', 'c.contrasena']);

            // La criba por nombre es para las claves sin clasificar: las del
            // catálogo ya son de esa entidad.
            $fila = $filas->first(fn ($f) => ! $aceptaEntidad || $f->entidad_tipo || $aceptaEntidad((string) $f->entidad));

            if (! $fila) {
                return ['error' => "La empresa no tiene la clave de {$nombre} en el módulo de claves."];
            }

            $usuario = trim($fila->usuario);
            $clave   = (string) $fila->contrasena;
        }

        if ($normalizaUsuario) {
            $usuario = $normalizaUsuario($usuario);
        }

        if ($empresa->clave_fallida_hash && hash_equals($empresa->clave_fallida_hash, self::huella($usuario, $clave))) {
            return ['error' => "{$nombre} ya rechazó esta clave (".($empresa->ultimo_error ?: 'sin detalle')
                .'). Actualízala en el módulo de claves para volver a intentar.'];
        }

        return ['usuario' => $usuario, 'contrasena' => $clave, 'empresa' => $empresa];
    }

    public static function exito(EpsPortalEmpresa $empresa): void
    {
        $empresa->update(['ultima_sesion_at' => now(), 'ultimo_error' => null, 'clave_fallida_hash' => null]);
    }

    public static function rechazada(EpsPortalEmpresa $empresa, string $usuario, string $clave, string $error): void
    {
        $empresa->update([
            'clave_fallida_hash' => self::huella($usuario, $clave),
            'ultimo_error'       => mb_substr($error, 0, 300),
        ]);
    }

    /**
     * "CC 1005878149" → ['CC', '1005878149']; un número suelto es cédula.
     *
     * @return array{0:string, 1:string}
     */
    public static function separarUsuario(string $usuario): array
    {
        return preg_match('/^([A-Za-z]{1,3})\s+(\d+)$/', trim($usuario), $m)
            ? [strtoupper($m[1]), $m[2]]
            : ['CC', preg_replace('/\D/', '', $usuario)];
    }

    private static function huella(string $usuario, string $clave): string
    {
        return hash('sha256', $usuario.'|'.$clave);
    }
}
