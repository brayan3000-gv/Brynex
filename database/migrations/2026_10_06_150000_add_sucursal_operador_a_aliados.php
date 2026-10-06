<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sucursal con la que el aliado aparece ante el operador de planilla
 * (01 Brygar, 02 Fecop, 03 Luis López…). Es del aliado, no de la empresa:
 * la misma sucursal sirve para todas sus razones sociales, y los planos la
 * toman de aquí antes que de la razón social. Ver App\Services\SucursalOperador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aliados', function (Blueprint $table) {
            $table->string('codigo_sucursal', 10)->nullable();
            $table->string('nombre_sucursal', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aliados', function (Blueprint $table) {
            $table->dropColumn(['codigo_sucursal', 'nombre_sucursal']);
        });
    }
};
