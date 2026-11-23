<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Motor de precios. UNA verdad, en el servidor:
 *  - Precio de un módulo = el del tramo cuyo [desde_cm, hasta_cm] (ambos incluidos) contiene su ancho. No se cobra por cm.
 *  - Si el ancho cae en un HUECO entre dos tramos, se cobra el TRAMO DE ARRIBA (el primero cuyo «desde» es mayor que el
 *    ancho), nunca uno inferior: tier_rule = 'next_up' y una nota en llano. Lo que queda por debajo del primer tramo o
 *    por encima del último, o fuera del mínimo/máximo del tipo, NO es válido (sin precio y con los tramos posibles).
 *  - Tramos por nodo: el antepasado más cercano (empezando por el propio nodo) que tenga tramos de ese tipo
 *    SUSTITUYE a los generales del tipo; si ninguno tiene, valen los generales.
 *  - Total = suma de líneas válidas, en céntimos, sin IVA. El IVA (ajustes) solo se muestra aparte.
 * precio_configuracion() la usan la tablet (vía /api), el panel y el MCP. Nunca se calcula en JavaScript.
 */

/** Fila de price_tiers → tramo interno (mm enteros, céntimos) con sus cm para mostrar. */
function tier_row(array $t): array
{
    $f = (int) $t['from_mm'];
    $to = (int) $t['to_mm'];
    return ['id' => (int) $t['id'], 'from_mm' => $f, 'to_mm' => $to, 'from_cm' => mm_to_cm($f), 'to_cm' => mm_to_cm($to), 'price' => (int) $t['price']];
}

/**
 * Tramos que valen para un tipo en un nodo (medidas en mm).
 * @return array{node_id:?int, tiers:list<array{id:int,from_mm:int,to_mm:int,from_cm:int|float,to_cm:int|float,price:int}>}
 */
function tiers_effective(int $typeId, int $nodeId): array
{
    foreach (node_lineage($nodeId) as $nid) {
        $rs = rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id = ? ORDER BY from_mm', [$typeId, $nid]);
        if ($rs) return ['node_id' => $nid, 'tiers' => array_map('tier_row', $rs)];
    }
    return ['node_id' => null, 'tiers' => array_map('tier_row', rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NULL ORDER BY from_mm', [$typeId]))];
}

/**
 * El tramo que se cobra para un ancho (mm): el que lo contiene ('exact') o, si cae en un hueco entre dos tramos, el de
 * arriba ('next_up'). Por debajo del primero o por encima del último: null. Única regla; la usan todos.
 * @return array{tier:array, rule:string}|null
 */
function tier_match(array $tiers, int $widthMm): ?array
{
    usort($tiers, fn($a, $b) => $a['from_mm'] <=> $b['from_mm']);
    $below = false;
    foreach ($tiers as $t) {
        if ($widthMm >= $t['from_mm'] && $widthMm <= $t['to_mm']) return ['tier' => $t, 'rule' => 'exact'];
        if ($t['from_mm'] > $widthMm) return $below ? ['tier' => $t, 'rule' => 'next_up'] : null;
        $below = true; // hay un tramo entero por debajo del ancho
    }
    return null;
}

/** Nota en llano cuando se cobra el tramo de arriba; null si el ancho cae dentro de su tramo. */
function tier_note(int $widthMm, ?array $tier, ?string $rule): ?string
{
    if ($rule !== 'next_up' || !$tier) return null;
    return t('{w} cm está entre tramos: se cobra como {f}–{t} cm.', ['w' => cm_text($widthMm), 'f' => cm_text($tier['from_mm']), 't' => cm_text($tier['to_mm'])]);
}

/** @return array{tier:?array, rule:?string, tiers:list, node_id:?int} — $widthMm en mm */
function tier_lookup(int $typeId, int $nodeId, int $widthMm): array
{
    $eff = tiers_effective($typeId, $nodeId);
    $m = tier_match($eff['tiers'], $widthMm);
    return ['tier' => $m['tier'] ?? null, 'rule' => $m['rule'] ?? null, 'tiers' => $eff['tiers'], 'node_id' => $eff['node_id']];
}

function tiers_text(array $tiers): string
{
    return implode(', ', array_map(fn($t) => cm_text($t['from_mm']) . '–' . cm_text($t['to_mm']) . ' cm (' . money($t['price']) . ')', $tiers));
}

function tiers_error_text(string $typeName, int $widthMm, array $tiers): string
{
    return $tiers
        ? t('{w} cm no es una medida válida para «{tipo}». Tramos posibles: {tramos}.', ['w' => cm_text($widthMm), 'tipo' => $typeName, 'tramos' => tiers_text($tiers)])
        : t('«{tipo}» no tiene tramos de precio para este modelo: no se puede usar todavía.', ['tipo' => $typeName]);
}

/** Si el tipo tiene ancho mínimo/máximo y $widthMm queda fuera, el motivo; si no, null. */
function width_range_error(array $type, int $widthMm): ?string
{
    $min = $type['min_width_mm'] !== null ? (int) $type['min_width_mm'] : null;
    $max = $type['max_width_mm'] !== null ? (int) $type['max_width_mm'] : null;
    if (($min !== null && $widthMm < $min) || ($max !== null && $widthMm > $max)) {
        return t('{w} cm está fuera del ancho de «{tipo}»: entre {min} y {max} cm.', ['w' => cm_text($widthMm), 'tipo' => $type['name'],
            'min' => $min !== null ? cm_text($min) : '—', 'max' => $max !== null ? cm_text($max) : '—']);
    }
    return null;
}

/** Tramos en formato público (API/MCP): cm y mm, euros con 2 decimales, céntimos y texto. */
function tiers_public(array $tiers): array
{
    return array_map(fn($t) => ['from_cm' => $t['from_cm'], 'to_cm' => $t['to_cm'], 'from_mm' => $t['from_mm'], 'to_mm' => $t['to_mm'],
        'price' => eur($t['price']), 'price_cents' => $t['price'], 'price_text' => money($t['price'])], $tiers);
}

/**
 * Ancho propuesto (mm) al añadir o sustituir un módulo: el preferido si es válido; si no, el borde del tramo más
 * cercano. Sin preferencia, el ancho por defecto del tipo. Sin tramos, null.
 */
function ancho_sugerido(int $typeId, int $nodeId, ?int $preferredMm = null): ?int
{
    $tiers = tiers_effective($typeId, $nodeId)['tiers'];
    if (!$tiers) return null;
    $preferredMm ??= (int) (module_type_get($typeId)['width_mm'] ?? 600);
    $best = null;
    foreach ($tiers as $t) {
        if ($preferredMm >= $t['from_mm'] && $preferredMm <= $t['to_mm']) return $preferredMm;
        $cand = $preferredMm < $t['from_mm'] ? $t['from_mm'] : $t['to_mm'];
        if ($best === null || abs($cand - $preferredMm) < abs($best - $preferredMm)) $best = $cand;
    }
    return $best;
}

/**
 * Guarda un tramo (alta o edición) validando que no se solape con los de su grupo (tipo + nodo, o tipo general).
 * $from y $to en cm con un decimal como mucho (40 o 60,1); se guardan en mm. $price en céntimos.
 */
function tier_save(int $typeId, ?int $nodeId, mixed $from, mixed $to, int $price, ?int $id = null): int
{
    module_type_get($typeId) ?? throw new UcError(t('El tipo de módulo {id} no existe.', ['id' => $typeId]), [], 404);
    if ($nodeId !== null && !node_get($nodeId)) throw new UcError(t('El nodo {id} no existe.', ['id' => $nodeId]), [], 404);
    $f = parse_width_mm($from, t('Desde (cm)'));
    $tt = parse_width_mm($to, t('Hasta (cm)'));
    if ($f > $tt) throw new UcError(t('El tramo {f}–{t} no vale: «desde» ({f}) es mayor que «hasta» ({t}).', ['f' => cm_text($f), 't' => cm_text($tt)]));
    if ($price < 0) throw new UcError(t('El precio no puede ser negativo.'));
    $others = rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND ' . ($nodeId === null ? 'node_id IS NULL' : 'node_id = ' . $nodeId) . ' AND id != ?', [$typeId, $id ?? 0]);
    foreach ($others as $o) {
        if ($f <= (int) $o['to_mm'] && $tt >= (int) $o['from_mm']) {
            throw new UcError(t('El tramo {f}–{t} cm se solapa con el tramo {of}–{ot} cm que ya existe. Cada medida tiene que caer en un solo tramo.', [
                'f' => cm_text($f), 't' => cm_text($tt), 'of' => cm_text((int) $o['from_mm']), 'ot' => cm_text((int) $o['to_mm'])]));
        }
    }
    $d = ['module_type_id' => $typeId, 'node_id' => $nodeId, 'from_mm' => $f, 'to_mm' => $tt, 'price' => $price];
    if ($id === null) {
        $id = insert('price_tiers', $d);
    } else {
        if (!val('SELECT 1 FROM price_tiers WHERE id = ? AND module_type_id = ?', [$id, $typeId])) throw new UcError(t('El tramo {id} no es de este tipo.', ['id' => $id]), [], 404);
        update('price_tiers', $d, 'id = ?', [$id]);
    }
    audit('tier.save', module_type_get($typeId)['name'] . ($nodeId ? ' @ ' . node_path_text($nodeId) : '') . ': ' . cm_text($f) . '–' . cm_text($tt) . ' cm = ' . money($price));
    return $id;
}

/**
 * Huecos entre tramos de un grupo (p. ej. entre 40 y 41 cm: 40,5 no tiene precio). Para avisar en el panel.
 * @return list<array{from_mm:int,to_mm:int}>
 */
function tier_gaps(array $tiers): array
{
    $gaps = [];
    usort($tiers, fn($a, $b) => $a['from_mm'] <=> $b['from_mm']);
    for ($i = 1; $i < count($tiers); $i++) {
        if ($tiers[$i]['from_mm'] > $tiers[$i - 1]['to_mm'] + 1) $gaps[] = ['from_mm' => $tiers[$i - 1]['to_mm'], 'to_mm' => $tiers[$i]['from_mm']];
    }
    return $gaps;
}

function tier_delete(int $typeId, int $id): void
{
    q('DELETE FROM price_tiers WHERE id = ? AND module_type_id = ?', [$id, $typeId]);
    audit('tier.delete', "tipo $typeId, tramo $id");
}

/**
 * Precio de una configuración (o de una lista de módulos) para un nodo final.
 *
 * @param list<array{id?:int, name?:string, items:list<array{id?:int, module_type_id:int, width_mm?:int, width_cm?:int|float}>}> $walls
 * @return array{node_id:int, walls:list<array>, count:int, total:int, valid:bool, invalid_count:int, tax_rate:float, tax:int, total_with_tax:int}
 *   Cada línea: module_type_id, type_name, family, width_mm, width_cm, valid, price (céntimos|null), tier_from_mm/_cm,
 *   tier_to_mm/_cm, tier_node_id, tiers (los posibles) y error (si no es válida). Un ancho fuera del mínimo/máximo
 *   del tipo tampoco es válido.
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
            $width = isset($it['width_mm']) ? (int) $it['width_mm'] : (int) round(((float) ($it['width_cm'] ?? 0)) * 10);
            $type = $types[$typeId] ??= module_type_get($typeId);
            $line = array_intersect_key($it, ['id' => 1, 'height_mm' => 1, 'depth_mm' => 1]) + ['module_type_id' => $typeId, 'type_name' => $type['name'] ?? null,
                'family' => $type['family'] ?? null, 'width_mm' => $width, 'width_cm' => mm_to_cm($width), 'valid' => false, 'price' => null,
                'tier_from_mm' => null, 'tier_to_mm' => null, 'tier_from_cm' => null, 'tier_to_cm' => null, 'tier_rule' => null, 'tier_note' => null,
                'tier_node_id' => null, 'tiers' => []];
            $count++;
            if (!$type) {
                $line['error'] = t('El tipo de módulo {id} no existe.', ['id' => $typeId]);
            } else {
                $eff = $cacheTiers[$typeId] ??= tiers_effective($typeId, $nodeId);
                $line['tiers'] = $eff['tiers'];
                $line['tier_node_id'] = $eff['node_id'];
                $range = width_range_error($type, $width);
                $m = $range === null ? tier_match($eff['tiers'], $width) : null;
                if ($m) {
                    $t = $m['tier'];
                    $line = array_merge($line, ['valid' => true, 'price' => $t['price'], 'tier_from_mm' => $t['from_mm'], 'tier_to_mm' => $t['to_mm'],
                        'tier_from_cm' => $t['from_cm'], 'tier_to_cm' => $t['to_cm'], 'tier_rule' => $m['rule'], 'tier_note' => tier_note($width, $t, $m['rule'])]);
                }
                if (!$line['valid']) $line['error'] = $range ?? tiers_error_text($type['name'], $width, $eff['tiers']);
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
