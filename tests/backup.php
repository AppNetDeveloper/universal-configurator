<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Copias de seguridad: ida y vuelta (los datos vuelven idénticos, también las imágenes), copias maliciosas rechazadas
// sin tocar nada (zip slip, ruta absoluta, enlace, sha256 cambiado, archivo de más, esquema más nuevo), restaurar sin
// escribir RESTAURAR no hace nada, cifrado al descargar (y contraseña mala), retención de las automáticas.
//   php tests/backup.php

require __DIR__ . '/_helper.php';

file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => '" . bin2hex(random_bytes(32)) . "', 'timezone' => 'Europe/Madrid'];");
$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$va = ['kind' => 'user', 'user' => $admin];
config_accept(config_create($c['blanco'], $va)['code'], $va);
$im = imagecreatetruecolor(4, 4); ob_start(); imagepng($im); $png = (string) ob_get_clean();
$t = tempnam(sys_get_temp_dir(), 'png'); file_put_contents($t, $png);
$img = image_store_upload(['error' => 0, 'tmp_name' => $t, 'name' => 'a.png'], false);

/** Foto de los datos que importan (todo menos registros técnicos). */
$snapshot = function (): array {
    $out = [];
    foreach (array_column(rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('audit_log','meta','throttle','login_backoff') ORDER BY name"), 'name') as $tb) {
        $out[$tb] = rows("SELECT * FROM $tb ORDER BY rowid");
    }
    $out['uploads'] = array_map(fn($f) => basename($f) . ':' . hash_file('sha256', $f), glob(uploads_dir() . '/*') ?: []);
    return $out;
};
/** Zip a mano (para las copias maliciosas). */
$mkzip = function (array $entries, ?array $manifest = null, array $symlinks = []): string {
    $p = bk_dir() . '/' . BK_PREFIX . date('Ymd-His') . '-x' . substr(md5(json_encode($entries) . microtime()), 0, 6) . '.zip';
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($manifest !== null) $z->addFromString('manifest.json', json_enc($manifest));
    foreach ($entries as $n => $data) $z->addFromString($n, $data);
    foreach ($symlinks as $n) { $z->addFromString($n, '/etc/passwd'); $z->setExternalAttributesName($n, ZipArchive::OPSYS_UNIX, 0120777 << 16); }
    $z->close();
    return basename($p);
};

$before = $snapshot();
$b = backup_create();
check('la copia es un .zip con la base, la configuración (config.json), las imágenes y un manifest con sha256 de cada archivo', function () use ($b, $img) {
    $z = new ZipArchive(); $z->open(bk_dir() . '/' . $b['name']);
    $m = json_decode((string) $z->getFromName('manifest.json'), true);
    $ok = eq(true, $z->locateName('configurator.sqlite') !== false && $z->locateName('config.json') !== false && $z->locateName('uploads/' . basename($img)) !== false)
        && eq('universal-configurator', $m['app']) && eq(UC_SCHEMA, $m['schema']) && eq(UC_VERSION, $m['version'])
        && eq(hash('sha256', (string) $z->getFromName('config.json')), $m['files']['config.json']['sha256']);
    $z->close();
    return $ok && eq(true, str_starts_with(bk_dir(), UC_DATA)) && eq(false, str_starts_with(bk_dir(), UC_PUBLIC));
});
check('lista de copias con fecha, tamaño y versión', fn() => eq($b['name'], backup_list()[0]['name']) && eq(UC_VERSION, backup_list()[0]['version']) && eq(true, backup_list()[0]['size'] > 0));

// Se cambian los datos y se vuelve a la copia
config_create($c['blanco'], $va);
q("UPDATE nodes SET name = 'Cambiado' WHERE id = ?", [$c['linea']]);
q('DELETE FROM price_tiers WHERE module_type_id = ?', [$c['alto']]);
foreach (glob(uploads_dir() . '/*') as $f) unlink($f);

check_error('sin escribir RESTAURAR no se hace nada', fn() => backup_restore($b['name'], 'restaurar'), 'RESTAURAR');
check('…y de verdad no cambió nada', fn() => eq('Cambiado', val('SELECT name FROM nodes WHERE id = ?', [$c['linea']])));
$r = backup_restore($b['name'], 'RESTAURAR');
check('ida y vuelta: los datos y las imágenes vuelven idénticos', fn() => eq($before, $snapshot()));
check('antes de restaurar se hizo una copia del estado de entonces, y queda en Actividad', fn() => eq(true, (bool) preg_match('/-antes\.zip$/', $r['safety']))
    && eq(true, is_file(bk_dir() . '/' . $r['safety'])) && eq(true, (bool) val("SELECT 1 FROM audit_log WHERE action = 'backup.restore'")));
check('la conexión vuelve a funcionar tras sustituir la base (y sin mantenimiento)', fn() => eq(true, (int) val('SELECT COUNT(*) FROM nodes') > 0) && eq(null, uc_maintenance_state()));

// Copias maliciosas
$good = function () use ($b): array {
    $z = new ZipArchive(); $z->open(bk_dir() . '/' . $b['name']);
    $m = json_decode((string) $z->getFromName('manifest.json'), true);
    $db = (string) $z->getFromName('configurator.sqlite');
    $z->close();
    return [$m, $db];
};
[$m0, $db0] = $good();
$dbOnly = fn(array $m) => ['files' => ['configurator.sqlite' => $m['files']['configurator.sqlite']]] + $m;
check_error('zip slip (../) rechazado', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0, '../../evil.php' => '<?php echo 1;'], $dbOnly($m0)), 'RESTAURAR'), 'ruta no permitida');
check_error('ruta absoluta rechazada', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0, '/tmp/evil' => 'x'], $dbOnly($m0)), 'RESTAURAR'), 'ruta no permitida');
check_error('enlace simbólico rechazado', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0], $dbOnly($m0), ['uploads/' . str_repeat('a', 24) . '.png']), 'RESTAURAR'), 'enlace simbólico');
check_error('sha256 que no cuadra (base cambiada) rechazado', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0 . 'x'], $dbOnly($m0)), 'RESTAURAR'), 'huella');
check_error('un archivo que no está en el manifest se rechaza', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.json' => '{}'], $dbOnly($m0)), 'RESTAURAR'), 'no coinciden');
check_error('copia de un esquema más nuevo que el código: «actualiza antes»', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0], ['schema' => UC_SCHEMA + 1, 'version' => '9.9.9'] + $dbOnly($m0)), 'RESTAURAR'), 'más nueva');
check_error('sin manifest no es una copia del configurador', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0]), 'RESTAURAR'), 'manifest');
check('nada de lo anterior tocó los datos', fn() => eq($before, $snapshot()));
check_error('una copia que no está en la lista (o con ruta) no existe', fn() => backup_restore('../config.php', 'RESTAURAR'), 'No existe');

// La configuración viaja como DATOS: un config.php venido de un zip nunca se copia ni se ejecuta
check('la copia guarda la configuración como config.json (datos), no como config.php', function () {
    $b = backup_create();
    $z = new ZipArchive(); $z->open(bk_dir() . '/' . $b['name']);
    $j = json_decode((string) $z->getFromName('config.json'), true);
    $ok = eq(false, $z->locateName('config.php') !== false) && eq(config('secret'), $j['secret'] ?? null);
    $z->close();
    return $ok;
});
$cfgBefore = (string) file_get_contents(UC_DATA . '/config.php');
check('zip con un config.php con código: ni se copia ni se ejecuta, aunque se pida restaurar las claves', function () use ($mkzip, $db0, $m0, $dbOnly, $cfgBefore) {
    $evil = "<?php file_put_contents('" . UC_DATA . "/pwned', 'x'); return ['secret' => str_repeat('0', 64)];";
    $m = $dbOnly($m0);
    $m['files']['config.php'] = ['sha256' => hash('sha256', $evil), 'size' => strlen($evil)];
    $r = backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.php' => $evil], $m), 'RESTAURAR', true);
    config('__reset__');
    return eq(false, is_file(UC_DATA . '/pwned')) && eq($cfgBefore, (string) file_get_contents(UC_DATA . '/config.php'))
        && eq(true, (bool) array_filter($r['warnings'], fn($w) => str_contains($w, 'config.php')));
});
check('zip con config.json válido y «restaurar también las claves»: la app escribe su propio config.php', function () use ($mkzip, $db0, $m0, $dbOnly, $cfgBefore) {
    $new = ['secret' => str_repeat('ab', 32), 'timezone' => 'Atlantic/Canary', 'debug' => false];
    $json = json_encode($new);
    $m = $dbOnly($m0);
    $m['files']['config.json'] = ['sha256' => hash('sha256', $json), 'size' => strlen($json)];
    backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.json' => $json], $m), 'RESTAURAR', true);
    $written = (string) file_get_contents(UC_DATA . '/config.php');
    $arr = require UC_DATA . '/config.php';
    file_put_contents(UC_DATA . '/config.php', $cfgBefore);
    return eq(str_repeat('ab', 32), $arr['secret']) && eq('Atlantic/Canary', $arr['timezone']) && eq(true, str_starts_with($written, "<?php\nreturn array ("));
});
check('sin marcar «restaurar también las claves», la clave actual se conserva', function () use ($mkzip, $db0, $m0, $dbOnly, $cfgBefore) {
    $json = json_encode(['secret' => str_repeat('cd', 32)]);
    $m = $dbOnly($m0);
    $m['files']['config.json'] = ['sha256' => hash('sha256', $json), 'size' => strlen($json)];
    $r = backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.json' => $json], $m), 'RESTAURAR', false);
    return eq($cfgBefore, (string) file_get_contents(UC_DATA . '/config.php')) && eq(true, (bool) $r['warnings']);
});
foreach ([['clave inesperada', ['secret' => str_repeat('ab', 32), 'evil' => '<?php']], ['secret con otro formato', ['secret' => 'corto']],
          ['valor que no es escalar', ['secret' => str_repeat('ab', 32), 'timezone' => ['x']]], ['zona horaria inventada', ['secret' => str_repeat('ab', 32), 'timezone' => 'Marte/Base']]] as [$what, $bad]) {
    check_error("config.json con $what: se rechaza y no se cambia nada", function () use ($mkzip, $db0, $m0, $dbOnly, $bad) {
        $json = json_encode($bad);
        $m = $dbOnly($m0);
        $m['files']['config.json'] = ['sha256' => hash('sha256', $json), 'size' => strlen($json)];
        backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.json' => $json], $m), 'RESTAURAR', true);
    }, 'config.json');
}
check('nada de una copia acaba en app/, public/ ni bin/: una «imagen» que no lo es se rechaza', function () use ($mkzip, $db0, $m0, $dbOnly) {
    $php = '<?php echo 1;';
    $n = 'uploads/' . str_repeat('b', 24) . '.png';
    $m = $dbOnly($m0);
    $m['files'][$n] = ['sha256' => hash('sha256', $php), 'size' => strlen($php)];
    try { backup_restore($mkzip(['configurator.sqlite' => $db0, $n => $php], $m), 'RESTAURAR'); } catch (UcError $e) {
        return eq(true, str_contains($e->getMessage(), 'imagen')) && eq(false, is_file(uploads_dir() . '/' . basename($n)));
    }
    return false;
});

// Cifrado al descargar y subida
$enc = sys_get_temp_dir() . '/uc-enc-' . getmypid();
check_error('contraseña de cifrado corta', fn() => bk_encrypt_file(bk_dir() . '/' . $b['name'], $enc, 'corta'), '10 caracteres');
bk_encrypt_file(bk_dir() . '/' . $b['name'], $enc, 'una-clave-larga-1');
check('cifrada: no se ve el zip ni su contenido', function () use ($enc) {
    $raw = (string) file_get_contents($enc);
    return eq(true, str_starts_with($raw, BK_MAGIC)) && eq(false, str_contains($raw, 'manifest.json')) && eq(false, str_contains($raw, 'PK'));
});
check_error('subir la cifrada con una contraseña mala: no entra', fn() => backup_import($enc, 'otra-clave-mala-2'), 'Contraseña incorrecta');
check('subir la cifrada con su contraseña: queda en la lista, validada', function () use ($enc) {
    $n = backup_import($enc, 'una-clave-larga-1');
    return eq(true, bk_valid_name($n)) && eq(true, in_array($n, array_column(backup_list(), 'name'), true));
});
check_error('subir algo que no es una copia', function () { $f = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($f, 'hola'); backup_import($f); }, 'no es una copia');
@unlink($enc);

// Automáticas y retención
check('automáticas: apagadas no hacen nada; diarias, una al día; se guardan las N más nuevas', function () {
    settings_save(['backup_auto' => 'off']);
    $none = backup_auto_tick();
    settings_save(['backup_auto' => 'daily', 'backup_keep' => '2']);
    q("DELETE FROM meta WHERE key = 'last_auto_backup'");
    $first = backup_auto_tick();
    $again = backup_auto_tick();
    foreach ([1, 2] as $i) { q("DELETE FROM meta WHERE key = 'last_auto_backup'"); sleep(1); backup_auto_tick(); }
    $autos = array_filter(backup_list(), fn($x) => $x['auto']);
    settings_save(['backup_auto' => 'off']);
    return eq(null, $none) && eq(true, $first !== null) && eq(null, $again) && eq(2, count($autos)) && eq(true, count(backup_list()) > 2);
});
check('borrar una copia', function () use ($b) { backup_delete($b['name']); return eq(false, in_array($b['name'], array_column(backup_list(), 'name'), true)); });

done();
