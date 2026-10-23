<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llamadas de Avappi Voz para recordar el pago de la seguridad social.
 *
 * Una fila por intento: Avappi crea una llamada (un id) por cada vez que marca,
 * y avisa cada una por separado. Las de una misma serie comparten `referencia`
 * (aliado, día y celular). `aviso_en` dice si el aviso de ese intento ya se
 * procesó: si llega repetido, no se aplica dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llamadas_voz')) {
            return;
        }

        Schema::create('llamadas_voz', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            $table->string('avappi_id', 36)->unique();
            $table->string('referencia', 150)->index();
            $table->string('numero', 15);
            $table->string('nombre', 150)->nullable();
            // Contratos que cubre la llamada (un celular puede pagar varios).
            $table->text('contrato_ids');
            $table->text('factura_ids')->nullable();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->decimal('valor', 14, 0)->default(0);
            $table->date('vence')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->unsignedTinyInteger('intento')->default(1);
            $table->boolean('final')->default(false);
            $table->string('tipo', 30)->nullable();
            $table->string('estado', 20)->nullable();
            $table->string('causa', 40)->nullable();
            $table->string('resultado', 40)->nullable();
            $table->text('datos')->nullable();
            $table->string('resumen', 1000)->nullable();
            $table->date('fecha_promesa')->nullable();
            $table->integer('duracion_seg')->nullable();
            $table->decimal('costo_cop', 12, 2)->nullable();
            $table->dateTime('proximo_intento_en')->nullable();
            $table->dateTime('terminada_en')->nullable();
            $table->dateTime('aviso_en')->nullable();
            $table->timestamps();

            $table->index(['aliado_id', 'numero']);
            $table->index(['aliado_id', 'created_at']);
            $table->foreign('aliado_id')->references('id')->on('aliados');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llamadas_voz');
    }
};
