<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// check(), check_error(), eq() y done(): lo mínimo para escribir pruebas sin PHPUnit.

$GLOBALS['__fails'] = 0;
$GLOBALS['__count'] = 0;

/** La prueba pasa si $fn no lanza y no devuelve false. */
function check(string $name, callable $fn): void
{
    $GLOBALS['__count']++;
    try { $r = $fn(); $ok = $r !== false; $msg = $ok ? '' : 'devolvió false'; } catch (Throwable $e) { $ok = false; $msg = get_class($e) . ': ' . $e->getMessage(); }
    if (!$ok) $GLOBALS['__fails']++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : '  → ' . $msg);
}

/** La prueba pasa si $fn lanza una excepción cuyo mensaje contiene $contains (si se da). */
function check_error(string $name, callable $fn, string $contains = ''): void
{
    check($name, function () use ($fn, $contains) {
        try { $fn(); } catch (Throwable $e) {
            if ($contains !== '' && !str_contains($e->getMessage(), $contains)) throw new RuntimeException('error distinto: ' . $e->getMessage());
            return true;
        }
        throw new RuntimeException('se esperaba un error y no lo hubo');
    });
}

function eq(mixed $expected, mixed $actual, string $what = ''): bool
{
    if ($expected !== $actual) throw new RuntimeException(($what ? "$what: " : '') . 'esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true));
    return true;
}

function done(): never
{
    $f = $GLOBALS['__fails'];
    echo $f ? "\n$f de {$GLOBALS['__count']} pruebas FALLAN\n" : "\n{$GLOBALS['__count']} pruebas, todo bien\n";
    exit($f ? 1 : 0);
}


/** Como root, las órdenes de la aplicación se lanzan como el servidor web (bin/uc se niega a correr como root). */
function as_web_user(array $cmd): array
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !posix_getpwnam('www-data')) return $cmd;
    return ['su', '-s', '/bin/sh', 'www-data', '-c', 'exec ' . implode(' ', array_map('escapeshellarg', $cmd))];
}

/** Como root, deja $path (y lo que haya dentro) del servidor web, para que las órdenes lanzadas con as_web_user() puedan escribir. */
function chown_to_web_user(string $path): void
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !($pw = posix_getpwnam('www-data'))) return;
    if (!file_exists($path) && !is_link($path)) return;
    @lchown($path, $pw['uid']); @lchgrp($path, $pw['gid']);
    if (is_dir($path) && !is_link($path)) foreach (scandir($path) as $f) if ($f !== '.' && $f !== '..') chown_to_web_user("$path/$f");
}
