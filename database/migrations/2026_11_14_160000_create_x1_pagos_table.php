<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos X1 por BryNex: cada planilla que BryNex paga por un aliado, con su
 * comprobante, para la contabilidad (ver X1LiquidacionBrynex::confirmar).
 * La persona de x1_solicitud_planos apunta a su pago con x1_pago_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x1_pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');            // el aliado al que se le pagó la X1
            $table->unsignedInteger('razon_social_id');
            $table->unsignedInteger('n_plano');
            $table->integer('tipo_modalidad_id');
            $table->string('numero_planilla', 30);
            // pagada (Simple) | doble_planilla | blindem
            $table->string('forma', 20);
            $table->decimal('valor', 18, 2)->default(0);
            $table->string('comprobante', 60)->nullable();     // N° del comprobante PSE / CUS
            $table->date('fecha_pago');
            $table->string('soporte_path', 300)->nullable();   // disco local, nunca public
            $table->string('soporte_nombre', 200)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->unsignedInteger('personas')->default(0);
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->index(['aliado_id', 'numero_planilla']);
            $table->index('fecha_pago');
        });

        Schema::table('x1_solicitud_planos', function (Blueprint $table) {
            $table->unsignedBigInteger('x1_pago_id')->nullable()->after('numero_planilla');
            $table->foreign('x1_pago_id')->references('id')->on('x1_pagos');
        });
    }

    public function down(): void
    {
        Schema::table('x1_solicitud_planos', function (Blueprint $table) {
            $table->dropForeign(['x1_pago_id']);
            $table->dropColumn('x1_pago_id');
        });
        Schema::dropIfExists('x1_pagos');
    }
};
