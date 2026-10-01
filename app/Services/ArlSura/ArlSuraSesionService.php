<?php

namespace App\Services\ArlSura;

use App\Models\ArlCredencial;
use App\Models\ArlUsuarioPortal;
use App\Models\RazonSocial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Abre sesión en el portal de ARL Sura sin intervención humana.
 *
 * La sesión de `sel-services` no se puede pedir por HTTP —nace del SSO de
 * login.sura.com, con teclado virtual y detrás de Imperva Incapsula, y la cookie
 * que importa es httpOnly—, así que se delega en un Chrome headless que hace el
 * login y devuelve las cookies. Ver `scripts/arl-sura-login.mjs`.
 *
 * Con esto nadie tiene que copiar cookies del navegador: basta registrar una
 * credencial por aliado.
 */
class ArlSuraSesionService
{
    /** El login tarda: hay SSO, redirecciones y el reto de Incapsula de por medio. */
    private const TIMEOUT_SEGUNDOS = 120;

    /**
     * Abre sesión para esa póliza y deja la cookie lista para ArlSuraApiService.
     *
     * @return string La cookie obtenida.
     */
    public static function renovar(int $aliadoId, string $poliza): string
    {
        $credencial = self::credencialPara($aliadoId, $poliza)
            ?? throw new RuntimeException(
                "No hay credenciales del portal de ARL Sura para la póliza {$poliza}."
            );

        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'nitEmpresa'    => self::nitDeLaPoliza($poliza),
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS)
            ->input($entrada)
            ->run(self::binarioNode().' scripts/arl-sura-login.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false) || empty($salida['cookie'])) {
            $error = $salida['error'] ?? trim($resultado->errorOutput()) ?: 'El login no devolvió una sesión.';

            if ($credencial->exists) {
                $credencial->update(['ultimo_error' => mb_substr($error, 0, 300)]);
            }
            // El mensaje puede traer nombres de la empresa, pero nunca la clave:
            // el script no la imprime.
            Log::warning('ARL Sura: no se pudo abrir sesión', ['aliado' => $aliadoId, 'poliza' => $poliza, 'error' => $error]);

            throw new RuntimeException("No se pudo abrir sesión en ARL Sura: {$error}");
        }

        ArlSuraApiService::guardarSesion($aliadoId, $poliza, $salida['cookie']);

        if ($credencial->exists) {
            $credencial->update(['ultima_sesion_at' => now(), 'ultimo_error' => null]);
        }

        return $salida['cookie'];
    }

    /**
     * Ejecuta la anulación en el portal, que no tiene API y vive en el Struts.
     *
     * Reutiliza la sesión ya abierta; si no hay, la abre. Devuelve el resultado
     * crudo del script para que quien llama decida qué hacer con el error.
     *
     * @return array{ok:bool, mensaje?:string, error?:string}
     */
    public static function anular(
        int $aliadoId,
        string $poliza,
        string $tipoId,
        string $numDoc,
        string $tipoAfiliado = 'D',
    ): array {
        // La de la empresa primero: cada empresa entra al portal con su propio
        // usuario, y la general del aliado no tiene por qué servir para todas.
        $credencial = self::credencialPara($aliadoId, $poliza)
            ?? throw new RuntimeException('No hay credenciales del portal de ARL Sura para anular.');

        // Se mandan credenciales, no la cookie: Incapsula ata la sesión al
        // navegador que la abrió, así que el script tiene que entrar por su
        // cuenta. Reutilizar la cookie devuelve la pantalla de login.
        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'nitEmpresa'    => self::nitDeLaPoliza($poliza),
            'tipoId'        => $tipoId,
            'numDoc'        => $numDoc,
            // Cada tipo de afiliado tiene su pantalla: la de dependientes no
            // encuentra a un independiente.
            'tipoAfiliado'  => $tipoAfiliado,
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS + 120) // login + tres pantallas del Struts
            ->input($entrada)
            ->run(self::binarioNode().' scripts/arl-sura-anular.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            Log::warning('ARL Sura: no se pudo anular', [
                'aliado' => $aliadoId, 'documento' => $tipoId.$numDoc,
                'error'  => $salida['error'] ?? $resultado->errorOutput(),
            ]);
        }

        return $salida ?: ['ok' => false, 'error' => 'El proceso de anulación no devolvió respuesta.'];
    }

    /**
     * Mueve la fecha de inicio de la cobertura de un trabajador.
     *
     * Es la renovación barata: un solo trámite en lugar de anular y volver a
     * afiliar, sin el hueco en que el trabajador queda sin ARL y sin gastar la
     * ventana de 30 días de la anulación. Sura solo exige que la fecha de
     * destino sea posterior a hoy.
     *
     * Tampoco tiene API: la hace un navegador sobre el Struts, igual que la
     * anulación.
     */
    public static function modificarCobertura(
        int $aliadoId,
        string $poliza,
        string $tipoId,
        string $numDoc,
        Carbon $fechaNueva,
        string $tipoAfiliado = '01',
    ): array {
        $credencial = self::credencialPara($aliadoId, $poliza)
            ?? throw new RuntimeException('No hay credenciales del portal de ARL Sura para modificar la cobertura.');

        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'nitEmpresa'    => self::nitDeLaPoliza($poliza),
            'tipoId'        => $tipoId,
            'numDoc'        => $numDoc,
            'fechaNueva'    => $fechaNueva->format('d/m/Y'),
            'tipoAfiliado'  => $tipoAfiliado,
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS + 120)
            ->input($entrada)
            ->run(self::binarioNode().' scripts/arl-sura-modificar-cobertura.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            Log::warning('ARL Sura: no se pudo mover la cobertura', [
                'aliado' => $aliadoId, 'documento' => $tipoId.$numDoc,
                'fecha'  => $fechaNueva->toDateString(),
                'error'  => $salida['error'] ?? $resultado->errorOutput(),
            ]);
        }

        return $salida ?: ['ok' => false, 'error' => 'El proceso de modificación no devolvió respuesta.'];
    }

    /**
     * La credencial que corresponde, de la más específica a la más general:
     *
     *  1. la de la empresa dueña de esa póliza, por NIT —cada empresa entra al
     *     portal con su propio usuario—;
     *  2. la general del aliado;
     *  3. la de cualquier otro aliado que comparta la póliza, para no tener que
     *     registrar el mismo secreto dos veces.
     */
    public static function credencialPara(int $aliadoId, string $poliza, ?string $nitEmpresa = null): ?ArlCredencial
    {
        if ($poliza !== '' && $credencial = ArlCredencial::where('poliza', $poliza)->where('activo', true)->first()) {
            return $credencial;
        }

        $nit = $nitEmpresa ?: ($poliza !== '' ? RazonSocial::where('arl_poliza', $poliza)->value('nit') : null);

        if ($credencial = ArlCredencial::deEmpresa($nit)) {
            return $credencial;
        }

        // El módulo de claves ya guarda la clave del portal de ARL Sura de
        // muchas empresas. Aprovecharla evita pedirle al usuario algo que él
        // mismo cargó en otra pantalla.
        if ($credencial = self::desdeModuloDeClaves($nit)) {
            return $credencial;
        }

        if ($credencial = ArlCredencial::activaDe($aliadoId)) {
            return $credencial;
        }

        if ($poliza === '') {
            return null;
        }

        $aliados = RazonSocial::where('arl_poliza', $poliza)->pluck('aliado_id')->unique();

        return ArlCredencial::whereIn('aliado_id', $aliados)->whereNull('nit')->where('activo', true)->first();
    }

    /**
     * La clave de ARL Sura que el equipo ya tenga cargada en el módulo de claves
     * para esa empresa (por NIT, en cualquier aliado).
     *
     * Se devuelve como un ArlCredencial **sin persistir**: la fuente sigue siendo
     * `clave_accesos`, así que cambiarla allí cambia lo que se usa aquí, sin
     * copias que se queden viejas. Por eso el resto del servicio solo escribe
     * estado cuando la credencial existe en base.
     */
    public static function desdeModuloDeClaves(?string $nit, string $tipo = 'ARL'): ?ArlCredencial
    {
        $nit = preg_replace('/\D/', '', (string) $nit);

        if (! $nit) {
            return null;
        }

        $clave = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', $nit)
            ->where('c.tipo', $tipo)
            ->where('c.entidad', 'like', '%SURA%')
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')
            ->whereNotNull('c.contrasena')
            ->orderByDesc('c.id')
            ->first(['c.usuario', 'c.contrasena']);

        if (! $clave) {
            return null;
        }

        $credencial = new ArlCredencial(['nit' => $nit, 'activo' => true]);
        $credencial->exists = false;

        // El login cuelga del usuario del portal, también cuando la credencial
        // es de paso: así la lee el mismo código que las guardadas.
        $credencial->setRelation('usuarioPortal', new ArlUsuarioPortal([
            // El módulo de claves no guarda el tipo de documento; los usuarios
            // registrados son cédulas.
            'tipo_documento' => 'C',
            'usuario'        => trim($clave->usuario),
            'contrasena'     => $clave->contrasena,
        ]));

        return $credencial;
    }

    /**
     * Inicia sesión con una credencial y averigua la póliza de esa empresa.
     *
     * La póliza se guarda en TODAS las razones sociales con ese NIT, sin importar
     * el aliado: es la misma empresa ante Sura.
     *
     * @return array{ok:bool, poliza?:string, empresa?:string, error?:string}
     */
    public static function descubrirPoliza(ArlCredencial $credencial, string $nit): array
    {
        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'nitEmpresa'    => preg_replace('/\D/', '', $nit),
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS)
            ->input($entrada)
            ->run(self::binarioNode().' scripts/arl-sura-poliza.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false) || empty($salida['poliza'])) {
            $error = $salida['error'] ?? 'No se pudo leer la póliza en el portal.';
            if ($credencial->exists) {
                $credencial->update(['ultimo_error' => mb_substr($error, 0, 300)]);
            }

            return ['ok' => false, 'error' => $error];
        }

        $poliza = $salida['poliza'];

        if ($credencial->exists) {
            $credencial->update(['poliza' => $poliza, 'ultima_sesion_at' => now(), 'ultimo_error' => null]);
        }

        RazonSocial::where('nit', preg_replace('/\D/', '', $nit))
            ->update(['arl_poliza' => $poliza]);

        Log::info('ARL Sura: póliza descubierta', ['nit' => $nit, 'poliza' => $poliza]);

        return ['ok' => true, 'poliza' => $poliza, 'empresa' => $salida['empresa'] ?? null];
    }

    /**
     * Ruta de node.
     *
     * Laravel lanza los procesos con `sh`, que no hereda el PATH de la sesión
     * interactiva: en el servidor y bajo php-fpm, un `node` a secas falla con
     * "command not found" aunque esté instalado. Por eso se busca en las rutas
     * habituales y se permite fijarlo con ARL_NODE_BIN.
     *
     * Público porque los scripts de EPS SURA corren con el mismo node.
     */
    public static function binarioNode(): string
    {
        if ($configurado = env('ARL_NODE_BIN')) {
            return $configurado;
        }

        foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $ruta) {
            if (is_executable($ruta)) {
                return $ruta;
            }
        }

        return 'node';
    }

    /**
     * El paso de la Sucursal Virtual pide el NIT de la empresa sobre la que se va
     * a trabajar, no la póliza.
     */
    private static function nitDeLaPoliza(string $poliza): ?string
    {
        $nit = RazonSocial::where('arl_poliza', $poliza)->value('nit');

        return $nit ? preg_replace('/\D/', '', $nit) : null;
    }
}
