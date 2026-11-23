<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Motor de precios: bordes de tramo, medida sin tramo, solapes, tramos por nodo, total e IVA.
//   php tests/pricing.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$walls = fn(array $items) => [['name' => 'P1', 'items' => array_map(fn($i) => ['module_type_id' => $i[0], 'width_cm' => $i[1]], $items)]];
$line = fn(array $r, int $n = 0) => $r['walls'][0]['items'][$n];

// --- bordes: [desde, hasta] ambos incluidos
foreach ([[30, 8000, 30, 40], [40, 8000, 30, 40], [41, 10000, 41, 60], [60, 10000, 41, 60]] as [$w, $price, $from, $to]) {
    check("ancho {$w} cm cae en el tramo {$from}–{$to}", function () use ($c, $walls, $line, $w, $price, $from, $to) {
        $l = $line(precio_configuracion($c['blanco'], $walls([[$c['bajo'], $w]])));
        return eq(true, $l['valid']) && eq($price, $l['price']) && eq($from, $l['tier_from_cm']) && eq($to, $l['tier_to_cm']);
    });
}

// --- sin tramo: la línea no es válida, no tiene precio y dice qué tramos hay
foreach ([29, 61, 200] as $w) {
    check("ancho $w cm no tiene tramo: no válido y con los tramos posibles", function () use ($c, $walls, $line, $w) {
        $r = precio_configuracion($c['blanco'], $walls([[$c['bajo'], $w], [$c['bajo'], 40]]));
        $l = $line($r);
        return eq(false, $l['valid']) && eq(null, $l['price']) && eq(false, $r['valid']) && eq(1, $r['invalid_count'])
            && eq(8000, $r['total'], 'el total solo suma las líneas válidas')
            && eq([[30, 40], [41, 60]], array_map(fn($t) => [$t['from_cm'], $t['to_cm']], $l['tiers']))
            && eq(true, str_contains($l['error'], '30–40') && str_contains($l['error'], '41–60'), 'el error nombra los tramos');
    });
}

check('tipo de módulo inexistente: línea no válida', function () use ($c, $walls, $line) {
    $l = $line(precio_configuracion($c['blanco'], $walls([[99999, 40]])));
    return eq(false, $l['valid']) && eq(true, str_contains($l['error'], '99999'));
});

// --- total e IVA (solo se muestra aparte)
check('total = suma de líneas; IVA 21 % aparte', function () use ($c, $walls) {
    $r = precio_configuracion($c['blanco'], $walls([[$c['bajo'], 40], [$c['bajo'], 60], [$c['alto'], 45]]));
    return eq(23000, $r['total']) && eq(21.0, $r['tax_rate']) && eq(4830, $r['tax']) && eq(27830, $r['total_with_tax']) && eq(3, $r['count']) && eq(true, $r['valid']);
});
check('IVA de los ajustes', function () use ($c, $walls) {
    settings_save(['iva' => '10']);
    $r = precio_configuracion($c['blanco'], $walls([[$c['bajo'], 40]]));
    settings_save(['iva' => '21']);
    return eq(10.0, $r['tax_rate']) && eq(800, $r['tax']);
});

// --- solapes (se validan al guardar, dentro de cada grupo tipo+nodo)
check_error('tramo que solapa con 41–60 se rechaza', fn() => tier_save($c['bajo'], null, 55, 70, 12000), 'solapa');
check_error('tramo que contiene a otro se rechaza', fn() => tier_save($c['bajo'], null, 20, 80, 12000), 'solapa');
check_error('desde mayor que hasta se rechaza', fn() => tier_save($c['bajo'], null, 70, 65, 12000), '70');
check_error('desde 0 se rechaza', fn() => tier_save($c['alto'], null, 0, 20, 100));
check('tramo contiguo 61–80 sí vale', fn() => tier_save($c['bajo'], null, 61, 80, 12500) > 0);
check('editar un tramo no choca consigo mismo', function () use ($c) {
    $id = (int) val('SELECT id FROM price_tiers WHERE module_type_id = ? AND node_id IS NULL AND from_cm = 61', [$c['bajo']]);
    tier_save($c['bajo'], null, 61, 85, 12500, $id);
    return eq(85, (int) val('SELECT to_cm FROM price_tiers WHERE id = ?', [$id]));
});
check('el mismo rango en otro nodo no es solape', fn() => tier_save($c['bajo'], $c['duo'], 41, 60, 11000) > 0);
check_error('solape dentro del grupo del nodo se rechaza', fn() => tier_save($c['bajo'], $c['duo'], 50, 55, 11000), 'solapa');

// --- tramos por nodo: el antepasado más cercano con tramos de ese tipo sustituye a los generales
check('Roble (hijo de Dúo) usa los tramos de Dúo', function () use ($c, $walls, $line) {
    $l = $line(precio_configuracion($c['roble'], $walls([[$c['bajo'], 50]])));
    return eq(11000, $l['price']) && eq($c['duo'], $l['tier_node_id']);
});
check('los tramos del nodo SUSTITUYEN a los generales (30 cm no vale en Dúo)', function () use ($c, $walls, $line) {
    $l = $line(precio_configuracion($c['roble'], $walls([[$c['bajo'], 30]])));
    return eq(false, $l['valid']) && eq([[41, 60]], array_map(fn($t) => [$t['from_cm'], $t['to_cm']], $l['tiers']));
});
check('Blanco (otro modelo) sigue con los generales', function () use ($c, $walls, $line) {
    $l = $line(precio_configuracion($c['blanco'], $walls([[$c['bajo'], 50]])));
    return eq(10000, $l['price']) && eq(null, $l['tier_node_id']);
});
check('tramo en el nodo final pisa al del modelo', function () use ($c, $walls, $line) {
    tier_save($c['bajo'], $c['roble'], 41, 60, 11500);
    $l = $line(precio_configuracion($c['roble'], $walls([[$c['bajo'], 50]])));
    return eq(11500, $l['price']) && eq($c['roble'], $l['tier_node_id']);
});
check('tipo sin tramos en el nodo usa los generales (Alto en Roble)', function () use ($c, $walls, $line) {
    return eq(5000, $line(precio_configuracion($c['roble'], $walls([[$c['alto'], 45]])))['price']);
});

check('una sola función de precio: la API, el panel y el MCP llaman a precio_configuracion()', function () {
    $src = '';
    foreach (glob(dirname(__DIR__) . '/app/*.php') as $f) if (basename($f) !== 'pricing.php') $src .= file_get_contents($f);
    // Nadie más busca tramos por su cuenta
    return eq(0, preg_match_all('/FROM\s+price_tiers\s+WHERE[^;]*(from_cm\s*<=|BETWEEN)/i', $src), 'búsqueda de tramo fuera de pricing.php')
        && eq(0, preg_match('/price\s*\*|priceFor|tramo.*js/i', (string) @file_get_contents(dirname(__DIR__) . '/public/assets/app.js')), 'cálculo de precio en JavaScript');
});

done();
