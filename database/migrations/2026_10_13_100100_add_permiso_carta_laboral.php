<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `contratos.carta_laboral`: generar cartas laborales a nombre de una razón
 * social, con su sello y la firma de quien la suscribe.
 *
 * Es una declaración de la empresa ante terceros (cajas, bancos, embajadas),
 * así que no la trae el rol usuario: la tiene admin y a un trabajador se le da
 * a mano desde Usuarios → Permisos. El catálogo vive en ModulosPermisosSeeder;
 * esta migración solo crea la fila para no re-sembrar producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('name', 'contratos.carta_laboral')->exists()) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'name' => 'contratos.carta_laboral',
            'guard_name' => 'web',
            'modulo_id' => DB::table('modulos')->where('codigo', 'contratos')->value('id'),
            'etiqueta' => 'Generar cartas laborales',
            'accion' => 'carta_laboral',
            'restringido' => false,
            'asignable' => true,
            'orden' => 60,
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
        $id = DB::table('permissions')->where('name', 'contratos.carta_laboral')->value('id');

        if ($id) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
