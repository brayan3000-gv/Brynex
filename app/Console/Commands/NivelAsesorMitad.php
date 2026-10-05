<?php

namespace App\Console\Commands;

use App\Models\AsesorNivel;
use App\Models\AsesorNivelTarifa;
use App\Services\TarifaAsesorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o recalcula) un nivel de asesor cuya matriz de afiliación es la regla del
 * Plan Asesor: el asesor gana la mitad del precio público, pero al aliado le queda
 * como mínimo un valor fijo por afiliación para asumir el retiro.
 *
 *   asesor = min( público / 2,  público − mínimo del aliado,  público − retiro − otros )
 *
 * El último tope evita prometer más de lo que el plan deja repartir (hay celdas
 * donde todo el precio es retiro). Sin --aplicar solo muestra la matriz.
 *
 *   php artisan brynex:nivel-asesor-mitad 2
 *   php artisan brynex:nivel-asesor-mitad 2 --aplicar
 */
class NivelAsesorMitad extends Command
{
    protected $signature = 'brynex:nivel-asesor-mitad
        {aliado : Id del aliado}
        {--nombre=Plan Asesor (mitad) : Nombre del nivel}
        {--minimo-aliado=60000 : Lo mínimo que le queda al aliado por afiliación}
        {--admon=23000 : Administración mensual del asesor que trae el nivel (pesos fijos)}
        {--aplicar : Crea o actualiza el nivel. Sin esto solo muestra la matriz}';

    protected $description = 'Nivel de asesor con la mitad de la afiliación y un mínimo para el aliado';

    public function handle(): int
    {
        $aliadoId = (int) $this->argument('aliado');
        $minimo = (int) $this->option('minimo-aliado');
        $nombre = trim((string) $this->option('nombre'));

        $nombres = [];
        foreach (TarifaAsesorService::combinaciones() as $combo) {
            $nombres[(int) $combo['plan']->id.'_'.(int) $combo['modalidad']->id] = $combo['plan']->nombre.' · '.$combo['modalidad']->nombre;
        }

        $celdas = [];
        $muestra = [];
        foreach (TarifaAsesorService::baseTarifario($aliadoId) as $clave => $c) {
            [$plan, $modalidad, $riesgo] = array_map('intval', explode('_', $clave));
            $publico = (int) $c['publico'];
            $asesor = max(0, min(intdiv($publico, 2), $publico - $minimo, $publico - (int) $c['retiro'] - (int) $c['otros']));
            $asesor = intdiv($asesor, 100) * 100;
            $celdas[] = ['plan_id' => $plan, 'tipo_modalidad_id' => $modalidad, 'nivel_arl' => $riesgo, 'afil_asesor' => $asesor];
            if ($publico > 0) {
                $muestra[] = [
                    $nombres["{$plan}_{$modalidad}"] ?? "plan $plan · modalidad $modalidad",
                    $riesgo,
                    number_format($publico, 0, ',', '.'),
                    number_format($asesor, 0, ',', '.'),
                    number_format($publico - $asesor, 0, ',', '.'),
                    match (true) {
                        $asesor * 2 >= $publico - 100 => 'mitad',
                        // El plan deja repartir menos que la mitad: el retiro y los «otros» pesan más
                        $publico - (int) $c['retiro'] - (int) $c['otros'] < min(intdiv($publico, 2), $publico - $minimo) => $asesor > 0 ? 'lo que deja el retiro' : 'el retiro se lleva todo',
                        $asesor > 0 => 'tope del mínimo',
                        default => 'no alcanza',
                    },
                ];
            }
        }

        $this->line("Aliado $aliadoId · nivel «{$nombre}» · mínimo para el aliado $".number_format($minimo, 0, ',', '.'));
        $this->table(['Plan · modalidad', 'Riesgo', 'Público', 'Asesor', 'Queda al aliado', 'Regla'], $muestra);
        $this->line(count($celdas).' celdas en total.');

        if (! $this->option('aplicar')) {
            $this->info('Modo de prueba: no se cambió nada. Agregue --aplicar para crear el nivel.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($aliadoId, $nombre, $minimo, $celdas) {
            $nivel = AsesorNivel::where('aliado_id', $aliadoId)->where('nombre', $nombre)->first()
                ?: AsesorNivel::create([
                    'aliado_id' => $aliadoId,
                    'nombre' => $nombre,
                    'orden' => (int) AsesorNivel::where('aliado_id', $aliadoId)->max('orden') + 1,
                    // Rango inalcanzable: este nivel se asigna a mano, no por tamaño de cartera.
                    'contratos_min' => 999999,
                    'contratos_max' => null,
                    'activo' => true,
                ]);
            $nivel->update([
                'descripcion' => 'Afiliación: la mitad para el asesor, con mínimo de $'.number_format($minimo, 0, ',', '.').' para el aliado. La administración se ajusta a mano según la cartera.',
                'admon_asesor' => (float) $this->option('admon'),
            ]);

            AsesorNivelTarifa::where('asesor_nivel_id', $nivel->id)->delete();
            $ahora = now();
            foreach (array_chunk($celdas, 200) as $bloque) {
                AsesorNivelTarifa::insert(array_map(fn ($c) => $c + ['asesor_nivel_id' => $nivel->id, 'created_at' => $ahora, 'updated_at' => $ahora], $bloque));
            }
            $this->info("Listo: nivel #{$nivel->id} «{$nivel->nombre}» con ".count($celdas).' celdas. Asígnelo desde la ficha del asesor.');
        });

        return self::SUCCESS;
    }
}
