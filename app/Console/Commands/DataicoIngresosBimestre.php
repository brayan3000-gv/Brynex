<?php

namespace App\Console\Commands;

use App\Models\RazonSocialCredencial;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Ingresos facturados de un bimestre, para el anticipo del SIMPLE (2593).
 *
 * Sale de lo que Brynex ya guardó al emitir, sin consultar Dataico:
 * `dataico_envios.base_admon` es lo que se facturó (administración +
 * afiliación; la seguridad social nunca va en la FE) y `enviado_at` es la
 * fecha de la factura, porque la DIAN solo acepta FE fechadas el día en que
 * se firman (regla FAD09e, ver PayloadBuilder). Las cargas en tanda del
 * 31-may y del 15-ago-2026 también se emitieron esos días: así lo dice Dataico.
 *
 * A eso se le restan las notas crédito enviadas en el mismo bimestre.
 *
 * Lo que este comando no puede saber son las FE que nunca pasaron por Brynex
 * (las de abril de 2026, FE198, FE1153, FE1166, FE1181, FE1182). Por eso el
 * total se compara contra el informe de facturas de Dataico (`--comparar`):
 * si no cuadra, la diferencia está en los números que el reporte marca como
 * huecos de la numeración.
 */
class DataicoIngresosBimestre extends Command
{
    protected $signature = 'dataico:ingresos-bimestre
        {anio : año gravable, p. ej. 2026}
        {bimestre : 1 = ene-feb … 6 = nov-dic}
        {--razon-social=42 : razones_sociales.id del emisor (42 = BRYGAR)}
        {--ficha= : brynex_razones_sociales.id de una empresa que factura directo en Dataico (p. ej. 12 = JUAMVI)}
        {--comparar= : total del informe de Dataico para el bimestre, para ver la diferencia}';

    /** Consecutivos seguidos sin respuesta para dar por terminada la numeración. */
    private const FIN_NUMERACION = 15;

    protected $description = 'Suma lo facturado en Dataico en un bimestre (menos notas crédito) desde lo guardado en Brynex';

    public function handle(): int
    {
        $anio = (int) $this->argument('anio');
        $bimestre = (int) $this->argument('bimestre');
        $rs = (int) $this->option('razon-social');

        if ($bimestre < 1 || $bimestre > 6) {
            $this->error('El bimestre va de 1 a 6.');

            return self::FAILURE;
        }

        $desde = Carbon::create($anio, $bimestre * 2 - 1, 1)->startOfDay();
        $hasta = $desde->copy()->addMonths(2);

        $ficha = $this->option('ficha') ? (int) $this->option('ficha') : null;

        if ($ficha) {
            $cache = $this->facturasDesdeDataico($ficha);
            if ($cache === null) {
                return self::FAILURE;
            }
            $facturas = collect($cache['facturas'])
                ->filter(fn ($f) => $f['estado'] !== 'DIAN_RECHAZADO')
                ->filter(fn ($f) => $f['fecha'] >= $desde->toDateString() && $f['fecha'] < $hasta->toDateString())
                ->map(fn ($f, $n) => (object) ['dataico_numero' => $n, 'base_admon' => $f['total'], 'enviado_at' => $f['fecha']])
                ->values();
            $notas = collect();
        } else {
            $facturas = DB::table('dataico_envios')
                ->where('razon_social_id', $rs)
                ->where('estado', 'enviado')
                ->where('enviado_at', '>=', $desde)
                ->where('enviado_at', '<', $hasta)
                ->get(['dataico_numero', 'base_admon', 'enviado_at']);

            $notas = DB::table('dataico_notas_credito as nc')
                ->join('dataico_envios as e', 'e.id', '=', 'nc.dataico_envio_id')
                ->where('e.razon_social_id', $rs)
                ->where('nc.estado', 'enviado')
                ->where('nc.enviado_at', '>=', $desde)
                ->where('nc.enviado_at', '<', $hasta)
                ->get(['nc.numero', 'nc.valor', 'nc.enviado_at', 'nc.factura_dataico_numero']);
        }

        $meses = [];
        foreach ([$desde->month, $desde->month + 1] as $m) {
            $meses[$m] = ['facturas' => 0, 'facturado' => 0.0, 'notas' => 0, 'anulado' => 0.0];
        }
        foreach ($facturas as $f) {
            $m = Carbon::parse($f->enviado_at)->month;
            $meses[$m]['facturas']++;
            $meses[$m]['facturado'] += (float) $f->base_admon;
        }
        foreach ($notas as $n) {
            $m = Carbon::parse($n->enviado_at)->month;
            $meses[$m]['notas']++;
            $meses[$m]['anulado'] += (float) $n->valor;
        }

        $nombre = $ficha ? $this->nombreFicha($ficha) : $this->nombreRazonSocial($rs);
        $this->info("{$nombre} — {$anio}, bimestre {$bimestre} ({$desde->format('d-m-Y')} a {$hasta->copy()->subDay()->format('d-m-Y')})");

        $filas = [];
        $total = ['facturas' => 0, 'facturado' => 0.0, 'notas' => 0, 'anulado' => 0.0];
        foreach ($meses as $m => $v) {
            $filas[] = [
                Carbon::create($anio, $m, 1)->locale('es')->translatedFormat('F'),
                $v['facturas'],
                $this->pesos($v['facturado']),
                $v['notas'],
                $this->pesos($v['anulado']),
                $this->pesos($v['facturado'] - $v['anulado']),
            ];
            foreach ($total as $k => $_) {
                $total[$k] += $v[$k];
            }
        }
        $neto = $total['facturado'] - $total['anulado'];
        $filas[] = ['TOTAL', $total['facturas'], $this->pesos($total['facturado']), $total['notas'], $this->pesos($total['anulado']), $this->pesos($neto)];
        $this->table(['Mes', 'FE', 'Facturado', 'NC', 'Anulado', 'Neto'], $filas);

        $this->line('Ingresos para el 2593: '.$this->pesos($neto).' (casilla del grupo que corresponda; Brygar va en la 28).');

        if ($ficha) {
            $rechazadas = collect($cache['facturas'])
                ->filter(fn ($f, $n) => $f['estado'] === 'DIAN_RECHAZADO'
                    && $f['fecha'] >= $desde->toDateString() && $f['fecha'] < $hasta->toDateString())
                ->keys();
            if ($rechazadas->isNotEmpty()) {
                $this->warn('Rechazadas por la DIAN (no cuentan): '.$rechazadas->implode(', '));
            }
            if ($cache['faltantes']) {
                $this->warn('Números sin factura en Dataico: '.implode(', ', $cache['faltantes']));
            }
            $this->line('Notas crédito: este modo no las ve (el API de Dataico no las lista); revisarlas en el informe.');
        } else {
            $this->reportarHuecos($rs, $facturas->pluck('dataico_numero'));
        }

        if (($comparar = $this->option('comparar')) !== null) {
            $informe = (float) preg_replace('/[^\d.-]/', '', str_replace(',', '', (string) $comparar));
            $dif = $informe - $total['facturado'];
            $this->newLine();
            $this->line('Informe de Dataico: '.$this->pesos($informe).' · Brynex (antes de notas): '.$this->pesos($total['facturado']));
            if (abs($dif) < 1) {
                $this->info('Cuadra.');
            } else {
                $this->warn('Diferencia: '.$this->pesos($dif).'. Buscarla en los huecos de la numeración de arriba.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Números de la numeración que caen dentro del rango del bimestre y que
     * Brynex no tiene en ningún mes: son FE emitidas por fuera (o perdidas)
     * y la causa más probable de una diferencia con Dataico.
     */
    private function reportarHuecos(int $rs, $numeros): void
    {
        $nums = collect($numeros)
            ->map(fn ($n) => preg_match('/(\d+)$/', (string) $n, $m) ? (int) $m[1] : null)
            ->filter()
            ->sort()
            ->values();

        if ($nums->isEmpty()) {
            return;
        }

        $todos = DB::table('dataico_envios')
            ->where('razon_social_id', $rs)
            ->whereIn('estado', ['enviado', 'enviando'])
            ->pluck('dataico_numero')
            ->map(fn ($n) => preg_match('/(\d+)$/', (string) $n, $m) ? (int) $m[1] : null)
            ->filter()
            ->flip();

        $huecos = [];
        for ($i = $nums->first(); $i <= $nums->last(); $i++) {
            if (! isset($todos[$i])) {
                $huecos[] = 'FE'.$i;
            }
        }

        $this->line("Numeración del bimestre en Brynex: FE{$nums->first()} a FE{$nums->last()}.");
        if ($huecos) {
            $this->warn('Huecos (no están en Brynex, revisarlos en Dataico): '.implode(', ', $huecos));
        }
    }

    /**
     * Facturas de una empresa que emite directo en Dataico (sin pasar por
     * Brynex), tomadas del API con el token de su ficha: pestaña Claves,
     * entidad «Dataico (API)», token en la contraseña.
     *
     * Se guardan en storage/app/dataico/{nit}.json (número → fecha, total
     * antes de impuestos, estado DIAN) y cada corrida solo consulta lo que
     * venga después de la última guardada. Así Dataico se consulta una vez
     * por factura y no en cada bimestre.
     *
     * @return array{facturas: array<string, array{fecha: string, total: float, estado: string}>, faltantes: string[]}|null
     */
    private function facturasDesdeDataico(int $ficha): ?array
    {
        $rs = DB::table('brynex_razones_sociales')->where('id', $ficha)->first(['nit', 'razon_social']);
        $cred = RazonSocialCredencial::where('ficha_id', $ficha)
            ->where('entidad', 'like', 'Dataico%')
            ->where('activo', true)
            ->latest('id')
            ->first();

        if (! $rs || ! $cred || ! $cred->contrasena) {
            $this->error("La ficha {$ficha} no tiene la clave «Dataico (API)» con el token en la contraseña.");

            return null;
        }

        $ruta = "dataico/{$rs->nit}.json";
        $disco = Storage::disk('local');
        $cache = $disco->exists($ruta)
            ? json_decode($disco->get($ruta), true)
            : ['prefijo' => 'FE', 'ultimo' => 0, 'facturas' => [], 'faltantes' => []];

        $url = rtrim(config('dataico.base_url'), '/').config('dataico.endpoints.consultar_factura');
        $prefijo = $cache['prefijo'];
        $sinRespuesta = 0;
        $nuevas = 0;
        $pendientes = [];

        for ($i = $cache['ultimo'] + 1; $sinRespuesta < self::FIN_NUMERACION; $i++) {
            usleep(350_000); // Dataico responde 429 a las ráfagas.
            $r = Http::withHeaders(['Auth-token' => $cred->contrasena])
                ->timeout(30)
                // Solo 429/5xx: un 404 es un número que no existe y no se reintenta.
                ->retry(3, 1500, fn ($e) => $e instanceof RequestException
                    && in_array($e->response->status(), [429, 500, 502, 503, 504], true), throw: false)
                ->get($url, ['number' => $prefijo.$i]);

            if ($r->status() === 404) {
                $sinRespuesta++;
                $pendientes[] = $prefijo.$i;

                continue;
            }
            if (! $r->successful()) {
                $this->error("Dataico respondió {$r->status()} en {$prefijo}{$i}; se guarda lo traído hasta aquí.");
                break;
            }

            $inv = $r->json('invoice') ?? [];
            $total = 0.0;
            foreach ($inv['items'] ?? [] as $it) {
                $bruto = (float) ($it['price'] ?? 0) * (float) ($it['quantity'] ?? 1);
                $total += $bruto * (1 - (float) ($it['discount_rate'] ?? 0) / 100);
            }

            // Lo que quedó sin respuesta antes de esta factura son huecos reales.
            array_push($cache['faltantes'], ...$pendientes);
            $pendientes = [];
            $sinRespuesta = 0;

            $cache['facturas'][$prefijo.$i] = [
                'fecha' => Carbon::createFromFormat('d/m/Y H:i:s', $inv['issue_date'])->toDateString(),
                'total' => round($total, 2),
                'estado' => (string) ($inv['dian_status'] ?? ''),
            ];
            $cache['ultimo'] = $i;
            $nuevas++;
        }

        $disco->put($ruta, json_encode($cache, JSON_PRETTY_PRINT));
        $this->line("Dataico: {$nuevas} factura(s) nueva(s) consultada(s); guardadas hasta {$prefijo}{$cache['ultimo']}.");

        return $cache;
    }

    private function nombreFicha(int $ficha): string
    {
        $r = DB::table('brynex_razones_sociales')->where('id', $ficha)->first(['razon_social', 'nit']);

        return $r ? "{$r->razon_social} ({$r->nit})" : "ficha {$ficha}";
    }

    private function nombreRazonSocial(int $rs): string
    {
        $r = DB::table('razones_sociales')->where('id', $rs)->first(['razon_social', 'nit']);

        return $r ? "{$r->razon_social} ({$r->nit})" : "razón social {$rs}";
    }

    private function pesos(float $v): string
    {
        return '$'.number_format($v, 0, ',', '.');
    }
}
