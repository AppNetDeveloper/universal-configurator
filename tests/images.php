<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Imágenes del catálogo: ilustraciones SVG generadas por la app (sin texto del usuario) y subida segura del admin
// (solo PNG/JPEG/WebP de verdad, 2 MB, nombre aleatorio, fuera de app/ y public/, servida sin ejecutar nada).
//   php tests/images.php

require __DIR__ . '/_helper.php';

$png = function (int $w = 40, int $h = 30): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 79, 70, 229));
    ob_start(); imagepng($im); return (string) ob_get_clean();
};
$tmp = function (string $content, string $name) {
    $f = tempnam(sys_get_temp_dir(), 'ucimg');
    file_put_contents($f, $content);
    return ['name' => $name, 'tmp_name' => $f, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'image/png'];
};

// --- ilustraciones generadas
foreach (ILLUSTRATIONS as $kind => $label) {
    check("ilustración «{$kind}»: SVG propio, sin scripts y con el acabado como color", function () use ($kind) {
        $svg = illustration_svg($kind, '#C8A165');
        return eq(true, str_starts_with($svg, '<svg') && str_contains($svg, '#C8A165') && !preg_match('/<script|on\w+=|href=/i', $svg));
    });
}
check('node_visual pinta la ilustración en línea', fn() => eq(true, str_contains(node_visual('ill:cocina-l:#374151'), '<svg')));
check_error('una ilustración con un color que no es #RRGGBB se rechaza al guardar', fn() => node_validate(['name' => 'X', 'image' => 'ill:cocina-l:#374151"><script>alert(1)</script>']), 'Imagen');
check_error('una ilustración que no existe se rechaza', fn() => node_validate(['name' => 'X', 'image' => 'ill:nave-espacial:#374151']), 'Imagen');
check('node_validate acepta color, ilustración, imagen subida y https', function () {
    foreach (['#C8A165', 'ill:armario-corredera:#F3F4F6', '/media/' . str_repeat('a', 24) . '.png', 'https://cdn.example.com/x.jpg'] as $ok) node_validate(['name' => 'X', 'image' => $ok]);
    return true;
});
check_error('una ruta propia que no es /media/… se rechaza', fn() => node_validate(['name' => 'X', 'image' => '/admin/usuarios']), 'Imagen');

// --- subida segura
$good = null;
check('PNG de verdad: se guarda con nombre aleatorio fuera de public/ y app/', function () use ($tmp, $png, &$good) {
    $good = image_store_upload($tmp($png(), 'mi foto.png'), false);
    $file = UC_DATA . '/uploads/' . basename($good);
    return eq(1, preg_match('#^/media/[a-f0-9]{24}\.png$#', $good)) && eq(true, is_file($file)) && eq(false, str_contains($file, '/public/'));
});
check_error('SVG con script: rechazado (las subidas no admiten SVG)', fn() => image_store_upload($tmp('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'), false), 'PNG, JPEG o WebP');
check_error('un fichero que dice ser PNG y no lo es: rechazado', fn() => image_store_upload($tmp("<?php system('id'); ?>", 'foto.png'), false), 'no es una imagen');
check_error('HTML con cabecera de GIF: rechazado', fn() => image_store_upload($tmp("GIF89a<script>alert(1)</script>", 'x.png'), false), 'PNG, JPEG o WebP');
check_error('demasiado grande (más de 2 MB): rechazado', fn() => image_store_upload($tmp($png() . str_repeat("\0", 2 * 1024 * 1024), 'grande.png'), false), '2 MB');
check_error('error de subida de PHP: mensaje claro', fn() => image_store_upload(['name' => 'x.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE], false), 'subir');
check('una imagen buena se vuelve a codificar (quita lo que viniera pegado detrás)', function () use ($tmp, $png) {
    $url = image_store_upload($tmp($png() . '<?php echo 1; ?>', 'pegado.png'), false);
    return eq(false, str_contains((string) file_get_contents(UC_DATA . '/uploads/' . basename($url)), '<?php'));
});
check('servir /media: solo nombres generados por la app, con nosniff y sin ejecutar nada', function () use (&$good) {
    [$st, $headers, $body] = media_response(basename($good));
    [$st2] = media_response('../config.php');
    [$st3] = media_response('a' . str_repeat('b', 23) . '.php');
    return eq(200, $st) && eq('image/png', $headers['Content-Type']) && eq('nosniff', $headers['X-Content-Type-Options'])
        && eq(true, str_contains($headers['Content-Security-Policy'], "default-src 'none'")) && eq(true, strlen($body) > 0) && eq(404, $st2) && eq(404, $st3);
});

done();
