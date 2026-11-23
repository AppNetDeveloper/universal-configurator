<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Acceso local: usuarios, contraseñas, sesión, registro, invitado, «olvidé mi contraseña» y roles.
 * Roles: admin (todo) · comercial (configura y ve las suyas) · cliente (configura y ve las suyas).
 * Invitado (si permitir_invitado): sin cuenta; solo ve las configuraciones creadas en SU sesión.
 */

const ROLES = ['admin' => 'Administrador', 'comercial' => 'Comercial', 'cliente' => 'Cliente'];
const RESET_TTL = 3600;          // «olvidé mi contraseña»: 1 hora
const INVITE_TTL = 72 * 3600;    // alta de una persona por el admin: 72 horas para poner su contraseña
const VERIFY_TTL = 24 * 3600;    // registro libre: 24 horas para verificar el correo y poner la contraseña
const PASSWORD_OPTS = ['cost' => 12];
/** Hash bcrypt (coste 12, como las contraseñas) precalculado: con él se compara cuando el correo no existe, sin calcular nada. */
const LOGIN_DUMMY_HASH = '$2y$12$1KJW4SM9BPOg6bNeFWZWWONbO.qWn6NiqDhuxQp.khtOX5iKesXUi';
const REGISTER_MESSAGE = 'Si el correo es válido, te hemos enviado un enlace para activar tu cuenta y poner tu contraseña (vale 24 horas).';

function user_by_id(int $id): ?array
{
    return row('SELECT * FROM users WHERE id = ?', [$id]);
}

function user_by_email(string $email): ?array
{
    return row('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
}

function validate_password(string $p): void
{
    if (mb_strlen($p) < 8) throw new UcError(t('La contraseña debe tener al menos 8 caracteres.'));
    if (mb_strlen($p) > 200) throw new UcError(t('La contraseña es demasiado larga.'));
}

function user_create(string $email, string $name, string $role, ?string $password = null, string $source = 'local', ?string $externalId = null): int
{
    $email = strtolower(trim($email));
    $name = trim($name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $email]));
    if ($name === '') throw new UcError(t('Escribe el nombre.'));
    if (!isset(ROLES[$role])) throw new UcError(t('Rol «{v}» no válido: admin, comercial o cliente.', ['v' => $role]));
    if ($password !== null) validate_password($password);
    if (user_by_email($email)) throw new UcError(t('Ya existe una cuenta con el correo {v}.', ['v' => $email]));
    $id = insert('users', ['email' => $email, 'name' => mb_substr($name, 0, 120), 'role' => $role,
        'password_hash' => $password === null ? null : password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTS),
        'active' => 1, 'auth_source' => $source, 'external_id' => $externalId, 'created_at' => now()]);
    audit('user.create', "$email ($role)");
    return $id;
}

function user_update(int $id, array $in): void
{
    $u = user_by_id($id) ?? throw new UcError(t('La persona {id} no existe.', ['id' => $id]), [], 404);
    $d = [];
    if (isset($in['name'])) { $n = trim((string) $in['name']); if ($n === '') throw new UcError(t('Escribe el nombre.')); $d['name'] = mb_substr($n, 0, 120); }
    if (isset($in['role'])) { if (!isset(ROLES[$in['role']])) throw new UcError(t('Rol «{v}» no válido: admin, comercial o cliente.', ['v' => (string) $in['role']])); $d['role'] = $in['role']; }
    if (isset($in['active'])) $d['active'] = $in['active'] ? 1 : 0;
    $deactivating = isset($d['active']) && $d['active'] === 0 && (int) $u['active'] === 1;
    // Nunca dejar la instalación sin un admin activo.
    $staysAdmin = ($d['role'] ?? $u['role']) === 'admin' && ($d['active'] ?? (int) $u['active']) === 1;
    if ($u['role'] === 'admin' && $u['active'] && !$staysAdmin && (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") <= 1) {
        throw new UcError(t('Es el único administrador activo: crea otro antes de quitarle el rol o desactivarlo.'));
    }
    if ($d) update('users', $d, 'id = ?', [$id]);
    if ($deactivating) q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]); // cierra sus sesiones
    audit('user.update', $u['email'] . ' ' . json_enc($d));
}

function user_set_password(int $id, string $password): void
{
    validate_password($password);
    // Nueva contraseña: las demás sesiones de esa persona dejan de valer.
    q('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTS), $id]);
}

// ---------------------------------------------------------------- sesión

function current_user(): ?array
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if (!$id) return null;
    $u = user_by_id($id);
    if (!$u || !$u['active'] || (int) ($_SESSION['sv'] ?? -1) !== (int) $u['session_version']) { unset($_SESSION['uid'], $_SESSION['sv']); return null; }
    return $u;
}

/** Id de sesión nuevo (contra la fijación de sesión). */
function session_regenerate(): void
{
    $GLOBALS['uc_session_regenerated'] = ($GLOBALS['uc_session_regenerated'] ?? 0) + 1;
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function session_login(array $user): void
{
    session_regenerate();
    $user = user_by_id((int) $user['id']) ?? $user;
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['sv'] = (int) $user['session_version'];
    unset($_SESSION['guest_codes'], $_SESSION['guest']);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    update('users', ['last_login_at' => now()], 'id = ?', [$user['id']]);
    Actor::set($user['email']);
}

function session_logout(): void
{
    $_SESSION = [];
    session_regenerate();
}

/** Hash para comparar aunque la cuenta no exista (mismo tiempo de respuesta: no revela qué correos existen). */
function login_dummy_hash(): string
{
    return LOGIN_DUMMY_HASH;
}

/**
 * Login local. Límites: por IP 20 intentos / 15 min y por correo+IP 5 / 15 min (bloquean); por correo solo, a partir
 * de 20 / 15 min, frena con un retardo pero NO cierra la cuenta (si no, cualquiera podría dejar fuera a otra persona).
 * Devuelve el usuario o lanza UcError.
 */
function login_attempt(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $mailHash = hash('sha256', $email);
    $ipKey = 'login-ip:' . client_ip();
    $mailIpKey = 'login-mailip:' . $mailHash . ':' . client_ip();
    if (!throttle($ipKey, 20, 900) || !throttle($mailIpKey, 5, 900)) {
        if (throttle('audit-throttled:' . $mailHash . ':' . client_ip(), 1, 900)) audit('user.login_throttled', $email); // una fila por ventana
        throw new UcError(t('Demasiados intentos. Espera 15 minutos y vuelve a probar.'), [], 429);
    }
    if (!throttle('login-mail:' . $mailHash, 20, 900)) {
        $GLOBALS['uc_login_delay'] = 2;
        if (!defined('UC_TESTING')) sleep(2);
    }
    $u = user_by_email($email);
    $ok = password_verify($password, $u['password_hash'] ?? login_dummy_hash());
    if (!$u || !$ok || !$u['active'] || $u['auth_source'] !== 'local' || $u['password_hash'] === null) {
        audit('user.login_failed', $email);
        throw new UcError(t('Correo o contraseña incorrectos.'), [], 401);
    }
    throttle_clear($mailIpKey);
    if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT, PASSWORD_OPTS)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTS), $u['id']]);
    }
    session_login($u);
    audit('user.login', $email);
    return $u;
}

/**
 * Registro libre de clientes (solo si permitir_registro). Solo pide nombre y correo: le llega un enlace (24 h) para
 * verificar el correo y poner su contraseña; hasta entonces la cuenta no está activa y no entra. Responde siempre el
 * mismo mensaje (REGISTER_MESSAGE), exista o no ya una cuenta con ese correo: no delata cuentas.
 */
function register_user(string $name, string $email): string
{
    if (!setting_bool('permitir_registro')) throw new UcError(t('El registro no está abierto. Pide una cuenta a la empresa.'), [], 403);
    if (!throttle('register-ip:' . client_ip(), 10, 3600)) throw new UcError(t('Demasiados registros desde esta conexión. Prueba más tarde.'), [], 429);
    $email = strtolower(trim($email));
    $name = trim($name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => mb_substr($email, 0, 80)]));
    if ($name === '') throw new UcError(t('Escribe tu nombre.'));
    $existing = user_by_email($email);
    if ($existing && !$existing['pending_verification']) { audit('user.register_existing', $email); return t(REGISTER_MESSAGE); }
    if (!throttle('register-mail:' . hash('sha256', $email), 3, 3600)) return t(REGISTER_MESSAGE);
    if (public_url() === null) { audit('mail.no_public_url', "registro de $email: no enviado (falta la URL pública)"); return t(REGISTER_MESSAGE); }
    $id = $existing ? (int) $existing['id'] : (int) tx(function () use ($email, $name) {
        $id = user_create($email, $name, 'cliente', null);
        update('users', ['active' => 0, 'pending_verification' => 1], 'id = ?', [$id]);
        return $id;
    });
    $link = reset_link(password_reset_create($id, VERIFY_TTL));
    try {
        mail_send([$email], t('Activa tu cuenta en {empresa}', ['empresa' => setting('empresa')]), t("Hola {nombre}:\n\nPara activar tu cuenta y poner tu contraseña abre este enlace (vale 24 horas):\n{enlace}\n\nSi no te has registrado tú, ignora este correo.\n", [
            'nombre' => $name, 'enlace' => $link, 'empresa' => setting('empresa')]));
    } catch (Throwable $ex) {
        error_log('[universal-configurator] correo de registro: ' . $ex->getMessage());
    }
    audit('user.register', $email);
    return t(REGISTER_MESSAGE);
}

// ---------------------------------------------------------------- invitado

function guest_allowed(): bool
{
    return setting_bool('permitir_invitado');
}

function guest_start(): void
{
    if (!guest_allowed()) throw new UcError(t('Hace falta entrar con una cuenta para configurar.'), [], 403);
    session_regenerate();
    $_SESSION['guest'] = 1;
    $_SESSION['guest_codes'] ??= [];
    Actor::set('invitado');
}

function is_guest(): bool
{
    return !current_user() && !empty($_SESSION['guest']) && guest_allowed();
}

/**
 * Quién mira (para la visibilidad de configuraciones):
 *  ['kind' => 'user', 'user' => …] · ['kind' => 'guest', 'codes' => […]] · null (nadie).
 */
function session_viewer(): ?array
{
    if ($u = current_user()) return ['kind' => 'user', 'user' => $u];
    if (is_guest()) return ['kind' => 'guest', 'codes' => array_values((array) ($_SESSION['guest_codes'] ?? []))];
    return null;
}

// ---------------------------------------------------------------- contraseñas: olvido, alta e invitación

/** Crea un token de un solo uso (se guarda su hash) y devuelve el token en claro. */
function password_reset_create(int $userId, int $ttl = RESET_TTL): string
{
    $raw = bin2hex(random_bytes(24));
    q('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?', [$userId, time()]);
    insert('password_resets', ['token_hash' => hash('sha256', $raw), 'user_id' => $userId, 'expires_at' => time() + $ttl]);
    return $raw;
}

function reset_link(string $raw): string
{
    return require_public_url() . '/restablecer?token=' . rawurlencode($raw);
}

/** «Olvidé mi contraseña»: siempre la misma respuesta, exista o no la cuenta. */
function password_reset_request(string $email): void
{
    $email = strtolower(trim($email));
    if (!throttle('reset-ip:' . client_ip(), 10, 3600) || !throttle('reset-mail:' . hash('sha256', $email), 3, 3600)) return;
    $u = user_by_email($email);
    if (!$u || !$u['active'] || $u['auth_source'] !== 'local') return;
    if (public_url() === null) {
        // Nunca se cae a la cabecera Host: sin URL pública no sale el correo y queda el aviso.
        audit('mail.no_public_url', "restablecer para $email: no enviado (falta la URL pública en Ajustes)");
        error_log('[universal-configurator] restablecer no enviado: falta url_publica en Ajustes');
        return;
    }
    $link = reset_link(password_reset_create((int) $u['id']));
    try {
        mail_send([$u['email']], t('Restablecer tu contraseña'), t("Hola {nombre}:\n\nPara poner una contraseña nueva abre este enlace (vale 1 hora):\n{enlace}\n\nSi no lo has pedido tú, ignora este correo.\n\n{empresa}", [
            'nombre' => $u['name'], 'enlace' => $link, 'empresa' => setting('empresa')]));
    } catch (Throwable $ex) {
        error_log('[universal-configurator] correo de restablecer: ' . $ex->getMessage());
    }
    audit('user.reset_requested', $email);
}

function password_reset_user(string $raw): ?array
{
    if ($raw === '') return null;
    $r = row('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $raw)]);
    if (!$r) return null;
    if ((int) $r['expires_at'] < time()) {
        q('DELETE FROM password_resets WHERE token_hash = ?', [$r['token_hash']]);
        return null;
    }
    return user_by_id((int) $r['user_id']);
}

function password_reset_consume(string $raw, string $password): array
{
    $u = password_reset_user($raw);
    if (!$u || (!$u['active'] && !$u['pending_verification'])) throw new UcError(t('El enlace no es válido o ha caducado. Pide uno nuevo en «¿Has olvidado tu contraseña?».'), [], 410);
    validate_password($password);
    tx(function () use ($u, $password) {
        user_set_password((int) $u['id'], $password);
        if ($u['pending_verification']) update('users', ['active' => 1, 'pending_verification' => 0], 'id = ?', [$u['id']]); // correo verificado
        q('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
    });
    audit('user.password_reset', $u['email']);
    return user_by_id((int) $u['id']);
}

/** El admin da de alta a una persona sin contraseña: le llega el enlace para ponerla. Devuelve [id, enlace, correo_enviado]. */
function user_invite(string $email, string $name, string $role): array
{
    if (public_url() === null) {
        audit('mail.no_public_url', 'alta de ' . strtolower(trim($email)) . ': no hecha (falta la URL pública en Ajustes)');
        require_public_url();
    }
    [$id, $link] = tx(function () use ($email, $name, $role) {
        $pending = user_by_email($email);
        if ($pending && $pending['pending_verification']) {
            // Un registro libre SIN verificar no ocupa el correo: la invitación lo reclama.
            if (!isset(ROLES[$role])) throw new UcError(t('Rol «{v}» no válido: admin, comercial o cliente.', ['v' => $role]));
            update('users', ['name' => mb_substr(trim($name), 0, 120), 'role' => $role, 'active' => 1, 'pending_verification' => 0, 'password_hash' => null], 'id = ?', [$pending['id']]);
            q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$pending['id']]);
            audit('user.invite_reclaim', strtolower(trim($email)));
            $id = (int) $pending['id'];
        } else {
            $id = user_create($email, $name, $role, null);
        }
        return [$id, reset_link(password_reset_create($id, INVITE_TTL))];
    });
    $sent = true;
    try {
        mail_send([strtolower(trim($email))], t('Tu cuenta en {empresa}', ['empresa' => setting('empresa')]), t("Hola {nombre}:\n\nTe hemos creado una cuenta en el configurador de {empresa}. Para poner tu contraseña abre este enlace (vale 72 horas):\n{enlace}\n", [
            'nombre' => trim($name), 'empresa' => setting('empresa'), 'enlace' => $link]));
    } catch (Throwable $ex) {
        $sent = false;
        error_log('[universal-configurator] correo de alta: ' . $ex->getMessage());
    }
    return [$id, $link, $sent];
}

// ---------------------------------------------------------------- roles

function is_admin(?array $u): bool
{
    return $u !== null && $u['role'] === 'admin';
}
