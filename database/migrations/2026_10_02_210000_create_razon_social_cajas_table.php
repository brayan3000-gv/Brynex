<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas de compensación de una razón social por departamento.
 *
 * La caja principal sigue en razones_sociales.caja_nit. Aquí van las de los
 * otros departamentos donde la empresa tiene trabajadores (Comfandi en el
 * Valle, Compensar en Bogotá…), una por departamento. El contrato las muestra
 * según el departamento del cliente.
 *
 * Una copia de otro aliado (origen_id) no tiene filas propias: usa las de la
 * original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razon_social_cajas', function (Blueprint $table) {
            $table->id();
            // razones_sociales.id es int (tabla legacy). Sin FK: borrar la razón
            // social borra sus cajas en el controlador.
            $table->unsignedInteger('razon_social_id');
            $table->integer('departamento_id');
            $table->unsignedBigInteger('caja_id');
            $table->timestamps();

            $table->unique(['razon_social_id', 'departamento_id'], 'uq_rs_cajas_rs_dpto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razon_social_cajas');
    }
};
