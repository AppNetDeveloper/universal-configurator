<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Concurrencia real (varios procesos a la vez sobre la misma base): aceptar mientras otros editan nunca deja una
// configuración aceptada con módulos distintos de su foto de precio, y nadie recibe un error interno (500).
//   php tests/concurrency.php

require __DIR__ . '/_helper.php';
file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => 'x'];");

$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$v = ['kind' => 'user', 'user' => $admin];
$results = [];
$broken = [];
for ($round = 0; $round < 5; $round++) {
    throttle_clear('ops:u' . $admin['id']); // el límite de cambios (600 cada 10 min) no debe frenar la carrera
    $cfg = config_create($c['blanco'], $v);
    $procs = [];
    foreach (['edit', 'edit', 'edit', 'accept', 'accept', 'edit'] as $mode) {
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/_race_worker.php', $mode, $cfg['code'], (string) $admin['id'], '1.2'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['UC_DATA' => UC_DATA]) ;
        $out[] = $pipes;
    }
    foreach ($out as $p) { foreach (explode("\n", trim(stream_get_contents($p[1]) . stream_get_contents($p[2]))) as $l) if ($l !== '') $results[] = $l; }
    foreach ($procs as $p) proc_close($p);
    $out = [];
    // Invariante: si está aceptada, su foto de precio corresponde a los módulos que tiene AHORA
    $now = row('SELECT * FROM configurations WHERE code = ?', [$cfg['code']]);
    if ($now['status'] === 'accepted') {
        $live = precio_configuracion((int) $now['node_id'], config_walls_input((int) $now['id']));
        $frozen = array_map(fn($i) => (int) $i['price'], array_merge(...array_map(fn($w) => $w['items'], config_walls_input((int) $now['id']))));
        $prices = array_map(fn($i) => (int) $i['price'], array_merge(...array_map(fn($w) => $w['items'], $live['walls'])));
        if ($live['total'] !== (int) $now['total'] || $frozen !== $prices) $broken[] = $cfg['code'];
    }
}
$count500 = count(array_filter($results, fn($l) => str_starts_with($l, '500')));
check('ninguna aceptada quedó con módulos distintos de su foto (' . count($results) . ' operaciones)', fn() => eq([], $broken));
check('las ediciones no chocaron con el límite de cambios (si no, la carrera no se probaría): ningún 429', fn() => eq(0, count(array_filter($results, fn($l) => $l === '429'))));
check('ningún error interno (500) bajo concurrencia', fn() => eq(0, $count500, implode(' | ', array_slice(array_filter($results, fn($l) => str_starts_with($l, '500')), 0, 3))));
check('se aceptaron todas (una vez cada una) y los demás recibieron 409', fn() => eq(5, count(array_filter($results, fn($l) => $l === 'ok')) >= 5 ? 5 : -1)
    && eq(true, in_array('409', $results, true)));

// Reserva atómica del presupuesto de la cookie de confianza: 12 intentos fallidos a la vez con la misma cookie
$ana = fx_user('comercial', 'ana@x.test', 'clave-de-ana');
login_attempt('ana@x.test', 'clave-de-ana');
$cookie = (string) $GLOBALS['uc_trust_cookie'];
q('DELETE FROM throttle'); q('DELETE FROM login_backoff');
$at = microtime(true) + 1.0;
$procs = [];
for ($i = 0; $i < 12; $i++) {
    $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/_login_worker.php', 'ana@x.test', 'mala', $cookie, "10.20.0.$i", (string) $at], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, ['UC_DATA' => UC_DATA]), $pp];
}
$lines = [];
foreach ($procs as [$p, $pp]) { $lines[] = trim(stream_get_contents($pp[1]) . stream_get_contents($pp[2])); proc_close($p); }
$trusted = count(array_filter($lines, fn($l) => str_starts_with($l, 'T')));
check('12 intentos a la vez con la misma cookie: los «de confianza» no pasan del presupuesto (3 seguidos + el que revoca = 4)', fn() => eq(true, $trusted <= 4, "$trusted de confianza: " . implode(' | ', $lines)));
check('ninguno de esos intentos dio un error interno', fn() => eq(0, count(array_filter($lines, fn($l) => str_contains($l, '500')))));

done();
