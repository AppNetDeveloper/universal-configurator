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
const CONFIG_MAX_OPS = 100;      // operaciones por petición
const CONFIG_MAX_LINES = 300;    // líneas (módulos) por configuración

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

/**
 * Borrador nuevo desde un nodo final con su composición por defecto. $customer opcional: name, email, phone
 * (cualquier otra clave es un error: no se ignora nada en silencio).
 */
function config_create(int $nodeId, ?array $viewer, array $customer = []): array
{
    if ($viewer === null) throw new UcError(t('Entra con tu cuenta (o como invitado, si se permite) para configurar.'), [], 401);
    $node = node_get($nodeId);
    if (!$node || !node_branch_active($nodeId)) throw new UcError(t('El nodo {id} no existe o no está disponible.', ['id' => $nodeId]), [], 404);
    $cust = config_customer_input($customer);
    if (!node_is_final($nodeId)) {
        $kids = implode(', ', array_map(fn($k) => "{$k['id']} ({$k['name']})", node_children($nodeId)));
        throw new UcError(t('«{n}» no es un nodo final: elige uno de sus hijos: {hijos}.', ['n' => $node['name'], 'hijos' => $kids]));
    }
    guest_create_limit($viewer);
    $code = tx(function () use ($nodeId, $viewer, $cust) {
        $code = config_code_new();
        $cid = insert('configurations', ['code' => $code, 'node_id' => $nodeId, 'user_id' => $viewer['kind'] === 'user' ? (int) $viewer['user']['id'] : null,
            'status' => 'draft', 'tax_rate' => tax_rate(), 'created_at' => now(), 'updated_at' => now()] + $cust);
        $layout = layout_for($nodeId);
        if (!$layout['walls']) $layout['walls'] = [['name' => t('Pared 1'), 'direction' => 'x', 'origin_x_mm' => 0, 'origin_y_mm' => 0, 'sort' => 10, 'modules' => []]];
        foreach ($layout['walls'] as $w) {
            $wid = insert('config_walls', ['configuration_id' => $cid, 'name' => $w['name'], 'direction' => $w['direction'],
                'origin_x_mm' => (int) $w['origin_x_mm'], 'origin_y_mm' => (int) $w['origin_y_mm'], 'sort' => (int) $w['sort']]);
            foreach ($w['modules'] as $m) {
                $t = module_type_get((int) $m['module_type_id']);
                insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => (int) $m['module_type_id'], 'width_mm' => (int) $m['width_mm'],
                    'height_mm' => (int) $t['height_mm'], 'depth_mm' => (int) $t['depth_mm'], 'sort' => (int) $m['sort']]);
            }
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

/** Invitado: como mucho 30 configuraciones nuevas (crear o duplicar) por hora y conexión. */
function guest_create_limit(array $viewer): void
{
    if ($viewer['kind'] === 'guest' && !throttle('guest-create:' . client_ip_key(), 30, 3600)) throw new UcError(t('Demasiadas configuraciones nuevas desde esta conexión. Prueba dentro de un rato.'), [], 429);
}

/** Datos del cliente (name, email, phone, notes) → columnas. Una clave que no existe es un error. */
function config_customer_input(array $in, bool $withNotes = false): array
{
    $map = ['name' => 'customer_name', 'email' => 'customer_email', 'phone' => 'customer_phone'] + ($withNotes ? ['notes' => 'notes'] : []);
    reject_unknown_keys($in, array_keys($map), 'customer');
    $d = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $in)) continue;
        if (!is_scalar($in[$k]) && $in[$k] !== null) throw new UcError(t('customer.{k} tiene que ser texto.', ['k' => $k]));
        $v = trim((string) $in[$k]);
        if ($k !== 'notes') $v = trim((string) preg_replace('/[\r\n\t]+/', ' ', $v)); // una línea (también contra fórmulas en el CSV)
        if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $v]));
        $d[$col] = mb_substr($v, 0, $k === 'notes' ? 4000 : 160);
    }
    return $d;
}

/** Un campo que no está en $allowed es un error que dice cuál sobra y cuáles valen (nunca se ignora en silencio). */
function reject_unknown_keys(array $in, array $allowed, string $where): void
{
    $extra = array_values(array_diff(array_keys($in), $allowed));
    if ($extra) {
        throw new UcError(t('{donde}: el campo «{c}» no existe. Valen: {v}.', ['donde' => $where, 'c' => implode('», «', array_map('strval', $extra)), 'v' => implode(', ', $allowed)]));
    }
}

/** Duplica (cualquier estado) en un borrador nuevo de quien lo pide, con precios de hoy. */
function config_copy(string $code, ?array $viewer): array
{
    $src = config_find($code, $viewer);
    if (!node_branch_active((int) $src['node_id'])) throw new UcError(t('El modelo de esta configuración ya no está disponible: no se puede duplicar.'));
    guest_create_limit($viewer);
    $new = tx(function () use ($src, $viewer) {
        $newCode = config_code_new();
        $cid = insert('configurations', ['code' => $newCode, 'node_id' => $src['node_id'], 'user_id' => $viewer['kind'] === 'user' ? (int) $viewer['user']['id'] : null,
            'customer_name' => $src['customer_name'], 'customer_email' => $src['customer_email'], 'customer_phone' => $src['customer_phone'],
            'notes' => $src['notes'], 'status' => 'draft', 'tax_rate' => tax_rate(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (rows('SELECT * FROM config_walls WHERE configuration_id = ? ORDER BY sort, id', [$src['id']]) as $w) {
            $wid = insert('config_walls', ['configuration_id' => $cid, 'name' => $w['name'], 'direction' => $w['direction'],
                'origin_x_mm' => $w['origin_x_mm'], 'origin_y_mm' => $w['origin_y_mm'], 'sort' => $w['sort']]);
            foreach (rows('SELECT * FROM config_items WHERE config_wall_id = ? ORDER BY sort, id', [$w['id']]) as $it) {
                insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => $it['module_type_id'], 'width_mm' => $it['width_mm'],
                    'height_mm' => $it['height_mm'], 'depth_mm' => $it['depth_mm'], 'sort' => $it['sort']]);
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
                'width_mm' => (int) $it['width_mm'], 'width_cm' => mm_to_cm((int) $it['width_mm']), 'height_mm' => $it['height_mm'], 'depth_mm' => $it['depth_mm'],
                'valid' => $it['price'] !== null, 'price' => $it['price'] === null ? null : (int) $it['price'],
                'tier_from_mm' => $it['tier_from_mm'] === null ? null : (int) $it['tier_from_mm'], 'tier_to_mm' => $it['tier_to_mm'] === null ? null : (int) $it['tier_to_mm'],
                'tier_from_cm' => mm_to_cm($it['tier_from_mm'] === null ? null : (int) $it['tier_from_mm']), 'tier_to_cm' => mm_to_cm($it['tier_to_mm'] === null ? null : (int) $it['tier_to_mm']),
                // Foto: si el ancho quedó por debajo del tramo guardado, se cobró el tramo de arriba (hueco entre tramos).
                'tier_rule' => $it['tier_from_mm'] === null ? null : ((int) $it['width_mm'] < (int) $it['tier_from_mm'] ? 'next_up' : 'exact'),
                'tier_note' => $it['tier_from_mm'] !== null && (int) $it['width_mm'] < (int) $it['tier_from_mm']
                    ? tier_note((int) $it['width_mm'], ['from_mm' => (int) $it['tier_from_mm'], 'to_mm' => (int) $it['tier_to_mm']], 'next_up') : null,
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
            $h = isset($it['height_mm']) && $it['height_mm'] !== null ? (int) $it['height_mm'] : (isset($type['height_mm']) ? (int) $type['height_mm'] : null);
            $d = isset($it['depth_mm']) && $it['depth_mm'] !== null ? (int) $it['depth_mm'] : (isset($type['depth_mm']) ? (int) $type['depth_mm'] : null);
            $parts = $p['frozen'] ? config_frozen_parts((int) $it['id'])
                : piezas_de_linea(['module_type_id' => $it['module_type_id'], 'width_mm' => $it['width_mm'], 'height_mm' => $h, 'depth_mm' => $d]);
            $items[] = [
                'id' => (int) $it['id'], 'module_type_id' => $it['module_type_id'], 'type_name' => $it['type_name'], 'ref' => $type['ref'] ?? '',
                'family' => $it['family'], 'width_mm' => (int) $it['width_mm'], 'height_mm' => $h, 'depth_mm' => $d,
                'width_cm' => $it['width_cm'], 'width_text' => cm_text((int) $it['width_mm']),
                'valid' => $it['valid'], 'tier_from_cm' => $it['tier_from_cm'], 'tier_to_cm' => $it['tier_to_cm'],
                'tier_from_mm' => $it['tier_from_mm'], 'tier_to_mm' => $it['tier_to_mm'],
                'tier_text' => $it['valid'] ? ($it['tier_from_mm'] === $it['tier_to_mm'] ? cm_text($it['tier_from_mm']) : cm_text($it['tier_from_mm']) . '–' . cm_text($it['tier_to_mm'])) . ' cm' : null,
                'tier_rule' => $it['tier_rule'] ?? null, 'tier_note' => $it['tier_note'] ?? null,
                'price' => eur($it['price']), 'price_cents' => $it['price'], 'price_text' => money($it['price']),
                'tiers' => tiers_public($it['tiers']), 'error' => $it['error'] ?? null,
                'parts' => $parts, 'parts_ok' => !array_filter($parts, fn($x) => $x['error'] !== null),
            ];
        }
        $walls[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'direction' => $w['direction'],
            'origin_x_mm' => (int) $w['origin_x_mm'], 'origin_y_mm' => (int) $w['origin_y_mm'], 'items' => $items];
    }
    return [
        'code' => $cfg['code'], 'status' => $cfg['status'], 'status_label' => t(CONFIG_STATUSES[$cfg['status']]), 'frozen' => $p['frozen'], 'units' => ['width_cm' => 'cm', '*_mm' => 'mm', 'price' => 'EUR sin IVA'],
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

/** Despiece congelado de una línea (aceptada o anulada). */
function config_frozen_parts(int $itemId): array
{
    return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int) $r['qty'], 'thickness_mm' => (float) $r['thickness_mm'], 'length_mm' => (float) $r['length_mm'],
        'width_mm' => (float) $r['width_mm'], 'grain' => $r['grain'], 'edge_banding' => $r['edge_banding'], 'error' => null],
        rows('SELECT * FROM config_item_parts WHERE config_item_id = ? ORDER BY sort, id', [$itemId]));
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
/** Comprueba que el ancho (mm) esté dentro del mínimo/máximo del tipo y tenga tramo; si no, UcError con los tramos posibles. */
function config_check_width(array $cfg, int $typeId, int $widthMm, ?int $itemId = null): void
{
    $type = module_type_get($typeId);
    $hit = tier_lookup($typeId, (int) $cfg['node_id'], $widthMm);
    $data = ['tiers' => tiers_public($hit['tiers']), 'item_id' => $itemId, 'width_cm' => mm_to_cm($widthMm), 'width_mm' => $widthMm];
    if ($type && ($e = width_range_error($type, $widthMm))) throw new UcError($e, $data + ['min_width_mm' => $type['min_width_mm'], 'max_width_mm' => $type['max_width_mm']]);
    if (!$hit['tier']) throw new UcError(tiers_error_text($type['name'] ?? (string) $typeId, $widthMm, $hit['tiers']), $data);
}

function config_active_type(mixed $id): array
{
    $tid = parse_int($id, 'module_type_id');
    $t = module_type_get($tid);
    if (!$t || !$t['active']) throw new UcError(t('El tipo de módulo {id} no existe o no está activo. Mira los tipos con module_types.', ['id' => $tid]));
    return $t;
}

/** Ancho de una operación: width_cm (cm, un decimal: 58,5) o width_mm (mm enteros). Null si no viene. */
function op_width_mm(array $op): ?int
{
    if (isset($op['width_mm']) && $op['width_mm'] !== '' && $op['width_mm'] !== null) return parse_int($op['width_mm'], 'width_mm', 10, 100000);
    if (isset($op['width_cm']) && $op['width_cm'] !== '' && $op['width_cm'] !== null) return parse_width_mm($op['width_cm'], 'width_cm');
    return null;
}

/** Campos que admite cada operación (cualquier otro es un error). */
const CONFIG_OP_FIELDS = [
    'set_width' => ['op', 'item_id', 'width_cm', 'width_mm'],
    'remove' => ['op', 'item_id'],
    'add' => ['op', 'wall_id', 'module_type_id', 'width_cm', 'width_mm'],
    'replace' => ['op', 'item_id', 'module_type_id', 'width_cm', 'width_mm'],
    'customer' => ['op', 'name', 'email', 'phone', 'notes'],
];

/**
 * Aplica operaciones a un borrador, todas o ninguna, en una transacción de escritura (BEGIN IMMEDIATE) que vuelve a
 * leer la configuración y exige que siga en borrador: nadie puede cambiar algo que se acaba de aceptar.
 *   set_width {item_id, width_cm|width_mm} · remove {item_id} · add {wall_id, module_type_id, width_cm?} ·
 *   replace {item_id, module_type_id, width_cm?} · customer {name?, email?, phone?, notes?}
 * @return array{config:array, notes:list<string>}
 */
function config_apply_ops(string $code, array $ops, ?array $viewer): array
{
    $cfg = config_find($code, $viewer);
    config_require_draft($cfg);
    if (!$ops || !array_is_list($ops)) throw new UcError(t('ops debe ser una lista de operaciones, p. ej. [{"op": "set_width", "item_id": 12, "width_cm": 60}].'));
    // Topes ANTES de abrir la transacción: un lote enorme se rechaza al momento y no bloquea la base.
    if (count($ops) > CONFIG_MAX_OPS) {
        throw new UcError(t('Demasiadas operaciones en una llamada: {n}. Como mucho {max}; parte el cambio en varias llamadas. No se ha hecho nada.', ['n' => count($ops), 'max' => CONFIG_MAX_OPS]), ['max_ops' => CONFIG_MAX_OPS]);
    }
    $adds = count(array_filter($ops, fn($o) => is_array($o) && ($o['op'] ?? '') === 'add'));
    $lines = (int) val('SELECT COUNT(*) FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id WHERE w.configuration_id = ?', [$cfg['id']]);
    if ($lines + $adds > CONFIG_MAX_LINES) {
        throw new UcError(t('Una configuración tiene como mucho {max} módulos: tiene {n} y la llamada añade {a}. No se ha hecho nada.', ['max' => CONFIG_MAX_LINES, 'n' => $lines, 'a' => $adds]), ['max_lines' => CONFIG_MAX_LINES]);
    }
    $notes = [];
    $ctx = [];
    tx(function () use ($cfg, $ops, &$notes, &$ctx) {
        $now = row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]);
        config_require_draft($now ?? $cfg);
        foreach ($ops as $n => $op) {
            try {
                if (!is_array($op)) throw new UcError(t('cada operación es un objeto con «op».'));
                $notes = array_merge($notes, config_apply_one($now, $op, $ctx));
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
function config_apply_one(array $cfg, array $op, array &$ctx = []): array
{
    // Se vuelve a leer el estado AQUÍ (dentro de la transacción de quien llama): una fila leída antes de que otra
    // petición aceptara la configuración no sirve para cambiarla.
    return tx(function () use ($cfg, $op, &$ctx) {
        $now = row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]) ?? throw new UcError(t('La configuración ya no existe.'), [], 404);
        config_require_draft($now);
        return config_apply_one_checked($now, $op, $ctx);
    });
}

/** @return list<string> — $ctx guarda entre operaciones de la misma llamada el siguiente «sort» de cada pared. */
function config_apply_one_checked(array $cfg, array $op, array &$ctx = []): array
{
    $kind = (string) ($op['op'] ?? '');
    if (!isset(CONFIG_OP_FIELDS[$kind])) throw new UcError(t('Operación «{v}» desconocida. Valen: {ops}.', ['v' => $kind, 'ops' => implode(', ', CONFIG_OPS)]));
    reject_unknown_keys($op, CONFIG_OP_FIELDS[$kind], $kind);
    switch ($kind) {
        case 'set_width':
            $it = config_item($cfg, $op['item_id'] ?? null);
            $w = op_width_mm($op) ?? throw new UcError(t('set_width necesita width_cm (p. ej. 58,5).'));
            config_check_width($cfg, (int) $it['module_type_id'], $w, (int) $it['id']);
            update('config_items', ['width_mm' => $w], 'id = ?', [$it['id']]);
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
            $w = op_width_mm($op);
            if ($w !== null) config_check_width($cfg, (int) $type['id'], $w);
            else $w = ancho_sugerido((int) $type['id'], (int) $cfg['node_id']) ?? throw new UcError(tiers_error_text($type['name'], 0, []));
            // El siguiente «sort» de la pared se calcula una vez por llamada, no en cada add.
            $ctx['sort'][$wid] ??= (int) val('SELECT COALESCE(MAX(sort), 0) FROM config_items WHERE config_wall_id = ?', [$wid]);
            $sort = $ctx['sort'][$wid] += 10;
            insert('config_items', ['config_wall_id' => $wid, 'module_type_id' => (int) $type['id'], 'width_mm' => $w,
                'height_mm' => (int) $type['height_mm'], 'depth_mm' => (int) $type['depth_mm'], 'sort' => $sort]);
            return [];
        case 'replace':
            $it = config_item($cfg, $op['item_id'] ?? null);
            $type = config_active_type($op['module_type_id'] ?? null);
            $notes = [];
            $w = op_width_mm($op);
            if ($w !== null) {
                config_check_width($cfg, (int) $type['id'], $w, (int) $it['id']);
            } else {
                $w = (int) $it['width_mm'];
                if (width_range_error($type, $w) !== null || !tier_lookup((int) $type['id'], (int) $cfg['node_id'], $w)['tier']) {
                    $new = ancho_sugerido((int) $type['id'], (int) $cfg['node_id'], $w) ?? throw new UcError(tiers_error_text($type['name'], $w, []));
                    $notes[] = t('«{tipo}» no admite {de} cm: la línea {id} pasa a {a} cm (el tramo más cercano).', ['tipo' => $type['name'], 'de' => cm_text($w), 'a' => cm_text($new), 'id' => $it['id']]);
                    $w = $new;
                }
            }
            update('config_items', ['module_type_id' => (int) $type['id'], 'width_mm' => $w, 'height_mm' => (int) $type['height_mm'], 'depth_mm' => (int) $type['depth_mm']], 'id = ?', [$it['id']]);
            return $notes;
        case 'customer':
            $d = config_customer_input(array_diff_key($op, ['op' => 1]), true);
            if (!$d) throw new UcError(t('customer necesita al menos uno de: name, email, phone, notes.'));
            update('configurations', $d, 'id = ?', [$cfg['id']]);
            return [];
    }
    return [];
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
    $line = fn($v) => trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v));
    $cust = ['customer_name' => $line($customer['name'] ?? $cfg['customer_name']), 'customer_email' => $line($customer['email'] ?? $cfg['customer_email']),
        'customer_phone' => $line($customer['phone'] ?? $cfg['customer_phone'])];
    if ($cust['customer_email'] !== '' && !filter_var($cust['customer_email'], FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $cust['customer_email']]));
    if ($guest) {
        if ($cust['customer_name'] === '' || $cust['customer_email'] === '' || $cust['customer_phone'] === '') throw new UcError(t('Para aceptar escribe tu nombre, tu correo y tu teléfono.'));
        if (!$consent) throw new UcError(t('Para aceptar marca la casilla de protección de datos.'));
    }
    $p = tx(function () use ($cfg, $cust, $consent) {
        config_require_draft(row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]) ?? $cfg); // se vuelve a mirar dentro de la transacción
        $p = precio_configuracion((int) $cfg['node_id'], config_walls_input((int) $cfg['id']));
        if ($p['count'] === 0) throw new UcError(t('La configuración no tiene ningún módulo: añade al menos uno antes de aceptar.'));
        if (!$p['valid']) {
            $bad = [];
            foreach ($p['walls'] as $w) foreach ($w['items'] as $it) if (!$it['valid']) $bad[] = t('línea {id}: {e}', ['id' => $it['id'], 'e' => $it['error']]);
            throw new UcError(t('No se puede aceptar: hay medidas sin tramo. {lista}', ['lista' => implode(' · ', $bad)]));
        }
        $badParts = [];
        foreach ($p['walls'] as $w) foreach ($w['items'] as $it) {
            foreach (piezas_de_linea($it) as $pp) if ($pp['error'] !== null) $badParts[] = t('línea {id}: {e}', ['id' => $it['id'], 'e' => $pp['error']]);
        }
        if ($badParts) throw new UcError(t('No se puede aceptar: hay piezas que no se pueden fabricar con esas medidas. {lista}', ['lista' => implode(' · ', $badParts)]));
        config_freeze((int) $cfg['id'], $p, 'accepted', ['accepted_at' => now(), 'consent_at' => $consent ? now() : null,
            'customer_name' => mb_substr($cust['customer_name'], 0, 160), 'customer_email' => mb_substr($cust['customer_email'], 0, 160), 'customer_phone' => mb_substr($cust['customer_phone'], 0, 160)]);
        return $p;
    });
    audit('config.accept', $cfg['code'] . ' · ' . money($p['total']) . ' · ' . viewer_label($viewer));
    return config_get($code, $viewer);
}

/**
 * Escribe la foto del precio (tramo y precio por línea, total e IVA) y cambia el estado, solo si sigue en borrador.
 * La usan aceptar y anular: una sola forma de congelar.
 */
function config_freeze(int $configId, array $p, string $status, array $extra = []): void
{
    foreach ($p['walls'] as $w) foreach ($w['items'] as $it) {
        update('config_items', ['tier_from_mm' => $it['tier_from_mm'], 'tier_to_mm' => $it['tier_to_mm'], 'price' => $it['price']], 'id = ?', [$it['id']]);
        // Despiece congelado (como el precio): un cambio posterior de fórmulas no cambia lo aceptado.
        q('DELETE FROM config_item_parts WHERE config_item_id = ?', [$it['id']]);
        foreach (piezas_de_linea($it) as $i => $pp) {
            if ($pp['error'] !== null) continue;
            insert('config_item_parts', ['config_item_id' => $it['id'], 'name' => $pp['name'], 'qty' => $pp['qty'], 'thickness_mm' => $pp['thickness_mm'],
                'length_mm' => $pp['length_mm'], 'width_mm' => $pp['width_mm'], 'grain' => $pp['grain'], 'edge_banding' => $pp['edge_banding'], 'sort' => $i]);
        }
    }
    $data = ['status' => $status, 'total' => $p['total'], 'tax_rate' => $p['tax_rate'], 'updated_at' => now()] + $extra;
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
    $n = q("UPDATE configurations SET $set WHERE id = ? AND status = 'draft'", [...array_values($data), $configId])->rowCount();
    if ($n !== 1) throw new UcError(t('La configuración ya no es un borrador.'), [], 409);
}

/** Anular (solo admin). */
function config_cancel(string $code, ?array $viewer): array
{
    $cfg = config_find($code, $viewer);
    if (($viewer['user']['role'] ?? '') !== 'admin') throw new UcError(t('Solo un administrador puede anular configuraciones.'), [], 403);
    if ($cfg['status'] === 'cancelled') return config_get($code, $viewer);
    tx(function () use ($cfg) {
        $cfg = row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]) ?? $cfg;
        if ($cfg['status'] === 'cancelled') return;
        if ($cfg['status'] === 'draft') {
            // Un borrador anulado guarda la foto de cómo estaba (las medidas sin tramo quedan sin precio).
            config_freeze((int) $cfg['id'], precio_configuracion((int) $cfg['node_id'], config_walls_input((int) $cfg['id'])), 'cancelled');
        } else {
            update('configurations', ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [$cfg['id']]);
        }
    });
    audit('config.cancel', $cfg['code']);
    return config_get($code, $viewer);
}

// ---------------------------------------------------------------- exportar

function config_export_json(array $view): string
{
    return json_enc(['generated_at' => date('c'), 'app' => 'Universal Configurator ' . UC_VERSION] + $view + ['cut_list' => cut_list($view)], true);
}

/**
 * Celda CSV segura. Se entrecomilla si lleva ; , " CR o LF. Una fórmula (= + - @ tabulador CR al principio) se
 * neutraliza con «'» al principio de CADA trozo separado por coma o punto y coma (Excel puede partir por cualquiera
 * de los dos). Los números tal cual (-12,5).
 */
function csv_cell(mixed $v): string
{
    $s = (string) $v;
    if (!preg_match('/^-?\d+([.,]\d+)?$/', $s)) {
        $s = (string) preg_replace_callback('/(^|[,;\r\n])([ \t]*)([=+\-@\t\r])/', fn($m) => $m[1] . $m[2] . "'" . $m[3], $s);
    }
    return preg_match('/[;,"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

function csv_join(array $rows): string
{
    return "\u{FEFF}" . implode("\r\n", array_map(fn($r) => implode(';', array_map('csv_cell', $r)), $rows)) . "\r\n";
}

/** CSV (separador «;», decimales con coma, UTF-8 con BOM para Excel): una fila por módulo, con sus medidas en mm. */
function config_export_csv(array $views): string
{
    $num = fn(?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    $rows = [['codigo', 'estado', 'creada', 'aceptada', 'nodo', 'cliente', 'correo', 'telefono', 'pared', 'direccion', 'origen_x_mm', 'origen_y_mm',
        'linea', 'modulo', 'ref', 'familia', 'ancho_cm', 'ancho_mm', 'alto_mm', 'fondo_mm', 'tramo_desde_cm', 'tramo_hasta_cm', 'precio_eur', 'total_sin_iva_eur', 'iva_pct', 'iva_eur']];
    foreach ($views as $v) {
        foreach ($v['walls'] as $w) {
            foreach ($w['items'] as $it) {
                $rows[] = [$v['code'], $v['status'], $v['created_at'], $v['accepted_at'] ?? '', $v['node']['path_text'],
                    $v['customer']['name'], $v['customer']['email'], $v['customer']['phone'], $w['name'], $w['direction'], $w['origin_x_mm'], $w['origin_y_mm'],
                    $it['id'], $it['type_name'], $it['ref'], $it['family'], $it['width_text'], $it['width_mm'], $it['height_mm'], $it['depth_mm'],
                    $it['tier_from_mm'] === null ? '' : cm_text($it['tier_from_mm']), $it['tier_to_mm'] === null ? '' : cm_text($it['tier_to_mm']),
                    $num($it['price_cents']), $num($v['total_cents']), str_replace('.', ',', (string) $v['tax_rate']), $num($v['tax_cents'])];
            }
        }
    }
    return csv_join($rows);
}

/** CSV del despiece: una fila por pieza de cada línea (medidas en mm, decimales con coma). */
function config_export_parts_csv(array $view): string
{
    $rows = [['codigo', 'pared', 'linea', 'modulo', 'ancho_modulo_cm', 'pieza', 'cantidad', 'espesor_mm', 'largo_mm', 'ancho_mm', 'veta', 'canto', 'error']];
    foreach ($view['walls'] as $w) foreach ($w['items'] as $it) foreach ($it['parts'] as $p) {
        $rows[] = [$view['code'], $w['name'], $it['id'], $it['type_name'], $it['width_text'], $p['name'], $p['qty'], mm_text($p['thickness_mm']),
            mm_text($p['length_mm']), mm_text($p['width_mm']), $p['grain'], $p['edge_banding'], $p['error'] ?? ''];
    }
    return csv_join($rows);
}

/** CSV de la lista de corte agregada. */
function config_export_cut_csv(array $view): string
{
    $rows = [['codigo', 'pieza', 'cantidad', 'espesor_mm', 'largo_mm', 'ancho_mm', 'veta', 'canto', 'origen']];
    foreach (cut_list($view) as $r) {
        $rows[] = [$view['code'], implode(' / ', $r['names']), $r['qty'], mm_text($r['thickness_mm']), mm_text($r['length_mm']), mm_text($r['width_mm']), $r['grain'], $r['edge_banding'],
            implode(' | ', array_map(fn($f) => "{$f['module']} {$f['width_cm']} cm (línea {$f['item_id']}, {$f['wall']}) ×{$f['qty']}", $r['from']))];
    }
    return csv_join($rows);
}
