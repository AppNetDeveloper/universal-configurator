<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Modo mantenimiento (restaurar una copia o actualizar). Lo comprueba public/index.php ANTES de cargar la aplicación,
 * porque durante una actualización el código está a medio cambiar. Archivo: data/maintenance.json {since, until, reason}.
 * Caduca solo (until): si un proceso muere a mitad, el configurador no se queda cerrado para siempre. Con una
 * actualización a medio terminar (data/updates/pending.json) no caduca: servir código a medio cambiar sería peor.
 * Autónomo: no usa nada de la aplicación. (Mismo patrón que Universal Web.)
 */

function uc_maintenance_file(): string
{
    return (getenv('UC_DATA') ?: dirname(__DIR__) . '/data') . '/maintenance.json';
}

function uc_maintenance_on(string $reason, int $minutes = 20): void
{
    @file_put_contents(uc_maintenance_file(), json_encode(['since' => time(), 'until' => time() + $minutes * 60, 'reason' => $reason]) . "\n", LOCK_EX);
}

function uc_maintenance_off(): void
{
    @unlink(uc_maintenance_file());
}

/** Datos del mantenimiento si está activo y no ha caducado; null si no. */
function uc_maintenance_state(): ?array
{
    $raw = @file_get_contents(uc_maintenance_file());
    if ($raw === false) return null;
    if (is_file(dirname(uc_maintenance_file()) . '/updates/pending.json')) return json_decode($raw, true) ?: ['reason' => 'update'];
    $m = json_decode($raw, true);
    if (!is_array($m) || (int) ($m['until'] ?? 0) < time()) return null;
    return $m;
}

/** true = la petición ya se ha respondido con 503. */
function uc_maintenance_gate(): bool
{
    if (PHP_SAPI === 'cli') return false;
    if (uc_maintenance_state() === null) return false;
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Volvemos en un momento</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.6 system-ui,sans-serif;background:#f5f6f8;color:#0f172a}'
        . 'main{max-width:30rem;padding:2rem 1.5rem;text-align:center}p{color:#475569}</style></head>'
        . '<body><main><h1>Volvemos en un momento</h1><p>Estamos poniendo al día el configurador. Vuelve a cargar la página en un par de minutos.</p></main></body></html>';
    return true;
}
