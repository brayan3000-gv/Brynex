<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo llegó cada prospecto a la demo, para saber a quién le toca la comisión:
 * `origen` es asesor, redes, campana u otro, y `origen_detalle` el nombre del
 * asesor tal como lo escribió el prospecto, la etiqueta de la campaña (del
 * enlace ?origen=…) o lo que escribió en «otro».
 *
 * Por ahora el asesor es texto libre (decisión del dueño, 13-nov-2026): los
 * asesores de BryNex todavía no están en el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_prospectos', function (Blueprint $table) {
            $table->string('origen', 20)->nullable()->after('acepto_contacto');
            $table->string('origen_detalle', 120)->nullable()->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('demo_prospectos', function (Blueprint $table) {
            $table->dropColumn(['origen', 'origen_detalle']);
        });
    }
};
