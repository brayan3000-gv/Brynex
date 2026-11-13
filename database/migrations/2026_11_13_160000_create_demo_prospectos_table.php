<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quienes prueban la demo pública (demo.brynex.co): lo que escribieron al
 * entrar y, si verificaron su WhatsApp, el número y el nombre que trae su
 * perfil. Vive en producción y no en la demo porque la base de la demo se
 * borra cada noche, y esta lista es para volver a escribirles.
 *
 * `aliado_id` es el de BryNex: son sus prospectos, los mismos que caen en su
 * bandeja de WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_prospectos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            $table->string('nombre', 120);
            $table->string('empresa', 150)->nullable();
            $table->string('celular', 20);                       // como lo escribió
            $table->string('codigo', 6);                         // el «DEMO 1234» que manda por WhatsApp
            $table->boolean('acepto_contacto')->default(false);
            $table->string('telefono_whatsapp', 20)->nullable(); // el que de verdad escribió (verificado)
            $table->string('nombre_whatsapp', 150)->nullable();  // nombre de su perfil de WhatsApp
            $table->dateTime('verificado_at')->nullable();
            $table->integer('envios')->default(0);               // envíos reales hechos desde la demo
            $table->dateTime('ultimo_envio_at')->nullable();
            $table->dateTime('ultimo_uso_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->index('codigo');
            $table->index('telefono_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_prospectos');
    }
};
