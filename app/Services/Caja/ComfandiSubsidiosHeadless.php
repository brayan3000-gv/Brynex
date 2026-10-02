<?php

namespace App\Services\Caja;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Los bloqueos de subsidio de una empresa, leídos por un Chrome del servidor.
 *
 * Es la otra manera de hacer lo mismo que la extensión BryNex Portales: ella
 * usa el navegador de la persona y esto usa uno propio, para que la revisión
 * pueda correr de noche. El trabajo de pantalla está en
 * `scripts/comfandi-subsidios.mjs`, igual que en Colmena y ARL Sura; aquí solo
 * se buscan las claves y se traduce la respuesta.
 */
class ComfandiSubsidiosHeadless
{
    /**
     * Cuánto se le da al portal.
     *
     * No son diez segundos por trabajador: entre que la pantalla del filtro a
     * veces hay que recargarla, la segunda pasada a los que fallan y lo que
     * tarda el propio portal de noche, cada uno puede llevarse tres cuartos de
     * minuto. Quedarse corto sale caro —corta la corrida entera a media
     * empresa—, y sobrar no cuesta nada.
     */
    private const SEGUNDOS_BASE = 240;

    private const SEGUNDOS_POR_TRABAJADOR = 45;

    /**
     * @param  array<string>  $documentos  cédulas de esa empresa
     * @return array{ok:bool, nit?:string, empresa?:?string, movimientos?:array, revisados?:array, errores?:array, error?:string}
     */
    public function bloqueos(string $nit, array $documentos, int $meses = 4, ?bool $conVentana = null): array
    {
        $documentos = array_values(array_filter(array_map(fn ($d) => preg_replace('/\D/', '', (string) $d), $documentos)));

        if (! $documentos) {
            return ['ok' => false, 'error' => 'No llegó ninguna cédula para consultar.'];
        }

        $clave = $this->credencial($nit);

        if (! $clave) {
            return ['ok' => false, 'error' => "La empresa {$nit} no tiene la clave de Comfandi en el módulo de claves."];
        }

        // Las credenciales van por stdin para que no queden en `ps`.
        $entrada = json_encode([
            'usuario' => $clave['usuario'],
            'contrasena' => $clave['contrasena'],
            'documentos' => $documentos,
            'meses' => $meses,
            // Comfandi NO sale por el proxy, aunque esté configurado para otros:
            // lo que rechaza es el navegador sin ventana, no la IP —con Xvfb
            // entra desde netcup sin problema—. Mandarlo por el proxy
            // residencial solo añadía lentitud y cortes
            // (ERR_TUNNEL_CONNECTION_FAILED, navegaciones agotadas, pantallas a
            // medio montar), y por eso lo que ayer funcionaba hoy fallaba.
            'proxy' => null,
            'visible' => $conVentana ?? $this->hayXvfb(),
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout(self::SEGUNDOS_BASE + self::SEGUNDOS_POR_TRABAJADOR * count($documentos))
            ->input($entrada)
            ->run($this->comando($conVentana ?? $this->hayXvfb()));

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            $error = $salida['error'] ?? trim($resultado->errorOutput()) ?: 'El portal no respondió.';

            // El script nunca imprime la clave: lo que llega es la pantalla en
            // la que se quedó, que es lo que hace falta para arreglarlo.
            Log::warning('Comfandi subsidios: no se pudo leer el portal', [
                'nit' => $nit,
                'error' => $error,
                'url' => $salida['url'] ?? null,
            ]);

            return ['ok' => false, 'error' => $error];
        }

        return $salida;
    }

    /**
     * El listado de trabajadores que Comfandi tiene de esa empresa.
     *
     * Es la misma pantalla de Gestión de trabajadores que se usa para buscar a
     * cada persona, pero leída entera: sirve para conciliar sin preguntar uno
     * por uno. El portal la pagina y el script la recorre.
     *
     * @return array{ok:bool, filas?:array, columnas?:array, error?:string}
     */
    public function trabajadores(string $nit, ?bool $conVentana = null): array
    {
        $clave = $this->credencial($nit);

        if (! $clave) {
            return ['ok' => false, 'error' => "La empresa {$nit} no tiene la clave de Comfandi en el módulo de claves."];
        }

        $resultado = Process::path(base_path())
            ->timeout(self::SEGUNDOS_BASE + 120)
            ->input(json_encode([
                'usuario' => $clave['usuario'],
                'contrasena' => $clave['contrasena'],
                'modo' => 'listado',
                // Por lo mismo que en bloqueos(): a Comfandi se le habla directo.
                'proxy' => null,
                'visible' => $conVentana ?? $this->hayXvfb(),
            ], JSON_UNESCAPED_UNICODE))
            ->run($this->comando($conVentana ?? $this->hayXvfb()));

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            $error = $salida['error'] ?? (trim($resultado->errorOutput()) ?: 'El portal no respondió.');
            Log::warning('Comfandi: no se pudo leer el listado de trabajadores', ['nit' => $nit, 'error' => $error]);

            return ['ok' => false, 'error' => $error];
        }

        // Con media lista, la conciliación daría por no afiliado a quien sí está
        // y le abriría trámite. Mejor no entregar nada.
        if (! ($salida['completo'] ?? true)) {
            $faltan = ($salida['declarados'] ?? 0) - count($salida['filas'] ?? []);

            return ['ok' => false, 'error' => "El listado de Comfandi llegó incompleto: {$faltan} trabajador(es) de "
                .($salida['declarados'] ?? '?').' no se pudieron leer.'];
        }

        // El portal trae [Gestionar, Nombre, Documento, Ingreso, Afiliación] y
        // la conciliación espera [documento, nombre, ingreso empresa, ingreso caja].
        $salida['filas'] = collect($salida['filas'] ?? [])
            ->map(fn ($f) => [$f[2] ?? '', $f[1] ?? '', $f[3] ?? '', $f[4] ?? ''])
            ->filter(fn ($f) => preg_replace('/\D/', '', (string) $f[0]) !== '')
            ->values()
            ->all();

        return $salida;
    }

    /**
     * Cómo se lanza el script.
     *
     * El portal solo atiende a un navegador con ventana —un Chrome sin ella
     * recibe el "Access Denied" de Akamai igual desde el servidor que desde una
     * casa en Cali, así que no es cosa de la IP—. En el servidor la ventana la
     * pone `xvfb-run`, un display virtual: Chrome corre como siempre, solo que
     * sin pantalla donde dibujar. Donde no hay Xvfb (el Mac) se usa el modo sin
     * ventana, que sirve para todo menos para hablar con Comfandi.
     */
    private function comando(bool $conVentana): string
    {
        $node = ArlSuraSesionService::binarioNode().' scripts/comfandi-subsidios.mjs';

        return $conVentana && $this->hayXvfb()
            ? 'xvfb-run -a --server-args="-screen 0 1400x900x24" '.$node
            : $node;
    }

    private function hayXvfb(): bool
    {
        static $hay = null;

        return $hay ??= is_executable('/usr/bin/xvfb-run');
    }

    /**
     * La clave de Comfandi de esa empresa, en cualquier aliado.
     *
     * Es la misma empresa ante la caja, así que no se filtra por aliado: si la
     * clave está guardada una vez, sirve para todos.
     *
     * @return array{usuario:string, contrasena:string}|null
     */
    public function credencial(string $nit): ?array
    {
        $fila = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', preg_replace('/\D/', '', $nit))
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, 'comfandi_caja', 'CAJA', '%COMFANDI%'))
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
            ->orderByDesc('c.updated_at')
            ->first(['c.usuario', 'c.contrasena']);

        return $fila ? ['usuario' => trim($fila->usuario), 'contrasena' => (string) $fila->contrasena] : null;
    }
}
