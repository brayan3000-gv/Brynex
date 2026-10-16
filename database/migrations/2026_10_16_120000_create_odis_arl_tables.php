<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de la ARL para pagar incapacidades a la razón social.
 *
 * La ARL le reconoce una incapacidad laboral a la empresa de dos formas, y
 * ninguna de las dos es una transferencia a su cuenta:
 *
 *   · ODI (Orden de Descuento de Incapacidad): la plata NO llega. Se descuenta
 *     del aporte a la ARL en las planillas siguientes (renglón 32 + número de
 *     ODI), hasta el 99 % del aporte de cada mes; lo que no alcance pasa al
 *     mes siguiente. Además de las incapacidades, la orden devuelve la salud y
 *     pensión que la empresa pagó durante la incapacidad ("cotizaciones
 *     pagadas a otros riesgos", renglón 34). El total a descontar es la suma.
 *   · OPI (Orden de Pago de Incapacidad): llega en cheque o consignación, y se
 *     cobra a una cuenta o en efectivo.
 *
 * Hasta ahora las dos se guardaban como una entrada de incapacidad sin banco
 * ni consignación: el pago al afiliado sí salía del banco, la entrada no
 * figuraba en ninguno, y las tarjetas del informe financiero quedaban en
 * negativo (BRYGAR, oct-2026).
 *
 * `odis_arl` lleva el saldo de cada ODI; `odi_descuentos`, cada planilla que la
 * fue consumiendo. Las incapacidades que cubre una ODI son sus entradas
 * (`abonos_incapacidades.odi_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odis_arl', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            // razones_sociales.id es INT (tabla legacy): con bigint la FK no se crea.
            $table->integer('razon_social_id');
            $table->unsignedBigInteger('arl_id')->nullable();
            $table->string('numero', 30);
            $table->date('fecha');
            $table->decimal('valor_incapacidades', 14, 2)->default(0);
            $table->decimal('valor_otros_riesgos', 14, 2)->default(0);
            // Se guarda (no se calcula) para que el saldo se lea con una resta.
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('descontado', 14, 2)->default(0);
            $table->string('imagen_path', 500)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->unique(['aliado_id', 'razon_social_id', 'numero']);
            $table->index(['aliado_id', 'razon_social_id']);

            $table->foreign('aliado_id')->references('id')->on('aliados')->noActionOnDelete();
            $table->foreign('razon_social_id')->references('id')->on('razones_sociales')->noActionOnDelete();
            $table->foreign('arl_id')->references('id')->on('arls')->noActionOnDelete();
        });

        Schema::create('odi_descuentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            $table->unsignedBigInteger('odi_id');
            $table->string('numero_planilla', 20)->nullable();
            // YYYYMM, como lo reporta el operador.
            $table->string('periodo', 6)->nullable();
            $table->decimal('valor', 14, 2);
            $table->date('fecha');
            // manual | api | correccion
            $table->string('origen', 20)->default('manual');
            $table->string('observacion', 500)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->index(['odi_id']);
            $table->index(['aliado_id', 'numero_planilla']);

            $table->foreign('aliado_id')->references('id')->on('aliados')->noActionOnDelete();
            $table->foreign('odi_id')->references('id')->on('odis_arl')->noActionOnDelete();
        });

        Schema::table('abonos_incapacidades', function (Blueprint $table) {
            // Cómo llegó una entrada de incapacidad: transferencia | odi |
            // opi_banco | opi_efectivo | cheque | otro. Nulo en los abonos que
            // no son entradas y en los anteriores a esta migración.
            $table->string('forma_recepcion', 20)->nullable()->after('tipo');
            $table->unsignedBigInteger('odi_id')->nullable()->after('consignacion_id');

            $table->foreign('odi_id')->references('id')->on('odis_arl')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('abonos_incapacidades', function (Blueprint $table) {
            $table->dropForeign(['odi_id']);
            $table->dropColumn(['forma_recepcion', 'odi_id']);
        });
        Schema::dropIfExists('odi_descuentos');
        Schema::dropIfExists('odis_arl');
    }
};
