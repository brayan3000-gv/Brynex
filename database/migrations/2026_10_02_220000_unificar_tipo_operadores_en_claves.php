<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Min. Trabajo (PILA)» y «Operadores» eran el mismo tipo de clave: el portal
 * del operador de planilla. Quedan todas como «Operadores» (en pantalla,
 * «Operadores PILA»). Ningún proceso filtraba por «MinTrabajo» ni «OPERADOR».
 *
 * Sin tocar `updated_at`: decide qué clave usan los robots cuando hay varias.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clave_accesos')
            ->whereIn('tipo', ['MinTrabajo', 'OPERADOR', 'OPERADORES'])
            ->update(['tipo' => 'Operadores']);
    }

    public function down(): void
    {
        // No se sabe cuáles eran «MinTrabajo»: el cambio no se deshace.
    }
};
