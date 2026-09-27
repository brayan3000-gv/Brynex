<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso de una empresa cliente a su portal (brynex.co/portal).
 *
 * Tabla aparte de `users` a propósito: la empresa inicia sesión con otro guard
 * (`empresa`), así que para Laravel nunca está autenticada en el guard `web` y
 * ninguna de las rutas de /admin la deja pasar, tenga o no permiso escrito.
 *
 * Uno por empresa. `usuario` es el NIT con que entra; se copia del NIT de la
 * empresa al crear el acceso y no se repite entre accesos, porque el login no
 * sabe de qué aliado viene quien lo escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_accesos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            // empresas.id es int legacy, no bigint: una FK con bigint no se deja crear.
            $table->integer('empresa_id');
            $table->string('usuario', 30);
            $table->string('password');
            $table->rememberToken();
            $table->boolean('activo')->default(true);
            // false: la empresa ve solo el total (seguridad social + administración)
            // true: ve EPS, ARL, pensión, caja y administración por separado.
            $table->boolean('ver_discriminado')->default(false);
            // La clave la genera el aliado y la ve una sola vez; la empresa pone
            // la suya al entrar.
            $table->boolean('debe_cambiar_clave')->default(true);
            $table->dateTime('ultimo_acceso_at')->nullable();
            $table->string('ultimo_acceso_ip', 45)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->unique('empresa_id');
            $table->unique('usuario');
            $table->index('aliado_id');

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->foreign('empresa_id')->references('id')->on('empresas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_accesos');
    }
};
