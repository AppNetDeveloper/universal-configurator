<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Copias de seguridad (mismo patrón que Universal Web, en .zip):
 *  - Qué lleva: la base de datos (VACUUM INTO: copia consistente, nunca el archivo en caliente), data/config.php (la
 *    clave secreta con la que se cifra la contraseña SMTP y se firman cookies y enlaces: la copia es SENSIBLE), las
 *    imágenes subidas (data/uploads) y manifest.json (aplicación, versión, esquema, fecha y sha256 de cada archivo).
 *  - Dónde: data/backups/ (fuera de public/; se descargan solo desde el panel, con sesión de administrador).
 *  - Cifrado opcional AL DESCARGAR con una contraseña: libsodium (Argon2id + XChaCha20-Poly1305 por trozos).
 *  - Restaurar (panel o `bin/uc restore`): valida rutas (ni ../ ni absolutas ni enlaces), tamaños, el manifest y cada
 *    sha256, la integridad de la base y que su esquema no sea más NUEVO que el código; antes hace una copia del estado
 *    actual, entra en mantenimiento, sustituye, migra y sale. Lo que falla antes de sustituir no cambia nada.
 *  - Automáticas (opcional): diaria o semanal con retención, por `bin/uc backup --auto` (cron) o al paso tras una visita.
 */

const BK_PREFIX = 'uc-copia-';
const BK_MAGIC = 'UCBK1';
const BK_MAX_UPLOAD = 512 * 1024 * 1024;       // .zip o cifrada que se sube para restaurar
const BK_MAX_UNPACKED = 2 * 1024 * 1024 * 1024; // anti «zip bomb»
const BK_MAX_ENTRIES = 20000;
const BK_CHUNK = 65536;
/** Lo único que puede traer una copia. Todo lo demás (../, rutas absolutas, otros nombres) la invalida. */
const BK_ALLOWED = '#^(manifest\.json|configurator\.sqlite|config\.php|uploads/[a-f0-9]{24}\.(png|jpg|webp))$#';

function bk_dir(): string
{
    $d = UC_DATA . '/backups';
    if (!is_dir($d)) { @mkdir($d, 0750, true); @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n"); }
    return $d;
}

function bk_work(string $prefix): string
{
    $d = bk_dir() . '/.' . $prefix . '-' . bin2hex(random_bytes(6));
    if (!@mkdir($d, 0700, true)) throw new UcError(t('No se puede crear la carpeta temporal en data/backups.'));
    return $d;
}

function bk_rmtree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($path);
}

function bk_human_size(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return number_format($b / 1024, 0, ',', '.') . ' KB';
    return number_format($b / 1048576, 1, ',', '.') . ' MB';
}

/** Nombre seguro de una copia de la lista (nada de rutas). */
function bk_valid_name(string $name): bool
{
    return (bool) preg_match('/^' . preg_quote(BK_PREFIX, '/') . '\d{8}-\d{6}(-[a-z0-9]{1,12})?\.zip$/', $name);
}

function backup_path(string $name): string
{
    if (!bk_valid_name($name) || !is_file(bk_dir() . '/' . $name)) throw new UcError(t('No existe esa copia.'), [], 404);
    return bk_dir() . '/' . $name;
}

// ---------------------------------------------------------------- crear

/** Crea una copia y devuelve sus datos (name, size, version, schema, created_at, auto…). */
function backup_create(bool $auto = false, string $tag = ''): array
{
    if (!class_exists('ZipArchive')) throw new UcError(t('Este PHP no tiene la extensión zip (ZipArchive): no se pueden hacer copias.'));
    @set_time_limit(0);
    $work = bk_work('crear');
    try {
        $files = [];
        $db = "$work/configurator.sqlite";
        db()->exec('VACUUM INTO ' . db()->quote($db)); // copia consistente, aunque haya escrituras a la vez
        $files['configurator.sqlite'] = $db;
        if (is_file(UC_DATA . '/config.php')) $files['config.php'] = UC_DATA . '/config.php';
        foreach (glob(uploads_dir() . '/*') ?: [] as $f) {
            $base = basename($f);
            if (is_file($f) && !is_link($f) && preg_match(BK_ALLOWED, "uploads/$base")) $files["uploads/$base"] = $f;
        }
        $manifest = ['app' => 'universal-configurator', 'format' => 1, 'version' => UC_VERSION, 'schema' => UC_SCHEMA, 'created_at' => date('c'),
            'created_by' => Actor::$name, 'auto' => $auto, 'includes_config' => isset($files['config.php']),
            'counts' => ['configurations' => (int) val('SELECT COUNT(*) FROM configurations'), 'nodes' => (int) val('SELECT COUNT(*) FROM nodes'),
                'users' => (int) val('SELECT COUNT(*) FROM users'), 'uploads' => count($files) - 1 - (int) isset($files['config.php'])],
            'note' => 'Incluye data/config.php (la clave secreta de la instalación): guarda esta copia como una contraseña.', 'files' => []];
        foreach ($files as $rel => $abs) $manifest['files'][$rel] = ['sha256' => hash_file('sha256', $abs), 'size' => (int) filesize($abs)];
        $name = BK_PREFIX . date('Ymd-His') . ($auto ? '-auto' : ($tag !== '' ? '-' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)) : '')) . '.zip';
        $n = 1;
        while (is_file(bk_dir() . "/$name")) $name = preg_replace('/(\.zip)$/', '', $name) . ($n++) . '.zip';
        $tmp = "$work/out.zip";
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new UcError(t('No se pudo crear el archivo de la copia.'));
        $z->addFromString('manifest.json', json_enc($manifest, true));
        foreach ($files as $rel => $abs) $z->addFile($abs, $rel);
        if (!$z->close()) throw new UcError(t('No se pudo escribir el archivo de la copia.'));
        if (!@rename($tmp, bk_dir() . "/$name")) throw new UcError(t('No se pudo guardar la copia en data/backups.'));
        @chmod(bk_dir() . "/$name", 0640);
    } finally {
        bk_rmtree($work);
    }
    $info = ['name' => $name, 'size' => (int) filesize(bk_dir() . "/$name")] + array_diff_key($manifest, ['files' => 1, 'note' => 1]);
    audit($auto ? 'backup.auto' : 'backup.create', $name . ' (' . bk_human_size($info['size']) . ')');
    if ($auto) bk_retention();
    return $info;
}

/** Copias automáticas: se conservan las N más nuevas (las manuales no se borran solas). */
function bk_retention(): void
{
    $keep = max(1, min(365, (int) setting('backup_keep')));
    $auto = array_values(array_filter(backup_list(), fn($b) => $b['auto']));
    foreach (array_slice($auto, $keep) as $b) { @unlink(bk_dir() . '/' . $b['name']); audit('backup.retention', $b['name']); }
}

/** @return list<array{name:string,size:int,version:?string,schema:?int,created_at:string,auto:bool}> más nuevas primero */
function backup_list(): array
{
    $out = [];
    foreach (glob(bk_dir() . '/' . BK_PREFIX . '*.zip') ?: [] as $f) {
        $name = basename($f);
        if (!bk_valid_name($name)) continue;
        $m = null;
        $z = new ZipArchive();
        if ($z->open($f) === true) { $m = json_decode((string) $z->getFromName('manifest.json'), true); $z->close(); }
        $out[] = ['name' => $name, 'size' => (int) filesize($f), 'version' => is_array($m) ? (string) ($m['version'] ?? '') : null,
            'schema' => is_array($m) ? (int) ($m['schema'] ?? 0) : null, 'created_at' => is_array($m) ? (string) ($m['created_at'] ?? '') : date('c', (int) filemtime($f)),
            'auto' => str_contains($name, '-auto'), 'by' => is_array($m) ? (string) ($m['created_by'] ?? '') : ''];
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function backup_delete(string $name): void
{
    $p = backup_path($name);
    if (!@unlink($p)) throw new UcError(t('No se pudo borrar la copia.'));
    audit('backup.delete', $name);
}

// ---------------------------------------------------------------- cifrado (al descargar)

/** Cifra $src en $dst con una contraseña: «UCBK1» + sal + cabecera de secretstream + trozos (Argon2id + XChaCha20-Poly1305). */
function bk_encrypt_file(string $src, string $dst, string $password): void
{
    if (mb_strlen($password) < 10) throw new UcError(t('La contraseña de la copia debe tener al menos 10 caracteres.'));
    if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) throw new UcError(t('Este PHP no tiene libsodium: no se puede cifrar.'));
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $password, $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $in = fopen($src, 'rb');
    $out = fopen($dst, 'wb');
    if (!$in || !$out) throw new UcError(t('No se pudo leer o escribir la copia cifrada.'));
    fwrite($out, BK_MAGIC . $salt . $header);
    $size = (int) filesize($src);
    $done = 0;
    do {
        $chunk = (string) fread($in, BK_CHUNK);
        $done += strlen($chunk);
        $tag = $done >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
    } while ($done < $size);
    fclose($in);
    fclose($out);
    sodium_memzero($key);
}

function bk_decrypt_file(string $src, string $dst, string $password): void
{
    $in = fopen($src, 'rb');
    if (!$in) throw new UcError(t('No se puede leer la copia.'));
    try {
        $head = (string) fread($in, 5 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (!str_starts_with($head, BK_MAGIC)) throw new UcError(t('El archivo no es una copia cifrada del configurador.'));
        if ($password === '') throw new UcError(t('Esta copia está cifrada: escribe su contraseña.'));
        $salt = substr($head, 5, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = substr($head, 5 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $password, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        sodium_memzero($key);
        $out = fopen($dst, 'wb');
        $final = false;
        while (!feof($in)) {
            $c = (string) fread($in, BK_CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($c === '') break;
            $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
            if ($r === false) { fclose($out); @unlink($dst); throw new UcError(t('Contraseña incorrecta o copia dañada.')); }
            fwrite($out, $r[0]);
            if ($r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { $final = true; break; }
        }
        fclose($out);
        if (!$final) { @unlink($dst); throw new UcError(t('La copia cifrada está incompleta.')); }
    } finally {
        fclose($in);
    }
}

// ---------------------------------------------------------------- validar y restaurar

/**
 * Comprueba una copia (zip en claro) y la extrae en $work: solo nombres permitidos, sin enlaces, con tope de tamaño;
 * manifest de esta aplicación, esquema no más nuevo que el código, cada archivo con su sha256 y la base íntegra.
 * @return array el manifest
 */
function bk_validate_extract(string $zipFile, string $work): array
{
    $z = new ZipArchive();
    if ($z->open($zipFile) !== true) throw new UcError(t('El archivo no es un .zip válido.'));
    try {
        if ($z->numFiles > BK_MAX_ENTRIES) throw new UcError(t('La copia tiene demasiados archivos ({n}).', ['n' => $z->numFiles]));
        $names = [];
        $total = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = (string) $st['name'];
            if (!preg_match(BK_ALLOWED, $name)) throw new UcError(t('La copia trae una ruta no permitida: «{n}». No se ha cambiado nada.', ['n' => mb_substr($name, 0, 120)]));
            $z->getExternalAttributesIndex($i, $os, $attr);
            if ((($attr >> 16) & 0170000) === 0120000) throw new UcError(t('La copia trae un enlace simbólico: «{n}». No se ha cambiado nada.', ['n' => $name]));
            if (isset($names[$name])) throw new UcError(t('La copia trae «{n}» dos veces.', ['n' => $name]));
            $names[$name] = $i;
            $total += (int) $st['size'];
            if ($total > BK_MAX_UNPACKED) throw new UcError(t('La copia descomprimida es demasiado grande.'));
        }
        $m = json_decode((string) $z->getFromName('manifest.json'), true);
        if (!is_array($m) || ($m['app'] ?? '') !== 'universal-configurator' || !is_array($m['files'] ?? null)) throw new UcError(t('Esta no es una copia del configurador (falta su manifest.json).'));
        if ((int) ($m['schema'] ?? 0) > UC_SCHEMA) {
            throw new UcError(t('La copia es de una versión más nueva ({v}, esquema {s}) que la instalada ({iv}, esquema {is}): actualiza antes de restaurarla.',
                ['v' => (string) ($m['version'] ?? '?'), 's' => (int) $m['schema'], 'iv' => UC_VERSION, 'is' => UC_SCHEMA]));
        }
        if (!isset($names['configurator.sqlite'])) throw new UcError(t('La copia no trae la base de datos.'));
        $listed = array_keys($m['files']);
        $present = array_values(array_diff(array_keys($names), ['manifest.json']));
        sort($listed);
        sort($present);
        if ($listed !== $present) throw new UcError(t('Los archivos de la copia no coinciden con su manifest.json.'));
        foreach ($present as $rel) {
            $dest = "$work/$rel";
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
            $in = $z->getStream($rel);
            $out = fopen($dest, 'wb');
            if (!$in || !$out) throw new UcError(t('No se pudo extraer «{n}».', ['n' => $rel]));
            $ctx = hash_init('sha256');
            $size = 0;
            while (!feof($in)) {
                $c = (string) fread($in, BK_CHUNK);
                $size += strlen($c);
                if ($size > BK_MAX_UNPACKED) throw new UcError(t('La copia descomprimida es demasiado grande.'));
                hash_update($ctx, $c);
                fwrite($out, $c);
            }
            fclose($in);
            fclose($out);
            if (!hash_equals((string) ($m['files'][$rel]['sha256'] ?? ''), hash_final($ctx))) {
                throw new UcError(t('La huella (sha256) de «{n}» no coincide: la copia está dañada o la han cambiado. No se ha cambiado nada.', ['n' => $rel]));
            }
        }
    } finally {
        $z->close();
    }
    try {
        $chk = new PDO('sqlite:' . "$work/configurator.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ok = $chk->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && $chk->query("SELECT 1 FROM sqlite_master WHERE name = 'configurations'")->fetchColumn();
        $chk = null;
    } catch (Throwable) { $ok = false; }
    if (!$ok) throw new UcError(t('La base de datos de la copia está dañada.'));
    return $m;
}

/**
 * Guarda en data/backups un archivo subido (zip, o cifrado con su contraseña) tras comprobarlo entero. Devuelve el
 * nombre con el que queda en la lista. $fromHttp = false solo en pruebas y en la línea de órdenes.
 */
function backup_import(string $path, string $password = '', bool $fromHttp = false): string
{
    if (!is_file($path) || ($fromHttp && !is_uploaded_file($path))) throw new UcError(t('No se pudo leer el archivo subido.'));
    if (filesize($path) > BK_MAX_UPLOAD) throw new UcError(t('El archivo pesa más de {m}: no se admite.', ['m' => bk_human_size(BK_MAX_UPLOAD)]));
    $work = bk_work('importar');
    try {
        $zip = $path;
        $fh = fopen($path, 'rb');
        $head = (string) fread($fh, 5);
        fclose($fh);
        if ($head === BK_MAGIC) { bk_decrypt_file($path, "$work/plain.zip", $password); $zip = "$work/plain.zip"; }
        elseif (strncmp($head, "PK\x03\x04", 4) !== 0) throw new UcError(t('El archivo no es una copia del configurador (.zip o cifrada).'));
        bk_validate_extract($zip, "$work/x");
        $name = BK_PREFIX . date('Ymd-His') . '-subida.zip';
        $n = 1;
        while (is_file(bk_dir() . "/$name")) $name = BK_PREFIX . date('Ymd-His') . '-subida' . ($n++) . '.zip';
        if (!@copy($zip, bk_dir() . "/$name")) throw new UcError(t('No se pudo guardar la copia subida.'));
        @chmod(bk_dir() . "/$name", 0640);
    } finally {
        bk_rmtree($work);
    }
    audit('backup.upload', $name);
    return $name;
}

/**
 * Restaura una copia de la lista. Exige escribir «RESTAURAR». Antes: copia del estado actual y mantenimiento.
 * $withConfig: también data/config.php (solo si la copia viene de otra instalación: sin su clave no se descifran la
 * contraseña SMTP ni valen los enlaces firmados de allí). Devuelve ['safety' => copia previa, 'warnings' => […]].
 */
function backup_restore(string $name, string $confirm, bool $withConfig = false): array
{
    if (trim($confirm) !== 'RESTAURAR') throw new UcError(t('Para restaurar escribe RESTAURAR (en mayúsculas). No se ha cambiado nada.'));
    $src = backup_path($name);
    @set_time_limit(0);
    $work = bk_work('restaurar');
    $warnings = [];
    try {
        $m = bk_validate_extract($src, "$work/x");
        $safety = backup_create(false, 'antes');
        uc_maintenance_on('restore', 10);
        try {
            // Base de datos: se vacía el WAL de la conexión actual, se cierra y se sustituye el archivo.
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            db_close();
            $dbPath = db_path();
            if (!@rename("$work/x/configurator.sqlite", $dbPath) && !@copy("$work/x/configurator.sqlite", $dbPath)) throw new UcError(t('No se pudo sustituir la base de datos.'));
            @unlink("$dbPath-wal");
            @unlink("$dbPath-shm");
            // Imágenes: la carpeta entera, y si falla, la de antes.
            $up = uploads_dir();
            $old = "$work/uploads-antes";
            $new = "$work/x/uploads";
            if (!is_dir($new)) mkdir($new, 0750, true);
            @rename($up, $old);
            if (!@rename($new, $up)) { @rename($old, $up); throw new UcError(t('No se pudieron reponer las imágenes.')); }
            if ($withConfig && is_file("$work/x/config.php")) {
                if (!@copy("$work/x/config.php", UC_DATA . '/config.php')) throw new UcError(t('No se pudo reponer config.php.'));
            } elseif (!empty($m['includes_config'])) {
                $warnings[] = t('Se ha conservado la clave de esta instalación (config.php). Si la copia viene de otra, la contraseña SMTP de allí no se podrá leer: vuelve a escribirla en Ajustes.');
            }
            ensure_schema(); // una copia de una versión anterior se pone al día
        } finally {
            uc_maintenance_off();
        }
    } finally {
        bk_rmtree($work);
    }
    audit('backup.restore', $name . ' (copia previa: ' . $safety['name'] . ')');
    return ['safety' => $safety['name'], 'warnings' => $warnings, 'version' => (string) ($m['version'] ?? '')];
}

// ---------------------------------------------------------------- automáticas

/** ¿Toca la copia automática? (diaria: 24 h; semanal: 7 días desde la última automática). */
function backup_auto_due(): bool
{
    $mode = setting('backup_auto');
    if (!in_array($mode, ['daily', 'weekly'], true)) return false;
    $last = (int) val("SELECT value FROM meta WHERE key = 'last_auto_backup'");
    return $last <= time() - ($mode === 'weekly' ? 7 * 86400 : 86400) + 300;
}

/** Al paso (tras responder) o desde cron: hace la copia automática si toca. Nunca lanza. */
function backup_auto_tick(): ?array
{
    try {
        if (!backup_auto_due()) return null;
        $lock = @fopen(bk_dir() . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;
        try {
            if (!backup_auto_due()) return null;
            // Si falla, se reintenta dentro de una hora y no en cada visita.
            q("INSERT OR REPLACE INTO meta (key, value) VALUES ('last_auto_backup', ?)", [(string) (time() - 86400 + 3600)]);
            $prev = Actor::$name;
            Actor::set('copia automática');
            try {
                $b = backup_create(true);
                q("INSERT OR REPLACE INTO meta (key, value) VALUES ('last_auto_backup', ?)", [(string) time()]);
                return $b;
            } finally {
                Actor::set($prev);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $ex) {
        error_log('[universal-configurator] copia automática: ' . $ex->getMessage());
        return null;
    }
}
