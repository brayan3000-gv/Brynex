<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo trabaja cada asesor con el aliado:
 *  - tipo_cobro: 'comision' (su parte va en el contrato, en admon_asesor),
 *    'neta' (el aliado le cobra un valor fijo por persona y él cobra lo suyo
 *    por fuera) o 'interno' (la oficina; no aplican niveles).
 *  - tarifa_neta: lo que paga por persona cuando es 'neta'. Informativo.
 *  - forma_pago: cómo recibe su comisión (descuenta al cobrar, quincenal, mensual).
 *
 * Se cambia a mano desde el formulario del asesor; nada lo mueve solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->string('tipo_cobro', 20)->default('comision')->after('comision_admon_valor');
            $table->decimal('tarifa_neta', 18, 2)->nullable()->after('tipo_cobro');
            $table->string('forma_pago', 20)->nullable()->after('tarifa_neta');
        });
    }

    public function down(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->dropColumn(['tipo_cobro', 'tarifa_neta', 'forma_pago']);
        });
    }
};
