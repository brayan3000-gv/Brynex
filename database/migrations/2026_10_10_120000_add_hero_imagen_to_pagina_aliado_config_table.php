<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto del encabezado de la página pública del aliado (ruta relativa a public/storage).
     * Sin foto, el hero muestra el logo de marca o la tarjeta con el logo.
     */
    public function up(): void
    {
        Schema::table('pagina_aliado_config', function (Blueprint $table) {
            $table->string('hero_imagen', 255)->nullable()->after('hero_cta_texto');
        });
    }

    public function down(): void
    {
        Schema::table('pagina_aliado_config', function (Blueprint $table) {
            $table->dropColumn('hero_imagen');
        });
    }
};
