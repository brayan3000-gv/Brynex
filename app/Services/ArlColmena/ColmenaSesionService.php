<?php

namespace App\Services\ArlColmena;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Abre sesión en la Oficina Digital ARL de Colmena sin intervención humana.
 *
 * El login es Azure AD B2C: no se puede pedir por HTTP, así que lo hace un
 * Chrome headless (`scripts/colmena-login.mjs`) que devuelve el JWT de la
 * cookie `token` y el consecutivo del contrato elegido. De ahí en adelante todo
 * el trabajo va por HTTP desde PHP — ver [[ColmenaApiService]].
 *
 * Las claves salen del módulo de claves (`clave_accesos`, tipo ARL, entidad
 * COLMENA) buscando por el NIT de la empresa: es donde el equipo ya las carga,
 * y así cambiarlas allí cambia lo que se usa aquí, sin copias que se queden
 * viejas.
 */
class ColmenaSesionService
{
    /** Aliado cuya clave se prueba primero cuando una empresa está cargada en varios (Brygar). */
    private const ALIADO_PREFERIDO = 2;

    /** Login B2C + elección de contrato: son varias redirecciones. */
    private const TIMEOUT_SEGUNDOS = 180;

    /**
     * Devuelve token y contrato para ese NIT, entrando al portal.
     *
     * @return array{token:string, contrato:string, empresa:?string}
     */
    public static function abrir(string $nit): array
    {
        $nit = preg_replace('/\D/', '', $nit);
        $claves = self::credencialesPara($nit);

        if (! $claves) {
            throw new RuntimeException("No hay clave de ARL Colmena para el NIT {$nit} en el módulo de claves.");
        }

        // La misma empresa puede estar cargada en varios aliados con claves
        // distintas, y alguna quedó vieja (pasó con Global Contact: la de un
        // aliado tenía la contraseña del semestre anterior). Se intenta con la
        // segunda antes de darse por vencido, pero no con más: el portal
        // bloquea la cuenta si se le insiste.
        $ultimoError = null;

        foreach (array_slice($claves, 0, 2) as $clave) {
            try {
                return self::entrar($nit, $clave);
            } catch (RuntimeException $e) {
                $ultimoError = $e;
            }
        }

        throw $ultimoError;
    }

    /**
     * @param  array{usuario:string, contrasena:string}  $clave
     * @return array{token:string, contrato:string, empresa:?string}
     */
    private static function entrar(string $nit, array $clave): array
    {
        $entrada = json_encode([
            'usuario' => $clave['usuario'],
            'contrasena' => $clave['contrasena'],
            'nitEmpresa' => $nit,
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS)
            ->input($entrada)
            ->run(ArlSuraSesionService::binarioNode().' scripts/colmena-login.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false) || empty($salida['token'])) {
            $error = $salida['error'] ?? trim($resultado->errorOutput()) ?: 'El login no devolvió una sesión.';

            // El script nunca imprime la clave; lo que llega es la pantalla en
            // la que se quedó, que es justo lo que hace falta para arreglarlo.
            Log::warning('ARL Colmena: no se pudo abrir sesión', [
                'nit' => $nit,
                'error' => $error,
                'url' => $salida['url'] ?? null,
            ]);

            throw new RuntimeException("No se pudo abrir sesión en ARL Colmena: {$error}");
        }

        return [
            'token' => $salida['token'],
            'contrato' => (string) $salida['contrato'],
            'empresa' => $salida['empresa'] ?? null,
        ];
    }

    /** La primera clave utilizable; sirve para saber si hay alguna. */
    public static function credencialPara(string $nit): ?array
    {
        return self::credencialesPara($nit)[0] ?? null;
    }

    /**
     * Las claves de Colmena de esa empresa, en cualquier aliado.
     *
     * Es la misma empresa ante la ARL, así que no se filtra por aliado: si
     * Brygar y Fecop la tienen cargada, cualquiera de las dos entra al mismo
     * contrato. **Ojo: pueden no estar igual de frescas.** Por eso mandan las
     * más recién editadas: la más vieja suele ser la que quedó sin actualizar
     * en el último cambio de contraseña.
     *
     * @return array<int,array{usuario:string, contrasena:string}>
     */
    public static function credencialesPara(string $nit): array
    {
        $nit = preg_replace('/\D/', '', $nit);

        if (! $nit) {
            return [];
        }

        return DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', $nit)
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, 'arl_colmena', 'ARL', '%COLMENA%'))
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')
            ->whereNotNull('c.contrasena')
            // Brygar manda: si cambia la clave, es la que vale, y a los demás
            // aliados se les cambia después. Entre el resto, la más reciente.
            ->orderByRaw('CASE WHEN rs.aliado_id = ? THEN 0 ELSE 1 END', [self::ALIADO_PREFERIDO])
            ->orderByDesc('c.updated_at')
            ->orderByDesc('c.id')
            ->get(['c.usuario', 'c.contrasena'])
            ->map(fn ($clave) => [
                'usuario' => trim($clave->usuario),
                'contrasena' => $clave->contrasena,
            ])
            ->all();
    }
}
