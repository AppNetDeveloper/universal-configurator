<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Arreglos de la revisión de seguridad: tiempo del login, bloqueo ajeno, sesiones tras cambiar la contraseña,
// registro con verificación, ramas desactivadas, crecimiento sin límite, CSV y expresiones enormes.
//   php tests/security.php

define('UC_TESTING', true);
require __DIR__ . '/_helper.php';

settings_save(['mail_transport' => 'log', 'url_publica' => 'https://conf.test']);
$c = fx_catalog();
$ana = fx_user('comercial', 'ana@x.test', 'clave-de-ana');
$admin = fx_user('admin', 'admin@x.test');
$mailLog = fn() => (string) @file_get_contents(UC_DATA . '/mail.log');
$lastToken = function () use ($mailLog) { preg_match_all('#/restablecer\?token=([a-f0-9]+)#', $mailLog(), $m); return end($m[1]) ?: ''; };
$as = function (string $ip) { $_SERVER['REMOTE_ADDR'] = $ip; };
$tryLogin = function (string $email, string $pw) { try { login_attempt($email, $pw); session_logout(); return 'ok'; } catch (UcError $e) { return $e->status; } };

// 2. El login tarda lo mismo exista o no el correo (hash de relleno precalculado)
check('hash de relleno: constante bcrypt precalculada, no se calcula en cada petición', function () {
    $info = password_get_info(LOGIN_DUMMY_HASH);
    return eq('bcrypt', $info['algoName']) && eq(LOGIN_DUMMY_HASH, login_dummy_hash());
});
check('login_dummy_hash no calcula ningún hash (cada petición web es un proceso nuevo)', function () {
    $src = (string) (new ReflectionFunction('login_dummy_hash'))->getFileName();
    $f = new ReflectionFunction('login_dummy_hash');
    $body = implode('', array_slice(file($src), $f->getStartLine() - 1, $f->getEndLine() - $f->getStartLine() + 1));
    return eq(false, str_contains($body, 'password_hash('));
});

// 3. Bloquear el login ajeno: el bloqueo es por correo+IP; por correo solo, frena (retardo) sin cerrar
check('5 fallos desde una IP bloquean ESA IP para ese correo…', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle');
    $as('10.0.0.1');
    for ($i = 0; $i < 5; $i++) $tryLogin('ana@x.test', 'mala');
    return eq(429, $tryLogin('ana@x.test', 'clave-de-ana'));
});
check('…pero la dueña entra desde su IP', function () use ($as, $tryLogin) { $as('10.0.0.2'); return eq('ok', $tryLogin('ana@x.test', 'clave-de-ana')); });
check('muchos fallos desde muchas IP: frena (retardo) pero no cierra la cuenta', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle');
    for ($i = 0; $i < 40; $i++) { $as("10.1.0.$i"); $tryLogin('ana@x.test', 'mala'); }
    $GLOBALS['uc_login_delay'] = 0;
    $as('10.2.0.1');
    $r = $tryLogin('ana@x.test', 'clave-de-ana');
    return eq('ok', $r) && eq(true, $GLOBALS['uc_login_delay'] > 0, 'hubo retardo');
});
check('el registro de actividad no crece con cada intento ya bloqueado (una fila por ventana)', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle');
    $as('10.3.0.1');
    $before = (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.login_throttled'");
    for ($i = 0; $i < 30; $i++) $tryLogin('ana@x.test', 'mala');
    return eq($before + 1, (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.login_throttled'"));
});
$as('127.0.0.1');
q('DELETE FROM throttle');

// 4. Cambiar la contraseña (o desactivar) cierra las demás sesiones
check('restablecer la contraseña cierra las sesiones abiertas', function () use ($lastToken) {
    login_attempt('ana@x.test', 'clave-de-ana');
    $other = $_SESSION; // la sesión de otro dispositivo
    password_reset_request('ana@x.test');
    password_reset_consume($lastToken(), 'clave-nueva-1');
    $_SESSION = $other;
    $r = current_user();
    session_logout();
    q('DELETE FROM throttle');
    return eq(null, $r);
});
check('desactivar y reactivar no deja viva la sesión de antes', function () use ($ana) {
    login_attempt('ana@x.test', 'clave-nueva-1');
    $other = $_SESSION;
    user_update((int) $ana['id'], ['active' => false]);
    user_update((int) $ana['id'], ['active' => true]);
    $_SESSION = $other;
    $r = current_user();
    session_logout();
    return eq(null, $r);
});
check('la sesión propia sigue viva tras restablecer', function () use ($lastToken) {
    password_reset_request('ana@x.test');
    $u = password_reset_consume($lastToken(), 'clave-nueva-2');
    session_login($u);
    $r = current_user();
    session_logout();
    q('DELETE FROM throttle');
    return eq($u['id'], $r['id'] ?? null);
});

// 5. Registro con verificación del correo y sin delatar cuentas
settings_save(['permitir_registro' => '1']);
check('registrarse con un correo que ya existe da el mismo mensaje y no envía nada', function () use ($mailLog) {
    $before = strlen($mailLog());
    $msg = register_user('Otra', 'ana@x.test');
    return eq(REGISTER_MESSAGE, $msg) && eq($before, strlen($mailLog()));
});
check('registro nuevo: la cuenta no entra hasta verificar el correo', function () use ($tryLogin) {
    $msg = register_user('Eva', 'eva@x.test');
    $u = user_by_email('eva@x.test');
    return eq(REGISTER_MESSAGE, $msg) && eq(0, (int) $u['active']) && eq(1, (int) $u['pending_verification']) && eq(null, $u['password_hash'])
        && eq(true, $tryLogin('eva@x.test', '') !== 'ok') && eq(null, session_viewer());
});
check('con el enlace del correo pone su contraseña y la cuenta se activa', function () use ($lastToken, $tryLogin) {
    password_reset_consume($lastToken(), 'clave-de-eva');
    q('DELETE FROM throttle');
    $u = user_by_email('eva@x.test');
    return eq(1, (int) $u['active']) && eq(0, (int) $u['pending_verification']) && eq('ok', $tryLogin('eva@x.test', 'clave-de-eva'));
});
check('una invitación del admin reclama un correo registrado SIN verificar', function () {
    register_user('Intruso', 'carla@x.test');
    [$id, $link] = user_invite('carla@x.test', 'Carla', 'comercial');
    $u = user_by_id($id);
    return eq('comercial', $u['role']) && eq('Carla', $u['name']) && eq(0, (int) $u['pending_verification']) && eq(1, (int) $u['active']);
});
check_error('una invitación a un correo YA verificado sigue siendo un error', fn() => user_invite('eva@x.test', 'Eva', 'comercial'), 'Ya existe');
settings_save(['permitir_registro' => '0']);

// 6. Precios de ramas desactivadas
node_set_active($c['duo'], false);
tier_save($c['bajo'], $c['roble'], 41, 60, 11100);
$_SESSION = ['uid' => (int) $admin['id'], 'sv' => (int) user_by_id((int) $admin['id'])['session_version']];
check('API /api/module-types de un nodo de una rama desactivada: 404', fn() => eq(404, api_dispatch('GET', '/api/module-types', ['node' => (string) $c['roble']], null)[0]));
$tok = token_create('t', ['read'], (int) $admin['id']);
$mcpCall = function (string $tool, array $args) use ($tok) {
    $r = json_decode(json_enc(mcp_process("Bearer $tok", json_enc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]]))[1]), true)['result'];
    return [$r['isError'], $r['isError'] ? $r['content'][0]['text'] : json_decode(json_enc($r['structuredContent']), true)];
};
check('MCP module_types con node_id de rama desactivada: error', fn() => eq(true, $mcpCall('module_types', ['node_id' => $c['roble']])[0]));
check('MCP price_check en rama desactivada: error', fn() => eq(true, $mcpCall('price_check', ['node_id' => $c['roble'], 'modules' => [['module_type_id' => $c['bajo'], 'width_cm' => 50]]])[0]));
check('MCP module_types no enseña node_tiers de ramas desactivadas', function () use ($mcpCall, $c) {
    $t = array_values(array_filter($mcpCall('module_types', [])[1]['types'], fn($x) => $x['id'] === $c['bajo']))[0];
    return eq([], $t['node_tiers']);
});
node_set_active($c['duo'], true);
$_SESSION = [];

// 7. Crecimiento sin límite
check('la tabla throttle purga lo caducado', function () {
    for ($i = 0; $i < 300; $i++) q('INSERT INTO throttle (key, hits, reset_at) VALUES (?, 1, ?)', ["viejo-$i", time() - 10]);
    throttle_purge();
    return eq(0, (int) val("SELECT COUNT(*) FROM throttle WHERE key LIKE 'viejo-%'"));
});
check('duplicar como invitado también tiene límite', function () use ($c) {
    settings_save(['permitir_invitado' => '1']);
    q('DELETE FROM throttle');
    $g = ['kind' => 'guest', 'codes' => []];
    $first = config_create($c['blanco'], $g);
    $g['codes'] = $_SESSION['guest_codes'];
    try { for ($i = 0; $i < 40; $i++) config_copy($first['code'], $g); } catch (UcError $e) { settings_save(['permitir_invitado' => '0']); return eq(429, $e->status); }
    throw new RuntimeException('no limitó');
});

// 8. CSV: comas y fórmulas en cada trozo
check('CSV: se entrecomilla si hay coma', fn() => eq('"a,b"', csv_cell('a,b')));
check('CSV: fórmula tras una coma o un punto y coma también se neutraliza', function () {
    $x = csv_cell('Pérez, =HYPERLINK("http://malo")');
    $y = csv_cell("ok;@SUM(1)");
    $z = csv_cell("ok, \t+1");
    return eq(true, str_contains($x, ", '=HYPERLINK")) && eq(true, str_contains($y, ";'@SUM")) && eq(true, str_contains($z, "'\t+1") || str_contains($z, "\t'+1"));
});
check('CSV: los números negativos con coma decimal quedan tal cual', fn() => eq('"-12,5"', csv_cell('-12,5')));

// 10. Invitado: id de sesión nuevo
check('guest_start pide un id de sesión nuevo', function () {
    settings_save(['permitir_invitado' => '1']);
    $GLOBALS['uc_session_regenerated'] = 0;
    guest_start();
    settings_save(['permitir_invitado' => '0']);
    return eq(1, $GLOBALS['uc_session_regenerated']);
});

// 11. deploy/apache.example arranca: ningún comentario detrás de una directiva
check('apache.example: los comentarios van en su propia línea', function () {
    foreach (file(dirname(__DIR__) . '/deploy/apache.example') as $n => $l) {
        if (preg_match('/^\s*[A-Za-z<][^#]*\S\s+#/', $l)) throw new RuntimeException('línea ' . ($n + 1) . ': ' . trim($l));
    }
    return true;
});

// Intérprete: topes contra expresiones enormes
check_error('cadena de 150 signos menos: tope de anidamiento', fn() => expr_value(str_repeat('-', 150) . 'W', ['W' => 1]), 'anidad');
check_error('producto que se dispara: resultado demasiado grande', fn() => expr_value('W*W*W*W*W*W', ['W' => 99999]), 'grande');
check_error('INF/NAN no se cuelan', fn() => expr_value('INF + NAN', []), 'desconocida');

done();
