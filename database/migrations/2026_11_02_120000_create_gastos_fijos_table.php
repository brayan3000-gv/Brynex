<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos fijos: lo que el aliado paga cada mes (arriendo, servicios, nómina…)
 * para que el estado financiero avise cuál falta reportar en el mes.
 *
 *   gastos_fijos            la lista que se configura (botón «Gastos fijos»)
 *   gastos_fijos_omitidos   «No aplica este mes» para un fijo en un mes
 *   gastos.gasto_fijo_id    el gasto que se registró desde el aviso
 *
 * Nació el 2-nov-2026: octubre de Brygar quedó sin el arriendo y nadie lo notó.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gastos_fijos')) {
            Schema::create('gastos_fijos', function (Blueprint $table) {
                $table->id();
                $table->integer('aliado_id')->index();
                $table->string('concepto', 150);
                $table->string('tipo', 50);                  // Gasto::TIPOS
                $table->string('pagado_a', 255)->nullable();
                // Texto con que se reconoce un gasto registrado a mano: se busca
                // en pagado_a y en la descripción del gasto del mismo tipo.
                $table->string('palabra_clave', 100)->nullable();
                $table->bigInteger('valor')->default(0);
                $table->string('frecuencia', 20)->default('mensual'); // mensual | quincenal | bimestral
                $table->tinyInteger('dia')->nullable();      // día del mes en que suele pagarse
                $table->string('forma_pago', 30)->nullable();
                $table->integer('banco_origen_id')->nullable();
                $table->date('desde')->nullable();           // primer mes en que aplica
                $table->boolean('activo')->default(true);
                $table->integer('usuario_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gastos_fijos_omitidos')) {
            Schema::create('gastos_fijos_omitidos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gasto_fijo_id')->index();
                $table->smallInteger('anio');
                $table->tinyInteger('mes');
                $table->integer('usuario_id')->nullable();
                $table->timestamps();
                $table->unique(['gasto_fijo_id', 'anio', 'mes']);
            });
        }

        if (! Schema::hasColumn('gastos', 'gasto_fijo_id')) {
            Schema::table('gastos', function (Blueprint $table) {
                $table->unsignedBigInteger('gasto_fijo_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gastos', 'gasto_fijo_id')) {
            Schema::table('gastos', function (Blueprint $table) {
                $table->dropColumn('gasto_fijo_id');
            });
        }
        Schema::dropIfExists('gastos_fijos_omitidos');
        Schema::dropIfExists('gastos_fijos');
    }
};
