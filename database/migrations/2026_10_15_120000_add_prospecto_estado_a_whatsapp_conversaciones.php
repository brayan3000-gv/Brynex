<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento a mano de los prospectos aliados (asesores y empresas): en qué quedó
 * cada uno según quien lo atiende. Lo marca una persona desde «🤝 Aliados»; la IA no
 * lo toca. Sin valor = pendiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            // pendiente | conversacion | no_interesado | software | plan
            $table->string('prospecto_estado', 20)->nullable()->after('perfil_avisado_a');
            $table->string('prospecto_nota', 500)->nullable()->after('prospecto_estado');
            $table->dateTime('prospecto_estado_at')->nullable()->after('prospecto_nota');
            $table->integer('prospecto_estado_por')->nullable()->after('prospecto_estado_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dropColumn(['prospecto_estado', 'prospecto_nota', 'prospecto_estado_at', 'prospecto_estado_por']);
        });
    }
};
