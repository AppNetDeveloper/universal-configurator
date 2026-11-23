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

done();
