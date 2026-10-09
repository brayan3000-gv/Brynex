<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con la caché de Claude y de Gemini, lo que cuesta una respuesta ya no sale solo de
 * tokens_entrada: lo leído de la caché cuesta una décima parte y lo escrito un poco más que
 * la entrada normal. Se guardan aparte para poder ver cuánto está ahorrando, junto con lo que
 * tardó el turno completo (todas las vueltas con herramientas).
 *
 * También entran aquí las transcripciones de notas de voz (canal whatsapp_audio / garvis_audio),
 * que antes no quedaban registradas en ninguna parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ia_consumo', function (Blueprint $table) {
            $table->unsignedInteger('tokens_cache_lectura')->default(0)->after('tokens_salida');
            $table->unsignedInteger('tokens_cache_escritura')->default(0)->after('tokens_cache_lectura');
            $table->unsignedInteger('latencia_ms')->nullable()->after('tokens_cache_escritura');
        });
    }

    public function down(): void
    {
        Schema::table('ia_consumo', function (Blueprint $table) {
            $table->dropColumn(['tokens_cache_lectura', 'tokens_cache_escritura', 'latencia_ms']);
        });
    }
};
