<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administración que ya salió en una factura electrónica y viaja en un anticipo.
 *
 * Cuando se anula UNA fila de un lote cuya FE cubre el recibo completo, la FE
 * no se toca y la plata de esa fila queda como anticipo (recibo #14580, Yeiner
 * Montenegro, nov-2026). La administración de esa fila ya se reportó a la DIAN:
 *
 *   anticipos.admon_fe_pendiente  cuánto de esa administración falta por pasar
 *                                 a la factura que consuma el anticipo.
 *   anticipos.dataico_envio_id    la FE donde quedó facturada.
 *   facturas.admon_fe_previa      lo que esta factura recibió de ahí: la emisión
 *                                 de FE lo resta de la base para no facturarlo
 *                                 dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('anticipos', 'admon_fe_pendiente')) {
            Schema::table('anticipos', function (Blueprint $table) {
                $table->integer('admon_fe_pendiente')->nullable();
                $table->unsignedBigInteger('dataico_envio_id')->nullable();
            });
        }

        if (! Schema::hasColumn('facturas', 'admon_fe_previa')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->integer('admon_fe_previa')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('anticipos', 'admon_fe_pendiente')) {
            Schema::table('anticipos', function (Blueprint $table) {
                $table->dropColumn(['admon_fe_pendiente', 'dataico_envio_id']);
            });
        }

        if (Schema::hasColumn('facturas', 'admon_fe_previa')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->dropColumn('admon_fe_previa');
            });
        }
    }
};
