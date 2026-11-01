<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `informes.cierre_saldos`: anotar con qué arranca cada cuenta y cada caja el
 * mes (el saldo del extracto o el efectivo contado al cierre del anterior).
 * Lo tiene el superadmin de cada aliado por el Gate::before; a un admin se le
 * da a mano desde Usuarios → Permisos. Reemplaza el hasRole(superadmin) que
 * había en InformeController::cierreSaldos. El catálogo vive en
 * ModulosPermisosSeeder; esta migración solo crea la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('name', 'informes.cierre_saldos')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => 'informes.cierre_saldos',
            'guard_name' => 'web',
            'modulo_id' => DB::table('modulos')->where('codigo', 'informes')->value('id'),
            'etiqueta' => 'Anotar saldos de arranque del mes',
            'accion' => 'cierre_saldos',
            'restringido' => false,
            'asignable' => true,
            'orden' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'informes.cierre_saldos')->value('id');

        if ($id) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
