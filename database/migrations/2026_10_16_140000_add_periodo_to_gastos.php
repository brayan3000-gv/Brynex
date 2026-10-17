<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mes al que corresponde el gasto, aparte de `fecha` (el día en que se pagó).
     *
     * El arriendo de agosto pagado en septiembre sale del banco en septiembre
     * —ahí lo pide el extracto— pero es costo de agosto. Se guarda el día 1 del
     * mes. Vacío = el mes de `fecha`: los gastos existentes no se tocan.
     */
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->date('periodo')->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn('periodo');
        });
    }
};
