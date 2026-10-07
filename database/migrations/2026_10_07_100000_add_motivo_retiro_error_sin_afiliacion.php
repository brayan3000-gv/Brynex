<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Motivo de retiro «Error - Sin Afiliación»: la persona se registró por
 * equivocación y no se debe afiliar. Afiliaciones oculta los contratos
 * retirados con este motivo para que nadie los radique.
 *
 * El catálogo de producción ya no es el de la migración que lo creó (lo
 * editaron a mano), así que no se asume si el id es IDENTITY: se toma el
 * siguiente libre y se inserta con IDENTITY_INSERT solo si hace falta.
 */
return new class extends Migration
{
    private const NOMBRE = 'Error - Sin Afiliación';

    public function up(): void
    {
        if (DB::table('motivos_retiro')->where('nombre', self::NOMBRE)->exists()) {
            return;
        }

        $id = (int) DB::table('motivos_retiro')->max('id') + 1;
        $conIdentity = (bool) DB::selectOne(
            "SELECT OBJECTPROPERTY(OBJECT_ID('motivos_retiro'), 'TableHasIdentity') AS si"
        )->si;

        $insert = 'INSERT INTO [motivos_retiro] ([id], [nombre], [es_reingreso], [activo]) VALUES (?, ?, 0, 1);';
        DB::statement(
            $conIdentity
                ? "SET IDENTITY_INSERT [motivos_retiro] ON; {$insert} SET IDENTITY_INSERT [motivos_retiro] OFF;"
                : $insert,
            [$id, self::NOMBRE]
        );
    }

    public function down(): void
    {
        // Solo si ningún contrato lo usa: la FK lo pondría en NULL y se
        // perdería por qué se retiró.
        $id = DB::table('motivos_retiro')->where('nombre', self::NOMBRE)->value('id');
        if ($id && ! DB::table('contratos')->where('motivo_retiro_id', $id)->exists()) {
            DB::table('motivos_retiro')->where('id', $id)->delete();
        }
    }
};
