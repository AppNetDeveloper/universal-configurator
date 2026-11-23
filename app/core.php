<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Núcleo: configuración, base de datos, utilidades, sesión, CSRF, ajustes y auditoría.
 * Sin dependencias externas: PHP 8.2+ con pdo_sqlite y mbstring.
 */

const UC_VERSION = '0.3.1';
const UC_SCHEMA = 7;
const UC_MAX_BODY = 256 * 1024; // cuerpo máximo de una petición a /api y /mcp
const PAGE_MAX = 100000;        // página más alta de cualquier listado (más allá, lista vacía; nunca un OFFSET desbordado)
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

/** Cierra la conexión (la siguiente llamada a db() abre otra): hace falta tras sustituir el archivo de la base. */
function db_close(): void
{
    $GLOBALS['__uc_pdo'] = null;
    setting('__reset');
}

function db(): PDO
{
    $pdo = $GLOBALS['__uc_pdo'] ?? null;
    if ($pdo === null) {
        $pdo = $GLOBALS['__uc_pdo'] = new PDO('sqlite:' . db_path(), null, null, [
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

/**
 * Ejecuta $fn dentro de una transacción de ESCRITURA (BEGIN IMMEDIATE: toma el cerrojo de escritura al empezar, así
 * dos peticiones a la vez no leen el mismo estado y luego chocan al escribir). Anidable: si ya hay una abierta, solo
 * la usa. Si la base está ocupada más allá de busy_timeout, responde 503 «inténtalo de nuevo» en vez de un 500.
 */
function tx(callable $fn): mixed
{
    static $depth = 0;
    if ($depth > 0) return $fn();
    $pdo = db();
    try {
        $pdo->exec('BEGIN IMMEDIATE');
    } catch (PDOException $ex) {
        if (db_busy($ex)) throw new UcError(t('La base de datos está ocupada en este momento: inténtalo de nuevo en unos segundos.'), [], 503);
        throw $ex;
    }
    $depth++;
    try {
        $r = $fn();
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
        if ($ex instanceof PDOException && db_busy($ex)) throw new UcError(t('La base de datos está ocupada en este momento: inténtalo de nuevo en unos segundos.'), [], 503);
        throw $ex;
    } finally {
        $depth--;
    }
}

function db_busy(PDOException $ex): bool
{
    return str_contains($ex->getMessage(), 'database is locked') || str_contains($ex->getMessage(), 'database is busy') || ($ex->errorInfo[1] ?? 0) === 5;
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
        external_id TEXT NULL, created_at TEXT NOT NULL, last_login_at TEXT NULL,
        session_version INTEGER NOT NULL DEFAULT 0, pending_verification INTEGER NOT NULL DEFAULT 0);
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
        family TEXT NOT NULL DEFAULT '', depth_mm INTEGER NOT NULL DEFAULT 560, active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0, width_mm INTEGER NOT NULL DEFAULT 600, height_mm INTEGER NOT NULL DEFAULT 720,
        min_width_mm INTEGER NULL, max_width_mm INTEGER NULL, board_mm REAL NOT NULL DEFAULT 19, back_mm REAL NOT NULL DEFAULT 8);
    CREATE TABLE IF NOT EXISTS price_tiers (
        id INTEGER PRIMARY KEY, module_type_id INTEGER NOT NULL REFERENCES module_types(id) ON DELETE CASCADE,
        node_id INTEGER NULL REFERENCES nodes(id) ON DELETE CASCADE,
        from_mm INTEGER NOT NULL, to_mm INTEGER NOT NULL, price INTEGER NOT NULL CHECK (price >= 0),
        CHECK (from_mm <= to_mm), CHECK (from_mm > 0));
    CREATE TABLE IF NOT EXISTS layout_walls (
        id INTEGER PRIMARY KEY, node_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE, name TEXT NOT NULL,
        direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
        origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS default_modules (
        id INTEGER PRIMARY KEY, layout_wall_id INTEGER NOT NULL REFERENCES layout_walls(id) ON DELETE CASCADE,
        module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_mm INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS configurations (
        id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, node_id INTEGER NOT NULL REFERENCES nodes(id),
        user_id INTEGER NULL REFERENCES users(id),
        customer_name TEXT NOT NULL DEFAULT '', customer_email TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','requested','accepted','cancelled')),
        total INTEGER NOT NULL DEFAULT 0, tax_rate REAL NOT NULL DEFAULT 21, notes TEXT NOT NULL DEFAULT '',
        consent_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, accepted_at TEXT NULL,
        requested_at TEXT NULL, work_status TEXT NULL CHECK (work_status IN ('nuevo','en_curso','hecho')),
        work_note TEXT NOT NULL DEFAULT '', work_by TEXT NOT NULL DEFAULT '', work_at TEXT NULL);
    CREATE INDEX IF NOT EXISTS configurations_user ON configurations (user_id, created_at);
    CREATE TABLE IF NOT EXISTS config_walls (
        id INTEGER PRIMARY KEY, configuration_id INTEGER NOT NULL REFERENCES configurations(id) ON DELETE CASCADE,
        name TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
        origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS config_items (
        id INTEGER PRIMARY KEY, config_wall_id INTEGER NOT NULL REFERENCES config_walls(id) ON DELETE CASCADE,
        module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_mm INTEGER NOT NULL, height_mm INTEGER NULL, depth_mm INTEGER NULL,
        sort INTEGER NOT NULL DEFAULT 0, tier_from_mm INTEGER NULL, tier_to_mm INTEGER NULL, price INTEGER NULL);
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS mcp_tokens (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT UNIQUE NOT NULL, prefix TEXT NOT NULL DEFAULT '',
        scopes TEXT NOT NULL, user_id INTEGER NULL REFERENCES users(id),
        created_at TEXT NOT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL);
    CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS throttle (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS login_backoff (key TEXT PRIMARY KEY, fails INTEGER NOT NULL, next_at REAL NOT NULL, last_fail_at REAL NOT NULL);
    SQL);
    // v3: medidas en mm (un decimal en cm = mm enteros), medidas del tipo y despiece.
    migrate_v3();
    // v7: estado «requested» (presupuesto pedido por el cliente) y estado de trabajo; tabla de leads.
    migrate_v7();
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY, configuration_id INTEGER NULL REFERENCES configurations(id) ON DELETE SET NULL,
        user_id INTEGER NULL REFERENCES users(id), name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
        message TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT 'configurador', consent_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'nuevo' CHECK (status IN ('nuevo','contactado','cerrado')),
        work_status TEXT NOT NULL DEFAULT 'nuevo' CHECK (work_status IN ('nuevo','en_curso','hecho')),
        work_note TEXT NOT NULL DEFAULT '', work_by TEXT NOT NULL DEFAULT '', work_at TEXT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS leads_created ON leads (created_at);
    CREATE INDEX IF NOT EXISTS leads_config ON leads (configuration_id);
    CREATE INDEX IF NOT EXISTS configurations_work ON configurations (status, work_status);
    SQL);
    db()->exec(<<<SQL
    CREATE INDEX IF NOT EXISTS price_tiers_type ON price_tiers (module_type_id, node_id, from_mm);
    CREATE TABLE IF NOT EXISTS module_parts (
        id INTEGER PRIMARY KEY, module_type_id INTEGER NOT NULL REFERENCES module_types(id) ON DELETE CASCADE,
        name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 1 CHECK (qty > 0), thickness_expr TEXT NOT NULL DEFAULT 'T',
        length_expr TEXT NOT NULL, width_expr TEXT NOT NULL, grain TEXT NOT NULL DEFAULT '', edge_banding TEXT NOT NULL DEFAULT '',
        sort INTEGER NOT NULL DEFAULT 0);
    CREATE INDEX IF NOT EXISTS module_parts_type ON module_parts (module_type_id, sort);
    CREATE TABLE IF NOT EXISTS config_item_parts (
        id INTEGER PRIMARY KEY, config_item_id INTEGER NOT NULL REFERENCES config_items(id) ON DELETE CASCADE,
        name TEXT NOT NULL, qty INTEGER NOT NULL, thickness_mm REAL NOT NULL, length_mm REAL NOT NULL, width_mm REAL NOT NULL,
        grain TEXT NOT NULL DEFAULT '', edge_banding TEXT NOT NULL DEFAULT '', sort INTEGER NOT NULL DEFAULT 0);
    CREATE INDEX IF NOT EXISTS config_item_parts_item ON config_item_parts (config_item_id, sort);
    CREATE INDEX IF NOT EXISTS config_items_wall ON config_items (config_wall_id, sort);
    SQL);
    // v4: versión de sesión (cambiar la contraseña o desactivar cierra las demás sesiones) y registro pendiente de verificar.
    $uc = array_column(rows('PRAGMA table_info(users)'), 'name');
    if (!in_array('session_version', $uc, true)) db()->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0');
    if (!in_array('pending_verification', $uc, true)) db()->exec('ALTER TABLE users ADD COLUMN pending_verification INTEGER NOT NULL DEFAULT 0');
    // v2: url_publica obligatoria. Una instalación anterior sin ella no puede deducirla con seguridad (la cabecera Host
    // no es de fiar): queda el aviso en el registro y en el panel, y no se envían enlaces hasta que el admin la ponga.
    if ((int) val("SELECT value FROM meta WHERE key = 'schema_version'") < 2 && trim((string) val("SELECT value FROM settings WHERE key = 'url_publica'")) === ''
        && val('SELECT 1 FROM users LIMIT 1')) {
        insert('audit_log', ['at' => now(), 'actor' => 'sistema', 'action' => 'settings.no_public_url', 'detail' => 'Falta la URL pública: configúrala en Ajustes; mientras, no se envían enlaces por correo.']);
    }
    q("INSERT OR REPLACE INTO meta (key, value) VALUES ('schema_version', ?)", [(string) UC_SCHEMA]);
}

/**
 * Paso de v2 a v3 en una base existente (anchos en cm enteros): renombra las columnas *_cm a *_mm multiplicando por
 * 10, añade alto y fondo a cada línea (los de su tipo) y las medidas del tipo (ancho, alto, mínimo y máximo desde sus
 * tramos, tablero 19 y trasera 8). Todo o nada. En una base nueva no hace nada.
 */
function migrate_v3(): void
{
    $cols = fn(string $t) => array_column(rows("PRAGMA table_info($t)"), 'name');
    if (!in_array('from_cm', $cols('price_tiers'), true)) return;
    tx(function () use ($cols) {
        $pdo = db();
        $pdo->exec('DROP INDEX IF EXISTS price_tiers_type');
        foreach (['price_tiers' => ['from_cm' => 'from_mm', 'to_cm' => 'to_mm'], 'default_modules' => ['width_cm' => 'width_mm'],
                  'config_items' => ['width_cm' => 'width_mm', 'tier_from_cm' => 'tier_from_mm', 'tier_to_cm' => 'tier_to_mm']] as $table => $map) {
            // Primero se renombran todas (el CHECK desde ≤ hasta se renombra con ellas) y luego se multiplican a la vez.
            foreach ($map as $from => $to) $pdo->exec("ALTER TABLE $table RENAME COLUMN $from TO $to");
            $pdo->exec("UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = $c * 10", $map)));
        }
        $mt = $cols('module_types');
        foreach (['width_mm' => 'INTEGER NOT NULL DEFAULT 600', 'height_mm' => 'INTEGER NOT NULL DEFAULT 720', 'min_width_mm' => 'INTEGER NULL',
                  'max_width_mm' => 'INTEGER NULL', 'board_mm' => 'REAL NOT NULL DEFAULT 19', 'back_mm' => 'REAL NOT NULL DEFAULT 8'] as $c => $def) {
            if (!in_array($c, $mt, true)) $pdo->exec("ALTER TABLE module_types ADD COLUMN $c $def");
        }
        $pdo->exec('UPDATE module_types SET min_width_mm = (SELECT MIN(from_mm) FROM price_tiers p WHERE p.module_type_id = module_types.id),
            max_width_mm = (SELECT MAX(to_mm) FROM price_tiers p WHERE p.module_type_id = module_types.id)');
        $pdo->exec('UPDATE module_types SET width_mm = COALESCE((SELECT to_mm FROM price_tiers p WHERE p.module_type_id = module_types.id AND p.node_id IS NULL
            AND p.from_mm <= 600 AND p.to_mm >= 600 LIMIT 1), max_width_mm, 600)');
        $ci = $cols('config_items');
        if (!in_array('height_mm', $ci, true)) $pdo->exec('ALTER TABLE config_items ADD COLUMN height_mm INTEGER NULL');
        if (!in_array('depth_mm', $ci, true)) $pdo->exec('ALTER TABLE config_items ADD COLUMN depth_mm INTEGER NULL');
        $pdo->exec('UPDATE config_items SET height_mm = (SELECT height_mm FROM module_types t WHERE t.id = config_items.module_type_id),
            depth_mm = (SELECT depth_mm FROM module_types t WHERE t.id = config_items.module_type_id) WHERE height_mm IS NULL');
        insert('audit_log', ['at' => now(), 'actor' => 'sistema', 'action' => 'schema.v3', 'detail' => 'Medidas pasadas a mm; medidas de tipo y despiece añadidos']);
    });
}

/**
 * Paso a v7 en una base existente: la columna status de configurations solo admitía draft, accepted y cancelled (un
 * CHECK, que SQLite no deja cambiar). Se reconstruye la tabla con el estado nuevo «requested» y las columnas del estado
 * de trabajo, conservando los id (paredes, líneas y piezas siguen apuntando a la misma fila). Con las claves ajenas
 * apagadas mientras dura: si no, quitar la tabla vieja arrastraría en cascada sus paredes. Todo o nada; al final se
 * comprueban las claves ajenas. Las aceptadas quedan con el estado de trabajo «nuevo». En una base nueva no hace nada.
 */
function migrate_v7(): void
{
    $sql = (string) val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'configurations'");
    if ($sql === '' || str_contains($sql, "'requested'")) return;
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        tx(function () use ($pdo) {
            $old = array_column(rows('PRAGMA table_info(configurations)'), 'name');
            $pdo->exec(<<<SQL
            CREATE TABLE configurations_v7 (
                id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, node_id INTEGER NOT NULL REFERENCES nodes(id),
                user_id INTEGER NULL REFERENCES users(id),
                customer_name TEXT NOT NULL DEFAULT '', customer_email TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','requested','accepted','cancelled')),
                total INTEGER NOT NULL DEFAULT 0, tax_rate REAL NOT NULL DEFAULT 21, notes TEXT NOT NULL DEFAULT '',
                consent_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, accepted_at TEXT NULL,
                requested_at TEXT NULL, work_status TEXT NULL CHECK (work_status IN ('nuevo','en_curso','hecho')),
                work_note TEXT NOT NULL DEFAULT '', work_by TEXT NOT NULL DEFAULT '', work_at TEXT NULL);
            SQL);
            $cols = implode(', ', $old);
            $pdo->exec("INSERT INTO configurations_v7 ($cols) SELECT $cols FROM configurations");
            $pdo->exec("UPDATE configurations_v7 SET work_status = 'nuevo' WHERE status = 'accepted'");
            $pdo->exec('DROP TABLE configurations');
            $pdo->exec('ALTER TABLE configurations_v7 RENAME TO configurations');
            $pdo->exec('CREATE INDEX IF NOT EXISTS configurations_user ON configurations (user_id, created_at)');
            if (rows('PRAGMA foreign_key_check')) throw new UcError('Migración v7: quedarían claves ajenas rotas; no se cambia nada.');
            insert('audit_log', ['at' => now(), 'actor' => 'sistema', 'action' => 'schema.v7', 'detail' => 'Estado «presupuesto pedido», estado de trabajo y leads']);
        });
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
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
        'backup_auto' => 'off', 'backup_keep' => '7',   // copias automáticas: off | daily | weekly, y cuántas se guardan
        'update_check_daily' => '0',                    // buscar versión nueva sola una vez al día (la única llamada de salida)
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

/**
 * Ancho que escribe la persona en cm, con un decimal como mucho («58,5», «58.5», 58.5, 60) → mm enteros (585).
 * Así se guardan todas las medidas: en mm enteros. Lanza UcError con el valor recibido.
 */
function parse_width_mm(mixed $v, string $field = 'width_cm', int $minCm = 1, int $maxCm = 10000): int
{
    if (is_int($v)) $s = (string) $v;
    elseif (is_float($v) && is_finite($v)) $s = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    elseif (is_string($v)) $s = str_replace(',', '.', trim($v));
    else throw new UcError(t('{campo} debe ser una medida en cm (p. ej. 60 o 58,5); recibido: {v}', ['campo' => $field, 'v' => gettype($v)]));
    if (preg_match('/^\d+\.\d{2,}$/', $s)) throw new UcError(t('{campo}: «{v}» tiene más de un decimal; las medidas van en cm con un decimal como mucho (58,5).', ['campo' => $field, 'v' => (string) $v]));
    if (!preg_match('/^\d{1,6}(\.\d)?$/', $s)) throw new UcError(t('{campo}: «{v}» no es una medida en cm (p. ej. 60 o 58,5).', ['campo' => $field, 'v' => is_scalar($v) ? mb_substr((string) $v, 0, 20) : '']));
    $mm = (int) round(((float) $s) * 10);
    if ($mm < $minCm * 10 || $mm > $maxCm * 10) throw new UcError(t('{campo} fuera de rango: {v} cm (entre {min} y {max}).', ['campo' => $field, 'v' => $s, 'min' => $minCm, 'max' => $maxCm]));
    return $mm;
}

/** mm → cm como número (60 o 58.5): entero si no tiene decimal. */
function mm_to_cm(?int $mm): int|float|null
{
    if ($mm === null) return null;
    return $mm % 10 === 0 ? intdiv($mm, 10) : $mm / 10;
}

/** mm → «58,5» o «60» (cm, para pantallas). */
function cm_text(?int $mm): string
{
    if ($mm === null) return '—';
    return $mm % 10 === 0 ? (string) intdiv($mm, 10) : str_replace('.', ',', (string) ($mm / 10));
}

/** Medida en mm de una pieza → «581» o «481,5». */
function mm_text(?float $mm): string
{
    if ($mm === null) return '—';
    return floor($mm) == $mm ? (string) (int) $mm : str_replace('.', ',', (string) $mm);
}

/** Redondeo de las piezas a 0,5 mm. */
function round_half(float $mm): float
{
    return round($mm * 2) / 2;
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

/**
 * IP para los límites de intentos: la IPv4 tal cual; una IPv6 agrupada por su /64 (quien tiene una /64 tiene
 * millones de direcciones y, si no, saltaría los límites cambiando de dirección).
 */
function client_ip_key(): string
{
    $ip = client_ip();
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) return $ip;
    return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** Reloj en segundos con decimales (las pruebas lo pueden adelantar). */
function clock(): float
{
    return microtime(true) + (defined('UC_TESTING') ? (float) ($GLOBALS['uc_clock_skew'] ?? 0) : 0.0);
}

function base_url(): string
{
    $host = preg_replace('/[^a-zA-Z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (is_https() ? 'https' : 'http') . '://' . $host;
}

/**
 * Dirección pública de la aplicación: la ÚNICA base de los enlaces que salen fuera (correos de restablecer y de alta,
 * vuelta de OAuth, dirección del MCP). Se guarda al instalar (obligatoria) y se cambia en Ajustes. Nunca se toma de la
 * cabecera Host, que la controla quien hace la petición (envenenamiento del enlace de restablecer). Vacía = null.
 */
function public_url(): ?string
{
    $u = rtrim(setting('url_publica'), '/');
    return $u !== '' ? $u : null;
}

/** public_url() o error claro: sin ella no se construye ningún enlace externo. */
function require_public_url(): string
{
    return public_url() ?? throw new UcError(t('Falta la URL pública de la aplicación: configúrala en Panel → Ajustes. Sin ella no se envían enlaces por correo.'), [], 409);
}

function valid_public_url(string $u): bool
{
    return (bool) preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#', $u);
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

/** Página de un listado entre 1 y PAGE_MAX (venga lo que venga: negativo, enorme o «abc» convertido a 0). */
function page_clamp(int $page): int
{
    return max(1, min(PAGE_MAX, $page));
}

/**
 * Limita intentos. Devuelve false si se supera $max en $window segundos. Contar y comprobar van juntos (también cuando
 * la ventana caducó): con muchas peticiones simultáneas entran exactamente $max, ni una más.
 */
function throttle(string $key, int $max, int $window): bool
{
    $t = time();
    if (random_int(1, 100) === 1) throttle_purge(); // la tabla no crece sin límite
    // Sumar y leer dentro de una transacción de escritura (BEGIN IMMEDIATE): nadie se cuela entre medias.
    // (Sin RETURNING, que pide SQLite 3.35: una instalación que actualiza con un SQLite más viejo sigue funcionando.)
    $hits = (int) tx(function () use ($key, $max, $window, $t) {
        q('INSERT INTO throttle (key, hits, reset_at) VALUES (:k, 1, :r)
            ON CONFLICT(key) DO UPDATE SET
                hits = CASE WHEN throttle.reset_at < :t THEN 1 ELSE min(throttle.hits, :max) + 1 END,
                reset_at = CASE WHEN throttle.reset_at < :t THEN :r ELSE throttle.reset_at END', ['k' => $key, 'r' => $t + $window, 't' => $t, 'max' => $max]);
        return val('SELECT hits FROM throttle WHERE key = ?', [$key]);
    });
    return $hits <= $max;
}

/** Borra los contadores caducados. */
function throttle_purge(): void
{
    q('DELETE FROM throttle WHERE reset_at < ?', [time()]);
    q('DELETE FROM login_backoff WHERE last_fail_at < ?', [clock() - 3600]);
}

/** Cuerpo de la petición con tope de tamaño; null si lo pasa (el que llama responde 413 sin hacer nada). */
function request_body_limited(int $max = UC_MAX_BODY): ?string
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) return null;
    $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
    return strlen($raw) > $max ? null : $raw;
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
