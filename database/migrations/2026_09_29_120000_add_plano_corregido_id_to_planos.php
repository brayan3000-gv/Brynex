<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La corrección N con valores (salario o días) necesita saber qué se pagó:
 * la línea A del archivo repite la línea pagada y la C trae lo nuevo. El
 * plano de corrección (tipo_p 16) apunta al plano que corrige, y de ahí sale
 * la A. Ver CorreccionPlanillaService.
 *
 * Sin FK: `planos` es legacy y un plano borrado (soft delete) no debe
 * arrastrar ni bloquear su corrección.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            if (! Schema::hasColumn('planos', 'plano_corregido_id')) {
                $table->unsignedBigInteger('plano_corregido_id')->nullable();
                $table->index('plano_corregido_id', 'planos_plano_corregido_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropIndex('planos_plano_corregido_id_index');
            $table->dropColumn('plano_corregido_id');
        });
    }
};
