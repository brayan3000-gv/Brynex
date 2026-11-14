<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos X1 por BryNex: BryNex marca que la consignación del aliado es real
 * (la vio en el banco) antes de pagar. La planilla conjunta por razón social
 * solo junta a la gente de solicitudes verificadas (Brygar no consigna: va
 * verificada sola). Ver X1LiquidacionBrynex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x1_solicitudes', function (Blueprint $table) {
            $table->timestamp('consignacion_verificada_at')->nullable()->after('soporte_nombre');
            $table->unsignedBigInteger('consignacion_verificada_por')->nullable()->after('consignacion_verificada_at');
        });
    }

    public function down(): void
    {
        Schema::table('x1_solicitudes', function (Blueprint $table) {
            $table->dropColumn(['consignacion_verificada_at', 'consignacion_verificada_por']);
        });
    }
};
