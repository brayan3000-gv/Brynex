<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CONDUCTOR (riesgo 4, ocupación 8321) son motorizados: en Nueva EPS va como
 * 8321 CONDUCTORES DE MOTOCICLETAS y no como conductor de camioneta (8323), que
 * fue lo que se cargó primero. Decisión del usuario, 26-oct-2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('eps_ocupaciones')
            ->where('entidad', 'nueva_eps')
            ->where('codigo_ocupacion', '8321')
            ->update([
                'codigo_entidad'      => '8321',
                'descripcion_entidad' => 'CONDUCTORES DE MOTOCICLETAS',
                'updated_at'          => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('eps_ocupaciones')
            ->where('entidad', 'nueva_eps')
            ->where('codigo_ocupacion', '8321')
            ->update([
                'codigo_entidad'      => '8323',
                'descripcion_entidad' => 'CONDUCTORES DE CAMIONETAS Y VEHÍCULOS LIVIANOS',
                'updated_at'          => now(),
            ]);
    }
};
