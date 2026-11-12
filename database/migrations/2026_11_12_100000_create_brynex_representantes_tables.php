<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Representantes legales de las razones sociales de BryNex: una fila por
 * PERSONA (cédula), no por empresa.
 *
 * Un representante lo es de 1 a 5 empresas. Sus claves personales (el correo
 * de su RUT, la DIAN como persona natural, el usuario autorizado de
 * facturación electrónica) son las mismas para todas, así que guardarlas en
 * cada razón social obligaba a repetirlas y a que una quedara vieja.
 *
 * Qué empresas representa NO se guarda aquí: sale de `razones_sociales.cedula_rep`
 * de las filas vinculadas a cada ficha, que es donde ya lo tienen los aliados.
 * Así no hay una segunda lista que se desactualice.
 *
 * Sin aliado_id, igual que `brynex_razones_sociales`: es de BryNex y el
 * aislamiento lo da el permiso `brynex_razones.*` (solo_brynex).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brynex_representantes')) {
            Schema::create('brynex_representantes', function (Blueprint $table) {
                $table->id();
                $table->string('tipo_documento', 4)->default('CC');
                // Texto y no número: una CE o un PT pueden traer letras, y
                // `users.cedula` ya enseñó lo que cuesta mezclar int y nvarchar.
                $table->string('cedula', 20);
                $table->string('nombre', 150);
                // El correo del RUT PERSONAL: ahí manda la DIAN el enlace de
                // acceso del representante legal (facturación electrónica).
                $table->string('correo_rut', 150)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->string('notas', 1000)->nullable();
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->unsignedBigInteger('actualizado_por')->nullable();
                $table->timestamps();

                $table->unique('cedula', 'ux_brynex_rep_cedula');
            });
        }

        if (! Schema::hasTable('brynex_representante_credenciales')) {
            Schema::create('brynex_representante_credenciales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('representante_id');
                $table->string('tipo', 40)->default('OTRO');
                $table->string('entidad', 150);
                $table->string('link_acceso', 350)->nullable();
                $table->string('usuario', 150)->nullable();
                // Cifrada: el texto cifrado de Laravel pasa largo de 200 chars.
                $table->text('contrasena')->nullable();
                $table->string('observacion', 500)->nullable();
                $table->boolean('activo')->default(true);
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->unsignedBigInteger('actualizado_por')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['representante_id', 'tipo'], 'ix_brynex_rep_cred');
                $table->foreign('representante_id', 'fk_brynex_rep_cred')
                    ->references('id')->on('brynex_representantes');
            });
        }

        // Representante puesto a mano en la ficha, para las empresas que no
        // están en ningún aliado (AVAPP, CIT, STRATEGY) o cuyo aliado no tiene
        // anotada la cédula. Si tiene valor, manda sobre lo de los aliados.
        if (Schema::hasTable('brynex_razones_sociales')
            && ! Schema::hasColumn('brynex_razones_sociales', 'representante_cedula')) {
            Schema::table('brynex_razones_sociales', function (Blueprint $table) {
                $table->string('representante_cedula', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('brynex_razones_sociales', 'representante_cedula')) {
            Schema::table('brynex_razones_sociales', function (Blueprint $table) {
                $table->dropColumn('representante_cedula');
            });
        }
        Schema::dropIfExists('brynex_representante_credenciales');
        Schema::dropIfExists('brynex_representantes');
    }
};
