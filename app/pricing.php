<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Motor de precios. UNA verdad, en el servidor:
 *  - Precio de un módulo = el del tramo cuyo [desde_cm, hasta_cm] (ambos incluidos) contiene su ancho. No se cobra por cm.
 *  - Sin tramo que lo contenga, la medida NO es válida (la línea sale sin precio y con los tramos posibles).
 *  - Tramos por nodo: el antepasado más cercano (empezando por el propio nodo) que tenga tramos de ese tipo
 *    SUSTITUYE a los generales del tipo; si ninguno tiene, valen los generales.
 *  - Total = suma de líneas válidas, en céntimos, sin IVA. El IVA (ajustes) solo se muestra aparte.
 * precio_configuracion() la usan la tablet (vía /api), el panel y el MCP. Nunca se calcula en JavaScript.
 */

/**
 * Tramos que valen para un tipo en un nodo.
 * @return array{node_id:?int, tiers:list<array{id:int,from_cm:int,to_cm:int,price:int}>}
 */
function tiers_effective(int $typeId, int $nodeId): array
{
    $map = fn(array $rs) => array_map(fn($t) => ['id' => (int) $t['id'], 'from_cm' => (int) $t['from_cm'], 'to_cm' => (int) $t['to_cm'], 'price' => (int) $t['price']], $rs);
    foreach (node_lineage($nodeId) as $nid) {
        $rs = rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id = ? ORDER BY from_cm', [$typeId, $nid]);
        if ($rs) return ['node_id' => $nid, 'tiers' => $map($rs)];
    }
    return ['node_id' => null, 'tiers' => $map(rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NULL ORDER BY from_cm', [$typeId]))];
}

/** @return array{tier:?array, tiers:list, node_id:?int} */
function tier_lookup(int $typeId, int $nodeId, int $width): array
{
    $eff = tiers_effective($typeId, $nodeId);
    foreach ($eff['tiers'] as $t) if ($width >= $t['from_cm'] && $width <= $t['to_cm']) return ['tier' => $t, 'tiers' => $eff['tiers'], 'node_id' => $eff['node_id']];
    return ['tier' => null, 'tiers' => $eff['tiers'], 'node_id' => $eff['node_id']];
}

function tiers_text(array $tiers): string
{
    return implode(', ', array_map(fn($t) => "{$t['from_cm']}–{$t['to_cm']} cm (" . money($t['price']) . ')', $tiers));
}

function tiers_error_text(string $typeName, int $width, array $tiers): string
{
    return $tiers
        ? t('{w} cm no es una medida válida para «{tipo}». Tramos posibles: {tramos}.', ['w' => $width, 'tipo' => $typeName, 'tramos' => tiers_text($tiers)])
        : t('«{tipo}» no tiene tramos de precio para este modelo: no se puede usar todavía.', ['tipo' => $typeName]);
}

/** Tramos en formato público (API/MCP): euros con 2 decimales y céntimos. */
function tiers_public(array $tiers): array
{
    return array_map(fn($t) => ['from_cm' => $t['from_cm'], 'to_cm' => $t['to_cm'], 'price' => eur($t['price']), 'price_cents' => $t['price']], $tiers);
}

/**
 * Ancho propuesto al añadir o sustituir un módulo: el que pide la persona si es válido; si no, el «hasta» del
 * tramo más cercano. Sin tramos, null.
 */
function ancho_sugerido(int $typeId, int $nodeId, ?int $preferred = null): ?int
{
    $tiers = tiers_effective($typeId, $nodeId)['tiers'];
    if (!$tiers) return null;
    $preferred ??= 60;
    $best = null;
    foreach ($tiers as $t) {
        if ($preferred >= $t['from_cm'] && $preferred <= $t['to_cm']) return $preferred;
        $cand = $preferred < $t['from_cm'] ? $t['from_cm'] : $t['to_cm'];
        if ($best === null || abs($cand - $preferred) < abs($best - $preferred)) $best = $cand;
    }
    return $best;
}

/**
 * Guarda un tramo (alta o edición) validando que no se solape con los de su grupo (tipo + nodo, o tipo general).
 * $price en céntimos.
 */
function tier_save(int $typeId, ?int $nodeId, mixed $from, mixed $to, int $price, ?int $id = null): int
{
    module_type_get($typeId) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $typeId]), [], 404);
    if ($nodeId !== null && !node_get($nodeId)) throw new UcError(t('El nodo {id} no existe.', ['id' => $nodeId]), [], 404);
    $f = parse_int($from, t('Desde (cm)'), 1, 10000);
    $tt = parse_int($to, t('Hasta (cm)'), 1, 10000);
    if ($f > $tt) throw new UcError(t('El tramo {f}–{t} no vale: «desde» ({f}) es mayor que «hasta» ({t}).', ['f' => $f, 't' => $tt]));
    if ($price < 0) throw new UcError(t('El precio no puede ser negativo.'));
    $others = rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND ' . ($nodeId === null ? 'node_id IS NULL' : 'node_id = ' . $nodeId) . ' AND id != ?', [$typeId, $id ?? 0]);
    foreach ($others as $o) {
        if ($f <= (int) $o['to_cm'] && $tt >= (int) $o['from_cm']) {
            throw new UcError(t('El tramo {f}–{t} cm se solapa con el tramo {of}–{ot} cm que ya existe. Cada medida tiene que caer en un solo tramo.', [
                'f' => $f, 't' => $tt, 'of' => $o['from_cm'], 'ot' => $o['to_cm']]));
        }
    }
    $d = ['module_type_id' => $typeId, 'node_id' => $nodeId, 'from_cm' => $f, 'to_cm' => $tt, 'price' => $price];
    if ($id === null) {
        $id = insert('price_tiers', $d);
    } else {
        if (!val('SELECT 1 FROM price_tiers WHERE id = ? AND module_type_id = ?', [$id, $typeId])) throw new UcError(t('El tramo {id} no es de este tipo.', ['id' => $id]), [], 404);
        update('price_tiers', $d, 'id = ?', [$id]);
    }
    audit('tier.save', module_type_get($typeId)['name'] . ($nodeId ? ' @ ' . node_path_text($nodeId) : '') . ": {$f}–{$tt} = " . money($price));
    return $id;
}

function tier_delete(int $typeId, int $id): void
{
    q('DELETE FROM price_tiers WHERE id = ? AND module_type_id = ?', [$id, $typeId]);
    audit('tier.delete', "tipo $typeId, tramo $id");
}

/**
 * Precio de una configuración (o de una lista de módulos) para un nodo final.
 *
 * @param list<array{id?:int, name?:string, items:list<array{id?:int, module_type_id:int, width_cm:int}>}> $walls
 * @return array{node_id:int, walls:list<array>, count:int, total:int, valid:bool, invalid_count:int, tax_rate:float, tax:int, total_with_tax:int}
 *   Cada línea: module_type_id, type_name, family, width_cm, valid, price (céntimos|null), tier_from_cm, tier_to_cm,
 *   tier_node_id, tiers (los posibles) y error (si no es válida).
 */
function precio_configuracion(int $nodeId, array $walls): array
{
    $types = [];
    $cacheTiers = [];
    $total = 0;
    $count = 0;
    $invalid = 0;
    $outWalls = [];
    foreach ($walls as $w) {
        $items = [];
        foreach ($w['items'] ?? [] as $it) {
            $typeId = (int) ($it['module_type_id'] ?? 0);
            $width = (int) ($it['width_cm'] ?? 0);
            $type = $types[$typeId] ??= module_type_get($typeId);
            $line = array_intersect_key($it, ['id' => 1]) + ['module_type_id' => $typeId, 'type_name' => $type['name'] ?? null,
                'family' => $type['family'] ?? null, 'width_cm' => $width, 'valid' => false, 'price' => null,
                'tier_from_cm' => null, 'tier_to_cm' => null, 'tier_node_id' => null, 'tiers' => []];
            $count++;
            if (!$type) {
                $line['error'] = t('El tipo de módulo {id} no existe.', ['id' => $typeId]);
            } else {
                $eff = $cacheTiers[$typeId] ??= tiers_effective($typeId, $nodeId);
                $line['tiers'] = $eff['tiers'];
                $line['tier_node_id'] = $eff['node_id'];
                foreach ($eff['tiers'] as $t) {
                    if ($width >= $t['from_cm'] && $width <= $t['to_cm']) {
                        $line = array_merge($line, ['valid' => true, 'price' => $t['price'], 'tier_from_cm' => $t['from_cm'], 'tier_to_cm' => $t['to_cm']]);
                        break;
                    }
                }
                if (!$line['valid']) $line['error'] = tiers_error_text($type['name'], $width, $eff['tiers']);
            }
            if ($line['valid']) $total += $line['price']; else $invalid++;
            $items[] = $line;
        }
        $outWalls[] = array_diff_key($w, ['items' => 1]) + ['items' => $items];
    }
    $rate = tax_rate();
    $tax = (int) round($total * $rate / 100);
    return ['node_id' => $nodeId, 'walls' => $outWalls, 'count' => $count, 'total' => $total, 'valid' => $invalid === 0 && $count > 0,
        'invalid_count' => $invalid, 'tax_rate' => $rate, 'tax' => $tax, 'total_with_tax' => $total + $tax];
}
