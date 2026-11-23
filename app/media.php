<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Imágenes que sube el admin para el catálogo. Subida segura:
 *  - solo PNG, JPEG o WebP DE VERDAD (tipo por contenido con finfo y getimagesize, no por el nombre); nada de SVG
 *    (un SVG puede llevar scripts: los SVG del catálogo los genera la app, ver illustrations.php);
 *  - 2 MB como mucho y 4000 px por lado;
 *  - si hay GD, se vuelve a codificar (se pierde lo que viniera pegado detrás o en los metadatos);
 *  - nombre aleatorio (24 hex), guardada en data/uploads (fuera de app/ y de public/);
 *  - se sirve por /media/<nombre> leyendo el fichero, con su tipo, nosniff y una CSP que no deja ejecutar nada.
 */

const IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const IMAGE_MAX_SIDE = 4000;
const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

function uploads_dir(): string
{
    return bk_private_dir(UC_DATA . '/uploads');
}

/**
 * Valida y guarda una imagen subida ($_FILES['x']). Devuelve su dirección («/media/<nombre>»). $fromHttp = false solo
 * en pruebas (no exige is_uploaded_file).
 */
function image_store_upload(array $f, bool $fromHttp = true): string
{
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new UcError(t('No se pudo subir la imagen: pesa más de lo que admite el servidor (máximo 2 MB).'));
    if ($err !== UPLOAD_ERR_OK) throw new UcError(t('No se pudo subir la imagen (código {c}). Vuelve a intentarlo.', ['c' => $err]));
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp) || ($fromHttp && !is_uploaded_file($tmp))) throw new UcError(t('No se pudo subir la imagen.'));
    $size = filesize($tmp);
    if ($size === false || $size > IMAGE_MAX_BYTES) throw new UcError(t('La imagen pesa {s} KB: el máximo son 2 MB.', ['s' => intdiv((int) $size, 1024)]));
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(IMAGE_TYPES[$mime])) {
        throw new UcError(str_contains($mime, 'image/') || str_ends_with(strtolower((string) ($f['name'] ?? '')), '.svg')
            ? t('Solo se admiten imágenes PNG, JPEG o WebP (recibido: {m}). Los SVG no se admiten al subir.', ['m' => $mime])
            : t('El fichero no es una imagen PNG, JPEG o WebP (recibido: {m}).', ['m' => $mime]));
    }
    $info = @getimagesize($tmp);
    $types = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/webp' => IMAGETYPE_WEBP];
    if (!$info || $info[2] !== $types[$mime]) throw new UcError(t('El fichero no es una imagen válida: no es una imagen PNG, JPEG o WebP de verdad.'));
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > IMAGE_MAX_SIDE || $info[1] > IMAGE_MAX_SIDE) throw new UcError(t('La imagen mide {w}×{h} px: como mucho {m} px por lado.', ['w' => $info[0], 'h' => $info[1], 'm' => IMAGE_MAX_SIDE]));
    $bytes = image_reencode((string) file_get_contents($tmp), $mime);
    $name = bin2hex(random_bytes(12)) . '.' . IMAGE_TYPES[$mime];
    if (file_put_contents(uploads_dir() . '/' . $name, $bytes) === false) throw new UcError(t('No se pudo guardar la imagen en el servidor.'));
    @chmod(uploads_dir() . '/' . $name, 0640);
    audit('media.upload', $name . ' (' . $mime . ', ' . intdiv(strlen($bytes), 1024) . ' KB)');
    return '/media/' . $name;
}

/**
 * Vuelve a codificar con GD si está disponible (quita lo pegado detrás y los metadatos). Sin GD, los bytes tal cual;
 * con $strict, sin GD (o sin poder escribir ese tipo) es un error: lo usa restaurar una copia.
 */
function image_reencode(string $bytes, string $mime, bool $strict = false): string
{
    $cant = t('Este PHP no tiene GD (php-gd): no se puede volver a codificar la imagen.');
    if (!function_exists('imagecreatefromstring')) { if ($strict) throw new UcError($cant); return $bytes; }
    $im = @imagecreatefromstring($bytes);
    if (!$im) throw new UcError(t('El fichero no es una imagen válida: no es una imagen PNG, JPEG o WebP de verdad.'));
    imagealphablending($im, false);
    imagesavealpha($im, true);
    ob_start();
    $ok = match ($mime) { 'image/png' => imagepng($im, null, 6), 'image/jpeg' => imagejpeg($im, null, 88), 'image/webp' => function_exists('imagewebp') ? imagewebp($im, null, 88) : false };
    $out = (string) ob_get_clean();
    imagedestroy($im);
    if (!$ok || $out === '') { if ($strict) throw new UcError($cant); return $bytes; }
    return $out;
}

/** @return array{0:int, 1:array<string,string>, 2:string} — respuesta de /media/<nombre> */
function media_response(string $name): array
{
    if (!preg_match('/^[a-f0-9]{24}\.(png|jpg|webp)$/', $name, $m)) return [404, [], ''];
    $file = UC_DATA . '/uploads/' . $name;
    if (!is_file($file)) return [404, [], ''];
    $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$m[1]];
    return [200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        'Cache-Control' => 'public, max-age=604800', 'Content-Disposition' => 'inline'], (string) file_get_contents($file)];
}

function media_handle(string $path): void
{
    [$st, $headers, $body] = media_response(substr($path, strlen('/media/')));
    http_response_code($st);
    foreach ($headers as $k => $v) header("$k: $v");
    echo $st === 200 ? $body : 'No encontrada.';
}
