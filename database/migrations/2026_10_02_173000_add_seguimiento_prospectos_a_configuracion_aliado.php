<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento automático de prospectos, por aliado: aviso diario por WhatsApp
 * de las llamadas pendientes, paso a «sin respuesta» y cierre por abandono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->boolean('prospectos_recordatorio')->default(false);
            $table->string('prospectos_recordatorio_celular', 20)->nullable();
            $table->unsignedSmallInteger('prospectos_dias_sin_respuesta')->nullable();
            $table->unsignedSmallInteger('prospectos_dias_cierre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->dropColumn([
                'prospectos_recordatorio', 'prospectos_recordatorio_celular',
                'prospectos_dias_sin_respuesta', 'prospectos_dias_cierre',
            ]);
        });
    }
};
