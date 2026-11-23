<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// root nunca ejecuta lo que el servidor web puede escribir. data/ (config.php, data/updates/…) es del servidor web:
// si root lo cargara, quien controle el servidor web pasaría a ser root. Por eso bin/uc se niega a correr como root
// (salvo `install`, que antes de tocar data/ baja al dueño de data/), y terminar una actualización solo acepta un
// pending.json normal, con el usuario que la lanzó, y un rollback.php de ese mismo usuario.
//   Hay que correrlo como root (docker run -u 0 …); si no, se omite.
//   php tests/cli_root.php

require __DIR__ . '/_checks.php';
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !($pw = posix_getpwnam('www-data'))) { echo "No es root (o no hay usuario www-data): se omite.\n"; exit(0); }
[$wuid, $wgid] = [(int) $pw['uid'], (int) $pw['gid']];
$repo = dirname(__DIR__);
$base = sys_get_temp_dir() . '/uc-root-' . getmypid();
$rm = function (string $d) use (&$rm) { if (!is_dir($d) || is_link($d)) { @unlink($d); return; } foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') $rm("$d/$f"); @rmdir($d); };
register_shutdown_function(fn() => $rm($base));
@mkdir($base, 0755, true);
$chown = function (string $p, int $u, int $g) use (&$chown) {
    chown($p, $u); chgrp($p, $g);
    if (is_dir($p) && !is_link($p)) foreach (scandir($p) as $f) if ($f !== '.' && $f !== '..') $chown("$p/$f", $u, $g);
};
/** Una carpeta de datos nueva, del servidor web (o de root con $owner = 0). */
$newData = function (string $name, int $owner = -1) use ($base, $wuid, $wgid, $chown): string {
    $d = "$base/$name";
    mkdir($d, 0750, true);
    $owner < 0 ? $chown($d, $wuid, $wgid) : $chown($d, $owner, $owner);
    return $d;
};
$uc = function (array $args, string $data, array $extra = []) use ($repo): array {
    $p = proc_open(array_merge([PHP_BINARY, "$repo/bin/uc"], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, ['UC_DATA' => $data, 'PATH' => getenv('PATH')] + $extra);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
/** Lo que haría un atacante que ya puede escribir como el servidor web: PHP que deja una marca si alguien lo ejecuta. */
$trap = fn(string $mark, string $tail = '') => "<?php file_put_contents(" . var_export($mark, true) . ", 'ejecutado por uid ' . posix_geteuid());$tail";

// --- 1. root no carga data/config.php
$d = $newData('config');
$mark = "$base/marca-config";
file_put_contents("$d/config.php", $trap($mark, " return ['secret' => str_repeat('a', 64), 'timezone' => 'UTC'];"));
$chown("$d/config.php", $wuid, $wgid);
[$rc, $out] = $uc(['backups'], $d);
check('bin/uc como root se niega (y dice cómo hacerlo: sudo -u www-data)', fn() => eq(true, $rc !== 0) && eq(true, str_contains($out, 'sudo -u www-data'), $out));
check('…y NO ha ejecutado el data/config.php que puede escribir el servidor web', fn() => eq(false, is_file($mark), 'marca'));

// --- 2. root no termina una actualización preparada por el servidor web
$d = $newData('finish');
$mark = "$base/marca-finish";
mkdir("$d/updates/x/code", 0750, true);
file_put_contents("$d/updates/pending.json", json_encode(['id' => 'x', 'from' => '0.3.0', 'to' => '0.3.1', 'stage' => 'swapping']));
file_put_contents("$d/updates/x/rollback.php", $trap($mark, " function uc_update_prev_rollback() { return ['restored' => 0, 'removed' => 0, 'db' => false, 'errors' => []]; }"));
$chown($d, $wuid, $wgid);
[$rc, $out] = $uc(['update:finish'], $d);
check('`bin/uc update:finish` como root se niega', fn() => eq(true, $rc !== 0) && eq(true, str_contains($out, 'sudo -u www-data'), $out));
check('…y NO ha ejecutado el rollback.php de data/updates', fn() => eq(false, is_file($mark), 'marca'));

// --- 3. install como root: antes de tocar data/ pasa a ser el dueño de data/
$d = $newData('install');
[$rc, $out] = $uc(['install', '--empresa=Prueba', '--email=a@x.test', '--url=http://127.0.0.1'], $d, ['UC_PASSWORD' => 'admin-12345']);
clearstatcache();
check('install como root funciona y todo lo de data/ es del dueño de data/ (no de root)', fn() => eq(0, $rc, $out)
    && eq($wuid, @fileowner("$d/config.php"), 'dueño de config.php') && eq($wuid, @fileowner("$d/configurator.sqlite"), 'dueño de la base'));
$d = $newData('install-root', 0);
[$rc, $out] = $uc(['install', '--empresa=Prueba', '--email=a@x.test'], $d, ['UC_PASSWORD' => 'admin-12345']);
clearstatcache();
check('install como root con data/ de root: se niega (no instala como root)', fn() => eq(true, $rc !== 0) && eq(false, is_file("$d/config.php")));

// --- 3b. un servidor web que corre como root (p. ej. `php -S` en un contenedor) no lanza actualizaciones: nadie podría
// terminarlas (root nunca termina una) y el configurador se quedaría en mantenimiento
$p = proc_open([PHP_BINARY, '-r', 'require "app/bootstrap.php"; [$ok, $why] = update_can_write(); echo $ok ? "SI" : "NO: $why";'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, ['UC_DATA' => "$base/install", 'PATH' => getenv('PATH')]);
$o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
proc_close($p);
check('como root, actualizar desde el panel se niega y dice por qué', fn() => eq(true, str_starts_with($o, 'NO:') && str_contains($o, 'root'), $o));

// --- 4. las reglas de terminar una actualización, en el propio proceso
$d = $newData('fn', 0);
putenv("UC_DATA=$d");
require $repo . '/app/update_finish.php';
check('pending.json sin uid: no lo termina nadie', fn() => eq(false, uc_update_may_finish(['id' => 'x'], 0)) && eq(false, uc_update_may_finish(['id' => 'x'], $wuid)));
check('root no termina ninguna; el mismo usuario sí', fn() => eq(false, uc_update_may_finish(['uid' => $wuid], 0)) && eq(false, uc_update_may_finish(['uid' => 0], 0))
    && eq(true, uc_update_may_finish(['uid' => $wuid], $wuid)) && eq(false, uc_update_may_finish(['uid' => $wuid + 1], $wuid)));
check('la que lanzó root con la 0.3.0 la termina el servidor web (no se queda en mantenimiento)', fn() => eq(true, uc_update_may_finish(['uid' => 0], $wuid)));

// Como el servidor web (el único que termina actualizaciones): los archivos de data/updates tienen que ser suyos y normales.
$d = $newData('fnweb');
mkdir("$d/updates/x/code", 0750, true);
$mark = "$d/marca-fn"; // dentro de data/: el servidor web puede escribir ahí si llegara a ejecutarlo
$pending = json_encode(['id' => 'x', 'from' => '0.3.0', 'to' => '0.3.1', 'stage' => 'swapping', 'uid' => $wuid]);
file_put_contents("$base/pending-de-fuera.json", $pending);
symlink("$base/pending-de-fuera.json", "$d/updates/pending.json");
file_put_contents("$d/updates/x/rollback.php", $trap($mark, " function uc_update_prev_rollback() { return ['restored' => 0, 'removed' => 0, 'db' => false, 'errors' => []]; }"));
$chown($d, $wuid, $wgid);
lchown("$d/updates/pending.json", $wuid);
$finish = function () use ($repo, $d): string {
    $code = 'require ' . var_export("$repo/app/update_finish.php", true) . '; echo var_export(uc_update_finish_entry(), true);';
    $p = proc_open(as_web_user([PHP_BINARY, '-r', $code]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $d, ['UC_DATA' => $d, 'PATH' => getenv('PATH')]);
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    proc_close($p);
    return trim($o);
};
check('pending.json que es un enlace simbólico: no se acepta', fn() => eq('NULL', $finish()) && eq(false, is_file($mark)));
unlink("$d/updates/pending.json");
file_put_contents("$d/updates/pending.json", $pending);
$chown("$d/updates/pending.json", $wuid, $wgid);
chown("$d/updates/x/rollback.php", 0);
check('rollback.php que no es del usuario que termina: no se ejecuta (se usa la vuelta atrás del código instalado)', fn() => eq("'rolled_back'", $finish()) && eq(false, is_file($mark)));

done();
