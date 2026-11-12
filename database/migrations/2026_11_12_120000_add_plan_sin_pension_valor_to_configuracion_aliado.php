<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan EPS sin pensión a precio fijo (BRYGAR lo lanzó en nov-2026 a $125.000 al mes, todo
     * incluido desde el primer mes). Con valor, el asistente de WhatsApp lo ofrece de entrada a
     * los prospectos que buscan salud y un asesor decide por dentro la vía de afiliación. Vacío,
     * el aliado sigue con la cotización de siempre.
     */
    public function up(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->decimal('plan_sin_pension_valor', 12, 2)->nullable()->after('ingreso_retiro_valor_mensual');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->dropColumn('plan_sin_pension_valor');
        });
    }
};
