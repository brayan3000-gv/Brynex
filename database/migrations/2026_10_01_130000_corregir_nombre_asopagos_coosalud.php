<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El nombre de Asopagos de Coosalud estaba cruzado: la de contributivo (EPS042)
 * salía en el Excel como «ESSC24_EPS-S COOSALUD» y la de movilidad (ESSC24)
 * como «EPS042_COOSALUD». Cada una queda con el de su código.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('eps')->where('codigo', 'EPS042')->update(['nombre_asopagos' => 'EPS042_COOSALUD']);
        DB::table('eps')->where('codigo', 'ESSC24')->update(['nombre_asopagos' => 'ESSC24_EPS-S COOSALUD']);
    }

    public function down(): void
    {
        DB::table('eps')->where('codigo', 'EPS042')->update(['nombre_asopagos' => 'ESSC24_EPS-S COOSALUD']);
        DB::table('eps')->where('codigo', 'ESSC24')->update(['nombre_asopagos' => 'EPS042_COOSALUD']);
    }
};
