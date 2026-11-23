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
require UC_ROOT . '/app/expr.php';
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

/** Punto de entrada único: instalador, MCP, API, panel o pantallas del configurador. */
function uc_handle_request(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');
    security_headers($path);
    try {
        if (!is_installed()) { install_handle($path, $method); return; }
        if ($path === '/mcp') { mcp_handle($method); return; }
        if (str_starts_with($path, '/api/')) { api_handle($method, $path); return; }
        session_start_safe();
        security_session_check();
        if ($path === '/admin' || str_starts_with($path, '/admin/')) { admin_handle(substr($path, 6) ?: '/', $method); return; }
        front_handle($path, $method);
    } catch (UcError $ex) {
        if ($ex->status === 419) { http_response_code(419); header('Content-Type: text/html; charset=utf-8'); echo e($ex->getMessage()); return; }
        error_log('[universal-configurator] ' . $ex->getMessage());
        http_response_code($ex->status);
        header('Content-Type: text/html; charset=utf-8');
        echo e($ex->getMessage());
    } catch (Throwable $ex) {
        error_log('[universal-configurator] ' . $ex);
        if (!headers_sent()) http_response_code(500);
        echo config('debug') ? '<pre>' . e((string) $ex) . '</pre>' : 'Error interno del servidor.';
    }
}
