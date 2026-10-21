<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Día del mes en que el aliado retira de la EPS a quien no ha pagado. Lo usa la
 * plantilla de cobro «aviso_retiro_eps» para decir cuánto falta («3 días»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->unsignedTinyInteger('dia_retiros_eps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_aliado', function (Blueprint $table) {
            $table->dropColumn('dia_retiros_eps');
        });
    }
};
