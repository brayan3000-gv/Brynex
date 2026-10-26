<?php

/**
 * La ESTRUCTURA de la base de producción, sin un solo dato, como script SQL
 * para crear la base de los videos (base-videos.sh).
 *
 *   DYLD_LIBRARY_PATH=/opt/homebrew/opt/openssl@3/lib php scripts/videos/demo/esquema.php > esquema.sql
 *
 * Por qué no `php artisan migrate`: routes/web.php consulta `aliados` al
 * arrancar, así que artisan no corre contra una base vacía; y además hay
 * índices creados a mano que las migraciones no tienen.
 *
 * Solo lee el catálogo del sistema (sys.tables, sys.columns, sys.indexes…).
 * Deja fuera las copias de respaldo (`*_bkp_*`, `*_20260801_…`).
 * Usa el .env del repo (producción, por el túnel), en solo lectura.
 */
$env = [];
foreach (file(__DIR__.'/../../../.env') as $l) {
    if (preg_match('/^(DB_[A-Z_]+)=(.*)$/', trim($l), $m)) {
        $env[$m[1]] = trim($m[2], "\"'");
    }
}
$pdo = new PDO(
    "sqlsrv:Server={$env['DB_HOST']},{$env['DB_PORT']};Database={$env['DB_DATABASE']};Encrypt=no;TrustServerCertificate=1",
    $env['DB_USERNAME'], $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$q = fn (string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$esRespaldo = fn (string $t) => (bool) preg_match('/(_bkp|_\d{8}(_\d{6})?$)/', $t);
$tablas = array_values(array_filter(
    array_column($q('SELECT name FROM sys.tables WHERE is_ms_shipped = 0 ORDER BY name'), 'name'),
    fn ($t) => ! $esRespaldo($t)
));
$incluida = array_flip($tablas);
$cotizar = fn (string $n) => '['.str_replace(']', ']]', $n).']';

function tipo(array $c): string
{
    $t = $c['tipo'];
    $len = (int) $c['max_length'];
    return match ($t) {
        'varchar', 'char', 'varbinary', 'binary' => "$t(".($len === -1 ? 'max' : $len).')',
        'nvarchar', 'nchar' => "$t(".($len === -1 ? 'max' : $len / 2).')',
        'decimal', 'numeric' => "$t({$c['precision']},{$c['scale']})",
        'datetime2', 'time', 'datetimeoffset' => "$t({$c['scale']})",
        'float' => $c['precision'] == 53 ? 'float' : "float({$c['precision']})",
        default => $t,
    };
}

$columnas = [];
foreach ($q("SELECT t.name tabla, c.name col, c.column_id, ty.name tipo, c.max_length, c.precision, c.scale,
        c.is_nullable, c.collation_name, ic.seed_value, ic.increment_value, c.is_identity,
        dc.name def_nombre, dc.definition def_valor
    FROM sys.columns c
    JOIN sys.tables t ON t.object_id = c.object_id
    JOIN sys.types ty ON ty.user_type_id = c.user_type_id
    LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
    LEFT JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
    ORDER BY t.name, c.column_id") as $c) {
    $columnas[$c['tabla']][] = $c;
}

$indices = [];
foreach ($q("SELECT t.name tabla, i.name indice, i.index_id, i.type_desc, i.is_unique, i.is_primary_key,
        i.is_unique_constraint, i.has_filter, i.filter_definition, c.name col, ic.key_ordinal,
        ic.is_descending_key, ic.is_included_column
    FROM sys.indexes i
    JOIN sys.tables t ON t.object_id = i.object_id
    JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
    JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
    WHERE i.type > 0
    ORDER BY t.name, i.index_id, ic.is_included_column, ic.key_ordinal, ic.index_column_id") as $r) {
    $indices[$r['tabla']][$r['indice']]['meta'] = $r;
    $indices[$r['tabla']][$r['indice']][$r['is_included_column'] ? 'incluye' : 'llave'][] =
        $cotizar($r['col']).($r['is_descending_key'] && ! $r['is_included_column'] ? ' DESC' : '');
}

echo "-- Estructura de BryNex (sin datos), generada por scripts/videos/demo/esquema.php el ".date('Y-m-d H:i')."\n";
echo "SET NOCOUNT ON;\nSET QUOTED_IDENTIFIER ON;\nGO\n";

foreach ($tablas as $t) {
    $lineas = [];
    foreach ($columnas[$t] as $c) {
        $l = '  '.$cotizar($c['col']).' '.tipo($c);
        if ($c['collation_name'] && $c['collation_name'] !== 'Modern_Spanish_CI_AS') {
            $l .= ' COLLATE '.$c['collation_name'];
        }
        if ($c['is_identity']) {
            $l .= " IDENTITY({$c['seed_value']},{$c['increment_value']})";
        }
        $l .= $c['is_nullable'] ? ' NULL' : ' NOT NULL';
        if ($c['def_nombre']) {
            $l .= ' CONSTRAINT '.$cotizar($c['def_nombre']).' DEFAULT '.$c['def_valor'];
        }
        $lineas[] = $l;
    }
    foreach ($indices[$t] ?? [] as $nombre => $i) {
        $m = $i['meta'];
        if ($m['is_primary_key'] || $m['is_unique_constraint']) {
            $lineas[] = '  CONSTRAINT '.$cotizar($nombre).($m['is_primary_key'] ? ' PRIMARY KEY ' : ' UNIQUE ')
                .($m['type_desc'] === 'CLUSTERED' ? 'CLUSTERED' : 'NONCLUSTERED').' ('.implode(', ', $i['llave']).')';
        }
    }
    echo "CREATE TABLE ".$cotizar($t)." (\n".implode(",\n", $lineas)."\n);\n";
    foreach ($indices[$t] ?? [] as $nombre => $i) {
        $m = $i['meta'];
        if ($m['is_primary_key'] || $m['is_unique_constraint'] || ! in_array($m['type_desc'], ['CLUSTERED', 'NONCLUSTERED'])) {
            continue;
        }
        echo 'CREATE '.($m['is_unique'] ? 'UNIQUE ' : '').$m['type_desc'].' INDEX '.$cotizar($nombre).' ON '.$cotizar($t)
            .' ('.implode(', ', $i['llave']).')'
            .(! empty($i['incluye']) ? ' INCLUDE ('.implode(', ', $i['incluye']).')' : '')
            .($m['has_filter'] ? ' WHERE '.$m['filter_definition'] : '').";\n";
    }
    echo "GO\n";
}

// Llaves foráneas, al final: ya existen todas las tablas.
$fks = [];
foreach ($q("SELECT fk.name, tp.name padre, cp.name col_padre, tr.name ref, cr.name col_ref,
        fk.delete_referential_action_desc del, fk.update_referential_action_desc upd, fk.is_disabled, fkc.constraint_column_id
    FROM sys.foreign_keys fk
    JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
    JOIN sys.tables tp ON tp.object_id = fk.parent_object_id
    JOIN sys.columns cp ON cp.object_id = fkc.parent_object_id AND cp.column_id = fkc.parent_column_id
    JOIN sys.tables tr ON tr.object_id = fk.referenced_object_id
    JOIN sys.columns cr ON cr.object_id = fkc.referenced_object_id AND cr.column_id = fkc.referenced_column_id
    ORDER BY fk.name, fkc.constraint_column_id") as $f) {
    $fks[$f['name']]['f'] = $f;
    $fks[$f['name']]['p'][] = $cotizar($f['col_padre']);
    $fks[$f['name']]['r'][] = $cotizar($f['col_ref']);
}
foreach ($fks as $nombre => $k) {
    $f = $k['f'];
    if (! isset($incluida[$f['padre']], $incluida[$f['ref']])) {
        continue;
    }
    $accion = fn ($a) => str_replace('_', ' ', $a);
    echo 'ALTER TABLE '.$cotizar($f['padre']).' WITH '.($f['is_disabled'] ? 'NOCHECK' : 'CHECK').' ADD CONSTRAINT '.$cotizar($nombre)
        .' FOREIGN KEY ('.implode(', ', $k['p']).') REFERENCES '.$cotizar($f['ref']).' ('.implode(', ', $k['r']).')'
        .($f['del'] !== 'NO_ACTION' ? ' ON DELETE '.$accion($f['del']) : '')
        .($f['upd'] !== 'NO_ACTION' ? ' ON UPDATE '.$accion($f['upd']) : '').";\n";
    if ($f['is_disabled']) {
        echo 'ALTER TABLE '.$cotizar($f['padre']).' NOCHECK CONSTRAINT '.$cotizar($nombre).";\n";
    }
}
foreach ($q("SELECT t.name tabla, cc.name, cc.definition FROM sys.check_constraints cc JOIN sys.tables t ON t.object_id = cc.parent_object_id") as $c) {
    if (isset($incluida[$c['tabla']])) {
        echo 'ALTER TABLE '.$cotizar($c['tabla']).' ADD CONSTRAINT '.$cotizar($c['name']).' CHECK '.$c['definition'].";\n";
    }
}
echo "GO\n";
fwrite(STDERR, count($tablas)." tablas, ".count($fks)." llaves foráneas\n");
