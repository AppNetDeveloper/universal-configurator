<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Lo que hace el cliente al terminar y lo que se trabaja después: aceptar solo el equipo, «Quiero este presupuesto»
// (precio guardado + lead, honeypot, consentimiento, límite por conexión), enlace firmado de solo lectura, estado de
// trabajo (nuevo, en curso, hecho) con permisos y sin tocar lo congelado, y deshacer desde la pantalla.
//   php tests/leads.php

require __DIR__ . '/_helper.php';

$c = fx_catalog();
$admin = fx_user('admin', 'admin@x.test');
$ana = fx_user('comercial', 'ana@x.test');
$beto = fx_user('comercial', 'beto@x.test');
$cli = fx_user('cliente', 'cli@x.test');
$va = ['kind' => 'user', 'user' => $ana];
$vb = ['kind' => 'user', 'user' => $beto];
$vc = ['kind' => 'user', 'user' => $cli];
$vadmin = ['kind' => 'user', 'user' => $admin];
settings_save(['url_publica' => 'https://configurador.example.com']);
if (!is_file(UC_DATA . '/config.php')) file_put_contents(UC_DATA . '/config.php', "<?php return ['secret' => '" . bin2hex(random_bytes(16)) . "'];");
$lead = fn(array $x = []) => $x + ['name' => 'Rocío Ibáñez', 'phone' => '677 120 984', 'email' => '', 'consent' => 1];
$clearIp = fn() => throttle_clear('lead:' . client_ip_key());

// --- aceptar es del equipo
check_error('un cliente con cuenta no acepta: 403', function () use ($c, $vc) { $cfg = config_create($c['blanco'], $vc); config_accept($cfg['code'], $vc); }, 'comercial');
check('un comercial acepta lo suyo y el trabajo queda «nuevo»', function () use ($c, $va) {
    $r = config_accept(config_create($c['blanco'], $va)['code'], $va);
    return eq('accepted', $r['status']) && eq('nuevo', $r['work_status']);
});

// --- «Quiero este presupuesto»
$req = config_create($c['blanco'], $vc);
check_error('sin nombre no se pide', fn() => config_request($req['code'], $vc, $lead(['name' => '  ']), false), 'nombre');
check_error('un teléfono raro se rechaza en llano', fn() => config_request($req['code'], $vc, $lead(['phone' => 'llámame']), false), 'teléfono');
check_error('un correo mal escrito se rechaza', fn() => config_request($req['code'], $vc, $lead(['email' => 'rocio@']), false), 'correo');
check_error('sin consentimiento no se guarda nada', fn() => config_request($req['code'], $vc, $lead(['consent' => 0]), false), 'protección de datos');
check('el honeypot (campo «website») finge que fue bien y no guarda nada', function () use ($req, $vc, $lead) {
    $r = config_request($req['code'], $vc, $lead(['website' => 'http://spam.example']), false);
    return eq(null, $r['lead_id']) && eq('draft', val('SELECT status FROM configurations WHERE code = ?', [$req['code']])) && eq(0, (int) val('SELECT COUNT(*) FROM leads'));
});
$before = config_get($req['code'], $vc);
$r = config_request($req['code'], $vc, $lead(['message' => "Por la tarde\npor favor"]), false);
check('pedir: precio congelado, bloqueada, datos del cliente y lead nuevo ligado', fn() => eq('requested', $r['config']['status']) && eq(true, $r['config']['frozen'])
    && eq($before['total_cents'], $r['config']['total_cents']) && eq('Rocío Ibáñez', $r['config']['customer']['name'])
    && eq((int) $cli['id'], (int) val('SELECT user_id FROM leads WHERE id = ?', [$r['lead_id']])) && eq("Por la tarde\npor favor", val('SELECT message FROM leads WHERE id = ?', [$r['lead_id']])));
check_error('una pedida ya no se cambia', fn() => config_apply_ops($req['code'], [['op' => 'customer', 'name' => 'X']], $vc), 'presupuesto pedido');
check_error('ni se pide dos veces', fn() => config_request($req['code'], $vc, $lead(), false), 'presupuesto pedido');
check('el comercial (o el admin) acepta una pedida sin recalcular el precio que vio el cliente', function () use ($req, $vadmin, $c) {
    q('UPDATE price_tiers SET price = 99900 WHERE price = 8000'); // la tarifa sube después de pedir
    $acc = config_accept($req['code'], $vadmin);
    $ok = eq('accepted', $acc['status']) && eq(18000, $acc['total_cents']) && eq('nuevo', $acc['work_status']);
    q('UPDATE price_tiers SET price = 8000 WHERE price = 99900');
    return $ok;
});
check('límite: 5 peticiones por conexión y hora; la sexta, 429 en llano', function () use ($c, $vc, $lead, $clearIp) {
    $clearIp();
    for ($i = 0; $i < LEAD_MAX_PER_IP_HOUR; $i++) config_request(config_create($c['blanco'], $vc)['code'], $vc, $lead(), false);
    try { config_request(config_create($c['blanco'], $vc)['code'], $vc, $lead(), false); } catch (UcError $e) { $clearIp(); return eq(429, $e->status); }
    $clearIp();
    throw new RuntimeException('sin límite');
});
check('el correo «guárdala» no lleva nada escrito por el usuario, sí el enlace firmado', function () use ($c, $vc, $lead, $clearIp) {
    settings_save(['mail_transport' => 'log']);
    @unlink(UC_DATA . '/mail.log');
    $cfg = config_create($c['blanco'], $vc);
    $r = config_request($cfg['code'], $vc, $lead(['name' => 'Gana dinero http://malo.example', 'email' => 'eva@x.test', 'message' => 'visita http://malo.example']));
    $clearIp();
    $log = (string) @file_get_contents(UC_DATA . '/mail.log');
    return eq(true, $r['mailed']) && eq(false, str_contains($log, 'malo.example')) && eq(false, str_contains($log, 'Gana dinero'))
        && eq(1, preg_match('#https://configurador\.example\.com/ver/' . $cfg['code'] . '\.\d+\.[a-f0-9]{32}#', $log));
});
check('«guárdala» a una misma dirección: como mucho 3 al día (el resto no se envía, la petición sí se guarda)', function () use ($c, $vc, $lead, $clearIp) {
    throttle_clear('save-mail:eva@x.test');
    $sent = 0;
    for ($i = 0; $i < 4; $i++) { $clearIp(); $sent += (int) config_request(config_create($c['blanco'], $vc)['code'], $vc, $lead(['email' => 'eva@x.test']))['mailed']; }
    $clearIp();
    return eq(3, $sent);
});

// --- enlace firmado
$sh = config_share($req['code'], $vc);
$tok = substr($sh['url'], strlen('https://configurador.example.com/ver/'));
check('el enlace usa la URL pública, caduca en 30 días y no lleva datos del cliente', fn() => eq(true, str_starts_with($sh['url'], 'https://configurador.example.com/ver/'))
    && eq(false, str_contains($sh['url'], 'Roc')) && eq(false, str_contains($sh['url'], '677')) && eq(30, $sh['days']) && eq(true, str_contains($sh['whatsapp_url'], 'wa.me')));
check('el enlace abre la configuración', fn() => eq($req['code'], share_resolve($tok)['code'] ?? null));
check('firma cambiada, código cambiado o caducado: no abre', function () use ($tok, $req) {
    [$code, $exp, $sig] = explode('.', $tok);
    $other = config_code_new();
    $past = time() - 10;
    return eq(null, share_resolve("$code.$exp." . str_repeat('0', 32))) && eq(null, share_resolve("$other.$exp.$sig"))
        && eq(null, share_resolve("$code.$past." . share_sig($code, $past))) && eq(null, share_resolve("$code." . ($exp + 1) . ".$sig"));
});
check('anulada: el enlace muere y no se comparte más', function () use ($tok, $req, $vadmin, $vc) {
    config_cancel($req['code'], $vadmin);
    try { config_share($req['code'], $vc); return false; } catch (UcError $e) { return eq(null, share_resolve($tok)) && eq(409, $e->status); }
});
check_error('sin URL pública no se hace ningún enlace (nunca con la cabecera Host)', function () use ($c, $vc) {
    settings_save(['url_publica' => '']);
    $_SERVER['HTTP_HOST'] = 'atacante.example';
    try { config_share(config_create($c['blanco'], $vc)['code'], $vc); } finally { settings_save(['url_publica' => 'https://configurador.example.com']); }
}, 'URL pública');

// --- estado de trabajo
$cfgA = config_accept(config_create($c['blanco'], $va)['code'], $va);
check('marcar: solo cambia el trabajo (precio, piezas, estado y updated_at intactos) y deja rastro', function () use ($cfgA, $va) {
    $before = config_get($cfgA['code'], $va);
    $parts = rows('SELECT * FROM config_item_parts ORDER BY id');
    Actor::set('MCP: ADRI (ana@x.test)');
    $r = config_mark($cfgA['code'], $va, ['work_status' => 'hecho', 'work_note' => 'Planos enviados']);
    Actor::set('sistema');
    return eq('hecho', $r['work_status']) && eq('Planos enviados', $r['work_note']) && eq('MCP: ADRI (ana@x.test)', $r['work_by'])
        && eq($before['total_cents'], $r['total_cents']) && eq('accepted', $r['status']) && eq($before['updated_at'], $r['updated_at'])
        && eq($parts, rows('SELECT * FROM config_item_parts ORDER BY id')) && eq(true, (bool) val("SELECT 1 FROM audit_log WHERE action = 'config.work'"));
});
check_error('un comercial no marca la de otro (ni la ve)', fn() => config_mark($cfgA['code'], $vb, ['work_status' => 'hecho']), 'No existe');
check_error('un cliente no marca', function () use ($c, $vc, $vadmin) { $x = config_request(config_create($c['blanco'], $vc)['code'], $vc, ['name' => 'X', 'phone' => '600 000 000', 'consent' => 1], false); throttle_clear('lead:' . client_ip_key()); config_mark($x['config']['code'], $vc, ['work_status' => 'hecho']); }, 'equipo');
check_error('un borrador no tiene trabajo que marcar', fn() => config_mark(config_create($c['blanco'], $va)['code'], $va, ['work_status' => 'hecho']), 'aceptadas');
check_error('estado de trabajo inventado', fn() => config_mark($cfgA['code'], $va, ['work_status' => 'listo']), 'nuevo, en_curso, hecho');
check_error('nota de más de 200', fn() => config_mark($cfgA['code'], $va, ['work_status' => 'hecho', 'work_note' => str_repeat('x', 201)]), '200');
check_error('campo desconocido = error', fn() => config_mark($cfgA['code'], $va, ['work_status' => 'hecho', 'prioridad' => 1]), 'prioridad');
check('la nota es de una línea', fn() => eq('a b', config_mark($cfgA['code'], $va, ['work_status' => 'en_curso', 'work_note' => "a\nb"])['work_note']));
check('pendientes: aceptadas o pedidas con nuevo/en curso, primero las nuevas, y cuántas hay', function () use ($va, $vadmin) {
    $l = config_list($vadmin, ['pendientes' => true]);
    $ws = array_column($l['items'], 'work_status');
    $sorted = $ws; usort($sorted, fn($x, $y) => ['nuevo' => 0, 'en_curso' => 1][$x] <=> ['nuevo' => 0, 'en_curso' => 1][$y]);
    return eq(true, $l['total'] > 0) && eq($l['total'], $l['pending']) && eq($sorted, $ws) && eq([], array_diff(array_unique(array_column($l['items'], 'status')), ['accepted', 'requested']))
        && eq(1, config_list($va, ['work_status' => 'en_curso'])['total']);
});

// --- leads
check('leads: el admin los ve todos; un comercial los de sus configuraciones; un cliente, ninguno', function () use ($c, $va, $vb, $vc, $vadmin, $lead, $clearIp) {
    $x = config_create($c['blanco'], $va);
    $clearIp();
    $r = config_request($x['code'], $va, $lead(['name' => 'Para Ana']), false);
    $clearIp();
    return eq(true, lead_list($vadmin, [])['total'] > 1) && eq(['Para Ana'], array_column(lead_list($va, [])['items'], 'name'))
        && eq(0, lead_list($vb, [])['total']) && eq(0, lead_list($vc, [])['total']) && eq('Para Ana', lead_get($r['lead_id'], $va)['name']);
});
check_error('un comercial no abre el lead de otro', fn() => lead_get((int) val("SELECT id FROM leads WHERE name = 'Para Ana'"), $vb), 'No existe');
check('lead_mark cambia trabajo y estado comercial; filtros por los dos', function () use ($va) {
    $id = (int) val("SELECT id FROM leads WHERE name = 'Para Ana'");
    $r = lead_mark($id, $va, ['work_status' => 'en_curso', 'estado' => 'contactado', 'work_note' => 'Llamado']);
    return eq('contactado', $r['estado']) && eq('en_curso', $r['work_status']) && eq(1, lead_list($va, ['estado' => 'contactado', 'work_status' => 'en_curso'])['total']);
});
check_error('estado de lead inventado', fn() => lead_list($va, ['estado' => 'ganado']), 'nuevo, contactado, cerrado');

// --- deshacer
$_SESSION = [];
$u = config_create($c['blanco'], $va);
$items = $u['walls'][0]['items'];
check('deshacer un «quitar» devuelve el módulo a su sitio, con su ancho y su id', function () use ($u, $items, $va) {
    config_apply_ops_undoable($u['code'], [['op' => 'set_width', 'item_id' => $items[0]['id'], 'width_cm' => 35]], $va);
    config_apply_ops_undoable($u['code'], [['op' => 'remove', 'item_id' => $items[0]['id']]], $va);
    $r = config_undo($u['code'], $va);
    $now = $r['config']['walls'][0]['items'];
    return eq([$items[0]['id'], $items[1]['id']], array_column($now, 'id')) && eq([35, 60], array_column($now, 'width_cm')) && eq(true, $r['can_undo']);
});
check('deshacer otra vez vuelve al ancho anterior y el precio lo calcula el servidor', function () use ($u, $va) {
    $r = config_undo($u['code'], $va);
    return eq([40, 60], array_column($r['config']['walls'][0]['items'], 'width_cm')) && eq(18000, $r['config']['total_cents']) && eq(false, $r['can_undo']);
});
check_error('sin nada que deshacer: lo dice', fn() => config_undo($u['code'], $va), 'nada que deshacer');
check_error('si otro la cambió después (p. ej. por MCP), no se deshace', function () use ($u, $items, $va) {
    config_apply_ops_undoable($u['code'], [['op' => 'set_width', 'item_id' => $items[1]['id'], 'width_cm' => 50]], $va);
    config_apply_ops($u['code'], [['op' => 'customer', 'name' => 'Desde MCP']], $va);
    config_undo($u['code'], $va);
}, 'otro sitio');

done();
