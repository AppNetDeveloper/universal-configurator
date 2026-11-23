<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Prueba HTTP real: levanta `php -S` con una carpeta de datos temporal y recorre la aplicación como un navegador:
// instalador, rutas internas que no se sirven, cabeceras, login de admin y de comercial, alta por invitación,
// crear/cambiar/aceptar por la API, comercial A contra B, CSRF y MCP con un token creado en el panel.
//   php tests/http.php

$root = dirname(__DIR__);
$data = sys_get_temp_dir() . '/uc-http-' . getmypid();
@mkdir($data, 0700, true);
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $p = random_int(20000, 40000);
    $s = @stream_socket_server("tcp://127.0.0.1:$p");
    if ($s) { fclose($s); $port = $p; }
}
$env = ['UC_DATA' => $data, 'PATH' => getenv('PATH') ?: '/usr/bin'];
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"], [0 => ['pipe', 'r'], 1 => ['file', "$data/server.log", 'a'], 2 => ['file', "$data/server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($proc, $data) {
    proc_terminate($proc);
    foreach (scandir($data) ?: [] as $f) if (is_file("$data/$f")) @unlink("$data/$f");
    @rmdir($data);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$BASE = "http://127.0.0.1:$port";

$fails = 0;
$count = 0;
const UC_SCHEMA_EXPECTED = '6';
function check(string $name, callable $fn): void
{
    global $fails, $count;
    $count++;
    try { $r = $fn(); $ok = $r !== false; $msg = $ok ? '' : 'devolvió false'; } catch (Throwable $e) { $ok = false; $msg = $e->getMessage(); }
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : '  → ' . $msg);
}
function eq(mixed $a, mixed $b, string $w = ''): bool { if ($a !== $b) throw new RuntimeException(($w ? "$w: " : '') . 'esperado ' . var_export($a, true) . ', obtenido ' . var_export($b, true)); return true; }

/** Navegador mínimo con cookies propias. */
final class Browser
{
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'ucjar'); }
    public function __destruct() { @unlink($this->jar); }
    /** @return array{status:int, headers:string, body:string, json:?array} */
    public function req(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: uc-test'], $headers)]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        $raw = (string) curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $b = substr($raw, $hs);
        return ['status' => $st, 'headers' => substr($raw, 0, $hs), 'body' => $b, 'json' => json_decode($b, true)];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function post(string $p, array $f): array { return $this->req('POST', $p, $f); }
    /** POST multipart con un fichero (cuerpo armado a mano: el campo, el nombre y los bytes los decide la prueba). */
    public function upload(string $p, array $f, string $field, string $filename, string $bytes, string $type): array
    {
        $b = '----uc' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($f as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: $type\r\n\r\n$bytes\r\n--$b--\r\n";
        return $this->req('POST', $p, $body, ['Content-Type: multipart/form-data; boundary=' . $b]);
    }
    public function api(string $m, string $p, ?array $json = null, ?string $csrf = null): array
    {
        return $this->req($m, $p, $json === null ? null : json_encode($json), array_merge(['Content-Type: application/json', 'Accept: application/json'], $csrf ? ["X-CSRF-Token: $csrf"] : []));
    }
    public static function csrf(string $html): string
    {
        if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m)) return $m[1];
        throw new RuntimeException('no hay _csrf en la página');
    }
}
function loc(array $r): string { return preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) ? $m[1] : ''; }

$adm = new Browser($BASE);

// --- instalador
check('sin instalar, todo lleva a /install', fn() => eq(303, $adm->get('/')['status']) && eq('/install', loc($adm->get('/configurar'))));
check('el instalador comprueba requisitos y lleva el crédito', function () use ($adm) {
    $r = $adm->get('/install');
    return eq(200, $r['status']) && eq(true, str_contains($r['body'], 'pdo_sqlite') && str_contains($r['body'], 'Hecho con Universal Configurator'));
});
check('el instalador propone la URL actual como dirección pública (obligatoria)', function () use ($adm, $BASE) {
    $b = $adm->get('/install')['body'];
    return eq(1, preg_match('#name="url" required[^>]*value="' . preg_quote($BASE, '#') . '"#', $b));
});
check('instalar con la dirección pública vacía no instala', function () use ($adm, $data) {
    $csrf = Browser::csrf($adm->get('/install')['body']);
    $r = $adm->post('/install', ['_csrf' => $csrf, 'install_code' => trim((string) file_get_contents("$data/install-code.txt")), 'empresa' => 'X', 'admin_name' => 'A', 'admin_email' => 'a@x.test', 'admin_password' => 'secreta123', 'url' => '']);
    return eq(200, $r['status']) && eq(true, str_contains($r['body'], 'URL pública')) && eq(false, is_file("$data/config.php"));
});
check('el instalador pide un código de un solo uso y dice dónde está (data/install-code.txt)', function () use ($adm, $data) {
    $b = $adm->get('/install')['body'];
    return eq(true, str_contains($b, 'install-code.txt') && str_contains($b, 'name="install_code"')) && eq(true, is_file("$data/install-code.txt"))
        && eq('600', substr(sprintf('%o', fileperms("$data/install-code.txt")), -3));
});
check('sin el código (o con uno falso) no se instala', function () use ($adm, $data) {
    $csrf = Browser::csrf($adm->get('/install')['body']);
    $base = ['_csrf' => $csrf, 'empresa' => 'X', 'admin_name' => 'A', 'admin_email' => 'a@x.test', 'admin_password' => 'secreta123', 'url' => $GLOBALS['BASE']];
    $r1 = $adm->post('/install', $base);
    $r2 = $adm->post('/install', $base + ['install_code' => 'AAAA-BBBB-CCCC']);
    return eq(true, str_contains($r1['body'], 'código de instalación') && str_contains($r2['body'], 'código de instalación')) && eq(false, is_file("$data/config.php"));
});
check('instalar sin CSRF no instala', function () use ($adm, $data) {
    $adm->post('/install', ['empresa' => 'X', 'admin_name' => 'A', 'admin_email' => 'a@x.test', 'admin_password' => 'secreta123']);
    return eq(false, is_file("$data/config.php"));
});
check('instalar (con el código sin guiones y en minúsculas) crea config.php, la base, el admin y los datos de ejemplo', function () use ($adm, $data) {
    $csrf = Browser::csrf($adm->get('/install')['body']);
    $r = $adm->post('/install', ['_csrf' => $csrf, 'install_code' => strtolower(str_replace('-', '', trim((string) file_get_contents("$data/install-code.txt")))), 'empresa' => 'Cocinas Demo', 'timezone' => 'Europe/Madrid', 'admin_name' => 'Admin',
        'admin_email' => 'admin@demo.test', 'admin_password' => 'admin-12345', 'sample' => '1', 'url' => $GLOBALS['BASE']]);
    return eq(303, $r['status']) && eq('/admin', loc($r)) && eq(true, is_file("$data/config.php")) && eq(true, is_file("$data/configurator.sqlite"))
        && eq(false, is_file("$data/install-code.txt"), 'el código se borra al usarlo');
});
check('tras instalar, /install ya no instala', fn() => eq(true, $adm->get('/install')['status'] !== 200 || !str_contains($adm->get('/install')['body'], 'admin_password')));

// --- nada interno se sirve
foreach (['/data/config.php', '/data/configurator.sqlite', '/app/core.php', '/app/bootstrap.php', '/tests/run.php', '/../data/config.php', '/%2e%2e/data/config.php', '/LICENSE'] as $p) {
    check("no se sirve $p", function () use ($adm, $p) {
        $r = $adm->get($p);
        return eq(404, $r['status']) && eq(false, str_contains($r['body'], 'secret') || str_contains($r['body'], 'SQLite format') || str_contains($r['body'], 'declare(strict_types'));
    });
}
check('los estáticos de public/ sí se sirven', fn() => eq(200, $adm->get('/assets/app.css')['status']));

// --- cabeceras
check('cabeceras: CSP sin JS en línea, no incrustable, nosniff', function () use ($adm) {
    $h = $adm->get('/')['headers'];
    return eq(true, str_contains($h, "script-src 'self';") && str_contains($h, 'X-Frame-Options: DENY') && str_contains($h, 'nosniff') && str_contains($h, "frame-ancestors 'none'"));
});
check('la cookie de sesión es HttpOnly y SameSite=Lax', function () use ($BASE) {
    $b = new Browser($BASE);
    $h = $b->get('/')['headers'];
    return eq(1, preg_match('/Set-Cookie: uc_session=[^;]+;.*HttpOnly/i', $h)) && eq(1, preg_match('/SameSite=Lax/i', $h));
});

// --- login de admin (navegador nuevo: el de la instalación ya quedó con sesión)
check('tras instalar, el instalador dejó la sesión del admin abierta', fn() => eq(200, $adm->get('/admin')['status']));
$adm = new Browser($BASE);
$adminCsrf = '';
check('POST de login sin CSRF: 419', fn() => eq(419, $adm->post('/entrar', ['email' => 'admin@demo.test', 'password' => 'admin-12345'])['status']));
check('login de admin lleva al panel', function () use ($adm, &$adminCsrf) {
    $r = $adm->post('/entrar', ['_csrf' => Browser::csrf($adm->get('/')['body']), 'email' => 'admin@demo.test', 'password' => 'admin-12345']);
    $p = $adm->get('/admin');
    $adminCsrf = Browser::csrf($p['body']);
    return eq('/admin', loc($r)) && eq(200, $p['status']) && eq(true, str_contains($p['body'], 'Borradores'));
});
check('tras el login queda la cookie de dispositivo de confianza (HttpOnly, SameSite=Lax, 90 días)', function () use ($BASE) {
    $b = new Browser($BASE);
    $r = $b->post('/entrar', ['_csrf' => Browser::csrf($b->get('/')['body']), 'email' => 'admin@demo.test', 'password' => 'admin-12345']);
    return eq(1, preg_match('/Set-Cookie: uc_trust=\d+\.\d+\.[A-Za-z0-9_-]{16}\.[a-f0-9]{64};[^\n]*HttpOnly/i', $r['headers'])) && eq(1, preg_match('/uc_trust=[^\n]*SameSite=Lax/i', $r['headers']));
});
check('el panel pinta catálogo, tipos, ajustes, tokens y actividad', function () use ($adm) {
    foreach (['/admin/catalogo' => 'Línea', '/admin/tipos' => 'Cajonera 3 cajones', '/admin/ajustes' => 'IVA', '/admin/tokens' => 'Nuevo token', '/admin/actividad' => 'sample.load', '/admin/usuarios' => 'admin@demo.test', '/admin/configuraciones' => 'Filtrar'] as $p => $txt) {
        $r = $adm->get($p);
        if ($r['status'] !== 200 || !str_contains($r['body'], $txt)) throw new RuntimeException("$p: {$r['status']}");
    }
    return true;
});
check('panel: el aviso de huecos es informativo (se cobra como el tramo siguiente)', function () use ($adm) {
    $p = $adm->get('/admin/tipos/3')['body'];
    return eq(true, str_contains($p, '50,1–50,9 cm se cobra como el tramo siguiente (51–60)'));
});
check('tramo que solapa en el panel: aviso y no se guarda', function () use ($adm, $adminCsrf) {
    $tid = 1;
    $r = $adm->post("/admin/tipos/$tid/tramo", ['_csrf' => $adminCsrf, 'from_cm' => '35', 'to_cm' => '45', 'price' => '99', 'node_id' => '1']); // los tramos de cocina cuelgan de la raíz «Cocina»
    $page = $adm->get("/admin/tipos/$tid")['body'];
    return eq(303, $r['status']) && eq(true, str_contains($page, 'se solapa'));
});

check('subir imagen de un nodo: PNG de verdad se guarda y se sirve sin ejecutar nada; SVG con script se rechaza', function () use ($adm, $adminCsrf) {
    $im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); $png = (string) ob_get_clean();
    $r = $adm->upload('/admin/catalogo/2/imagen', ['_csrf' => $adminCsrf], 'imagen', 'foto.png', $png, 'image/png');
    $page = $adm->get('/admin/catalogo/2')['body'];
    if (!preg_match('#src="(/media/[a-f0-9]{24}\.png)"#', $page, $m)) throw new RuntimeException('no aparece la imagen subida');
    $img = $adm->get($m[1]);
    $svg = $adm->upload('/admin/catalogo/2/imagen', ['_csrf' => $adminCsrf], 'imagen', 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');
    $after = $adm->get('/admin/catalogo/2')['body'];
    $noCsrf = $adm->upload('/admin/catalogo/2/imagen', [], 'imagen', 'foto.png', $png, 'image/png');
    return eq(303, $r['status']) && eq(200, $img['status']) && eq(true, str_contains($img['headers'], 'image/png'))
        && eq(true, str_contains($img['headers'], 'nosniff')) && eq(true, str_contains($img['headers'], 'sandbox'))
        && eq(303, $svg['status']) && eq(true, str_contains($after, 'Los SVG no se admiten')) && eq(true, str_contains($after, $m[1]))
        && eq(true, in_array($noCsrf['status'], [403, 419], true));
});
check('composición por defecto: solo ofrece los módulos de su familia', function () use ($adm) {
    $p = $adm->get('/admin/composicion/2')['body'];
    return eq(true, str_contains($p, 'Bajo 1 puerta (B1P)')) && eq(false, str_contains($p, 'Armario corredera')) && eq(false, str_contains($p, 'Columna de baño'));
});
check('/media no sirve nada fuera de su carpeta', fn() => eq(404, $adm->get('/media/../uc.sqlite')['status']) && eq(404, $adm->get('/media/abc.php')['status']));

// --- alta de comerciales por invitación
$invite = function (string $email, string $name) use ($adm, $adminCsrf) {
    $adm->post('/admin/usuarios/crear', ['_csrf' => $adminCsrf, 'name' => $name, 'email' => $email, 'role' => 'comercial']);
    $page = $adm->get('/admin/usuarios')['body'];
    if (!preg_match('#/restablecer\?token=([a-f0-9]+)#', $page, $m)) throw new RuntimeException('no aparece el enlace');
    $b = new Browser($GLOBALS['BASE']);
    $form = $b->get('/restablecer?token=' . $m[1]);
    $r = $b->post('/restablecer', ['_csrf' => Browser::csrf($form['body']), 'token' => $m[1], 'password' => 'comercial-123', 'password2' => 'comercial-123']);
    if (loc($r) !== '/configurar') throw new RuntimeException('restablecer: ' . $r['status'] . ' ' . loc($r));
    return $b;
};
$ana = $beto = null;
check('alta de comercial: el enlace para poner contraseña abre sesión', function () use ($invite, &$ana, &$beto) {
    $ana = $invite('ana@demo.test', 'Ana');
    $beto = $invite('beto@demo.test', 'Beto');
    return eq(200, $ana->get('/configurar')['status']);
});
check('un comercial no entra en el panel', fn() => eq(403, $ana->get('/admin')['status']));

// --- configurar como comercial: paso 1 por formulario, cambios y aceptar por la API
$code = '';
$apiCsrf = '';
check('paso 1: elegir hasta un nodo final crea el borrador', function () use ($ana, &$code) {
    $home = $ana->get('/configurar')['body'];
    preg_match('#/configurar\?nodo=(\d+)#', $home, $m1);
    $models = $ana->get('/configurar?nodo=' . $m1[1])['body'];
    if (!preg_match('#/configurar\?nodo=(\d+)">(?:(?!</a>).)*?<span class="choice-name">Dúo<#s', $models, $m2)) throw new RuntimeException('no aparece Dúo');
    $duo = $m2[1];
    $colors = $ana->get('/configurar?nodo=' . $duo)['body'];
    preg_match('#name="node_id" value="(\d+)"#', $colors, $m3);
    $r = $ana->post('/configurar', ['_csrf' => Browser::csrf($colors), 'node_id' => $m3[1]]);
    $code = basename(loc($r));
    return eq(303, $r['status']) && eq(1, preg_match('/^UC-[A-Z2-9]{8}$/', $code));
});
check('paso 2 lleva los datos para la tablet (config, tipos y CSRF)', function () use ($ana, $code, &$apiCsrf) {
    $p = $ana->get("/configurar/$code")['body'];
    preg_match('#<script type="application/json" id="uc-data">(.*?)</script>#s', $p, $m);
    $d = json_decode($m[1], true);
    $apiCsrf = $d['csrf'];
    return eq($code, $d['config']['code']) && eq(true, count($d['types']) >= 9) && eq(true, strlen($apiCsrf) === 32);
});
$first = null;
check('API: leer la configuración (paredes en L con geometría)', function () use ($ana, $code, &$first) {
    $r = $ana->api('GET', "/api/configurations/$code");
    $first = $r['json']['config'];
    return eq(200, $r['status']) && eq(['x', 'y'], array_slice(array_column($first['walls'], 'direction'), 0, 2));
});
check('API: cambiar ancho, añadir, sustituir y quitar; el total lo calcula el servidor', function () use ($ana, $code, $apiCsrf, &$first) {
    $w = $first['walls'][0];
    $it = $w['items'][0];
    $r = $ana->api('POST', "/api/configurations/$code/ops", ['ops' => [
        ['op' => 'set_width', 'item_id' => $it['id'], 'width_cm' => 100],
        ['op' => 'add', 'wall_id' => $w['id'], 'module_type_id' => $w['items'][1]['module_type_id']],
        ['op' => 'customer', 'name' => 'Lucía Pérez', 'email' => 'lucia@cliente.test', 'phone' => '600 000 000'],
    ]], $apiCsrf);
    $after = $r['json']['config'];
    $last = end($after['walls'][0]['items']);
    $r2 = $ana->api('POST', "/api/configurations/$code/ops", ['op' => 'remove', 'item_id' => $last['id']], $apiCsrf);
    return eq(200, $r['status']) && eq(100, $after['walls'][0]['items'][0]['width_cm']) && eq(true, $after['total'] > $first['total'])
        && eq(200, $r2['status']) && eq($after['count'] - 1, $r2['json']['config']['count']);
});
check('API: medida sin tramo → 422 con los tramos; no cambia nada', function () use ($ana, $code, $apiCsrf, $first) {
    $it = $first['walls'][0]['items'][0];
    $r = $ana->api('POST', "/api/configurations/$code/ops", ['op' => 'set_width', 'item_id' => $it['id'], 'width_cm' => 59], $apiCsrf);
    $now = $ana->api('GET', "/api/configurations/$code")['json']['config'];
    return eq(422, $r['status']) && eq(true, count($r['json']['tiers']) > 0) && eq(100, $now['walls'][0]['items'][0]['width_cm']);
});
check('API: POST sin cabecera CSRF → 419', fn() => eq(419, $ana->api('POST', "/api/configurations/$code/ops", ['op' => 'customer', 'name' => 'X'])['status']));

// --- Beto contra Ana
check('Beto no ve la de Ana (pantalla y API)', fn() => eq(404, $beto->get("/configurar/$code")['status']) && eq(404, $beto->api('GET', "/api/configurations/$code")['status']));
check('Beto no la cambia ni la acepta por la API', function () use ($beto, $code) {
    $p = $beto->get('/configurar')['body'];
    $tok = Browser::csrf($p); // mismo token de sesión
    return eq(404, $beto->api('POST', "/api/configurations/$code/ops", ['op' => 'customer', 'name' => 'Hack'], $tok)['status'])
        && eq(404, $beto->api('POST', "/api/configurations/$code/accept", [], $tok)['status']);
});
check('Beto no ve la de Ana en «Mis configuraciones»', fn() => eq(false, str_contains($beto->get('/mis-configuraciones')['body'], $code)));

// --- aceptar
$acceptedTotal = 0.0;
check('API: aceptar congela; luego no se puede cambiar (409)', function () use ($ana, $code, $apiCsrf, &$acceptedTotal) {
    $r = $ana->api('POST', "/api/configurations/$code/accept", [], $apiCsrf);
    $acceptedTotal = $r['json']['config']['total'];
    $r2 = $ana->api('POST', "/api/configurations/$code/ops", ['op' => 'customer', 'name' => 'Otra'], $apiCsrf);
    return eq(200, $r['status']) && eq('accepted', $r['json']['config']['status']) && eq(409, $r2['status']);
});
check('el resumen de la aceptada se ve y ofrece duplicar', function () use ($ana, $code) {
    $p = $ana->get("/configurar/$code/resumen")['body'];
    return eq(true, str_contains($p, 'Aceptada') && str_contains($p, 'Duplicar'));
});

// --- descargas del admin
check('el admin descarga JSON y CSV de la configuración', function () use ($adm, $code, $acceptedTotal) {
    $j = $adm->get("/admin/configuraciones/$code.json");
    $c = $adm->get("/admin/configuraciones/$code.csv");
    $l = $adm->get('/admin/configuraciones.csv?status=accepted');
    return eq(200, $j['status']) && eq($acceptedTotal, json_decode($j['body'], true)['total']) && eq(true, str_contains($j['headers'], 'attachment'))
        && eq(true, str_contains($c['body'], 'Lucía Pérez')) && eq(true, str_contains($l['body'], $code));
});
check('un comercial no descarga desde el panel', fn() => eq(403, $ana->get("/admin/configuraciones/$code.json")['status']));

// --- MCP con un token creado en el panel (se ve una sola vez)
$tokAna = '';
check('token MCP: se ve una sola vez', function () use ($adm, $adminCsrf, &$tokAna) {
    $users = $adm->get('/admin/tokens')['body'];
    preg_match('#<option value="(\d+)"[^>]*>Ana#', $users, $m);
    $adm->post('/admin/tokens/crear', ['_csrf' => $adminCsrf, 'name' => 'ADRI de Ana', 'user_id' => $m[1], 'scopes' => ['read', 'write']]);
    $first = $adm->get('/admin/tokens')['body'];
    preg_match('#value="(uc_[a-f0-9]{40})"#', $first, $t);
    $tokAna = $t[1] ?? '';
    $second = $adm->get('/admin/tokens')['body'];
    return eq(true, $tokAna !== '') && eq(false, str_contains($second, $tokAna));
});
$mcp = function (string $tok, string $method, array $params = []) use ($BASE) {
    $b = new Browser($BASE);
    return $b->req('POST', '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]), ['Content-Type: application/json', "Authorization: Bearer $tok"]);
};
check('MCP por HTTP: sin token 401; initialize con instrucciones', function () use ($mcp, $tokAna) {
    $bad = $mcp('uc_' . str_repeat('a', 40), 'ping');
    $ok = $mcp($tokAna, 'initialize', ['protocolVersion' => '2025-06-18']);
    return eq(401, $bad['status']) && eq(true, str_contains((string) ($ok['json']['result']['instructions'] ?? ''), 'config_accept'));
});
check('MCP por HTTP: config_get de la de Ana con su token', function () use ($mcp, $tokAna, $code, $acceptedTotal) {
    $r = $mcp($tokAna, 'tools/call', ['name' => 'config_get', 'arguments' => ['code' => $code]]);
    return eq(false, $r['json']['result']['isError']) && eq($acceptedTotal, $r['json']['result']['structuredContent']['config']['total']);
});
check('el registro de actividad recoge el uso del MCP', fn() => eq(true, str_contains($adm->get('/admin/actividad')['body'], 'MCP: ADRI de Ana')));

// --- panel: cada formulario hace lo que dice
$idFrom = function (string $html, string $re) { if (!preg_match($re, $html, $m)) throw new RuntimeException("no encontrado: $re"); return (int) $m[1]; };
$armario = 0;
check('panel: crear, editar y desactivar un nodo', function () use ($adm, $adminCsrf, $idFrom, &$armario) {
    $adm->post('/admin/catalogo/crear', ['_csrf' => $adminCsrf, 'name' => 'Vestidor', 'description' => 'A medida']);
    $armario = $idFrom($adm->get('/admin/catalogo')['body'], '#/admin/catalogo/(\d+)">Vestidor<#');
    $adm->post("/admin/catalogo/$armario", ['_csrf' => $adminCsrf, 'name' => 'Vestidores', 'slug' => '', 'description' => 'A medida', 'image' => '#AABBCC']);
    $adm->post("/admin/catalogo/$armario/activar", ['_csrf' => $adminCsrf, 'back' => "/catalogo/$armario", 'active' => '']);
    $p = $adm->get("/admin/catalogo/$armario")['body'];
    $adm->post("/admin/catalogo/$armario/activar", ['_csrf' => $adminCsrf, 'back' => "/catalogo/$armario", 'active' => '1']);
    return eq(true, str_contains($p, 'Vestidores') && str_contains($p, 'Desactivado'));
});
$puerta = 0;
check('panel: hijo, mover y borrar', function () use ($adm, $adminCsrf, $idFrom, &$armario, &$puerta) {
    $adm->post('/admin/catalogo/crear', ['_csrf' => $adminCsrf, 'parent_id' => $armario, 'name' => 'Batiente']);
    $adm->post('/admin/catalogo/crear', ['_csrf' => $adminCsrf, 'parent_id' => $armario, 'name' => 'Corredera']);
    $p = $adm->get("/admin/catalogo/$armario")['body'];
    $puerta = $idFrom($p, '#/admin/catalogo/(\d+)">Batiente<#');
    $corr = $idFrom($p, '#/admin/catalogo/(\d+)">Corredera<#');
    $r = $adm->post("/admin/catalogo/$corr/mover", ['_csrf' => $adminCsrf, 'dir' => '-1', 'back' => "/catalogo/$armario"]);
    $p2 = $adm->get("/admin/catalogo/$armario")['body'];
    $d = $adm->post("/admin/catalogo/$corr/borrar", ['_csrf' => $adminCsrf]);
    return eq(303, $r['status']) && eq(true, strpos($p2, '>Corredera<') < strpos($p2, '>Batiente<'), 'Corredera subió') && eq(303, $d['status'])
        && eq(false, str_contains($adm->get("/admin/catalogo/$armario")['body'], '>Corredera<'));
});
check('panel: tipo nuevo con su tramo; el tramo se ve', function () use ($adm, $adminCsrf, &$zap) {
    $r = $adm->post('/admin/tipos/crear', ['_csrf' => $adminCsrf, 'name' => 'Zapatero', 'ref' => 'ZAP', 'family' => 'bajo', 'depth_mm' => '350']);
    $tid = (int) basename(loc($r));
    $GLOBALS['zap'] = $tid;
    $adm->post("/admin/tipos/$tid/tramo", ['_csrf' => $adminCsrf, 'from_cm' => '40', 'to_cm' => '60', 'price' => '120,50', 'node_id' => '']);
    $p = $adm->get("/admin/tipos/$tid")['body'];
    return eq(true, $tid > 0) && eq(true, str_contains($p, 'value="120,50"'));
});
check('panel: composición con una pared; ancho sin tramo avisa y no se guarda', function () use ($adm, $adminCsrf, $idFrom, &$puerta) {
    $adm->post("/admin/composicion/$puerta/pared", ['_csrf' => $adminCsrf, 'name' => 'Hueco', 'direction' => 'x', 'origin_x_mm' => '0', 'origin_y_mm' => '0']);
    $p = $adm->get("/admin/composicion/$puerta")['body'];
    $wid = $idFrom($p, "#/admin/composicion/$puerta/pared/(\d+)\"#");
    $zap = $GLOBALS['zap'];
    $adm->post("/admin/composicion/$puerta/modulo", ['_csrf' => $adminCsrf, 'wall_id' => $wid, 'module_type_id' => $zap, 'width_cm' => '90']);
    $bad = $adm->get("/admin/composicion/$puerta")['body'];
    $adm->post("/admin/composicion/$puerta/modulo", ['_csrf' => $adminCsrf, 'wall_id' => $wid, 'module_type_id' => $zap, 'width_cm' => '50']);
    $good = $adm->get("/admin/composicion/$puerta")['body'];
    return eq(true, str_contains($bad, 'no es una medida válida')) && eq(true, str_contains($good, '120,50 €'));
});
check('panel: el nodo nuevo se configura en la tablet con su composición', function () use ($ana, &$armario, &$puerta) {
    $p = $ana->get('/configurar?nodo=' . $armario)['body'];
    $r = $ana->post('/configurar', ['_csrf' => Browser::csrf($p), 'node_id' => $puerta]);
    $cfg = $ana->api('GET', '/api/configurations/' . basename(loc($r)))['json']['config'];
    return eq(true, str_contains($p, 'Batiente')) && eq(1, $cfg['count']) && eq('Zapatero', $cfg['walls'][0]['items'][0]['type_name']) && eq(120.5, $cfg['total']);
});
check('panel: ajustes (IVA) se guardan y se ven en el resumen', function () use ($adm, $adminCsrf) {
    $r = $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '10', 'mail_transport' => 'log', 'smtp_port' => '587', 'url_publica' => $GLOBALS['BASE']]);
    $p = $adm->get('/admin/ajustes')['body'];
    $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '21', 'mail_transport' => 'log', 'smtp_port' => '587', 'url_publica' => $GLOBALS['BASE']]);
    return eq(303, $r['status']) && eq(true, str_contains($p, 'name="iva" inputmode="decimal" value="10"'));
});
check('panel: ajuste no válido avisa y no se guarda', function () use ($adm, $adminCsrf) {
    $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '150', 'mail_transport' => 'log']);
    $p = $adm->get('/admin/ajustes')['body'];
    return eq(true, str_contains($p, 'porcentaje entre 0 y 100') && str_contains($p, 'value="21"'));
});
check('«olvidé» con Host: evil.example: el enlace del correo lleva la URL pública', function () use ($BASE, $data) {
    $b = new Browser($BASE);
    $g = $b->get('/olvide');
    preg_match('/Set-Cookie: (uc_session=[^;]+)/i', $g['headers'], $ck); // con otro Host, curl no manda sola la cookie
    $r = $b->req('POST', '/olvide', http_build_query(['_csrf' => Browser::csrf($g['body']), 'email' => 'ana@demo.test']), ['Host: evil.example', "Cookie: {$ck[1]}", 'Content-Type: application/x-www-form-urlencoded']);
    $log = (string) @file_get_contents("$data/mail.log");
    return eq(303, $r['status'], 'POST /olvide') && eq(true, str_contains($log, $BASE . '/restablecer?token=')) && eq(false, str_contains($log, 'evil.example'));
});
check('panel: cambiar el rol de una persona', function () use ($adm, $adminCsrf, $idFrom) {
    $p = $adm->get('/admin/usuarios')['body'];
    $bid = $idFrom($p, '#value="Beto".*?action="/admin/usuarios/(\d+)"#s');
    $adm->post("/admin/usuarios/$bid", ['_csrf' => $adminCsrf, 'name' => 'Beto', 'role' => 'cliente', 'active' => '1']);
    $after = (string) $adm->get('/admin/usuarios')['body'];
    $adm->post("/admin/usuarios/$bid", ['_csrf' => $adminCsrf, 'name' => 'Beto', 'role' => 'comercial', 'active' => '1']);
    return eq(1, preg_match('#value="Beto".*?<option value="cliente" selected#s', $after));
});
check('panel: ver y anular una configuración', function () use ($adm, $adminCsrf, $code) {
    $v = $adm->get("/admin/configuraciones/$code");
    $adm->post("/admin/configuraciones/$code/anular", ['_csrf' => $adminCsrf]);
    $after = $adm->get("/admin/configuraciones/$code")['body'];
    return eq(200, $v['status']) && eq(true, str_contains($v['body'], 'avanza en x desde')) && eq(true, str_contains($after, 'badge-cancelled'));
});
check('panel: revocar el token deja el MCP en 401', function () use ($adm, $adminCsrf, $mcp, $idFrom, &$tokAna) {
    $tid = $idFrom($adm->get('/admin/tokens')['body'], '#/admin/tokens/(\d+)/revocar#');
    $adm->post("/admin/tokens/$tid/revocar", ['_csrf' => $adminCsrf]);
    return eq(401, $mcp($tokAna, 'ping')['status']);
});

check('panel: medidas del tipo se guardan; mínimo mayor que máximo avisa y no se guarda', function () use ($adm, $adminCsrf) {
    $tid = $GLOBALS['zap'];
    $base = ['_csrf' => $adminCsrf, 'name' => 'Zapatero', 'ref' => 'ZAP', 'family' => 'bajo', 'width_mm' => '500', 'height_mm' => '720', 'depth_mm' => '350',
        'min_width_mm' => '400', 'max_width_mm' => '600', 'board_mm' => '18,5', 'back_mm' => '8', 'sort' => '0', 'description' => ''];
    $adm->post("/admin/tipos/$tid", $base);
    $ok = $adm->get("/admin/tipos/$tid")['body'];
    $adm->post("/admin/tipos/$tid", ['min_width_mm' => '700'] + $base);
    $bad = $adm->get("/admin/tipos/$tid")['body'];
    return eq(true, str_contains($ok, 'name="board_mm" inputmode="decimal" class="w-num" value="18,5"') && str_contains($ok, 'name="height_mm" min="1" class="w-num" value="720"'))
        && eq(true, str_contains($bad, 'mayor que el máximo')) && eq(true, str_contains($adm->get("/admin/tipos/$tid")['body'], 'name="min_width_mm" min="1" class="w-num" value="400"'));
});
check('panel: una pieza con fórmula mala avisa y no se guarda; una buena se guarda con su vista previa', function () use ($adm, $adminCsrf) {
    $tid = $GLOBALS['zap'];
    $adm->post("/admin/tipos/$tid/pieza", ['_csrf' => $adminCsrf, 'name' => 'Lateral', 'qty' => '2', 'thickness' => 'T', 'length_expr' => 'W - ancho', 'width_expr' => 'D', 'grain' => 'largo', 'edge_banding' => '1L']);
    $bad = $adm->get("/admin/tipos/$tid")['body'];
    $adm->post("/admin/tipos/$tid/pieza", ['_csrf' => $adminCsrf, 'name' => 'Lateral', 'qty' => '2', 'thickness' => 'T', 'length_expr' => 'H', 'width_expr' => 'D - 20', 'grain' => 'largo', 'edge_banding' => '1L']);
    $good = $adm->get("/admin/tipos/$tid")['body'];
    return eq(true, str_contains($bad, 'Variable desconocida')) && eq(0, preg_match("#/admin/tipos/$tid/pieza/\\d+\"#", $bad), 'no se guardó ninguna pieza')
        && eq(true, str_contains($good, 'value="Lateral"') && str_contains($good, '2 × 18,5 · 720 × 330 mm'));
});
check('panel: editar y borrar una pieza', function () use ($adm, $adminCsrf, $idFrom) {
    $tid = $GLOBALS['zap'];
    $pid = $idFrom($adm->get("/admin/tipos/$tid")['body'], "#/admin/tipos/$tid/pieza/(\d+)\"#");
    $adm->post("/admin/tipos/$tid/pieza/$pid", ['_csrf' => $adminCsrf, 'name' => 'Costado', 'qty' => '2', 'thickness' => 'T', 'length_expr' => 'H - 10', 'width_expr' => 'D - 20', 'grain' => 'largo', 'edge_banding' => '', 'sort' => '10']);
    $edited = $adm->get("/admin/tipos/$tid")['body'];
    $adm->post("/admin/tipos/$tid/pieza/$pid/borrar", ['_csrf' => $adminCsrf]);
    $gone = $adm->get("/admin/tipos/$tid")['body'];
    return eq(true, str_contains($edited, 'value="Costado"') && str_contains($edited, '710 × 330')) && eq(false, str_contains($gone, 'value="Costado"'));
});
check('panel: descargas de despiece y de lista de corte', function () use ($adm, $code) {
    $p = $adm->get("/admin/configuraciones/$code-despiece.csv");
    $c = $adm->get("/admin/configuraciones/$code-corte.csv");
    return eq(200, $p['status']) && eq(true, str_contains($p['headers'], 'attachment') && str_contains($p['body'], 'pieza;cantidad;espesor_mm;largo_mm;ancho_mm'))
        && eq(true, str_contains($p['body'], ';Lateral;2;19;720;560;')) && eq(200, $c['status']) && eq(true, str_contains($c['body'], 'Lateral') && str_contains($c['headers'], 'attachment'));
});
check('registro por HTTP: mismo mensaje exista o no; el enlace del correo activa la cuenta y entra', function () use ($adm, $adminCsrf, $BASE, $data) {
    $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '21', 'mail_transport' => 'log', 'smtp_port' => '587', 'url_publica' => $BASE, 'permitir_registro' => '1']);
    $b = new Browser($BASE);
    $f = $b->get('/registro')['body'];
    $r1 = $b->post('/registro', ['_csrf' => Browser::csrf($f), 'name' => 'Rosa', 'email' => 'rosa@demo.test']);
    $msg1 = $b->get('/')['body'];
    $r2 = $b->post('/registro', ['_csrf' => Browser::csrf($b->get('/registro')['body']), 'name' => 'Otra', 'email' => 'ana@demo.test']);
    $msg2 = $b->get('/')['body'];
    preg_match_all('#/restablecer\?token=([a-f0-9]+)#', (string) file_get_contents("$data/mail.log"), $m);
    $tok = end($m[1]);
    $form = $b->get('/restablecer?token=' . $tok);
    $r3 = $b->post('/restablecer', ['_csrf' => Browser::csrf($form['body']), 'token' => $tok, 'password' => 'rosa-clave-123', 'password2' => 'rosa-clave-123']);
    $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '21', 'mail_transport' => 'log', 'smtp_port' => '587', 'url_publica' => $BASE]);
    return eq(303, $r1['status']) && eq(303, $r2['status']) && eq(true, str_contains($msg1, 'Si el correo es válido') && str_contains($msg2, 'Si el correo es válido'))
        && eq('/configurar', loc($r3)) && eq(false, str_contains($form['body'], 'caducado'));
});

// --- cliente final (v0.3): portada, paso 1 sin niveles de una opción, paso 2 sin jerga, pedir el presupuesto, enlace
$setGuest = function (bool $on) use ($adm, $adminCsrf, $BASE) {
    $adm->post('/admin/ajustes', ['_csrf' => $adminCsrf, 'empresa' => 'Cocinas Demo', 'iva' => '21', 'mail_transport' => 'log', 'smtp_port' => '587', 'url_publica' => $BASE] + ($on ? ['permitir_invitado' => '1'] : []));
};
check('portada: con invitado permitido, «Empezar» primero y «Entrar» discreto; sin él, el formulario de entrar', function () use ($setGuest, $BASE) {
    $setGuest(true);
    $on = (new Browser($BASE))->get('/')['body'];
    $setGuest(false);
    $off = (new Browser($BASE))->get('/')['body'];
    $team = (new Browser($BASE))->get('/entrar');
    return eq(true, str_contains($on, 'action="/invitado"') && str_contains($on, 'Empezar') && str_contains($on, 'href="/entrar"')) && eq(false, str_contains($on, 'name="password"'))
        && eq(true, str_contains($off, 'name="password"')) && eq(200, $team['status']) && eq(true, str_contains($team['body'], 'name="password"'));
});
$guestB = null;
$gcode = '';
check('invitado: menú sin «Mis configuraciones» ni «Salir»; paso 2 sin código ni referencias a la vista', function () use ($setGuest, $BASE, &$guestB, &$gcode) {
    $setGuest(true);
    $guestB = new Browser($BASE);
    $home = $guestB->get('/')['body'];
    $guestB->post('/invitado', ['_csrf' => Browser::csrf($home)]);
    $p1 = $guestB->get('/configurar')['body'];
    preg_match('#/configurar\?nodo=(\d+)#', $p1, $m);
    $models = $guestB->get('/configurar?nodo=' . $m[1])['body'];
    preg_match('#/configurar\?nodo=(\d+)">(?:(?!</a>).)*?<span class="choice-name">Dúo<#s', $models, $m2);
    $colors = $guestB->get('/configurar?nodo=' . $m2[1])['body'];
    preg_match('#name="node_id" value="(\d+)"#', $colors, $m3);
    $gcode = basename(loc($guestB->post('/configurar', ['_csrf' => Browser::csrf($colors), 'node_id' => $m3[1]])));
    $p2 = $guestB->get("/configurar/$gcode")['body'];
    $visible = preg_replace('#<script type="application/json".*?</script>#s', '', $p2);
    return eq(false, str_contains($p1, '/mis-configuraciones'), 'mis') && eq(false, str_contains($p1, 'action="/salir"'), 'salir') && eq(true, str_contains($p1, 'Empezar de nuevo'))
        && eq(false, (bool) preg_match('#>[^<]*' . $gcode . '#', $visible), 'código a la vista') && eq(false, str_contains($visible, 'Borrador'), 'borrador') && eq(true, str_contains($p2, 'configurador.js'));
});
check('cada pantalla del cliente pesa menos de 100 KB (HTML + CSS + JS, sin comprimir)', function () use (&$guestB, &$gcode, $BASE) {
    $assets = 0;
    foreach (['/assets/app.css', '/assets/app.js', '/assets/configurador.js'] as $a) $assets += strlen((new Browser($BASE))->get($a)['body']);
    $sizes = [strlen($guestB->get('/configurar')['body']), strlen($guestB->get("/configurar/$gcode")['body']), strlen($guestB->get("/configurar/$gcode/resumen")['body'])];
    if (max($sizes) + $assets >= 100 * 1024) throw new RuntimeException('pesa ' . (max($sizes) + $assets) . ' bytes');
    return true;
});
check('paso 3 del invitado: «Quiero este presupuesto», sin «Aceptar»; aceptar a mano da 403', function () use (&$guestB, &$gcode) {
    $p = $guestB->get("/configurar/$gcode/resumen")['body'];
    $acc = $guestB->post("/configurar/$gcode/aceptar", ['_csrf' => Browser::csrf($p)]);
    $after = $guestB->get("/configurar/$gcode/resumen")['body'];
    return eq(true, str_contains($p, 'Quiero este presupuesto') && str_contains($p, 'name="website"')) && eq(false, str_contains($p, 'Aceptar configuración'))
        && eq(303, $acc['status']) && eq(true, str_contains($after, 'comercial o del administrador'));
});
$shareUrl = '';
check('WhatsApp sin JavaScript: el formulario lleva al enlace firmado con la URL pública y al botón de WhatsApp', function () use (&$guestB, &$gcode, &$shareUrl, $BASE) {
    $p = $guestB->get("/configurar/$gcode/resumen")['body'];
    $guestB->post("/configurar/$gcode/compartir", ['_csrf' => Browser::csrf($p)]);
    $after = $guestB->get("/configurar/$gcode/resumen")['body'];
    if (!preg_match('#value="(' . preg_quote($BASE, '#') . '/ver/UC-[A-Z2-9]{8}\.\d+\.[a-f0-9]{32})"#', $after, $m)) throw new RuntimeException('sin enlace');
    $shareUrl = $m[1];
    return eq(true, str_contains($after, 'https://wa.me/?text='));
});
check('pedir el presupuesto: honeypot no guarda; con datos y consentimiento queda pedido y lo dice', function () use (&$guestB, &$gcode, $adm) {
    $p = $guestB->get("/configurar/$gcode/resumen")['body'];
    $guestB->post("/configurar/$gcode/pedir", ['_csrf' => Browser::csrf($p), 'name' => 'Bot', 'phone' => '600 000 000', 'consent' => '1', 'website' => 'x']);
    $still = $guestB->get("/configurar/$gcode/resumen")['body'];
    $r = $guestB->post("/configurar/$gcode/pedir", ['_csrf' => Browser::csrf($still), 'name' => 'Rocío Ibáñez', 'phone' => '677 120 984', 'email' => '', 'consent' => '1']);
    $done = $guestB->get("/configurar/$gcode/resumen")['body'];
    return eq(true, str_contains($still, 'Quiero este presupuesto')) && eq(303, $r['status']) && eq(true, str_contains($done, 'Te llamaremos'))
        && eq(true, str_contains($adm->get('/admin/configuraciones?status=requested')['body'], $gcode));
});
check('el enlace compartido abre sin sesión, sin datos del cliente; cambiado no abre', function () use (&$shareUrl, $BASE) {
    $path = substr($shareUrl, strlen($BASE));
    $r = (new Browser($BASE))->get($path);
    $bad = (new Browser($BASE))->get(substr($path, 0, -1) . (str_ends_with($path, '0') ? '1' : '0'));
    return eq(200, $r['status']) && eq(false, str_contains($r['body'], 'Rocío') || str_contains($r['body'], '677 120')) && eq(true, str_contains($r['body'], 'noindex'))
        && eq(true, str_contains($r['headers'], 'no-referrer')) && eq(404, $bad['status']);
});
check('un nivel con una sola opción no se pregunta y «Atrás» no lleva a un bucle', function () use ($adm, $adminCsrf, &$guestB) {
    $roots = [];
    preg_match_all('#/admin/catalogo/(\d+)"[^>]*>([^<]+)</a>#', $adm->get('/admin/catalogo')['body'], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) if (in_array(trim($x[2]), ['Armarios', 'Baños', 'Vestidores'], true)) $roots[trim($x[2])] ??= $x[1]; // la raíz sale antes que cualquier hijo
    $roots = array_values($roots);
    $toggle = fn(string $v) => array_map(fn($id) => $adm->post("/admin/catalogo/$id/activar", ['_csrf' => $adminCsrf, 'active' => $v, 'back' => '/catalogo']), $roots);
    $res = $toggle('');
    $r = $guestB->get('/configurar');
    if ($r['status'] !== 303) throw new RuntimeException('roots=' . json_encode($roots) . ' toggle=' . json_encode(array_map(fn($x) => [$x['status'], loc($x)], $res)) . ' choices=' . json_encode(preg_match_all('#choice-name">([^<]+)#', $r['body'], $cn) ? $cn[1] : []) . ' url=' . loc($r));
    $page = $guestB->get(loc($r))['body'];
    $toggle('1');
    return eq(3, count($roots)) && eq(303, $r['status']) && eq(1, preg_match('#^/configurar\?nodo=\d+$#', loc($r))) && eq(true, str_contains($page, 'Elige el modelo'))
        && eq(false, str_contains($page, '← '));
});

check('G: con la base bloqueada durante una actualización de esquema, 503 «actualizando» sin trazas', function () use ($BASE, $data) {
    $pdo = new PDO('sqlite:' . $data . '/configurator.sqlite');
    $pdo->exec("UPDATE meta SET value = '4' WHERE key = 'schema_version'");
    $pdo->exec('BEGIN EXCLUSIVE');
    $b = new Browser($BASE);
    $r = $b->get('/');
    $pdo->exec('ROLLBACK');
    $pdo->exec("UPDATE meta SET value = '" . UC_SCHEMA_EXPECTED . "' WHERE key = 'schema_version'");
    return eq(503, $r['status']) && eq(true, str_contains($r['body'], 'Actualizando')) && eq(false, str_contains($r['body'], '#0') || str_contains($r['body'], 'PDOException'));
});

// --- salir
check('salir cierra la sesión', function () use ($ana) {
    $ana->post('/salir', ['_csrf' => Browser::csrf($ana->get('/configurar')['body'])]);
    return eq(303, $ana->get('/configurar')['status']);
});

echo $fails ? "\n$fails de $count pruebas FALLAN\n" : "\n$count pruebas, todo bien\n";
if ($fails) echo "--- server.log ---\n" . @file_get_contents("$data/server.log");
exit($fails ? 1 : 0);
