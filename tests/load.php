<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Prueba de carga real (revisión del 23-nov, punto A): un lote enorme de operaciones se rechaza al momento, sin
// bloquear la base, y el login sigue respondiendo mientras se aplican lotes válidos.
//   php tests/load.php

$root = dirname(__DIR__);
$data = sys_get_temp_dir() . '/uc-load-' . getmypid();
@mkdir($data, 0700, true);
$port = random_int(40001, 50000);
$env = ['UC_DATA' => $data, 'PHP_CLI_SERVER_WORKERS' => '6', 'PATH' => getenv('PATH') ?: '/usr/bin'];
putenv("UC_DATA=$data");
require $root . '/app/bootstrap.php';
require __DIR__ . '/_checks.php';
// Instalación directa (sin el instalador web) con datos de ejemplo
uc_install(['empresa' => 'Carga', 'admin_email' => 'admin@x.test', 'admin_password' => 'secreta123', 'url' => "http://127.0.0.1:$port", 'sample' => true]);
settings_save(['permitir_invitado' => '1']);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"], [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($proc, $data) { proc_terminate($proc); foreach (scandir($data) ?: [] as $f) if (is_file("$data/$f")) @unlink("$data/$f"); @rmdir($data); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$B = "http://127.0.0.1:$port";

function http(string $method, string $url, ?string $body, array $headers, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $t = microtime(true);
    $raw = (string) curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $st, 'body' => substr($raw, $hs), 'ms' => $ms];
}
/** Sesión de invitado con un borrador: [cookie jar, csrf, code, wall_id, module_type_id] */
function guest(string $B): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ucl');
    $home = http('GET', "$B/", null, [], $jar)['body'];
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $home, $m);
    http('POST', "$B/invitado", http_build_query(['_csrf' => $m[1]]), [], $jar);
    $r = json_decode(http('POST', "$B/api/configurations", json_encode(['node_id' => 5]), ['Content-Type: application/json', "X-CSRF-Token: {$m[1]}"], $jar)['body'], true);
    return [$jar, $m[1], $r['config']['code'], $r['config']['walls'][0]['id'], $r['config']['walls'][0]['items'][1]['module_type_id']];
}

[$jar, $csrf, $code, $wall, $type] = guest($B);
$h = ['Content-Type: application/json', "X-CSRF-Token: $csrf"];
$big = json_encode(['ops' => array_fill(0, 20000, ['op' => 'add', 'wall_id' => $wall, 'module_type_id' => $type])]);
$r = http('POST', "$B/api/configurations/$code/ops", $big, $h, $jar);
printf("     (lote de 20.000 add, %.0f KB: HTTP %d en %.0f ms)\n", strlen($big) / 1024, $r['status'], $r['ms']);
check('un lote de 20.000 operaciones se rechaza al momento y no se aplica nada', fn() => eq(true, in_array($r['status'], [413, 422], true)) && eq(true, $r['ms'] < 1000)
    && eq(9, (int) val('SELECT COUNT(*) FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id JOIN configurations c ON c.id = w.configuration_id WHERE c.code = ?', [$code])));
$mid = json_encode(['ops' => array_fill(0, 101, ['op' => 'add', 'wall_id' => $wall, 'module_type_id' => $type])]);
$r2 = http('POST', "$B/api/configurations/$code/ops", $mid, $h, $jar);
check('101 operaciones (por debajo del tope de tamaño) también se rechazan con un error que dice el tope', fn() => eq(422, $r2['status']) && eq(true, str_contains($r2['body'], '100')));
$fill = json_encode(['ops' => array_fill(0, 100, ['op' => 'add', 'wall_id' => $wall, 'module_type_id' => $type])]);
for ($i = 0; $i < 2; $i++) http('POST', "$B/api/configurations/$code/ops", $fill, $h, $jar); // 9 + 200 = 209 líneas
$r3 = http('POST', "$B/api/configurations/$code/ops", $fill, $h, $jar); // pasaría de 300
check('una configuración no pasa de 300 líneas', fn() => eq(422, $r3['status']) && eq(true, str_contains($r3['body'], '300')));

// Lotes válidos en paralelo (6 invitados × 100 add) mientras se hacen logins
$guests = [];
for ($i = 0; $i < 6; $i++) $guests[] = guest($B);
$mh = curl_multi_init();
$handles = [];
foreach ($guests as [$gj, $gc, $gcode, $gw, $gt]) {
    $ch = curl_init("$B/api/configurations/$gcode/ops");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $gj, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $gc"],
        CURLOPT_POSTFIELDS => json_encode(['ops' => array_fill(0, 100, ['op' => 'add', 'wall_id' => $gw, 'module_type_id' => $gt])])]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
$loginJar = tempnam(sys_get_temp_dir(), 'ucl');
$logins = [];
$t0 = microtime(true);
do {
    curl_multi_exec($mh, $running);
    $home = http('GET', "$B/", null, [], $loginJar);
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $home['body'], $mm);
    if ($mm) {
        $l = http('POST', "$B/entrar", http_build_query(['_csrf' => $mm[1], 'email' => 'admin@x.test', 'password' => 'secreta123']), [], $loginJar);
        $logins[] = [$l['status'], $l['ms']];
        http('POST', "$B/salir", http_build_query(['_csrf' => $mm[1]]), [], $loginJar);
    }
    curl_multi_select($mh, 0.05);
} while ($running && microtime(true) - $t0 < 30);
$batch = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
$batchMs = (microtime(true) - $t0) * 1000;
$maxLogin = $logins ? max(array_column($logins, 1)) : 0;
printf("     (6 lotes de 100 add en paralelo: %.0f ms; %d logins mientras tanto, el más lento %.0f ms; estados lotes %s)\n", $batchMs, count($logins), $maxLogin, implode(',', $batch));
check('los lotes válidos se aplican (200)', fn() => eq(array_fill(0, 6, 200), $batch));
check('el login sigue respondiendo mientras tanto (ningún 500, ninguno por encima de 3 s)', fn() => eq(true, count($logins) > 0)
    && eq([], array_filter($logins, fn($l) => $l[0] >= 500 || $l[1] > 3000)));
check('cuerpo de más de 256 KB en /mcp: 413 sin tocar nada', function () use ($B) {
    $r = http('POST', "$B/mcp", str_repeat('x', 300 * 1024), ['Content-Type: application/json', 'Authorization: Bearer uc_' . str_repeat('0', 40)], tempnam(sys_get_temp_dir(), 'ucl'));
    return eq(413, $r['status']);
});

done();
