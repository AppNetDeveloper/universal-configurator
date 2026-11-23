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

check('3 modelos y 4 colores por modelo (12 nodos finales)', function () {
    $root = node_children(null)[0];
    $models = node_children((int) $root['id']);
    $finals = (int) val('SELECT COUNT(*) FROM nodes n WHERE NOT EXISTS (SELECT 1 FROM nodes c WHERE c.parent_id = n.id)');
    return eq(3, count($models)) && eq(12, $finals) && eq([4, 4, 4], array_map(fn($m) => count(node_children((int) $m['id'])), $models));
});
check('9 tipos de módulo, todos con tramos', fn() => eq(9, count(module_types_all())) && eq(0, (int) val('SELECT COUNT(*) FROM module_types t WHERE NOT EXISTS (SELECT 1 FROM price_tiers p WHERE p.module_type_id = t.id)')));
check('ningún tramo se solapa dentro de su grupo', function () {
    $bad = (int) val('SELECT COUNT(*) FROM price_tiers a JOIN price_tiers b ON a.module_type_id = b.module_type_id AND a.id < b.id
        AND COALESCE(a.node_id, 0) = COALESCE(b.node_id, 0) AND a.from_cm <= b.to_cm AND b.from_cm <= a.to_cm');
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
    return eq(21000, tier_lookup($caj, $escalaRoble, 60)['tier']['price']);
});
check_error('no se cargan dos veces', fn() => seed_sample(), 'vacío');

done();
