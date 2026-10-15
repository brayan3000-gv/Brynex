<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mismo par de columnas que ya tienen `eps` y `pensiones` (ver
     * 2026_08_28_100000_add_formulario_to_pensiones_table): el editor visual
     * de mapeo es el mismo, solo cambia la entidad dueña del PDF.
     * Nace por el formulario de afiliación de dependientes de COMFABOY.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            // Nombre del archivo dentro de storage/app/formularios/cajas
            $table->string('formulario_pdf')->nullable();
            // JSON con las coordenadas de cada campo para superponer con FPDI
            $table->json('formulario_campos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn(['formulario_pdf', 'formulario_campos']);
        });
    }
};
