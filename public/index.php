<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

// Servidor integrado de PHP (desarrollo): servir directamente los archivos estáticos de public/.
if (PHP_SAPI === 'cli-server') {
    $file = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($file !== false && str_starts_with($file, __DIR__ . '/') && is_file($file) && !str_ends_with($file, '.php')) return false;
}

// Antes de cargar la aplicación: terminar una actualización pendiente (con el código nuevo) y, si hay mantenimiento, 503.
require __DIR__ . '/../app/update_finish.php';
uc_update_finish_entry();
require __DIR__ . '/../app/maintenance.php';
if (uc_maintenance_gate()) return;

require_once __DIR__ . '/../app/bootstrap.php';
uc_handle_request();
