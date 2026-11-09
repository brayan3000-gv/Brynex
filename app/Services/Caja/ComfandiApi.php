<?php

namespace App\Services\Caja;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Comfandi por su API JSON (`ria.sucursalcomfandi.com`), en vez de raspar el
 * portal. Akamai bloquea cualquier cliente que no sea un navegador real, así que
 * las llamadas NO salen por Guzzle: se hacen con `fetch()` desde dentro del
 * Chrome del robot (`scripts/comfandi-subsidios.mjs`, modo `via: api`), que es lo
 * único que pasa Akamai. Este servicio arma la entrada, lanza el robot y
 * devuelve el JSON ya parseado.
 *
 * Autentica con el usuario gestor único: un login + un TOTP para todas las
 * empresas. Las empresas y sus `x-company-id` salen del token (claim companies);
 * la credencial (clave + secreto TOTP) se lee del módulo de claves por el
 * documento del gestor. Ver config/comfandi.php y docs/comfandi-api.md.
 */
class ComfandiApi
{
    private const SEGUNDOS_BASE = 200;

    private const SEGUNDOS_POR_DOC = 20;

    /** @return array{usuario:string, clave:string, totp:string}|null */
    private function gestor(): ?array
    {
        $usuario = preg_replace('/\D/', '', (string) config('comfandi.gestor_usuario'));

        if ($usuario === '') {
            return null;
        }

        // La clave del gestor en el módulo (una entrada de Comfandi por su
        // documento). Como en ComfandiSubsidiosHeadless, se busca por NIT/usuario
        // sin mirar el aliado: la cuenta es una sola ante la caja.
        $fila = DB::table('clave_accesos as c')
            ->where('c.usuario', $usuario)
            ->where('c.activo', true)
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, 'comfandi_caja', 'CAJA', '%COMFANDI%'))
            ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
            ->orderByDesc('c.updated_at')
            ->first(['c.usuario', 'c.contrasena', 'c.totp_secret']);

        if (! $fila) {
            return null;
        }

        return [
            'usuario' => trim((string) $fila->usuario),
            'clave' => (string) $fila->contrasena,
            'totp' => trim((string) ($fila->totp_secret ?? '')),
        ];
    }

    public function disponible(): bool
    {
        return $this->gestor() !== null;
    }

    /**
     * Lanza el robot en modo API con la credencial del gestor y devuelve el JSON.
     *
     * @param  array  $opciones  modo/nit/documentos/desde/hasta/meses
     */
    public function ejecutar(array $opciones, ?int $segundos = null): array
    {
        $g = $this->gestor();

        if (! $g) {
            return ['ok' => false, 'error' => 'No hay clave del gestor de Comfandi en el módulo (usuario '.config('comfandi.gestor_usuario').').'];
        }

        $visible = $this->hayXvfb(); // Comfandi exige ventana; en el servidor la da Xvfb.

        $entrada = json_encode(array_merge([
            'via' => 'api',
            'usuario' => $g['usuario'],
            'contrasena' => $g['clave'],
            'totpSecret' => $g['totp'],
            // El navegador real desde netcup pasa Akamai sin proxy (lo que rechaza
            // es el cliente sin ventana, no la IP).
            'proxy' => null,
            'visible' => $visible,
        ], $opciones), JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout($segundos ?? self::SEGUNDOS_BASE)
            ->input($entrada)
            ->run($this->comando());

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $salida['error'] ?? (trim($resultado->errorOutput()) ?: 'El portal no respondió.'),
            ];
        }

        return $salida;
    }

    /**
     * Como ejecutar(), pero si vuelve «ok» sin titular para esa cédula reintenta
     * UNA vez. Esa es la firma del arranque en frío del robot: entra y saca el
     * token, pero recién elegida la empresa la sesión aún no asienta y el /sap
     * —y con él los subsidios— vuelve vacío. En la segunda corrida el perfil ya
     * está caliente y trae los datos, así que un arranque en frío no se confunde
     * con «no está en la caja». Un titular presente pero sin movimientos sí es
     * real (está afiliado, sin subsidios) y no gatilla el reintento.
     */
    private function ejecutarConTitular(array $opciones, string $cedula, ?int $segundos = null): array
    {
        $r = $this->ejecutar($opciones, $segundos);

        $sinTitular = fn (array $x): bool => ($x['ok'] ?? false)
            && ($x['trabajadores'][$cedula]['body']['Titular'][0] ?? null) === null;

        if ($sinTitular($r)) {
            $r = $this->ejecutar($opciones, $segundos);
            $r['reintento'] = true;
        }

        return $r;
    }

    /** Las empresas del gestor con su x-company-id (del token). */
    public function empresas(): array
    {
        $r = $this->ejecutar([]);

        return $r['ok'] ?? false ? ($r['empresas'] ?? []) : [];
    }

    /**
     * El trabajador y sus beneficiarios (PACS) en una empresa.
     *
     * @return array{ok:bool, titular?:array, error?:string}
     */
    public function trabajador(string $nit, string $cedula): array
    {
        $cedula = preg_replace('/\D/', '', $cedula);
        $r = $this->ejecutarConTitular([
            'modo' => 'subsidios', // el modo con documentos: trae /sap del trabajador
            'nit' => preg_replace('/\D/', '', $nit),
            'documentos' => [$cedula],
        ], $cedula);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'No se pudo consultar.'];
        }

        $t = $r['trabajadores'][$cedula] ?? null;
        $titular = $t['body']['Titular'][0] ?? null;

        return $titular
            ? ['ok' => true, 'titular' => $titular, 'empresas' => $r['empresas'] ?? []]
            : ['ok' => false, 'error' => 'El trabajador no aparece en esa empresa en Comfandi.'];
    }

    /**
     * El estado de subsidios por mes (pagos, retiros, bloqueos) de un trabajador.
     *
     * @param  string  $desde  AAAA-MM-DD
     * @param  string  $hasta  AAAA-MM-DD
     * @return array{ok:bool, movimientos?:array, error?:string}
     */
    public function subsidios(string $nit, string $cedula, string $desde, string $hasta): array
    {
        $cedula = preg_replace('/\D/', '', $cedula);
        $meses = $this->mesesEntre($desde, $hasta);

        $r = $this->ejecutarConTitular([
            'modo' => 'subsidios',
            'nit' => preg_replace('/\D/', '', $nit),
            'documentos' => [$cedula],
            'desde' => $desde,
            'hasta' => $hasta,
            'meses' => $meses,
        ], $cedula, self::SEGUNDOS_BASE + self::SEGUNDOS_POR_DOC + 30);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'No se pudo consultar.'];
        }

        $s = $r['subsidios'][$cedula] ?? [];

        return ['ok' => true, 'movimientos' => $s['movimientos'] ?? [], 'fuente' => $s['fuente'] ?? null];
    }

    /**
     * Trabajador + beneficiarios + subsidios en UNA sola corrida del robot (el
     * login es lo caro, así que no se repite). Para la vista de datos de caja.
     *
     * @return array{ok:bool, titular?:?array, movimientos?:array, error?:string}
     */
    public function datos(string $nit, string $cedula, string $desde, string $hasta): array
    {
        $cedula = preg_replace('/\D/', '', $cedula);

        $r = $this->ejecutarConTitular([
            'modo' => 'subsidios',
            'nit' => preg_replace('/\D/', '', $nit),
            'documentos' => [$cedula],
            'desde' => $desde,
            'hasta' => $hasta,
            'meses' => $this->mesesEntre($desde, $hasta),
        ], $cedula, self::SEGUNDOS_BASE + self::SEGUNDOS_POR_DOC + 30);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'No se pudo consultar.'];
        }

        return [
            'ok' => true,
            'titular' => $r['trabajadores'][$cedula]['body']['Titular'][0] ?? null,
            'movimientos' => $r['subsidios'][$cedula]['movimientos'] ?? [],
            'empresas' => $r['empresas'] ?? [],
        ];
    }

    /** El listado de trabajadores de una empresa. */
    public function listado(string $nit): array
    {
        $r = $this->ejecutar(['modo' => 'listado', 'nit' => preg_replace('/\D/', '', $nit)]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'No se pudo consultar.'];
        }

        return ['ok' => true, 'listado' => $r['listado']['body'] ?? null, 'empresas' => $r['empresas'] ?? []];
    }

    private function mesesEntre(string $desde, string $hasta): int
    {
        try {
            $d = new \DateTime($desde);
            $h = new \DateTime($hasta);
            $m = ($h->format('Y') - $d->format('Y')) * 12 + ($h->format('n') - $d->format('n'));

            return max(1, min(24, $m));
        } catch (\Throwable) {
            return 12;
        }
    }

    private function comando(): string
    {
        $node = ArlSuraSesionService::binarioNode().' scripts/comfandi-subsidios.mjs';

        return $this->hayXvfb()
            ? 'xvfb-run -a --server-args="-screen 0 1400x900x24" '.$node
            : $node;
    }

    private function hayXvfb(): bool
    {
        static $hay = null;

        return $hay ??= is_executable('/usr/bin/xvfb-run');
    }
}
