<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Actualización de punta a punta, SIN red real: una copia de la aplicación en una carpeta temporal, versiones falsas
// firmadas con una clave desechable y servidas por un servidor HTTP local (php -S). Comprueba: firma y sha256
// (un paquete cambiado o firmado por otro no se instala y no deja nada a medias), la actualización buena (código nuevo,
// datos intactos, sin mantenimiento) y la vuelta atrás sola cuando la migración de la versión nueva falla.
//   php tests/update.php

require __DIR__ . '/_checks.php';
if (!class_exists('ZipArchive') || !function_exists('sodium_crypto_sign_keypair')) { echo "Sin zip o libsodium: se omite.\n"; exit(0); }

$base = sys_get_temp_dir() . '/uc-update-' . getmypid();
$root = "$base/root";
$data = "$base/data";
$dist = "$base/dist";
$php = PHP_BINARY;
$repo = dirname(__DIR__);
$rm = function (string $d) use (&$rm) { if (!is_dir($d) || is_link($d)) { @unlink($d); return; } foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') $rm("$d/$f"); @rmdir($d); };
$copy = function (string $from, string $to) use (&$copy) {
    if (is_dir($from)) { @mkdir($to, 0755, true); foreach (scandir($from) as $f) if ($f !== '.' && $f !== '..') $copy("$from/$f", "$to/$f"); }
    else { copy($from, $to); chmod($to, fileperms($from) & 0777); }
};
register_shutdown_function(function () use ($base, $rm) { if (!empty($GLOBALS['__srv'])) proc_terminate($GLOBALS['__srv']); $rm($base); });

// Copia de la aplicación (sin .git: si no, el actualizador se niega con razón) con una clave de pruebas
@mkdir($data, 0750, true);
foreach (['app', 'bin', 'public'] as $d) $copy("$repo/$d", "$root/$d");
$kp = sodium_crypto_sign_keypair();
$pub = base64_encode(sodium_crypto_sign_publickey($kp));
$sk = sodium_crypto_sign_secretkey($kp);
$upd = (string) file_get_contents("$root/app/update.php");
file_put_contents("$root/app/update.php", preg_replace("/const UPDATE_PUBLIC_KEY = '[^']+';/", "const UPDATE_PUBLIC_KEY = '$pub';", $upd));
file_put_contents("$base/key", base64_encode($sk) . "\n");
chmod("$base/key", 0600);
$version = fn() => preg_match("/const UC_VERSION = '([^']+)';/", (string) file_get_contents("$root/app/core.php"), $m) ? $m[1] : '?';
$installed = $version();

$env = ['UC_DATA' => $data, 'UC_PASSWORD' => 'admin-12345'];
$run = function (array $args, array $extra = []) use ($php, $root, $env): array {
    $p = proc_open(array_merge([$php, "$root/bin/uc"], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + $extra + ['PATH' => getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$sql = function (string $q) use ($data) { $pdo = new PDO("sqlite:$data/configurator.sqlite"); $r = $pdo->query($q); return $r ? $r->fetchAll(PDO::FETCH_NUM) : []; };
[$rc] = $run(['install', '--empresa=Prueba', '--email=a@x.test', '--url=http://127.0.0.1', '--sample']);
check('la copia de pruebas se instala con los datos de ejemplo', fn() => eq(0, $rc) && eq(true, (int) $sql('SELECT COUNT(*) FROM nodes')[0][0] > 0));
$nodes = $sql('SELECT id, name FROM nodes ORDER BY id');

/** Una versión falsa: código de la copia con otra versión y $tweak aplicado; empaquetada y firmada por bin/release. */
$release = function (string $v, ?callable $tweak = null, ?string $key = null) use ($root, $base, $dist, $php, $copy, $rm): void {
    $src = "$base/src-$v";
    $rm($src);
    foreach (['app', 'bin', 'public'] as $d) $copy("$root/$d", "$src/$d");
    $core = "$src/app/core.php";
    file_put_contents($core, preg_replace("/const UC_VERSION = '[^']+';/", "const UC_VERSION = '$v';", (string) file_get_contents($core)));
    file_put_contents("$src/public/assets/version-$v.txt", "v$v\n");
    if ($tweak) $tweak($src);
    $p = proc_open([$php, "$root/bin/release", "--src=$src", "--out=$dist", '--key=' . ($key ?? "$base/key"), '--other-key'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) throw new RuntimeException("bin/release: $o");
};
/** Publica en el «GitHub» de pruebas una lista con estas versiones. */
$publish = function (array $versions) use ($dist, &$port) {
    $list = [];
    foreach ($versions as $v) $list[] = ['tag_name' => "v$v", 'draft' => false, 'prerelease' => false, 'html_url' => "http://127.0.0.1:$port/v$v",
        'assets' => [['name' => "universal-configurator-$v.zip", 'browser_download_url' => "http://127.0.0.1:$port/universal-configurator-$v.zip"],
                     ['name' => "universal-configurator-$v.update.json", 'browser_download_url' => "http://127.0.0.1:$port/universal-configurator-$v.update.json"]]];
    file_put_contents("$dist/releases.json", json_encode($list));
};

// Servidor HTTP local que hace de GitHub
@mkdir($dist, 0755, true);
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 60000);
    $srv = proc_open([$php, '-S', "127.0.0.1:$cand", '-t', $dist], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
    for ($i = 0; $i < 30; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $cand)) { $port = $cand; $GLOBALS['__srv'] = $srv; break; } }
    if (!$port) proc_terminate($srv);
}
if (!$port) { echo "No se pudo levantar el servidor de pruebas.\n"; exit(1); }
$feed = ['UC_UPDATE_FEED' => "http://127.0.0.1:$port/releases.json"];

// --- 1. paquete cambiado después de firmarlo: sha256 malo
$release('9.8.0');
file_put_contents("$dist/universal-configurator-9.8.0.zip", "basura", FILE_APPEND);
$publish(['9.8.0']);
[$rc, $out] = $run(['update', '--yes'], $feed);
check('un paquete que no coincide con su sha256 firmado NO se instala y no deja nada a medias', fn() => eq(1, $rc) && eq(true, str_contains($out, 'sha256'))
    && eq($installed, $version()) && eq(false, is_file("$data/maintenance.json")) && eq(false, is_file("$data/updates/pending.json")) && eq(false, is_file("$root/public/assets/version-9.8.0.txt")));

// --- 2. firmado con otra clave
$other = sodium_crypto_sign_keypair();
file_put_contents("$base/otra", base64_encode(sodium_crypto_sign_secretkey($other)) . "\n");
chmod("$base/otra", 0600);
$release('9.8.1', null, "$base/otra");
$publish(['9.8.1']);
[$rc, $out] = $run(['update:check'], $feed);
check('firmado con otra clave: ni siquiera se ofrece (la firma no vale)', fn() => eq(true, str_contains($out, 'firma')) && eq($installed, $version()));
[$rc, $out] = $run(['update', '--yes'], $feed);
check('…ni se instala', fn() => eq(1, $rc) && eq($installed, $version()) && eq(false, is_file("$root/public/assets/version-9.8.1.txt")));

// --- 3. la buena
$release('9.9.0');
$publish(['9.8.0', '9.9.0']);
[$rc, $out] = $run(['update:check'], $feed);
check('buscar: ofrece la versión más nueva', fn() => eq(true, str_contains($out, '9.9.0')));
[$rc, $out] = $run(['update', '--yes'], $feed);
check('actualizar: código nuevo, datos intactos, sin mantenimiento y con copia previa', fn() => eq(0, $rc) && eq('9.9.0', $version())
    && eq(true, is_file("$root/public/assets/version-9.9.0.txt")) && eq($nodes, $sql('SELECT id, name FROM nodes ORDER BY id'))
    && eq(false, is_file("$data/maintenance.json")) && eq(false, is_file("$data/updates/pending.json"))
    && eq(true, (bool) glob("$data/backups/uc-copia-*-antes.zip")) && eq(true, json_decode((string) file_get_contents("$data/updates/last-result.json"), true)['ok']));

// --- 4. la migración de la versión nueva falla: vuelve sola a la anterior, también la base
$release('9.9.1', function (string $src) {
    $core = "$src/app/core.php";
    $c = (string) file_get_contents($core);
    $c = preg_replace('/const UC_SCHEMA = (\d+);/', 'const UC_SCHEMA = 99;', $c);
    $c = str_replace("function migrate(): void\n{", "function migrate(): void\n{\n    db()->exec('CREATE TABLE IF NOT EXISTS rastro_migracion (x)');\n    throw new RuntimeException('migración rota a propósito');", $c);
    file_put_contents($core, $c);
});
$publish(['9.9.0', '9.9.1']);
[$rc, $out] = $run(['update', '--yes'], $feed);
$res = json_decode((string) @file_get_contents("$data/updates/last-result.json"), true);
check('migración que falla: vuelve sola a la 9.9.0 (código y base), sin mantenimiento, y lo explica', fn() => eq(1, $rc) && eq('9.9.0', $version())
    && eq(false, is_file("$root/public/assets/version-9.9.1.txt")) && eq([], $sql("SELECT name FROM sqlite_master WHERE name = 'rastro_migracion'"))
    && eq($nodes, $sql('SELECT id, name FROM nodes ORDER BY id')) && eq(false, is_file("$data/maintenance.json")) && eq(false, is_file("$data/updates/pending.json"))
    && eq(false, $res['ok']) && eq(true, str_contains((string) $res['message'], 'migración rota a propósito')));
check('tras la vuelta atrás, la aplicación arranca y responde', function () use ($run) { [$rc, $o] = $run(['backups']); return eq(0, $rc); });

// --- 5. desde el panel: la fase 1 en una petición y la fase 2 en la SIGUIENTE petición web (public/index.php)
$phase1 = function () use ($php, $root, $env, $feed): array {
    $code = 'require "app/bootstrap.php"; Actor::set("panel"); try { $r = update_run_remote("admin"); echo "OK ", $r["to"]; } catch (Throwable $e) { echo "ERR ", $e->getMessage(); }';
    $p = proc_open([$php, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + $feed + ['PATH' => getenv('PATH')]);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $o];
};
$web = function () use ($php, $root, $data): array {
    $port = 0; $srv = null;
    for ($t = 0; $t < 20 && !$port; $t++) {
        $cand = random_int(20000, 60000);
        $srv = proc_open([$php, '-S', "127.0.0.1:$cand", '-t', "$root/public", "$root/public/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, ['UC_DATA' => $data, 'PATH' => getenv('PATH')]);
        for ($i = 0; $i < 30; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $cand)) { $port = $cand; break; } }
        if (!$port) proc_terminate($srv);
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
    $body = (string) @file_get_contents("http://127.0.0.1:$port/", false, $ctx);
    $status = (int) (preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    proc_terminate($srv);
    return [$status, $body];
};
$release('9.9.2');
$publish(['9.9.2']);
[$rc, $out] = $phase1();
$mid = is_file("$data/updates/pending.json") && is_file("$data/maintenance.json");
[$st, $body] = $web();
clearstatcache(); // el is_file de antes queda en la caché de stat de PHP
check('panel: tras «Actualizar ahora», la siguiente visita termina la actualización (200, versión nueva, sin mantenimiento)', fn() => eq(true, str_starts_with($out, 'OK 9.9.2'))
    && eq(true, $mid) && eq(200, $st) && eq('9.9.2', $version()) && eq(false, is_file("$data/updates/pending.json")) && eq(false, is_file("$data/maintenance.json"))
    && eq(true, json_decode((string) file_get_contents("$data/updates/last-result.json"), true)['ok']));
$release('9.9.3', function (string $src) {
    $core = "$src/app/core.php";
    file_put_contents($core, str_replace("function migrate(): void\n{", "function migrate(): void\n{\n    throw new RuntimeException('migración rota (web)');", (string) file_get_contents($core)));
    file_put_contents($core, preg_replace('/const UC_SCHEMA = (\d+);/', 'const UC_SCHEMA = 98;', (string) file_get_contents($core)));
});
$publish(['9.9.3']);
[$rc, $out] = $phase1();
[$st, $body] = $web();
[$st2] = $web();
clearstatcache();
check('panel: si la versión nueva falla al arrancar, esa visita vuelve sola a la anterior (503 de un momento) y la siguiente ya funciona', fn() => eq(true, str_starts_with($out, 'OK 9.9.3'))
    && eq(503, $st) && eq('9.9.2', $version()) && eq(200, $st2) && eq(false, is_file("$data/updates/pending.json")) && eq(false, is_file("$data/maintenance.json"))
    && eq(true, str_contains((string) json_decode((string) file_get_contents("$data/updates/last-result.json"), true)['message'], 'migración rota (web)')));

done();
