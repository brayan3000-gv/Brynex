<?php

namespace App\Console\Commands;

use App\Models\DataicoConfiguracion;
use App\Models\DataicoEnvio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Reconstruye en Brynex qué facturas ya se emitieron ante la DIAN.
 *
 * El problema que resuelve: BRYGAR lleva ~1.128 facturas emitidas en Dataico
 * (subidas con el Excel del módulo viejo), pero `facturas.fe_marcada` estaba en
 * cero en TODAS las filas — nadie usó nunca el botón de marcar. Sin este
 * cruce, encender la emisión automática con un corte hacia atrás volvería a
 * emitir ante la DIAN lo que ya está emitido, y eso solo se deshace con nota
 * crédito una por una.
 *
 * Va en tres fases, y el orden importa:
 *
 *   1. Precarga  — 3 consultas y ya. La primera versión consultaba `empresas`
 *      una vez por factura: 1.200 viajes al SQL Server a ~250 ms cada uno, y
 *      el túnel SSH se cayó a mitad de camino arrastrando la corrida entera.
 *   2. Consulta  — 20 minutos hablando SOLO con Dataico. Sin tocar la BD, un
 *      corte de túnel durante esta fase no rompe nada.
 *   3. Escritura — todo junto al final, en tandas.
 *
 * Cómo cruza: las facturas viejas no llevan el número de Brynex en ningún
 * campo (el Excel mandaba `NUMERO` vacío y sin referencia), así que el cruce
 * es por **documento del adquiriente + valor exacto**, tomando la factura más
 * antigua que quede libre. Ese orden no es arbitrario: los consecutivos de
 * Dataico van en orden de carga y se subió mes a mes, de lo más viejo a lo más
 * nuevo. Lo que no cruza se reporta; no se inventa.
 */
class DataicoConciliar extends Command
{
    protected $signature = 'dataico:conciliar
        {--aliado= : Aliado cuya configuración se usa}
        {--desde=1 : Primer consecutivo a consultar}
        {--hasta= : Último consecutivo. Sin él, se detecta solo}
        {--ejecutar : Escribe los cambios. Sin esto, solo reporta}
        {--cache= : Archivo donde guardar/reusar lo traído de Dataico}';

    protected $description = 'Cruza las facturas ya emitidas en Dataico contra las facturas de Brynex';

    /** Pausa entre consultas. Dataico responde 429 a las ráfagas. */
    private const ESPERA_MS = 350;

    /** Reintentos por consecutivo antes de darlo por fallido. */
    private const MAX_INTENTOS = 4;

    private DataicoConfiguracion $cfg;

    /** Consecutivos que no se pudieron consultar. Invalidan la corrida. */
    private array $fallidos = [];

    /** "documento|valor" → [ ['numero'=>int,'fecha'=>string], … ] */
    private array $indice = [];

    /** Números de Dataico ya registrados en Brynex, para no recontarlos. */
    private array $yaRegistrados = [];

    /**
     * Facturas traídas de Dataico, por consecutivo.
     *
     * Consultar las 1.200 toma 20 minutos; volver a cruzarlas con una regla
     * corregida no tiene por qué costar otros 20. Con `--cache` la corrida
     * guarda lo que trajo y la siguiente lo reusa.
     */
    private array $cache = [];

    private ?string $rutaCache = null;

    public function handle(): int
    {
        $aliadoId = (int) ($this->option('aliado') ?: 0);
        $cfg = DataicoConfiguracion::where('aliado_id', $aliadoId)->first();

        if (! $cfg || blank($cfg->dataico_account_id) || blank($cfg->auth_token)) {
            $this->error("No hay credenciales de Dataico guardadas para el aliado {$aliadoId}.");

            return self::FAILURE;
        }

        if (blank($cfg->prefijo)) {
            $this->error('Falta el prefijo en la configuración (por ejemplo FE).');

            return self::FAILURE;
        }

        $this->cfg = $cfg;
        $ejecutar = (bool) $this->option('ejecutar');
        $this->rutaCache = $this->option('cache');

        if ($this->rutaCache && is_readable($this->rutaCache)) {
            $this->cache = json_decode(file_get_contents($this->rutaCache), true) ?: [];
            $this->line('  caché: '.count($this->cache).' facturas de Dataico ya traídas.');
        }

        // ── Fase 1: precarga ────────────────────────────────────────────
        $this->line('Precargando facturas de Brynex…');
        $usados = $this->precargar($aliadoId);
        $this->line('  '.count($this->indice).' llaves documento+valor, '
                   .count($usados).' grupos ya conciliados antes.');

        $desde = (int) $this->option('desde');
        $hasta = $this->option('hasta') !== null
            ? (int) $this->option('hasta')
            : $this->detectarUltimo($desde);

        if ($hasta < $desde) {
            $this->error('No se encontró ninguna factura emitida en ese rango.');

            return self::FAILURE;
        }

        // ── Fase 2: consulta a Dataico, sin tocar la BD ─────────────────
        $this->info("Consultando {$cfg->prefijo}{$desde} … {$cfg->prefijo}{$hasta}"
                   .($ejecutar ? '' : '  (SIMULACIÓN — no escribe nada)'));

        $barra = $this->output->createProgressBar($hasta - $desde + 1);
        $barra->start();

        $cruces = [];
        $sinCruce = [];
        $noExisten = 0;
        $yaEstaban = 0;

        for ($n = $desde; $n <= $hasta; $n++) {
            $barra->advance();

            $r = $this->consultar($n);

            if ($r['estado'] === 'error') {
                $this->fallidos[] = $cfg->prefijo.$n;

                continue;
            }

            if ($r['estado'] === 'ausente') {
                $noExisten++;

                continue;
            }

            $factura = $r['factura'];

            // Ya conciliada en una corrida anterior: ni se recuenta como
            // cruce nuevo ni se reporta como huérfana.
            if (isset($this->yaRegistrados[$factura['numero']])) {
                $yaEstaban++;

                continue;
            }

            $numeroBrynex = $this->cruzar($factura, $usados);

            if ($numeroBrynex === null) {
                $sinCruce[] = [
                    $factura['numero'],
                    $factura['identificacion'],
                    number_format($factura['precio']),
                    $factura['fecha'],
                ];

                continue;
            }

            $usados[$numeroBrynex] = true;
            $cruces[$numeroBrynex] = $factura;
        }

        $barra->finish();
        $this->newLine(2);

        if ($this->rutaCache) {
            file_put_contents($this->rutaCache, json_encode($this->cache));
            $this->line('  caché guardada: '.count($this->cache).' facturas.');
        }

        // ── Fase 3: escritura ───────────────────────────────────────────
        if ($ejecutar && ! empty($cruces)) {
            $this->line('Escribiendo '.count($cruces).' conciliaciones…');
            $this->escribir($aliadoId, $cruces);
        }

        $this->line('  cruzadas ............. '.count($cruces));
        $this->line("  ya conciliadas ....... {$yaEstaban}");
        $this->line('  sin cruce ............ '.count($sinCruce));
        $this->line("  consecutivos vacíos .. {$noExisten}");
        $this->line('  no consultables ...... '.count($this->fallidos));

        if (! empty($this->fallidos)) {
            $this->newLine();
            $this->error('⚠️  '.count($this->fallidos).' consecutivos no se pudieron consultar.');
            $this->line('   '.implode(', ', array_slice($this->fallidos, 0, 30))
                       .(count($this->fallidos) > 30 ? ' …' : ''));
            $this->line('   Un consecutivo no consultado NO es un consecutivo vacío: puede ser una');
            $this->line('   factura ya emitida que quedaría sin marcar, y por lo tanto elegible para');
            $this->line('   volver a emitirse ante la DIAN. Vuelve a correr el comando sobre ese');
            $this->line('   rango antes de dar la conciliación por buena.');
        }

        if (! empty($sinCruce)) {
            $this->newLine();
            $this->warn('Facturas emitidas en Dataico que no se pudieron atar a una factura de Brynex:');
            $this->table(['Número', 'Documento', 'Valor', 'Fecha'], array_slice($sinCruce, 0, 40));

            if (count($sinCruce) > 40) {
                $this->line('  … y '.(count($sinCruce) - 40).' más.');
            }
        }

        if (! $ejecutar) {
            $this->newLine();
            $this->comment('Nada se escribió. Repite con --ejecutar para aplicar.');
        }

        return self::SUCCESS;
    }

    // ─── Fase 1: precarga ────────────────────────────────────────────────

    /**
     * Arma en memoria el índice "documento + valor → grupos de Brynex".
     *
     * Tres consultas y ninguna más: los grupos con su valor y fecha, las
     * cédulas de cada grupo, y el NIT de cada empresa. Todo lo demás se
     * resuelve en PHP.
     *
     * @return array<int,bool> grupos ya conciliados, que no se vuelven a tocar
     */
    private function precargar(int $aliadoId): array
    {
        $grupos = DB::select(
            'SELECT f.numero_factura,
                    MIN(f.empresa_id) AS empresa_id,
                    SUM('.\App\Services\Dataico\SeleccionFacturasService::BASE_FE_SQL.') AS base,
                    CONVERT(varchar(10), MIN(f.fecha_pago), 23) AS fecha
             FROM facturas f
             WHERE f.aliado_id = ? AND f.deleted_at IS NULL
             GROUP BY f.numero_factura',
            [$aliadoId]
        );

        // Un grupo puede tener varias cédulas; cualquiera sirve de llave
        // cuando la factura es individual.
        $cedulasPorGrupo = [];
        foreach (DB::select(
            'SELECT DISTINCT numero_factura, cedula FROM facturas
             WHERE aliado_id = ? AND deleted_at IS NULL AND cedula IS NOT NULL',
            [$aliadoId]
        ) as $fila) {
            $cedulasPorGrupo[(int) $fila->numero_factura][] = (string) $fila->cedula;
        }

        $nitPorEmpresa = [];
        foreach (DB::select(
            'SELECT id, nit FROM empresas WHERE aliado_id = ? AND nit IS NOT NULL',
            [$aliadoId]
        ) as $e) {
            $doc = preg_replace('/\D+/', '', (string) $e->nit);
            if ($doc !== '') {
                $nitPorEmpresa[(int) $e->id] = $doc;
            }
        }

        foreach ($grupos as $g) {
            $numero = (int) $g->numero_factura;
            $base = (int) $g->base;

            if ($base <= 0) {
                continue;
            }

            // Lote empresarial: la llave es el documento del empleador.
            // Individual: cualquiera de las cédulas del grupo.
            $documentos = $g->empresa_id !== null && isset($nitPorEmpresa[(int) $g->empresa_id])
                ? [$nitPorEmpresa[(int) $g->empresa_id]]
                : ($cedulasPorGrupo[$numero] ?? []);

            foreach (array_unique($documentos) as $doc) {
                $this->indice[$doc.'|'.$base][] = [
                    'numero' => $numero,
                    'fecha' => $g->fecha,
                ];
            }
        }

        $this->yaRegistrados = DataicoEnvio::aliado($aliadoId)
            ->whereNotNull('dataico_numero')
            ->pluck('dataico_numero')
            ->mapWithKeys(fn ($n) => [(string) $n => true])
            ->all();

        return DataicoEnvio::aliado($aliadoId)->pluck('numero_factura')
            ->mapWithKeys(fn ($n) => [(int) $n => true])
            ->all();
    }

    // ─── Fase 2: Dataico ─────────────────────────────────────────────────

    /**
     * Busca el último consecutivo emitido.
     *
     * No se puede asumir que la numeración arranque en 1 ni que sea continua:
     * en BRYGAR, FE1 no existe (la resolución empezó más arriba) y hay 72
     * huecos en el medio. Por eso primero se busca CUALQUIER factura viva con
     * una escalera de potencias de 2, y desde ahí se sube; y un fallo suelto no
     * se toma como el final hasta confirmar que los siguientes también faltan.
     */
    private function detectarUltimo(int $desde): int
    {
        $this->line('Detectando el último consecutivo emitido…');

        $ancla = 0;
        for ($n = max($desde, 1); $n <= 65536; $n *= 2) {
            if ($this->consultar($n)['estado'] === 'ok') {
                $ancla = $n;
            }
        }

        if ($ancla === 0) {
            $this->warn("  no se encontró ninguna factura viva desde {$this->cfg->prefijo}{$desde}.");

            return 0;
        }

        $paso = 64;
        $ultimo = $ancla;

        while ($paso >= 1) {
            if ($this->hayVidaCerca($ultimo + $paso)) {
                $ultimo += $paso;

                continue;
            }
            $paso = intdiv($paso, 2);
        }

        while ($this->consultar($ultimo + 1)['estado'] === 'ok') {
            $ultimo++;
        }

        $this->line("  último emitido: {$this->cfg->prefijo}{$ultimo}");

        return $ultimo;
    }

    /** ¿Existe algo en {n .. n+4}? Evita que un hueco corte la búsqueda. */
    private function hayVidaCerca(int $n): bool
    {
        for ($i = $n; $i < $n + 5; $i++) {
            if ($this->consultar($i)['estado'] === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * Trae una factura de Dataico.
     *
     * Devuelve `['estado' => 'ok'|'ausente'|'error', 'factura' => …]`.
     *
     * La distinción es lo más importante de este comando. Dataico responde
     * **HTTP 429** cuando se le piden varias facturas en el mismo segundo, y
     * confundir ese 429 con "esa factura no existe" es exactamente el error que
     * dejaría facturas ya emitidas sin marcar en Brynex — y por lo tanto
     * elegibles para volver a emitirse ante la DIAN. Un 404 es ausencia; todo
     * lo demás es un fallo que hay que reintentar o reportar, nunca tragarse.
     */
    private function consultar(int $n): array
    {
        if (isset($this->cache[$n])) {
            return $this->cache[$n];
        }

        $r = $this->consultarRemoto($n);

        // Solo se cachea lo concluyente. Un 'error' es transitorio: guardarlo
        // congelaría el falso negativo en el archivo.
        if ($this->rutaCache && $r['estado'] !== 'error') {
            $this->cache[$n] = $r;
        }

        return $r;
    }

    private function consultarRemoto(int $n): array
    {
        $numero = $this->cfg->prefijo.$n;
        $url = rtrim(config('dataico.base_url'), '/').config('dataico.endpoints.consultar_factura');
        $espera = self::ESPERA_MS;

        for ($intento = 1; $intento <= self::MAX_INTENTOS; $intento++) {
            usleep($espera * 1000);

            try {
                $r = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Auth-token' => $this->cfg->auth_token,
                ])
                    ->timeout(config('dataico.timeout'))
                    ->get($url, [
                        'dataico_account_id' => $this->cfg->dataico_account_id,
                        'number' => $numero,
                    ]);
            } catch (\Throwable $e) {
                $espera *= 2;

                continue;
            }

            if ($r->successful()) {
                $i = $r->json()['invoice'] ?? null;

                return is_array($i)
                    ? ['estado' => 'ok', 'factura' => $this->mapear($numero, $i)]
                    : ['estado' => 'ausente'];
            }

            if ($r->status() === 404) {
                return ['estado' => 'ausente'];
            }

            $espera *= 2;
        }

        return ['estado' => 'error'];
    }

    private function mapear(string $numero, array $i): array
    {
        $cliente = $i['customer'] ?? [];
        $nombre = $cliente['company_name']
            ?? trim(($cliente['first_name'] ?? '').' '.($cliente['family_name'] ?? ''));

        return [
            'numero' => $i['number'] ?? $numero,
            'cufe' => $i['cufe'] ?? null,
            'uuid' => $i['uuid'] ?? null,
            'identificacion' => preg_replace('/\D+/', '', (string) ($cliente['party_identification'] ?? '')),
            'nombre' => $nombre,
            'precio' => (float) collect($i['items'] ?? [])->sum(
                fn ($it) => (float) ($it['price'] ?? 0) * (float) ($it['quantity'] ?? 1)
            ),
            // Dataico devuelve "15/08/2026 13:31:57".
            'fecha' => isset($i['issue_date'])
                ? $this->aIso(substr((string) $i['issue_date'], 0, 10))
                : null,
        ];
    }

    private function aIso(string $ddmmaaaa): ?string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $ddmmaaaa, $m)
            ? "{$m[3]}-{$m[2]}-{$m[1]}"
            : null;
    }

    // ─── Cruce, todo en memoria ──────────────────────────────────────────

    /**
     * Documento + valor exacto, la más ANTIGUA que quede libre, y nunca una
     * factura posterior a la emisión.
     *
     * Se elige la más antigua porque así se cargaron: los consecutivos de
     * Dataico van en orden de subida, y al recorrerlos se ve el patrón —
     * FE357-456 concentra enero, FE557-656 febrero, FE757-856 marzo—. Es una
     * carga mes a mes, de lo más viejo a lo más nuevo.
     *
     * Antes se desempataba por la fecha más cercana a la emisión, y eso repartía
     * mal: con una carga masiva del 31 de mayo, «lo más cercano» tiraba todo
     * hacia mayo en vez de respetar el orden en que se subió.
     *
     * Esa segunda condición no es un refinamiento, es lo que separa un cruce
     * real de uno inventado. Sin ella, la primera corrida ató 189 facturas de
     * agosto a facturas electrónicas emitidas el 15 de agosto o el 31 de mayo,
     * ANTES de que esos pagos existieran. El valor $46.000 se repite cientos
     * de veces, así que documento + valor solos cruzan casi con cualquiera.
     * El daño va en la dirección peligrosa: una factura marcada como ya
     * emitida sin estarlo no se emite nunca.
     *
     * Se permiten 2 días de holgura porque la emisión puede quedar registrada
     * con la fecha del día anterior al cierre.
     */
    private function cruzar(array $f, array $usados): ?int
    {
        if ($f['identificacion'] === '' || $f['precio'] <= 0) {
            return null;
        }

        $candidatas = $this->indice[$f['identificacion'].'|'.(int) $f['precio']] ?? [];
        $limite = $f['fecha'] !== null ? strtotime($f['fecha'].' +2 days') : null;

        $libres = array_values(array_filter(
            $candidatas,
            fn ($c) => ! isset($usados[$c['numero']])
                && ($limite === null || strtotime($c['fecha']) <= $limite)
        ));

        if (empty($libres)) {
            return null;
        }

        if (count($libres) === 1) {
            return $libres[0]['numero'];
        }

        usort($libres, fn ($a, $b) => strtotime($a['fecha']) <=> strtotime($b['fecha']));

        return $libres[0]['numero'];
    }

    // ─── Fase 3: escritura ───────────────────────────────────────────────

    /** @param  array<int,array>  $cruces */
    private function escribir(int $aliadoId, array $cruces): void
    {
        $barra = $this->output->createProgressBar(count($cruces));
        $barra->start();

        foreach (array_chunk($cruces, 50, true) as $tanda) {
            DB::transaction(function () use ($aliadoId, $tanda, $barra) {
                foreach ($tanda as $numeroBrynex => $f) {
                    DataicoEnvio::updateOrCreate(
                        ['aliado_id' => $aliadoId, 'numero_factura' => $numeroBrynex],
                        [
                            'razon_social_id' => $this->cfg->razon_social_id,
                            'estado' => DataicoEnvio::ESTADO_ENVIADO,
                            'base_admon' => $f['precio'],
                            'cliente_identificacion' => $f['identificacion'],
                            'cliente_nombre' => mb_substr($f['nombre'], 0, 250),
                            'dataico_numero' => $f['numero'],
                            'dataico_uuid' => $f['uuid'],
                            'cufe' => $f['cufe'],
                            'enviado_at' => $f['fecha'],
                            'error_mensaje' => 'Conciliada: emitida antes por el Excel manual.',
                        ]
                    );

                    DB::table('facturas')
                        ->where('aliado_id', $aliadoId)
                        ->where('numero_factura', $numeroBrynex)
                        ->whereNull('deleted_at')
                        ->update(['fe_marcada' => 1, 'fe_marcada_at' => now()]);

                    $barra->advance();
                }
            });
        }

        $barra->finish();
        $this->newLine();
    }
}
