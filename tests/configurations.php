<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Configuraciones: crear desde la composición por defecto, operaciones, aceptar (foto del precio),
// duplicar, visibilidad por persona (comercial A no ve a B, invitado solo lo suyo) y exportación.
//   php tests/configurations.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$a = fx_user('comercial', 'ana@x.test');
$b = fx_user('comercial', 'beto@x.test');
$va = ['kind' => 'user', 'user' => $a];
$vb = ['kind' => 'user', 'user' => $b];
$vadmin = ['kind' => 'user', 'user' => $admin];
$items = fn(array $cfg) => array_merge(...array_map(fn($w) => $w['items'], $cfg['walls']));

// --- crear
$cfg = config_create($c['blanco'], $va);
check('crea un borrador con la composición heredada del modelo', fn() => eq('draft', $cfg['status']) && eq(1, count($cfg['walls']))
    && eq([40, 60], array_column($items($cfg), 'width_cm')) && eq(18000, $cfg['total_cents']) && eq($a['id'], $cfg['user']['id']));
check('el código es aleatorio y no correlativo', fn() => eq(1, preg_match('/^UC-[A-Z2-9]{8}$/', $cfg['code'])));
check('la pared lleva su geometría (dirección y origen en mm)', fn() => eq('x', $cfg['walls'][0]['direction']) && eq(0, $cfg['walls'][0]['origin_x_mm']));
check_error('no se crea desde un nodo que no es final', fn() => config_create($c['linea'], $va), 'final');
check_error('no se crea sin sesión', fn() => config_create($c['blanco'], null));
check('un nodo final sin composición arranca con una pared vacía', function () use ($c, $va) {
    $r = config_create($c['roble'], $va);
    return eq(1, count($r['walls'])) && eq(0, $r['count']);
});
check_error('no se crea desde una rama desactivada', function () use ($c, $va) {
    node_set_active($c['duo'], false);
    try { config_create($c['roble'], $va); } finally { node_set_active($c['duo'], true); }
}, 'disponible');

// --- operaciones
$code = $cfg['code'];
$it = $items($cfg);
check('set_width válido recalcula el total', function () use ($code, $it, $va) {
    $r = config_apply_ops($code, [['op' => 'set_width', 'item_id' => $it[0]['id'], 'width_cm' => 45]], $va)['config'];
    return eq(20000, $r['total_cents']) && eq(45, $r['walls'][0]['items'][0]['width_cm']);
});
check('set_width sin tramo se rechaza con los tramos y NO cambia nada (atómico)', function () use ($code, $it, $va) {
    try {
        config_apply_ops($code, [['op' => 'set_width', 'item_id' => $it[1]['id'], 'width_cm' => 50], ['op' => 'set_width', 'item_id' => $it[0]['id'], 'width_cm' => 95]], $va);
    } catch (UcError $e) {
        $r = config_get($code, $va);
        return eq(true, str_contains($e->getMessage(), '95')) && eq(true, str_contains($e->getMessage(), '41–60'))
            && eq(2, count($e->data['tiers'] ?? [])) && eq(60, $r['walls'][0]['items'][1]['width_cm'], 'la primera operación no se aplicó');
    }
    throw new RuntimeException('no dio error');
});
check_error('set_width con dos decimales se rechaza (uno sí vale)', fn() => config_apply_ops($code, [['op' => 'set_width', 'item_id' => $it[0]['id'], 'width_cm' => '45.55']], $va), 'decimal');
check('add con ancho', function () use ($code, $cfg, $c, $va) {
    $r = config_apply_ops($code, [['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $c['alto'], 'width_cm' => 50]], $va)['config'];
    return eq(3, $r['count']) && eq(25000, $r['total_cents']);
});
check('add sin ancho usa un ancho válido sugerido', function () use ($code, $cfg, $c, $va) {
    $r = config_apply_ops($code, [['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $c['bajo']]], $va)['config'];
    return eq(4, $r['count']) && eq(true, $r['valid']);
});
check('replace conserva el ancho si vale en el tipo nuevo', function () use ($code, $c, $va) {
    $r = config_get($code, $va);
    $last = end($r['walls'][0]['items']);
    $after = config_apply_ops($code, [['op' => 'replace', 'item_id' => $last['id'], 'module_type_id' => $c['alto']]], $va)['config'];
    $l = end($after['walls'][0]['items']);
    return eq($c['alto'], $l['module_type_id']) && eq($last['width_cm'], $l['width_cm']);
});
check('replace ajusta el ancho al tramo más cercano si no vale, y lo dice', function () use ($code, $c, $it, $va) {
    config_apply_ops($code, [['op' => 'set_width', 'item_id' => $it[0]['id'], 'width_cm' => 30]], $va); // Bajo 30 cm
    tier_save($c['alto'], $c['linea'], 45, 60, 6000); // en Línea, Alto solo 45–60: 30 cm no vale
    $out = config_apply_ops($code, [['op' => 'replace', 'item_id' => $it[0]['id'], 'module_type_id' => $c['alto']]], $va);
    q('DELETE FROM price_tiers WHERE node_id = ?', [$c['linea']]);
    return eq(45, $out['config']['walls'][0]['items'][0]['width_cm']) && eq(1, count($out['notes']));
});
check('remove quita la línea', function () use ($code, $va) {
    $r = config_get($code, $va);
    $last = end($r['walls'][0]['items']);
    return eq($r['count'] - 1, config_apply_ops($code, [['op' => 'remove', 'item_id' => $last['id']]], $va)['config']['count']);
});
check('customer guarda los datos del cliente', function () use ($code, $va) {
    $r = config_apply_ops($code, [['op' => 'customer', 'name' => 'Lucía Pérez', 'email' => 'lucia@cliente.test', 'phone' => '600111222']], $va)['config'];
    return eq('Lucía Pérez', $r['customer']['name']);
});
check_error('customer con correo no válido se rechaza', fn() => config_apply_ops($code, [['op' => 'customer', 'email' => 'no-es-correo']], $va), 'correo');
check_error('operación desconocida dice cuáles valen', fn() => config_apply_ops($code, [['op' => 'resize']], $va), 'set_width');
$other = config_create($c['blanco'], $va);
check_error('no se toca una línea de OTRA configuración', fn() => config_apply_ops($code, [['op' => 'remove', 'item_id' => $other['walls'][0]['items'][0]['id']]], $va), 'no es de');

// --- visibilidad
check_error('comercial B no ve la configuración de A', fn() => config_get($code, $vb), 'No existe');
check_error('comercial B no puede cambiar la de A', fn() => config_apply_ops($code, [['op' => 'customer', 'name' => 'X']], $vb), 'No existe');
check_error('comercial B no puede aceptar la de A', fn() => config_accept($code, $vb), 'No existe');
check_error('comercial B no puede duplicar la de A', fn() => config_copy($code, $vb), 'No existe');
check('la lista de B no incluye las de A', fn() => eq(0, config_list($vb, [])['total']));
check('la lista de A tiene las suyas', fn() => eq(3, config_list($va, [])['total']));
check('el admin ve todas', fn() => eq($code, config_get($code, $vadmin)['code']) && eq(3, config_list($vadmin, [])['total']));
$guest1 = ['kind' => 'guest', 'codes' => []];
$g = config_create($c['blanco'], $guest1);
$guest1['codes'] = $_SESSION['guest_codes'];
check('el invitado ve la suya (por su sesión)', fn() => eq($g['code'], config_get($g['code'], $guest1)['code']) && eq(null, $g['user']));
check_error('otro invitado (user_id NULL también) no la ve', fn() => config_get($g['code'], ['kind' => 'guest', 'codes' => ['UC-AAAAAAAA']]), 'No existe');
check_error('un invitado no ve la de un comercial', fn() => config_get($code, $guest1), 'No existe');
check('la lista del invitado solo tiene la suya', fn() => eq(1, config_list($guest1, [])['total']));
check('filtros de la lista: estado, texto y fechas', function () use ($vadmin) {
    return eq(1, config_list($vadmin, ['q' => 'Lucía'])['total']) && eq(0, config_list($vadmin, ['status' => 'accepted'])['total'])
        && eq(4, config_list($vadmin, ['from' => date('Y-m-d'), 'to' => date('Y-m-d')])['total']) && eq(0, config_list($vadmin, ['to' => '2000-01-01'])['total']);
});

// --- aceptar y congelar
check_error('el invitado NO acepta (aceptar es del equipo): 403 con la salida buena', fn() => config_accept($g['code'], $guest1, ['name' => 'Eva', 'email' => 'eva@x.test', 'phone' => '600 123 123'], true), 'Quiero este presupuesto');
check_error('el invitado pide el presupuesto solo con consentimiento', fn() => config_request($g['code'], $guest1, ['name' => 'Eva', 'phone' => '600 123 123'], false), 'protección de datos');
check('el invitado pide el presupuesto: queda «presupuesto pedido», con precio guardado y un lead', function () use ($g, $guest1) {
    $r = config_request($g['code'], $guest1, ['name' => 'Eva', 'email' => 'eva@x.test', 'phone' => '600 123 123', 'consent' => 1], false);
    throttle_clear('lead:' . client_ip_key());
    return eq('requested', $r['config']['status']) && eq(true, $r['config']['frozen']) && eq('Eva', $r['config']['customer']['name'])
        && eq(true, $r['lead_id'] > 0) && eq('nuevo', val('SELECT work_status FROM leads WHERE id = ?', [$r['lead_id']]));
});
$before = config_get($code, $va);
$acc = config_accept($code, $va);
check('aceptar congela tramo y precio de cada línea, el total y el IVA', function () use ($acc, $before) {
    $l = $acc['walls'][0]['items'][0];
    return eq('accepted', $acc['status']) && eq(true, $acc['frozen']) && eq($before['total_cents'], $acc['total_cents'])
        && eq(21.0, $acc['tax_rate']) && eq($before['walls'][0]['items'][0]['price_cents'], $l['price_cents'])
        && eq($l['tier_from_cm'], (int) (int) val('SELECT tier_from_mm FROM config_items WHERE id = ?', [$l['id']]) / 10);
});
check('el precio congelado NO cambia al cambiar la tabla ni el IVA', function () use ($code, $c, $acc, $va, $vadmin) {
    q('UPDATE price_tiers SET price = price * 3 WHERE module_type_id = ?', [$c['bajo']]);
    settings_save(['iva' => '10']);
    $r = config_get($code, $va);
    $list = array_values(array_filter(config_list($vadmin, ['q' => $code])['items'], fn($i) => $i['code'] === $code))[0];
    $csv = config_export_csv([$r]);
    settings_save(['iva' => '21']);
    return eq($acc['total_cents'], $r['total_cents']) && eq($acc['walls'][0]['items'][0]['price_cents'], $r['walls'][0]['items'][0]['price_cents'])
        && eq(21.0, $r['tax_rate']) && eq($acc['total_cents'], $list['total_cents'])
        && eq(true, str_contains($csv, number_format($acc['walls'][0]['items'][0]['price_cents'] / 100, 2, ',', '')), 'el CSV lee la foto');
});
check('un borrador sí ve los precios nuevos', function () use ($other, $va) {
    return eq(true, config_get($other['code'], $va)['total_cents'] > $other['total_cents']);
});
check_error('una configuración aceptada no se edita (se duplica)', fn() => config_apply_ops($code, [['op' => 'customer', 'name' => 'X']], $va), 'Duplícala');
check_error('no se acepta dos veces', fn() => config_accept($code, $va), 'aceptada');
check('duplicar una aceptada da un borrador nuevo con precios de hoy', function () use ($code, $acc, $va) {
    $d = config_copy($code, $va);
    return eq('draft', $d['status']) && eq(true, $d['code'] !== $code) && eq($acc['count'], $d['count']) && eq(true, $d['total_cents'] > $acc['total_cents']);
});
check_error('no se acepta con una medida sin tramo', function () use ($other, $va) {
    q('UPDATE config_items SET width_mm = 990 WHERE id = ?', [$other['walls'][0]['items'][0]['id']]);
    config_accept($other['code'], $va);
}, '99');
check_error('no se acepta una configuración vacía', function () use ($c, $va) {
    $e = config_create($c['roble'], $va);
    config_accept($e['code'], $va);
}, 'módulo');

// --- anular (admin)
check('anular un borrador congela sus precios (las líneas no se quedan sin precio)', function () use ($c, $va, $vadmin) {
    $d = config_create($c['blanco'], $va);
    $r = config_cancel($d['code'], $vadmin);
    q('UPDATE price_tiers SET price = price + 100');
    $after = config_get($d['code'], $vadmin);
    return eq('cancelled', $r['status']) && eq($d['total_cents'], $after['total_cents']) && eq(true, $after['walls'][0]['items'][0]['price_cents'] !== null)
        && eq($d['walls'][0]['items'][0]['price_cents'], $after['walls'][0]['items'][0]['price_cents']);
});
check_error('un comercial no anula', fn() => config_cancel(config_create($c['blanco'], $va)['code'], $va), 'administrador');
check('el invitado tiene un límite de borradores por conexión', function () use ($c) {
    q("DELETE FROM throttle");
    $g = ['kind' => 'guest', 'codes' => []];
    for ($i = 0; $i < 30; $i++) config_create($c['blanco'], $g);
    try { config_create($c['blanco'], $g); } catch (UcError $e) { q("DELETE FROM throttle"); return eq(429, $e->status); }
    throw new RuntimeException('no limitó');
});

// --- carrera «editar lo aceptado» (revisión de seguridad): con la fila leída ANTES de aceptar, una operación no pasa
check('una operación con la configuración leída antes de aceptar no cambia lo aceptado (409)', function () use ($c, $va) {
    $x = config_create($c['blanco'], $va);
    $stale = row('SELECT * FROM configurations WHERE code = ?', [$x['code']]); // leída cuando aún era borrador
    config_accept($x['code'], $va);
    $item = $x['walls'][0]['items'][0]['id'];
    try { config_apply_one($stale, ['op' => 'replace', 'item_id' => $item, 'module_type_id' => $c['alto']]); }
    catch (UcError $e) { return eq(409, $e->status) && eq($c['bajo'], (int) val('SELECT module_type_id FROM config_items WHERE id = ?', [$item])); }
    throw new RuntimeException('cambió una configuración aceptada');
});
check('lo mismo con customer', function () use ($c, $va) {
    $x = config_create($c['blanco'], $va);
    $stale = row('SELECT * FROM configurations WHERE code = ?', [$x['code']]);
    config_accept($x['code'], $va);
    try { config_apply_one($stale, ['op' => 'customer', 'name' => 'Otro']); } catch (UcError $e) { return eq(409, $e->status) && eq('', val('SELECT customer_name FROM configurations WHERE code = ?', [$x['code']])); }
    throw new RuntimeException('cambió una configuración aceptada');
});

// --- exportación
check('CSV: una fila por módulo, separador ; y fórmulas neutralizadas', function () use ($c, $va) {
    $x = config_create($c['blanco'], $va);
    $x = config_apply_ops($x['code'], [['op' => 'customer', 'name' => '=HYPERLINK("http://malo")']], $va)['config'];
    $csv = config_export_csv([$x]);
    $lines = array_values(array_filter(explode("\r\n", $csv)));
    return eq(3, count($lines)) && eq(true, str_contains($lines[1], ";\"'=HYPERLINK")) && eq(false, (bool) preg_match('/;"?=/', $lines[1])) && eq(true, str_starts_with($csv, "\u{FEFF}"));
});
check('JSON de exportación con geometría, tramos y totales', function () use ($code, $va) {
    $j = json_decode(config_export_json(config_get($code, $va)), true);
    return eq($code, $j['code']) && eq(true, isset($j['walls'][0]['origin_x_mm'], $j['walls'][0]['items'][0]['tier_to_cm'], $j['total'], $j['tax']));
});

done();
