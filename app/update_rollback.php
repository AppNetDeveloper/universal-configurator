<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Vuelta atrás de una actualización. Archivo AUTÓNOMO: no usa ninguna función de la aplicación, porque se ejecuta
 * cuando el código nuevo ha fallado (o desde la copia guardada antes de actualizar, data/updates/<id>/rollback.php,
 * con los nombres cambiados a uc_update_prev_*). Mismo patrón que Universal Web.
 *
 * En data/updates/<id>/ hay:
 *   code/                los archivos de la versión anterior que la actualización iba a cambiar o borrar
 *   added.txt            los archivos que la versión nueva añadió (se borran)
 *   configurator.sqlite  copia de la base de datos justo antes de actualizar (solo se repone si $withDb)
 */

if (!function_exists('uc_update_rollback')) {
    /** ¿Ruta relativa segura dentro del código? Nunca data/, .git ni rutas raras. */
    function uc_update_safe_rel(string $rel): bool
    {
        return $rel !== '' && $rel[0] !== '/' && !str_contains($rel, '..') && !str_contains($rel, "\0") && !str_contains($rel, '\\')
            && !preg_match('#^(data|\.git)(/|$)#', $rel);
    }

    /** Copia un archivo en su sitio de forma atómica (temporal + rename) conservando permisos. */
    function uc_update_put(string $from, string $to): bool
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
        $tmp = $to . '.uctmp' . bin2hex(random_bytes(3));
        if (!@copy($from, $tmp)) return false;
        @chmod($tmp, (@fileperms($from) ?: 0644) & 0777);
        if (!@rename($tmp, $to)) { @unlink($tmp); return false; }
        return true;
    }

    /**
     * Restaura el código (y la base de datos si $withDb) guardados en $dir. Sigue aunque falle un archivo: mejor volver
     * casi entero que quedarse a medias. @return array{restored:int, removed:int, db:bool, errors:list<string>}
     */
    function uc_update_rollback(string $dir, string $root, string $dataDir, bool $withDb): array
    {
        $out = ['restored' => 0, 'removed' => 0, 'db' => false, 'errors' => []];
        foreach (is_file("$dir/added.txt") ? (file("$dir/added.txt", FILE_IGNORE_NEW_LINES) ?: []) : [] as $rel) {
            $rel = trim($rel);
            if (!uc_update_safe_rel($rel) || !is_file("$root/$rel")) continue;
            if (@unlink("$root/$rel")) $out['removed']++; else $out['errors'][] = "No se pudo borrar $rel";
        }
        $code = "$dir/code";
        if (is_dir($code)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($code, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->isLink()) continue;
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($code) + 1));
                if (!uc_update_safe_rel($rel)) continue;
                if (uc_update_put($f->getPathname(), "$root/$rel")) $out['restored']++; else $out['errors'][] = "No se pudo reponer $rel";
            }
        }
        if ($withDb && is_file("$dir/configurator.sqlite")) {
            $db = "$dataDir/configurator.sqlite";
            $tmp = "$db.ucrestore";
            if (@copy("$dir/configurator.sqlite", $tmp)) {
                @unlink("$db-wal");
                @unlink("$db-shm");
                $out['db'] = @rename($tmp, $db);
                if (!$out['db']) $out['errors'][] = 'No se pudo reponer la base de datos';
            } else {
                $out['errors'][] = 'No se pudo copiar la base de datos guardada';
            }
        }
        if (function_exists('opcache_reset')) @opcache_reset();
        return $out;
    }
}
