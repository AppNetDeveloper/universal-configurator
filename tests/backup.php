<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Copias de seguridad: ida y vuelta (los datos vuelven idénticos, también las imágenes), copias maliciosas rechazadas
// sin tocar nada (zip slip, ruta absoluta, enlace, sha256 cambiado, archivo de más, esquema más nuevo), restaurar sin
// escribir RESTAURAR no hace nada, cifrado al descargar (y contraseña mala), retención de las automáticas.
//   php tests/backup.php

require __DIR__ . '/_helper.php';

if (!is_file(UC_DATA . '/config.php')) file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => '" . bin2hex(random_bytes(16)) . "'];");
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
check('la copia es un .zip con la base, config.php, las imágenes y un manifest con sha256 de cada archivo', function () use ($b, $img) {
    $z = new ZipArchive(); $z->open(bk_dir() . '/' . $b['name']);
    $m = json_decode((string) $z->getFromName('manifest.json'), true);
    $ok = eq(true, $z->locateName('configurator.sqlite') !== false && $z->locateName('config.php') !== false && $z->locateName('uploads/' . basename($img)) !== false)
        && eq('universal-configurator', $m['app']) && eq(UC_SCHEMA, $m['schema']) && eq(UC_VERSION, $m['version'])
        && eq(hash('sha256', (string) $z->getFromName('config.php')), $m['files']['config.php']['sha256']);
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
check_error('un archivo que no está en el manifest se rechaza', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0, 'config.php' => '<?php return [];'], $dbOnly($m0)), 'RESTAURAR'), 'no coinciden');
check_error('copia de un esquema más nuevo que el código: «actualiza antes»', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0], ['schema' => UC_SCHEMA + 1, 'version' => '9.9.9'] + $dbOnly($m0)), 'RESTAURAR'), 'más nueva');
check_error('sin manifest no es una copia del configurador', fn() => backup_restore($mkzip(['configurator.sqlite' => $db0]), 'RESTAURAR'), 'manifest');
check('nada de lo anterior tocó los datos', fn() => eq($before, $snapshot()));
check_error('una copia que no está en la lista (o con ruta) no existe', fn() => backup_restore('../config.php', 'RESTAURAR'), 'No existe');

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
