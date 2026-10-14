<?php

use App\Models\TipoModalidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipo E - X1: salud sin pensión en UNA sola planilla.
 *
 * Solo agrega filas de catálogo: una modalidad y sus tres planes, todos
 * existentes. No toca contratos, planos ni columnas.
 */
return new class extends Migration
{
    private const PLANES = ['SOLO_EPS', 'EPS_ARL', 'EPS_ARL_CCF'];

    public function up(): void
    {
        if (! DB::table('tipo_modalidad')->where('id', TipoModalidad::ID_EX1)->exists()) {
            DB::table('tipo_modalidad')->insert([
                'id' => TipoModalidad::ID_EX1,
                'tipo_modalidad' => 'EX1',
                'observacion' => 'Tipo E - X1',
                'modalidad' => 'Extraordinarias',
                'orden' => 21,
                'activo' => 1,
                'tipo_cot' => 1,
                'sub_tipo_cot' => null,
                'es_tiempo_parcial' => 0,
                'dias_arl' => null,
                'dias_afp' => null,
                'dias_caja' => null,
                'descripcion' => 'Tipo E - X1 — salud sin pensión en UNA sola planilla, '
                    .'apoyada en el subtipo de cotizante 03/04. Es para quien NO cumple '
                    .'requisitos de exención: el que ya está pensionado, el hombre de 55+, '
                    .'la mujer de 50+ o quien tiene documento CE/PT/PP/PE/PA va por Tipo E '
                    .'normal, que liquida en cualquier operador. Solo liquida por SIMPLE: '
                    .'Enlace consulta la base de la UGPP y la rechaza (eo.val.2.655). '
                    .'Tres planes: Solo EPS (la ARL va reportada a 30 días pero con tarifa '
                    .'cero, así que el trabajador NO queda cubierto ese mes), EPS+ARL y '
                    .'EPS+ARL+CCF. No se mezcla con gente normal en el mismo plano.',
            ]);
        }

        $planes = DB::table('planes_contrato')->whereIn('codigo', self::PLANES)->pluck('id');

        foreach ($planes as $planId) {
            $ya = DB::table('modalidad_planes')
                ->where('tipo_modalidad_id', TipoModalidad::ID_EX1)
                ->where('plan_id', $planId)
                ->exists();

            if (! $ya) {
                DB::table('modalidad_planes')->insert([
                    'tipo_modalidad_id' => TipoModalidad::ID_EX1,
                    'plan_id' => $planId,
                    // visible en el formulario, no reservado a la IA
                    'solo_ia' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        // no-borra-datos: quita únicamente las filas de catálogo que creó el up.
        DB::table('modalidad_planes')->where('tipo_modalidad_id', TipoModalidad::ID_EX1)->delete();
        DB::table('tipo_modalidad')->where('id', TipoModalidad::ID_EX1)->delete();
    }
};
