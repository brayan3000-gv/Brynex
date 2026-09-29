<?php

namespace App\Services\ArlSura;

use App\Models\ArlCredencial;
use App\Models\ArlUsuarioPortal;
use App\Models\RazonSocial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /** Cuánto se espera antes de volver a mandar una clave que Sura rechazó. */
    private const PAUSA_TRAS_FALLO_MINUTOS = 30;

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

        $nit    = self::nitDeLaPoliza($poliza);
        $salida = self::abrirSesion($credencial, $nit);

        // La clave de Sura se vence y el equipo la cambia en el módulo de
        // claves, pero una credencial guardada aparte para la póliza gana en
        // `credencialPara()` y sigue entrando con la vieja. Si esa falla y el
        // módulo de claves tiene otra distinta, se prueba con esa antes de
        // rendirse.
        if (! ($salida['ok'] ?? false) && $credencial->exists
            && ($alterna = self::desdeModuloDeClaves($nit))
            && ($alterna->usuario !== $credencial->usuario || $alterna->contrasena !== $credencial->contrasena)) {
            Log::info('ARL Sura: la credencial guardada no entró; se prueba la del módulo de claves', [
                'aliado' => $aliadoId, 'poliza' => $poliza, 'credencial_id' => $credencial->id,
            ]);

            $primerError = $salida['error'] ?? null;
            $salida      = self::abrirSesion($alterna, $nit);

            if ($salida['ok'] ?? false) {
                $credencial->update(['ultimo_error' => 'Entró con la clave del módulo de claves; esta no sirvió: '.mb_substr((string) $primerError, 0, 200)]);
                $credencial = $alterna;
            } else {
                $salida['error'] = 'con la clave guardada: '.$primerError
                    .' / con la del módulo de claves: '.($salida['error'] ?? 'sin respuesta');
            }
        }

        if (! ($salida['ok'] ?? false) || empty($salida['cookie'])) {
            $error = $salida['error'] ?? 'El login no devolvió una sesión.';

            if ($credencial->exists) {
                $credencial->update(['ultimo_error' => mb_substr($error, 0, 300)]);
            }
            // El mensaje puede traer nombres de la empresa, pero nunca la clave:
            // el script no la imprime. La página donde se quedó es lo que dice
            // si fue la clave, un cambio de contraseña pedido por Sura o un
            // cambio del portal; sin ella el arreglo es a ciegas.
            Log::warning('ARL Sura: no se pudo abrir sesión', [
                'aliado'     => $aliadoId,
                'poliza'     => $poliza,
                'credencial' => self::origen($credencial),
                'usuario'    => self::enmascarar($credencial->usuario),
                'error'      => $error,
                'url'        => $salida['url'] ?? null,
                'pagina'     => isset($salida['texto']) ? mb_substr($salida['texto'], 0, 300) : null,
            ]);

            throw new RuntimeException("No se pudo abrir sesión en ARL Sura: {$error}");
        }

        ArlSuraApiService::guardarSesion($aliadoId, $poliza, $salida['cookie']);

        if ($credencial->exists) {
            $credencial->update(['ultima_sesion_at' => now(), 'ultimo_error' => null]);
        }

        return $salida['cookie'];
    }

    /**
     * Corre el login en Chrome y devuelve lo que diga el script.
     *
     * Un usuario y clave que Sura acaba de rechazar no se vuelven a mandar
     * durante un rato: Sura bloquea el usuario tras unos pocos intentos
     * fallidos, y entre la consulta previa, la afiliación y el cruce nocturno
     * se repetiría varias veces la misma clave mala. La marca va por usuario y
     * clave, así que en cuanto alguien corrige la clave se intenta enseguida.
     */
    private static function abrirSesion(ArlCredencial $credencial, ?string $nit): array
    {
        $marca = 'arlsura:login-fallido:'.sha1($credencial->usuario.'|'.$credencial->contrasena);

        if ($fallo = Cache::get($marca)) {
            return ['ok' => false, 'error' => $fallo.' (no se reintentó para no bloquear el usuario en Sura; corrige la clave o espera '.self::PAUSA_TRAS_FALLO_MINUTOS.' min)'];
        }

        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'nitEmpresa'    => $nit,
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::TIMEOUT_SEGUNDOS)
            ->input($entrada)
            ->run(self::binarioNode().' scripts/arl-sura-login.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! isset($salida['error']) && ! ($salida['ok'] ?? false)) {
            $salida['error'] = trim($resultado->errorOutput()) ?: 'El login no devolvió una sesión.';
        }

        // Solo el rechazo del login marca la clave: un Chrome que no arrancó o
        // un portal caído no dicen nada de ella.
        if (! ($salida['ok'] ?? false) && ($salida['url'] ?? '') !== '' && str_contains($salida['url'], 'login.sura.com')) {
            Cache::put($marca, $salida['error'], now()->addMinutes(self::PAUSA_TRAS_FALLO_MINUTOS));
        }

        return $salida;
    }

    /** De dónde salió la credencial, para saber cuál corregir. */
    private static function origen(ArlCredencial $credencial): string
    {
        if (! $credencial->exists) {
            return 'modulo de claves (NIT '.$credencial->nit.')';
        }

        return 'arl_credenciales #'.$credencial->id.($credencial->usuario_portal_id ? ' / usuario portal #'.$credencial->usuario_portal_id : '');
    }

    /** Lo justo para reconocer el usuario sin dejar la cédula entera en el log. */
    private static function enmascarar(?string $usuario): string
    {
        $usuario = (string) $usuario;

        return strlen($usuario) > 4 ? str_repeat('*', strlen($usuario) - 4).substr($usuario, -4) : '****';
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
    private static function desdeModuloDeClaves(?string $nit): ?ArlCredencial
    {
        $nit = preg_replace('/\D/', '', (string) $nit);

        if (! $nit) {
            return null;
        }

        $clave = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', $nit)
            ->where('c.tipo', 'ARL')
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
