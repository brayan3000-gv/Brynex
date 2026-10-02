<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizar a una empresa con varios trabajadores: el prospecto pasa a tener un
 * tipo (persona o empresa) y cada trabajador cotizado queda en su propia fila,
 * con cargo, plan y resultado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones_prospectos', function (Blueprint $table) {
            $table->string('tipo', 10)->default('persona')->after('aliado_id'); // persona | empresa
            $table->string('empresa_nombre', 200)->nullable()->after('tipo');
            $table->string('empresa_nit', 20)->nullable()->after('empresa_nombre');
        });

        Schema::create('cotizacion_trabajadores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('aliado_id')->index();
            $table->unsignedBigInteger('cotizacion_id')->index();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('cargo', 100);
            $table->string('nombre', 150)->nullable();
            $table->unsignedBigInteger('modalidad_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->integer('salario')->default(0);
            $table->unsignedTinyInteger('n_arl')->default(1);
            $table->text('resultado')->nullable(); // JSON: completo, proporcional
            $table->timestamps();

            $table->foreign('cotizacion_id')->references('id')->on('cotizaciones_prospectos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_trabajadores');

        Schema::table('cotizaciones_prospectos', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'empresa_nombre', 'empresa_nit']);
        });
    }
};
