<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portales de entidades por razón social.
 *
 * Hasta ahora la entidad de una clave era texto libre («SURA», «Sura ARL»,
 * «EPS», «Caja»…) y los procesos la buscaban con LIKE, así que no había forma
 * de saber qué EPS le faltaba a una empresa. Con esto cada clave de EPS, ARL o
 * caja queda ligada a su fila del catálogo, y puede decir también que la
 * entidad no tiene portal (se trabaja con el asesor por correo) o que no le
 * aplica a esa empresa.
 *
 * El asesor va en campos propios y no en `correo_entidad`: ese campo hoy
 * mezcla el correo del asesor de S.O.S. con el de la empresa y hasta notas.
 *
 * En `eps`, la marca `vigente` separa las EPS que operan de las liquidadas,
 * las universidades y SENA/ICBF que también viven en el catálogo. La lista
 * sale de las EPS de los contratos de 2026, sin las liquidadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clave_accesos', function (Blueprint $table) {
            // EPS | ARL | CAJA — y el id en eps, arls o cajas.
            $table->string('entidad_tipo', 10)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();

            // La entidad no tiene portal de empleador: se afilia por correo.
            $table->boolean('sin_portal')->default(false);
            // La empresa no trabaja con esta entidad.
            $table->boolean('no_aplica')->default(false);

            $table->string('asesor_nombre', 150)->nullable();
            $table->string('asesor_correo', 150)->nullable();
            $table->string('asesor_telefono', 50)->nullable();
            // A quién escribir cuando el asesor está de vacaciones.
            $table->string('asesor2_nombre', 150)->nullable();
            $table->string('asesor2_correo', 150)->nullable();
            $table->string('asesor2_telefono', 50)->nullable();

            $table->index(['entidad_tipo', 'entidad_id'], 'IX_clave_accesos_entidad');
        });

        Schema::table('eps', function (Blueprint $table) {
            $table->boolean('vigente')->default(false);
        });

        DB::table('eps')->whereIn('codigo', [
            'EPS010', // SURA
            'EPS005', // SANITAS
            'EPS002', // SALUD TOTAL
            'EPS037', // NUEVA EPS
            'EPS018', // S.O.S.
            'EPS012', // COMFENALCO VALLE
            'ESSC18', // EMSSANAR
            'EPS042', // COOSALUD
            'EPS040', // SAVIA SALUD
            'ESSC62', // ASMET SALUD
            'EPS017', // FAMISANAR
            'EPS008', // COMPENSAR
            'EPSIC3', // A.I.C.
            'ESSC07', // MUTUAL SER
            'EPS001', // ALIANSALUD
            'EPSC34', // CAPITAL SALUD
            'EPSIC5', // MALLAMAS
            'CCFC55', // CAJACOPI
            'EPS046', // SALUD MIA
            'CCFC50', // COMFAORIENTE
            'CCFC20', // COMFACHOCO
            'CCFC23', // COMFAGUAJIRA
            'EPSIC1', // DUSAKAWI
            'EPSIC6', // PIJAOS SALUD
            'EPSIC4', // ANAS WAYUU
            'EPSC25', // CAPRESOCA
        ])->update(['vigente' => true]);
    }

    public function down(): void
    {
        Schema::table('clave_accesos', function (Blueprint $table) {
            $table->dropIndex('IX_clave_accesos_entidad');
            $table->dropColumn([
                'entidad_tipo', 'entidad_id', 'sin_portal', 'no_aplica',
                'asesor_nombre', 'asesor_correo', 'asesor_telefono',
                'asesor2_nombre', 'asesor2_correo', 'asesor2_telefono',
            ]);
        });

        Schema::table('eps', function (Blueprint $table) {
            $table->dropColumn('vigente');
        });
    }
};
