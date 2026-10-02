<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿El aliado que recibe una razón social prestada ve sus claves de portales?
 *
 * Solo cuenta en las copias (`origen_id` no nulo). Por defecto no: a Fecop y a
 * Luis Lopez las afiliaciones se las hace BryNex, que las ve igual. Se cambia
 * desde «Habilitar en aliado». Ver ClaveAcceso::visiblesPara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('razones_sociales', function (Blueprint $table) {
            $table->boolean('ve_claves')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('razones_sociales', function (Blueprint $table) {
            $table->dropColumn('ve_claves');
        });
    }
};
