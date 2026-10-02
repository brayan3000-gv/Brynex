<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué razón social es copia esta fila.
 *
 * La misma empresa existe una vez por aliado (Brygar presta las suyas a Fecop
 * y a Luis Lopez). Con esto la copia sabe cuál es la original: los datos de la
 * empresa se cambian allá y pasan solos a las copias, y cada aliado conserva
 * lo suyo (sucursal, planilla, estado). Ver RazonSocialCompartida.
 *
 * Es int y sin FK a propósito: `razones_sociales.id` es int legacy sin
 * IDENTITY (ver la migración de creación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('razones_sociales', function (Blueprint $table) {
            $table->integer('origen_id')->nullable();
            $table->index('origen_id', 'IX_razones_sociales_origen');
        });
    }

    public function down(): void
    {
        Schema::table('razones_sociales', function (Blueprint $table) {
            $table->dropIndex('IX_razones_sociales_origen');
            $table->dropColumn('origen_id');
        });
    }
};
