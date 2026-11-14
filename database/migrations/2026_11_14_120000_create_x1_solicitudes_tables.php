<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de Tipo E - X1 por BryNex.
 *
 * El aliado no liquida la X1: le manda a Brygar la plata (SS con el día de
 * pensión + administración X1 por persona) con la foto de la consignación, y
 * BryNex hace el pago en Simple. Ver App\Services\X1PagosBrynex.
 *
 * Tablas propias a propósito: no se le agrega nada a `planos` ni a `facturas`,
 * para que la X1 se pueda retirar después sin tocar los planos normales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una solicitud = una consignación del aliado, que puede cubrir varias
        // razones sociales del mismo período de pago.
        Schema::create('x1_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            $table->unsignedSmallInteger('mes');   // período de PAGO (el de planos)
            $table->unsignedSmallInteger('anio');
            // solicitada → en_proceso → pagada (o parcial mientras unas esperan ventana)
            $table->string('estado', 20)->default('solicitada');
            $table->unsignedInteger('personas')->default(0);
            $table->decimal('valor_ss', 18, 2)->default(0);      // incluye el día de pensión
            $table->decimal('valor_admon', 18, 2)->default(0);
            $table->decimal('valor_mora', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('admon_por_persona', 18, 2)->default(0);
            $table->string('soporte_path', 300)->nullable();     // disco local, nunca public
            $table->string('soporte_nombre', 200)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('observacion', 500)->nullable();
            $table->timestamps();

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->index(['aliado_id', 'anio', 'mes']);
            $table->index('estado');
        });

        // Una fila por persona (registro de `planos`) dentro de la solicitud.
        Schema::create('x1_solicitud_planos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('x1_solicitud_id');
            $table->unsignedBigInteger('aliado_id');
            $table->unsignedBigInteger('plano_id');
            $table->unsignedInteger('razon_social_id');
            $table->integer('tipo_modalidad_id');
            $table->unsignedInteger('n_plano_origen');           // dónde estaba al solicitar
            $table->unsignedInteger('n_plano');                  // dónde quedó (su plano X1)
            $table->decimal('valor_ss', 18, 2)->default(0);
            $table->decimal('valor_afp', 18, 2)->default(0);
            // en_solicitud → pagada | esperando_ventana → pagada | blindem | doble_planilla
            $table->string('estado', 20)->default('en_solicitud');
            $table->string('numero_planilla', 30)->nullable();
            $table->timestamps();

            $table->foreign('x1_solicitud_id')->references('id')->on('x1_solicitudes');
            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->foreign('plano_id')->references('id')->on('planos')->noActionOnDelete();
            // Un registro de plano va en una sola solicitud.
            $table->unique('plano_id');
            $table->index(['aliado_id', 'razon_social_id']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x1_solicitud_planos');
        Schema::dropIfExists('x1_solicitudes');
    }
};
