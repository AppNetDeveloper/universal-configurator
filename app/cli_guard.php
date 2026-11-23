<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * root nunca ejecuta bin/uc: la aplicación carga archivos de data/ (config.php, data/updates/…/rollback.php) que el
 * servidor web puede escribir, y root ejecutándolos convertiría cualquier fallo del servidor web en root.
 * Única excepción: `install`, que ANTES de tocar nada de data/ pasa a ser el dueño de data/ (el servidor web).
 * Archivo autónomo: bin/uc lo carga antes que nada.
 */

/** Usuario efectivo de este proceso (-1 si no se puede saber). */
function uc_cli_euid(): int
{
    if (function_exists('posix_geteuid')) return posix_geteuid();
    $st = @file_get_contents('/proc/self/status');
    return is_string($st) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $st, $m) ? (int) $m[1] : -1;
}

/** null = puede seguir; si no, el motivo para no hacerlo. Para `install` como root, baja al dueño de data/. */
function uc_cli_drop_root(string $cmd): ?string
{
    if (uc_cli_euid() !== 0) return null;
    $how = 'sudo -u www-data php bin/uc ' . ($cmd !== '' ? $cmd : '…');
    if ($cmd !== 'install') {
        return "no ejecutes bin/uc como root: carga archivos de data/ que el servidor web puede cambiar. Hazlo como el usuario del servidor web: $how";
    }
    $data = getenv('UC_DATA') ?: dirname(__DIR__) . '/data';
    if (!is_dir($data) || is_link($data)) return "falta la carpeta de datos ($data). Créala y dásela al servidor web (sudo mkdir -p data && sudo chown www-data: data), o instala con: $how";
    $uid = (int) fileowner($data);
    $gid = (int) filegroup($data);
    if ($uid === 0) return "la carpeta de datos es de root: dásela al usuario del servidor web (sudo chown -R www-data: data) o instala con: $how";
    if (!function_exists('posix_setuid') || !function_exists('posix_initgroups') || !($pw = posix_getpwuid($uid))) {
        return "como root no se puede instalar aquí (no se puede pasar al dueño de data/). Instala con: $how";
    }
    // Primero los grupos extra, luego el grupo y por último el usuario (después ya no se puede cambiar nada).
    if (!posix_initgroups((string) $pw['name'], $gid) || !posix_setgid($gid) || !posix_setuid($uid)) return "no se ha podido pasar al usuario {$pw['name']}. Instala con: $how";
    if (posix_geteuid() !== $uid || posix_getuid() !== $uid || posix_getegid() !== $gid) return "sigue con permisos de root: no se instala. Instala con: $how";
    return null;
}
