<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Equivalencia de ASESOR COMERCIAL (nivel 1, ocupación 3414 en BryNex) en el
 * catálogo de cargos de Nueva EPS: el 631102, tal como lo lista
 * `ReInsertRegister/loadPositionTypes` del portal. Sin ella el reingreso de
 * Global Contact (26-oct-2026) se bloqueaba en el precheck.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('eps_ocupaciones')->where('entidad', 'nueva_eps')->where('codigo_ocupacion', '3414')->exists()) {
            return;
        }

        DB::table('eps_ocupaciones')->insert([
            'entidad'             => 'nueva_eps',
            'codigo_ocupacion'    => '3414',
            'codigo_entidad'      => '631102',
            'descripcion_entidad' => 'ASESOR COMERCIAL',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('eps_ocupaciones')->where('entidad', 'nueva_eps')->where('codigo_ocupacion', '3414')->delete();
    }
};
