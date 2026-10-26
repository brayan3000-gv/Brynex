<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `anticipos.anular`: anular un anticipo o devolverlo. Deshace plata recibida,
 * así que no lo trae el rol usuario: lo tiene admin y a un trabajador se le da a
 * mano desde Usuarios → Permisos. Reemplaza el hasRole(admin|superadmin) que
 * había en AnticipoController. El catálogo vive en ModulosPermisosSeeder; esta
 * migración solo crea la fila para no re-sembrar producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('name', 'anticipos.anular')->exists()) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'name' => 'anticipos.anular',
            'guard_name' => 'web',
            'modulo_id' => DB::table('modulos')->where('codigo', 'anticipos')->value('id'),
            'etiqueta' => 'Anular y devolver anticipos',
            'accion' => 'anular',
            'restringido' => false,
            'asignable' => true,
            'orden' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        if ($adminId) {
            DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $adminId]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'anticipos.anular')->value('id');

        if ($id) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
