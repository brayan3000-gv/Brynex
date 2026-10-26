<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Código de ARL Sura para las dos Skandia, que arl:sincronizar-catalogos no
 * empareja: en BryNex figuran con el NIT 800253055 y en Sura con el 800148514,
 * y el nombre tampoco cruza porque Sura lista el fondo obligatorio con su
 * nombre viejo (OLDMUTUAL). Sin código, afiliar a la ARL a quien cotiza en
 * Skandia se bloqueaba (26-oct-2026, Henrry Cano en Confecciones Gavi).
 *
 * Por código PILA, no por id: 230901 es el fondo obligatorio y 230904 el
 * alternativo. Como el comando deja intactas las que no empareja, no lo pisa.
 */
return new class extends Migration
{
    private const CODIGOS = [
        // PILA => [código Sura, dni Sura]
        '230901' => ['009', 'N800148514'],   // OLDMUTUAL
        '230904' => ['030', 'N800148514'],   // SSS FONDO ALTERNATIVO DE PENSIONES SKANDIA
    ];

    public function up(): void
    {
        foreach (self::CODIGOS as $pila => [$codigo, $dni]) {
            DB::table('pensiones')->where('codigo', $pila)->whereNull('codigo_sura')
                ->update(['codigo_sura' => $codigo, 'dni_sura' => $dni]);
        }
    }

    public function down(): void
    {
        DB::table('pensiones')->whereIn('codigo', array_keys(self::CODIGOS))
            ->update(['codigo_sura' => null, 'dni_sura' => null]);
    }
};
