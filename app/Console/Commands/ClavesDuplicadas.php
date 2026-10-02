<?php

namespace App\Console\Commands;

use App\Models\ClaveAcceso;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Las claves que dos aliados tienen cargadas para la misma empresa y entidad.
 *
 * Ante la entidad la empresa es una sola, así que la clave debería ser una. Los
 * procesos automáticos la buscan por NIT y se quedan con la **actualizada más
 * recientemente**, sea de quien sea: si un aliado guarda una vieja después, los
 * trámites de los demás dejan de funcionar sin que nadie lo note.
 *
 * Este comando enseña esas parejas y, con `--dejar`, desactiva las copias y se
 * queda con la que se diga. No borra nada: solo pone `activo = 0`.
 *
 *   php artisan claves:duplicadas
 *   php artisan claves:duplicadas --dejar=47
 */
class ClavesDuplicadas extends Command
{
    protected $signature = 'claves:duplicadas
                            {--dejar= : Id de la clave que se queda; las otras de esa empresa+entidad se desactivan}
                            {--consolidar-iguales : Deja una sola donde todas las copias tienen la misma contraseña}
                            {--aliado= : Con --consolidar-iguales, de qué aliado se conserva la copia}';

    protected $description = 'Lista las claves repetidas de la misma empresa y entidad entre aliados';

    public function handle(): int
    {
        if ($id = $this->option('dejar')) {
            return $this->dejarSolo((int) $id);
        }

        if ($this->option('consolidar-iguales')) {
            return $this->consolidarIguales($this->option('aliado') ? (int) $this->option('aliado') : null);
        }

        $grupos = $this->grupos();

        if (! $grupos) {
            $this->info('No hay claves repetidas entre aliados.');

            return self::SUCCESS;
        }

        foreach ($grupos as $g) {
            $distintas = $g->claves->pluck('contrasena')->unique()->count() > 1;

            $this->line('');
            $this->line("<options=bold>{$g->nit} · {$g->tipo} {$g->entidad}</> "
                .($distintas ? '<fg=red>← contraseñas distintas</>' : '<fg=gray>(misma contraseña)</>'));

            foreach ($g->claves as $c) {
                // La que usan hoy los procesos es la de updated_at más reciente.
                $manda = $c->id === $g->claves->sortByDesc('updated_at')->first()->id;
                $this->line(sprintf('   %s #%-5d aliado %-18s usuario %-28s actualizada %s',
                    $manda ? '<fg=green>→</>' : ' ',
                    $c->id,
                    mb_substr($c->aliado?->nombre ?? '?', 0, 18),
                    mb_substr((string) $c->usuario, 0, 28),
                    $c->updated_at?->format('d/m/Y') ?? '—'));
            }
        }

        $this->line('');
        $this->line('La marcada con → es la que usan hoy los procesos.');
        $this->line('Para dejar una sola:  php artisan claves:duplicadas --dejar=<id>');

        return self::SUCCESS;
    }

    /**
     * Deja una sola copia donde todas dicen lo mismo.
     *
     * Solo toca los grupos cuya contraseña coincide: ahí no hay nada que
     * decidir, y quitar las repetidas evita que mañana alguien edite la copia
     * que no manda. Las que difieren se quedan como están: cuál vale lo sabe
     * una persona, no este comando.
     */
    private function consolidarIguales(?int $aliadoId): int
    {
        $hechos = 0;

        foreach ($this->grupos() as $g) {
            if ($g->claves->pluck('contrasena')->unique()->count() > 1) {
                $this->warn("   se salta {$g->nit} · {$g->tipo} {$g->entidad}: las contraseñas no coinciden.");

                continue;
            }

            $buena = ($aliadoId ? $g->claves->firstWhere('aliado_id', $aliadoId) : null)
                ?? $g->claves->sortByDesc('updated_at')->first();

            foreach ($g->claves->where('id', '<>', $buena->id) as $otra) {
                $otra->update(['activo' => false]);
            }

            $buena->touch();
            $hechos++;
            $this->line("   {$g->nit} · {$g->tipo} {$g->entidad}: queda la #{$buena->id} ({$buena->aliado?->nombre})");
        }

        $this->info("{$hechos} grupo(s) consolidado(s).");

        return self::SUCCESS;
    }

    private function dejarSolo(int $id): int
    {
        $buena = ClaveAcceso::find($id);

        if (! $buena) {
            $this->error("No existe la clave #{$id}.");

            return self::FAILURE;
        }

        $nit = $buena->razonSocial?->nit;

        if (! $nit) {
            $this->error("La clave #{$id} no cuelga de una razón social con NIT.");

            return self::FAILURE;
        }

        // Las clasificadas se comparan por la entidad del catálogo («ARL SURA» y
        // «SURA» son la misma); las demás, por el texto como antes.
        $otras = ClaveAcceso::where('id', '<>', $buena->id)
            ->when($buena->entidad_tipo && $buena->entidad_id,
                fn ($q) => $q->where('entidad_tipo', $buena->entidad_tipo)->where('entidad_id', $buena->entidad_id),
                fn ($q) => $q->where('tipo', $buena->tipo)->where('entidad', $buena->entidad))
            ->where('activo', true)
            ->whereHas('razonSocial', fn ($q) => $q->where('nit', $nit))
            ->get();

        if ($otras->isEmpty()) {
            $this->info('No había otras copias activas.');

            return self::SUCCESS;
        }

        foreach ($otras as $o) {
            $o->update(['activo' => false]);
            $this->line("   desactivada #{$o->id} (aliado {$o->aliado?->nombre})");
        }

        // Se toca para que sea la más reciente y no gane una desactivada por
        // fecha en algún sitio que mire updated_at sin filtrar por activo.
        $buena->touch();

        $this->info("Queda la #{$buena->id} para {$nit} · {$buena->tipo} {$buena->entidad}.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection */
    private function grupos()
    {
        $repetidas = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('c.activo', true)
            ->whereNotNull('rs.nit')->where('rs.nit', '<>', '')
            ->selectRaw('rs.nit, c.tipo, c.entidad')
            ->groupBy('rs.nit', 'c.tipo', 'c.entidad')
            ->havingRaw('count(distinct c.aliado_id) > 1')
            ->get();

        return $repetidas->map(function ($g) {
            $g->claves = ClaveAcceso::with(['aliado', 'razonSocial'])
                ->where('tipo', $g->tipo)
                ->where('entidad', $g->entidad)
                ->where('activo', true)
                ->whereHas('razonSocial', fn ($q) => $q->where('nit', $g->nit))
                ->get();

            return $g;
        })->filter(fn ($g) => $g->claves->count() > 1)->values();
    }
}
