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
require UC_ROOT . '/app/parts.php';
require UC_ROOT . '/app/configurations.php';
require UC_ROOT . '/app/front.php';
require UC_ROOT . '/app/api.php';
require UC_ROOT . '/app/mcp.php';
require UC_ROOT . '/app/admin.php';
require UC_ROOT . '/app/install.php';

date_default_timezone_set((string) config('timezone', 'Europe/Madrid'));
mb_internal_encoding('UTF-8');
// Actualización de esquema: con varios arranques a la vez la base puede estar bloqueada; se responde 503 «actualizando».
$GLOBALS['uc_schema_busy'] = false;
if (is_installed()) {
    try { ensure_schema(); }
    catch (PDOException $ex) { if (!db_busy($ex)) throw $ex; $GLOBALS['uc_schema_busy'] = true; }
}

/** Punto de entrada único: instalador, MCP, API, panel o pantallas del configurador. */
function uc_handle_request(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');
    security_headers($path);
    if (!empty($GLOBALS['uc_schema_busy'])) { uc_busy_response($path, t('Actualizando la aplicación: reintenta en unos segundos.')); return; }
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
    } catch (PDOException $ex) {
        // Base ocupada fuera de una transacción (límites de intentos, login, auditoría…): 503, no 500.
        if (!db_busy($ex)) { error_log('[universal-configurator] ' . $ex); if (!headers_sent()) http_response_code(500); echo 'Error interno del servidor.'; return; }
        uc_busy_response($path, t('La base de datos está ocupada en este momento: inténtalo de nuevo en unos segundos.'));
    } catch (Throwable $ex) {
        error_log('[universal-configurator] ' . $ex);
        if (!headers_sent()) http_response_code(500);
        echo config('debug') ? '<pre>' . e((string) $ex) . '</pre>' : 'Error interno del servidor.';
    }
}

/** 503 sin trazas, con Retry-After; JSON en /api y /mcp. */
function uc_busy_response(string $path, string $msg): void
{
    if (!headers_sent()) { http_response_code(503); header('Retry-After: 3'); header('Cache-Control: no-store'); }
    if ($path === '/mcp' || str_starts_with($path, '/api/')) { header('Content-Type: application/json; charset=utf-8'); echo json_enc(['ok' => false, 'error' => $msg]); return; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>503</title><p>' . e($msg) . '</p>';
}
