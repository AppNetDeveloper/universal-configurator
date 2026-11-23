<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Núcleo: configuración, base de datos, utilidades, sesión, CSRF, ajustes y auditoría.
 * Sin dependencias externas: PHP 8.2+ con pdo_sqlite y mbstring.
 */

const UC_VERSION = '0.1.0';
const UC_SCHEMA = 1;
const UC_AUTHOR_URL = 'https://www.bionicadri.com';

/** Crédito de autoría (lo exige la Licencia BionicAdri: no se debe eliminar). */
function uc_credit(): string
{
    return '<span class="uc-credit">' . e(t('Hecho con Universal Configurator')) . ' · Powered by <a href="' . UC_AUTHOR_URL . '" target="_blank" rel="noopener">bionicadri.com</a></span>';
}

/** Error con mensaje seguro para mostrar a la persona o a la IA. $data viaja en la respuesta JSON (p. ej. tramos válidos). */
class UcError extends RuntimeException
{
    public function __construct(string $message, public array $data = [], public int $status = 422)
    {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------- config

function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null; // solo se guarda en caché cuando el archivo existe (tras instalar, se lee en la misma petición)
    if ($cfg === null && is_file(UC_DATA . '/config.php')) $cfg = require UC_DATA . '/config.php';
    $c = $cfg ?? [];
    return $key === null ? $c : ($c[$key] ?? $default);
}

function is_installed(): bool
{
    return is_file(UC_DATA . '/config.php');
}

// ---------------------------------------------------------------- base de datos

function db_path(): string
{
    return UC_DATA . '/configurator.sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . db_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Las claves de $data vienen siempre del código, nunca del usuario. */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $params = []): void
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params]);
}

/** Ejecuta $fn dentro de una transacción (anidable: si ya hay una abierta, solo la usa). */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Esquema. Precios e importes en CÉNTIMOS enteros (price, total): sin deriva de coma flotante.
 * mcp_tokens.user_id (añadido al diseño): el token actúa con el rol y la visibilidad de su dueño.
 */
function migrate(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL COLLATE NOCASE, name TEXT NOT NULL,
        password_hash TEXT NULL, role TEXT NOT NULL CHECK (role IN ('admin','comercial','cliente')),
        active INTEGER NOT NULL DEFAULT 1,
        auth_source TEXT NOT NULL DEFAULT 'local' CHECK (auth_source IN ('local','bionicadri')),
        external_id TEXT NULL, created_at TEXT NOT NULL, last_login_at TEXT NULL);
    CREATE UNIQUE INDEX IF NOT EXISTS users_external ON users (auth_source, external_id) WHERE external_id IS NOT NULL;
    CREATE TABLE IF NOT EXISTS password_resets (
        token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        expires_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS nodes (
        id INTEGER PRIMARY KEY, parent_id INTEGER NULL REFERENCES nodes(id),
        name TEXT NOT NULL, slug TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL DEFAULT '',
        image TEXT NULL, active INTEGER NOT NULL DEFAULT 1);
    CREATE INDEX IF NOT EXISTS nodes_parent ON nodes (parent_id, sort);
    CREATE TABLE IF NOT EXISTS module_types (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, ref TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
        family TEXT NOT NULL DEFAULT '', depth_mm INTEGER NOT NULL DEFAULT 600, active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS price_tiers (
        id INTEGER PRIMARY KEY, module_type_id INTEGER NOT NULL REFERENCES module_types(id) ON DELETE CASCADE,
        node_id INTEGER NULL REFERENCES nodes(id) ON DELETE CASCADE,
        from_cm INTEGER NOT NULL, to_cm INTEGER NOT NULL, price INTEGER NOT NULL CHECK (price >= 0),
        CHECK (from_cm <= to_cm), CHECK (from_cm > 0));
    CREATE INDEX IF NOT EXISTS price_tiers_type ON price_tiers (module_type_id, node_id, from_cm);
    CREATE TABLE IF NOT EXISTS layout_walls (
        id INTEGER PRIMARY KEY, node_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE, name TEXT NOT NULL,
        direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
        origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS default_modules (
        id INTEGER PRIMARY KEY, layout_wall_id INTEGER NOT NULL REFERENCES layout_walls(id) ON DELETE CASCADE,
        module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_cm INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS configurations (
        id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, node_id INTEGER NOT NULL REFERENCES nodes(id),
        user_id INTEGER NULL REFERENCES users(id),
        customer_name TEXT NOT NULL DEFAULT '', customer_email TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','accepted','cancelled')),
        total INTEGER NOT NULL DEFAULT 0, tax_rate REAL NOT NULL DEFAULT 21, notes TEXT NOT NULL DEFAULT '',
        consent_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, accepted_at TEXT NULL);
    CREATE INDEX IF NOT EXISTS configurations_user ON configurations (user_id, created_at);
    CREATE TABLE IF NOT EXISTS config_walls (
        id INTEGER PRIMARY KEY, configuration_id INTEGER NOT NULL REFERENCES configurations(id) ON DELETE CASCADE,
        name TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
        origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS config_items (
        id INTEGER PRIMARY KEY, config_wall_id INTEGER NOT NULL REFERENCES config_walls(id) ON DELETE CASCADE,
        module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_cm INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0,
        tier_from_cm INTEGER NULL, tier_to_cm INTEGER NULL, price INTEGER NULL);
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS mcp_tokens (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT UNIQUE NOT NULL, prefix TEXT NOT NULL DEFAULT '',
        scopes TEXT NOT NULL, user_id INTEGER NULL REFERENCES users(id),
        created_at TEXT NOT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL);
    CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS throttle (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL);
    SQL);
    q("INSERT OR REPLACE INTO meta (key, value) VALUES ('schema_version', ?)", [(string) UC_SCHEMA]);
}

function ensure_schema(): void
{
    if ((int) val("SELECT value FROM meta WHERE key = 'schema_version'") < UC_SCHEMA) migrate();
}

// ---------------------------------------------------------------- ajustes

function settings_defaults(): array
{
    return [
        'empresa' => 'Mi empresa', 'logo' => '', 'iva' => '21',
        'permitir_registro' => '0', 'permitir_invitado' => '0',
        'mail_transport' => 'mail',  // mail | smtp | log (log = data/mail.log, para pruebas)
        'mail_from' => '', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => '',
        'bionicadri_url' => '', 'bionicadri_client_id' => '', 'url_publica' => '',
    ];
}

function setting(string $key): string
{
    static $cache = null;
    if ($key === '__reset') { $cache = null; return ''; }
    $cache ??= array_column(rows('SELECT key, value FROM settings'), 'value', 'key');
    return (string) ($cache[$key] ?? settings_defaults()[$key] ?? '');
}

function settings_save(array $values): void
{
    foreach ($values as $k => $v) q('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$k, (string) $v]);
    setting('__reset');
}

function setting_bool(string $key): bool
{
    return setting($key) === '1';
}

/** IVA en porcentaje (solo se MUESTRA; los precios no lo llevan). */
function tax_rate(): float
{
    $v = (float) str_replace(',', '.', setting('iva'));
    return $v >= 0 && $v <= 100 ? $v : 21.0;
}

// ---------------------------------------------------------------- utilidades

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function json_enc(mixed $v, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
    return json_encode($v, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
}

function json_dec(?string $s, mixed $default = []): mixed
{
    if ($s === null || $s === '') return $default;
    $v = json_decode($s, true);
    return $v ?? $default;
}

function slugify(string $s): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
    ];
    $s = strtr(mb_strtolower(trim($s)), $map);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Céntimos → «1.234,50 €». */
function money(?int $cents): string
{
    if ($cents === null) return '—';
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

/** Céntimos → euros con 2 decimales (número para JSON/MCP). */
function eur(?int $cents): ?float
{
    return $cents === null ? null : round($cents / 100, 2);
}

/** «135», «135,5», «135.50» → céntimos. Lanza UcError si no es un importe. */
function parse_money(mixed $v, string $field = 'precio'): int
{
    $s = trim(str_replace([' ', '€'], '', (string) $v));
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
    else $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) throw new UcError(t('{campo}: «{v}» no es un importe válido (ej. 135 o 135,50)', ['campo' => $field, 'v' => (string) $v]));
    return (int) round(((float) $s) * 100);
}

/** Entero positivo de una entrada (cm, ids). Lanza UcError con el valor recibido. */
function parse_int(mixed $v, string $field, int $min = 1, int $max = PHP_INT_MAX): int
{
    if (is_int($v)) $n = $v;
    elseif (is_float($v) && floor($v) === $v) $n = (int) $v;
    elseif (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)) $n = (int) trim($v);
    else throw new UcError(t('{campo} debe ser un número entero; recibido: «{v}»', ['campo' => $field, 'v' => is_scalar($v) ? (string) $v : gettype($v)]));
    if ($n < $min || $n > $max) throw new UcError(t('{campo} fuera de rango: {v} (entre {min} y {max})', ['campo' => $field, 'v' => $n, 'min' => $min, 'max' => $max === PHP_INT_MAX ? '∞' : $max]));
    return $n;
}

function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_enc($data, true);
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function base_url(): string
{
    $host = preg_replace('/[^a-zA-Z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (is_https() ? 'https' : 'http') . '://' . $host;
}

/**
 * Dirección pública de la aplicación para enlaces que salen fuera (correos, vuelta de OAuth, MCP). Se guarda al
 * instalar y se cambia en Ajustes; nunca se toma de la cabecera Host si está guardada (un Host falso no cuela un enlace).
 */
function public_url(): string
{
    $u = rtrim(setting('url_publica'), '/');
    return $u !== '' ? $u : base_url();
}

function request_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $v = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? null;
    if ($v === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $hv) if (strcasecmp($k, $name) === 0) return $hv;
    }
    return $v;
}

/** Limita intentos. Devuelve false si se supera $max en $window segundos. */
function throttle(string $key, int $max, int $window): bool
{
    $t = time();
    $r = row('SELECT hits, reset_at FROM throttle WHERE key = ?', [$key]);
    if (!$r || $r['reset_at'] < $t) {
        q('INSERT OR REPLACE INTO throttle (key, hits, reset_at) VALUES (?, 1, ?)', [$key, $t + $window]);
        return true;
    }
    if ($r['hits'] >= $max) return false;
    q('UPDATE throttle SET hits = hits + 1 WHERE key = ?', [$key]);
    return true;
}

function throttle_clear(string $key): void
{
    q('DELETE FROM throttle WHERE key = ?', [$key]);
}

// ---------------------------------------------------------------- sesión, CSRF, flash

function session_start_safe(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') { $_SESSION ??= []; return; } // pruebas: sesión en memoria
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(is_https() ? '__Host-uc_session' : 'uc_session');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Formularios: campo _csrf. API: cabecera X-CSRF-Token. */
function csrf_valid(?string $given): bool
{
    return !empty($_SESSION['csrf']) && is_string($given) && hash_equals($_SESSION['csrf'], $given);
}

function csrf_check(): void
{
    if (!csrf_valid((string) ($_POST['_csrf'] ?? ''))) {
        throw new UcError(t('Sesión caducada o formulario repetido. Vuelve atrás y recarga la página.'), [], 419);
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- auditoría

/** Quién está actuando: una persona (sesión), un token MCP, un invitado o el sistema. */
final class Actor
{
    public static string $name = 'sistema';

    public static function set(string $name): void
    {
        self::$name = $name;
    }
}

function audit(string $action, string $detail = ''): void
{
    insert('audit_log', ['at' => now(), 'actor' => Actor::$name, 'action' => $action, 'detail' => mb_substr($detail, 0, 2000)]);
}
