<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prospectos que quieren trabajar con el aliado (asesores y empresas aliadas):
 * qué son, cuántas personas dijeron manejar y qué camino les sugirió la IA.
 * Lo escribe la herramienta perfilar_aliado o el comando whatsapp:perfil-aliado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->string('perfil_aliado', 20)->nullable()->after('pendiente_motivo');   // asesor | empresa | empleador
            $table->integer('personas_declaradas')->nullable()->after('perfil_aliado');
            $table->string('perfil_sugerencia', 120)->nullable()->after('personas_declaradas');
            $table->dateTime('perfil_aliado_at')->nullable()->after('perfil_sugerencia');
            $table->string('perfil_avisado_a', 80)->nullable()->after('perfil_aliado_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dropColumn(['perfil_aliado', 'personas_declaradas', 'perfil_sugerencia', 'perfil_aliado_at', 'perfil_avisado_a']);
        });
    }
};
