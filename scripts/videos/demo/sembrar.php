<?php

/**
 * Siembra el aliado «Seguridad Social Demo» (id 100) con gente INVENTADA:
 * cédulas 9985…, celulares 3995…, correos @example.com, NIT 998500xxx.
 *
 *   scripts/videos/app-videos.sh sembrar      (corre dentro del sandbox: solo localhost)
 *
 * Dos tiempos:
 *   1. Lo de base, directo en la base: usuarios (uno por rol), razones sociales,
 *      empresas, asesores, cuentas, clientes, beneficiarios, página pública.
 *   2. La historia, POR LA APP: contratos, facturas de los meses pasados,
 *      pagos, préstamos, incapacidades, tareas… se crean llamando a las rutas
 *      reales (middleware de permisos y controladores incluidos) con
 *      `Carbon::setTestNow()` en la fecha de cada cosa. Así los valores salen
 *      de la lógica de verdad y no de un cálculo copiado aquí.
 *
 * La única persona real es Yuly Paola Garcia Vidal (CC 1143961945), autorizada
 * por el dueño para mostrar la consulta por cédula: su respuesta del registro
 * oficial es real (guardada en la caché de la app por catalogos.php, NO se
 * consulta en vivo); sus contratos y planillas son inventados, con razón social
 * ficticia, y su celular, correo y dirección también.
 *
 * Las claves de las cuentas van a .env.videos (ignorado por git).
 */

use App\Models\Contrato;
use App\Models\User;
use App\Services\CobroContratoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\InteractsWithSession;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$APP = getenv('VIDEOS_APP') ?: getenv('HOME').'/brynex-videos-app';
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ── Seguro: solo la base de Docker ─────────────────────────────────────────
$c = config('database.connections.sqlsrv');
if ((string) $c['port'] !== '14333' || $c['database'] !== 'BryNex_Videos' || ! in_array($c['host'], ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Esto solo siembra la base de videos (127.0.0.1:14333/BryNex_Videos).\n");
    exit(1);
}

const ALIADO = 100;
const RS_DEP = 998500101;   // razón social de los dependientes (id = NIT)
const RS_IND = 998500102;   // la de los independientes

// La respuesta oficial de Yuly en la caché con la llave que usa
// RegistroOficialService::consultar(): al buscar su cédula, la app la encuentra
// ahí y no sale a preguntarle al operador. `restablecer` la vuelve a poner.
$json = getenv('HOME').'/Movies/Brynex-videos/base/ruaf-yuly.json';
if (is_file($json)) {
    \Illuminate\Support\Facades\Cache::forever('ruaf_'.ALIADO.'_CC_1143961945', json_decode(file_get_contents($json), true));
}
if (in_array('--solo-cache', $argv, true)) {
    exit(0);
}
mt_srand(20261024);

// «Hoy» de la demo es el día en que se siembra (o VIDEOS_HOY=AAAA-MM-DD): todo se
// cuenta desde aquí —«hace 3 días», «el mes pasado»—, porque la app calcula la
// mora, los semáforos y la caja del día contra la fecha real. Se siembra (rehacer)
// el mismo día en que se graba; si no, la demo envejece.
$HOY = Carbon::parse(getenv('VIDEOS_HOY') ?: 'today', 'America/Bogota')->startOfDay();
/** Un día del mes de hace $atras meses, nunca después de ayer. */
$enMes = function (int $atras, int $dia, string $hora = '10:00:00') use ($HOY): string {
    $m = $HOY->copy()->startOfMonth()->subMonthsNoOverflow($atras);
    $d = $m->copy()->day(min($dia, $m->daysInMonth));
    if ($d->gte($HOY)) {
        $d = $HOY->copy()->subDay();
    }
    if ($d->lt($m)) {
        $d = $m->copy();
    }

    return $d->format('Y-m-d').' '.$hora;
};
$haceDias = fn (int $n, string $hora = '10:00:00') => $HOY->copy()->subDays($n)->format('Y-m-d').' '.$hora;
$fechaDe = fn (string $fechaHora) => substr($fechaHora, 0, 10);
$mesDe = function (int $atras) use ($HOY): array {
    $m = $HOY->copy()->startOfMonth()->subMonthsNoOverflow($atras);

    return [(int) $m->month, (int) $m->year];
};
$ahora = fn () => Carbon::now()->format('Y-m-d H:i:s');
$ins = fn (string $t, array $f) => DB::table($t)->insertGetId($f);
$sinId = fn (string $t, array $f) => DB::table($t)->insert($f);

// ── 1. Usuarios: uno por rol ───────────────────────────────────────────────
$roles = DB::table('roles')->pluck('id', 'name');
$cuentas = [
    'SUPERADMIN' => ['9985000001', 'Carolina Andrade Méndez', 'superadmin'],
    'ADMIN' => ['9985000002', 'Julián Esteban Rojas Pardo', 'admin'],
    'USUARIO' => ['9985000003', 'Daniela Restrepo Gómez', 'usuario'],
    'CONTABLE' => ['9985000004', 'Hernán Darío Salazar Vélez', 'contable'],
    'ASESOR' => ['9985000005', 'Valentina Ortiz Cárdenas', 'asesor'],
];
$lineasEnv = [];
$usuarios = [];
foreach ($cuentas as $clave => [$cedula, $nombre, $rol]) {
    $pass = 'Video-'.bin2hex(random_bytes(5));
    $id = $ins('users', [
        'aliado_id' => ALIADO, 'nombre' => $nombre, 'email' => strtolower(explode(' ', $nombre)[0]).'.'.$cedula.'@example.com',
        'password' => Hash::make($pass), 'cedula' => $cedula, 'telefono' => '399500'.substr($cedula, -4), 'es_brynex' => 0, 'activo' => 1,
        'acepto_tratamiento_at' => $ahora(), 'acepto_tratamiento_ip' => '127.0.0.1',
        'acepto_tratamiento_version' => \App\Http\Controllers\AvisoTratamientoController::VERSION,
        'created_at' => '2026-06-01 08:00:00', 'updated_at' => $ahora(),
    ]);
    $sinId('model_has_roles', ['role_id' => $roles[$rol], 'model_type' => User::class, 'model_id' => $id]);
    $usuarios[$clave] = $id;
    $lineasEnv[] = "VIDEOS_{$clave}_CEDULA=$cedula";
    $lineasEnv[] = "VIDEOS_{$clave}_CLAVE=$pass";
}
DB::table('configuracion_aliado')->where('aliado_id', ALIADO)->whereNull('plan_id')->update(['encargado_default_id' => $usuarios['USUARIO']]);

// ── 2. Razones sociales, cajas, cargos, cuentas, asesores ─────────────────
$salarioMinimo = (int) DB::table('configuracion_brynex')->where('clave', 'salario_minimo')->value('valor');
$rsBase = [
    'estado' => 'Activa', 'salario_minimo' => $salarioMinimo, 'arl_nit' => 800256161, 'caja_nit' => 890303208,
    'mes_pagos' => 10, 'anio_pagos' => 2026, 'n_plano' => 1, 'dia_habil' => 9, 'forma_presentacion' => 'S',
    'codigo_sucursal' => '01', 'nombre_sucursal' => 'PRINCIPAL', 'aliado_id' => ALIADO, 'encargado_id' => $usuarios['USUARIO'],
    'tipo_persona' => 'J', 'cod_departamento' => '76', 'cod_municipio' => '001', 'rep_tipo_doc' => 'CC',
    'cedula_rep' => '9985000001', 'nombre_rep' => 'Carolina Andrade Méndez', 'exonerado_parafiscales' => 1,
    'direccion' => 'Calle 5 # 38-25, oficina 301, Cali', 'telefonos' => '6029985000', 'correos' => 'nomina@example.com',
    'actividad_economica' => '8211', 'objeto_social' => 'Servicios administrativos combinados de oficina',
    'fecha_constitucion' => '2019-03-12',
];
$sinId('razones_sociales', $rsBase + ['id' => RS_DEP, 'nit' => RS_DEP, 'dv' => 3, 'razon_social' => 'SERVICIOS INTEGRALES DEMO S.A.S.', 'es_independiente' => 0]);
$sinId('razones_sociales', $rsBase + ['id' => RS_IND, 'nit' => RS_IND, 'dv' => 7, 'razon_social' => 'INDEPENDIENTES DEMO', 'es_independiente' => 1]);
foreach ([RS_DEP, RS_IND] as $rs) {
    $sinId('razon_social_cajas', ['razon_social_id' => $rs, 'departamento_id' => 76, 'caja_id' => 22, 'created_at' => $ahora(), 'updated_at' => $ahora()]);
}
foreach ([['AUXILIAR ADMINISTRATIVO', 1, 4110], ['VENDEDOR', 1, 5242], ['OPERARIO DE CONFECCIÓN', 2, 8263], ['AUXILIAR DE COCINA', 2, 9412],
          ['MAESTRO DE OBRA', 4, 7112], ['CONDUCTOR', 4, 8322], ['MENSAJERO', 3, 9621]] as [$cargo, $nivel, $cod]) {
    $sinId('razon_social_cargos', ['aliado_id' => ALIADO, 'razon_social_id' => RS_DEP, 'cargo' => $cargo, 'nivel_riesgo' => $nivel,
        'codigo_ocupacion' => $cod, 'por_defecto' => $cargo === 'AUXILIAR ADMINISTRATIVO' ? 1 : 0, 'activo' => 1, 'created_at' => $ahora(), 'updated_at' => $ahora()]);
}
$cuentaBanco = $ins('banco_cuentas', ['aliado_id' => ALIADO, 'nombre' => 'Bancolombia ahorros', 'nit' => '998500001', 'banco' => 'BANCOLOMBIA',
    'tipo_cuenta' => 'Ahorros', 'numero_cuenta' => '99850012345', 'activo' => 1, 'cobro' => 1, 'facturacion' => 1, 'incapacidad' => 1,
    'uso_personal' => 0, 'razon_social_id' => RS_DEP, 'created_at' => $ahora(), 'updated_at' => $ahora()]);
$cuentaNequi = $ins('banco_cuentas', ['aliado_id' => ALIADO, 'nombre' => 'Nequi', 'nit' => '998500001', 'banco' => 'NEQUI',
    'tipo_cuenta' => 'Ahorros', 'numero_cuenta' => '3995000000', 'activo' => 1, 'cobro' => 1, 'facturacion' => 1, 'incapacidad' => 0,
    'uso_personal' => 0, 'created_at' => $ahora(), 'updated_at' => $ahora()]);
$nivelAsesor = DB::table('asesor_niveles')->where('aliado_id', ALIADO)->orderBy('orden')->value('id');
$asesores = [];
foreach ([['9985000901', 'Andrés Felipe Molina Ríos'], ['9985000902', 'Lorena Patricia Vargas Díaz']] as [$ced, $nom]) {
    $asesores[] = $ins('asesores', ['aliado_id' => ALIADO, 'cedula' => $ced, 'nombre' => $nom, 'celular' => '399500'.substr($ced, -4),
        'correo' => 'asesor'.substr($ced, -3).'@example.com', 'ciudad' => 'Cali', 'departamento' => 'VALLE DEL CAUCA',
        'activo' => 1, 'nivel_id' => $nivelAsesor, 'fecha_ingreso' => '2026-01-15', 'created_at' => $ahora(), 'updated_at' => $ahora()]);
}

// ── 3. Empresas cliente ────────────────────────────────────────────────────
// La empresa 1 es «Individual», como en producción: la app la trata aparte
// (FacturacionController la excluye con id > 1) y los independientes van ahí.
$sinId('empresas', ['id' => 1, 'empresa' => 'Individual', 'aliado_id' => ALIADO, 'created_at' => '2026-06-01 08:00:00', 'updated_at' => $ahora()]);
$empresas = [];
foreach ([
    ['PANADERÍA LA ESPIGA DORADA S.A.S.', 'Gloria Inés Cárdenas', 'Carrera 44 # 9-31'],
    ['TALLER MECÁNICO EL PISTÓN', 'Wilmer Andrés Ospina', 'Calle 34 # 7-80'],
    ['CONFECCIONES LUCÍA MODA S.A.S.', 'Lucía Fernanda Mejía', 'Carrera 1 # 45-12'],
    ['RESTAURANTE SAZÓN DEL PACÍFICO', 'Yolanda Riascos Mina', 'Avenida 6N # 23-50'],
    ['FERRETERÍA EL TORNILLO FELIZ', 'Óscar Iván Bermúdez', 'Calle 15 # 32-08'],
    ['CONSTRUCCIONES ANDINAS DEMO S.A.S.', 'Ricardo Alberto Lozano', 'Carrera 100 # 11-60'],
] as $i => [$nombre, $contacto, $dir]) {
    $id = $i + 2;
    $sinId('empresas', ['id' => $id, 'nit' => (string) (998500201 + $i), 'empresa' => $nombre, 'nombre_legal' => $nombre, 'contacto' => $contacto,
        'telefono' => '60299850'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'celular' => '39950002'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
        'contacto_celular' => '39950002'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'direccion' => $dir.', Cali',
        'correo' => 'empresa'.($i + 1).'@example.com', 'tipo_facturacion' => 'Recibo de Pago', 'tipo_documento' => 'NIT', 'iva' => 0,
        'aliado_id' => ALIADO, 'asesor_id' => $asesores[$i % 2], 'encargado_id' => $usuarios['USUARIO'], 'departamento_id' => 76, 'municipio_id' => 76001,
        'created_at' => $enMes(4, $i + 1), 'updated_at' => $ahora()]);
    $empresas[] = $id;
}

// ── 4. Clientes inventados (+ Yuly) ────────────────────────────────────────
$nombresF = ['María Fernanda', 'Luz Dary', 'Paola Andrea', 'Ana Milena', 'Diana Marcela', 'Claudia Patricia', 'Yesenia', 'Sandra Liliana',
    'Leidy Johana', 'Angie Carolina', 'Martha Cecilia', 'Natalia', 'Erika Vanessa', 'Gloria Amparo', 'Jessica Lorena', 'Nathaly'];
$nombresM = ['Juan Carlos', 'Luis Fernando', 'Jhon Jairo', 'Carlos Andrés', 'Diego Alejandro', 'Jorge Iván', 'Édgar', 'Wilson',
    'Brayan Stiven', 'Héctor Fabio', 'Kevin Santiago', 'Óscar Eduardo', 'Cristian Camilo', 'Harold', 'Jhonatan', 'Freddy'];
$apellidos = ['Gómez', 'Rodríguez', 'Valencia', 'Mosquera', 'Castillo', 'Zapata', 'Ocampo', 'Hurtado', 'Rentería', 'Quintero',
    'Arboleda', 'Cuero', 'Grueso', 'Montaño', 'Bermúdez', 'Saavedra', 'Lasso', 'Muñoz', 'Velasco', 'Caicedo', 'Ibarguen', 'Cortés'];
$barrios = ['El Vergel', 'Alfonso López', 'Mojica', 'El Poblado II', 'Siloé', 'Ciudad Córdoba', 'San Fernando', 'La Flora', 'Meléndez', 'Calima'];
$epsIds = [2, 5, 9, 12, 18, 59, 60];
$afpIds = [5, 8, 9, 14];
$elegir = fn (array $a) => $a[mt_rand(0, count($a) - 1)];

$clientes = [];
$siguienteId = 1;
$nuevoCliente = function (array $datos) use (&$siguienteId, &$clientes, $sinId) {
    $fila = $datos + ['id' => $siguienteId++, 'aliado_id' => ALIADO, 'tipo_doc' => 'CC', 'departamento_id' => 76, 'municipio_id' => 76001,
        'created_at' => $datos['created_at'] ?? '2026-06-15 10:00:00', 'updated_at' => $datos['created_at'] ?? '2026-06-15 10:00:00'];
    $sinId('clientes', $fila);
    $clientes[] = $fila;

    return $fila;
};
for ($i = 1; $i <= 62; $i++) {
    $mujer = $i % 2 === 0;
    [$n1, $n2] = array_pad(explode(' ', $elegir($mujer ? $nombresF : $nombresM), 2), 2, null);
    $cedula = '99851'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
    $nuevoCliente([
        'cedula' => $cedula, 'primer_nombre' => $n1, 'segundo_nombre' => $n2, 'primer_apellido' => $elegir($apellidos), 'segundo_apellido' => $elegir($apellidos),
        'genero' => $mujer ? 'F' : 'M', 'fecha_nacimiento' => sprintf('%d-%02d-%02d', mt_rand(1965, 2004), mt_rand(1, 12), mt_rand(1, 28)),
        'celular' => '39951'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'correo' => 'persona'.$i.'@example.com',
        'direccion_vivienda' => 'Calle '.mt_rand(1, 80).' # '.mt_rand(1, 99).'-'.mt_rand(1, 99), 'barrio' => $elegir($barrios),
        'eps_id' => $elegir($epsIds), 'pension_id' => $elegir($afpIds),
        'cod_empresa' => $i <= 36 ? $empresas[($i - 1) % 6] : 1,
        'created_at' => $enMes(mt_rand(4, 5), mt_rand(1, 28), '09:30:00'),
    ]);
}
// Yuly: nombre, cédula, EPS y fondo reales (de su consulta del registro oficial); lo demás, inventado.
$ruafYuly = json_decode((string) @file_get_contents(getenv('HOME').'/Movies/Brynex-videos/base/ruaf-yuly.json'), true);
$yuly = $nuevoCliente([
    'cedula' => '1143961945', 'primer_nombre' => 'YULY', 'segundo_nombre' => 'PAOLA', 'primer_apellido' => 'GARCIA', 'segundo_apellido' => 'VIDAL',
    'genero' => 'F', 'celular' => '3995199999', 'correo' => 'yuly.demo@example.com', 'direccion_vivienda' => 'Carrera 50 # 10-20',
    'barrio' => 'El Vergel', 'eps_id' => $ruafYuly['eps_id'] ?? 2, 'pension_id' => $ruafYuly['pension_id'] ?? 9, 'cod_empresa' => 4,
    'created_at' => $enMes(3, 1, '09:00:00'),
]);
foreach (array_slice($clientes, 0, 10) as $k => $cl) {
    $sinId('beneficiarios', ['aliado_id' => ALIADO, 'cc_cliente' => $cl['cedula'], 'tipo_doc' => $k % 3 ? 'RC' : 'TI',
        'n_documento' => '99859'.str_pad((string) ($k + 1), 5, '0', STR_PAD_LEFT), 'nombres' => $elegir($nombresF).' '.$cl['primer_apellido'],
        'fecha_nacimiento' => sprintf('20%02d-%02d-%02d', mt_rand(12, 22), mt_rand(1, 12), mt_rand(1, 28)), 'parentesco' => 'HIJO(A)',
        'fecha_ingreso' => $fechaDe($enMes(3, 1)), 'created_at' => $ahora(), 'updated_at' => $ahora()]);
}

// ── 5. Página pública del aliado ───────────────────────────────────────────
if (DB::getSchemaBuilder()->hasTable('pagina_aliado_config')) {
    $sinId('pagina_aliado_config', ['aliado_id' => ALIADO, 'activo' => 1, 'hero_titulo' => 'Tu seguridad social, al día y sin filas',
        'hero_subtitulo' => 'Afiliamos a independientes y empresas a salud, pensión, ARL y caja. Cotiza en línea en un minuto.',
        'hero_cta_texto' => 'Arma tu plan', 'seo_titulo' => 'Seguridad Social Demo', 'seo_descripcion' => 'Aliado de demostración de BryNex',
        'mostrar_precios' => 1, 'precios_modo' => 'desde', 'whatsapp_mensaje_base' => 'Hola, quiero información', 'created_at' => $ahora(), 'updated_at' => $ahora()]);
    foreach ([['¿Cuánto tarda la afiliación?', 'La afiliación a la ARL queda activa desde el día siguiente; la EPS, según la entidad.'],
              ['¿Puedo pagar por Nequi?', 'Sí. También en efectivo en la oficina o por consignación.'],
              ['¿Qué necesito para afiliarme?', 'Tu cédula y, si eres independiente, tu actividad económica.']] as $k => [$p, $r]) {
        $sinId('pagina_faqs', ['aliado_id' => ALIADO, 'pregunta' => $p, 'respuesta' => $r, 'orden' => $k + 1, 'activo' => 1, 'created_at' => $ahora(), 'updated_at' => $ahora()]);
    }
}
echo "Base: ".count($usuarios)." cuentas, 2 razones sociales, ".count($empresas)." empresas, ".count($clientes)." clientes\n";

// ═══ La historia, por la app ════════════════════════════════════════════════
$nav = new class($app)
{
    use InteractsWithAuthentication, InteractsWithSession, MakesHttpRequests;

    public function __construct(public $app) {}
};
$nav->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
$nav->actingAs(User::find($usuarios['ADMIN']));
$errores = 0;
$llamar = function (string $metodo, string $ruta, array $datos = [], ?string $cuando = null) use ($nav, &$errores) {
    if ($cuando) {
        Carbon::setTestNow(Carbon::parse($cuando, 'America/Bogota'));
    }
    $r = $metodo === 'GET' ? $nav->getJson($ruta) : $nav->call($metodo, $ruta, $datos, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $codigo = $r->getStatusCode();
    if ($codigo >= 400) {
        $errores++;
        fwrite(STDERR, "  ✗ $metodo $ruta → $codigo ".mb_substr(strip_tags((string) $r->getContent()), 0, 300)."\n");
    }

    return $r;
};

// ── 6. Contratos (por el formulario real) ─────────────────────────────────
$planes = [['plan' => 6, 'mod' => 0, 'arl' => 1], ['plan' => 5, 'mod' => 0, 'arl' => 2], ['plan' => 6, 'mod' => 0, 'arl' => 4]];
$contratos = [];
foreach ($clientes as $k => $cl) {
    $esYuly = $cl['cedula'] === '1143961945';
    if (! $esYuly && $k >= 36 && $k < 52) {
        $tipo = ['plan' => 5, 'mod' => 10, 'arl' => 1, 'indep' => true];           // independientes
    } elseif (! $esYuly && $k >= 52 && $k < 57) {
        $tipo = ['plan' => 2, 'mod' => 15, 'arl' => mt_rand(1, 3), 'indep' => true];   // gestión ARL
    } elseif (! $esYuly && $k >= 57) {
        $tipo = ['plan' => 6, 'mod' => mt_rand(1, 2), 'arl' => 1];                       // tiempo parcial
    } else {
        $tipo = $planes[$k % 3];
    }
    $indep = ! empty($tipo['indep']);
    // Meses atrás del ingreso: 0 = este mes (sale en Afiliaciones), 1 = el pasado, 2–4 = antes.
    $atrasIngreso = $k % 7 === 3 ? 0 : ($k % 5 === 0 ? 1 : mt_rand(2, 4));
    $ingreso = $fechaDe($esYuly ? $enMes(3, 1) : $enMes($atrasIngreso, mt_rand(1, $atrasIngreso === 0 ? 21 : 20)));
    $asesor = $k % 4 === 0 ? $asesores[$k % 2] : null;
    $salario = $indep ? 0 : ($k % 3 === 0 ? $salarioMinimo : $salarioMinimo + 100000 * mt_rand(1, 18));
    $ibc = $indep ? max($salarioMinimo, 100000 * mt_rand(15, 40)) : $salario;

    $tarifas = $llamar('GET', route('admin.contratos.tarifas', ['plan_id' => $tipo['plan'], 'tipo_modalidad_id' => $tipo['mod'],
        'n_arl' => $tipo['arl'], 'asesor_id' => $asesor, 'cedula' => $cl['cedula']]), [], Carbon::parse($ingreso)->subDays(2)->setTime(10, 15)->toDateTimeString())->json() ?? [];
    $r = $llamar('POST', route('admin.contratos.store'), array_filter([
        'cedula' => $cl['cedula'], 'razon_social_id' => $indep ? RS_IND : RS_DEP, 'plan_id' => $tipo['plan'], 'tipo_modalidad_id' => $tipo['mod'],
        'eps_id' => $cl['eps_id'], 'pension_id' => in_array($tipo['plan'], [5, 6, 7, 8, 9, 11, 16]) ? $cl['pension_id'] : null,
        'arl_id' => 3, 'n_arl' => $tipo['arl'], 'arl_modo' => $indep ? 'independiente' : 'razon_social',
        'caja_id' => in_array($tipo['plan'], [4, 6, 8, 11, 13]) ? 22 : null,
        'cargo' => $indep ? 'INDEPENDIENTE' : ['AUXILIAR ADMINISTRATIVO', 'VENDEDOR', 'OPERARIO DE CONFECCIÓN', 'AUXILIAR DE COCINA', 'CONDUCTOR'][$k % 5],
        'fecha_ingreso' => $ingreso, 'fecha_arl' => $ingreso, 'salario' => $salario ?: $ibc, 'ibc' => $ibc,
        'administracion' => $tarifas['administracion'] ?? 46000, 'admon_asesor' => $tarifas['admon_asesor'] ?? 0,
        'costo_afiliacion' => $tarifas['costo_afiliacion'] ?? 0, 'afiliacion_asesor' => $tarifas['afiliacion_asesor'] ?? null,
        'asesor_id' => $asesor, 'encargado_id' => $nav->app['auth']->id(), 'motivo_afiliacion_id' => 1, 'envio_planilla' => 'WhatsApp',
        'actividad_economica_id' => $indep ? 1 : null,
    ], fn ($v) => $v !== null), Carbon::parse($ingreso)->subDays(2)->setTime(10, 20)->toDateTimeString());
    $id = Contrato::where('aliado_id', ALIADO)->where('cedula', $cl['cedula'])->latest('id')->value('id');
    if ($id) {
        $contratos[] = ['id' => $id, 'cedula' => $cl['cedula'], 'empresa' => $cl['cod_empresa'], 'ingreso' => $ingreso, 'indep' => $indep];
    }
}
echo 'Contratos: '.count($contratos)."\n";

// ── 7. Facturación de los dos meses pasados y del actual (por la app) ──────
$facturar = function (array $ids, int $mes, int $anio, string $cuando, ?int $empresa = null, string $forma = 'efectivo') use ($llamar, $cuentaBanco) {
    // El total se calcula en la fecha de la factura: la mora y el mes dependen de «hoy».
    Carbon::setTestNow(Carbon::parse($cuando, 'America/Bogota'));
    // Un lote con afiliaciones y planillas mezcladas se factura en dos: el tipo es por factura.
    $grupos = [];
    foreach ($ids as $id) {
        $calc = CobroContratoService::calcular(Contrato::find($id), $mes, $anio);
        $tipo = $calc['tipo'] ?? 'planilla';
        $grupos[$tipo]['ids'][] = $id;
        $grupos[$tipo]['total'] = ($grupos[$tipo]['total'] ?? 0) + (int) ($calc['total'] ?? 0);
    }
    foreach ($grupos as $tipo => ['ids' => $grupo, 'total' => $total]) {
        $pago = $forma === 'consignacion'
            ? ['forma_pago' => 'consignacion', 'consignaciones' => [['banco_cuenta_id' => $cuentaBanco, 'valor' => $total, 'fecha' => substr($cuando, 0, 10), 'referencia' => 'TR'.mt_rand(100000, 999999)]]]
            : ['forma_pago' => 'efectivo', 'valor_efectivo' => $total];
        $llamar('POST', route('admin.facturacion.facturar'), array_filter([
            'contratos' => $grupo, 'tipo' => $tipo, 'mes' => $mes, 'anio' => $anio, 'estado' => 'pagada', 'empresa_id' => $empresa,
        ]) + $pago, $cuando);
    }
};
foreach ([2, 1, 0] as $atras) {
    [$mes, $anio] = $mesDe($atras);
    $fin = Carbon::create($anio, $mes, 1)->endOfMonth();
    $vigentes = array_filter($contratos, fn ($c) => Carbon::parse($c['ingreso'])->lte($fin));
    // Empresas: un lote por empresa. Este mes, dos empresas quedan sin pagar (cartera).
    foreach ($empresas as $e) {
        $ids = array_column(array_filter($vigentes, fn ($c) => (int) $c['empresa'] === $e), 'id');
        if (! $ids || ($atras === 0 && in_array($e, [3, 6]))) {
            continue;
        }
        // El lote se paga después del último ingreso del mes en esa empresa.
        $ultimo = max(array_map(fn ($c) => (int) substr($c['ingreso'], 5, 2) === $mes ? (int) substr($c['ingreso'], 8, 2) : 0,
            array_filter($vigentes, fn ($c) => (int) $c['empresa'] === $e)));
        $facturar($ids, $mes, $anio, $enMes($atras, max(2 + $e, $ultimo + 1), sprintf('11:%02d:00', 10 * ($e % 5))), $e, $e % 2 ? 'consignacion' : 'efectivo');
    }
    // Independientes y demás: uno por uno. Este mes, un tercio queda pendiente.
    foreach (array_values(array_filter($vigentes, fn ($c) => (int) $c['empresa'] <= 1)) as $k => $c) {
        if ($atras === 0 && $k % 3 === 0) {
            continue;
        }
        $dia = max((int) substr($c['ingreso'], 8, 2) * (int) ((int) substr($c['ingreso'], 5, 2) === $mes), min(28, 2 + $k));
        $facturar([$c['id']], $mes, $anio, $enMes($atras, $dia, sprintf('%02d:%02d:00', 8 + $k % 9, ($k * 7) % 60)), null, $k % 4 ? 'efectivo' : 'consignacion');
    }
}
echo 'Facturas: '.DB::table('facturas')->where('aliado_id', ALIADO)->count().', planos: '.DB::table('planos')->where('aliado_id', ALIADO)->count()."\n";

// ── 8. Préstamos: dos independientes pagan este mes «fiado» ────────────────
[$mesHoy, $anioHoy] = $mesDe(0);
$individuales = array_values(array_filter($contratos, fn ($c) => (int) $c['empresa'] <= 1 && Carbon::parse($c['ingreso'])->lt($HOY->copy()->startOfMonth())));
foreach ([0, 3] as $k) {
    $c = $individuales[$k];
    if (DB::table('facturas')->where('aliado_id', ALIADO)->where('cedula', $c['cedula'])->where('mes', $mesHoy)->where('anio', $anioHoy)->exists()) {
        continue;
    }
    $cuando = $enMes(0, 5 + $k, '15:10:00');
    Carbon::setTestNow(Carbon::parse($cuando, 'America/Bogota'));
    $calc = CobroContratoService::calcular(Contrato::find($c['id']), $mesHoy, $anioHoy);
    $llamar('POST', route('admin.facturacion.facturar'), ['contratos' => [$c['id']], 'tipo' => $calc['tipo'] ?? 'planilla', 'mes' => $mesHoy, 'anio' => $anioHoy,
        'forma_pago' => 'prestamo', 'estado' => 'prestamo', 'es_prestamo' => 1, 'valor_prestamo' => (int) $calc['total']], $cuando);
}

// ── 9. Anticipos: plata que llegó antes de la factura ─────────────────────
foreach (array_slice($individuales, 6, 2) as $k => $c) {
    $llamar('POST', route('admin.anticipos.store'), ['contrato_id' => $c['id'], 'fecha_pago' => $fechaDe($haceDias(9 - $k)), 'valor' => 150000 + 50000 * $k,
        'forma_pago' => $k ? 'transferencia' : 'efectivo', 'banco_cuenta_id' => $k ? $cuentaNequi : null, 'origen' => 'individual',
        'observacion' => 'Abono para el próximo mes'], $haceDias(9 - $k, '10:30:00'));
}

// ── 10. Radicados: el trámite de cada afiliación, en distintos estados ──────
foreach (DB::table('radicados as r')->join('contratos as c', 'c.id', '=', 'r.contrato_id')->where('c.aliado_id', ALIADO)
    ->select('r.id', 'r.tipo', 'c.fecha_ingreso')->orderBy('r.id')->get() as $k => $r) {
    $ingreso = Carbon::parse($r->fecha_ingreso);
    $atras = ($HOY->year - $ingreso->year) * 12 + $HOY->month - $ingreso->month;
    if ($atras >= 2) {
        $estado = 'confirmado';
    } elseif ($atras === 1) {
        $estado = $k % 3 ? 'confirmado' : 'en_tramite';
    } else {
        $estado = ['pendiente', 'en_tramite', 'pendiente'][$k % 3];
    }
    if ($estado === 'pendiente') {
        continue;
    }
    // Estados de RadicadoController: pendiente, tramite, traslado, error, ok.
    $llamar('PATCH', route('admin.radicados.update', $r->id), ['estado' => 'tramite', 'canal_envio' => $r->tipo === 'arl' ? 'web' : 'correo',
        'observacion' => 'Enviado a la entidad.'], $ingreso->copy()->subDay()->setTime(14, 0)->toDateTimeString());
    if ($estado === 'confirmado') {
        $llamar('PATCH', route('admin.radicados.update', $r->id), ['estado' => 'ok', 'numero_radicado' => strtoupper($r->tipo).'-'.(80000 + $r->id),
            'observacion' => 'Confirmado por la entidad.'], $ingreso->copy()->addDays(2)->setTime(9, 30)->toDateTimeString());
    }
}

// ── 11. Incapacidades, con su trámite ante la EPS ─────────────────────────
$dependientes = array_values(array_filter($contratos, fn ($c) => (int) $c['empresa'] > 1 && Carbon::parse($c['ingreso'])->lt($HOY->copy()->startOfMonth()->subMonthNoOverflow())));
$incapacidades = [
    // [contrato, tipo, días, inicio (hace N días), recibido (hace N días), [estados que va tomando]]
    [0, 'enfermedad_general', 3, 52, 51, ['radicada', 'en_liquidacion', 'pagada_razon_social']],
    [2, 'enfermedad_general', 8, 39, 38, ['radicada', 'en_liquidacion']],
    [4, 'accidente_laboral', 12, 23, 22, ['radicada']],
    [6, 'licencia_maternidad', 126, 65, 64, ['radicada', 'en_liquidacion', 'pagada_razon_social']],
    [8, 'enfermedad_general', 2, 10, 9, []],
    [10, 'enfermedad_general', 5, 4, 3, []],
];
foreach ($incapacidades as [$i, $tipo, $dias, $haceInicio, $haceRecibido, $estados]) {
    $c = $dependientes[$i] ?? null;
    if (! $c) {
        continue;
    }
    $inicio = $fechaDe($haceDias($haceInicio));
    $recibido = $fechaDe($haceDias($haceRecibido));
    $contrato = Contrato::find($c['id']);
    $llamar('POST', route('admin.incapacidades.store'), ['cedula_usuario' => $c['cedula'], 'contrato_id' => $c['id'], 'tipo_incapacidad' => $tipo,
        'tipo_entidad' => $tipo === 'accidente_laboral' ? 'arl' : 'eps', 'dias_incapacidad' => $dias, 'fecha_inicio' => $inicio,
        'fecha_terminacion' => Carbon::parse($inicio)->addDays($dias - 1)->toDateString(), 'fecha_recibido' => $recibido,
        'quien_recibe_id' => $usuarios['USUARIO'], 'quien_remite' => 'El trabajador', 'razon_social_id' => RS_DEP,
        'entidad_responsable_id' => $tipo === 'accidente_laboral' ? $contrato->arl_id : $contrato->eps_id,
        'diagnostico' => ['J06.9 Infección respiratoria aguda', 'M54.5 Lumbago', 'S62.6 Fractura de dedo de la mano', 'O80 Parto', 'A09 Gastroenteritis', 'J11 Influenza'][$i / 2 % 6],
        'descripcion_cliente' => 'Incapacidad entregada en la oficina'], "$recibido 10:00:00");
    $inc = DB::table('incapacidades')->where('aliado_id', ALIADO)->where('cedula_usuario', $c['cedula'])->latest('id')->value('id');
    foreach ($estados as $n => $estado) {
        $llamar('POST', route('admin.incapacidades.gestion.store', $inc), ['tipo' => $n ? 'portal' : 'correo', 'estado_nuevo' => $estado,
            'tramite' => ['radicada' => 'Se radicó en el portal de la EPS con la epicrisis.', 'en_liquidacion' => 'La EPS confirmó que la está liquidando.',
                'pagada_razon_social' => 'La EPS consignó a la razón social.'][$estado],
            'numero_radicado' => $estado === 'radicada' ? 'INC-'.(500200 + $inc) : null], Carbon::parse($recibido)->addDays(3 + 6 * $n)->setTime(11, 0)->toDateTimeString());
    }
}

// ── 12. Tareas del equipo ──────────────────────────────────────────────────
$tareas = [
    ['traslado_eps', 'Trasladar de EMSSANAR a SURA: ya firmó el formulario.', 'pendiente'],
    ['inclusion_beneficiarios', 'Incluir a la hija como beneficiaria en la EPS. Falta registro civil.', 'en_gestion'],
    ['actualizar_documentos', 'Pedir copia de la cédula ampliada al 150 %.', 'pendiente'],
    ['subsidios', 'Postular a subsidio de caja de compensación.', 'en_gestion'],
    ['otros', 'Llamar para confirmar el cambio de salario desde el próximo mes.', 'pendiente'],
    ['traslado_eps', 'Traslado a NUEVA EPS radicado; esperar respuesta en 15 días.', 'cerrada'],
    ['mora_eps', 'Revisar la mora que reportó la EPS el mes pasado.', 'pendiente'],
];
foreach ($tareas as $k => [$tipo, $texto, $estado]) {
    $c = $contratos[($k * 5) % count($contratos)];
    $cuando = $haceDias(18 - 2 * $k, sprintf('09:%02d:00', 10 + $k));
    $llamar('POST', route('admin.tareas.store'), ['tipo' => $tipo, 'cedula' => $c['cedula'], 'contrato_id' => $c['id'], 'tarea' => $texto,
        'encargado_id' => $k % 3 ? $usuarios['USUARIO'] : $usuarios['ADMIN'], 'fecha_limite' => Carbon::parse($cuando)->addDays(8)->toDateString()], $cuando);
    $id = DB::table('tareas')->where('aliado_id', ALIADO)->latest('id')->value('id');
    if ($estado !== 'pendiente') {
        $llamar('POST', route('admin.tareas.gestion', $id), ['tipo_accion' => 'tramite_realizado', 'observacion' => 'Se habló con el afiliado: queda en enviar el documento.'],
            Carbon::parse($cuando)->addDays(2)->toDateTimeString());
    }
    if ($estado === 'cerrada') {
        $llamar('PATCH', route('admin.tareas.cerrar', $id), ['observacion' => 'La EPS aprobó el traslado.', 'resultado' => 'positivo'], Carbon::parse($cuando)->addDays(5)->toDateTimeString());
    }
}

// ── 13. Prospectos (cotizaciones) ──────────────────────────────────────────
foreach ([['Sebastián Arango Pérez', '3995300001', 'sin_respuesta', 'whatsapp'], ['Marcela Ruiz Tobón', '3995300002', 'interesado', 'whatsapp'],
          ['Óscar Daniel Lemos', '3995300003', 'interesado', 'referido'], ['Lina María Holguín', '3995300004', 'no_interesado', 'llamada'],
          ['Rodrigo Andrés Peña', '3995300005', 'sin_respuesta', 'pagina_web']] as $k => [$nombre, $cel, $estado, $canal]) {
    $llamar('POST', route('admin.cotizaciones.store'), ['tipo' => 'persona', 'nombre_completo' => $nombre, 'celular' => $cel, 'correo' => 'prospecto'.($k + 1).'@example.com',
        'es_independiente' => 1, 'municipio_id' => 76001, 'canal_origen' => $canal, 'modalidad_id' => 10, 'plan_id' => 5, 'salario_base' => $salarioMinimo,
        'n_arl' => 1, 'estado' => $estado, 'proxima_llamada' => $HOY->copy()->addDays(1 + $k % 3)->toDateString(),
        'fecha_cotizacion' => $fechaDe($haceDias(14 - 2 * $k))], $haceDias(14 - 2 * $k, '16:00:00'));
}

// ── 14. WhatsApp: conversaciones inventadas (sin enviar nada) ──────────────
// El inbox se llena por el webhook de Meta; aquí se escriben directo, como si
// hubieran llegado. Ningún mensaje sale: no hay token ni red.
foreach ([
    [$clientes[4], [['in', 'Buenos días, ¿ya salió mi planilla de este mes?'], ['out', '¡Buenos días! Sí, ya está pagada. Te la envío por aquí en PDF.'], ['in', 'Muchas gracias 🙏']]],
    [$clientes[40], [['in', 'Hola, quiero afiliar a mi esposo como independiente'], ['out', 'Con gusto. ¿Me regalas su cédula y su actividad?']]],
    [$clientes[12], [['in', 'Me dieron incapacidad de 3 días, ¿qué hago?'], ['out', 'Mándanos la foto de la incapacidad y la epicrisis por aquí y la radicamos.'], ['in', '[Imagen]']]],
    [null, [['in', 'Hola, ¿cuánto cuesta la afiliación a ARL riesgo 1?']]],
] as $k => [$cl, $mensajes]) {
    $tel = $cl ? '57'.$cl['celular'] : '573995300010';
    $hora = Carbon::parse($haceDias(3 - $k, '08:'.(10 + 7 * $k).':00'), 'America/Bogota');
    $conv = $ins('whatsapp_conversaciones', ['aliado_id' => ALIADO, 'wa_contact_id' => $tel,
        'nombre_contacto' => $cl ? $cl['primer_nombre'].' '.$cl['primer_apellido'] : 'Prospecto web', 'estado' => $k % 2 ? 'asignada' : 'abierta',
        'asignado_a' => $k % 2 ? $usuarios['USUARIO'] : null, 'ultimo_mensaje_at' => $hora->copy()->addMinutes(count($mensajes) * 3),
        'ventana_activa_hasta' => $hora->copy()->addDay(), 'total_mensajes_no_leidos' => end($mensajes)[0] === 'in' ? 1 : 0,
        'bot_activo' => 0, 'created_at' => $hora, 'updated_at' => $hora]);
    foreach ($mensajes as $n => [$dir, $texto]) {
        $ins('whatsapp_mensajes', ['conversacion_id' => $conv, 'aliado_id' => ALIADO, 'wa_message_id' => 'demo-'.$conv.'-'.$n,
            'direccion' => $dir === 'in' ? 'entrante' : 'saliente', 'tipo' => 'text', 'contenido' => $texto, 'estado' => $dir === 'in' ? null : 'leido',
            'usuario_id' => $dir === 'out' ? $usuarios['USUARIO'] : null, 'es_bot' => 0,
            'created_at' => $hora->copy()->addMinutes(3 * $n), 'updated_at' => $hora->copy()->addMinutes(3 * $n)]);
    }
}

// ── 15. Acceso de una empresa al portal (Confecciones Lucía) ──────────────
$claveEmpresa = 'Empresa-'.bin2hex(random_bytes(4));
$ins('empresa_accesos', ['aliado_id' => ALIADO, 'empresa_id' => 4, 'usuario' => '998500203', 'password' => Hash::make($claveEmpresa), 'activo' => 1,
    'ver_discriminado' => 1, 'debe_cambiar_clave' => 0, 'creado_por' => $usuarios['SUPERADMIN'], 'created_at' => $enMes(2, 1), 'updated_at' => $ahora()]);
$lineasEnv[] = 'VIDEOS_EMPRESA_CEDULA=998500203';
$lineasEnv[] = "VIDEOS_EMPRESA_CLAVE=$claveEmpresa";

// ── 15b. Gastos administrativos del aliado (arriendo, servicios, nómina) ────
// GastoAdminController solo deja registrar al superadmin (aunque el rol admin
// traiga gastos.gestionar): se siembran con la cuenta de Carolina.
$nav->actingAs(User::find($usuarios['SUPERADMIN']));
foreach ([2, 1, 0] as $atras) {
    [$mesG, $anioG] = $mesDe($atras);
    // Montos de una oficina pequeña: que la utilidad del mes no salga negativa en la demo.
    foreach ([['arriendo', 'Arriendo de la oficina', 'Inmobiliaria Demo', 650000, 5], ['servicios', 'Energía, agua e internet', 'Empresas de servicios', 185000, 12],
              ['salarios', 'Nómina de la quincena', 'Equipo de la oficina', 1100000, 15], ['papeleria', 'Papelería y tinta', 'Papelería El Centro', 62000, 20]] as [$tipoG, $desc, $a, $valor, $dia]) {
        $cuando = $enMes($atras, $dia, '16:00:00');
        $llamar('POST', route('admin.informes.gastos.store'), ['fecha' => $fechaDe($cuando), 'periodo' => sprintf('%04d-%02d', $anioG, $mesG), 'tipo' => $tipoG,
            'descripcion' => $desc, 'pagado_a' => $a, 'forma_pago' => 'transferencia_bancaria', 'banco_origen_id' => $cuentaBanco, 'valor' => $valor], $cuando);
    }
}

$nav->actingAs(User::find($usuarios['ADMIN']));

// ── 16. El efectivo de cada día se entrega al cierre (a la jefa, superadmin) ──
// Sin esto, la caja del día «traería de antes» todo el efectivo de tres meses.
foreach (DB::table('facturas')->where('aliado_id', ALIADO)->where('valor_efectivo', '>', 0)
    ->selectRaw('CAST(created_at AS date) dia, SUM(valor_efectivo) total')->groupByRaw('CAST(created_at AS date)')->get() as $d) {
    $llamar('POST', route('admin.cuadre-diario.movimiento-caja.store'), ['tipo' => 'entrega', 'fecha' => $d->dia, 'valor' => (int) $d->total,
        'usuario_origen_id' => $usuarios['ADMIN'], 'usuario_destino_id' => $usuarios['SUPERADMIN'], 'observacion' => 'Entrega del efectivo del día'],
        $d->dia.' 18:30:00');
}

// ── 17. Hoy: Daniela ya cobró a tres personas en la oficina ────────────────
$nav->actingAs(User::find($usuarios['USUARIO']));
$pendientes = array_values(array_filter($individuales, fn ($c) => ! DB::table('facturas')->where('aliado_id', ALIADO)
    ->where('cedula', $c['cedula'])->where('mes', $mesHoy)->where('anio', $anioHoy)->exists()));
foreach (array_slice($pendientes, 0, 3) as $k => $c) {
    $facturar([$c['id']], $mesHoy, $anioHoy, $HOY->format('Y-m-d').sprintf(' %02d:%02d:00', 8 + $k, 15 + 12 * $k), null, $k === 1 ? 'consignacion' : 'efectivo');
}

Carbon::setTestNow();

// ── Cuentas a .env.videos ──────────────────────────────────────────────────
$archivo = getenv('VIDEOS_ENV');
$previo = array_filter(file($archivo, FILE_IGNORE_NEW_LINES) ?: [], fn ($l) => ! preg_match('/^VIDEOS_(SUPERADMIN|ADMIN|USUARIO|CONTABLE|ASESOR|EMPRESA)_/', $l));
file_put_contents($archivo, implode("\n", array_merge($previo, $lineasEnv))."\n");
echo $errores ? "Terminó con $errores errores (ver arriba).\n" : "Sembrado sin errores.\n";
exit($errores ? 1 : 0);
