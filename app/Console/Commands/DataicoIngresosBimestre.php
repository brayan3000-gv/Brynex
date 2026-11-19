<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        {--comparar= : total del informe de Dataico para el bimestre, para ver la diferencia}';

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

        $nombre = $this->nombreRazonSocial($rs);
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

        $this->reportarHuecos($rs, $facturas->pluck('dataico_numero'));

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
