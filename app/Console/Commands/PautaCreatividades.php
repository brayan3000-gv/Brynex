<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\PautaConfig;
use App\Models\Publicacion;
use App\Services\RedesSociales\MetaAdsService;
use Illuminate\Console\Command;

/**
 * Mantiene el conjunto permanente de pauta: mete la pieza publicada más reciente como
 * creatividad nueva (si queda cupo semanal) y pausa las que ya no compiten.
 *
 * No enciende gasto: si el conjunto está en pausa, las creatividades entran en pausa. El
 * único acto que abre la llave es activar el conjunto, y eso es manual a propósito.
 */
class PautaCreatividades extends Command
{
    protected $signature = 'marketing:pauta-creatividades {--aliado= : Slug del aliado (por defecto, todos los que tengan pauta activa)}';

    protected $description = 'Agrega la pieza del día al conjunto permanente de pauta y rota las creatividades';

    /**
     * Antigüedad máxima de una pieza para estrenarla en pauta.
     *
     * Sin este tope el comando se come el histórico: cuando el piloto dejó de generar piezas
     * (bandeja llena desde el 8-sep-2026), se quedó sin candidatas nuevas y empezó a caminar
     * hacia atrás estrenando creatividades de agosto que ya se habían probado — #83, #82, #78,
     * #74, #73, #72, #69, #63, una por día. Dos de ellas gastaron $2.548 sin una sola
     * conversación. Si no hay pieza fresca, lo correcto es no pautar nada y que se note en el
     * log, no reciclar lo que ya no funcionó.
     */
    private const DIAS_FRESCURA = 15;

    public function handle(): int
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

            $conjunto = MetaAdsService::asegurarConjuntoPermanente($config, $aliado->id);
            if (! $conjunto['ok']) {
                $this->error("   {$conjunto['mensaje']}");

                continue;
            }
            $this->line("   {$conjunto['mensaje']}");

            // Candidata: la pieza publicada más reciente que todavía no esté pautada. Solo
            // se pauta lo que ya salió en orgánico — si una pieza no era digna de publicarse,
            // menos de pagarla. Y solo si es reciente: ver DIAS_FRESCURA.
            $candidata = Publicacion::where('aliado_id', $aliado->id)
                ->whereNotNull('publicada_at')
                ->where('publicada_at', '>=', now()->subDays(self::DIAS_FRESCURA))
                ->whereNull('meta_ad_id')
                ->where('pauta_excluida', false)
                ->orderByDesc('publicada_at')
                ->first();

            if (! $candidata) {
                $this->line('   Sin piezas nuevas para pautar.');
            } else {
                $r = MetaAdsService::agregarPieza($candidata);
                $this->line('   '.($r['ok'] ? "✅ {$r['mensaje']}" : "⏭  {$r['mensaje']}"));
            }

            $rotacion = MetaAdsService::rotarCreatividades($config->fresh(), $aliado->id);
            $this->line("   {$rotacion['mensaje']}");
        }

        return self::SUCCESS;
    }
}
