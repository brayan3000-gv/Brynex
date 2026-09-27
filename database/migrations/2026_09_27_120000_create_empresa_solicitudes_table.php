<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que una empresa pide desde su portal: ingreso de un trabajador, retiro,
 * incapacidad u otra solicitud. Nada se ejecuta solo: cada solicitud abre una
 * tarea (tareas.empresa_id) y el equipo la resuelve desde allí.
 *
 * - empresa_solicitudes.datos: lo que llenó la empresa (JSON), incluidos los
 *   archivos, que viven en el disco `local` (portal/{aliado}/{empresa}/{id}).
 * - tareas.empresa_id: de qué empresa es la tarea aunque no tenga cédula (una
 *   solicitud general) y para contarlas en la tarjeta de la empresa.
 * - tarea_gestiones.visible_empresa: qué avances ve la empresa. Las notas
 *   internas siguen siendo internas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliado_id');
            // empresas.id y contratos.id son int legacy, no bigint.
            $table->integer('empresa_id');
            $table->unsignedBigInteger('empresa_acceso_id')->nullable();
            $table->unsignedBigInteger('tarea_id')->nullable();
            // ingreso | retiro | incapacidad | otra
            $table->string('tipo', 20);
            // pendiente | aprobada | rechazada
            $table->string('estado', 20)->default('pendiente');
            $table->string('tipo_doc', 5)->nullable();
            $table->string('cedula', 20)->nullable();
            $table->integer('contrato_id')->nullable();
            $table->text('datos')->nullable();
            // Lo que respondió el RUAF al revisarla (EPS y AFP de la persona).
            $table->text('ruaf')->nullable();
            // Lo que se creó al aprobarla: el contrato o la incapacidad.
            $table->unsignedBigInteger('resultado_id')->nullable();
            // Cuándo la vio alguien del equipo: hasta entonces cuenta como nueva.
            $table->dateTime('vista_at')->nullable();
            $table->unsignedBigInteger('atendida_por')->nullable();
            $table->dateTime('atendida_at')->nullable();
            $table->timestamps();

            $table->index(['aliado_id', 'estado']);
            $table->index(['empresa_id', 'estado']);
            $table->index('tarea_id');

            $table->foreign('aliado_id')->references('id')->on('aliados');
            $table->foreign('empresa_id')->references('id')->on('empresas');
        });

        Schema::table('tareas', function (Blueprint $table) {
            $table->integer('empresa_id')->nullable()->after('aliado_id');
            $table->index('empresa_id');
        });

        Schema::table('tarea_gestiones', function (Blueprint $table) {
            $table->boolean('visible_empresa')->default(false)->after('observacion');
        });
    }

    public function down(): void
    {
        Schema::table('tarea_gestiones', function (Blueprint $table) {
            $table->dropColumn('visible_empresa');
        });
        Schema::table('tareas', function (Blueprint $table) {
            $table->dropIndex(['empresa_id']);
            $table->dropColumn('empresa_id');
        });
        Schema::dropIfExists('empresa_solicitudes');
    }
};
