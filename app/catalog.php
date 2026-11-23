<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Catálogo: árbol genérico de nodos (sin límite de niveles), tipos de módulo y composición por defecto
 * (paredes con sus módulos). Los tramos de precio viven en pricing.php.
 *
 * Nodo final = nodo activo sin hijos activos. La composición por defecto se busca en el propio nodo final y,
 * si no tiene, en el antepasado más cercano que la tenga (así se define una vez por modelo y vale para sus colores).
 */

// ---------------------------------------------------------------- nodos

function node_get(int $id): ?array
{
    return row('SELECT * FROM nodes WHERE id = ?', [$id]);
}

/** Hijos de un nodo (null = raíces), en orden. */
function node_children(?int $parentId, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM nodes WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?') . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY sort, id';
    return rows($sql, $parentId === null ? [] : [$parentId]);
}

function node_is_final(int $id): bool
{
    return (int) val('SELECT COUNT(*) FROM nodes WHERE parent_id = ? AND active = 1', [$id]) === 0;
}

/** Ids del nodo y de sus antepasados, del nodo hacia la raíz. */
function node_lineage(int $id): array
{
    $out = [];
    $guard = 0;
    for ($cur = node_get($id); $cur && $guard++ < 100; $cur = $cur['parent_id'] !== null ? node_get((int) $cur['parent_id']) : null) $out[] = (int) $cur['id'];
    return $out;
}

/** Camino desde la raíz: [['id'=>…, 'name'=>…], …]. */
function node_path(int $id): array
{
    $out = [];
    foreach (array_reverse(node_lineage($id)) as $nid) {
        $n = node_get($nid);
        $out[] = ['id' => (int) $n['id'], 'name' => $n['name']];
    }
    return $out;
}

function node_path_text(int $id): string
{
    return implode(' › ', array_column(node_path($id), 'name'));
}

/** ¿Está el nodo y toda su rama activa? (un hijo de un nodo desactivado tampoco se puede configurar) */
function node_branch_active(int $id): bool
{
    foreach (node_lineage($id) as $nid) if (!(int) val('SELECT active FROM nodes WHERE id = ?', [$nid])) return false;
    return true;
}

/** Árbol anidado: [{id, name, slug, description, image, active, is_final, children:[…]}]. */
function node_tree(bool $onlyActive = true, ?int $parentId = null, int $depth = 0): array
{
    if ($depth > 50) return [];
    $out = [];
    foreach (node_children($parentId, $onlyActive) as $n) {
        $children = node_tree($onlyActive, (int) $n['id'], $depth + 1);
        $out[] = ['id' => (int) $n['id'], 'name' => $n['name'], 'slug' => $n['slug'], 'description' => $n['description'],
            'image' => $n['image'], 'active' => (bool) $n['active'],
            'is_final' => !array_filter($children, fn($c) => $c['active']), 'children' => $children];
    }
    return $out;
}

function node_validate(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') throw new UcError(t('Escribe el nombre del nodo.'));
    if (mb_strlen($name) > 120) throw new UcError(t('El nombre es demasiado largo (máximo 120 caracteres).'));
    $image = trim((string) ($in['image'] ?? ''));
    // Imagen: color #RRGGBB, ilustración de la app («ill:tipo:#RRGGBB»), imagen subida (/media/…) o una dirección https.
    $okImage = preg_match('/^#[0-9a-fA-F]{6}$/', $image) || illustration_valid($image)
        || preg_match('#^/media/[a-f0-9]{24}\.(png|jpg|webp)$#', $image) || preg_match('#^https://[^\s"\'<>]+$#', $image);
    if ($image !== '' && !$okImage) {
        throw new UcError(t('Imagen: pon un color #RRGGBB, una ilustración (ill:tipo:#RRGGBB), una imagen subida o una dirección https://…; recibido «{v}»', ['v' => mb_substr($image, 0, 60)]));
    }
    return ['name' => $name, 'description' => trim((string) ($in['description'] ?? '')), 'image' => $image === '' ? null : $image];
}

function node_unique_slug(?int $parentId, string $base, ?int $exceptId = null): string
{
    $base = slugify($base) ?: 'nodo';
    $slug = $base;
    for ($i = 2; ; $i++) {
        $exists = val('SELECT 1 FROM nodes WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ' . (int) $parentId) . ' AND slug = ? AND id != ?', [$slug, $exceptId ?? 0]);
        if (!$exists) return $slug;
        $slug = "$base-$i";
    }
}

function node_create(?int $parentId, array $in): int
{
    if ($parentId !== null && !node_get($parentId)) throw new UcError(t('El nodo padre {id} no existe.', ['id' => $parentId]));
    $d = node_validate($in);
    $sort = (int) val('SELECT COALESCE(MAX(sort), 0) + 10 FROM nodes WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'), $parentId === null ? [] : [$parentId]);
    $id = insert('nodes', $d + ['parent_id' => $parentId, 'slug' => node_unique_slug($parentId, (string) ($in['slug'] ?? '') ?: $d['name']), 'sort' => $sort, 'active' => 1]);
    audit('node.create', node_path_text($id));
    return $id;
}

function node_update(int $id, array $in): void
{
    $n = node_get($id) ?? throw new UcError(t('El nodo {id} no existe.', ['id' => $id]), [], 404);
    $d = node_validate($in);
    $slug = trim((string) ($in['slug'] ?? '')) ?: $n['slug'];
    update('nodes', $d + ['slug' => node_unique_slug($n['parent_id'] === null ? null : (int) $n['parent_id'], $slug, $id)], 'id = ?', [$id]);
    audit('node.update', node_path_text($id));
}

function node_set_active(int $id, bool $active): void
{
    node_get($id) ?? throw new UcError(t('El nodo {id} no existe.', ['id' => $id]), [], 404);
    update('nodes', ['active' => $active ? 1 : 0], 'id = ?', [$id]);
    audit($active ? 'node.activate' : 'node.deactivate', node_path_text($id));
}

/** Sube o baja un nodo entre sus hermanos. */
function node_move(int $id, int $dir): void
{
    $n = node_get($id) ?? throw new UcError(t('El nodo {id} no existe.', ['id' => $id]), [], 404);
    $sibs = node_children($n['parent_id'] === null ? null : (int) $n['parent_id'], false);
    $ids = array_map(fn($s) => (int) $s['id'], $sibs);
    $pos = array_search($id, $ids, true);
    $to = $pos + ($dir < 0 ? -1 : 1);
    if ($pos === false || $to < 0 || $to >= count($ids)) return;
    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
    tx(function () use ($ids) { foreach ($ids as $i => $nid) update('nodes', ['sort' => ($i + 1) * 10], 'id = ?', [$nid]); });
}

/** Borrar solo si no tiene hijos ni configuraciones; si no, se desactiva. */
function node_delete(int $id): void
{
    $n = node_get($id) ?? throw new UcError(t('El nodo {id} no existe.', ['id' => $id]), [], 404);
    if (val('SELECT 1 FROM nodes WHERE parent_id = ?', [$id])) throw new UcError(t('«{n}» tiene nodos hijos: bórralos antes o desactívalo.', ['n' => $n['name']]));
    if (val('SELECT 1 FROM configurations WHERE node_id = ?', [$id])) throw new UcError(t('«{n}» tiene configuraciones guardadas: no se borra, desactívalo.', ['n' => $n['name']]));
    $path = node_path_text($id);
    q('DELETE FROM nodes WHERE id = ?', [$id]);
    audit('node.delete', $path);
}

// ---------------------------------------------------------------- tipos de módulo

function module_type_get(int $id): ?array
{
    return row('SELECT * FROM module_types WHERE id = ?', [$id]);
}

function module_types_all(bool $onlyActive = true): array
{
    return rows('SELECT * FROM module_types' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort, family, name, id');
}

function module_type_save(array $in, ?int $id = null): int
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') throw new UcError(t('Escribe el nombre del tipo de módulo.'));
    $d = [
        'name' => mb_substr($name, 0, 120),
        'ref' => mb_substr(trim((string) ($in['ref'] ?? '')), 0, 60),
        'description' => trim((string) ($in['description'] ?? '')),
        'family' => mb_substr(trim((string) ($in['family'] ?? '')), 0, 60),
        'width_mm' => parse_int($in['width_mm'] ?? 600, t('Ancho por defecto (mm)'), 1, 10000),
        'height_mm' => parse_int($in['height_mm'] ?? 720, t('Alto (mm)'), 1, 5000),
        'depth_mm' => parse_int($in['depth_mm'] ?? 560, t('Fondo (mm)'), 1, 5000),
        'min_width_mm' => ($in['min_width_mm'] ?? '') === '' || $in['min_width_mm'] === null ? null : parse_int($in['min_width_mm'], t('Ancho mínimo (mm)'), 1, 10000),
        'max_width_mm' => ($in['max_width_mm'] ?? '') === '' || $in['max_width_mm'] === null ? null : parse_int($in['max_width_mm'], t('Ancho máximo (mm)'), 1, 10000),
        'board_mm' => parse_thickness($in['board_mm'] ?? 19, t('Tablero T (mm)')),
        'back_mm' => parse_thickness($in['back_mm'] ?? 8, t('Trasera B (mm)')),
        'sort' => isset($in['sort']) && $in['sort'] !== '' ? parse_int($in['sort'], t('Orden'), 0, 100000) : 0,
    ];
    if ($d['min_width_mm'] !== null && $d['max_width_mm'] !== null && $d['min_width_mm'] > $d['max_width_mm']) {
        throw new UcError(t('El ancho mínimo ({a} mm) es mayor que el máximo ({b} mm).', ['a' => $d['min_width_mm'], 'b' => $d['max_width_mm']]));
    }
    if (($d['min_width_mm'] !== null && $d['width_mm'] < $d['min_width_mm']) || ($d['max_width_mm'] !== null && $d['width_mm'] > $d['max_width_mm'])) {
        throw new UcError(t('El ancho por defecto ({w} mm) tiene que estar entre el mínimo y el máximo.', ['w' => $d['width_mm']]));
    }
    if ($id === null) {
        $id = insert('module_types', $d + ['active' => 1]);
        audit('module_type.create', $d['name']);
    } else {
        module_type_get($id) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $id]), [], 404);
        update('module_types', $d, 'id = ?', [$id]);
        audit('module_type.update', $d['name']);
    }
    return $id;
}

/** Espesor en mm con un decimal como mucho (19, 18,5), entre 1 y 100. */
function parse_thickness(mixed $v, string $field): float
{
    $s = str_replace(',', '.', trim((string) $v));
    if (!preg_match('/^\d{1,3}(\.\d)?$/', $s) || (float) $s <= 0 || (float) $s > 100) throw new UcError(t('{campo}: «{v}» no es un espesor válido en mm (p. ej. 19 o 18,5).', ['campo' => $field, 'v' => mb_substr((string) $v, 0, 20)]));
    return (float) $s;
}

function module_type_set_active(int $id, bool $active): void
{
    $t = module_type_get($id) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $id]), [], 404);
    update('module_types', ['active' => $active ? 1 : 0], 'id = ?', [$id]);
    audit($active ? 'module_type.activate' : 'module_type.deactivate', $t['name']);
}

/** Familias usadas (para agrupar en los selectores). */
function module_families(): array
{
    return array_column(rows("SELECT DISTINCT family FROM module_types WHERE family != '' ORDER BY family"), 'family');
}

// ---------------------------------------------------------------- composición por defecto

function layout_walls_of(int $nodeId): array
{
    return rows('SELECT * FROM layout_walls WHERE node_id = ? ORDER BY sort, id', [$nodeId]);
}

/**
 * Composición con la que arranca un nodo final: la suya o la del antepasado más cercano que tenga paredes.
 * @return array{node_id:?int, walls:list<array>}  cada pared con 'modules' => [{module_type_id, width_mm}]
 */
function layout_for(int $nodeId): array
{
    foreach (node_lineage($nodeId) as $nid) {
        $walls = layout_walls_of($nid);
        if (!$walls) continue;
        foreach ($walls as &$w) $w['modules'] = rows('SELECT * FROM default_modules WHERE layout_wall_id = ? ORDER BY sort, id', [$w['id']]);
        return ['node_id' => $nid, 'walls' => $walls];
    }
    return ['node_id' => null, 'walls' => []];
}

function wall_validate(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') throw new UcError(t('Escribe el nombre de la pared.'));
    $dir = (string) ($in['direction'] ?? 'x');
    if (!in_array($dir, ['x', 'y'], true)) throw new UcError(t('Dirección: «{v}» no vale; usa x o y.', ['v' => $dir]));
    return [
        'name' => mb_substr($name, 0, 80), 'direction' => $dir,
        'origin_x_mm' => parse_int($in['origin_x_mm'] ?? 0, t('Origen X (mm)'), -100000, 100000),
        'origin_y_mm' => parse_int($in['origin_y_mm'] ?? 0, t('Origen Y (mm)'), -100000, 100000),
    ];
}

function layout_wall_save(int $nodeId, array $in, ?int $id = null): int
{
    node_get($nodeId) ?? throw new UcError(t('El nodo {id} no existe.', ['id' => $nodeId]), [], 404);
    $d = wall_validate($in);
    if ($id === null) {
        $sort = (int) val('SELECT COALESCE(MAX(sort), 0) + 10 FROM layout_walls WHERE node_id = ?', [$nodeId]);
        $id = insert('layout_walls', $d + ['node_id' => $nodeId, 'sort' => $sort]);
    } else {
        if (!val('SELECT 1 FROM layout_walls WHERE id = ? AND node_id = ?', [$id, $nodeId])) throw new UcError(t('La pared {id} no es de este nodo.', ['id' => $id]), [], 404);
        update('layout_walls', $d, 'id = ?', [$id]);
    }
    audit('layout.wall', node_path_text($nodeId) . ': ' . $d['name']);
    return $id;
}

function layout_wall_delete(int $nodeId, int $id): void
{
    q('DELETE FROM layout_walls WHERE id = ? AND node_id = ?', [$id, $nodeId]);
    audit('layout.wall_delete', node_path_text($nodeId) . " #$id");
}

/** Añade (o cambia) un módulo por defecto. El ancho tiene que tener tramo para ese nodo. */
function default_module_save(int $wallId, int $typeId, mixed $width, ?int $id = null): int
{
    $wall = row('SELECT * FROM layout_walls WHERE id = ?', [$wallId]) ?? throw new UcError(t('La pared {id} no existe.', ['id' => $wallId]), [], 404);
    $type = module_type_get($typeId) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $typeId]));
    $w = parse_width_mm($width, t('Ancho (cm)'));
    if ($e = width_range_error($type, $w)) throw new UcError($e);
    $hit = tier_lookup($typeId, (int) $wall['node_id'], $w);
    if (!$hit['tier']) throw new UcError(tiers_error_text($type['name'], $w, $hit['tiers']), ['tiers' => tiers_public($hit['tiers'])]);
    if ($id === null) {
        $sort = (int) val('SELECT COALESCE(MAX(sort), 0) + 10 FROM default_modules WHERE layout_wall_id = ?', [$wallId]);
        $id = insert('default_modules', ['layout_wall_id' => $wallId, 'module_type_id' => $typeId, 'width_mm' => $w, 'sort' => $sort]);
    } else {
        update('default_modules', ['module_type_id' => $typeId, 'width_mm' => $w], 'id = ? AND layout_wall_id = ?', [$id, $wallId]);
    }
    return $id;
}

function default_module_delete(int $wallId, int $id): void
{
    q('DELETE FROM default_modules WHERE id = ? AND layout_wall_id = ?', [$id, $wallId]);
}

/** Sube o baja un módulo por defecto dentro de su pared. */
function default_module_move(int $wallId, int $id, int $dir): void
{
    $ids = array_map('intval', array_column(rows('SELECT id FROM default_modules WHERE layout_wall_id = ? ORDER BY sort, id', [$wallId]), 'id'));
    $pos = array_search($id, $ids, true);
    $to = $pos + ($dir < 0 ? -1 : 1);
    if ($pos === false || $to < 0 || $to >= count($ids)) return;
    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
    tx(function () use ($ids) { foreach ($ids as $i => $mid) update('default_modules', ['sort' => ($i + 1) * 10], 'id = ?', [$mid]); });
}
