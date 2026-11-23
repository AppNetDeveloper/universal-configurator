<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Migración de esquema v2 (anchos en cm enteros) a v3 (medidas en mm, medidas del tipo y despiece):
// convierte los datos existentes sin perder nada, también las configuraciones aceptadas con su foto.
//   php tests/migration.php

$GLOBALS['__uc_tmp'] = sys_get_temp_dir() . '/uc-test-migration-' . getmypid();
@mkdir($GLOBALS['__uc_tmp'], 0700, true);
putenv('UC_DATA=' . $GLOBALS['__uc_tmp']);
// Base con el esquema v2 tal cual estaba, y datos de antes
$old = new PDO('sqlite:' . $GLOBALS['__uc_tmp'] . '/configurator.sqlite');
$old->exec(file_get_contents(__DIR__ . '/fixtures/schema_v2.sql'));
$old->exec(<<<SQL
INSERT INTO meta VALUES ('schema_version', '2');
INSERT INTO users (id, email, name, role, created_at) VALUES (1, 'a@x.test', 'Admin', 'admin', '2026-11-01 10:00:00');
INSERT INTO nodes (id, parent_id, name, slug, sort) VALUES (1, NULL, 'Cocina', 'cocina', 10), (2, 1, 'Línea', 'linea', 10);
INSERT INTO module_types (id, name, ref, family, depth_mm) VALUES (1, 'Bajo', 'B1P', 'bajo', 560), (2, 'Alto', 'A1P', 'alto', 330);
INSERT INTO price_tiers (id, module_type_id, node_id, from_cm, to_cm, price) VALUES (1, 1, NULL, 30, 40, 8500), (2, 1, NULL, 41, 60, 11000), (3, 2, 2, 30, 60, 7500);
INSERT INTO layout_walls (id, node_id, name, direction) VALUES (1, 2, 'Frente', 'x');
INSERT INTO default_modules (id, layout_wall_id, module_type_id, width_cm, sort) VALUES (1, 1, 1, 40, 10), (2, 1, 2, 60, 20);
INSERT INTO configurations (id, code, node_id, user_id, status, total, tax_rate, created_at, updated_at, accepted_at)
  VALUES (1, 'UC-AAAAAAAA', 2, 1, 'accepted', 19500, 21, '2026-11-02 10:00:00', '2026-11-02 10:00:00', '2026-11-02 10:05:00'),
         (2, 'UC-BBBBBBBB', 2, 1, 'draft', 0, 21, '2026-11-03 10:00:00', '2026-11-03 10:00:00', NULL);
INSERT INTO config_walls (id, configuration_id, name, direction) VALUES (1, 1, 'Frente', 'x'), (2, 2, 'Frente', 'x');
INSERT INTO config_items (id, config_wall_id, module_type_id, width_cm, sort, tier_from_cm, tier_to_cm, price)
  VALUES (1, 1, 1, 40, 10, 30, 40, 8500), (2, 1, 1, 55, 20, 41, 60, 11000), (3, 2, 2, 45, 10, NULL, NULL, NULL);
SQL);
$old = null;

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_checks.php';
register_shutdown_function(function () {
    $d = $GLOBALS['__uc_tmp'];
    foreach (scandir($d) ?: [] as $f) if (is_file("$d/$f")) @unlink("$d/$f");
    @rmdir($d);
});
file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => 'x'];");
ensure_schema();

$cols = fn(string $t) => array_column(rows("PRAGMA table_info($t)"), 'name');
check('versión de esquema al día (6)', fn() => eq('6', val("SELECT value FROM meta WHERE key = 'schema_version'")));
check('tramos en mm (30–40 cm → 300–400 mm), sin columnas en cm', fn() => eq([[300, 400], [410, 600], [300, 600]], array_map(fn($r) => [(int) $r['from_mm'], (int) $r['to_mm']], rows('SELECT * FROM price_tiers ORDER BY id')))
    && eq(false, in_array('from_cm', $cols('price_tiers'), true)));
check('el CHECK desde ≤ hasta sigue valiendo en mm', function () {
    try { q('INSERT INTO price_tiers (module_type_id, from_mm, to_mm, price) VALUES (1, 700, 600, 1)'); } catch (PDOException) { return true; }
    throw new RuntimeException('el CHECK no se conservó');
});
check('composición por defecto en mm', fn() => eq([400, 600], array_map('intval', array_column(rows('SELECT width_mm FROM default_modules ORDER BY id'), 'width_mm'))));
check('líneas en mm con alto y fondo del tipo; la foto del tramo también en mm', function () {
    $r = rows('SELECT * FROM config_items ORDER BY id');
    return eq([400, 550, 450], array_map(fn($x) => (int) $x['width_mm'], $r)) && eq([300, 400], [(int) $r[0]['tier_from_mm'], (int) $r[0]['tier_to_mm']])
        && eq([560, 330], [(int) $r[0]['depth_mm'], (int) $r[2]['depth_mm']]) && eq(720, (int) $r[0]['height_mm']) && eq(null, $r[2]['tier_from_mm']);
});
check('los tipos ganan medidas por defecto, mínimo/máximo desde sus tramos y espesores 19/8', function () {
    $t = row('SELECT * FROM module_types WHERE id = 1');
    return eq([600, 720, 560, 300, 600], [(int) $t['width_mm'], (int) $t['height_mm'], (int) $t['depth_mm'], (int) $t['min_width_mm'], (int) $t['max_width_mm']])
        && eq([19.0, 8.0], [(float) $t['board_mm'], (float) $t['back_mm']]);
});
check('tablas nuevas de despiece', fn() => eq(true, in_array('length_expr', $cols('module_parts'), true) && in_array('length_mm', $cols('config_item_parts'), true)));
check('la configuración aceptada se lee igual que antes (precio congelado)', function () {
    $v = config_get('UC-AAAAAAAA', ['kind' => 'user', 'user' => user_by_id(1)]);
    return eq(19500, $v['total_cents']) && eq([40, 55], array_column($v['walls'][0]['items'], 'width_cm')) && eq(41, $v['walls'][0]['items'][1]['tier_from_cm']);
});
check('el borrador recalcula con los tramos en mm', function () {
    $v = config_get('UC-BBBBBBBB', ['kind' => 'user', 'user' => user_by_id(1)]);
    return eq(7500, $v['total_cents']) && eq(45, $v['walls'][0]['items'][0]['width_cm']);
});
check('una base en esquema 4 (sin login_backoff) recibe la tabla al actualizar', function () {
    q('DROP TABLE login_backoff');
    q("UPDATE meta SET value = '4' WHERE key = 'schema_version'");
    ensure_schema();
    return eq('login_backoff', val("SELECT name FROM sqlite_master WHERE name = 'login_backoff'"));
});
check('migrar dos veces no cambia nada', function () {
    migrate();
    return eq(400, (int) val('SELECT to_mm FROM price_tiers WHERE id = 1'));
});

done();
