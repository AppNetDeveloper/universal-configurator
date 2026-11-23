<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// MCP: token (falta, malo, revocado, dueño desactivado), ámbitos read/write, cada herramienta, errores «smart»
// y que un comercial no ve ni toca las configuraciones de otro.
//   php tests/mcp.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$ana = fx_user('comercial', 'ana@x.test');
$beto = fx_user('comercial', 'beto@x.test');
$tAdminRW = token_create('ADRI admin', ['read', 'write'], (int) $admin['id']);
$tAdminR = token_create('Solo lectura', ['read'], (int) $admin['id']);
$tAna = token_create('ADRI de Ana', ['read', 'write'], (int) $ana['id']);
$tBeto = token_create('ADRI de Beto', ['read', 'write'], (int) $beto['id']);

$id = 0;
$rpc = function (string $token, string $method, array $params = []) use (&$id) {
    [$status, $body] = mcp_process('Bearer ' . $token, json_enc(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]));
    return ['status' => $status] + (array) $body;
};
/** Llama a una herramienta: devuelve [isError, datos|texto]. */
$call = function (string $token, string $tool, array $args = []) use ($rpc) {
    $r = $rpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $args]);
    $res = (array) $r['result'];
    return [$res['isError'], $res['isError'] ? $res['content'][0]['text'] : json_decode(json_enc($res['structuredContent']), true)];
};
$ok = function (array $r) { if ($r[0]) throw new RuntimeException('isError: ' . $r[1]); return $r[1]; };
$err = function (array $r, string $contains) { if (!$r[0]) throw new RuntimeException('se esperaba error'); if (!str_contains($r[1], $contains)) throw new RuntimeException('error distinto: ' . $r[1]); return true; };

// --- token
check('sin token: 401', fn() => eq(401, mcp_process(null, '{}')[0]));
check('token inventado: 401', fn() => eq(401, mcp_process('Bearer uc_' . str_repeat('0', 40), '{}')[0]));
check('el token se guarda como hash y empieza por uc_', fn() => eq(1, preg_match('/^uc_[a-f0-9]{40}$/', $tAna)) && eq(null, val('SELECT 1 FROM mcp_tokens WHERE token_hash = ?', [$tAna])));
check('token revocado: 401', function () use ($admin) {
    $t = token_create('Temporal', ['read'], (int) $admin['id']);
    token_revoke((int) val('SELECT id FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $t)]));
    return eq(401, mcp_process("Bearer $t", '{}')[0]);
});
check('dueño desactivado: el token deja de valer', function () use ($beto, $tBeto) {
    update('users', ['active' => 0], 'id = ?', [$beto['id']]);
    $s = mcp_process("Bearer $tBeto", json_enc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']))[0];
    update('users', ['active' => 1], 'id = ?', [$beto['id']]);
    return eq(401, $s);
});
check('JSON no válido: -32700', fn() => eq(-32700, mcp_process("Bearer $tAna", '{no json')[1]['error']['code']));

// --- protocolo
check('initialize: versión, nombre e instrucciones con el flujo', function () use ($rpc, $tAna) {
    $r = (array) $rpc($tAna, 'initialize', ['protocolVersion' => '2025-06-18'])['result'];
    return eq('2025-06-18', $r['protocolVersion']) && eq('universal-configurator', $r['serverInfo']['name'])
        && eq(true, str_contains($r['instructions'], 'config_create') && str_contains($r['instructions'], 'price_check') && str_contains($r['instructions'], 'NO dibuja'));
});
check('tools/list con token write: las 9 herramientas', function () use ($rpc, $tAdminRW) {
    $names = array_column(((array) $rpc($tAdminRW, 'tools/list')['result'])['tools'], 'name');
    sort($names);
    return eq(['catalog_tree', 'config_accept', 'config_create', 'config_get', 'config_list', 'config_update', 'cut_list', 'module_types', 'price_check'], $names);
});
check('tools/list con token read: solo las 6 de lectura', function () use ($rpc, $tAdminR) {
    return eq(6, count(((array) $rpc($tAdminR, 'tools/list')['result'])['tools']));
});
check('config_accept avisa en su descripción de que lo confirma la persona', function () use ($rpc, $tAdminRW) {
    $tools = array_column(((array) $rpc($tAdminRW, 'tools/list')['result'])['tools'], 'description', 'name');
    return eq(true, str_contains($tools['config_accept'], 'la debe confirmar la persona'));
});
check('método desconocido: -32601', fn() => eq(-32601, $rpc($tAna, 'nada/raro')['error']['code']));
check('lote de 2 peticiones', function () use ($tAna) {
    [$s, $b] = mcp_process("Bearer $tAna", json_enc([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]));
    return eq(200, $s) && eq(2, count($b));
});

// --- herramientas de lectura
check('catalog_tree: árbol activo con nodos finales', function () use ($call, $tAna, $c) {
    $t = $call($tAna, 'catalog_tree');
    $tree = $t[1]['tree'];
    $linea = $tree[0]['children'][0];
    return eq('Cocina', $tree[0]['name']) && eq(false, $tree[0]['is_final']) && eq($c['blanco'], $linea['children'][0]['id']) && eq(true, $linea['children'][0]['is_final']);
});
check('module_types: fondo y tramos generales y por nodo', function () use ($call, $tAna, $c, $ok) {
    tier_save($c['bajo'], $c['duo'], 41, 60, 11000);
    $d = $ok($call($tAna, 'module_types'));
    $bajo = array_values(array_filter($d['types'], fn($t) => $t['id'] === $c['bajo']))[0];
    return eq(560, $bajo['depth_mm']) && eq(2, count($bajo['tiers'])) && eq(100.0, $bajo['tiers'][1]['price']) && eq($c['duo'], $bajo['node_tiers'][0]['node_id']);
});
check('module_types con node_id: los tramos que valen en ese nodo', function () use ($call, $tAna, $c, $ok) {
    $d = $ok($call($tAna, 'module_types', ['node_id' => $c['roble']]));
    $bajo = array_values(array_filter($d['types'], fn($t) => $t['id'] === $c['bajo']))[0];
    return eq([[41, 60, 110.0]], array_map(fn($t) => [$t['from_cm'], $t['to_cm'], $t['price']], $bajo['effective_tiers'])) && eq($c['duo'], $bajo['effective_from_node_id']);
});
check('price_check: precio por línea, total y la medida que no vale', function () use ($call, $tAna, $c, $ok) {
    $d = $ok($call($tAna, 'price_check', ['node_id' => $c['blanco'], 'modules' => [['module_type_id' => $c['bajo'], 'width_cm' => 40], ['module_type_id' => $c['bajo'], 'width_cm' => 95]]]));
    return eq(80.0, $d['total']) && eq(false, $d['valid']) && eq(false, $d['lines'][1]['valid']) && eq(true, str_contains($d['lines'][1]['error'], '41–60'));
});
check('price_check en un hueco entre tramos: tramo de arriba con tier_rule next_up y su nota', function () use ($call, $tAna, $c, $ok) {
    $d = $ok($call($tAna, 'price_check', ['node_id' => $c['blanco'], 'modules' => [['module_type_id' => $c['bajo'], 'width_cm' => '40,5']]]));
    $l = $d['lines'][0];
    return eq(true, $l['valid']) && eq('next_up', $l['tier_rule']) && eq(41, $l['tier_from_cm']) && eq('40,5 cm está entre tramos: se cobra como 41–60 cm.', $l['tier_note']) && eq(100.0, $d['total']);
});
check('price_check no guarda nada', fn() => eq(0, (int) val('SELECT COUNT(*) FROM configurations')));

// --- escribir
$created = $ok($call($tAna, 'config_create', ['node_id' => $c['blanco']]))['config'];
$code = $created['code'];
check('config_create: borrador con la composición por defecto, del dueño del token', fn() => eq('draft', $created['status']) && eq($ana['id'], $created['user']['id']) && eq(2, $created['count']));
check('config_get: geometría (mm), módulos (cm), tramo y precio por línea, total', function () use ($call, $tAna, $code, $ok) {
    $d = $ok($call($tAna, 'config_get', ['code' => $code]))['config'];
    $l = $d['walls'][0]['items'][0];
    return eq('x', $d['walls'][0]['direction']) && eq(0, $d['walls'][0]['origin_y_mm']) && eq(40, $l['width_cm']) && eq(30, $l['tier_from_cm']) && eq(80.0, $l['price']) && eq(180.0, $d['total']);
});
$itemId = $created['walls'][0]['items'][1]['id'];
check('config_update: set_width + customer, devuelve el total nuevo', function () use ($call, $tAna, $code, $itemId, $ok) {
    $d = $ok($call($tAna, 'config_update', ['code' => $code, 'ops' => [['op' => 'set_width', 'item_id' => $itemId, 'width_cm' => 45], ['op' => 'customer', 'name' => 'Lucía']]]));
    return eq(180.0, $d['config']['total']) && eq('Lucía', $d['config']['customer']['name']);
});
check('error smart: qué falló (valor), qué vale, un ejemplo y «no se ha hecho nada»', function () use ($call, $tAna, $code, $itemId, $err) {
    $r = $call($tAna, 'config_update', ['code' => $code, 'ops' => [['op' => 'set_width', 'item_id' => $itemId, 'width_cm' => 95]]]);
    return $err($r, '95 cm') && $err($r, '41–60') && $err($r, 'Ejemplo') && $err($r, 'No se ha hecho nada');
});
check('error smart: falta un parámetro obligatorio', fn() => $err($call($tAna, 'config_get', []), 'code'));
check('error smart: operación desconocida', fn() => $err($call($tAna, 'config_update', ['code' => $code, 'ops' => [['op' => 'mover']]]), 'set_width'));
check('herramienta desconocida', fn() => $err($call($tAna, 'config_delete', []), 'desconocida'));

// --- ámbitos
check('token read no puede escribir (y no se crea nada)', function () use ($call, $tAdminR, $c, $err) {
    $before = (int) val('SELECT COUNT(*) FROM configurations');
    $r1 = $err($call($tAdminR, 'config_create', ['node_id' => $c['blanco']]), "'write'");
    return $r1 && eq($before, (int) val('SELECT COUNT(*) FROM configurations'));
});
check('token read no puede cambiar ni aceptar', fn() => $err($call($tAdminR, 'config_update', ['code' => $code, 'ops' => [['op' => 'customer', 'name' => 'X']]]), "'write'")
    && $err($call($tAdminR, 'config_accept', ['code' => $code]), "'write'") && eq('Lucía', val('SELECT customer_name FROM configurations WHERE code = ?', [$code])));
check('token read sí puede leer', fn() => eq($code, $ok($call($tAdminR, 'config_get', ['code' => $code]))['config']['code']));

// --- un comercial no ve ni toca las de otro
check('Beto no ve la de Ana', fn() => $err($call($tBeto, 'config_get', ['code' => $code]), 'No existe'));
check('Beto no la cambia ni la acepta', fn() => $err($call($tBeto, 'config_update', ['code' => $code, 'ops' => [['op' => 'customer', 'name' => 'Hack']]]), 'No existe')
    && $err($call($tBeto, 'config_accept', ['code' => $code]), 'No existe') && eq('Lucía', val('SELECT customer_name FROM configurations WHERE code = ?', [$code])));
check('Beto no la duplica con copy_of', fn() => $err($call($tBeto, 'config_create', ['copy_of' => $code]), 'No existe'));
check('config_list de Beto no la tiene; la de Ana y la del admin, sí', fn() => eq(0, $ok($call($tBeto, 'config_list'))['total'])
    && eq(1, $ok($call($tAna, 'config_list'))['total']) && eq(1, $ok($call($tAdminRW, 'config_list', ['status' => 'draft', 'q' => 'Lucía']))['total']));
check('config_list: filtros y paginación', function () use ($call, $tAna, $c, $ok) {
    $ok($call($tAna, 'config_create', ['node_id' => $c['blanco']]));
    $d = $ok($call($tAna, 'config_list', ['per_page' => 1, 'page' => 2]));
    return eq(2, $d['total']) && eq(2, $d['pages']) && eq(1, count($d['items']));
});
check('config_list con fecha mal escrita: error smart', fn() => $err($call($tAna, 'config_list', ['from' => '23/11/2026']), 'AAAA-MM-DD'));

// --- aceptar
check('config_accept congela el precio y avisa de la confirmación', function () use ($call, $tAna, $code, $c, $ok) {
    $d = $ok($call($tAna, 'config_accept', ['code' => $code]));
    q('UPDATE price_tiers SET price = price * 2');
    $after = $ok($call($tAna, 'config_get', ['code' => $code]))['config'];
    return eq('accepted', $d['config']['status']) && eq(180.0, $after['total']) && eq(true, str_contains($d['note'], 'persona'));
});
check('una aceptada no se edita por MCP', fn() => $err($call($tAna, 'config_update', ['code' => $code, 'ops' => [['op' => 'customer', 'name' => 'X']]]), 'Duplícala'));
check('config_create con copy_of duplica una aceptada en borrador', function () use ($call, $tAna, $code, $ok) {
    $d = $ok($call($tAna, 'config_create', ['copy_of' => $code]))['config'];
    return eq('draft', $d['status']) && eq(360.0, $d['total']);
});
check('el uso del token queda registrado', fn() => eq(true, val('SELECT last_used_at FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $tAna)]) !== null)
    && eq(true, (int) val("SELECT COUNT(*) FROM audit_log WHERE actor LIKE 'MCP:%'") > 0));

// --- parámetros desconocidos: error en TODAS las herramientas (nunca se ignoran)
foreach (['catalog_tree' => [], 'module_types' => [], 'config_list' => [], 'config_get' => ['code' => $code], 'price_check' => ['node_id' => $c['blanco'], 'modules' => []],
    'config_create' => ['node_id' => $c['blanco']], 'config_update' => ['code' => $code, 'ops' => []], 'config_accept' => ['code' => $code], 'cut_list' => ['code' => $code]] as $tool => $args) {
    check("$tool con un parámetro que no existe: error que dice cuál sobra y cuáles valen", function () use ($call, $tAdminRW, $tool, $args, $err) {
        $before = (int) val('SELECT COUNT(*) FROM configurations');
        $r = $call($tAdminRW, $tool, $args + ['sobrante' => 1]);
        return $err($r, "«sobrante»") && $err($r, 'Valen:') && $err($r, 'Ejemplo') && $err($r, 'No se ha hecho nada') && eq($before, (int) val('SELECT COUNT(*) FROM configurations'));
    });
}
check('config_update: campo desconocido dentro de una operación', function () use ($call, $tAna, $c, $ok, $err) {
    $d = $ok($call($tAna, 'config_create', ['node_id' => $c['blanco']]))['config'];
    return $err($call($tAna, 'config_update', ['code' => $d['code'], 'ops' => [['op' => 'customer', 'nombre' => 'X']]]), 'nombre');
});
check('price_check: campo desconocido dentro de un módulo', fn() => $err($call($tAna, 'price_check', ['node_id' => $c['blanco'], 'modules' => [['module_type_id' => $c['bajo'], 'ancho' => 40]]]), 'ancho'));
check('config_create guarda el cliente (customer)', function () use ($call, $tAna, $c, $ok) {
    $d = $ok($call($tAna, 'config_create', ['node_id' => $c['blanco'], 'customer' => ['name' => 'Marta Ruiz', 'email' => 'marta@x.test', 'phone' => '600 123 123']]))['config'];
    return eq(['name' => 'Marta Ruiz', 'email' => 'marta@x.test', 'phone' => '600 123 123'], $d['customer']);
});
check('config_create: customer con un campo que no existe es un error y no crea nada', function () use ($call, $tAna, $c, $err) {
    $before = (int) val('SELECT COUNT(*) FROM configurations');
    return $err($call($tAna, 'config_create', ['node_id' => $c['blanco'], 'customer' => ['nombre' => 'X']]), 'nombre') && eq($before, (int) val('SELECT COUNT(*) FROM configurations'));
});

// --- medidas y despiece por MCP
module_part_save($c['bajo'], ['name' => 'Lateral', 'qty' => 2, 'thickness' => 'T', 'length_expr' => 'H', 'width_expr' => 'D', 'grain' => 'largo']);
module_part_save($c['bajo'], ['name' => 'Base', 'qty' => 1, 'thickness' => 'T', 'length_expr' => 'W - 2*T', 'width_expr' => 'D']);
check('module_types: medidas por defecto, espesores y fórmulas', function () use ($call, $tAna, $c, $ok) {
    $t = array_values(array_filter($ok($call($tAna, 'module_types'))['types'], fn($x) => $x['id'] === $c['bajo']))[0];
    return eq(true, isset($t['width_mm'], $t['height_mm'], $t['depth_mm'], $t['board_mm'], $t['back_mm'])) && eq('W - 2*T', $t['parts'][1]['length_expr']) && eq('T', $t['parts'][1]['thickness']);
});
$dec = $ok($call($tAna, 'config_create', ['node_id' => $c['blanco']]))['config'];
check('config_update con 58,5 cm y config_get con width_mm, height_mm, depth_mm y parts', function () use ($call, $tAna, $dec, $ok) {
    $it = $dec['walls'][0]['items'][1]['id'];
    $ok($call($tAna, 'config_update', ['code' => $dec['code'], 'ops' => [['op' => 'set_width', 'item_id' => $it, 'width_cm' => 58.5]]]));
    $l = $ok($call($tAna, 'config_get', ['code' => $dec['code']]))['config']['walls'][0]['items'][1];
    $base = array_column($l['parts'], null, 'name')['Base'];
    return eq(585, $l['width_mm']) && eq(560, $l['depth_mm']) && eq(true, $l['height_mm'] > 0) && eq(547.0, $base['length_mm']) && eq(19.0, $base['thickness_mm']);
});
check('cut_list: lista de corte agregada con su origen', function () use ($call, $tAna, $dec, $ok) {
    $d = $ok($call($tAna, 'cut_list', ['code' => $dec['code']]));
    $lat = array_values(array_filter($d['cut_list'], fn($r) => $r['name'] === 'Lateral'))[0];
    return eq(4, $lat['qty']) && eq(2, count($lat['from'])) && eq(true, isset($lat['from'][0]['item_id'], $lat['from'][0]['module']));
});
check('cut_list de otro comercial: No existe', fn() => $err($call($tBeto, 'cut_list', ['code' => $dec['code']]), 'No existe'));
check('initialize dice cómo dibujar en CAD', function () use ($rpc, $tAna) {
    return eq(true, (bool) stripos(((array) $rpc($tAna, 'initialize')['result'])['instructions'], 'para dibujar en CAD usa config_get (medidas y despiece) o cut_list'));
});

done();
