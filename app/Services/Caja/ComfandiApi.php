<?php

namespace App\Services\Caja;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** Recibe cada paso del robot (ver ComfandiConsultaApiJob). */
    private ?\Closure $alAvanzar = null;

    public function alAvanzar(callable $avisar): static
    {
        $this->alAvanzar = \Closure::fromCallable($avisar);

        return $this;
    }

    private function avisar(string $paso): void
    {
        if ($this->alAvanzar) {
            ($this->alAvanzar)($paso);
        }
    }

    /** Avisa el último `paso:` que traiga un trozo de salida del robot. */
    private function avisarDesdeSalida(string $salida): void
    {
        if (preg_match_all('/^paso: (.+)$/m', $salida, $m)) {
            $this->avisar(trim(end($m[1])));
        }
    }

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

        // El robot abre un perfil de Chrome único por gestor (SingletonLock): dos
        // corridas a la vez chocan y una queda tumbada (fue lo que colgó una
        // consulta). Se serializan con un lock por usuario; si hay otra en curso,
        // espera su turno y si no lo alcanza, avisa sin tumbar nada.
        $segs = $segundos ?? self::SEGUNDOS_BASE;
        $lock = \Illuminate\Support\Facades\Cache::lock('comfandi-robot:'.$g['usuario'], $segs + 60);

        if (! $lock->get()) {
            $this->avisar('Hay otra consulta de Comfandi en curso: esperando turno');

            try {
                $lock->block($segs + 40);
            } catch (\Throwable) {
                return ['ok' => false, 'error' => 'Hay otra consulta de Comfandi en curso; intenta de nuevo en un momento.'];
            }
        }

        try {
            // Primero el daemon (sesión caliente): si está vivo —o se puede
            // levantar— reusa la sesión y la consulta sale rápido. Si por lo que
            // sea no se puede usar, cae al robot de un disparo (abre Chrome, entra
            // y consulta una vez). Así la función siempre responde.
            $r = $this->porDaemon($g, $opciones);
            if ($r === null) {
                $r = $this->unDisparo($g, $opciones, $segs);
            }

            // Toda respuesta trae `empresas` (del token del gestor): se cachean sus
            // NITs para saber, sin esperar, qué empresas puede afiliar el servidor.
            $this->recordarEmpresasDelGestor($r);

            return $r;
        } finally {
            $lock->release();
        }
    }

    /** Cachea los NITs de las empresas del gestor (del token), para decidir rápido. */
    private function recordarEmpresasDelGestor(array $r): void
    {
        if (empty($r['empresas']) || ! is_array($r['empresas'])) {
            return;
        }
        $nits = collect($r['empresas'])
            ->map(fn ($e) => preg_replace('/\D/', '', (string) ($e['nit'] ?? '')))
            ->filter()->unique()->values()->all();
        if ($nits) {
            \Illuminate\Support\Facades\Cache::put('comfandi:gestor-nits', $nits, now()->addDays(30));
        }
    }

    /** Los NITs de las empresas del gestor único (cacheados), o null si aún no se sabe. */
    public function empresasDelGestor(): ?array
    {
        return \Illuminate\Support\Facades\Cache::get('comfandi:gestor-nits');
    }

    /** ¿El servidor puede afiliar esta empresa? true/false si se sabe, null si aún no. */
    public function servidorPuedeAfiliar(string $nit): ?bool
    {
        $nits = $this->empresasDelGestor();
        if ($nits === null) {
            return null;
        }

        return in_array(preg_replace('/\D/', '', $nit), $nits, true);
    }

    /** La entrada base del robot (credenciales del gestor + vía API). */
    private function entradaBase(array $g): array
    {
        return [
            'via' => 'api',
            'usuario' => $g['usuario'],
            'contrasena' => $g['clave'],
            'totpSecret' => $g['totp'],
            // El navegador real desde netcup pasa Akamai sin proxy (lo que rechaza
            // es el cliente sin ventana, no la IP).
            'proxy' => null,
            'visible' => $this->hayXvfb(), // Comfandi exige ventana; en el servidor la da Xvfb.
        ];
    }

    /** Corre la consulta con el robot de un disparo (abre Chrome, entra, consulta, sale). */
    private function unDisparo(array $g, array $opciones, int $segs): array
    {
        $this->avisar('Abriendo una sesión nueva de Comfandi');

        $entrada = json_encode(array_merge($this->entradaBase($g), $opciones), JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            ->timeout($segs)
            ->input($entrada)
            ->run($this->comando(), function (string $tipo, string $trozo) {
                if ($tipo === 'err') {
                    $this->avisarDesdeSalida($trozo);
                }
            });

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            $stderr = trim((string) preg_replace('/^paso: .*$\R?/m', '', $resultado->errorOutput()));

            return [
                'ok' => false,
                'error' => $salida['error'] ?? ($stderr ?: 'El portal no respondió.'),
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
            $this->avisar('La sesión recién abierta volvió vacía: repitiendo la consulta');
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

    /**
     * El certificado de afiliación a la caja (PDF) de un trabajador, con o sin
     * beneficiarios. Lo baja por `/certificate/new-create-affiliation` (código
     * BEN = con beneficiarios). Devuelve el PDF en base64.
     *
     * @return array{ok:bool, base64?:string, contentType?:string, error?:string}
     */
    public function certificado(string $nit, string $cedula, string $tipoDoc = 'CO1C', bool $conBeneficiarios = true): array
    {
        $cedula = preg_replace('/\D/', '', $cedula);

        $r = $this->ejecutar([
            'modo' => 'certificado',
            'nit' => preg_replace('/\D/', '', $nit),
            'documentos' => [$cedula],
            'tipoDocTrab' => $tipoDoc,
            'certCodigo' => $conBeneficiarios ? 'BEN' : 'TRA',
        ], self::SEGUNDOS_BASE + self::SEGUNDOS_POR_DOC);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'No se pudo consultar.'];
        }

        $c = $r['certificados'][$cedula] ?? null;
        $status = (int) ($c['status'] ?? 0);
        $base64 = (string) ($c['base64'] ?? '');
        $bin = $base64 !== '' ? base64_decode($base64, true) : false;
        $esPdf = $bin !== false && str_starts_with($bin, '%PDF');

        // 200 y 201 (Created) son éxito: lo que decide es que de verdad venga un
        // PDF. Comfandi a veces responde 201 con el PDF ya generado (y el chequeo
        // viejo, que solo aceptaba 200, lo tiraba como error). Un 200/201 SIN un
        // PDF válido suele ser «aceptado, generando» (async): no es un error feo,
        // hay que reintentar en un momento.
        if (! $c || ! in_array($status, [200, 201], true) || ! $esPdf) {
            Log::info('Comfandi certificado sin PDF listo', ['cedula' => $cedula, 'status' => $c['status'] ?? null, 'b64len' => strlen($base64), 'pdf' => $esPdf]);

            return [
                'ok' => false,
                'enProceso' => true,
                'error' => 'El certificado todavía se está generando en Comfandi (estado '.($c['status'] ?? '?').'). Reintenta en un momento.',
            ];
        }

        // Guarda contra placeholder: a veces Comfandi devuelve un PDF válido pero
        // que no es del trabajador (pasó con Brayan: salió un cert a nombre de
        // «KAREN»). Si se puede leer texto del PDF y NO aparece la cédula del
        // trabajador, no es su certificado → se trata como «en proceso». Si no se
        // puede leer el texto (fuentes embebidas), NO se rechaza, para no botar un
        // certificado bueno.
        $digitos = self::digitosDelPdf($bin);
        if (strlen($digitos) >= 20 && ! str_contains($digitos, $cedula)) {
            Log::info('Comfandi certificado: el PDF no menciona la cédula del trabajador (¿placeholder?)', [
                'cedula' => $cedula, 'status' => $status, 'digitos_leidos' => strlen($digitos),
            ]);

            return [
                'ok' => false,
                'enProceso' => true,
                'error' => 'El certificado que devolvió Comfandi no corresponde al trabajador (aún se está generando). Reintenta en un momento.',
            ];
        }

        return ['ok' => true, 'base64' => $base64, 'contentType' => $c['contentType'] ?? 'application/pdf'];
    }

    /**
     * Los dígitos del texto visible de un PDF, para comprobar que el certificado
     * sea del trabajador (que aparezca su cédula). Descomprime los streams
     * (FlateDecode) y, si alguno viene sin comprimir pero es texto, también lo
     * suma; los streams binarios (imágenes/fuentes) se ignoran. Devuelve '' si no
     * se pudo leer nada, y entonces el llamador NO rechaza el PDF.
     */
    private static function digitosDelPdf(string $bin): string
    {
        $texto = '';
        if (preg_match_all('/stream\r?\n?(.*?)\r?\n?endstream/s', $bin, $m)) {
            foreach ($m[1] as $s) {
                $plano = @gzuncompress($s);
                if (! is_string($plano)) {
                    $plano = @gzinflate($s);
                }
                if (! is_string($plano)) {
                    // ¿stream de texto sin comprimir? Se suma si es casi todo imprimible.
                    $imprimible = strlen((string) preg_replace('/[^\x20-\x7E]/', '', $s));
                    $plano = ($s !== '' && $imprimible / max(1, strlen($s)) > 0.7) ? $s : '';
                }
                $texto .= ' '.$plano;
            }
        }

        return (string) preg_replace('/\D+/', '', $texto);
    }

    /**
     * Etiqueta de parentesco para mostrar. Comfandi devuelve la categoría genérica
     * «Padre - Madre Biológica» para cualquier progenitor (y a veces con doble
     * espacio), que se lee mal: un hombre aparecía como «Madre Biológica». Se
     * resuelve a Padre/Madre según el sexo del beneficiario; el resto de etiquetas
     * se dejan igual, solo colapsando espacios.
     */
    public static function etiquetaParentesco(?string $raw, ?string $genero): string
    {
        $p = trim(preg_replace('/\s+/', ' ', (string) $raw));
        if ($p === '') {
            return '';
        }

        // «Padre - Madre [Biológica/Adoptiva/…]» → Padre/Madre según el sexo.
        if (preg_match('/^padre\s*-\s*madre\b\s*(.*)$/i', $p, $m)) {
            $es_f = strtoupper(trim((string) $genero)) === 'F';
            $sufijo = trim($m[1]);
            // Concordancia del sufijo para «Padre»: Biológica→Biológico, etc.
            if (! $es_f) {
                $sufijo = preg_replace('/(biol[oó]gic|adoptiv|legal)a\b/iu', '$1o', $sufijo);
            }

            return trim(($es_f ? 'Madre' : 'Padre').' '.$sufijo);
        }

        return $p;
    }

    /**
     * Afilia (radica) a un trabajador por el formulario individual del portal,
     * reusando la sesión del gestor del daemon (DOM, no API). Con $finalizar=false
     * solo llena y devuelve el estado (dry-run, para revisar). El robot cambia a la
     * empresa del contrato y ABORTA si no es la correcta. Lo llama
     * ComfandiConsultaApiJob (worker), no una petición web: tarda 1–3 min.
     *
     * @return array{ok:bool, numero?:string, texto?:string, yaAfiliado?:bool, desde?:string, error?:string}
     */
    public function afiliarIndividual(\App\Models\Contrato $contrato, bool $finalizar = false, ?string $progresoFile = null): array
    {
        $prep = app(ComfandiCajaService::class)->preparar($contrato);
        if (! empty($prep['problemas'])) {
            return ['ok' => false, 'error' => implode(' ', $prep['problemas'])];
        }
        if (empty($prep['portal'])) {
            return ['ok' => false, 'error' => 'No se pudo preparar la afiliación de Comfandi.'];
        }

        $op = ['modo' => 'afiliarIndividual', 'finalizar' => $finalizar];
        if ($progresoFile) {
            $op['progresoFile'] = $progresoFile; // el daemon escribe aquí el paso actual
        }

        return $this->ejecutar(array_merge($op, $prep['portal']), self::SEGUNDOS_BASE + 150);
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

    // ─── Daemon de sesión caliente ──────────────────────────────────────────
    // En vez de abrir Chrome + login por cada consulta (~1–2 min), un daemon deja
    // la sesión viva ~12 min atendiendo consultas por HTTP local. La 1ª consulta
    // lo levanta (paga el login); las siguientes salen en segundos.

    /** La carpeta del perfil persistente del gestor (ahí vive el statefile del daemon). */
    private function perfilDir(array $g): string
    {
        return storage_path('app/comfandi-perfiles/'.$g['usuario']);
    }

    /**
     * Intenta resolver la consulta por el daemon: lo reusa si está vivo o lo
     * levanta si no. Devuelve la salida, o null si el daemon no se pudo usar
     * (para que ejecutar() caiga al robot de un disparo).
     */
    private function porDaemon(array $g, array $opciones): ?array
    {
        $dir = $this->perfilDir($g);

        $estado = $this->daemonVivo($dir);
        if ($estado) {
            $this->avisar('Usando la sesión de Comfandi que ya estaba abierta');
        } else {
            $this->avisar('Abriendo el navegador para entrar a Comfandi');
            $estado = $this->levantarDaemon($g, $dir);
        }

        if (! $estado) {
            return null;
        }

        $this->avisar(($opciones['modo'] ?? '') === 'certificado'
            ? 'Generando el certificado en Comfandi'
            : 'Consultando trabajador, beneficiarios y subsidios');

        return $this->enviarAlDaemon((int) $estado['puerto'], $opciones);
    }

    /** El daemon vivo (statefile + pid + health), o null. */
    private function daemonVivo(string $dir): ?array
    {
        $f = $dir.'/daemon.json';
        if (! is_file($f)) {
            return null;
        }

        $j = json_decode((string) @file_get_contents($f), true);
        if (! is_array($j) || empty($j['puerto']) || empty($j['pid'])) {
            return null;
        }

        if (! $this->pidVivo((int) $j['pid'])) {
            @unlink($f); // statefile huérfano de un daemon muerto

            return null;
        }

        return $this->daemonHealth((int) $j['puerto']) ? $j : null;
    }

    /** Lanza el daemon desprendido y espera a que entre y levante el servidor. */
    private function levantarDaemon(array $g, string $dir): ?array
    {
        @mkdir($dir, 0775, true);

        // La entrada va en un archivo (no por argv) para no dejar la clave en `ps`;
        // el robot lo lee y lo borra. 0600 por si acaso.
        $entradaFile = $dir.'/.daemon-entrada-'.bin2hex(random_bytes(6)).'.json';
        // inactividadSeg: la sesión viva se mantiene hasta 15 min desde la última
        // consulta REAL (los latidos de mantenimiento no cuentan); después se
        // autolimpia. El latido (default 100s en el robot) refresca el token
        // —que vive 5 min— para que no caduque en los huecos.
        @file_put_contents($entradaFile, json_encode(array_merge($this->entradaBase($g), ['servidor' => true, 'inactividadSeg' => 900]), JSON_UNESCAPED_UNICODE));
        @chmod($entradaFile, 0600);

        $node = ArlSuraSesionService::binarioNode().' scripts/comfandi-subsidios.mjs --entrada-file='.escapeshellarg($entradaFile);
        $cmd = $this->hayXvfb()
            ? 'xvfb-run -a --server-args="-screen 0 1400x900x24" '.$node
            : $node;

        // El log acumula corridas viejas: solo interesa lo que escriba de aquí en adelante.
        $log = $dir.'/daemon.log';
        $leidoHasta = is_file($log) ? (int) filesize($log) : 0;

        // Desprendido (setsid) para que sobreviva a esta petición PHP.
        $inner = 'cd '.escapeshellarg(base_path()).' && '.$cmd.' >> '.escapeshellarg($log).' 2>&1';
        @exec('setsid bash -c '.escapeshellarg($inner).' >/dev/null 2>&1 &');

        // El daemon escribe daemon.json cuando ya entró y levantó el servidor
        // (login ~1–2 min). Se sondea hasta ~3 min.
        for ($i = 0; $i < 120; $i++) {
            usleep(1_500_000);

            $nuevo = @file_get_contents($log, false, null, $leidoHasta);
            if (is_string($nuevo) && $nuevo !== '') {
                $leidoHasta += strlen($nuevo);
                $this->avisarDesdeSalida($nuevo);
            }

            if ($est = $this->daemonVivo($dir)) {
                return $est;
            }
        }

        return null;
    }

    /** Manda la consulta al daemon por HTTP y devuelve la salida (null si no respondió). */
    private function enviarAlDaemon(int $puerto, array $opciones): ?array
    {
        if (! function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init('http://127.0.0.1:'.$puerto.'/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($opciones, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 200, // una consulta reusando la sesión: holgado
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $err !== '') {
            return null; // el daemon no respondió → ejecutar() cae al robot de un disparo
        }

        $salida = json_decode($resp, true);

        // Respondió (aunque sea con error de negocio): es un resultado real, no un
        // fallo del daemon, así que se devuelve tal cual (nada de fallback).
        return is_array($salida) ? $salida : ['ok' => false, 'error' => 'El daemon devolvió una respuesta inválida.'];
    }

    /** ¿El proceso (pid) sigue vivo? */
    private function pidVivo(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/'.$pid);
    }

    /** ¿El daemon responde en su puerto de health? */
    private function daemonHealth(int $puerto): bool
    {
        $r = @file_get_contents('http://127.0.0.1:'.$puerto.'/health', false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true],
        ]));

        return is_string($r) && str_contains($r, 'ready');
    }
}
