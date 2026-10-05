<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departamento y municipio del aliado, escogidos de las listas como en la ficha
 * del cliente. `ciudad` se queda: se llena con el nombre del municipio al
 * guardar, porque la usan las cuentas de cobro y la página pública.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aliados', function (Blueprint $table) {
            $table->unsignedInteger('departamento_id')->nullable()->after('ciudad');
            $table->unsignedInteger('municipio_id')->nullable()->after('departamento_id');
        });
    }

    public function down(): void
    {
        Schema::table('aliados', function (Blueprint $table) {
            $table->dropColumn(['departamento_id', 'municipio_id']);
        });
    }
};
