<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Ilustraciones del catálogo generadas por la app: SVG propio, sobrio, con el acabado como color. Se guardan en el nodo
 * como «ill:<tipo>:#RRGGBB» y se pintan en línea. Nunca llevan texto del usuario ni nada ejecutable: el SVG sale de
 * plantillas fijas de este fichero y el único dato variable es un color validado (#RRGGBB).
 */

const ILLUSTRATIONS = [
    'cocina-linea' => 'Cocina en línea', 'cocina-l' => 'Cocina en L', 'cocina-paralela' => 'Cocina en paralelo',
    'armario-corredera' => 'Armario de puertas correderas', 'armario-batiente' => 'Armario de puertas batientes', 'bano-lavabo' => 'Mueble de lavabo suspendido',
];

function illustration_valid(string $value): bool
{
    return (bool) preg_match('/^ill:([a-z-]+):#[0-9a-fA-F]{6}$/', $value, $m) && isset(ILLUSTRATIONS[$m[1]]);
}

/** «ill:tipo:#hex» → SVG; '' si no es válido. */
function illustration_from_value(string $value): string
{
    if (!illustration_valid($value)) return '';
    [, $kind, $hex] = explode(':', $value, 3);
    return illustration_svg($kind, $hex);
}

/** Color más oscuro o más claro (factor < 1 oscurece). */
function shade(string $hex, float $f): string
{
    $c = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $c = array_map(fn($v) => (int) max(0, min(255, $f < 1 ? $v * $f : $v + (255 - $v) * ($f - 1))), $c);
    return sprintf('#%02X%02X%02X', ...$c);
}

/** Luminancia relativa (0 negro … 1 blanco) de un #RRGGBB. */
function luminance(string $hex): float
{
    [$r, $g, $b] = array_map(fn($v) => hexdec($v) / 255, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function illustration_svg(string $kind, string $hex): string
{
    if (!isset(ILLUSTRATIONS[$kind]) || !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) return '';
    $hex = strtoupper($hex);
    $front = $hex;
    $edge = shade($hex, 0.72);
    $line = '#94A3B8';
    $ink = luminance($hex) < 0.32 ? '#E2E8F0' : '#334155'; // tiradores visibles también en acabados oscuros
    $wall = '#F1F5F9';
    $top = '#CBD5E1';
    $body = match ($kind) {
        'cocina-linea' => ill_kitchen_line($front, $edge, $ink, $top),
        'cocina-l' => ill_kitchen_plan('l', $front, $edge, $ink, $top),
        'cocina-paralela' => ill_kitchen_plan('paralela', $front, $edge, $ink, $top),
        'armario-corredera' => ill_wardrobe(true, $front, $edge, $ink),
        'armario-batiente' => ill_wardrobe(false, $front, $edge, $ink),
        'bano-lavabo' => ill_bathroom($front, $edge, $ink),
    };
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 150" role="img" aria-label="' . e(t(ILLUSTRATIONS[$kind])) . '" class="ill">'
        . '<rect width="240" height="150" rx="10" fill="' . $wall . '"/>'
        . '<line x1="14" y1="132" x2="226" y2="132" stroke="' . $line . '" stroke-width="1.5"/>' . $body . '</svg>';
}

/** Frente de cocina en línea: altos, encimera, bajos con fregadero y placa, columna. */
function ill_kitchen_line(string $f, string $e, string $ink, string $top): string
{
    $s = '';
    // columna alta
    $s .= "<rect x='22' y='22' width='34' height='106' rx='2' fill='$f' stroke='$e' stroke-width='1.5'/>";
    $s .= "<line x1='22' y1='76' x2='56' y2='76' stroke='$e' stroke-width='1.2'/><rect x='49' y='44' width='2.5' height='14' rx='1' fill='$ink'/><rect x='49' y='86' width='2.5' height='14' rx='1' fill='$ink'/>";
    // altos
    foreach ([60, 102, 144, 186] as $x) $s .= "<rect x='$x' y='22' width='40' height='34' rx='2' fill='$f' stroke='$e' stroke-width='1.5'/><rect x='" . ($x + 17) . "' y='48' width='6' height='2.5' rx='1' fill='$ink'/>";
    // encimera
    $s .= "<rect x='58' y='82' width='170' height='7' rx='1.5' fill='$top' stroke='#94A3B8' stroke-width='1'/>";
    // fregadero y placa
    $s .= "<rect x='76' y='79' width='30' height='4' rx='1.5' fill='#94A3B8'/><path d='M91 79 v-8 h6' fill='none' stroke='$ink' stroke-width='1.5' stroke-linecap='round'/>";
    $s .= "<rect x='160' y='80' width='38' height='3' rx='1' fill='$ink'/>";
    // bajos
    foreach ([60, 102, 144, 186] as $i => $x) {
        $s .= "<rect x='$x' y='89' width='40' height='37' rx='2' fill='$f' stroke='$e' stroke-width='1.5'/>";
        if ($i === 2) $s .= "<line x1='$x' y1='101' x2='" . ($x + 40) . "' y2='101' stroke='$e' stroke-width='1.2'/><line x1='$x' y1='113' x2='" . ($x + 40) . "' y2='113' stroke='$e' stroke-width='1.2'/>";
        $s .= "<rect x='" . ($x + 15) . "' y='93' width='10' height='2.5' rx='1' fill='$ink'/>";
    }
    $s .= "<rect x='58' y='126' width='170' height='6' fill='#E2E8F0'/>";
    return $s;
}

/** Planta de cocina en L o en paralelo: encimeras con el frente del acabado, fregadero y placa. */
function ill_kitchen_plan(string $layout, string $f, string $e, string $ink, string $top): string
{
    $run = function (float $x, float $y, float $w, float $h, string $frontSide) use ($f, $e, $top) {
        $s = "<rect x='$x' y='$y' width='$w' height='$h' fill='$top' stroke='#94A3B8' stroke-width='1.2'/>";
        $band = match ($frontSide) {
            'bottom' => "<rect x='$x' y='" . ($y + $h - 6) . "' width='$w' height='6' fill='$f' stroke='$e' stroke-width='1'/>",
            'top' => "<rect x='$x' y='$y' width='$w' height='6' fill='$f' stroke='$e' stroke-width='1'/>",
            'right' => "<rect x='" . ($x + $w - 6) . "' y='$y' width='6' height='$h' fill='$f' stroke='$e' stroke-width='1'/>",
        };
        return $s . $band;
    };
    $sink = fn($x, $y) => "<rect x='$x' y='$y' width='22' height='14' rx='3' fill='#F8FAFC' stroke='$ink' stroke-width='1.2'/><circle cx='" . ($x + 11) . "' cy='" . ($y + 7) . "' r='1.6' fill='$ink'/>";
    $hob = function ($x, $y) use ($ink) { $s = ''; foreach ([[0, 0], [12, 0], [0, 11], [12, 11]] as [$dx, $dy]) $s .= "<circle cx='" . ($x + 5 + $dx) . "' cy='" . ($y + 5 + $dy) . "' r='4' fill='none' stroke='$ink' stroke-width='1.2'/>"; return $s; };
    $s = "<rect x='16' y='14' width='208' height='112' rx='4' fill='none' stroke='#CBD5E1' stroke-width='1.2' stroke-dasharray='3 3'/>";
    if ($layout === 'l') {
        $s .= $run(16, 14, 208, 34, 'bottom') . $run(16, 48, 34, 78, 'right');
        $s .= $sink(96, 20) . $hob(160, 20);
        $s .= "<rect x='22' y='92' width='22' height='26' rx='2' fill='$f' stroke='$e' stroke-width='1'/>";
    } else {
        $s .= $run(16, 14, 208, 34, 'bottom') . $run(16, 92, 208, 34, 'top');
        $s .= $sink(70, 20) . $hob(150, 104);
        $s .= "<rect x='190' y='98' width='26' height='24' rx='2' fill='$f' stroke='$e' stroke-width='1'/>";
    }
    return $s;
}

/** Armario de suelo a techo: correderas (dos hojas solapadas con perfiles) o batientes (puertas con tirador). */
function ill_wardrobe(bool $sliding, string $f, string $e, string $ink): string
{
    $s = "<rect x='40' y='14' width='160' height='116' rx='2' fill='" . shade($f, 0.86) . "' stroke='$e' stroke-width='1.5'/>";
    if ($sliding) {
        $s .= "<rect x='44' y='18' width='82' height='108' rx='1.5' fill='$f' stroke='$e' stroke-width='1.5'/>";
        $s .= "<rect x='114' y='18' width='82' height='108' rx='1.5' fill='$f' stroke='$e' stroke-width='1.5'/>";
        $s .= "<line x1='40' y1='16' x2='200' y2='16' stroke='$ink' stroke-width='2'/><line x1='40' y1='128' x2='200' y2='128' stroke='$ink' stroke-width='2'/>";
        $s .= "<rect x='118' y='56' width='3' height='30' rx='1.5' fill='$ink'/><rect x='121' y='56' width='3' height='30' rx='1.5' fill='$ink' opacity='.35'/>";
    } else {
        foreach ([44, 84, 122, 162] as $i => $x) {
            $s .= "<rect x='$x' y='18' width='36' height='108' rx='1.5' fill='$f' stroke='$e' stroke-width='1.5'/>";
            $hx = $i % 2 === 0 ? $x + 30 : $x + 3;
            $s .= "<rect x='$hx' y='60' width='3' height='24' rx='1.5' fill='$ink'/>";
        }
    }
    return $s;
}

/** Mueble de lavabo suspendido con dos cajones, encimera con lavabo y espejo; columna al lado. */
function ill_bathroom(string $f, string $e, string $ink): string
{
    $s = "<rect x='64' y='14' width='78' height='46' rx='6' fill='#E2E8F0' stroke='#94A3B8' stroke-width='1.2'/>";
    $s .= "<path d='M73 22 l14 -0 M73 28 l8 0' stroke='#FFFFFF' stroke-width='2' stroke-linecap='round'/>";
    $s .= "<rect x='52' y='72' width='102' height='6' rx='1.5' fill='#F8FAFC' stroke='#94A3B8' stroke-width='1'/>";
    $s .= "<ellipse cx='103' cy='73' rx='20' ry='3.5' fill='#E2E8F0' stroke='#94A3B8' stroke-width='1'/><path d='M103 70 v-8 h5' fill='none' stroke='$ink' stroke-width='1.5' stroke-linecap='round'/>";
    $s .= "<rect x='52' y='78' width='102' height='40' rx='2' fill='$f' stroke='$e' stroke-width='1.5'/><line x1='52' y1='98' x2='154' y2='98' stroke='$e' stroke-width='1.2'/>";
    $s .= "<rect x='93' y='84' width='20' height='2.5' rx='1' fill='$ink'/><rect x='93' y='104' width='20' height='2.5' rx='1' fill='$ink'/>";
    $s .= "<rect x='166' y='14' width='34' height='104' rx='2' fill='$f' stroke='$e' stroke-width='1.5'/><rect x='192' y='56' width='2.5' height='18' rx='1' fill='$ink'/>";
    return $s;
}

// ---------------------------------------------------------------- iconos de módulo

/**
 * Tipo de icono de un tipo de módulo, por su familia y su nombre (sin campo nuevo en el catálogo: el fabricante no
 * tiene que elegir nada). Lo desconocido cae en un bajo de una puerta.
 */
function module_icon_kind(array $type): string
{
    $n = mb_strtolower((string) ($type['name'] ?? ''));
    $f = mb_strtolower((string) ($type['family'] ?? ''));
    $two = str_contains($n, '2 puertas') || str_contains($n, 'dos puertas');
    return match (true) {
        str_contains($n, 'fregadero') => 'sink',
        str_contains($n, 'lavavajillas') => 'dishwasher',
        str_contains($n, 'horno') => 'oven',
        str_contains($n, 'frigor') || str_contains($n, 'nevera') => 'fridge',
        str_contains($n, 'lavabo') => 'basin',
        str_contains($n, 'corredera') => 'sliding',
        str_contains($n, 'esquina') || str_contains($n, 'rincón') => 'corner',
        str_contains($n, 'cajon') => $f === 'interior' ? 'inner-drawers' : 'drawers',
        $f === 'alto' => $two ? 'wall2' : 'wall1',
        $f === 'armario' => $two ? 'wardrobe2' : 'wardrobe1',
        $f === 'columna', $f === 'baño' && str_contains($n, 'columna') => 'tall',
        default => $two ? 'base2' : 'base1',
    };
}

/** Icono SVG (48×48, trazo con currentColor) de un tipo de módulo. Plantillas fijas: nada del usuario dentro. */
function module_icon_svg(array $type): string
{
    $k = module_icon_kind($type);
    $box = fn($x, $y, $w, $h) => "<rect x='$x' y='$y' width='$w' height='$h' rx='2'/>";
    $line = fn($x1, $y1, $x2, $y2) => "<path d='M{$x1} {$y1}L{$x2} {$y2}'/>";
    $knob = fn($x, $y, $h = 6) => "<path d='M$x $y v$h' stroke-width='2.4'/>";
    $body = match ($k) {
        'base1' => $box(12, 14, 24, 26) . $line(12, 18, 36, 18) . $knob(31, 23),
        'base2' => $box(8, 14, 32, 26) . $line(8, 18, 40, 18) . $line(24, 18, 24, 40) . $knob(21, 24) . $knob(27, 24),
        'drawers' => $box(10, 14, 28, 26) . $line(10, 22, 38, 22) . $line(10, 31, 38, 31) . "<path d='M20 18h8M20 26.5h8M20 35.5h8' stroke-width='2.4'/>",
        'sink' => $box(8, 18, 32, 22) . "<path d='M6 18h36'/><path d='M18 18v-3a4 4 0 0 1 8 0' />" . $line(24, 18, 24, 40) . $knob(21, 26) . $knob(27, 26),
        'dishwasher' => $box(10, 14, 28, 26) . "<path d='M10 20h28'/><circle cx='15' cy='17' r='1' fill='currentColor'/><path d='M19 24h10' stroke-width='2.4'/>",
        'oven' => $box(12, 4, 24, 40) . "<rect x='15' y='17' width='18' height='13' rx='1.5'/>" . $line(12, 33, 36, 33) . "<path d='M17 14h14' stroke-width='2.4'/>",
        'fridge' => $box(12, 4, 24, 40) . $line(12, 26, 36, 26) . $knob(31, 12, 8) . $knob(31, 30, 8),
        'tall' => $box(15, 4, 18, 40) . $line(15, 18, 33, 18) . $knob(29, 24, 7),
        'wall1' => $box(12, 8, 24, 22) . $knob(31, 21) . "<path d='M8 36h32' stroke-dasharray='2 3'/>",
        'wall2' => $box(8, 8, 32, 22) . $line(24, 8, 24, 30) . $knob(21, 21) . $knob(27, 21) . "<path d='M8 36h32' stroke-dasharray='2 3'/>",
        'wardrobe1' => $box(14, 4, 20, 40) . $knob(29, 20, 8),
        'wardrobe2' => $box(8, 4, 32, 40) . $line(24, 4, 24, 44) . $knob(21, 20, 8) . $knob(27, 20, 8),
        'sliding' => $box(6, 4, 36, 40) . "<rect x='9' y='7' width='17' height='34' rx='1'/><rect x='22' y='7' width='17' height='34' rx='1' fill='currentColor' fill-opacity='.12'/><path d='M14 24h4M30 24h4'/>",
        'corner' => "<path d='M8 44V10h18v34M26 10l14-6v34l-14 6'/>" . $knob(21, 22, 8),
        'inner-drawers' => "<rect x='6' y='6' width='36' height='36' rx='2' stroke-dasharray='3 3'/>" . $box(11, 20, 26, 20) . $line(11, 30, 37, 30) . "<path d='M20 25h8M20 35h8' stroke-width='2.4'/>",
        'basin' => "<path d='M6 18h36'/><path d='M16 18a8 4 0 0 0 16 0'/><path d='M24 14v-4h4'/>" . $box(9, 22, 30, 16) . $line(9, 30, 39, 30) . "<path d='M20 26h8M20 34h8' stroke-width='2.4'/>",
    };
    return '<svg class="mod-ico" viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
