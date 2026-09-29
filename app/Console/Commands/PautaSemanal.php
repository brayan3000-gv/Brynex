<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\PautaConfig;
use App\Models\Publicacion;
use App\Services\AlertaOperativaService;
use App\Services\RedesSociales\MetaAdsService;
use Illuminate\Console\Command;

/**
 * Corte de los lunes: mide cada creatividad de la semana, apaga las que no jalan y avisa.
 *
 * Nace de la revisión del 28-sep-2026, donde se vio que la plata se iba sola sin que nadie
 * mirara: $363.062 en total para una afiliación cerrada, con piezas como #86 ($17.781 y una
 * conversación) o #73 ($2.439 y ninguna) gastando semanas enteras porque no había un momento
 * fijo en el que alguien decidiera cortarlas.
 *
 * Se mide por conversaciones que pasaron del saludo: el anuncio manda el mensaje escrito de
 * antemano, así que contar conversaciones a secas premia a quien trae curiosos.
 *
 * Solo apaga gasto, nunca lo prende ni lo sube — eso sigue pidiendo el clic del usuario. Y nunca
 * apaga la mejor de un conjunto, para que una mala semana no deje la pauta en cero.
 *
 * Ejecución manual: php artisan marketing:pauta-semanal --no-enviar --no-pausar
 */
class PautaSemanal extends Command
{
    protected $signature = 'marketing:pauta-semanal
        {--aliado= : Slug del aliado (por defecto, todos los que tengan pauta activa)}
        {--dias=7 : Ventana de la medición}
        {--no-pausar : Solo medir, sin apagar nada}
        {--no-enviar : No mandar el aviso por WhatsApp}';

    protected $description = 'Mide las creatividades de la semana, pausa las que no jalan gente y avisa por WhatsApp';

    /** El tope de la plantilla de avisos es 600 caracteres. */
    private const MAX_CARACTERES = 560;

    public function handle(AlertaOperativaService $alertas): int
    {
        $aliados = $this->option('aliado')
            ? Aliado::where('slug', $this->option('aliado'))->get()
            : Aliado::whereIn('id', PautaConfig::where('activo', true)->pluck('aliado_id'))->get();

        if ($aliados->isEmpty()) {
            $this->warn('No hay aliados con pauta activa.');

            return self::SUCCESS;
        }

        foreach ($aliados as $aliado) {
            $this->line("── {$aliado->nombre}");
            $config = PautaConfig::paraAliado($aliado->id);
            $regla = MetaAdsService::reglaDePrueba();

            $dias = max(1, (int) $this->option('dias'));
            $desde = now()->subDays($dias);
            $entrega = MetaAdsService::entregaPorAnuncio($config, $desde->toDateString(), now()->toDateString());

            $apagadas = [];
            $resumen = [];

            foreach (MetaAdsService::conjuntos($config) as $etiqueta => $adsetId) {
                $piezas = Publicacion::where('aliado_id', $aliado->id)
                    ->where('meta_adset_id', $adsetId)
                    ->where('pauta_estado', 'activa')
                    ->whereNotNull('meta_ad_id')
                    ->get();

                if ($piezas->isEmpty()) {
                    continue;
                }

                $reales = MetaAdsService::conversacionesReales($piezas->pluck('id'), $desde);

                $filas = $piezas->map(function ($p) use ($entrega, $reales) {
                    $gasto = (float) ($entrega[$p->meta_ad_id]['gasto'] ?? 0);
                    $conversaciones = (int) ($reales[$p->id] ?? 0);

                    return [
                        'pieza' => $p,
                        'gasto' => $gasto,
                        'impresiones' => (int) ($entrega[$p->meta_ad_id]['impresiones'] ?? 0),
                        'conversaciones' => $conversaciones,
                        // Sin conversaciones el costo por lead es infinito, no cero: se ordena al final.
                        'costo' => $conversaciones > 0 ? $gasto / $conversaciones : INF,
                        'en_prueba' => MetaAdsService::enPrueba($p),
                    ];
                })->sortBy('costo')->values();

                $this->mostrar($etiqueta, $filas);

                $mejor = $filas->first();
                $referencia = $mejor && is_finite($mejor['costo']) ? $mejor['costo'] : null;

                foreach ($filas as $i => $f) {
                    // La mejor del conjunto nunca se apaga, ni siquiera si tuvo una mala semana:
                    // apagarla dejaría el conjunto entregando a nadie.
                    if ($i === 0 || $f['en_prueba']) {
                        continue;
                    }

                    $sinNada = $f['conversaciones'] === 0;
                    $muyCara = $referencia !== null && is_finite($f['costo'])
                        && $f['costo'] > $referencia * $regla['veces'];

                    if (! $sinNada && ! $muyCara) {
                        continue;
                    }

                    $motivo = $sinNada ? 'nadie escribió' : 'cuesta '.round($f['costo'] / max($referencia, 1)).'× la mejor';
                    $this->warn("   apagar #{$f['pieza']->id} — {$motivo}");

                    if (! $this->option('no-pausar') && MetaAdsService::pausarAnuncio($f['pieza'])) {
                        $apagadas[] = "#{$f['pieza']->id} ({$motivo})";
                    }
                }

                if ($mejor) {
                    $costo = is_finite($mejor['costo']) ? '$'.number_format($mejor['costo']) : 'sin leads';
                    $resumen[] = "{$etiqueta}: manda #{$mejor['pieza']->id} a {$costo}/lead";
                }
            }

            if (empty($resumen)) {
                $this->info('   Sin creatividades activas para medir.');

                continue;
            }

            $texto = $this->aviso($resumen, $apagadas, $dias);
            $this->line('   Aviso: '.$texto);

            if (! $this->option('no-enviar')) {
                foreach ($this->destinatarios() as $numero) {
                    $ok = $alertas->enviarA($numero, 'Corte de pauta', $texto);
                    $this->line($ok ? "   → enviado a {$numero}" : "   → no se pudo enviar a {$numero} (ver el log).");
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function destinatarios(): array
    {
        $crudos = explode(',', (string) config('services.whatsapp.pendientes_numeros'));

        return array_values(array_unique(array_filter(array_map(fn ($n) => preg_replace('/\D/', '', $n), $crudos))));
    }

    private function aviso(array $resumen, array $apagadas, int $dias): string
    {
        $texto = "Pauta de los últimos {$dias} días. ".implode('. ', $resumen).'. ';
        $texto .= $apagadas
            ? 'Apagadas: '.implode(', ', $apagadas).'.'
            : 'No se apagó nada: todas sostienen su costo.';

        return mb_substr($texto, 0, self::MAX_CARACTERES);
    }

    private function mostrar(string $etiqueta, $filas): void
    {
        $this->line("   ── {$etiqueta}");
        $this->table(
            ['Pieza', 'Gasto', 'Impr.', 'Leads', '$/lead', ''],
            $filas->map(fn ($f) => [
                '#'.$f['pieza']->id,
                '$'.number_format($f['gasto']),
                number_format($f['impresiones']),
                $f['conversaciones'],
                is_finite($f['costo']) ? '$'.number_format($f['costo']) : '—',
                $f['en_prueba'] ? 'en prueba' : '',
            ])->all()
        );
    }
}
