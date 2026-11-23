<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Intérprete de fórmulas del despiece: números, W H D T B, + - * / y paréntesis. Sin eval: casos buenos y malos.
//   php tests/expr.php

require __DIR__ . '/_helper.php';

$v = ['W' => 585, 'H' => 720, 'D' => 560, 'T' => 19, 'B' => 8];
$ok = fn(string $src, float $expected) => check("«{$src}» = {$expected}", fn() => eq($expected, expr_value($src, $v)));
$bad = fn(string $src, string $contains) => check_error("rechaza «" . mb_substr($src, 0, 40) . "»", fn() => expr_value($src, $v), $contains);

// --- buenos
$ok('W - 2*T', 547.0);
$ok('W-2*T', 547.0);
$ok('(H - 4*3) / 3', 236.0);
$ok('H', 720.0);
$ok('19', 19.0);
$ok('-T + 40', 21.0);
$ok('D - 60', 500.0);
$ok('(W - 7) / 2', 289.0);
$ok('2 * (W - 2*T) - (D - 40) / 4', 964.0);
$ok('W - 4*T - 26', 483.0);
$ok('W*0.5', 292.5);
$ok('W*0,5', 292.5);
$ok('  ( ( W ) )  ', 585.0);
$ok('10 - 2 - 3', 5.0);
$ok('100 / 10 / 2', 5.0);
$ok('2 + 3 * 4', 14.0);
$ok('--T', 19.0);

// --- malos: sintaxis, variables, código, límites
$bad('', 'vacía');
$bad('W -', 'termina');
$bad('W * * 2', 'inesperado');
$bad('(W - 2', 'paréntesis');
$bad('W - 2)', 'paréntesis');
$bad(')(', 'inesperado');
$bad('W2', 'desconocida');
$bad('w - 2*T', 'desconocida');
$bad('ancho - 2', 'desconocida');
$bad('X + 1', 'desconocida');
$bad('system("ls")', 'desconocida');
$bad('exec(W)', 'desconocida');
$bad('`id`', 'Carácter');
$bad('W; phpinfo()', 'Carácter');
$bad('$W', 'Carácter');
$bad('W ** 2', 'inesperado');
$bad('W ^ 2', 'Carácter');
$bad('W % 2', 'Carácter');
$bad('1e5', 'desconocida');
$bad('0x10', 'desconocida');
$bad('12.5.3', 'Número');
$bad('99999999999', 'demasiado grande');
$bad(str_repeat('(', 30) . 'W' . str_repeat(')', 30), 'anidad');
$bad(str_repeat('W+', 120) . 'W', 'larga');
$bad('W / 0', 'cero');
$bad('W / (T - 19)', 'cero');
$bad('W / (D - 560)', 'cero');
$bad("W\x00", 'Carácter');
check('la variable que no se pasa al evaluar es un error', function () {
    try { expr_value('W + H', ['W' => 1]); } catch (UcError $e) { return eq(true, str_contains($e->getMessage(), 'H')); }
    throw new RuntimeException('no dio error');
});
check('no hay eval ni ejecución de código en el intérprete', function () {
    $src = file_get_contents(dirname(__DIR__) . '/app/expr.php');
    return eq(0, preg_match('/\b(eval|assert|create_function|call_user_func|exec|system|passthru|shell_exec|popen|proc_open)\s*\(/', $src));
});
check('el resultado no depende de espacios ni de la coma decimal', fn() => eq(expr_value('(W-7)/2', $v), expr_value(' ( W - 7 ) / 2 ', $v)));

done();
