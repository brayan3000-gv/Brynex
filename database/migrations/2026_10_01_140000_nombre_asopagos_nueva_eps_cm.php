<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NUEVA EPS CM (EPS041) quedó unificada en NUEVA EPS (EPS037) y su nombre de
 * Asopagos no está en ningún catálogo que tengamos. Si se vuelve a sacar un
 * plano viejo suyo, sale con el nombre de Nueva EPS, que es con el que pasan
 * las planillas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nombre = DB::table('eps')->where('codigo', 'EPS037')->orderBy('id')->value('nombre_asopagos');
        if ($nombre) {
            DB::table('eps')->where('codigo', 'EPS041')->update(['nombre_asopagos' => $nombre]);
        }
    }

    public function down(): void
    {
        DB::table('eps')->where('codigo', 'EPS041')
            ->update(['nombre_asopagos' => 'EPS037_NUEVA EMPRESA PROMOTORA DE SALUD S.A. - 900156264']);
    }
};
