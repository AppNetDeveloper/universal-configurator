<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Revisión de seguridad de la v0.3.1: el paquete de una actualización se comprueba y se extrae de la MISMA copia
// privada; límites de compartir y de cambiar configuraciones; restaurar vuelve a codificar las imágenes; `page`
// gigantesco; zip bomb al restaurar.
//   php tests/security_v031.php

define('UC_TESTING', true);
require __DIR__ . '/_helper.php';
file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => 'clave-de-pruebas-0123456789abcdef0123456789abcdef'];");

/** Un «archivo» que da un contenido la primera vez que se abre y otro las siguientes (lo cambian entre comprobar y extraer). */
final class SwapStream
{
    public static array $bodies = [];
    public static int $opens = 0;
    public $context;
    private string $buf = '';
    private int $pos = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->buf = self::$bodies[min(self::$opens++, 1)];
        return true;
    }
    public function stream_read(int $n): string { $r = substr($this->buf, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof(): bool { return $this->pos >= strlen($this->buf); }
    public function stream_stat(): array { return ['size' => strlen($this->buf)]; }
    public function url_stat(string $path, int $flags): array { return ['size' => strlen(self::$bodies[min(self::$opens, 1)]), 'mode' => 0100644]; }
    public function stream_close(): void {}
}
stream_wrapper_register('swap', SwapStream::class);

/** Un paquete mínimo de la versión $v (con un archivo de marca $mark). */
$pkg = function (string $v, string $mark): string {
    $f = tempnam(sys_get_temp_dir(), 'uc-pkg');
    $z = new ZipArchive();
    $z->open($f, ZipArchive::OVERWRITE);
    $z->addFromString('app/core.php', "<?php\nconst UC_VERSION = '$v';\n");
    $z->addFromString('app/bootstrap.php', "<?php\n");
    $z->addFromString('public/index.php', "<?php\n");
    $z->addFromString("public/$mark.txt", "$mark\n");
    $z->close();
    $b = (string) file_get_contents($f);
    unlink($f);
    return $b;
};

// --- Actualización: lo que se comprueba (sha256) es exactamente lo que se extrae
$good = $pkg('9.0.0', 'bueno');
$evil = $pkg('9.0.0', 'malo');
$m = ['version' => '9.0.0', 'sha256' => hash('sha256', $good), 'size' => strlen($good)];
SwapStream::$bodies = [$good, $evil];
SwapStream::$opens = 0;
$dest = UC_DATA . '/extraido';
check('el paquete se comprueba y se extrae de la misma copia privada (cambiarlo después de comprobarlo no cuela nada)', function () use ($m, $dest) {
    $priv = update_package_private('swap://paquete.zip', $m);
    try { $files = update_extract($priv, $m, $dest); } finally { bk_rmtree(dirname($priv)); }
    return eq(true, isset($files['public/bueno.txt'])) && eq(false, isset($files['public/malo.txt'])) && eq(false, is_file("$dest/public/malo.txt"));
});
SwapStream::$bodies = [$evil, $evil];
SwapStream::$opens = 0;
check_error('un paquete que no coincide con su sha256 firmado no deja copia', function () use ($m) { update_package_private('swap://paquete.zip', $m); }, 'sha256');

// --- Compartir y cambiar: con límite por persona (o por conexión, el invitado), y compartir lo mismo no repite la fila
settings_save(['url_publica' => 'https://conf.test']);
$c = fx_catalog();
$ana = fx_user('comercial', 'ana@x.test');
$va = ['kind' => 'user', 'user' => $ana];
$cfg = config_create($c['blanco'], $va);
$shares = fn(string $code) => (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'config.share' AND detail LIKE ?", [$code . '%']);
check('compartir dos veces lo mismo: el mismo enlace y una sola fila en la actividad', function () use ($cfg, $va, $shares) {
    $a = config_share($cfg['code'], $va);
    $b = config_share($cfg['code'], $va);
    return eq($a['url'], $b['url']) && eq(1, $shares($cfg['code']));
});
check('compartir sin parar: a partir de ' . SHARE_MAX_PER_HOUR . ' por hora, 429', function () use ($c, $va) {
    $codes = [];
    for ($i = 0; $i < 3; $i++) $codes[] = config_create($c['blanco'], $va)['code'];
    $ok = 0; $last = null;
    for ($i = 0; $i < 300; $i++) { try { config_share($codes[$i % 3], $va); $ok++; } catch (UcError $e) { $last = $e->status; } }
    return eq(true, $ok <= SHARE_MAX_PER_HOUR) && eq(429, $last) && eq(true, (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'config.share'") <= 4);
});
check('cambiar una configuración sin parar: a partir de ' . CONFIG_OPS_PER_WINDOW . ' llamadas en la ventana, 429 (y la actividad no crece más)', function () use ($c, $va) {
    $code = config_create($c['blanco'], $va)['code'];
    $item = (int) val('SELECT i.id FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id JOIN configurations c ON c.id = w.configuration_id WHERE c.code = ? ORDER BY i.id LIMIT 1', [$code]);
    $before = (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'config.update'");
    $ok = 0; $last = null;
    for ($i = 0; $i < CONFIG_OPS_PER_WINDOW + 20; $i++) {
        try { config_apply_ops($code, [['op' => 'set_width', 'item_id' => $item, 'width_cm' => 40]], $va); $ok++; } catch (UcError $e) { $last = $e->status; }
    }
    return eq(CONFIG_OPS_PER_WINDOW, $ok) && eq(429, $last) && eq(CONFIG_OPS_PER_WINDOW, (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'config.update'") - $before);
});
check('el límite es de cada uno: otra persona sigue pudiendo cambiar las suyas', function () use ($c) {
    $vb = ['kind' => 'user', 'user' => fx_user('comercial', 'beto@x.test')];
    $code = config_create($c['blanco'], $vb)['code'];
    $item = (int) val('SELECT i.id FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id JOIN configurations c ON c.id = w.configuration_id WHERE c.code = ? ORDER BY i.id LIMIT 1', [$code]);
    return is_array(config_apply_ops($code, [['op' => 'set_width', 'item_id' => $item, 'width_cm' => 40]], $vb));
});

done();
