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
check('instalar sin CSRF no instala', function () use ($adm, $data) {
    $adm->post('/install', ['empresa' => 'X', 'admin_name' => 'A', 'admin_email' => 'a@x.test', 'admin_password' => 'secreta123']);
    return eq(false, is_file("$data/config.php"));
});
check('instalar crea config.php, la base, el admin y los datos de ejemplo', function () use ($adm, $data) {
    $csrf = Browser::csrf($adm->get('/install')['body']);
    $r = $adm->post('/install', ['_csrf' => $csrf, 'empresa' => 'Cocinas Demo', 'timezone' => 'Europe/Madrid', 'admin_name' => 'Admin',
        'admin_email' => 'admin@demo.test', 'admin_password' => 'admin-12345', 'sample' => '1']);
    return eq(303, $r['status']) && eq('/admin', loc($r)) && eq(true, is_file("$data/config.php")) && eq(true, is_file("$data/configurator.sqlite"));
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
check('el panel pinta catálogo, tipos, ajustes, tokens y actividad', function () use ($adm) {
    foreach (['/admin/catalogo' => 'Línea', '/admin/tipos' => 'Cajonera 3 cajones', '/admin/ajustes' => 'IVA', '/admin/tokens' => 'Nuevo token', '/admin/actividad' => 'sample.load', '/admin/usuarios' => 'admin@demo.test', '/admin/configuraciones' => 'Filtrar'] as $p => $txt) {
        $r = $adm->get($p);
        if ($r['status'] !== 200 || !str_contains($r['body'], $txt)) throw new RuntimeException("$p: {$r['status']}");
    }
    return true;
});
check('tramo que solapa en el panel: aviso y no se guarda', function () use ($adm, $adminCsrf) {
    $tid = 1;
    $r = $adm->post("/admin/tipos/$tid/tramo", ['_csrf' => $adminCsrf, 'from_cm' => '35', 'to_cm' => '45', 'price' => '99', 'node_id' => '']);
    $page = $adm->get("/admin/tipos/$tid")['body'];
    return eq(303, $r['status']) && eq(true, str_contains($page, 'se solapa'));
});

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
    preg_match('#/configurar\?nodo=(\d+)"[^>]*>\s*<span class="node-name">Dúo#s', $models, $m2) || preg_match_all('#/configurar\?nodo=(\d+)#', $models, $m2b);
    $duo = $m2[1] ?? end($m2b[1]);
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

// --- salir
check('salir cierra la sesión', function () use ($ana) {
    $ana->post('/salir', ['_csrf' => Browser::csrf($ana->get('/configurar')['body'])]);
    return eq(303, $ana->get('/configurar')['status']);
});

echo $fails ? "\n$fails de $count pruebas FALLAN\n" : "\n$count pruebas, todo bien\n";
if ($fails) echo "--- server.log ---\n" . @file_get_contents("$data/server.log");
exit($fails ? 1 : 0);
