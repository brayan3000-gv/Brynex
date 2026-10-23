<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo contestó el cliente: si contestó y colgó antes de que el agente
 * anotara un resultado, no es «no contesta».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('llamadas_voz', 'contestada_en')) {
            return;
        }

        Schema::table('llamadas_voz', function (Blueprint $table) {
            $table->dateTime('contestada_en')->nullable()->after('terminada_en');
        });
    }

    public function down(): void
    {
        Schema::table('llamadas_voz', function (Blueprint $table) {
            $table->dropColumn('contestada_en');
        });
    }
};
