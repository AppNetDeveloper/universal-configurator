<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

define('UC_ROOT', dirname(__DIR__));
define('UC_PUBLIC', UC_ROOT . '/public');
define('UC_DATA', getenv('UC_DATA') ?: UC_ROOT . '/data');

require UC_ROOT . '/app/textos.php';
require UC_ROOT . '/app/core.php';
require UC_ROOT . '/app/security.php';
require UC_ROOT . '/app/mail.php';
require UC_ROOT . '/app/auth.php';
require UC_ROOT . '/app/auth_providers.php';
require UC_ROOT . '/app/catalog.php';
require UC_ROOT . '/app/pricing.php';
require UC_ROOT . '/app/configurations.php';
require UC_ROOT . '/app/front.php';
require UC_ROOT . '/app/api.php';
require UC_ROOT . '/app/mcp.php';
require UC_ROOT . '/app/admin.php';
require UC_ROOT . '/app/install.php';

date_default_timezone_set((string) config('timezone', 'Europe/Madrid'));
mb_internal_encoding('UTF-8');
if (is_installed()) ensure_schema();
