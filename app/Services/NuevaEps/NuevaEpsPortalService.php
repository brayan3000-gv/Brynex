<?php

namespace App\Services\NuevaEps;

use App\Models\EpsPortalEmpresa;
use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Corre `scripts/nueva-eps-portal.mjs` con la clave de la empresa.
 *
 * La clave sale del módulo de claves de BryNex por el NIT de la empresa, igual
 * que en ARL Sura: cambiarla allí basta. Si el portal la rechaza se guarda su
 * huella y no se vuelve a probar hasta que cambie, porque los portales bloquean
 * al usuario tras varios intentos fallidos.
 */
class NuevaEpsPortalService
{
    public const ENTIDAD = 'nueva_eps';

    /** Códigos de Nueva EPS en la tabla `eps` (contributivo y movilidad). */
    public const CODIGOS_EPS = ['EPS037', 'EPS041'];

    /** Login, elegir empresa y abrir la SPA ya suman cerca de un minuto. */
    private const TIMEOUT_SEGUNDOS = 240;

    /** El reporte de mora se genera aparte y hay que esperar a que quede listo. */
    private const TIMEOUT_MORA_SEGUNDOS = 480;

    /**
     * La clave de la empresa, o por qué no se puede usar.
     *
     * @return array{usuario:string, contrasena:string, empresa:EpsPortalEmpresa}|array{error:string}
     */
    public static function credencial(string $nit): array
    {
        $nit     = preg_replace('/\D/', '', $nit);
        $empresa = EpsPortalEmpresa::de(self::ENTIDAD, $nit);

        if ($portal = $empresa->usuarioPortal) {
            $usuario = trim($portal->tipo_documento.' '.$portal->usuario);
            $clave   = (string) $portal->contrasena;
        } else {
            $fila = DB::table('clave_accesos as c')
                ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
                ->where('rs.nit', $nit)
                ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, self::ENTIDAD, 'EPS', '%NUEVA%EPS%'))
                ->where('c.activo', true)
                ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
                ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
                ->orderByDesc('c.updated_at')
                ->first(['c.usuario', 'c.contrasena']);

            if (! $fila) {
                return ['error' => 'La empresa no tiene la clave de Nueva EPS en el módulo de claves.'];
            }

            $usuario = trim($fila->usuario);
            $clave   = (string) $fila->contrasena;
        }

        if ($empresa->clave_fallida_hash && hash_equals($empresa->clave_fallida_hash, self::huella($usuario, $clave))) {
            return ['error' => 'Nueva EPS ya rechazó esta clave ('.($empresa->ultimo_error ?: 'sin detalle')
                .'). Actualízala en el módulo de claves para volver a intentar.'];
        }

        return ['usuario' => $usuario, 'contrasena' => $clave, 'empresa' => $empresa];
    }

    /**
     * @return array La salida del script; con `error` si no se pudo ni empezar.
     */
    public static function ejecutar(string $nit, array $datos, ?int $segundos = null): array
    {
        $cred = self::credencial($nit);

        if (isset($cred['error'])) {
            return ['ok' => false, 'paso' => 'credencial', 'error' => $cred['error']];
        }

        // Sin el túnel arriba Chrome solo diría ERR_CONNECTION_REFUSED; y no es
        // un fallo de clave, así que no debe marcarla como rechazada. Solo
        // aplica cuando el túnel es la salida: con proxy contratado, correrScript()
        // ni lo mira, y exigirlo aquí dejaba el portal inaccesible con el PC de
        // la oficina apagado —que es justo lo que el proxy vino a evitar—.
        if (! config('services.proxy_colombia.url') && self::tunelConectado() === false) {
            return ['ok' => false, 'paso' => 'tunel', 'error' => 'El PC de la oficina no tiene conectado el túnel hacia Nueva EPS ('
                .config('services.nueva_eps.tunel').'). Revisa que esté encendido y con internet.'];
        }

        $salida = self::correrScript($datos + [
            'usuario'    => $cred['usuario'],
            'contrasena' => $cred['contrasena'],
            'nitEmpresa' => preg_replace('/\D/', '', $nit),
        ], $segundos);

        $empresa = $cred['empresa'];

        if ($salida['ok'] ?? false) {
            $empresa->update(['ultima_sesion_at' => now(), 'ultimo_error' => null, 'clave_fallida_hash' => null]);
        } else {
            // El mensaje nunca trae la clave: el script no la imprime.
            Log::warning('Nueva EPS: el portal falló', ['nit' => $nit, 'paso' => $salida['paso'] ?? null, 'error' => $salida['error'] ?? null]);

            if (($salida['paso'] ?? null) === 'login') {
                $empresa->update([
                    'clave_fallida_hash' => self::huella($cred['usuario'], $cred['contrasena']),
                    'ultimo_error'       => mb_substr((string) ($salida['error'] ?? ''), 0, 300),
                ]);
            }
        }

        return $salida;
    }

    /**
     * La mora por trabajador que Nueva EPS le tiene a esa empresa.
     *
     * El corte va en el primer día de un mes y el portal devuelve lo anterior a
     * esa fecha: con el mes en curso se deja fuera el mes que todavía está a
     * tiempo de pagarse, que si no saldría como mora de quien solo va tarde.
     *
     * Tarda: el portal genera el reporte aparte y hay que esperarlo, así que
     * este modo lleva su propio plazo y no el de las consultas normales.
     */
    public static function mora(string $nit, ?string $fechaCorte = null): array
    {
        return self::ejecutar($nit, [
            'modo'       => 'mora',
            'fechaCorte' => $fechaCorte ?: now()->startOfMonth()->toDateString(),
        ], self::TIMEOUT_MORA_SEGUNDOS);
    }

    /**
     * Todos los cotizantes que Nueva EPS tiene de esa empresa, con la fecha de
     * retiro que ella registró.
     *
     * Es el mismo informe de la mora, pero sin filtrarlo: sirve para conciliar
     * retiros, que es cazar el problema antes —un retiro que no le llegó a la
     * EPS se cobra mes a mes hasta que alguien lo note—.
     */
    public static function cotizantes(string $nit, ?string $fechaCorte = null): array
    {
        return self::ejecutar($nit, [
            'modo'       => 'cotizantes',
            'fechaCorte' => $fechaCorte ?: now()->startOfMonth()->toDateString(),
        ], self::TIMEOUT_MORA_SEGUNDOS);
    }

    /**
     * Con qué IP sale el servidor hacia Nueva EPS y si el portal deja entrar.
     * No usa claves del portal: sirve para probar el proxy.
     */
    public static function probarConexion(): array
    {
        return self::correrScript(['modo' => 'conexion']);
    }

    /**
     * Si el túnel de la oficina está escuchando en el servidor. `null` cuando no
     * hay túnel configurado (se sale directo o por PROXY_COLOMBIA).
     */
    public static function tunelConectado(): ?bool
    {
        $tunel = config('services.nueva_eps.tunel');

        if (! $tunel || ! preg_match('/^([\w.-]+):(\d{2,5})$/', $tunel, $m)) {
            return null;
        }

        $socket = @fsockopen($m[1], (int) $m[2], $errno, $errstr, 3);

        if (! $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * El proxy va por stdin junto con los datos, para que no quede en `ps`.
     *
     * Dos salidas colombianas y basta con una: manda el proxy, que no depende
     * de que nadie deje encendido el PC de la oficina, y el túnel queda de
     * respaldo para cuando no haya proxy contratado. Pasar las dos a la vez no
     * sirve: el `--host-rules` del túnel manda el tráfico a un puerto local y
     * el proxy nunca llegaría a verlo.
     */
    private static function correrScript(array $entrada, ?int $segundos = null): array
    {
        $proxy = config('services.proxy_colombia.url');

        $resultado = Process::path(base_path())
            ->timeout($segundos ?? self::TIMEOUT_SEGUNDOS)
            ->input(json_encode($entrada + [
                'proxy' => $proxy,
                'tunel' => $proxy ? null : config('services.nueva_eps.tunel'),
            ], JSON_UNESCAPED_UNICODE))
            ->run(ArlSuraSesionService::binarioNode().' scripts/nueva-eps-portal.mjs');

        return json_decode(trim($resultado->output()), true) ?: [
            'ok' => false, 'paso' => 'proceso',
            'error' => trim($resultado->errorOutput()) ?: 'El proceso del portal no devolvió respuesta.',
        ];
    }

    private static function huella(string $usuario, string $clave): string
    {
        return hash('sha256', $usuario.'|'.$clave);
    }
}
