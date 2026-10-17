<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo de la consignación con el abono a préstamo que la originó.
 *
 * Un abono consignado no creaba consignación: la plata entraba a la cuenta y
 * el saldo del banco no la veía. Ahora la crea, colgada del abono y no de la
 * factura (ver Consignacion::registrarDeAbono). Sin FK: al anular la factura
 * se borran sus abonos y sus consignaciones en el mismo paso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('abono_id')->nullable()->after('anticipo_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('consignaciones', function (Blueprint $table) {
            $table->dropIndex(['abono_id']);
            $table->dropColumn('abono_id');
        });
    }
};
