<?php

/**
 * Un espacio más de la demo pública (App\Services\Demo\EspaciosDemo): clona la
 * configuración del aliado 100 de la plantilla —precios, modalidades ARL,
 * niveles de asesor, plantillas de WhatsApp, módulos— como el aliado ALIADO.
 * La gente la siembra después sembrar.php, con el prefijo del espacio.
 *
 * Lo incluye scripts/videos/demo/sembrar.php con DEMO_PUBLICO=1 y
 * DEMO_ESPACIO=n (n ≥ 1), sobre la plantilla recién restaurada: si el aliado ya
 * existe no toca nada.
 */

use Illuminate\Support\Facades\DB;

if (DB::table('aliados')->where('id', ALIADO)->exists()) {
    fwrite(STDERR, 'El aliado '.ALIADO." ya existe: los espacios se siembran solo sobre la plantilla recién restaurada (reiniciar.sh).\n");
    exit(1);
}

$plantilla = (array) DB::table('aliados')->where('id', 100)->first();
if (! $plantilla) {
    fwrite(STDERR, "Falta el aliado 100 de la plantilla.\n");
    exit(1);
}

DB::transaction(function () use ($plantilla) {
    DB::unprepared('SET IDENTITY_INSERT aliados ON');
    DB::table('aliados')->insert(array_merge($plantilla, [
        'id' => ALIADO, 'nit' => PRE.'00001', 'slug' => 'demo-'.(ALIADO - 100),
        'created_at' => now(), 'updated_at' => now(),
    ]));
    DB::unprepared('SET IDENTITY_INSERT aliados OFF');

    $copiar = function (string $tabla) {
        $ids = [];
        foreach (DB::table($tabla)->where('aliado_id', 100)->orderBy('id')->get() as $fila) {
            $f = (array) $fila;
            $viejo = $f['id'];
            unset($f['id']);
            $f['aliado_id'] = ALIADO;
            $ids[$viejo] = DB::table($tabla)->insertGetId($f);
        }

        return $ids;
    };

    $copiar('configuracion_aliado');
    $copiar('afiliacion_arl_modalidad');
    $copiar('whatsapp_plantillas');
    $copiar('brynex_modulos_aliado');
    foreach ($copiar('asesor_niveles') as $viejo => $nuevo) {
        foreach (DB::table('asesor_nivel_tarifas')->where('asesor_nivel_id', $viejo)->get() as $t) {
            $t = (array) $t;
            unset($t['id']);
            $t['asesor_nivel_id'] = $nuevo;
            DB::table('asesor_nivel_tarifas')->insert($t);
        }
    }
});
echo 'Espacio '.(ALIADO - 100).': aliado '.ALIADO." clonado de la plantilla\n";
