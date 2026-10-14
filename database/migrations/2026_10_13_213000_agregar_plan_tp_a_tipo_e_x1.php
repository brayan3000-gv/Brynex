<?php

use App\Models\TipoModalidad;
use App\Services\PilaCotizanteEX1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suma el plan de tiempo parcial —ARL del mes completo + caja de 14 días, sin
 * pensión ni salud— a Tipo E - X1.
 *
 * El plan ya existe (`ARL_CCF`, el del Tiempo Parcial): solo se le abre esta
 * modalidad. Dentro de X1 va con el cotizante 51 y el subtipo 03/04, que es lo
 * que lo libera de la pensión — ver PilaCotizanteEX1::esTiempoParcial().
 */
return new class extends Migration
{
    public function up(): void
    {
        $planId = DB::table('planes_contrato')
            ->where('codigo', PilaCotizanteEX1::PLAN_ARL_CCF)
            ->value('id');

        if (! $planId) {
            return;
        }

        $ya = DB::table('modalidad_planes')
            ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
            ->where('plan_id', $planId)
            ->exists();

        if (! $ya) {
            DB::table('modalidad_planes')->insert([
                'tipo_modalidad_id' => TipoModalidad::ID_EX1,
                'plan_id' => $planId,
                'solo_ia' => 0,
            ]);
        }
    }

    public function down(): void
    {
        // no-borra-datos: quita solo la fila de catálogo que creó el up.
        $planId = DB::table('planes_contrato')
            ->where('codigo', PilaCotizanteEX1::PLAN_ARL_CCF)
            ->value('id');

        if ($planId) {
            DB::table('modalidad_planes')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
                ->where('plan_id', $planId)
                ->delete();
        }
    }
};
