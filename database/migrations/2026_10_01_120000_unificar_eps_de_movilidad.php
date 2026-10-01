<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coosalud y Nueva EPS estaban dos veces: la de contributivo y la de movilidad
 * (gente que viene del subsidiado). Son la misma empresa y la planilla pasa con
 * el código de contributivo —en 2026 se pagaron con EPS042 128 personas que la
 * BDUA tiene como ESSC24, y con EPS037 61 que tiene como EPS041—, pero el
 * contrato tomaba la de movilidad cuando el RUAF respondía ese código.
 *
 * La de movilidad no se borra: los planos viejos la referencian por NIT y la
 * corrección N repite el código pagado. Queda marcada con `reemplazada_por_id`,
 * que la saca de las listas y hace que el RUAF la traduzca a la de contributivo.
 */
return new class extends Migration
{
    /** código de movilidad → código de contributivo */
    private const UNIFICAR = [
        'ESSC24' => 'EPS042', // COOSALUD MOVILIDAD → COOSALUD
        'EPS041' => 'EPS037', // NUEVA EPS CM → NUEVA EPS
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('eps', 'reemplazada_por_id')) {
            Schema::table('eps', function (Blueprint $table) {
                $table->unsignedBigInteger('reemplazada_por_id')->nullable();
            });
        }

        foreach (self::UNIFICAR as $movilidad => $contributivo) {
            $viejas = DB::table('eps')->where('codigo', $movilidad)->pluck('id');
            $nueva = DB::table('eps')->where('codigo', $contributivo)->orderBy('id')->value('id');
            if ($viejas->isEmpty() || ! $nueva) {
                continue;
            }

            DB::table('eps')->whereIn('id', $viejas)->update(['reemplazada_por_id' => $nueva]);
            DB::table('contratos')->whereIn('eps_id', $viejas)->update(['eps_id' => $nueva]);
            DB::table('clientes')->whereIn('eps_id', $viejas)->update(['eps_id' => $nueva]);
        }
    }

    public function down(): void
    {
        // Los contratos y clientes no se devuelven: ya no se sabe cuáles eran.
        Schema::table('eps', function (Blueprint $table) {
            $table->dropColumn('reemplazada_por_id');
        });
    }
};
