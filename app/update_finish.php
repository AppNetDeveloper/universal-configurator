<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Segunda mitad de una actualización, en un proceso NUEVO (el que cambió los archivos sigue con el código viejo en
 * memoria). La lanza la primera petición que llega con data/updates/pending.json en fase «swapped» (public/index.php)
 * o `php bin/uc update:finish`. Con el código nuevo: migraciones y prueba de salud. Si algo falla —una excepción, un
 * error fatal o una migración que no termina—, la función de cierre vuelve sola a la versión anterior (código y base
 * de datos) con el rollback.php que se guardó del código viejo, y lo deja escrito en last-result.json.
 * Autónomo hasta cargar bootstrap.php. (Mismo patrón que Universal Web.)
 */

if (!function_exists('uc_update_data_dir')) {

function uc_update_data_dir(): string
{
    return getenv('UC_DATA') ?: dirname(__DIR__) . '/data';
}

/** Cerrojo de un archivo de data/updates (si ya existe se abre en lectura: flock vale igual). */
function uc_update_lock_open(string $file)
{
    return is_file($file) ? @fopen($file, 'r') : @fopen($file, 'c');
}

/** Usuario que ejecuta este proceso (o -1 si no se sabe). */
function uc_update_uid(): int
{
    return function_exists('posix_geteuid') ? posix_geteuid() : -1;
}

/**
 * ¿Puede este usuario terminar la actualización? Root, nunca (cargaría lo que el servidor web puede escribir). Sin `uid`,
 * nadie. El mismo usuario que la lanzó, sí. Y la que lanzó root con la 0.3.0 (su manual decía `sudo php bin/uc update`):
 * la termina el servidor web en la siguiente visita, para no dejar el configurador en mantenimiento.
 */
function uc_update_may_finish(array $p, int $uid): bool
{
    return $uid !== 0 && isset($p['uid']) && is_int($p['uid']) && ($p['uid'] === $uid || $p['uid'] === 0);
}

/** ¿Archivo normal (no un enlace) y de este mismo usuario? Lo que se lee o ejecuta de data/updates tiene que serlo. */
function uc_update_own_file(string $f): bool
{
    if (!is_file($f) || is_link($f)) return false;
    $uid = uc_update_uid();
    return $uid < 0 || @fileowner($f) === $uid;
}

function uc_update_rmtree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

/** Resultado de la última actualización (lo lee el panel aunque la base de datos haya vuelto atrás). */
function uc_update_write_result(array $r): void
{
    @mkdir(uc_update_data_dir() . '/updates', 0750, true);
    @file_put_contents(uc_update_data_dir() . '/updates/last-result.json', json_encode($r + ['at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}

/**
 * Termina una actualización pendiente si la hay. null = nada que hacer; 'ok' = terminó (la aplicación ya está cargada);
 * 'rolled_back' = no terminó y se volvió atrás.
 */
function uc_update_finish_entry(): ?string
{
    $data = uc_update_data_dir();
    $pf = "$data/updates/pending.json";
    if (is_link("$data/updates") || !uc_update_own_file($pf)) return null;
    $p = json_decode((string) @file_get_contents($pf), true);
    if (!is_array($p) || !uc_update_may_finish($p, uc_update_uid())) return null;
    $lock = uc_update_lock_open("$data/updates/finish.lock");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;
    $p = json_decode((string) @file_get_contents($pf), true);
    if (!uc_update_own_file($pf) || !is_array($p) || !uc_update_may_finish($p, uc_update_uid()) || !preg_match('/^[0-9a-z][0-9a-z.-]*$/', (string) ($p['id'] ?? ''))
        || is_link("$data/updates/{$p['id']}")) { flock($lock, LOCK_UN); return null; }
    if (($p['stage'] ?? '') === 'swapping') {
        // El proceso que cambiaba los archivos murió a mitad: si ya no tiene el cerrojo, se deshace (la base no se tocó).
        $ul = uc_update_lock_open("$data/updates/update.lock");
        if ($ul && flock($ul, LOCK_EX | LOCK_NB)) {
            $GLOBALS['uc_update_pending'] = $p;
            $GLOBALS['uc_update_error'] = 'la actualización se interrumpió mientras cambiaba los archivos';
            $GLOBALS['uc_update_no_db'] = true;
            uc_update_finish_shutdown();
            flock($ul, LOCK_UN);
        }
        flock($lock, LOCK_UN);
        return 'rolled_back';
    }
    if (($p['stage'] ?? '') !== 'swapped') { flock($lock, LOCK_UN); return null; }
    $GLOBALS['uc_update_pending'] = $p;
    $GLOBALS['uc_update_lock'] = $lock;
    register_shutdown_function('uc_update_finish_shutdown');
    try {
        require_once dirname(__DIR__) . '/app/bootstrap.php';
        ensure_schema();                 // migraciones de la versión nueva
        update_finish_after_boot($p);    // app/update.php: comprobaciones y prueba de salud; lanza si algo no cuadra
    } catch (Throwable $e) {
        $GLOBALS['uc_update_error'] = $e->getMessage() . ' (' . str_replace(dirname(__DIR__) . '/', '', $e->getFile()) . ':' . $e->getLine() . ')';
        if (PHP_SAPI === 'cli') { uc_update_finish_shutdown(); return 'rolled_back'; }
        exit(1); // la función de cierre vuelve a la versión anterior
    }
    $GLOBALS['uc_update_ok'] = true;
    @flock($lock, LOCK_UN);
    return 'ok';
}

/** Si la actualización no terminó bien, vuelve a la versión anterior (código y base de datos) y lo explica. */
function uc_update_finish_shutdown(): void
{
    $p = $GLOBALS['uc_update_pending'] ?? null;
    if (!$p || !empty($GLOBALS['uc_update_ok']) || !empty($GLOBALS['uc_update_done'])) return;
    $GLOBALS['uc_update_done'] = true;
    $data = uc_update_data_dir();
    $dir = "$data/updates/{$p['id']}";
    $err = error_get_last();
    $why = (string) ($GLOBALS['uc_update_error'] ?? '');
    if ($why === '' && $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $why = $err['message'] . ' (' . str_replace(dirname(__DIR__) . '/', '', (string) $err['file']) . ':' . $err['line'] . ')';
    }
    if ($why === '') $why = 'la versión nueva no terminó de arrancar';
    // La vuelta atrás guardada del código viejo, solo si es un archivo normal de este mismo usuario; si no, la instalada.
    if (uc_update_own_file("$dir/rollback.php")) require_once "$dir/rollback.php";
    $fn = function_exists('uc_update_prev_rollback') ? 'uc_update_prev_rollback' : null;
    if ($fn === null) { require_once __DIR__ . '/update_rollback.php'; $fn = 'uc_update_rollback'; }
    $res = $fn($dir, dirname(__DIR__), $data, empty($GLOBALS['uc_update_no_db']));
    @unlink("$data/updates/pending.json");
    @unlink("$data/maintenance.json");
    uc_update_rmtree($dir);
    uc_update_write_result(['ok' => false, 'from' => $p['from'], 'to' => $p['to'], 'rolled_back' => true,
        'error' => mb_substr((string) preg_replace('/\s+/', ' ', $why), 0, 600), 'rollback' => $res,
        'message' => "La actualización a la {$p['to']} ha fallado y el configurador ha vuelto solo a la {$p['from']}, con sus datos tal cual estaban. Motivo: " . mb_substr($why, 0, 300)]);
    if (!empty($GLOBALS['uc_update_lock'])) @flock($GLOBALS['uc_update_lock'], LOCK_UN);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "La actualización falló y se ha vuelto a la {$p['from']}. Motivo: $why\n"); return; }
    if (!headers_sent()) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        $admin = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/admin');
        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Actualización</title>'
            . '<body style="font:16px/1.6 system-ui;max-width:34rem;margin:15vh auto;padding:0 1rem">'
            . ($admin ? '<h1>La actualización ha fallado</h1><p>El configurador ha vuelto solo a la versión anterior. <a href="/admin/actualizaciones">Ver qué ha pasado</a></p>'
                : '<h1>Un momento</h1><p>Vuelve a cargar la página.</p>');
    }
}

}
