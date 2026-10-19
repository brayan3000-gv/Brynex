<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de pago conjunto en los abonos.
 *
 * «Pagar todo» reparte una sola consignación entre varios períodos de un
 * cliente: queda un abono por período pero UNA consignación, colgada del
 * primero. `pago_id` es el id de ese primer abono en todos los del pago (él
 * incluido), y así el relleno de consignaciones no crea las «faltantes» y la
 * anulación de una factura sabe que la consignación es compartida.
 * Null en los abonos sueltos. Sin FK: igual que consignaciones.abono_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonos', function (Blueprint $table) {
            $table->unsignedBigInteger('pago_id')->nullable()->after('factura_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('abonos', function (Blueprint $table) {
            $table->dropIndex(['pago_id']);
            $table->dropColumn('pago_id');
        });
    }
};
