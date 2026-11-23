<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Medidas en mm con un decimal en cm, medidas del tipo, despiece por fórmulas (validación al guardar, cálculo con
// redondeo a 0,5 mm), despiece congelado al aceptar y lista de corte.
//   php tests/parts.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$v = ['kind' => 'user', 'user' => $admin];
$caj = module_type_save(['name' => 'Cajonera', 'family' => 'bajo', 'width_mm' => 600, 'height_mm' => 720, 'depth_mm' => 560,
    'min_width_mm' => 400, 'max_width_mm' => 900, 'board_mm' => 19, 'back_mm' => 8]);
tier_save($caj, null, 40, 60, 18500);
tier_save($caj, null, '60,1', 90, 22500);

// --- medidas con decimal
check('58,5 cm se guarda como 585 mm', fn() => eq(585, parse_width_mm('58,5')) && eq(585, parse_width_mm('58.5')) && eq(585, parse_width_mm(58.5)) && eq(600, parse_width_mm(60)));
check_error('dos decimales se rechazan', fn() => parse_width_mm('58,55'), 'decimal');
check_error('texto que no es medida', fn() => parse_width_mm('sesenta'), 'cm');
check('58,5 cae en el tramo 41–60 (bordes incluidos, en mm)', function () use ($c) {
    $l = precio_configuracion($c['blanco'], [['items' => [['module_type_id' => $c['bajo'], 'width_mm' => 585]]]])['walls'][0]['items'][0];
    return eq(true, $l['valid']) && eq(10000, $l['price']) && eq(58.5, $l['width_cm']) && eq(41, $l['tier_from_cm']);
});
check('un tramo con borde decimal (60,1–90) cubre 60,5 y no 60', function () use ($c, $caj) {
    $p = precio_configuracion($c['blanco'], [['items' => [['module_type_id' => $caj, 'width_mm' => 605], ['module_type_id' => $caj, 'width_mm' => 600]]]]);
    return eq(22500, $p['walls'][0]['items'][0]['price']) && eq(18500, $p['walls'][0]['items'][1]['price']) && eq(60.1, $p['walls'][0]['items'][0]['tier_from_cm']);
});
check_error('fuera del ancho mínimo/máximo del tipo se rechaza aunque haya tramo', function () use ($c, $caj, $v) {
    tier_save($caj, null, 91, 120, 30000);
    $cfg = config_create($c['roble'], $v);
    config_apply_ops($cfg['code'], [['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $caj, 'width_cm' => 95]], $v);
}, '90');

// --- despiece: validación al guardar
$T = fn(array $p) => module_part_save($caj, $p + ['qty' => 1, 'thickness' => 'T']);
check_error('variable desconocida al guardar una pieza', fn() => $T(['name' => 'Mala', 'length_expr' => 'ANCHO - 2', 'width_expr' => 'D']), 'desconocida');
check_error('sintaxis rota al guardar', fn() => $T(['name' => 'Mala', 'length_expr' => '(W - 2', 'width_expr' => 'D']), 'paréntesis');
check_error('división por cero al guardar', fn() => $T(['name' => 'Mala', 'length_expr' => 'W / (T - 19)', 'width_expr' => 'D']), 'cero');
check_error('resultado ≤ 0 con las medidas por defecto', fn() => $T(['name' => 'Mala', 'length_expr' => 'W - 600', 'width_expr' => 'D']), '0');
check_error('inyección en el nombre de una variable', fn() => $T(['name' => 'Mala', 'length_expr' => 'system("id")', 'width_expr' => 'D']), 'desconocida');
check_error('cantidad 0', fn() => $T(['name' => 'Mala', 'qty' => 0, 'length_expr' => 'W', 'width_expr' => 'D']), 'Cantidad');
check_error('veta inventada', fn() => $T(['name' => 'Mala', 'length_expr' => 'W', 'width_expr' => 'D', 'grain' => 'diagonal']), 'veta');
check('nada de lo rechazado se guardó', fn() => eq(0, (int) val('SELECT COUNT(*) FROM module_parts')));

// Despiece de una cajonera de 3 cajones
foreach ([
    ['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'],
    ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'],
    ['Trasera', 1, 'B', 'H - 3', 'W - 3', '', ''],
    ['Frente de cajón', 3, 'T', 'W - 4', '(H - 12) / 3', 'largo', '4 cantos'],
    ['Lateral de cajón', 6, '16', 'D - 60', '150', '', ''],
    ['Fondo de cajón', 3, 'B', 'D - 62', 'W - 2*T - 26', '', ''],
    ['Frente interior', 3, '16', '(W - 4*T - 27) / 1', '150', '', ''],
] as $i => [$name, $qty, $th, $len, $wid, $grain, $edge]) {
    module_part_save($caj, ['name' => $name, 'qty' => $qty, 'thickness' => $th, 'length_expr' => $len, 'width_expr' => $wid, 'grain' => $grain, 'edge_banding' => $edge, 'sort' => $i]);
}
$byName = fn(array $parts) => array_column($parts, null, 'name');
check('cajonera de 58,5: piezas coherentes con sus fórmulas', function () use ($caj, $byName) {
    $p = $byName(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 585]));
    return eq([2, 19.0, 720.0, 560.0], [$p['Lateral']['qty'], $p['Lateral']['thickness_mm'], $p['Lateral']['length_mm'], $p['Lateral']['width_mm']])
        && eq(547.0, $p['Base']['length_mm'], 'base = W - 2T = 585 - 38')
        && eq([8.0, 717.0, 582.0], [$p['Trasera']['thickness_mm'], $p['Trasera']['length_mm'], $p['Trasera']['width_mm']])
        && eq([3, 581.0, 236.0], [$p['Frente de cajón']['qty'], $p['Frente de cajón']['length_mm'], $p['Frente de cajón']['width_mm']])
        && eq(521.0, $p['Fondo de cajón']['width_mm'], 'fondo = 585 - 38 - 26')
        && eq('largo', $p['Base']['grain']) && eq('4 cantos', $p['Frente de cajón']['edge_banding']);
});
check('redondeo a 0,5 mm exacto', function () use ($caj, $byName) {
    module_part_save($caj, ['name' => 'Prueba', 'qty' => 1, 'thickness' => 'T', 'length_expr' => '481.3', 'width_expr' => '481.2']);
    $q = $byName(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 585]))['Prueba'];
    q("DELETE FROM module_parts WHERE name = 'Prueba'");
    return eq(481.5, $q['length_mm']) && eq(481.0, $q['width_mm']) && eq(292.5, round_half(292.6)) && eq(292.5, round_half(292.25));
});
check('la línea puede llevar su alto y fondo (hoy salen del tipo)', function () use ($caj, $byName) {
    $p = $byName(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 585, 'height_mm' => 800, 'depth_mm' => 500]));
    return eq(800.0, $p['Lateral']['length_mm']) && eq(500.0, $p['Lateral']['width_mm']);
});
check('una pieza que da ≤ 0 con un ancho raro sale con error, no con medida negativa', function () use ($caj) {
    module_part_save($caj, ['name' => 'Estrecha', 'qty' => 1, 'thickness' => 'T', 'length_expr' => 'W - 450', 'width_expr' => 'D']);
    $p = array_column(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 400]), null, 'name')['Estrecha'];
    q("DELETE FROM module_parts WHERE name = 'Estrecha'");
    return eq(null, $p['length_mm']) && eq(true, str_contains((string) $p['error'], '0'));
});

// --- en la configuración: medidas por línea, despiece en vivo, congelado al aceptar y lista de corte
$cfg = config_create($c['roble'], $v);
$out = config_apply_ops($cfg['code'], [['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $caj, 'width_cm' => '58,5']], $v)['config'];
$line = $out['walls'][0]['items'][0];
check('la línea lleva width_mm, height_mm, depth_mm y su despiece', fn() => eq([585, 720, 560], [$line['width_mm'], $line['height_mm'], $line['depth_mm']])
    && eq(58.5, $line['width_cm']) && eq('58,5', $line['width_text']) && eq(7, count($line['parts'])));
check('set_width con decimal y con coma por la operación', function () use ($cfg, $line, $v) {
    $r = config_apply_ops($cfg['code'], [['op' => 'set_width', 'item_id' => $line['id'], 'width_cm' => '59.5']], $v)['config'];
    config_apply_ops($cfg['code'], [['op' => 'set_width', 'item_id' => $line['id'], 'width_cm' => '58,5']], $v);
    return eq(595, $r['walls'][0]['items'][0]['width_mm']);
});
check_error('una operación con un campo que no existe es un error', fn() => config_apply_ops($cfg['code'], [['op' => 'set_width', 'item_id' => $line['id'], 'width' => 60]], $v), 'width');
check('lista de corte: piezas iguales sumadas con su módulo de origen', function () use ($cfg, $v, $caj) {
    config_apply_ops($cfg['code'], [['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $caj, 'width_cm' => '58,5']], $v);
    $cl = cut_list(config_get($cfg['code'], $v));
    $lat = array_values(array_filter($cl, fn($r) => $r['name'] === 'Lateral'))[0];
    $front = array_values(array_filter($cl, fn($r) => $r['name'] === 'Frente de cajón'))[0];
    return eq(4, $lat['qty']) && eq(2, count($lat['from'])) && eq(6, $front['qty']) && eq(581.0, $front['length_mm']) && eq(19.0, $front['thickness_mm']);
});
check('al aceptar se congela el despiece: cambiar la fórmula después no lo cambia', function () use ($cfg, $v, $caj) {
    $acc = config_accept($cfg['code'], $v);
    q("UPDATE module_parts SET length_expr = 'W - 100' WHERE module_type_id = ? AND name = 'Base'", [$caj]);
    $after = config_get($cfg['code'], $v);
    $base = fn($x) => array_column($x['walls'][0]['items'][0]['parts'], null, 'name')['Base']['length_mm'];
    $draft = array_column(piezas_de_linea(['module_type_id' => $caj, 'width_mm' => 585]), null, 'name')['Base']['length_mm'];
    return eq(547.0, $base($acc)) && eq(547.0, $base($after)) && eq(485.0, $draft, 'un borrador sí usa la fórmula nueva');
});
check('lista de corte: piezas iguales con nombres distintos se suman en una fila con sus nombres', function () use ($caj) {
    $view = ['walls' => [['name' => 'P', 'items' => [['id' => 1, 'type_name' => 'X', 'width_cm' => 60, 'parts' => [
        ['name' => 'Base', 'qty' => 1, 'thickness_mm' => 19.0, 'length_mm' => 562.0, 'width_mm' => 560.0, 'grain' => 'largo', 'edge_banding' => '1L', 'error' => null],
        ['name' => 'Techo', 'qty' => 1, 'thickness_mm' => 19.0, 'length_mm' => 562.0, 'width_mm' => 560.0, 'grain' => 'largo', 'edge_banding' => '1L', 'error' => null]]]]]]];
    $cl = cut_list($view);
    return eq(1, count($cl)) && eq(2, $cl[0]['qty']) && eq(['Base', 'Techo'], $cl[0]['names']);
});
check('las descargas llevan el despiece y la lista de corte', function () use ($cfg, $v) {
    $view = config_get($cfg['code'], $v);
    $j = json_decode(config_export_json($view), true);
    $csv = config_export_parts_csv($view);
    $cut = config_export_cut_csv($view);
    return eq(true, isset($j['walls'][0]['items'][0]['parts'][0]['length_mm'], $j['cut_list'][0]['qty'])) && eq(true, str_contains($csv, 'Frente de cajón;3;19;581;236'))
        && eq(true, str_contains($cut, 'Lateral'));
});

done();
