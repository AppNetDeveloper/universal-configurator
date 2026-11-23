<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Arreglos de la revisión de seguridad: tiempo del login, bloqueo ajeno, sesiones tras cambiar la contraseña,
// registro con verificación, ramas desactivadas, crecimiento sin límite, CSV y expresiones enormes.
//   php tests/security.php

define('UC_TESTING', true);
require __DIR__ . '/_helper.php';
file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => 'clave-de-pruebas-0123456789abcdef'];"); // firma de la cookie de confianza

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

// 3. Bloquear el login ajeno y fuerza bruta por cuenta (sin sleep: «siguiente intento permitido» y tope por cuenta)
$GLOBALS['uc_clock_skew'] = 0.0;
$advance = function (float $s) { $GLOBALS['uc_clock_skew'] += $s; };
check('tras un fallo, el siguiente intento a esa cuenta antes de tiempo: 429 al momento, sin dormir', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $as('10.0.0.1');
    $tryLogin('ana@x.test', 'mala');
    $t = hrtime(true);
    $r = $tryLogin('ana@x.test', 'clave-de-ana');
    $ms = (hrtime(true) - $t) / 1e6;
    return eq(429, $r) && eq(true, $ms < 300, sprintf('tardó %.0f ms', $ms));
});
check('la espera es progresiva (1, 2, 4… s) con tope de 60 s, desde la misma IP', function () use ($as, $tryLogin, $advance) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $waits = [];
    for ($i = 0; $i < 8; $i++) {
        $as('10.9.0.1'); q("DELETE FROM throttle WHERE key LIKE 'login-mailip:%'");
        $tryLogin('ana@x.test', 'mala');
        $waits[] = (int) round((float) val('SELECT next_at FROM login_backoff') - clock());
        $advance(61);
    }
    return eq([1, 2, 4, 8, 16, 32, 60, 60], $waits);
});
check('los fallos de un atacante desde su IP no retrasan a la dueña en la suya', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $as('10.66.0.1');
    for ($i = 0; $i < 3; $i++) $tryLogin('ana@x.test', 'mala');
    $as('10.0.0.2');
    return eq('ok', $tryLogin('ana@x.test', 'clave-de-ana'));
});
$trusted = '';
check('tras entrar bien, queda una cookie de dispositivo de confianza firmada para esa cuenta', function () use (&$trusted, $ana) {
    $trusted = (string) ($GLOBALS['uc_trust_cookie'] ?? '');
    return eq(true, $trusted !== '') && eq((int) $ana['id'], trusted_device_user($trusted)) && eq(null, trusted_device_user($trusted . 'x'))
        && eq(null, trusted_device_user(preg_replace('/^\d+/', '999', $trusted)));
});
check('5 fallos desde una IP bloquean ESA IP para ese correo', function () use ($as, $tryLogin, $advance) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $as('10.0.0.1');
    for ($i = 0; $i < 5; $i++) { $tryLogin('ana@x.test', 'mala'); $advance(61); }
    return eq(429, $tryLogin('ana@x.test', 'clave-de-ana'));
});
check('tope duro por cuenta: 50 fallos en una hora desde IP distintas → 429 sin cookie de confianza', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    unset($_COOKIE['uc_trust']);
    for ($i = 0; $i < 50; $i++) { $as('10.5.0.' . ($i + 1)); $tryLogin('ana@x.test', 'mala'); }
    $as('10.6.0.1');
    return eq(429, $tryLogin('ana@x.test', 'clave-de-ana'));
});
check('…pero desde su dispositivo de confianza la dueña entra (solo cuenta el límite por IP)', function () use ($as, $tryLogin, &$trusted) {
    $_COOKIE['uc_trust'] = $trusted;
    $as('10.6.0.2');
    $r = $tryLogin('ana@x.test', 'clave-de-ana');
    unset($_COOKIE['uc_trust']);
    return eq('ok', $r);
});
check('la cookie de confianza deja de valer al restablecer o cambiar la contraseña (va firmada con session_version)', function () use (&$trusted, $ana) {
    $before = trusted_device_user($trusted);
    user_set_password((int) $ana['id'], 'clave-de-ana'); // misma clave, pero cambia session_version
    $after = trusted_device_user($trusted);
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    login_attempt('ana@x.test', 'clave-de-ana'); session_logout();
    $trusted = (string) $GLOBALS['uc_trust_cookie']; // la nueva, tras entrar otra vez
    return eq((int) $ana['id'], $before) && eq(null, $after) && eq((int) $ana['id'], trusted_device_user($trusted));
});
check('con la cookie, 3 fallos seguidos no la revocan; el 4.º sí, y ese dispositivo vuelve a las reglas normales', function () use ($as, $tryLogin, &$trusted) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $_COOKIE['uc_trust'] = $trusted;
    $as('10.8.0.1');
    for ($i = 0; $i < 3; $i++) $tryLogin('ana@x.test', 'mala');
    $still = trusted_device_ok($trusted, 'ana@x.test');
    $ok = $tryLogin('ana@x.test', 'clave-de-ana'); // sin espera: sigue siendo de confianza
    $_COOKIE['uc_trust'] = $trusted = (string) $GLOBALS['uc_trust_cookie'];
    for ($i = 0; $i < 4; $i++) $tryLogin('ana@x.test', 'mala');
    $revoked = !trusted_device_ok($trusted, 'ana@x.test');
    $tryLogin('ana@x.test', 'mala'); // ya sin confianza: este fallo deja espera para esta IP
    $now = $tryLogin('ana@x.test', 'clave-de-ana');
    unset($_COOKIE['uc_trust']);
    return eq(true, $still) && eq('ok', $ok) && eq(true, $revoked) && eq(429, $now);
});
check('la cookie tiene su propio presupuesto: más de 10 fallos en una hora y deja de eximir', function () use ($as, $tryLogin, $advance) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $as('10.8.1.1');
    login_attempt('ana@x.test', 'clave-de-ana'); session_logout();
    $cookie = (string) $GLOBALS['uc_trust_cookie'];
    for ($i = 0; $i < 4; $i++) { // 3 fallos y un acierto, cuatro veces: nunca 4 seguidos, pero 12 fallos en la hora
        $_COOKIE['uc_trust'] = $cookie;
        for ($j = 0; $j < 3; $j++) { $as('10.8.2.' . ($i * 3 + $j + 1)); $tryLogin('ana@x.test', 'mala'); }
        $as('10.8.3.' . ($i + 1));
        $tryLogin('ana@x.test', 'clave-de-ana');
        if (isset($GLOBALS['uc_trust_cookie'])) $cookie = (string) $GLOBALS['uc_trust_cookie'];
    }
    $r = trusted_device_ok($cookie, 'ana@x.test');
    unset($_COOKIE['uc_trust']);
    return eq(false, $r);
});
check('la cookie de confianza de OTRA cuenta no salta el tope', function () use ($as, $tryLogin, &$trusted, $admin) {
    $_COOKIE['uc_trust'] = $trusted; // es de Ana
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    for ($i = 0; $i < 50; $i++) { $as('10.7.0.' . ($i + 1)); $tryLogin('admin@x.test', 'mala'); }
    $as('10.7.1.1');
    $r = $tryLogin('admin@x.test', 'secreta123');
    unset($_COOKIE['uc_trust']);
    return eq(429, $r);
});
check('con la cuenta bloqueada, restablecer por correo funciona y quita el bloqueo de la cuenta', function () use ($lastToken, $as, $tryLogin) {
    $as('10.6.0.9');
    password_reset_request('ana@x.test');
    $u = password_reset_consume($lastToken(), 'clave-de-ana');
    $h = hash('sha256', 'ana@x.test');
    $left = (int) val('SELECT COUNT(*) FROM login_backoff WHERE key LIKE ?', [$h . ':%']) + (int) val('SELECT COUNT(*) FROM throttle WHERE key = ?', ['login-fails:' . $h]);
    return eq('ana@x.test', $u['email']) && eq(0, $left) && eq('ok', $tryLogin('ana@x.test', 'clave-de-ana'));
});
q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
check('IPv6: las claves de IP agrupan por /64', function () {
    $k = function (string $ip) { $_SERVER['REMOTE_ADDR'] = $ip; return client_ip_key(); };
    return eq($k('2001:db8:1:2::1'), $k('2001:db8:1:2:ffff:ffff:ffff:ffff')) && eq(true, $k('2001:db8:1:2::1') !== $k('2001:db8:1:3::1'))
        && eq('10.0.0.1', $k('10.0.0.1'));
});
check('el registro de actividad no crece con cada intento ya bloqueado (una fila por ventana)', function () use ($as, $tryLogin) {
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
    $as('10.3.0.1');
    $before = (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.login_throttled'");
    for ($i = 0; $i < 30; $i++) $tryLogin('ana@x.test', 'mala');
    return eq($before + 1, (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.login_throttled'"));
});
$as('127.0.0.1');
q('DELETE FROM throttle'); q('DELETE FROM login_backoff');

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
check('registrarse con un correo que ya existe: mismo mensaje, aviso al dueño con /olvide y SIN token nuevo', function () use ($mailLog) {
    q('DELETE FROM throttle');
    $before = strlen($mailLog());
    $tokens = (int) val('SELECT COUNT(*) FROM password_resets');
    $msg = register_user('Otra', 'ana@x.test');
    $new = substr($mailLog(), $before);
    return eq(REGISTER_MESSAGE, $msg) && eq(true, str_contains($new, 'Para: ana@x.test') && str_contains($new, 'Alguien ha intentado registrarse') && str_contains($new, 'https://conf.test/olvide'))
        && eq(false, str_contains($new, '/restablecer?token=') || str_contains($new, 'Otra')) && eq($tokens, (int) val('SELECT COUNT(*) FROM password_resets'));
});
check('esos avisos cuentan en el límite de correos de restablecer (no se puede bombardear)', function () use ($mailLog) {
    q('DELETE FROM throttle');
    $before = substr_count($mailLog(), 'Para: ana@x.test');
    for ($i = 0; $i < 6; $i++) register_user('X', 'ana@x.test');
    password_reset_request('ana@x.test');
    q('DELETE FROM throttle');
    return eq(3, substr_count($mailLog(), 'Para: ana@x.test') - $before);
});
check('un restablecer no invalida una invitación viva de 72 h', function () use ($lastToken) {
    q('DELETE FROM throttle');
    [$id, $link] = user_invite('ines@x.test', 'Inés', 'comercial');
    $invite = substr($link, strpos($link, 'token=') + 6);
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('provisional-1', PASSWORD_BCRYPT), $id]); // para que pueda pedir restablecer
    password_reset_request('ines@x.test');
    $u = password_reset_user($invite);
    q('DELETE FROM throttle');
    return eq($id, (int) ($u['id'] ?? 0));
});
check('el registro tarda lo mismo exista o no el correo (los dos caminos envían un correo; mínimo 300 ms)', function () {
    q('DELETE FROM throttle');
    $t = function (string $email) { $s = hrtime(true); register_user('X', $email); return (hrtime(true) - $s) / 1e6; };
    $a = [$t('ana@x.test'), $t('admin@x.test')];
    $b = [$t('nuevo1@x.test'), $t('nuevo2@x.test')];
    $avgA = array_sum($a) / 2; $avgB = array_sum($b) / 2;
    fwrite(STDOUT, sprintf("     (registro: correo existente %.0f ms, nuevo %.0f ms)\n", $avgA, $avgB));
    q('DELETE FROM throttle');
    return eq(true, min([...$a, ...$b]) >= 300, 'mínimo 300 ms') && eq(true, abs($avgA - $avgB) < 0.35 * max($avgA, $avgB), sprintf('existe %.0f ms, nuevo %.0f ms', $avgA, $avgB));
});
check('el correo de activación (correo sin verificar) no lleva el nombre ni texto libre', function () use ($mailLog) {
    $before = strlen($mailLog());
    register_user("Gana 1000€ en http://malo.example y www.otro.example\nURGENTE", 'victima@x.test');
    $new = substr($mailLog(), $before);
    return eq(true, str_contains($new, 'Hola:') && str_contains($new, setting('empresa'))) && eq(false, str_contains($new, 'malo') || str_contains($new, 'Gana') || str_contains($new, 'URGENTE'));
});
check('nombre en un correo: una línea, sin URL ni dominios, como mucho 60 caracteres', function () {
    $n = mail_name("Ana\nVisita https://malo.example/x www.otro.example o banco.example.com ya " . str_repeat('x', 100));
    return eq(false, str_contains($n, "\n") || str_contains($n, 'http') || str_contains($n, 'www.') || str_contains($n, '.example')) && eq(true, mb_strlen($n) <= 60) && eq(true, str_starts_with($n, 'Ana'));
});
check('registro nuevo: la cuenta no entra hasta verificar el correo', function () use ($tryLogin) {
    $msg = register_user('Eva', 'eva@x.test');
    $u = user_by_email('eva@x.test');
    return eq(REGISTER_MESSAGE, $msg) && eq(0, (int) $u['active']) && eq(1, (int) $u['pending_verification']) && eq(null, $u['password_hash'])
        && eq(true, $tryLogin('eva@x.test', '') !== 'ok') && eq(null, session_viewer());
    q('DELETE FROM login_backoff');
});
check('con el enlace del correo pone su contraseña y la cuenta se activa', function () use ($lastToken, $tryLogin) {
    password_reset_consume($lastToken(), 'clave-de-eva');
    q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
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
