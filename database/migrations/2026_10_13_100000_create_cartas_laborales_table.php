<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de las cartas laborales que BryNex genera a nombre de una razón
 * social (módulo Cartas laborales).
 *
 * Una carta laboral es una declaración de la empresa: queda registrado quién
 * la sacó, quién la firma y con qué datos. `datos` guarda el contenido ya
 * resuelto, para que volver a descargarla dé exactamente el mismo texto
 * aunque después cambie el contrato.
 *
 * contrato_id y razon_social_id van sin FK: esas dos tablas son legacy con
 * `id` INT (ver migraciones-pk-legacy-int) y la FK a un bigint no cuadra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartas_laborales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            $table->integer('contrato_id')->nullable();
            $table->integer('razon_social_id')->nullable();
            $table->string('cedula', 20);
            $table->string('destino', 20); // caja | certificacion
            $table->string('firmante_tipo', 20); // representante | usuario
            $table->unsignedBigInteger('firmante_user_id')->nullable();
            $table->string('firmante_nombre', 150);
            $table->string('firmante_cargo', 120)->nullable();
            $table->text('datos');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->index(['aliado_id', 'created_at']);
            $table->index(['aliado_id', 'cedula']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartas_laborales');
    }
};
