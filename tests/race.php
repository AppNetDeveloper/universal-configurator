<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Límites con concurrencia REAL: un servidor PHP con varios procesos (PHP_CLI_SERVER_WORKERS) y muchas peticiones a la
// vez, cada una con su propia sesión (una cookie compartida las pondría en fila y escondería la carrera). Entran
// exactamente las que deja el límite: ni una más, y ningún error interno.
//   php tests/race.php

$root = dirname(__DIR__);
$data = sys_get_temp_dir() . '/uc-race-' . getmypid();
@mkdir($data, 0700, true);
$port = random_int(40001, 50000);
$env = ['UC_DATA' => $data, 'PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => getenv('PATH') ?: '/usr/bin'];
putenv("UC_DATA=$data");
require $root . '/app/bootstrap.php';
require __DIR__ . '/_checks.php';
uc_install(['empresa' => 'Carrera', 'admin_email' => 'admin@x.test', 'admin_password' => 'secreta123', 'url' => "http://127.0.0.1:$port", 'sample' => true]);
settings_save(['permitir_invitado' => '1']);
$final = (int) val('SELECT n.id FROM nodes n WHERE NOT EXISTS (SELECT 1 FROM nodes c WHERE c.parent_id = n.id) ORDER BY n.id LIMIT 1');
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"], [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root, $env);
$jars = [];
register_shutdown_function(function () use ($proc, $data, &$jars) {
    proc_terminate($proc);
    foreach ($jars as $j) @unlink($j);
    foreach (scandir($data) ?: [] as $f) if (is_file("$data/$f")) @unlink("$data/$f");
    @rmdir($data);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$B = "http://127.0.0.1:$port";

$opts = function (string $method, string $url, ?string $body, array $headers, string $jar): array {
    return [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []);
};
$one = function (string $method, string $url, ?string $body, array $headers, string $jar) use ($opts): array {
    $ch = curl_init();
    curl_setopt_array($ch, $opts($method, $url, $body, $headers, $jar));
    $b = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$st, $b];
};
/** Todas a la vez. @param list<array{0:string,1:string,2:?string,3:array,4:string}> $reqs  @return list<int> estados */
$parallel = function (array $reqs) use ($opts): array {
    $mh = curl_multi_init();
    $hs = [];
    foreach ($reqs as $r) { $ch = curl_init(); curl_setopt_array($ch, $opts(...$r)); curl_multi_add_handle($mh, $ch); $hs[] = $ch; }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $ch) { $out[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $out;
};
/** Una sesión de invitado nueva: [cookie jar, csrf]. */
$guest = function () use ($B, $one, &$jars): array {
    $jar = $jars[] = tempnam(sys_get_temp_dir(), 'ucr');
    [, $home] = $one('GET', "$B/", null, [], $jar);
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $home, $m);
    $one('POST', "$B/invitado", http_build_query(['_csrf' => $m[1]]), [], $jar);
    return [$jar, $m[1]];
};
$count = fn(array $sts, int $code) => count(array_filter($sts, fn($s) => $s === $code));

// --- 1. pedir el presupuesto: 20 a la vez desde la misma conexión, límite 5 por hora
$reqs = [];
for ($i = 0; $i < 20; $i++) {
    [$jar, $csrf] = $guest();
    $h = ['Content-Type: application/json', "X-CSRF-Token: $csrf"];
    $c = json_decode($one('POST', "$B/api/configurations", json_encode(['node_id' => $final]), $h, $jar)[1], true);
    $reqs[] = ['POST', "$B/api/configurations/{$c['config']['code']}/request", json_encode(['name' => "Cliente $i", 'phone' => '600 000 ' . sprintf('%03d', $i), 'consent' => true]), $h, $jar];
}
q('DELETE FROM throttle'); // las 20 configuraciones de arriba no cuentan para lo de abajo
$sts = $parallel($reqs);
check('20 peticiones de presupuesto a la vez: entran exactamente ' . LEAD_MAX_PER_IP_HOUR . ' (estados: ' . implode(',', $sts) . ')', fn() => eq(LEAD_MAX_PER_IP_HOUR, $count($sts, 200))
    && eq(20 - LEAD_MAX_PER_IP_HOUR, $count($sts, 429)) && eq(LEAD_MAX_PER_IP_HOUR, (int) val('SELECT COUNT(*) FROM leads')));

// --- 2. borradores de invitado: 45 a la vez desde la misma conexión, límite 30 por hora
q('DELETE FROM throttle');
$before = (int) val('SELECT COUNT(*) FROM configurations');
$reqs = [];
for ($i = 0; $i < 45; $i++) {
    [$jar, $csrf] = $guest();
    $reqs[] = ['POST', "$B/api/configurations", json_encode(['node_id' => $final]), ['Content-Type: application/json', "X-CSRF-Token: $csrf"], $jar];
}
$sts = $parallel($reqs);
check('45 borradores de invitado a la vez: entran exactamente 30 (estados: ' . implode(',', $sts) . ')', fn() => eq(30, $count($sts, 201)) && eq(15, $count($sts, 429))
    && eq(30, (int) val('SELECT COUNT(*) FROM configurations') - $before));

done();
