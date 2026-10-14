<?php

use App\Models\TipoModalidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Separa el plan de tiempo parcial de Tipo E - X1 en su propia modalidad.
 *
 * Tenerlos juntos obligaba a decidir el tiempo parcial por el PLAN, porque
 * `es_tiempo_parcial` es una columna de la modalidad y una no puede ser las dos
 * cosas. Eso además dejaba la pantalla de planos sin forma de separarlos: filtra
 * por modalidad, no por plan, así que no se podían descargar aparte.
 *
 * Con dos modalidades, `-14` es un tiempo parcial normal —misma configuración de
 * días que TP(14)— y toda la maquinaria que ya existe lo entiende sin ayuda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $planTp = DB::table('planes_contrato')->where('codigo', 'ARL_CCF')->value('id');

        if (! DB::table('tipo_modalidad')->where('id', TipoModalidad::ID_EX1_TP)->exists()) {
            DB::table('tipo_modalidad')->insert([
                'id' => TipoModalidad::ID_EX1_TP,
                'tipo_modalidad' => 'EX1-TP14',
                'observacion' => 'Tipo E - X1 TP14',
                'modalidad' => 'Extraordinarias',
                'orden' => 22,
                'activo' => 1,
                'tipo_cot' => 51,
                'sub_tipo_cot' => null,
                // Tiempo parcial de verdad: mismos días que TP(14). El plan no
                // vende pensión, así que los 14 de AFP solo fijan el piso de
                // salario (medio mínimo); los días de pensión salen en cero
                // porque el contrato va sin AFP.
                'es_tiempo_parcial' => 1,
                'dias_arl' => 30,
                'dias_afp' => 14,
                'dias_caja' => 14,
                'descripcion' => 'Tipo E - X1 TP14 — ARL del mes completo + caja de 14 días, '
                    .'sin pensión y sin salud, para quien NO cumple requisitos de exención. '
                    .'Cotizante 51 con subtipo 03/04. Solo liquida por SIMPLE: Enlace consulta '
                    .'la base de la UGPP y la rechaza (eo.val.2.655). Es la variante de tiempo '
                    .'parcial de Tipo E - X1; no se mezcla con otras modalidades en un plano.',
            ]);
        }

        if ($planTp) {
            $ya = DB::table('modalidad_planes')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1_TP)
                ->where('plan_id', $planTp)->exists();

            if (! $ya) {
                DB::table('modalidad_planes')->insert([
                    'tipo_modalidad_id' => TipoModalidad::ID_EX1_TP,
                    'plan_id' => $planTp,
                    'solo_ia' => 0,
                ]);
            }

            // El plan de tiempo parcial sale de X1: allá quedan los tres de
            // dependiente. Y lo que ya se creó en X1 con ese plan se muda, o
            // quedaría en una modalidad que ya no lo ofrece.
            // no-borra-datos: quita una fila de catálogo que se reemplaza por la
            // de la modalidad nueva, sin tocar contratos ni planos.
            DB::table('modalidad_planes')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
                ->where('plan_id', $planTp)
                ->delete();

            DB::table('contratos')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
                ->where('plan_id', $planTp)
                ->update(['tipo_modalidad_id' => TipoModalidad::ID_EX1_TP]);

            DB::table('planos')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
                ->whereIn('contrato_id', DB::table('contratos')
                    ->where('tipo_modalidad_id', TipoModalidad::ID_EX1_TP)->pluck('id'))
                ->update(['tipo_modalidad_id' => TipoModalidad::ID_EX1_TP]);
        }
    }

    public function down(): void
    {
        // no-borra-datos: devuelve todo a Tipo E - X1, que es de donde salió.
        $planTp = DB::table('planes_contrato')->where('codigo', 'ARL_CCF')->value('id');

        DB::table('planos')->where('tipo_modalidad_id', TipoModalidad::ID_EX1_TP)
            ->update(['tipo_modalidad_id' => TipoModalidad::ID_EX1]);
        DB::table('contratos')->where('tipo_modalidad_id', TipoModalidad::ID_EX1_TP)
            ->update(['tipo_modalidad_id' => TipoModalidad::ID_EX1]);

        if ($planTp && ! DB::table('modalidad_planes')
            ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)->where('plan_id', $planTp)->exists()) {
            DB::table('modalidad_planes')->insert([
                'tipo_modalidad_id' => TipoModalidad::ID_EX1,
                'plan_id' => $planTp,
                'solo_ia' => 0,
            ]);
        }

        DB::table('modalidad_planes')->where('tipo_modalidad_id', TipoModalidad::ID_EX1_TP)->delete();
        DB::table('tipo_modalidad')->where('id', TipoModalidad::ID_EX1_TP)->delete();
    }
};
