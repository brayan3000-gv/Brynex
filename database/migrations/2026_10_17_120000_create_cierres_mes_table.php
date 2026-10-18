<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cierre de cada mes de un aliado: la foto de cómo terminó y qué pasó al
 * mes siguiente (ver App\Services\Caja\CierreMes).
 *
 * No hay cierre manual: la foto se guarda sola el día 1 (cierres:foto) y cada
 * mes arranca con lo que dejó el anterior. Si después alguien edita el mes,
 * el informe compara la foto con lo de ahora y avisa la diferencia.
 *
 *   foto                bloques A–D calculados al cerrar (json)
 *   saldos_reales       lo que el superadmin anota que hay de verdad en cada
 *                       cuenta y en efectivo al cierre (json)
 *   es_apertura         el primer cierre del aliado: su diferencia no se busca,
 *                       se guarda como ajuste de apertura (como el saldo inicial
 *                       de los bancos el 1-jul-2026)
 *   utilidad_acumulada  utilidad que quedó en la empresa al terminar el mes; el
 *                       mes siguiente parte de aquí
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_mes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('aliado_id');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->text('foto')->nullable();
            $table->dateTime('foto_at')->nullable();
            $table->text('saldos_reales')->nullable();
            $table->unsignedInteger('saldos_por')->nullable();
            $table->dateTime('saldos_at')->nullable();
            $table->boolean('es_apertura')->default(false);
            $table->bigInteger('utilidad_acumulada')->nullable();
            $table->timestamps();

            $table->unique(['aliado_id', 'anio', 'mes'], 'ux_cierres_mes_aliado_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_mes');
    }
};
