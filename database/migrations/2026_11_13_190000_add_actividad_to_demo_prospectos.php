<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué hizo cada prospecto en la demo: pasos del recorrido, módulos que abrió y
 * si vio la presentación (JSON). Lo manda la demo por el relevo; sirve para
 * saber a quién llamar primero y de qué hablarle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_prospectos', function (Blueprint $table) {
            $table->text('actividad')->nullable()->after('ultimo_uso_at');
        });
    }

    public function down(): void
    {
        Schema::table('demo_prospectos', function (Blueprint $table) {
            $table->dropColumn('actividad');
        });
    }
};
