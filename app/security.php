<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Endurecimiento: cabeceras de seguridad (CSP sin JavaScript en línea, no se puede incrustar), caducidad de la
 * sesión por inactividad y vínculo al navegador. Todo POST lleva CSRF (formularios: campo _csrf; API: X-CSRF-Token).
 */

const SESSION_IDLE_SECONDS = 8 * 3600;

function security_headers(string $path): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header('X-Frame-Options: DENY');
    header_remove('X-Powered-By');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($path === '/mcp') return;
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; "
        . "font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
}

/** Llamar tras session_start_safe(): caducidad por inactividad, vínculo al navegador y rotación periódica del id. */
function security_session_check(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $now = time();
    $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!empty($_SESSION['uid']) || !empty($_SESSION['guest'])) {
        $expired = isset($_SESSION['last_seen']) && $now - (int) $_SESSION['last_seen'] > SESSION_IDLE_SECONDS;
        $hijack = isset($_SESSION['ua']) && !hash_equals((string) $_SESSION['ua'], $ua);
        if ($expired || $hijack) {
            $_SESSION = [];
            session_regenerate_id(true);
            flash($expired ? t('Sesión caducada por inactividad: entra de nuevo.') : t('Sesión cerrada: el navegador ha cambiado. Entra de nuevo.'), 'error');
        } elseif ($now - (int) ($_SESSION['regen_at'] ?? 0) > 1800) {
            session_regenerate_id(true);
            $_SESSION['regen_at'] = $now;
        }
    }
    $_SESSION['last_seen'] = $now;
    $_SESSION['ua'] ??= $ua;
    $_SESSION['regen_at'] ??= $now;
}
