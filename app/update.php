<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Actualizaciones desde el panel (mismo patrón que Universal Web).
 *
 *  1. Buscar: lista de versiones públicas de github.com/AppNetDeveloper/universal-configurator. Es la ÚNICA llamada de
 *     salida de la aplicación y solo ocurre cuando el administrador pulsa «Buscar actualizaciones» o si ha activado
 *     «buscar sola una vez al día». La app no llama a casa por su cuenta.
 *  2. Cada versión publica un paquete .zip y un «sobre» .update.json: {payload: base64(JSON), sig: base64(firma)}. El
 *     JSON lleva versión, sha256 y tamaño del zip y el resumen de cambios; la firma es ed25519 (libsodium) con la clave
 *     cuya parte pública está en UPDATE_PUBLIC_KEY. Sin firma válida o con otra huella, no se instala nunca. Tampoco una
 *     versión igual o anterior.
 *  3. Actualizar (fase 1, con el código viejo): copia de seguridad → mantenimiento → copia exacta de la base → zip
 *     verificado (firma, sha256, tamaño, rutas, enlaces, sintaxis PHP) → copia del código que se va a tocar → reemplazo
 *     archivo a archivo de app/, public/, bin/… Nunca data/.
 *  4. Fase 2 (app/update_finish.php, proceso nuevo con el código nuevo): migraciones y prueba de salud; si algo falla,
 *     vuelve sola a la versión anterior (código y base de datos).
 */

const UPDATE_REPO = 'AppNetDeveloper/universal-configurator';
const UPDATE_RELEASES_URL = 'https://api.github.com/repos/' . UPDATE_REPO . '/releases?per_page=30';
/** Clave pública ed25519 de BionicAdri para firmar las versiones (la privada nunca está en el repositorio). */
const UPDATE_PUBLIC_KEY = 'uqQ/Va/SwQOap5nXdpdLE0LUsMF6B51c6+NfGMmtZ5s=';
const UPDATE_MAX_ZIP = 40 * 1024 * 1024;
const UPDATE_MAX_FILES = 3000;
const UPDATE_ROOT_FILES = ['README.md', 'LICENSE', 'install.sh', '.htaccess', '.gitignore'];
const UPDATE_ROOT_DIRS = ['app', 'bin', 'deploy', 'docs', 'public'];

function update_dir(): string
{
    $d = UC_DATA . '/updates';
    if (!is_dir($d)) { @mkdir($d, 0750, true); @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n"); }
    return $d;
}

function update_state(): array
{
    $s = json_decode((string) val("SELECT value FROM meta WHERE key = 'update_state'"), true);
    return (is_array($s) ? $s : []) + ['checked_at' => 0, 'latest' => null, 'error' => ''];
}

function update_state_save(array $s): void
{
    q("INSERT OR REPLACE INTO meta (key, value) VALUES ('update_state', ?)", [json_enc($s)]);
}

/** ¿$new es más nueva que $cur? (x.y.z) */
function update_newer(string $cur, string $new): bool
{
    return (bool) preg_match('/^\d+\.\d+\.\d+$/', $new) && version_compare($new, $cur, '>');
}

/**
 * Origen de la lista de versiones: GitHub; para pruebas y la línea de órdenes, otro con UC_UPDATE_FEED (un servidor de
 * pruebas local). Las descargas solo van a GitHub, salvo con ese mismo origen de pruebas.
 */
function update_feed(): string
{
    return (string) (getenv('UC_UPDATE_FEED') ?: UPDATE_RELEASES_URL);
}

function update_url_allowed(string $url): bool
{
    $feed = (string) getenv('UC_UPDATE_FEED');
    if ($feed !== '' && str_starts_with($url, (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $feed) . '/')) return true;
    $host = (string) parse_url($url, PHP_URL_HOST);
    return str_starts_with($url, 'https://') && in_array($host, ['api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'], true);
}

/** Descarga con tope de tamaño y tiempo. A un archivo si $toFile; si no, devuelve el contenido. */
function update_http(string $url, int $max, int $timeout, ?string $toFile = null): string
{
    if (!update_url_allowed($url)) throw new UcError(t('Dirección de descarga no permitida: {u}', ['u' => mb_substr($url, 0, 120)]));
    if (!function_exists('curl_init')) throw new UcError(t('Este PHP no tiene la extensión curl: no se puede buscar ni descargar actualizaciones.'));
    $fh = $toFile ? fopen($toFile, 'wb') : null;
    $body = '';
    $size = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('UC_UPDATE_FEED') ? CURLPROTO_HTTP : 0), CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | (getenv('UC_UPDATE_FEED') ? CURLPROTO_HTTP : 0),
        CURLOPT_USERAGENT => 'universal-configurator/' . UC_VERSION, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, application/octet-stream, */*'],
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$size, $max, $fh) {
            $size += strlen($chunk);
            if ($size > $max) return 0; // corta: demasiado grande
            $fh ? fwrite($fh, $chunk) : $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($size > $max) throw new UcError(t('La descarga pasa de {m}: se corta.', ['m' => bk_human_size($max)]));
    if ($ok === false || $code !== 200) throw new UcError(t('No se pudo descargar ({c}{e}).', ['c' => $code ?: 'sin respuesta', 'e' => $err !== '' ? ': ' . $err : '']));
    return $body;
}

/** La versión más nueva instalable de una lista con el formato de GitHub, o null. */
function update_pick(array $releases, string $current): ?array
{
    $best = null;
    foreach ($releases as $r) {
        if (!is_array($r) || !empty($r['draft']) || !empty($r['prerelease'])) continue;
        $v = ltrim((string) ($r['tag_name'] ?? ''), 'v');
        if (!update_newer($current, $v)) continue;
        $assets = [];
        foreach ((array) ($r['assets'] ?? []) as $a) if (is_array($a)) $assets[(string) ($a['name'] ?? '')] = (string) ($a['browser_download_url'] ?? '');
        $env = $assets["universal-configurator-$v.update.json"] ?? '';
        $zip = $assets["universal-configurator-$v.zip"] ?? '';
        if ($env === '' || $zip === '') continue; // sin sobre firmado no se puede instalar desde el panel
        if ($best === null || version_compare($v, $best['version'], '>')) $best = ['version' => $v, 'envelope_url' => $env, 'zip_url' => $zip, 'url' => (string) ($r['html_url'] ?? '')];
    }
    return $best;
}

/** Busca versión nueva (y la guarda). Nunca lanza: sin red, guarda el error. Las notas salen del sobre FIRMADO. */
function update_check(): array
{
    $st = update_state();
    $st['checked_at'] = time();
    try {
        $list = json_decode(update_http(update_feed(), 2 * 1024 * 1024, 10), true);
        if (!is_array($list) || !array_is_list($list)) throw new UcError(t('Respuesta no válida de la lista de versiones.'));
        $latest = update_pick($list, UC_VERSION);
        if ($latest) {
            $m = update_verify_envelope(update_http($latest['envelope_url'], 256 * 1024, 10));
            if ($m['version'] !== $latest['version']) throw new UcError(t('El sobre firmado no corresponde a la versión {v}.', ['v' => $latest['version']]));
            $latest += ['changelog' => mb_substr((string) ($m['changelog'] ?? ''), 0, 20000), 'released_at' => (string) ($m['released_at'] ?? ''), 'size' => (int) $m['size']];
        }
        $st['latest'] = $latest;
        $st['error'] = '';
    } catch (Throwable $e) {
        $st['error'] = mb_substr($e->getMessage(), 0, 300);
    }
    update_state_save($st);
    audit('update.check', $st['error'] !== '' ? 'error: ' . $st['error'] : ($st['latest'] ? 'nueva: ' . $st['latest']['version'] : 'al día'));
    return $st;
}

/** Comprueba el sobre firmado y devuelve el JSON de la versión. $publicKey solo en pruebas (un par desechable). */
function update_verify_envelope(string $raw, ?string $publicKey = null): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) throw new UcError(t('Este PHP no tiene libsodium: no se puede comprobar la firma.'));
    $env = json_decode($raw, true);
    if (!is_array($env) || !is_string($env['payload'] ?? null) || !is_string($env['sig'] ?? null)) throw new UcError(t('El sobre de la versión no tiene el formato esperado (payload y firma).'));
    $payload = base64_decode($env['payload'], true);
    $sig = base64_decode($env['sig'], true);
    $pub = base64_decode($publicKey ?? UPDATE_PUBLIC_KEY, true);
    if ($payload === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new UcError(t('Firma o clave con formato no válido.'));
    }
    if (!sodium_crypto_sign_verify_detached($sig, $payload, $pub)) throw new UcError(t('La firma del paquete NO es válida: no se instala. Puede estar dañado o no ser una versión oficial.'));
    $m = json_decode($payload, true);
    if (!is_array($m) || ($m['name'] ?? '') !== 'universal-configurator' || (int) ($m['format'] ?? 0) !== 1) throw new UcError(t('El paquete firmado no es del configurador.'));
    if (!preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/', (string) ($m['sha256'] ?? '')) || (int) ($m['size'] ?? 0) <= 0) {
        throw new UcError(t('Datos de la versión incompletos.'));
    }
    return $m;
}

/** ¿Puede este proceso escribir el código? [bool, motivo]. */
function update_can_write(): array
{
    foreach (['app', 'app/core.php', 'public', 'public/index.php', 'bin'] as $rel) {
        $f = UC_ROOT . '/' . $rel;
        if (file_exists($f) && !is_writable($f)) return [false, t('El servidor web no puede escribir en el código ({r}): así lo deja el instalador por seguridad. Actualiza desde la terminal: sudo php bin/uc update --yes', ['r' => $rel])];
    }
    if (!is_writable(UC_DATA)) return [false, t('No se puede escribir en la carpeta de datos.')];
    if (is_dir(UC_ROOT . '/.git')) return [false, t('Esta instalación es una copia de Git: actualízala con git (la etiqueta de la versión nueva), así no se pisa tu copia de trabajo.')];
    if (!class_exists('ZipArchive')) return [false, t('Este PHP no tiene la extensión zip.')];
    return [true, ''];
}

/** Valida el zip (rutas, enlaces, tamaño, versión, sintaxis PHP) y lo extrae en $dest. Devuelve archivo => permisos. */
function update_extract(string $zipFile, array $m, string $dest): array
{
    $z = new ZipArchive();
    if ($z->open($zipFile) !== true) throw new UcError(t('El paquete no es un zip válido.'));
    $files = [];
    $total = 0;
    try {
        if ($z->numFiles > UPDATE_MAX_FILES) throw new UcError(t('El paquete trae demasiados archivos.'));
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = (string) $st['name'];
            if (str_ends_with($name, '/')) continue;
            $top = explode('/', $name)[0];
            $okTop = in_array($top, UPDATE_ROOT_DIRS, true) ? str_contains($name, '/') : in_array($name, UPDATE_ROOT_FILES, true);
            if (!$okTop || !uc_update_safe_rel($name) || str_contains($name, '//')) throw new UcError(t('El paquete trae una ruta no permitida: {n}', ['n' => $name]));
            $z->getExternalAttributesIndex($i, $os, $attr);
            if ((($attr >> 16) & 0170000) === 0120000) throw new UcError(t('El paquete trae un enlace simbólico: {n}', ['n' => $name]));
            $total += (int) $st['size'];
            if ($total > 200 * 1024 * 1024) throw new UcError(t('El paquete descomprimido es demasiado grande.'));
            $files[$name] = (($attr >> 16) & 0111) ? 0755 : 0644;
        }
        if (!isset($files['app/core.php'], $files['public/index.php'], $files['app/bootstrap.php'])) throw new UcError(t('Al paquete le faltan archivos básicos (app/core.php, public/index.php).'));
        if (!preg_match("/const UC_VERSION = '" . preg_quote((string) $m['version'], '/') . "';/", (string) $z->getFromName('app/core.php'))) {
            throw new UcError(t('La versión del código no coincide con la del paquete firmado.'));
        }
        if (!@mkdir($dest, 0750, true) && !is_dir($dest)) throw new UcError(t('No se puede crear la carpeta temporal de la actualización.'));
        foreach ($files as $name => $mode) {
            $data = $z->getFromName($name);
            if ($data === false) throw new UcError(t('No se puede leer {n} del paquete.', ['n' => $name]));
            if (str_ends_with($name, '.php') || in_array($name, ['bin/uc', 'bin/release'], true)) {
                try { token_get_all($data, TOKEN_PARSE); } catch (ParseError $e) { throw new UcError(t('El paquete trae un archivo PHP con errores: {n} (línea {l})', ['n' => $name, 'l' => $e->getLine()])); }
            }
            if (!is_dir(dirname("$dest/$name"))) mkdir(dirname("$dest/$name"), 0750, true);
            file_put_contents("$dest/$name", $data);
            chmod("$dest/$name", $mode);
        }
    } finally {
        $z->close();
    }
    return $files;
}

/** Archivos de la versión instalada (los lista el paquete en app/release-files.json), o null si no se sabe. */
function update_installed_files(): ?array
{
    $j = json_decode((string) @file_get_contents(UC_ROOT . '/app/release-files.json'), true);
    return is_array($j) && is_array($j['files'] ?? null) ? $j['files'] : null;
}

/**
 * Fase 1 con un sobre y su zip ya descargados. $publicKey solo en pruebas. Deja pending.json para la fase 2.
 * Si falla antes de cambiar el código no toca nada; si falla cambiándolo, vuelve atrás al momento.
 */
function update_install_package(string $envelopeRaw, string $zipFile, string $by, ?string $publicKey = null): array
{
    $m = update_verify_envelope($envelopeRaw, $publicKey);
    if (!update_newer(UC_VERSION, (string) $m['version'])) throw new UcError(t('La versión {v} no es más nueva que la instalada ({i}): no se instala.', ['v' => $m['version'], 'i' => UC_VERSION]));
    if (isset($m['min_php']) && version_compare(PHP_VERSION, (string) $m['min_php'], '<')) throw new UcError(t('La versión {v} necesita PHP {p} o superior.', ['v' => $m['version'], 'p' => $m['min_php']]));
    if (!is_file($zipFile) || filesize($zipFile) !== (int) $m['size'] || !hash_equals((string) $m['sha256'], (string) hash_file('sha256', $zipFile))) {
        throw new UcError(t('El paquete descargado no coincide con su huella firmada (sha256): no se instala.'));
    }
    [$can, $why] = update_can_write();
    if (!$can) throw new UcError($why);
    $dir = update_dir();
    $lock = uc_update_lock_open("$dir/update.lock");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new UcError(t('Ya hay una actualización en marcha.'));
    if (is_file("$dir/pending.json")) throw new UcError(t('Hay una actualización a medio terminar: recarga la página para que termine (o se deshaga) antes de lanzar otra.'));
    @set_time_limit(0);
    $id = date('Ymd-His') . '-' . preg_replace('/[^0-9.]/', '', (string) $m['version']);
    $work = "$dir/$id";
    $maint = false;
    $swapped = false;
    try {
        if (!@mkdir("$work/code", 0750, true)) throw new UcError(t('No se puede crear la carpeta de la actualización.'));
        // 1) Copia de seguridad completa, con el configurador aún abierto
        $backup = backup_create(false, 'antes');
        // 2) Mantenimiento y copia exacta de la base para volver atrás
        uc_maintenance_on('update ' . UC_VERSION . ' → ' . $m['version'], 30);
        $maint = true;
        db()->exec('VACUUM INTO ' . db()->quote("$work/configurator.sqlite"));
        // 3) Paquete verificado → carpeta temporal
        $new = update_extract($zipFile, $m, "$work/new");
        $old = update_installed_files();
        $toWrite = array_keys($new);
        $toDelete = $old === null ? [] : array_values(array_filter(array_diff(array_keys($old), $toWrite), fn($r) => uc_update_safe_rel((string) $r)));
        // 4) Copia del código que se va a tocar y lista de lo que es nuevo
        $added = [];
        foreach (array_merge($toWrite, $toDelete) as $rel) {
            $f = UC_ROOT . '/' . $rel;
            if (is_file($f)) { if (!uc_update_put($f, "$work/code/$rel")) throw new UcError(t('No se puede guardar la copia de {r}.', ['r' => $rel])); }
            elseif (in_array($rel, $toWrite, true)) $added[] = $rel;
        }
        file_put_contents("$work/added.txt", implode("\n", $added) . "\n");
        // La vuelta atrás del código VIEJO (que funciona), con nombres que no chocan con los del nuevo
        file_put_contents("$work/rollback.php", str_replace('uc_update_', 'uc_update_prev_', (string) file_get_contents(__DIR__ . '/update_rollback.php')));
        file_put_contents("$work/info.json", json_enc(['from' => UC_VERSION, 'to' => $m['version'], 'by' => $by, 'backup' => $backup['name'], 'changelog' => $m['changelog'] ?? ''], true));
        // 5) Reemplazo archivo a archivo (cada uno atómico). Si este proceso muere a mitad, la siguiente petición lo deshace.
        file_put_contents("$dir/pending.json", json_enc(['id' => $id, 'from' => UC_VERSION, 'to' => $m['version'], 'stage' => 'swapping', 'by' => $by, 'at' => date('c'), 'uid' => uc_update_uid()]));
        $swapped = true;
        foreach ($toWrite as $rel) if (!uc_update_put("$work/new/$rel", UC_ROOT . '/' . $rel)) throw new UcError(t('No se pudo escribir {r}.', ['r' => $rel]));
        foreach ($toDelete as $rel) @unlink(UC_ROOT . '/' . $rel);
        if (function_exists('opcache_reset')) @opcache_reset();
        file_put_contents("$dir/pending.json", json_enc(['id' => $id, 'from' => UC_VERSION, 'to' => $m['version'], 'stage' => 'swapped', 'by' => $by, 'at' => date('c'), 'uid' => uc_update_uid()]));
        audit('update.swap', UC_VERSION . ' → ' . $m['version'] . ' · ' . $by);
        return ['id' => $id, 'from' => UC_VERSION, 'to' => $m['version'], 'backup' => $backup['name']];
    } catch (Throwable $e) {
        if ($swapped) uc_update_rollback($work, UC_ROOT, UC_DATA, false);
        @unlink("$dir/pending.json");
        $failed = true;
        if ($maint) uc_maintenance_off();
        uc_update_write_result(['ok' => false, 'from' => UC_VERSION, 'to' => $m['version'], 'rolled_back' => $swapped, 'error' => $e->getMessage(),
            'message' => $swapped ? t('La actualización a la {v} ha fallado al cambiar los archivos y se ha vuelto a la {i}. Motivo: {e}', ['v' => $m['version'], 'i' => UC_VERSION, 'e' => $e->getMessage()])
                : t('No se ha actualizado (sigue la {i}): {e}', ['i' => UC_VERSION, 'e' => $e->getMessage()])]);
        throw $e instanceof UcError ? $e : new UcError(t('Error al actualizar: {e}', ['e' => $e->getMessage()]));
    } finally {
        bk_rmtree(!empty($failed) ? $work : "$work/new");
        flock($lock, LOCK_UN);
        uc_update_chown_data();
    }
}

/** Descarga la última versión y la instala (fase 1). */
function update_run_remote(string $by): array
{
    $st = update_check();
    $latest = $st['latest'];
    if (!$latest) throw new UcError($st['error'] !== '' ? t('No se ha podido comprobar si hay versión nueva: {e}', ['e' => $st['error']]) : t('Ya tienes la última versión ({v}).', ['v' => UC_VERSION]));
    $env = update_http((string) $latest['envelope_url'], 256 * 1024, 15);
    update_verify_envelope($env); // antes de bajar el paquete, que la versión esté firmada
    $zip = tempnam(update_dir(), 'pkg');
    try {
        update_http((string) $latest['zip_url'], UPDATE_MAX_ZIP, 300, $zip);
        return update_install_package($env, $zip, $by);
    } finally {
        @unlink($zip);
    }
}

/** Fase 2 (con el código nuevo ya cargado y migrado): comprobaciones y prueba de salud; lanza si algo no cuadra. */
function update_finish_after_boot(array $p): void
{
    if (UC_VERSION !== $p['to']) throw new UcError('El código cargado es la ' . UC_VERSION . ', no la ' . $p['to']);
    if ((int) val("SELECT value FROM meta WHERE key = 'schema_version'") < UC_SCHEMA) throw new UcError('La base de datos no se ha actualizado');
    // Prueba de salud: la base responde y lo esencial se calcula sin errores.
    if (db()->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new UcError('La base de datos no pasa la comprobación');
    node_tree(true);
    module_types_all();
    $admin = row("SELECT * FROM users WHERE role = 'admin' AND active = 1 LIMIT 1");
    if ($admin) foreach (config_list(['kind' => 'user', 'user' => $admin], [], 1, 3)['items'] as $c) config_get($c['code'], ['kind' => 'user', 'user' => $admin]);
    $dir = update_dir();
    @unlink("$dir/pending.json");
    uc_maintenance_off();
    file_put_contents("$dir/last.json", json_enc(['id' => $p['id'], 'from' => $p['from'], 'to' => $p['to'], 'at' => date('c')]));
    uc_update_write_result(['ok' => true, 'from' => $p['from'], 'to' => $p['to'], 'message' => t('El configurador se ha actualizado de la {f} a la {t}.', ['f' => $p['from'], 't' => $p['to']])]);
    Actor::set('actualización');
    audit('update.done', $p['from'] . ' → ' . $p['to']);
    $st = update_state();
    $st['latest'] = null;
    update_state_save($st);
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) if (basename($d) !== $p['id']) bk_rmtree($d); // se guarda la última, para volver atrás
    uc_update_chown_data();
}

/** Volver a la versión anterior desde la copia de la última actualización (solo el código; los datos se conservan). */
function update_manual_rollback(string $by): array
{
    $dir = update_dir();
    $last = json_decode((string) @file_get_contents("$dir/last.json"), true);
    $id = (string) ($last['id'] ?? '');
    if ($id === '' || !preg_match('/^[0-9a-z.-]+$/', $id) || !is_dir("$dir/$id/code")) throw new UcError(t('No hay ninguna actualización anterior guardada a la que volver.'));
    if (($last['to'] ?? '') !== UC_VERSION) throw new UcError(t('La última copia es de la actualización a la {v}, pero ahora tienes la {i}.', ['v' => $last['to'], 'i' => UC_VERSION]));
    [$can, $why] = update_can_write();
    if (!$can) throw new UcError($why);
    backup_create(false, 'antes');
    uc_maintenance_on('rollback', 10);
    $res = uc_update_rollback("$dir/$id", UC_ROOT, UC_DATA, false);
    uc_maintenance_off();
    @unlink("$dir/last.json");
    $msg = t('Se ha vuelto a la {v} (los datos se conservan).', ['v' => $last['from']]);
    uc_update_write_result(['ok' => true, 'from' => $last['to'], 'to' => $last['from'], 'rollback_manual' => true, 'message' => $msg, 'rollback' => $res]);
    audit('update.rollback', $last['to'] . ' → ' . $last['from'] . ' · ' . $by);
    return ['message' => $msg] + $res;
}

/** Al paso (tras responder) o desde cron: si el admin lo activó, busca versión nueva una vez al día. Nunca instala sola. */
function update_daily_tick(): void
{
    try {
        if (!setting_bool('update_check_daily') || (int) update_state()['checked_at'] > time() - 86400) return;
        if (!throttle('update-check', 1, 3600)) return;
        update_check();
    } catch (Throwable $e) {
        error_log('[universal-configurator] buscar actualización: ' . $e->getMessage());
    }
}

/** Resumen para el panel. */
function update_status(): array
{
    $st = update_state();
    $latest = $st['latest'] && update_newer(UC_VERSION, (string) $st['latest']['version']) ? $st['latest'] : null;
    [$can, $why] = update_can_write();
    return ['installed' => UC_VERSION, 'latest' => $latest, 'checked_at' => $st['checked_at'] ? (int) $st['checked_at'] : null, 'error' => $st['error'] ?: null,
        'can' => $can, 'why' => $why, 'daily' => setting_bool('update_check_daily'), 'repo' => UPDATE_REPO,
        'result' => json_decode((string) @file_get_contents(update_dir() . '/last-result.json'), true),
        'last' => json_decode((string) @file_get_contents(update_dir() . '/last.json'), true)];
}
