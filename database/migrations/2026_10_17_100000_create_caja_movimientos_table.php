<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de efectivo entre personas, que no son ni cobro ni gasto.
 *
 * El cuadre partía cada día de cero: lo que una persona no entregaba se
 * "perdía" al día siguiente, y lo que le pasaba a otra no quedaba en ningún
 * lado. En BRYGAR eso dejó ~$50M de efectivo que existían solo en el sistema.
 * Con esta tabla cada persona arrastra su saldo («pendiente por pasar»):
 *
 *   saldo_inicial  el efectivo contado con que arranca una persona
 *                  (usuario_destino_id), al inicio de `fecha`
 *   entrega        de usuario_origen_id a usuario_destino_id
 *   retiro_dueno   el dueño saca plata de la caja (usuario_origen_id); no es
 *                  gasto, se descuenta de la utilidad acumulada
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            // Mismo tipo que users.aliado_id y aliado_user.aliado_id (int).
            $table->unsignedInteger('aliado_id');
            $table->date('fecha');
            $table->string('tipo', 20);
            $table->unsignedInteger('usuario_origen_id')->nullable();
            $table->unsignedInteger('usuario_destino_id')->nullable();
            $table->unsignedBigInteger('valor');
            $table->string('observacion', 500)->nullable();
            $table->unsignedInteger('registrado_por');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['aliado_id', 'fecha'], 'ix_caja_mov_aliado_fecha');
            $table->index(['aliado_id', 'tipo', 'usuario_destino_id'], 'ix_caja_mov_aliado_tipo_destino');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
    }
};
