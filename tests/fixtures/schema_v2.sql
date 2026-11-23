-- Esquema v2 (0.1.0) tal cual lo creaba migrate(): base para probar la migración a v3.
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE users (
    id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL COLLATE NOCASE, name TEXT NOT NULL,
    password_hash TEXT NULL, role TEXT NOT NULL CHECK (role IN ('admin','comercial','cliente')),
    active INTEGER NOT NULL DEFAULT 1,
    auth_source TEXT NOT NULL DEFAULT 'local' CHECK (auth_source IN ('local','bionicadri')),
    external_id TEXT NULL, created_at TEXT NOT NULL, last_login_at TEXT NULL);
CREATE UNIQUE INDEX users_external ON users (auth_source, external_id) WHERE external_id IS NOT NULL;
CREATE TABLE password_resets (
    token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL);
CREATE TABLE nodes (
    id INTEGER PRIMARY KEY, parent_id INTEGER NULL REFERENCES nodes(id),
    name TEXT NOT NULL, slug TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL DEFAULT '',
    image TEXT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE INDEX nodes_parent ON nodes (parent_id, sort);
CREATE TABLE module_types (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, ref TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
    family TEXT NOT NULL DEFAULT '', depth_mm INTEGER NOT NULL DEFAULT 600, active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE price_tiers (
    id INTEGER PRIMARY KEY, module_type_id INTEGER NOT NULL REFERENCES module_types(id) ON DELETE CASCADE,
    node_id INTEGER NULL REFERENCES nodes(id) ON DELETE CASCADE,
    from_cm INTEGER NOT NULL, to_cm INTEGER NOT NULL, price INTEGER NOT NULL CHECK (price >= 0),
    CHECK (from_cm <= to_cm), CHECK (from_cm > 0));
CREATE INDEX price_tiers_type ON price_tiers (module_type_id, node_id, from_cm);
CREATE TABLE layout_walls (
    id INTEGER PRIMARY KEY, node_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE, name TEXT NOT NULL,
    direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
    origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE default_modules (
    id INTEGER PRIMARY KEY, layout_wall_id INTEGER NOT NULL REFERENCES layout_walls(id) ON DELETE CASCADE,
    module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_cm INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE configurations (
    id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, node_id INTEGER NOT NULL REFERENCES nodes(id),
    user_id INTEGER NULL REFERENCES users(id),
    customer_name TEXT NOT NULL DEFAULT '', customer_email TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','accepted','cancelled')),
    total INTEGER NOT NULL DEFAULT 0, tax_rate REAL NOT NULL DEFAULT 21, notes TEXT NOT NULL DEFAULT '',
    consent_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, accepted_at TEXT NULL);
CREATE INDEX configurations_user ON configurations (user_id, created_at);
CREATE TABLE config_walls (
    id INTEGER PRIMARY KEY, configuration_id INTEGER NOT NULL REFERENCES configurations(id) ON DELETE CASCADE,
    name TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'x' CHECK (direction IN ('x','y')),
    origin_x_mm INTEGER NOT NULL DEFAULT 0, origin_y_mm INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE config_items (
    id INTEGER PRIMARY KEY, config_wall_id INTEGER NOT NULL REFERENCES config_walls(id) ON DELETE CASCADE,
    module_type_id INTEGER NOT NULL REFERENCES module_types(id), width_cm INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0,
    tier_from_cm INTEGER NULL, tier_to_cm INTEGER NULL, price INTEGER NULL);
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
CREATE TABLE mcp_tokens (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT UNIQUE NOT NULL, prefix TEXT NOT NULL DEFAULT '',
    scopes TEXT NOT NULL, user_id INTEGER NULL REFERENCES users(id),
    created_at TEXT NOT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL);
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
CREATE TABLE throttle (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL);
