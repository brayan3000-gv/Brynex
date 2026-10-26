<?php

/**
 * Copia los CATÁLOGOS de producción a la base de los videos: entidades (EPS,
 * ARL, AFP, cajas), ciudades, modalidades, planes, tarifas, motivos,
 * operadores, módulos, roles y permisos. Ni una persona, ni una clave.
 *
 *   DYLD_LIBRARY_PATH=/opt/homebrew/opt/openssl@3/lib php scripts/videos/demo/catalogos.php
 *
 * Lista BLANCA a propósito: una tabla nueva no se copia hasta que alguien la
 * revise y la agregue aquí. De producción solo se lee (por el túnel, con el
 * .env del repo); se escribe únicamente en BryNex_Videos (127.0.0.1:14333).
 */
const CATALOGOS = [
    // tabla => filtro (WHERE) o null para copiarla entera
    'departamentos' => null,
    'ciudades' => null,
    'ciudades2' => null,
    'actividades_economicas' => null,
    'eps' => null,
    'eps_ocupaciones' => null,
    'arls' => null,
    'pensiones' => null,
    'cajas' => null,
    'planes_contrato' => null,
    'tipo_modalidad' => null,
    'modalidad_planes' => null,
    'motivos_afiliacion' => null,
    'motivos_retiro' => null,
    'upc_adicional_tarifas' => null,
    // Las globales (sin aliado); las de cada aliado no.
    'arl_tarifas' => 'aliado_id IS NULL',
    'operadores_planilla' => 'aliado_id IS NULL',
    'operador_planillas_templates' => null,
    'tarea_semaforo_config' => 'aliado_id IS NULL',
    'modulos' => null,
    'permissions' => null,
    'roles' => null,
    'role_has_permissions' => null,
    'brynex_modulos' => null,
    'migrations' => null,
    // Solo parámetros: los tokens de WhatsApp, IA y Dataico NO.
    'configuracion_brynex' => "clave IN ('salario_minimo','porcentaje_iva','tasa_mora_pila','regla_afp_obligatorio',
        'pct_caja_dependiente','pct_caja_independiente_alto','pct_caja_independiente_bajo','pct_ibc_independiente_sugerido',
        'pct_pension_dependiente','pct_pension_independiente','pct_salud_dependiente','pct_salud_independiente',
        'ia_proveedor_default','ia_modelo_claude_default','ia_modelo_gemini_default','ia_modelo_openai_default')",
];

function env_de(string $archivo, string $patron): array
{
    $v = [];
    foreach (file($archivo) as $l) {
        if (preg_match($patron, trim($l), $m)) {
            $v[$m[1]] = trim($m[2], "\"'");
        }
    }
    return $v;
}
$raiz = __DIR__.'/../../..';
$p = env_de("$raiz/.env", '/^(DB_[A-Z_]+)=(.*)$/');
$v = env_de("$raiz/.env.videos", '/^(VIDEOS_SQL_SA)=(.*)$/');
$opc = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
// Lo leído de producción queda en una copia local (fuera del repo): los siguientes
// `rehacer` no tocan producción ni dependen del túnel. --refrescar la vuelve a leer.
$copiaLocal = getenv('HOME').'/Movies/Brynex-videos/base/catalogos-produccion.json';
$leido = (is_file($copiaLocal) && ! in_array('--refrescar', $argv, true)) ? json_decode(file_get_contents($copiaLocal), true) : [];
$prodPdo = null;
$prod = new class($leido, function () use (&$prodPdo, $p, $opc) {
    return $prodPdo ??= new PDO("sqlsrv:Server={$p['DB_HOST']},{$p['DB_PORT']};Database={$p['DB_DATABASE']};Encrypt=no;TrustServerCertificate=1", $p['DB_USERNAME'], $p['DB_PASSWORD'], $opc);
})
{
    public function __construct(public array $leido, private $conectar) {}
    public function query(string $sql): object
    {
        $this->leido[$sql] ??= ($this->conectar)()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $filas = $this->leido[$sql];
        return new class($filas) {
            public function __construct(private array $filas) {}
            public function fetchAll($modo = null): array { return $this->filas; }
            public function fetch($modo = null) { return $this->filas[0] ?? false; }
        };
    }
};
$demo = new PDO('sqlsrv:Server=127.0.0.1,14333;Database=BryNex_Videos;Encrypt=no;TrustServerCertificate=1', 'sa', $v['VIDEOS_SQL_SA'], $opc);

// Seguro: el destino tiene que ser la base de videos, nunca la de producción.
if ($demo->query('SELECT DB_NAME()')->fetchColumn() !== 'BryNex_Videos' || $p['DB_PORT'] === '14333') {
    exit("Destino inesperado: no copio nada.\n");
}

$demo->exec('EXEC sp_MSforeachtable "ALTER TABLE ? NOCHECK CONSTRAINT ALL"');
foreach (CATALOGOS as $tabla => $filtro) {
    $filas = $prod->query("SELECT * FROM [$tabla]".($filtro ? " WHERE $filtro" : ''))->fetchAll(PDO::FETCH_ASSOC);
    $demo->exec("DELETE FROM [$tabla]");
    if (! $filas) {
        echo "  $tabla: 0\n";
        continue;
    }
    $identidad = (bool) $demo->query("SELECT 1 FROM sys.identity_columns WHERE object_id = OBJECT_ID('$tabla')")->fetchColumn();
    $cols = array_keys($filas[0]);
    $sql = "INSERT INTO [$tabla] ([".implode('],[', $cols).']) VALUES ('.implode(',', array_fill(0, count($cols), '?')).')';
    $demo->beginTransaction();
    if ($identidad) {
        $demo->exec("SET IDENTITY_INSERT [$tabla] ON");
    }
    $ins = $demo->prepare($sql);
    foreach ($filas as $f) {
        $ins->execute(array_values($f));
    }
    if ($identidad) {
        $demo->exec("SET IDENTITY_INSERT [$tabla] OFF");
    }
    $demo->commit();
    echo "  $tabla: ".count($filas)."\n";
}
// ── El aliado de demostración y su configuración de PRECIOS ─────────────────
// Los precios por plan, modalidad y riesgo se copian de BRYGAR (aliado 2), que
// es el que tiene el tarifario completo: son tarifas, no personas. Lo que
// apunta a un usuario o a un teléfono queda vacío; el nombre de la marca en
// las plantillas de WhatsApp se cambia por el de la demo.
const DEMO = 100;
const ORIGEN = 2;
$ahora = date('Y-m-d H:i:s');
$demo->exec('SET IDENTITY_INSERT aliados ON');
$demo->prepare('INSERT INTO aliados (id, nombre, nit, razon_social, contacto, telefono, celular, correo, direccion, ciudad,
        color_primario, activo, afiliaciones_brynex, slug, eslogan, recibo_doble_copia, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,1,0,?,?,0,?,?)')
    ->execute([DEMO, 'Seguridad Social Demo', '998500001', 'SEGURIDAD SOCIAL DEMO S.A.S.', 'Mariana Demo Ruiz',
        '6029985000', '3995000000', 'contacto@example.com', 'Calle 5 # 38-25, oficina 301', 'Cali',
        '#1e40af', 'demo', 'Tu seguridad social, al día y sin filas', $ahora, $ahora]);
$demo->exec('SET IDENTITY_INSERT aliados OFF');

$copiar = function (string $tabla, array $cambios = [], ?string $filtro = null) use ($prod, $demo): array {
    $filas = $prod->query("SELECT * FROM [$tabla] WHERE aliado_id = ".ORIGEN.($filtro ? " AND $filtro" : ''))->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($filas as $f) {
        $viejo = $f['id'] ?? null;
        unset($f['id']);
        $f = array_merge($f, ['aliado_id' => DEMO], array_map(fn ($c) => is_callable($c) ? $c($f) : $c, $cambios));
        $demo->prepare("INSERT INTO [$tabla] ([".implode('],[', array_keys($f)).']) VALUES ('.implode(',', array_fill(0, count($f), '?')).')')
            ->execute(array_values($f));
        if ($viejo !== null) {
            $ids[$viejo] = (int) $demo->query('SELECT SCOPE_IDENTITY()')->fetchColumn() ?: (int) $demo->query("SELECT IDENT_CURRENT('$tabla')")->fetchColumn();
        }
    }
    echo "  $tabla (de BRYGAR): ".count($filas)."\n";
    return $ids;
};
$marca = fn ($t) => $t === null ? null : str_ireplace(['BRYGAR SAS', 'BRYGAR', 'Brygar'], ['Seguridad Social Demo', 'Seguridad Social Demo', 'Seguridad Social Demo'], $t);

$copiar('configuracion_aliado', ['encargado_default_id' => null, 'prospectos_recordatorio_celular' => null]);
$copiar('afiliacion_arl_modalidad');
$niveles = $copiar('asesor_niveles');
foreach ($niveles as $viejo => $nuevo) {
    $tarifas = $prod->query("SELECT * FROM asesor_nivel_tarifas WHERE asesor_nivel_id = $viejo")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tarifas as $t) {
        unset($t['id']);
        $t['asesor_nivel_id'] = $nuevo;
        $demo->prepare('INSERT INTO asesor_nivel_tarifas (['.implode('],[', array_keys($t)).']) VALUES ('.implode(',', array_fill(0, count($t), '?')).')')
            ->execute(array_values($t));
    }
}
$copiar('whatsapp_plantillas', ['meta_template_id' => null, 'cuerpo' => fn ($f) => $marca($f['cuerpo']),
    'footer' => fn ($f) => $marca($f['footer']), 'header_valor' => fn ($f) => $marca($f['header_valor'])], 'deleted_at IS NULL');
// Módulos de BryNex: los de BRYGAR, más la automatización (para que se vean sus botones).
$copiar('brynex_modulos_aliado', ['notas_negociacion' => null, 'activo' => fn ($f) => in_array((int) $f['modulo_id'], [10, 11], true) ? 1 : $f['activo']]);

$demo->exec('EXEC sp_MSforeachtable "ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL"');

// ── La única persona real: Yuly Paola Garcia Vidal (autorizada por el dueño) ──
// Su última respuesta del registro oficial (RUAF/BDUA), tal como la guardó la
// app, armada con la misma forma que devuelve RegistroOficialService::consultar().
// No se consulta al operador: se lee lo que ya quedó guardado. Va FUERA del repo.
$r = $prod->query("SELECT TOP 1 operador, payload, consultado_at FROM ruaf_consultas
    WHERE cedula = '1143961945' AND estado = 'hallado' ORDER BY consultado_at DESC")->fetch(PDO::FETCH_ASSOC);
if ($r) {
    $d = json_decode($r['payload'], true);
    $eps = $demo->prepare('SELECT TOP 1 COALESCE(reemplazada_por_id, id) id FROM eps WHERE codigo = ? OR codigo_sura = ?');
    $eps->execute([$d['administradoraBDUA'] ?? '', $d['administradoraBDUA'] ?? '']);
    $epsId = $eps->fetchColumn() ?: null;
    $afp = $demo->prepare('SELECT id FROM pensiones WHERE codigo = ?');
    $afp->execute([$d['administradoraRUAF'] ?? '']);
    $afpId = $afp->fetchColumn() ?: null;
    $nombreDe = fn ($tabla, $col, $id) => $id ? $demo->query("SELECT $col FROM $tabla WHERE id = ".(int) $id)->fetchColumn() : null;
    $resultado = [
        'encontrado' => true, 'operador' => $r['operador'] === 'ARUS' ? 'Enlace' : $r['operador'], 'tipo_doc' => 'CC',
        'primer_nombre' => $d['primerNombre'] ?? '', 'segundo_nombre' => $d['segundoNombre'] ?? '',
        'primer_apellido' => $d['primerApellido'] ?? '', 'segundo_apellido' => $d['segundoApellido'] ?? '',
        'eps_id' => $epsId ? (int) $epsId : null, 'eps_nombre' => $nombreDe('eps', 'nombre', $epsId), 'eps_codigo' => $d['administradoraBDUA'] ?? null,
        'pension_id' => $afpId ? (int) $afpId : null, 'pension_nombre' => $nombreDe('pensiones', 'razon_social', $afpId), 'pension_codigo' => $d['administradoraRUAF'] ?? null,
        'estado' => $d['estado'] ?? null, 'regimen' => $d['regimen'] ?? null,
        'en_ruaf' => ! empty($d['fechaAfiliacionRUAF']), 'ruaf_desde' => $d['fechaAfiliacionRUAF'] ?? null,
        'raw' => $d, 'consultado_at' => $r['consultado_at'],
    ];
    $dir = getenv('HOME').'/Movies/Brynex-videos/base';
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/ruaf-yuly.json", json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod("$dir/ruaf-yuly.json", 0600);
    echo "  Yuly: respuesta oficial del {$r['consultado_at']} guardada fuera del repo\n";
}
@mkdir(dirname($copiaLocal), 0700, true);
file_put_contents($copiaLocal, json_encode($prod->leido, JSON_UNESCAPED_UNICODE));
chmod($copiaLocal, 0600);
echo "Catálogos y configuración copiados.\n";
