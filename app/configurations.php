<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Configuraciones: todo el dominio vive aquí; la API (tablet), el panel, las descargas y el MCP solo llaman.
 *
 * Quién mira ($viewer): ['kind' => 'user', 'user' => fila de users] (sesión o dueño de un token MCP) ·
 * ['kind' => 'guest', 'codes' => [...]] (invitado: solo lo creado en su sesión) · null (nadie).
 * Visibilidad (config_visible, UNA función): admin todo; comercial y cliente solo las suyas (user_id = su id);
 * invitado solo las de su sesión y sin dueño. Lo que no se puede ver responde igual que lo que no existe.
 *
 * Borrador: precio en vivo con precio_configuracion(). Aceptada: foto congelada (tramo y precio por línea,
 * total e IVA) que leen todos (API, panel, JSON, CSV, MCP); ya no se edita: se duplica.
 */

const CONFIG_STATUSES = ['draft' => 'Borrador', 'accepted' => 'Aceptada', 'cancelled' => 'Anulada'];
const CONFIG_OPS = ['set_width', 'remove', 'add', 'replace', 'customer'];

function config_code_new(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = 'UC-';
        for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (val('SELECT 1 FROM configurations WHERE code = ?', [$code]));
    return $code;
}

function config_visible(array $cfg, ?array $viewer): bool
{
    if ($viewer === null) return false;
    if ($viewer['kind'] === 'user') {
        $u = $viewer['user'];
        if (!$u || !$u['active']) return false;
        if ($u['role'] === 'admin') return true;
        return $cfg['user_id'] !== null && (int) $cfg['user_id'] === (int) $u['id'];
    }
    if ($viewer['kind'] === 'guest') {
        return $cfg['user_id'] === null && in_array($cfg['code'], (array) ($viewer['codes'] ?? []), true);
    }
    return false;
}

/** Fila de la configuración si existe y este $viewer la puede ver; si no, 404 (mismo mensaje en los dos casos). */
function config_find(string $code, ?array $viewer): array
{
    $code = strtoupper(trim($code));
    $cfg = $code !== '' ? row('SELECT * FROM configurations WHERE code = ?', [$code]) : null;
    if (!$cfg || !config_visible($cfg, $viewer)) {
        throw new UcError(t('No existe la configuración «{c}» o no tienes acceso a ella.', ['c' => $code]), [], 404);
    }
    return $cfg;
}

function viewer_label(?array $viewer): string
{
    if (!$viewer) return 'nadie';
    return $viewer['kind'] === 'user' ? $viewer['user']['email'] : 'invitado';
}

// ---------------------------------------------------------------- crear y duplicar

function config_create(int $nodeId, ?array $viewer): array
{
    if ($viewer === null) throw new UcError(t('Entra con tu cuenta (o como invitado, si se permite) para configurar.'), [], 401);
    $node = node_get($nodeId);
    if (!$node || !node_branch_active($nodeId)) throw new UcError(t('El nodo {id} no existe o no está disponible.', ['id' => $nodeId]), [], 404);
    if (!node_is_final($nodeId)) {
        $kids = implode(', ', array_map(fn($k) => "{$k['id']} ({$k['name']})", node_children($nodeId)));
        throw new UcError(t('«{n}» no es un nodo final: elige uno de sus hijos: {hijos}.', ['n' => $node['name'], 'hijos' => $kids]));
    }
    $code = tx(function () use ($nodeId, $viewer) {
        $code = config_code_new();
        $cid = insert('configurations', ['code' => $code, 'node_id' => $nodeId, 'user_id' => $viewer['kind'] === 'user' ? (int) $viewer['user']['id'] : null,
            'status' => 'draft', 'tax_rate' => tax_rate(), 'created_at' => now(), 'updated_at' => now()]);
        $layout = layout_for($nodeId);
        if (!$layout['walls']) $layout['walls'] = [['name' => t('Pared 1'), 'direction' => 'x', 'origin_x_mm' => 0, 'origin_y_mm' => 0, 'sort' => 10, 'modules' => []]];
        foreach ($layout['walls'] as $w) {
            $wid = insert('config_walls', ['configuration_id' => $cid, 'name' => $w['name'], 'direction' => $w['direction'],
                'origin_x_mm' => (int) $w['origin_x_mm'], 'origin_y_mm' => (int) $w['origin_y_mm'], 'sort' => (int) $w['sort']]);
            foreach ($w['modules'] as $m) insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => (int) $m['module_type_id'], 'width_cm' => (int) $m['width_cm'], 'sort' => (int) $m['sort']]);
        }
        config_touch($cid);
        return $code;
    });
    if ($viewer['kind'] === 'guest') {
        $_SESSION['guest_codes'][] = $code;
        $viewer['codes'][] = $code;
    }
    audit('config.create', "$code · " . node_path_text($nodeId) . ' · ' . viewer_label($viewer));
    return config_view(config_find($code, $viewer));
}

/** Duplica (cualquier estado) en un borrador nuevo de quien lo pide, con precios de hoy. */
function config_copy(string $code, ?array $viewer): array
{
    $src = config_find($code, $viewer);
    if (!node_branch_active((int) $src['node_id'])) throw new UcError(t('El modelo de esta configuración ya no está disponible: no se puede duplicar.'));
    $new = tx(function () use ($src, $viewer) {
        $newCode = config_code_new();
        $cid = insert('configurations', ['code' => $newCode, 'node_id' => $src['node_id'], 'user_id' => $viewer['kind'] === 'user' ? (int) $viewer['user']['id'] : null,
            'customer_name' => $src['customer_name'], 'customer_email' => $src['customer_email'], 'customer_phone' => $src['customer_phone'],
            'notes' => $src['notes'], 'status' => 'draft', 'tax_rate' => tax_rate(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (rows('SELECT * FROM config_walls WHERE configuration_id = ? ORDER BY sort, id', [$src['id']]) as $w) {
            $wid = insert('config_walls', ['configuration_id' => $cid, 'name' => $w['name'], 'direction' => $w['direction'],
                'origin_x_mm' => $w['origin_x_mm'], 'origin_y_mm' => $w['origin_y_mm'], 'sort' => $w['sort']]);
            foreach (rows('SELECT * FROM config_items WHERE config_wall_id = ? ORDER BY sort, id', [$w['id']]) as $it) {
                insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => $it['module_type_id'], 'width_cm' => $it['width_cm'], 'sort' => $it['sort']]);
            }
        }
        config_touch($cid);
        return $newCode;
    });
    if ($viewer['kind'] === 'guest') {
        $_SESSION['guest_codes'][] = $new;
        $viewer['codes'][] = $new;
    }
    audit('config.copy', "{$src['code']} → $new · " . viewer_label($viewer));
    return config_view(config_find($new, $viewer));
}

// ---------------------------------------------------------------- leer

/** Paredes con sus líneas, en el formato que entiende precio_configuracion(). */
function config_walls_input(int $configId): array
{
    $walls = rows('SELECT * FROM config_walls WHERE configuration_id = ? ORDER BY sort, id', [$configId]);
    foreach ($walls as &$w) {
        $w['items'] = rows('SELECT * FROM config_items WHERE config_wall_id = ? ORDER BY sort, id', [$w['id']]);
    }
    return $walls;
}

/** Precio de una configuración: en vivo si es borrador; la foto congelada si está aceptada o anulada. */
function config_pricing(array $cfg): array
{
    $walls = config_walls_input((int) $cfg['id']);
    if ($cfg['status'] === 'draft') return precio_configuracion((int) $cfg['node_id'], $walls) + ['frozen' => false];
    // Foto: cada línea con el tramo y precio guardados al aceptar.
    $count = 0;
    foreach ($walls as &$w) {
        foreach ($w['items'] as &$it) {
            $type = module_type_get((int) $it['module_type_id']);
            $count++;
            $it = ['id' => (int) $it['id'], 'module_type_id' => (int) $it['module_type_id'], 'type_name' => $type['name'] ?? '?', 'family' => $type['family'] ?? '',
                'width_cm' => (int) $it['width_cm'], 'valid' => $it['price'] !== null, 'price' => $it['price'] === null ? null : (int) $it['price'],
                'tier_from_cm' => $it['tier_from_cm'] === null ? null : (int) $it['tier_from_cm'], 'tier_to_cm' => $it['tier_to_cm'] === null ? null : (int) $it['tier_to_cm'],
                'tier_node_id' => null, 'tiers' => []];
        }
    }
    $total = (int) $cfg['total'];
    $rate = (float) $cfg['tax_rate'];
    $tax = (int) round($total * $rate / 100);
    return ['node_id' => (int) $cfg['node_id'], 'walls' => $walls, 'count' => $count, 'total' => $total, 'valid' => true, 'invalid_count' => 0,
        'tax_rate' => $rate, 'tax' => $tax, 'total_with_tax' => $total + $tax, 'frozen' => true];
}

/** Representación completa (API, MCP, panel, exportación). Importes en euros (2 decimales) y en céntimos (*_cents). */
function config_view(array $cfg): array
{
    $p = config_pricing($cfg);
    $owner = $cfg['user_id'] !== null ? user_by_id((int) $cfg['user_id']) : null;
    $walls = [];
    foreach ($p['walls'] as $w) {
        $items = [];
        foreach ($w['items'] as $it) {
            $type = module_type_get($it['module_type_id']);
            $items[] = [
                'id' => (int) $it['id'], 'module_type_id' => $it['module_type_id'], 'type_name' => $it['type_name'], 'ref' => $type['ref'] ?? '',
                'family' => $it['family'], 'depth_mm' => isset($type['depth_mm']) ? (int) $type['depth_mm'] : null, 'width_cm' => $it['width_cm'],
                'valid' => $it['valid'], 'tier_from_cm' => $it['tier_from_cm'], 'tier_to_cm' => $it['tier_to_cm'],
                'price' => eur($it['price']), 'price_cents' => $it['price'], 'price_text' => money($it['price']),
                'tiers' => tiers_public($it['tiers']), 'error' => $it['error'] ?? null,
            ];
        }
        $walls[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'direction' => $w['direction'],
            'origin_x_mm' => (int) $w['origin_x_mm'], 'origin_y_mm' => (int) $w['origin_y_mm'], 'items' => $items];
    }
    return [
        'code' => $cfg['code'], 'status' => $cfg['status'], 'status_label' => t(CONFIG_STATUSES[$cfg['status']]), 'frozen' => $p['frozen'],
        'node' => ['id' => (int) $cfg['node_id'], 'path' => node_path((int) $cfg['node_id']), 'path_text' => node_path_text((int) $cfg['node_id'])],
        'user' => $owner ? ['id' => (int) $owner['id'], 'name' => $owner['name'], 'email' => $owner['email'], 'role' => $owner['role']] : null,
        'customer' => ['name' => $cfg['customer_name'], 'email' => $cfg['customer_email'], 'phone' => $cfg['customer_phone']],
        'notes' => $cfg['notes'], 'walls' => $walls, 'count' => $p['count'], 'valid' => $p['valid'], 'invalid_count' => $p['invalid_count'],
        'currency' => 'EUR', 'prices_include_tax' => false,
        'total' => eur($p['total']), 'total_cents' => $p['total'], 'total_text' => money($p['total']), 'tax_rate' => $p['tax_rate'],
        'tax' => eur($p['tax']), 'tax_cents' => $p['tax'], 'total_with_tax' => eur($p['total_with_tax']), 'total_with_tax_cents' => $p['total_with_tax'],
        'created_at' => $cfg['created_at'], 'updated_at' => $cfg['updated_at'], 'accepted_at' => $cfg['accepted_at'],
    ];
}

function config_get(string $code, ?array $viewer): array
{
    return config_view(config_find($code, $viewer));
}

/** Guarda en la fila el total actual de un borrador (para listados y orden). La verdad sigue siendo el cálculo. */
function config_touch(int $configId): void
{
    $cfg = row('SELECT * FROM configurations WHERE id = ?', [$configId]);
    if (!$cfg || $cfg['status'] !== 'draft') return;
    $p = precio_configuracion((int) $cfg['node_id'], config_walls_input($configId));
    update('configurations', ['total' => $p['total'], 'tax_rate' => $p['tax_rate'], 'updated_at' => now()], 'id = ?', [$configId]);
}

/**
 * Lista con filtros (status, from, to = AAAA-MM-DD sobre la fecha de creación, q = código o cliente, user_id solo admin),
 * paginada. Solo lo que $viewer puede ver.
 */
function config_list(?array $viewer, array $f, int $page = 1, int $perPage = 25): array
{
    if ($viewer === null) return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 0];
    $where = [];
    $args = [];
    if ($viewer['kind'] === 'user') {
        if ($viewer['user']['role'] !== 'admin') { $where[] = 'c.user_id = ?'; $args[] = (int) $viewer['user']['id']; }
        elseif (!empty($f['user_id'])) { $where[] = 'c.user_id = ?'; $args[] = (int) $f['user_id']; }
    } else {
        $codes = array_values(array_filter((array) ($viewer['codes'] ?? []), 'is_string'));
        if (!$codes) return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 0];
        $where[] = 'c.user_id IS NULL AND c.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        array_push($args, ...$codes);
    }
    $status = (string) ($f['status'] ?? '');
    if ($status !== '') {
        if (!isset(CONFIG_STATUSES[$status])) throw new UcError(t('Estado «{v}» no válido: draft, accepted o cancelled.', ['v' => $status]));
        $where[] = 'c.status = ?';
        $args[] = $status;
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = trim((string) ($f[$k] ?? ''));
        if ($d === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new UcError(t('Fecha «{v}» no válida: usa AAAA-MM-DD (ej. 2026-11-23).', ['v' => $d]));
        $where[] = "date(c.created_at) $op ?";
        $args[] = $d;
    }
    $qtext = trim((string) ($f['q'] ?? ''));
    if ($qtext !== '') {
        $where[] = '(c.code LIKE ? OR c.customer_name LIKE ? OR c.customer_email LIKE ? OR c.customer_phone LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qtext) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sqlWhere = str_replace('LIKE ?', "LIKE ? ESCAPE '\\'", $sqlWhere);
    $perPage = max(1, min(100, $perPage));
    $total = (int) val("SELECT COUNT(*) FROM configurations c $sqlWhere", $args);
    $pages = (int) ceil($total / $perPage);
    $page = max(1, $page);
    $rs = rows("SELECT c.* FROM configurations c $sqlWhere ORDER BY c.created_at DESC, c.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $args);
    $items = array_map(function ($cfg) {
        $p = config_pricing($cfg);
        $owner = $cfg['user_id'] !== null ? user_by_id((int) $cfg['user_id']) : null;
        return ['code' => $cfg['code'], 'status' => $cfg['status'], 'status_label' => t(CONFIG_STATUSES[$cfg['status']]),
            'node' => node_path_text((int) $cfg['node_id']), 'customer_name' => $cfg['customer_name'], 'customer_email' => $cfg['customer_email'],
            'user' => $owner ? $owner['name'] : null, 'count' => $p['count'], 'valid' => $p['valid'],
            'total' => eur($p['total']), 'total_cents' => $p['total'], 'created_at' => $cfg['created_at'], 'updated_at' => $cfg['updated_at'], 'accepted_at' => $cfg['accepted_at']];
    }, $rs);
    return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages];
}

// ---------------------------------------------------------------- cambiar

function config_require_draft(array $cfg): void
{
    if ($cfg['status'] === 'accepted') throw new UcError(t('La configuración {c} está aceptada: ya no se edita. Duplícala para hacer cambios (botón «Duplicar» o config_create con copy_of).', ['c' => $cfg['code']]), [], 409);
    if ($cfg['status'] !== 'draft') throw new UcError(t('La configuración {c} está anulada: no se edita. Duplícala para hacer cambios.', ['c' => $cfg['code']]), [], 409);
}

/** Línea de ESTA configuración (nunca de otra, aunque se mande su id). */
function config_item(array $cfg, mixed $itemId): array
{
    $id = parse_int($itemId, 'item_id');
    $it = row('SELECT i.*, w.configuration_id FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id WHERE i.id = ?', [$id]);
    if (!$it || (int) $it['configuration_id'] !== (int) $cfg['id']) {
        throw new UcError(t('La línea {id} no es de la configuración {c}. Mira los id de las líneas (items) con config_get.', ['id' => $id, 'c' => $cfg['code']]), [], 404);
    }
    return $it;
}

/** Comprueba que el ancho tenga tramo; si no, UcError con los tramos posibles en data['tiers']. */
function config_check_width(array $cfg, int $typeId, int $width, ?int $itemId = null): void
{
    $type = module_type_get($typeId);
    $hit = tier_lookup($typeId, (int) $cfg['node_id'], $width);
    if (!$hit['tier']) throw new UcError(tiers_error_text($type['name'] ?? (string) $typeId, $width, $hit['tiers']), ['tiers' => tiers_public($hit['tiers']), 'item_id' => $itemId, 'width_cm' => $width]);
}

function config_active_type(mixed $id): array
{
    $tid = parse_int($id, 'module_type_id');
    $t = module_type_get($tid);
    if (!$t || !$t['active']) throw new UcError(t('El tipo de módulo {id} no existe o no está activo. Mira los tipos con module_types.', ['id' => $tid]));
    return $t;
}

/**
 * Aplica operaciones a un borrador, todas o ninguna (transacción).
 *   set_width {item_id, width_cm} · remove {item_id} · add {wall_id, module_type_id, width_cm?} ·
 *   replace {item_id, module_type_id, width_cm?} · customer {name?, email?, phone?, notes?}
 * @return array{config:array, notes:list<string>}
 */
function config_apply_ops(string $code, array $ops, ?array $viewer): array
{
    $cfg = config_find($code, $viewer);
    config_require_draft($cfg);
    if (!$ops || !array_is_list($ops)) throw new UcError(t('ops debe ser una lista de operaciones, p. ej. [{"op": "set_width", "item_id": 12, "width_cm": 60}].'));
    $notes = [];
    tx(function () use ($cfg, $ops, &$notes) {
        foreach ($ops as $n => $op) {
            try {
                if (!is_array($op)) throw new UcError(t('cada operación es un objeto con «op».'));
                $notes = array_merge($notes, config_apply_one($cfg, $op));
            } catch (UcError $ex) {
                $name = is_array($op) ? (string) ($op['op'] ?? '?') : '?';
                throw new UcError((count($ops) > 1 ? "ops[$n] ($name): " : '') . $ex->getMessage(), $ex->data + ['op_index' => $n], $ex->status);
            }
        }
        config_touch((int) $cfg['id']);
    });
    audit('config.update', $cfg['code'] . ' · ' . implode(', ', array_map(fn($o) => is_array($o) ? (string) ($o['op'] ?? '?') : '?', $ops)));
    return ['config' => config_view(config_find($code, $viewer)), 'notes' => $notes];
}

/** @return list<string> notas (p. ej. ancho ajustado) */
function config_apply_one(array $cfg, array $op): array
{
    $kind = (string) ($op['op'] ?? '');
    switch ($kind) {
        case 'set_width':
            $it = config_item($cfg, $op['item_id'] ?? null);
            $w = parse_int($op['width_cm'] ?? null, 'width_cm', 1, 10000);
            config_check_width($cfg, (int) $it['module_type_id'], $w, (int) $it['id']);
            update('config_items', ['width_cm' => $w], 'id = ?', [$it['id']]);
            return [];
        case 'remove':
            $it = config_item($cfg, $op['item_id'] ?? null);
            q('DELETE FROM config_items WHERE id = ?', [$it['id']]);
            return [];
        case 'add':
            $wid = parse_int($op['wall_id'] ?? null, 'wall_id');
            if (!val('SELECT 1 FROM config_walls WHERE id = ? AND configuration_id = ?', [$wid, $cfg['id']])) {
                throw new UcError(t('La pared {id} no es de la configuración {c}. Mira los id de las paredes (walls) con config_get.', ['id' => $wid, 'c' => $cfg['code']]), [], 404);
            }
            $type = config_active_type($op['module_type_id'] ?? null);
            if (isset($op['width_cm']) && $op['width_cm'] !== '' && $op['width_cm'] !== null) {
                $w = parse_int($op['width_cm'], 'width_cm', 1, 10000);
                config_check_width($cfg, (int) $type['id'], $w);
            } else {
                $w = ancho_sugerido((int) $type['id'], (int) $cfg['node_id']) ?? throw new UcError(tiers_error_text($type['name'], 0, []));
            }
            $sort = (int) val('SELECT COALESCE(MAX(sort), 0) + 10 FROM config_items WHERE config_wall_id = ?', [$wid]);
            insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => (int) $type['id'], 'width_cm' => $w, 'sort' => $sort]);
            return [];
        case 'replace':
            $it = config_item($cfg, $op['item_id'] ?? null);
            $type = config_active_type($op['module_type_id'] ?? null);
            $notes = [];
            if (isset($op['width_cm']) && $op['width_cm'] !== '' && $op['width_cm'] !== null) {
                $w = parse_int($op['width_cm'], 'width_cm', 1, 10000);
                config_check_width($cfg, (int) $type['id'], $w, (int) $it['id']);
            } else {
                $w = (int) $it['width_cm'];
                if (!tier_lookup((int) $type['id'], (int) $cfg['node_id'], $w)['tier']) {
                    $new = ancho_sugerido((int) $type['id'], (int) $cfg['node_id'], $w) ?? throw new UcError(tiers_error_text($type['name'], $w, []));
                    $notes[] = t('«{tipo}» no admite {de} cm: la línea {id} pasa a {a} cm (el tramo más cercano).', ['tipo' => $type['name'], 'de' => $w, 'a' => $new, 'id' => $it['id']]);
                    $w = $new;
                }
            }
            update('config_items', ['module_type_id' => (int) $type['id'], 'width_cm' => $w], 'id = ?', [$it['id']]);
            return $notes;
        case 'customer':
            $d = [];
            foreach (['name' => 'customer_name', 'email' => 'customer_email', 'phone' => 'customer_phone', 'notes' => 'notes'] as $in => $col) {
                if (!array_key_exists($in, $op)) continue;
                $v = trim((string) $op[$in]);
                if ($in === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $v]));
                $d[$col] = mb_substr($v, 0, $in === 'notes' ? 4000 : 160);
            }
            if (!$d) throw new UcError(t('customer necesita al menos uno de: name, email, phone, notes.'));
            update('configurations', $d, 'id = ?', [$cfg['id']]);
            return [];
        default:
            throw new UcError(t('Operación «{v}» desconocida. Valen: {ops}.', ['v' => $kind, 'ops' => implode(', ', CONFIG_OPS)]));
    }
}

// ---------------------------------------------------------------- aceptar

/**
 * Acepta un borrador y congela su precio (tramo y precio por línea, total e IVA).
 * Invitado: nombre, correo y teléfono obligatorios y consentimiento de protección de datos.
 */
function config_accept(string $code, ?array $viewer, array $customer = [], bool $consent = false): array
{
    $cfg = config_find($code, $viewer);
    config_require_draft($cfg);
    $guest = $viewer['kind'] === 'guest';
    $cust = ['customer_name' => trim((string) ($customer['name'] ?? $cfg['customer_name'])), 'customer_email' => trim((string) ($customer['email'] ?? $cfg['customer_email'])),
        'customer_phone' => trim((string) ($customer['phone'] ?? $cfg['customer_phone']))];
    if ($cust['customer_email'] !== '' && !filter_var($cust['customer_email'], FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $cust['customer_email']]));
    if ($guest) {
        if ($cust['customer_name'] === '' || $cust['customer_email'] === '' || $cust['customer_phone'] === '') throw new UcError(t('Para aceptar escribe tu nombre, tu correo y tu teléfono.'));
        if (!$consent) throw new UcError(t('Para aceptar marca la casilla de protección de datos.'));
    }
    $p = precio_configuracion((int) $cfg['node_id'], config_walls_input((int) $cfg['id']));
    if ($p['count'] === 0) throw new UcError(t('La configuración no tiene ningún módulo: añade al menos uno antes de aceptar.'));
    if (!$p['valid']) {
        $bad = [];
        foreach ($p['walls'] as $w) foreach ($w['items'] as $it) if (!$it['valid']) $bad[] = t('línea {id}: {e}', ['id' => $it['id'], 'e' => $it['error']]);
        throw new UcError(t('No se puede aceptar: hay medidas sin tramo. {lista}', ['lista' => implode(' · ', $bad)]));
    }
    tx(function () use ($cfg, $p, $cust, $consent) {
        foreach ($p['walls'] as $w) foreach ($w['items'] as $it) {
            update('config_items', ['tier_from_cm' => $it['tier_from_cm'], 'tier_to_cm' => $it['tier_to_cm'], 'price' => $it['price']], 'id = ?', [$it['id']]);
        }
        $n = q("UPDATE configurations SET status = 'accepted', total = ?, tax_rate = ?, accepted_at = ?, updated_at = ?, consent_at = ?,
            customer_name = ?, customer_email = ?, customer_phone = ? WHERE id = ? AND status = 'draft'",
            [$p['total'], $p['tax_rate'], now(), now(), $consent ? now() : null, mb_substr($cust['customer_name'], 0, 160), mb_substr($cust['customer_email'], 0, 160), mb_substr($cust['customer_phone'], 0, 160), $cfg['id']])->rowCount();
        if ($n !== 1) throw new UcError(t('La configuración {c} ya no es un borrador.', ['c' => $cfg['code']]), [], 409);
    });
    audit('config.accept', $cfg['code'] . ' · ' . money($p['total']) . ' · ' . viewer_label($viewer));
    return config_get($code, $viewer);
}

/** Anular (solo admin). */
function config_cancel(string $code, ?array $viewer): array
{
    $cfg = config_find($code, $viewer);
    if (($viewer['user']['role'] ?? '') !== 'admin') throw new UcError(t('Solo un administrador puede anular configuraciones.'), [], 403);
    if ($cfg['status'] === 'cancelled') return config_get($code, $viewer);
    if ($cfg['status'] === 'draft') config_touch((int) $cfg['id']);
    update('configurations', ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [$cfg['id']]);
    audit('config.cancel', $cfg['code']);
    return config_get($code, $viewer);
}

// ---------------------------------------------------------------- exportar

function config_export_json(array $view): string
{
    return json_enc(['generated_at' => date('c'), 'app' => 'Universal Configurator ' . UC_VERSION] + $view, true);
}

/** Celda CSV segura: comillas y fórmulas neutralizadas (= + - @ al principio). */
function csv_cell(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^-?\d+([.,]\d+)?$/', $s)) $s = "'" . $s;
    return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/** CSV (separador «;», decimales con coma, UTF-8 con BOM para Excel): una fila por módulo. */
function config_export_csv(array $views): string
{
    $num = fn(?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    $head = ['codigo', 'estado', 'creada', 'aceptada', 'nodo', 'cliente', 'correo', 'telefono', 'pared', 'direccion', 'origen_x_mm', 'origen_y_mm',
        'linea', 'modulo', 'ref', 'familia', 'fondo_mm', 'ancho_cm', 'tramo_desde_cm', 'tramo_hasta_cm', 'precio_eur', 'total_sin_iva_eur', 'iva_pct', 'iva_eur'];
    $out = [implode(';', $head)];
    foreach ($views as $v) {
        foreach ($v['walls'] as $w) {
            foreach ($w['items'] as $it) {
                $out[] = implode(';', array_map('csv_cell', [$v['code'], $v['status'], $v['created_at'], $v['accepted_at'] ?? '', $v['node']['path_text'],
                    $v['customer']['name'], $v['customer']['email'], $v['customer']['phone'], $w['name'], $w['direction'], $w['origin_x_mm'], $w['origin_y_mm'],
                    $it['id'], $it['type_name'], $it['ref'], $it['family'], $it['depth_mm'], $it['width_cm'], $it['tier_from_cm'], $it['tier_to_cm'],
                    $num($it['price_cents']), $num($v['total_cents']), str_replace('.', ',', (string) $v['tax_rate']), $num($v['tax_cents'])]));
            }
        }
    }
    return "\u{FEFF}" . implode("\r\n", $out) . "\r\n";
}
