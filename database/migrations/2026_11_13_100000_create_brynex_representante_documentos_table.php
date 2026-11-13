<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos personales de un representante legal (cédula, RUT personal),
 * guardados una sola vez por persona igual que sus claves: los piden la
 * entidad certificadora de la firma digital, la cámara de comercio, los bancos…
 * y son los mismos para todas sus empresas.
 *
 * Los archivos van al disco `local` (storage/app), nunca a `public`: son datos
 * personales (ver C-4 en docs/auditoria-seguridad.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brynex_representante_documentos')) {
            return;
        }

        Schema::create('brynex_representante_documentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('representante_id');
            $table->string('tipo', 20)->default('OTRO');
            $table->string('nombre_original', 255);
            $table->string('ruta', 500);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->string('observacion', 300)->nullable();
            $table->unsignedBigInteger('subido_por')->nullable();
            $table->timestamps();

            $table->index('representante_id', 'ix_brynex_rep_doc');
            $table->foreign('representante_id', 'fk_brynex_rep_doc')
                ->references('id')->on('brynex_representantes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brynex_representante_documentos');
    }
};
