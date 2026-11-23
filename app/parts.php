<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Despiece por fórmulas. Cada tipo de módulo tiene sus piezas (module_parts): nombre, cantidad, espesor, largo y
 * ancho como fórmulas de W, H, D (medidas del módulo en mm), T (tablero) y B (trasera), con el intérprete de expr.php.
 *
 * piezas_de_linea() es la ÚNICA función que calcula las piezas de una línea (la usan la API, el panel, el MCP y la
 * congelación al aceptar). Medidas en mm redondeadas a 0,5 mm. Una pieza que da ≤ 0 con esas medidas sale con
 * medidas null y su error (y la configuración no se puede aceptar así).
 */

const GRAINS = ['' => 'Sin veta', 'largo' => 'Veta a lo largo', 'ancho' => 'Veta a lo ancho'];
const GRAINS_SHORT = ['' => 'Sin veta', 'largo' => 'A lo largo', 'ancho' => 'A lo ancho']; // en la columna «Veta» del editor

function module_parts_of(int $typeId): array
{
    return rows('SELECT * FROM module_parts WHERE module_type_id = ? ORDER BY sort, id', [$typeId]);
}

/** Variables de las fórmulas para un tipo, con el ancho, alto y fondo de la línea (o los del tipo). */
function part_vars(array $type, ?int $w = null, ?int $h = null, ?int $d = null): array
{
    return ['W' => (float) ($w ?? $type['width_mm']), 'H' => (float) ($h ?? $type['height_mm']), 'D' => (float) ($d ?? $type['depth_mm']),
        'T' => (float) $type['board_mm'], 'B' => (float) $type['back_mm']];
}

/**
 * Valida y guarda una pieza. Entrada: name, qty, thickness (número o fórmula, p. ej. «T» o «16»), length_expr,
 * width_expr, grain ('' | largo | ancho), edge_banding (texto libre), sort. Cada fórmula se analiza y se calcula con
 * las medidas por defecto del tipo: tiene que dar un número mayor que 0.
 */
function module_part_save(int $typeId, array $in, ?int $id = null): int
{
    $type = module_type_get($typeId) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $typeId]), [], 404);
    if (!is_scalar($in['name'] ?? '') || !is_scalar($in['grain'] ?? '') || !is_scalar($in['edge_banding'] ?? '')) throw new UcError(t('Nombre, veta y canto de la pieza tienen que ser texto.'));
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') throw new UcError(t('Escribe el nombre de la pieza (p. ej. «Lateral»).'));
    $qty = parse_int($in['qty'] ?? 1, t('Cantidad'), 1, 1000);
    $grain = (string) ($in['grain'] ?? '');
    if (!isset(GRAINS[$grain])) throw new UcError(t('La veta «{v}» no vale: deja vacío, «largo» o «ancho».', ['v' => mb_substr($grain, 0, 20)]));
    $vars = part_vars($type);
    $exprs = [];
    foreach (['thickness' => t('Espesor'), 'length_expr' => t('Largo'), 'width_expr' => t('Ancho')] as $k => $label) {
        $raw = $in[$k] ?? ($k === 'thickness' ? 'T' : '');
        if (!is_scalar($raw)) throw new UcError(t('{campo} de «{p}» tiene que ser texto (una fórmula), no una lista.', ['campo' => $label, 'p' => $name]));
        $src = trim((string) $raw);
        try {
            $val = expr_value($src, $vars);
        } catch (UcError $ex) {
            throw new UcError(t('{campo} de «{p}»: {e}', ['campo' => $label, 'p' => $name, 'e' => $ex->getMessage()]));
        }
        if ($val <= 0) {
            throw new UcError(t('{campo} de «{p}» da {v} mm con las medidas por defecto (W={w}, H={h}, D={d}): tiene que ser mayor que 0.', [
                'campo' => $label, 'p' => $name, 'v' => mm_text(round_half($val)), 'w' => $vars['W'], 'h' => $vars['H'], 'd' => $vars['D']]));
        }
        $exprs[$k] = $src;
    }
    $d = ['module_type_id' => $typeId, 'name' => mb_substr($name, 0, 80), 'qty' => $qty, 'thickness_expr' => $exprs['thickness'],
        'length_expr' => $exprs['length_expr'], 'width_expr' => $exprs['width_expr'], 'grain' => $grain,
        'edge_banding' => mb_substr(trim((string) ($in['edge_banding'] ?? '')), 0, 60),
        'sort' => isset($in['sort']) && $in['sort'] !== '' ? parse_int($in['sort'], t('Orden'), 0, 100000) : (int) val('SELECT COALESCE(MAX(sort), 0) + 10 FROM module_parts WHERE module_type_id = ?', [$typeId])];
    if ($id === null) {
        $id = insert('module_parts', $d);
    } else {
        if (!val('SELECT 1 FROM module_parts WHERE id = ? AND module_type_id = ?', [$id, $typeId])) throw new UcError(t('La pieza {id} no es de este tipo.', ['id' => $id]), [], 404);
        update('module_parts', $d, 'id = ?', [$id]);
    }
    audit('part.save', $type['name'] . ': ' . $d['name'] . " ×{$qty} ({$d['thickness_expr']}; {$d['length_expr']} × {$d['width_expr']})");
    return $id;
}

function module_part_delete(int $typeId, int $id): void
{
    q('DELETE FROM module_parts WHERE id = ? AND module_type_id = ?', [$id, $typeId]);
    audit('part.delete', "tipo $typeId, pieza $id");
}

/**
 * Piezas de una línea: [{name, qty, thickness_mm, length_mm, width_mm, grain, edge_banding, error}].
 * $item: module_type_id, width_mm y, si los tiene, height_mm y depth_mm (si no, los del tipo).
 */
function piezas_de_linea(array $item): array
{
    $type = module_type_get((int) $item['module_type_id']);
    if (!$type) return [];
    $vars = part_vars($type, isset($item['width_mm']) ? (int) $item['width_mm'] : null,
        isset($item['height_mm']) && $item['height_mm'] !== null ? (int) $item['height_mm'] : null,
        isset($item['depth_mm']) && $item['depth_mm'] !== null ? (int) $item['depth_mm'] : null);
    $out = [];
    foreach (module_parts_of((int) $type['id']) as $p) {
        $row = ['name' => $p['name'], 'qty' => (int) $p['qty'], 'thickness_mm' => null, 'length_mm' => null, 'width_mm' => null,
            'grain' => $p['grain'], 'edge_banding' => $p['edge_banding'], 'error' => null,
            'formulas' => ['thickness' => $p['thickness_expr'], 'length' => $p['length_expr'], 'width' => $p['width_expr']]];
        try {
            $vals = [];
            foreach (['thickness_mm' => 'thickness_expr', 'length_mm' => 'length_expr', 'width_mm' => 'width_expr'] as $k => $col) {
                $vals[$k] = round_half(expr_value($p[$col], $vars));
                if ($vals[$k] <= 0) throw new UcError(t('«{p}»: {campo} da {v} mm con un ancho de {w} cm.', ['p' => $p['name'], 'campo' => $col === 'length_expr' ? t('el largo') : ($col === 'width_expr' ? t('el ancho') : t('el espesor')), 'v' => mm_text($vals[$k]), 'w' => cm_text((int) $vars['W'])]));
            }
            $row = array_merge($row, $vals);
        } catch (UcError $ex) {
            $row['error'] = $ex->getMessage();
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Lista de corte de una configuración (su vista de config_view): piezas iguales (espesor, largo, ancho, veta y
 * canto) sumadas aunque se llamen distinto (names las lista; name es la primera), con el módulo de origen de cada una.
 * Las piezas con error no entran.
 */
function cut_list(array $view): array
{
    $acc = [];
    foreach ($view['walls'] as $w) {
        foreach ($w['items'] as $it) {
            foreach ($it['parts'] as $p) {
                if ($p['error'] !== null || $p['length_mm'] === null) continue;
                // Iguales = mismo espesor, largo y ancho (y misma veta y canto, que cambian cómo se corta y se chapa).
                $key = implode('|', [$p['thickness_mm'], $p['length_mm'], $p['width_mm'], $p['grain'], $p['edge_banding']]);
                $acc[$key] ??= ['name' => $p['name'], 'names' => [], 'qty' => 0, 'thickness_mm' => $p['thickness_mm'], 'length_mm' => $p['length_mm'], 'width_mm' => $p['width_mm'],
                    'grain' => $p['grain'], 'edge_banding' => $p['edge_banding'], 'from' => []];
                $acc[$key]['qty'] += $p['qty'];
                if (!in_array($p['name'], $acc[$key]['names'], true)) $acc[$key]['names'][] = $p['name'];
                $acc[$key]['from'][] = ['item_id' => $it['id'], 'module' => $it['type_name'], 'wall' => $w['name'], 'width_cm' => $it['width_cm'], 'qty' => $p['qty']];
            }
        }
    }
    $out = array_values($acc);
    usort($out, fn($a, $b) => [$b['thickness_mm'], $b['length_mm'], $b['width_mm'], $a['name']] <=> [$a['thickness_mm'], $a['length_mm'], $a['width_mm'], $b['name']]);
    return $out;
}

/** Fórmulas del tipo y piezas con las medidas por defecto (panel y MCP). */
function parts_definition(array $type): array
{
    return array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'qty' => (int) $p['qty'], 'thickness' => $p['thickness_expr'],
        'length_expr' => $p['length_expr'], 'width_expr' => $p['width_expr'], 'grain' => $p['grain'], 'edge_banding' => $p['edge_banding']], module_parts_of((int) $type['id']));
}
