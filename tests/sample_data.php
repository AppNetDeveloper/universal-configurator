<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Datos de ejemplo e instalación: 3 modelos × 4 colores, 9 tipos con tramos sin solapes, composiciones recta,
// en L y paralela, y que TODOS los nodos finales arrancan con precio válido.
//   php tests/sample_data.php

require __DIR__ . '/_helper.php';

$admin = fx_user('admin', 'admin@x.test');
seed_sample();
$v = ['kind' => 'user', 'user' => $admin];

check('cocina: 3 modelos y 4 colores por modelo', function () {
    $root = node_children(null)[0];
    $models = node_children((int) $root['id']);
    return eq(3, count($models)) && eq([4, 4, 4], array_map(fn($m) => count(node_children((int) $m['id'])), $models));
});
check('al menos 9 tipos de módulo, todos con tramos', fn() => eq(true, count(module_types_all()) >= 9) && eq(0, (int) val('SELECT COUNT(*) FROM module_types t WHERE NOT EXISTS (SELECT 1 FROM price_tiers p WHERE p.module_type_id = t.id)')));
check('ningún tramo se solapa dentro de su grupo', function () {
    $bad = (int) val('SELECT COUNT(*) FROM price_tiers a JOIN price_tiers b ON a.module_type_id = b.module_type_id AND a.id < b.id
        AND COALESCE(a.node_id, 0) = COALESCE(b.node_id, 0) AND a.from_mm <= b.to_mm AND b.from_mm <= a.to_mm');
    return eq(0, $bad);
});
$finals = rows('SELECT n.id FROM nodes n WHERE NOT EXISTS (SELECT 1 FROM nodes c WHERE c.parent_id = n.id)');
foreach ($finals as $f) {
    $path = node_path_text((int) $f['id']);
    check("$path: arranca con módulos y todos con precio", function () use ($f, $v) {
        $cfg = config_create((int) $f['id'], $v);
        return eq(true, $cfg['count'] > 0) && eq(true, $cfg['valid']) && eq(0, $cfg['invalid_count']) && eq(true, $cfg['total_cents'] > 0);
    });
}
check('composiciones: recta (1 frente), en L (paredes x e y) y paralela (2 frentes en x separados)', function () {
    $dirs = function (string $model) {
        $nid = (int) val("SELECT id FROM nodes WHERE name = ? AND parent_id IS NOT NULL", [$model]);
        return array_map(fn($w) => [$w['direction'], (int) $w['origin_y_mm']], array_values(array_filter(layout_walls_of($nid), fn($w) => !str_contains(mb_strtolower($w['name']), 'altos'))));
    };
    return eq([['x', 0]], $dirs('Línea')) && eq([['x', 0], ['y', 600]], $dirs('Dúo')) && eq([['x', 0], ['x', 2400]], $dirs('Escala'));
});
check('Escala usa sus tramos propios de cajonera (210 € a 60 cm)', function () use ($v) {
    $escalaRoble = (int) val("SELECT c.id FROM nodes c JOIN nodes m ON m.id = c.parent_id WHERE m.name = 'Escala' AND c.name = 'Roble'");
    $caj = (int) val("SELECT id FROM module_types WHERE ref = 'CAJ3'");
    return eq(21000, tier_lookup($caj, $escalaRoble, 600)['tier']['price']);
});
check('cada tipo tiene medidas reales y despiece; todas las piezas salen bien en todos los nodos finales', function () use ($v) {
    foreach (module_types_all() as $t) if (!module_parts_of((int) $t['id'])) throw new RuntimeException("{$t['name']} sin despiece");
    $bajo = row("SELECT * FROM module_types WHERE ref = 'B1P'");
    $alto = row("SELECT * FROM module_types WHERE ref = 'A1P'");
    $col = row("SELECT * FROM module_types WHERE ref = 'CHO'");
    foreach (config_list($v, [], 1, 100)['items'] as $it) foreach (config_get($it['code'], $v)['walls'] as $w) foreach ($w['items'] as $l) {
        if (!$l['parts_ok'] || !$l['parts']) throw new RuntimeException("{$it['code']} línea {$l['id']}: despiece con error");
    }
    return eq([720, 560], [(int) $bajo['height_mm'], (int) $bajo['depth_mm']]) && eq([720, 330], [(int) $alto['height_mm'], (int) $alto['depth_mm']]) && eq([2100, 560], [(int) $col['height_mm'], (int) $col['depth_mm']]);
});
check('cajonera de 58,5 cm: piezas coherentes', function () {
    $caj = (int) val("SELECT id FROM module_types WHERE ref = 'CAJ3'");
    $p = array_column(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 585]), null, 'name');
    return eq([2, 720.0, 560.0], [$p['Lateral']['qty'], $p['Lateral']['length_mm'], $p['Lateral']['width_mm']])
        && eq(547.0, $p['Base']['length_mm'], 'base = 585 - 2·19')
        && eq([3, 581.0, 236.0], [$p['Frente de cajón']['qty'], $p['Frente de cajón']['length_mm'], $p['Frente de cajón']['width_mm']], 'frente = (585-4) × (720-12)/3')
        && eq(489.0, $p['Frente y trasera de caja']['length_mm'], 'caja = 585 - 38 - 58')
        && eq([498.0, 521.0], [$p['Fondo de cajón']['length_mm'], $p['Fondo de cajón']['width_mm']]) && eq(8.0, $p['Trasera']['thickness_mm']);
});
check('tres familias: Cocina, Armarios (Corredera y Batiente) y Baños', function () {
    $roots = array_column(node_children(null), 'name');
    $arm = (int) val("SELECT id FROM nodes WHERE name = 'Armarios' AND parent_id IS NULL");
    return eq(['Cocina', 'Armarios', 'Baños'], $roots) && eq(['Corredera', 'Batiente'], array_column(node_children($arm), 'name'))
        && eq(true, count(node_children((int) val("SELECT id FROM nodes WHERE name = 'Corredera'"))) >= 3);
});
check('cada nodo final de las tres familias arranca con módulos, precio y despiece', function () use ($v) {
    $finals = rows('SELECT n.id FROM nodes n WHERE NOT EXISTS (SELECT 1 FROM nodes c WHERE c.parent_id = n.id)');
    foreach ($finals as $f) {
        $cfg = config_create((int) $f['id'], $v);
        if (!$cfg['valid'] || !$cfg['count']) throw new RuntimeException(node_path_text((int) $f['id']) . ': sin precio');
        foreach ($cfg['walls'] as $w) foreach ($w['items'] as $l) if (!$l['parts'] || !$l['parts_ok']) throw new RuntimeException(node_path_text((int) $f['id']) . ': despiece');
    }
    return eq(true, count($finals) >= 22);
});
check('cada familia solo ofrece sus módulos (los de cocina no salen en un armario y al revés)', function () {
    $leaf = fn(string $model) => (int) val('SELECT c.id FROM nodes c JOIN nodes m ON m.id = c.parent_id WHERE m.name = ? LIMIT 1', [$model]);
    $names = fn(int $n) => array_column(module_types_for_node($n), 'name');
    $arm = $names($leaf('Batiente'));
    $coc = $names($leaf('Línea'));
    return eq(false, in_array('Cajonera 3 cajones', $arm, true)) && eq(true, in_array('Armario 2 puertas', $arm, true))
        && eq(false, in_array('Armario 2 puertas', $coc, true)) && eq(true, in_array('Cajonera 3 cajones', $coc, true));
});
check('todos los nodos llevan una ilustración generada (y los acabados con su color)', function () {
    $bad = rows("SELECT name, image FROM nodes WHERE image IS NULL OR image NOT LIKE 'ill:%'");
    return eq([], $bad);
});
check('los modelos y acabados tienen descripción', fn() => eq(0, (int) val("SELECT COUNT(*) FROM nodes WHERE parent_id IS NOT NULL AND description = ''")));
check('demo: 3 comerciales, 4 clientes y ~12 configuraciones de las tres familias en todos los estados', function () {
    $info = seed_demo();
    $byRole = array_column(rows('SELECT role, COUNT(*) n FROM users GROUP BY role'), 'n', 'role');
    $st = array_column(rows('SELECT status, COUNT(*) n FROM configurations WHERE user_id IN (SELECT id FROM users WHERE role != \'admin\') GROUP BY status'), 'n', 'status');
    $families = (int) val("SELECT COUNT(DISTINCT root) FROM (SELECT (SELECT p2.name FROM nodes p2 WHERE p2.parent_id IS NULL AND (p2.id = n.parent_id OR p2.id = (SELECT pp.parent_id FROM nodes pp WHERE pp.id = n.parent_id))) AS root
        FROM configurations c JOIN nodes n ON n.id = c.node_id JOIN users u ON u.id = c.user_id WHERE u.role != 'admin')");
    $decimal = (int) val('SELECT COUNT(*) FROM config_items WHERE width_mm % 10 != 0');
    return eq(3, (int) $byRole['comercial']) && eq(4, (int) $byRole['cliente']) && eq(true, array_sum($st) >= 12)
        && eq(true, ($st['accepted'] ?? 0) >= 3 && ($st['draft'] ?? 0) >= 3 && ($st['cancelled'] ?? 0) >= 1) && eq(3, $families) && eq(true, $decimal >= 2)
        && eq(true, count($info['users']) === 7);
});
check('demo: hay una línea cobrada como el tramo de arriba (hueco) y un token de demostración revocado', function () use ($v) {
    $nextUp = false;
    foreach (config_list($v, [], 1, 100)['items'] as $it) foreach (config_get($it['code'], $v)['walls'] as $w) foreach ($w['items'] as $l) if (($l['tier_rule'] ?? '') === 'next_up') $nextUp = true;
    return eq(true, $nextUp) && eq(1, (int) val('SELECT COUNT(*) FROM mcp_tokens WHERE revoked_at IS NOT NULL'));
});
check_error('la demo no se carga dos veces', fn() => seed_demo(), 'ya');
check_error('no se cargan dos veces', fn() => seed_sample(), 'vacío');

done();
