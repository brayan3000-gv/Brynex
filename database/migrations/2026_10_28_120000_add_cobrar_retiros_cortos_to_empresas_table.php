<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un retiro de 1 a 3 días no se le cobra a la empresa: la seguridad social de
 * esos días la asume el aliado, igual que la administración (que ya no se
 * cobraba en retiros cortos). Las empresas que sí los pagan se marcan aquí,
 * con el botón de la planilla de facturación de la empresa (oct-2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->boolean('cobrar_retiros_cortos')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('cobrar_retiros_cortos');
        });
    }
};
