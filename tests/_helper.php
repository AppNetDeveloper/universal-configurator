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

require __DIR__ . '/_checks.php';

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
