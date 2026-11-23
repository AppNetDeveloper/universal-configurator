<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

// Servidor integrado de PHP (desarrollo): servir directamente los archivos estáticos de public/.
if (PHP_SAPI === 'cli-server') {
    $file = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($file !== false && str_starts_with($file, __DIR__ . '/') && is_file($file) && !str_ends_with($file, '.php')) return false;
}

require __DIR__ . '/../app/bootstrap.php';
uc_handle_request();
