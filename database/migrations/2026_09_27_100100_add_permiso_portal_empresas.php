<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `facturacion.portal_empresas`: crear, restablecer y desactivar el acceso de
 * una empresa a su portal.
 *
 * No lo trae ningún rol: el superadmin de cada aliado lo tiene por el
 * Gate::before, y a otro usuario se le da a mano desde Usuarios → Permisos.
 * El catálogo vive en ModulosPermisosSeeder; esta migración solo crea la fila
 * para no re-sembrar producción, que reconstruye la matriz de todos los roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('name', 'facturacion.portal_empresas')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => 'facturacion.portal_empresas',
            'guard_name' => 'web',
            'modulo_id' => DB::table('modulos')->where('codigo', 'facturacion')->value('id'),
            'etiqueta' => 'Dar acceso al portal a empresas',
            'accion' => 'portal_empresas',
            'restringido' => false,
            'asignable' => true,
            'orden' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'facturacion.portal_empresas')->value('id');

        if ($id) {
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
