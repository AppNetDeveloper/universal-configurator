<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// API JSON de la tablet (/api/*): sesión + CSRF por cabecera, crear, cambiar, aceptar y duplicar,
// y que un comercial no ve ni toca las configuraciones de otro.
//   php tests/api.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$ana = fx_user('comercial', 'ana@x.test');
$beto = fx_user('comercial', 'beto@x.test');
$admin = fx_user('admin', 'admin@x.test');
$as = function (?array $u) { $_SESSION = []; if ($u) $_SESSION['uid'] = (int) $u['id']; return csrf_token(); };
$api = fn(string $m, string $path, array $body = [], ?string $csrf = null) => api_dispatch($m, $path, $body, $csrf);

// --- sin sesión / sin CSRF
$as(null);
check('sin sesión: 401 al crear', fn() => eq(401, $api('POST', '/api/configurations', ['node_id' => $c['blanco']], csrf_token())[0]));
check('sin sesión: 401 al leer el catálogo', fn() => eq(401, $api('GET', '/api/nodes')[0]));
$tokA = $as($ana);
check('POST sin cabecera CSRF: 419 y no se crea nada', fn() => eq(419, $api('POST', '/api/configurations', ['node_id' => $c['blanco']])[0]) && eq(0, (int) val('SELECT COUNT(*) FROM configurations')));
check('POST con CSRF de otra sesión: 419', fn() => eq(419, $api('POST', '/api/configurations', ['node_id' => $c['blanco']], 'deadbeef')[0]));
check('ruta desconocida: 404', fn() => eq(404, $api('GET', '/api/nada')[0]));

// --- catálogo
check('nodos raíz e hijos con is_final', function () use ($api, $c) {
    [$s, $b] = $api('GET', '/api/nodes', ['parent' => (string) $c['linea']]);
    return eq(200, $s) && eq('Blanco', $b['nodes'][0]['name']) && eq(true, $b['nodes'][0]['is_final']) && eq('Cocina', $b['path'][0]['name']);
});
check('tipos de módulo con los tramos que valen en el nodo', function () use ($api, $c) {
    [$s, $b] = $api('GET', '/api/module-types', ['node' => (string) $c['blanco']]);
    $bajo = array_values(array_filter($b['types'], fn($t) => $t['name'] === 'Bajo'))[0];
    return eq(200, $s) && eq(2, count($b['types'])) && eq(2, count($bajo['tiers'])) && eq(60, $bajo['suggested_width_cm']);
});

// --- crear, cambiar, aceptar
[$s, $b] = $api('POST', '/api/configurations', ['node_id' => $c['blanco']], $tokA);
$code = $b['config']['code'] ?? '';
$item = $b['config']['walls'][0]['items'][1]['id'] ?? 0;
check('Ana crea su configuración (201)', fn() => eq(201, $s) && eq(180.0, $b['config']['total']));
check('cambiar el ancho devuelve el total calculado en el servidor', function () use ($api, $code, $item, $tokA) {
    [$s, $b] = $api('POST', "/api/configurations/$code/ops", ['op' => 'set_width', 'item_id' => $item, 'width_cm' => 41], $tokA);
    return eq(200, $s) && eq(180.0, $b['config']['total']) && eq(41, $b['config']['walls'][0]['items'][1]['width_cm']);
});
check('medida sin tramo: 422 con los tramos posibles y la línea', function () use ($api, $code, $item, $tokA) {
    [$s, $b] = $api('POST', "/api/configurations/$code/ops", ['ops' => [['op' => 'set_width', 'item_id' => $item, 'width_cm' => 64]]], $tokA);
    return eq(422, $s) && eq(false, $b['ok']) && eq(2, count($b['tiers'])) && eq($item, $b['item_id']) && eq(true, str_contains($b['error'], '64'));
});

// --- Beto no ve ni toca lo de Ana
$tokB = $as($beto);
check('Beto: GET de la de Ana → 404', fn() => eq(404, $api('GET', "/api/configurations/$code")[0]));
check('Beto: cambiarla → 404 y no cambia', fn() => eq(404, $api('POST', "/api/configurations/$code/ops", ['op' => 'customer', 'name' => 'Hack'], $tokB)[0])
    && eq('', val('SELECT customer_name FROM configurations WHERE code = ?', [$code])));
check('Beto: aceptarla → 404', fn() => eq(404, $api('POST', "/api/configurations/$code/accept", [], $tokB)[0]) && eq('draft', val('SELECT status FROM configurations WHERE code = ?', [$code])));
check('Beto: duplicarla → 404', fn() => eq(404, $api('POST', "/api/configurations/$code/duplicate", [], $tokB)[0]));
check('Beto: su lista no la tiene', fn() => eq(0, $api('GET', '/api/configurations')[1]['total']));
check('Beto no puede usar el CSRF de Ana', fn() => eq(419, $api('POST', '/api/configurations', ['node_id' => $c['blanco']], $tokA)[0]));

// --- vuelve Ana y acepta
$tokA = $as($ana);
check('aceptar (200) congela; luego cambiarla → 409 «duplícala»', function () use ($api, $code, $tokA) {
    [$s, $b] = $api('POST', "/api/configurations/$code/accept", ['name' => 'Lucía'], $tokA);
    [$s2, $b2] = $api('POST', "/api/configurations/$code/ops", ['op' => 'remove', 'item_id' => 1], $tokA);
    return eq(200, $s) && eq('accepted', $b['config']['status']) && eq('Lucía', $b['config']['customer']['name']) && eq(409, $s2) && eq(true, str_contains($b2['error'], 'Duplícala'));
});
check('duplicar la aceptada da un borrador nuevo (201)', function () use ($api, $code, $tokA) {
    [$s, $b] = $api('POST', "/api/configurations/$code/duplicate", [], $tokA);
    return eq(201, $s) && eq('draft', $b['config']['status']) && eq(true, $b['config']['code'] !== $code);
});
check('el admin ve la de Ana', function () use ($as, $api, $code) { $as($GLOBALS['admin']); return eq(200, $api('GET', "/api/configurations/$code")[0]); });

// --- invitado
check('invitado no permitido: 401', function () use ($as, $api, $c) { $t = $as(null); $_SESSION['guest'] = 1; return eq(401, $api('POST', '/api/configurations', ['node_id' => $c['blanco']], $t)[0]); });
check('invitado permitido: crea, ve la suya y acepta con datos y consentimiento', function () use ($as, $api, $c, $code) {
    settings_save(['permitir_invitado' => '1']);
    $t = $as(null);
    $_SESSION['guest'] = 1;
    [$s, $b] = $api('POST', '/api/configurations', ['node_id' => $c['blanco']], $t);
    $gcode = $b['config']['code'];
    $other = $api('GET', "/api/configurations/$code")[0];
    [$s2] = $api('POST', "/api/configurations/$gcode/accept", ['name' => 'Eva', 'email' => 'eva@x.test', 'phone' => '600'], $t);
    [$s3, $b3] = $api('POST', "/api/configurations/$gcode/accept", ['name' => 'Eva', 'email' => 'eva@x.test', 'phone' => '600', 'consent' => true], $t);
    settings_save(['permitir_invitado' => '0']);
    return eq(201, $s) && eq(404, $other) && eq(422, $s2) && eq(200, $s3) && eq('accepted', $b3['config']['status']);
});

done();
