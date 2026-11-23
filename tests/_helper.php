<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Utilidades comunes de las pruebas. Cada fichero de prueba usa su propia carpeta de datos temporal
// (UC_DATA), nunca la carpeta data/ real.

$GLOBALS['__uc_tmp'] = sys_get_temp_dir() . '/uc-test-' . basename($_SERVER['SCRIPT_NAME'] ?? 'x', '.php') . '-' . getmypid();
@mkdir($GLOBALS['__uc_tmp'], 0700, true);
putenv('UC_DATA=' . $GLOBALS['__uc_tmp']);
require dirname(__DIR__) . '/app/bootstrap.php';
migrate();
register_shutdown_function(function () {
    $d = $GLOBALS['__uc_tmp'];
    foreach (scandir($d) ?: [] as $f) if (is_file("$d/$f")) @unlink("$d/$f");
    @rmdir($d);
});

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

// ---------------------------------------------------------------- datos de prueba

function fx_user(string $role, string $email, string $password = 'secreta123', bool $active = true): array
{
    $id = user_create($email, ucfirst($role) . ' ' . strtok($email, '@'), $role, $password);
    if (!$active) update('users', ['active' => 0], 'id = ?', [$id]);
    return user_by_id($id);
}

/**
 * Catálogo mínimo: Cocina → Línea → Blanco (final) · Cocina → Dúo → Roble (final).
 * Tipo «Bajo» con tramos generales 30–40 = 80 € y 41–60 = 100 €; «Alto» 30–60 = 50 €.
 * Composición de Línea: una pared con Bajo 40 y Bajo 60 (la hereda Blanco).
 */
function fx_catalog(): array
{
    $root = node_create(null, ['name' => 'Cocina']);
    $linea = node_create($root, ['name' => 'Línea']);
    $blanco = node_create($linea, ['name' => 'Blanco', 'image' => '#F8FAFC']);
    $duo = node_create($root, ['name' => 'Dúo']);
    $roble = node_create($duo, ['name' => 'Roble']);
    $bajo = module_type_save(['name' => 'Bajo', 'family' => 'bajo', 'depth_mm' => 560]);
    $alto = module_type_save(['name' => 'Alto', 'family' => 'alto', 'depth_mm' => 350]);
    tier_save($bajo, null, 30, 40, 8000);
    tier_save($bajo, null, 41, 60, 10000);
    tier_save($alto, null, 30, 60, 5000);
    $wall = layout_wall_save($linea, ['name' => 'Pared 1', 'direction' => 'x']);
    default_module_save($wall, $bajo, 40);
    default_module_save($wall, $bajo, 60);
    return compact('root', 'linea', 'blanco', 'duo', 'roble', 'bajo', 'alto', 'wall');
}
