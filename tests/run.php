<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Lanza todas las pruebas (cada una en su propio proceso) y sale con código ≠ 0 si alguna falla.
//   php tests/run.php            · php tests/run.php -v   (muestra cada línea)

$verbose = in_array('-v', $argv, true);
$files = array_filter(glob(__DIR__ . '/*.php'), fn($f) => basename($f) !== 'run.php' && !str_starts_with(basename($f), '_'));
sort($files);
$failed = [];
$totalTests = 0;
$totalFails = 0;
foreach ($files as $f) {
    $t0 = microtime(true);
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    $n = count(preg_grep('/^(ok  |FAIL)/', $out));
    $nf = count(preg_grep('/^FAIL/', $out));
    $totalTests += $n;
    $totalFails += $nf;
    printf("%-28s %3d pruebas  %s  (%.1f s)\n", basename($f), $n, $code === 0 ? 'ok' : "FALLA ($nf)", microtime(true) - $t0);
    if ($code !== 0 || $verbose) {
        foreach ($out as $l) if ($verbose || !str_starts_with($l, 'ok  ')) echo '    ', $l, "\n";
        if ($code !== 0) $failed[] = basename($f);
    }
}
echo "\nTotal: $totalTests pruebas en " . count($files) . " ficheros, $totalFails fallos\n";
echo $failed ? 'FALLAN: ' . implode(', ', $failed) . "\n" : "Todo bien\n";
exit($failed ? 1 : 0);
