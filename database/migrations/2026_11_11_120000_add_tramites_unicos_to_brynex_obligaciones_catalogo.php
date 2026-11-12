<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trámites de una sola vez en el checklist de razones sociales.
 *
 * Hasta ahora todo era periódico (mensual, bimestral, anual). La habilitación
 * de facturación electrónica no: se hace una vez y queda. `meses_plazo` dice
 * cuántos meses hay desde la constitución para hacerla — el art. 915 E.T. le
 * da al régimen simple dos meses desde la inscripción en el RUT, y
 * `fecha_constitucion` es, en la práctica, la fecha de esa inscripción.
 *
 * El renglón del catálogo va aquí y no solo en el seeder porque el despliegue
 * corre las migraciones, no los seeders. El seeder lo repite para que siga
 * siendo la fuente completa del catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brynex_obligaciones_catalogo', 'meses_plazo')) {
            Schema::table('brynex_obligaciones_catalogo', function (Blueprint $table) {
                // Solo para periodicidad 'unica': meses desde la constitución.
                $table->integer('meses_plazo')->nullable();
            });
        }

        if (! DB::table('brynex_obligaciones_catalogo')->where('codigo', 'facturacion_electronica')->exists()) {
            DB::table('brynex_obligaciones_catalogo')->insert([
                'codigo' => 'facturacion_electronica',
                'nombre' => 'Habilitación de facturación electrónica',
                'entidad' => 'DIAN',
                'formulario' => null,
                'regimen' => 'RST',
                'periodicidad' => 'unica',
                'requiere_iva' => false,
                'periodicidad_iva_requerida' => null,
                'requiere_responsabilidad' => null,
                'anios_desfase' => 0,
                'meses_plazo' => 2,
                'descripcion' => 'Se hace una sola vez. El régimen simple tiene 2 meses desde la inscripción en el RUT (art. 915 E.T.); pasado el plazo el incumplimiento es insubsanable. Gratis con el facturador de la DIAN. Marcar «Hecha» con la fecha de la habilitación.',
                'orden' => 5,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('brynex_obligaciones_catalogo')->where('codigo', 'facturacion_electronica')->delete();

        if (Schema::hasColumn('brynex_obligaciones_catalogo', 'meses_plazo')) {
            Schema::table('brynex_obligaciones_catalogo', function (Blueprint $table) {
                $table->dropColumn('meses_plazo');
            });
        }
    }
};
