<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino un gasto que no se digitó en la pantalla. Hoy solo GARVIS
 * (`garvis:<mensaje de WhatsApp>#<renglón>`): si el workflow se vuelve a correr
 * con el mismo mensaje, el único hace que el gasto no quede dos veces.
 *
 * Va en database/migrations y no en database/migrations/finanzas porque el
 * despliegue solo corre las de aquí; apunta a la conexión `finanzas` igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('finanzas')->hasColumn('finanzas_gastos', 'origen')) {
            return;
        }

        Schema::connection('finanzas')->table('finanzas_gastos', function (Blueprint $table) {
            $table->string('origen', 100)->nullable();
        });

        // Único solo entre los que tienen origen: los digitados a mano lo llevan en NULL.
        DB::connection('finanzas')->statement(
            'CREATE UNIQUE INDEX finanzas_gastos_origen_unique ON finanzas_gastos (origen) WHERE origen IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('finanzas')->statement('DROP INDEX finanzas_gastos_origen_unique ON finanzas_gastos');

        Schema::connection('finanzas')->table('finanzas_gastos', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
