<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién atiende la IA de WhatsApp en cada conversación: cliente, asesor o aliado
 * (ver AsistenteIaService::PERFIL_*). Lo escriben las herramientas cambiar_perfil y
 * perfilar_aliado; vacío, el perfil se deduce del contacto y del anuncio de origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->string('perfil_ia', 20)->nullable()->after('perfil_avisado_a');   // cliente | asesor | aliado
            $table->dateTime('perfil_ia_at')->nullable()->after('perfil_ia');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dropColumn(['perfil_ia', 'perfil_ia_at']);
        });
    }
};
