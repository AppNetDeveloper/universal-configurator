<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Acceso: login, límite de intentos, CSRF, roles, registro, invitado, olvido de contraseña (y enlace caducado),
// alta por el admin y la interfaz de proveedores (local + hueco BionicEye con OAuth2 + PKCE).
//   php tests/auth.php

require __DIR__ . '/_helper.php';

settings_save(['mail_transport' => 'log', 'empresa' => 'Cocinas Prueba', 'url_publica' => 'https://conf.test']);
$admin = fx_user('admin', 'admin@x.test');
$ana = fx_user('comercial', 'ana@x.test', 'clave-de-ana');
$mailLog = fn() => (string) @file_get_contents(UC_DATA . '/mail.log');
$lastLink = function () use ($mailLog) {
    preg_match_all('#https?://[^\s]+/restablecer\?token=([a-f0-9]+)#', $mailLog(), $m);
    return end($m[1]) ?: '';
};

// --- login
check('login correcto abre sesión', fn() => eq($ana['id'], login_attempt('ANA@x.test ', 'clave-de-ana')['id']) && eq($ana['id'], current_user()['id']));
session_logout();
check_error('contraseña mala', fn() => login_attempt('ana@x.test', 'mala'), 'incorrectos');
check_error('correo que no existe da el mismo mensaje', fn() => login_attempt('nadie@x.test', 'mala'), 'incorrectos');
check('5 fallos por correo bloquean aunque luego acierte (throttle)', function () {
    for ($i = 0; $i < 4; $i++) { try { login_attempt('ana@x.test', 'mala'); } catch (UcError) {} }
    try { login_attempt('ana@x.test', 'clave-de-ana'); } catch (UcError $e) { return eq(429, $e->status); }
    throw new RuntimeException('no bloqueó');
});
check('el límite por IP también existe (20 intentos)', function () {
    q('DELETE FROM throttle');
    for ($i = 0; $i < 20; $i++) { try { login_attempt("otro$i@x.test", 'mala'); } catch (UcError) {} }
    try { login_attempt('admin@x.test', 'secreta123'); } catch (UcError $e) { q('DELETE FROM throttle'); return eq(429, $e->status); }
    throw new RuntimeException('no bloqueó por IP');
});
check_error('una cuenta desactivada no entra', function () use ($ana) {
    update('users', ['active' => 0], 'id = ?', [$ana['id']]);
    try { login_attempt('ana@x.test', 'clave-de-ana'); } finally { update('users', ['active' => 1], 'id = ?', [$ana['id']]); }
}, 'incorrectos');
check('desactivar a alguien con sesión le cierra la sesión', function () use ($ana) {
    login_attempt('ana@x.test', 'clave-de-ana');
    update('users', ['active' => 0], 'id = ?', [$ana['id']]);
    $r = current_user();
    update('users', ['active' => 1], 'id = ?', [$ana['id']]);
    session_logout();
    return eq(null, $r);
});

// --- CSRF
check('CSRF: el token de la sesión vale; otro o ninguno, no', function () {
    $tok = csrf_token();
    return eq(true, csrf_valid($tok)) && eq(false, csrf_valid('x' . $tok)) && eq(false, csrf_valid('')) && eq(false, csrf_valid(null));
});
check_error('CSRF: un formulario sin _csrf se rechaza (419)', function () { $_POST = []; csrf_check(); }, 'Sesión caducada');
check('el login cambia el token CSRF (no se reutiliza el de antes)', function () {
    $before = csrf_token();
    login_attempt('admin@x.test', 'secreta123');
    $after = csrf_token();
    session_logout();
    return eq(true, $before !== $after);
});

// --- roles
check('roles: admin / comercial / cliente', fn() => eq(true, is_admin($admin)) && eq(false, is_admin($ana)) && eq(false, is_admin(null)));
check_error('rol inventado se rechaza', fn() => user_create('x@x.test', 'X', 'jefe', 'secreta123'), 'Rol');
check_error('no se puede dejar sin admin activo', fn() => user_update((int) $admin['id'], ['role' => 'comercial']), 'único administrador');
check_error('contraseña corta se rechaza', fn() => user_create('corta@x.test', 'C', 'cliente', '1234567'), '8 caracteres');

// --- registro e invitado
check_error('registro cerrado por defecto', fn() => register_user('Eva', 'eva@x.test', 'secreta123'), 'registro');
check('registro abierto crea un cliente con sesión', function () {
    settings_save(['permitir_registro' => '1']);
    $u = register_user('Eva', 'eva@x.test', 'secreta123');
    settings_save(['permitir_registro' => '0']);
    session_logout();
    return eq('cliente', $u['role']);
});
check_error('invitado no permitido por defecto', fn() => guest_start(), 'cuenta');
check('invitado permitido: sesión de invitado sin cuenta', function () {
    settings_save(['permitir_invitado' => '1']);
    guest_start();
    $v = session_viewer();
    settings_save(['permitir_invitado' => '0']);
    $after = session_viewer();
    session_logout();
    return eq('guest', $v['kind']) && eq(null, $after, 'si el admin lo quita, el invitado deja de valer');
});

// --- olvidé mi contraseña
check('olvido: envía un enlace y con él se pone la contraseña nueva (una sola vez)', function () use ($lastLink) {
    password_reset_request('ana@x.test');
    $tok = $lastLink();
    $u = password_reset_consume($tok, 'nueva-clave-1');
    $reuse = false;
    try { password_reset_consume($tok, 'otra-clave-2'); } catch (UcError) { $reuse = true; }
    q('DELETE FROM throttle');
    return eq('ana@x.test', $u['email']) && eq(true, $reuse, 'el enlace no se reutiliza') && eq('ana@x.test', login_attempt('ana@x.test', 'nueva-clave-1')['email']);
});
session_logout();
check('el token se guarda como hash, no en claro', function () use ($lastLink) {
    password_reset_request('admin@x.test');
    $tok = $lastLink();
    return eq(null, val('SELECT 1 FROM password_resets WHERE token_hash = ?', [$tok])) && eq(1, (int) val('SELECT 1 FROM password_resets WHERE token_hash = ?', [hash('sha256', $tok)]));
});
check_error('enlace caducado (más de 1 hora) no vale', function () use ($lastLink) {
    $tok = $lastLink();
    q('UPDATE password_resets SET expires_at = ?', [time() - 1]);
    password_reset_consume($tok, 'clave-nueva-99');
}, 'caducado');
check('sin url_publica y con Host: evil.example no sale ningún enlace (ni correo) y queda aviso', function () use ($mailLog) {
    q('DELETE FROM throttle');
    settings_save(['url_publica' => '']);
    $_SERVER['HTTP_HOST'] = 'evil.example';
    $before = $mailLog();
    password_reset_request('ana@x.test');
    $after = $mailLog();
    $warn = (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'mail.no_public_url'");
    $inviteErr = '';
    try { user_invite('nueva@x.test', 'Nueva', 'comercial'); } catch (UcError $e) { $inviteErr = $e->getMessage(); }
    unset($_SERVER['HTTP_HOST']);
    return eq($before, $after, 'no se envía nada') && eq(false, str_contains($after, 'evil.example')) && eq(true, $warn > 0)
        && eq(true, str_contains($inviteErr, 'URL pública')) && eq(null, user_by_email('nueva@x.test'), 'el alta no se hace a medias');
});
check_error('reset_link sin url_publica no cae a la cabecera Host', function () {
    $_SERVER['HTTP_HOST'] = 'evil.example';
    try { reset_link('abc'); } finally { unset($_SERVER['HTTP_HOST']); }
}, 'URL pública');
check_error('instalar sin dirección pública no instala', fn() => uc_install(['empresa' => 'X', 'admin_email' => 'z@x.test', 'admin_password' => 'secreta123', 'url' => '']), 'URL pública');
check('el enlace del correo usa la dirección pública de Ajustes, no la cabecera Host', function () use ($lastLink, $mailLog) {
    q('DELETE FROM throttle');
    settings_save(['url_publica' => 'https://configurador.empresa.test']);
    $_SERVER['HTTP_HOST'] = 'atacante.test';
    password_reset_request('ana@x.test');
    unset($_SERVER['HTTP_HOST']);
    $log = $mailLog();
    return eq(true, str_contains($log, 'https://configurador.empresa.test/restablecer?token=')) && eq(false, str_contains($log, 'atacante.test'));
});
check('correo desconocido: no se envía nada ni se dice', function () use ($mailLog) {
    $before = strlen($mailLog());
    password_reset_request('nadie@x.test');
    return eq($before, strlen($mailLog()));
});

// --- alta por el admin
check('alta de comercial: sin contraseña hasta que abre su enlace', function () use ($lastLink) {
    [$id, $link, $sent] = user_invite('carla@x.test', 'Carla', 'comercial');
    $noLogin = false;
    try { login_attempt('carla@x.test', ''); } catch (UcError) { $noLogin = true; }
    $tok = $lastLink();
    password_reset_consume($tok, 'clave-de-carla');
    q('DELETE FROM throttle');
    $ok = login_attempt('carla@x.test', 'clave-de-carla');
    session_logout();
    return eq(true, $sent) && eq(true, str_contains($link, $tok)) && eq(true, $noLogin) && eq('comercial', $ok['role'])
        && eq(true, (int) val('SELECT 1') === 1);
});

// --- proveedores de acceso
check('proveedores: local activo; BionicEye oculto sin configurar', function () {
    $ids = array_map(fn($p) => $p->id(), auth_providers_enabled());
    return eq(['local'], $ids) && eq(false, auth_provider('bionicadri')->enabled());
});
check('BionicEye configurado: URL de autorización con PKCE S256 y state', function () {
    settings_save(['bionicadri_url' => 'https://be.cliente.test', 'bionicadri_client_id' => 'abc']);
    $url = auth_provider('bionicadri')->authorizeUrl('http://localhost/auth/bionicadri/callback');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $verifier = $_SESSION['oauth']['bionicadri']['verifier'];
    $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return eq(true, str_starts_with($url, 'https://be.cliente.test/oauth/authorize?')) && eq('S256', $q['code_challenge_method'])
        && eq($expected, $q['code_challenge']) && eq($_SESSION['oauth']['bionicadri']['state'], $q['state']) && eq('code', $q['response_type'])
        && eq(true, strlen($verifier) >= 43);
});
check_error('callback con state distinto: no disponible y no crea nada', fn() => auth_provider('bionicadri')->callback(['state' => 'otro', 'code' => 'x'], 'http://localhost/cb'), 'no válida');
check('callback correcto crea la persona por external_id, sin contraseña local', function () {
    $p = auth_provider('bionicadri');
    $p->authorizeUrl('http://localhost/cb');
    $state = $_SESSION['oauth']['bionicadri']['state'];
    $calls = [];
    $p->http = function (string $method, string $url, array $data, array $headers) use (&$calls) {
        $calls[] = [$method, $url, $data];
        return str_ends_with($url, '/oauth/token') ? ['access_token' => 'tk'] : ['id' => 77, 'email' => 'luis@be.test', 'name' => 'Luis'];
    };
    $u = $p->callback(['state' => $state, 'code' => 'c0de'], 'http://localhost/cb');
    $again = $p; $again->authorizeUrl('http://localhost/cb');
    $u2 = $again->callback(['state' => $_SESSION['oauth']['bionicadri']['state'], 'code' => 'c0de2'], 'http://localhost/cb');
    session_logout();
    return eq('bionicadri', $u['auth_source']) && eq('77', $u['external_id']) && eq(null, $u['password_hash']) && eq($u['id'], $u2['id'], 'la segunda vez enlaza, no duplica')
        && eq(true, isset($calls[0][2]['code_verifier'])) && eq('authorization_code', $calls[0][2]['grant_type']);
});
check_error('BionicEye no se apodera de una cuenta local con el mismo correo', function () {
    $p = auth_provider('bionicadri');
    $p->authorizeUrl('http://localhost/cb');
    $p->http = fn($m, $url) => str_ends_with($url, '/oauth/token') ? ['access_token' => 'tk'] : ['id' => 88, 'email' => 'ana@x.test', 'name' => 'Ana'];
    $p->callback(['state' => $_SESSION['oauth']['bionicadri']['state'], 'code' => 'c'], 'http://localhost/cb');
}, 'Ya existe');
settings_save(['bionicadri_url' => '', 'bionicadri_client_id' => '']);

done();
