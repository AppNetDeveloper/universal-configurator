<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Lo que hace el cliente final al terminar y lo que trabaja después el fabricante (o Adri):
 *  - «Quiero este presupuesto» (config_request): congela el precio, deja la configuración como «presupuesto pedido»
 *    (bloqueada a su nombre) y crea un lead (nombre, teléfono, correo opcional, consentimiento).
 *  - Enlace para compartir (share_link): de solo lectura, firmado, caduca en 30 días, sin datos personales en la URL.
 *  - Estado de TRABAJO (aparte del de venta) en configuraciones aceptadas y en leads: nuevo → en curso → hecho, con una
 *    nota corta y quién lo cambió. Así Adri sabe qué es nuevo y sin procesar.
 */

const LEAD_STATUSES = ['nuevo' => 'Nuevo', 'contactado' => 'Contactado', 'cerrado' => 'Cerrado'];
const WORK_STATUSES = ['nuevo' => 'Nuevo', 'en_curso' => 'En curso', 'hecho' => 'Hecho'];
const WORK_NOTE_MAX = 200;
const SHARE_TTL_DAYS = 30;
const LEAD_MAX_PER_IP_HOUR = 5;     // peticiones de presupuesto por conexión y hora
const SAVE_MAIL_MAX_PER_DEST_DAY = 3; // correos «guárdala» a una misma dirección al día

// ---------------------------------------------------------------- pedir el presupuesto

/**
 * El cliente (invitado o con cuenta) pide el presupuesto. $in: name, phone (obligatorios), email y message (opcionales),
 * consent (obligatorio), website (trampa para robots: si viene con algo, se finge que fue bien y no se guarda nada).
 * Devuelve ['config' => vista, 'lead_id' => int|null, 'mailed' => bool].
 */
function config_request(string $code, ?array $viewer, array $in, bool $mail = true): array
{
    $cfg = config_find($code, $viewer);
    if (trim((string) ($in['website'] ?? '')) !== '') { // honeypot: un robot rellena todo
        audit('lead.honeypot', $cfg['code']);
        return ['config' => config_view($cfg), 'lead_id' => null, 'mailed' => false];
    }
    config_require_draft($cfg);
    $d = lead_input($in);
    if (!throttle('lead:' . client_ip_key(), LEAD_MAX_PER_IP_HOUR, 3600)) {
        throw new UcError(t('Has enviado varias peticiones seguidas desde esta conexión. Espera un rato o llámanos directamente.'), [], 429);
    }
    [$p, $leadId] = tx(function () use ($cfg, $d) {
        $now = row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]) ?? $cfg;
        config_require_draft($now);
        $p = config_checked_pricing($now, t('pedir el presupuesto'));
        config_freeze((int) $cfg['id'], $p, 'requested', ['requested_at' => now(), 'consent_at' => now(),
            'customer_name' => $d['name'], 'customer_email' => $d['email'], 'customer_phone' => $d['phone']]);
        $leadId = insert('leads', ['configuration_id' => (int) $cfg['id'], 'user_id' => $cfg['user_id'], 'name' => $d['name'], 'email' => $d['email'],
            'phone' => $d['phone'], 'message' => $d['message'], 'source' => 'configurador', 'consent_at' => now(),
            'status' => 'nuevo', 'work_status' => 'nuevo', 'created_at' => now(), 'updated_at' => now()]);
        return [$p, $leadId];
    });
    audit('config.request', $cfg['code'] . ' · ' . money($p['total']) . ' · lead #' . $leadId);
    $mailed = $mail && $d['email'] !== '' && save_mail_send($cfg, $d['email']);
    return ['config' => config_get($cfg['code'], $viewer), 'lead_id' => $leadId, 'mailed' => $mailed];
}

/** Datos del formulario del lead, limpios y validados (mensajes en llano). */
function lead_input(array $in): array
{
    $line = fn($v, int $max) => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v)), 0, $max);
    $d = ['name' => $line($in['name'] ?? '', 120), 'phone' => $line($in['phone'] ?? '', 40), 'email' => $line($in['email'] ?? '', 160),
        'message' => mb_substr(trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', (string) ($in['message'] ?? ''))), 0, 500)];
    if ($d['name'] === '') throw new UcError(t('Escribe tu nombre para que sepamos a quién llamar.'));
    if (preg_match_all('/\d/', $d['phone']) < 6 || !preg_match('/^[0-9 +().\-]+$/', $d['phone'])) {
        throw new UcError(t('El teléfono «{v}» no parece válido: escribe solo números (puede llevar + y espacios).', ['v' => $d['phone']]));
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo «{v}» no es válido.', ['v' => $d['email']]));
    if (empty($in['consent'])) throw new UcError(t('Marca la casilla de protección de datos: sin ella no podemos guardar tus datos para llamarte.'));
    return $d;
}

/** Precio actual comprobado (sin medidas sin tramo ni piezas imposibles): lo comparten aceptar y pedir. */
function config_checked_pricing(array $cfg, string $what): array
{
    $p = precio_configuracion((int) $cfg['node_id'], config_walls_input((int) $cfg['id']));
    if ($p['count'] === 0) throw new UcError(t('La configuración no tiene ningún módulo: añade al menos uno antes de {q}.', ['q' => $what]));
    if (!$p['valid']) {
        $bad = [];
        foreach ($p['walls'] as $w) foreach ($w['items'] as $it) if (!$it['valid']) $bad[] = t('línea {id}: {e}', ['id' => $it['id'], 'e' => $it['error']]);
        throw new UcError(t('No se puede {q}: hay medidas sin tramo. {lista}', ['q' => $what, 'lista' => implode(' · ', $bad)]));
    }
    $badParts = [];
    foreach ($p['walls'] as $w) foreach ($w['items'] as $it) {
        foreach (piezas_de_linea($it) as $pp) if ($pp['error'] !== null) $badParts[] = t('línea {id}: {e}', ['id' => $it['id'], 'e' => $pp['error']]);
    }
    if ($badParts) throw new UcError(t('No se puede {q}: hay piezas que no se pueden fabricar con esas medidas. {lista}', ['q' => $what, 'lista' => implode(' · ', $badParts)]));
    return $p;
}

/**
 * Correo «guárdala» con el enlace de solo lectura. La dirección no está verificada: el cuerpo no lleva NADA escrito
 * por el usuario (ni su nombre), solo la empresa, el modelo, el total y el enlace. Con límite por destino y por día.
 * Si falta la URL pública o el correo falla, no se rompe la petición: se devuelve false y queda en el registro.
 */
function save_mail_send(array $cfg, string $email): bool
{
    if (public_url() === null || !throttle('save-mail:' . strtolower($email), SAVE_MAIL_MAX_PER_DEST_DAY, 86400)) return false;
    try {
        $v = config_view(row('SELECT * FROM configurations WHERE id = ?', [$cfg['id']]) ?? $cfg);
        $body = t("Hola:\n\nAquí tienes tu configuración de {empresa} ({modelo}).\nTotal: {total} (sin IVA).\n\nPuedes verla cuando quieras en este enlace (vale {d} días):\n{url}\n\nTe llamaremos para darte el presupuesto. Si no lo has pedido tú, ignora este correo.\n\n{empresa}",
            ['empresa' => setting('empresa'), 'modelo' => $v['node']['path_text'], 'total' => money($v['total_cents']), 'd' => SHARE_TTL_DAYS, 'url' => share_link($cfg['code'])]);
        mail_send([$email], t('Tu configuración en {empresa}', ['empresa' => setting('empresa')]), $body);
        audit('config.save_mail', $cfg['code']);
        return true;
    } catch (Throwable $ex) {
        audit('config.save_mail_error', $cfg['code'] . ' · ' . $ex->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------- enlace para compartir

/** Firma del enlace: código + caducidad, con el secreto de la instalación. */
function share_sig(string $code, int $exp): string
{
    $secret = (string) config('secret', '');
    if ($secret === '') throw new UcError(t('Falta el secreto de la instalación (data/config.php): no se pueden firmar enlaces.'), [], 500);
    return substr(hash_hmac('sha256', 'share|' . $code . '|' . $exp, $secret), 0, 32);
}

/** Ruta (sin dominio) del enlace de solo lectura. El código no es un dato personal; sin la firma no abre nada. */
function share_path(string $code, ?int $exp = null): string
{
    $exp ??= time() + SHARE_TTL_DAYS * 86400;
    return '/ver/' . $code . '.' . $exp . '.' . share_sig($code, $exp);
}

/** Enlace completo, siempre con la URL pública (nunca con la cabecera Host). */
function share_link(string $code): string
{
    return require_public_url() . share_path($code);
}

/** Enlace para quien puede ver la configuración: lo usan el paso 3 (WhatsApp) y la herramienta MCP config_share. */
function config_share(string $code, ?array $viewer): array
{
    $cfg = config_find($code, $viewer);
    if ($cfg['status'] === 'cancelled') throw new UcError(t('La configuración {c} está anulada: no se comparte.', ['c' => $cfg['code']]), [], 409);
    $exp = time() + SHARE_TTL_DAYS * 86400;
    $url = require_public_url() . share_path($cfg['code'], $exp);
    audit('config.share', $cfg['code']);
    return ['code' => $cfg['code'], 'url' => $url, 'expires_at' => date('c', $exp), 'days' => SHARE_TTL_DAYS,
        'whatsapp_url' => 'https://wa.me/?text=' . rawurlencode(t('Mi configuración en {e}: {u}', ['e' => setting('empresa'), 'u' => $url]))];
}

/** Configuración de un enlace compartido, o null si la firma no cuadra, caducó, o está anulada. */
function share_resolve(string $token): ?array
{
    if (!preg_match('/^(UC-[A-Z2-9]{8})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) return null;
    [, $code, $exp, $sig] = $m;
    if ((int) $exp < time() || (string) config('secret', '') === '' || !hash_equals(share_sig($code, (int) $exp), $sig)) return null;
    $cfg = row('SELECT * FROM configurations WHERE code = ?', [$code]);
    if (!$cfg || $cfg['status'] === 'cancelled') return null;
    return $cfg;
}

// ---------------------------------------------------------------- leads

function lead_visible(array $lead, ?array $viewer): bool
{
    if (($viewer['kind'] ?? '') !== 'user' || !($viewer['user']['active'] ?? false)) return false;
    if ($viewer['user']['role'] === 'admin') return true;
    return $viewer['user']['role'] === 'comercial' && $lead['user_id'] !== null && (int) $lead['user_id'] === (int) $viewer['user']['id'];
}

function lead_find(mixed $id, ?array $viewer): array
{
    $lid = parse_int($id, 'lead_id');
    $l = row('SELECT * FROM leads WHERE id = ?', [$lid]);
    if (!$l || !lead_visible($l, $viewer)) throw new UcError(t('No existe el lead {id} o no tienes acceso a él. Mira los id con lead_list.', ['id' => $lid]), [], 404);
    return $l;
}

function lead_view(array $l): array
{
    $code = $l['configuration_id'] ? val('SELECT code FROM configurations WHERE id = ?', [$l['configuration_id']]) : null;
    $total = $l['configuration_id'] ? val('SELECT total FROM configurations WHERE id = ?', [$l['configuration_id']]) : null;
    return ['id' => (int) $l['id'], 'name' => $l['name'], 'phone' => $l['phone'], 'email' => $l['email'], 'message' => $l['message'],
        'source' => $l['source'], 'estado' => $l['status'], 'estado_label' => t(LEAD_STATUSES[$l['status']]),
        'configuration_code' => $code ?: null, 'configuration_total' => $total === null ? null : eur((int) $total),
        'configuration_total_text' => $total === null ? null : money((int) $total),
        'consent_at' => $l['consent_at'], 'created_at' => $l['created_at'], 'updated_at' => $l['updated_at']] + work_view($l);
}

function lead_get(mixed $id, ?array $viewer): array
{
    return lead_view(lead_find($id, $viewer));
}

/** $f: estado, work_status, q. Admin ve todos; un comercial, los de sus configuraciones; nadie más. */
function lead_list(?array $viewer, array $f, int $page = 1, int $perPage = 25): array
{
    $empty = ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 0, 'pending' => 0];
    if (($viewer['kind'] ?? '') !== 'user' || !in_array($viewer['user']['role'] ?? '', ['admin', 'comercial'], true)) return $empty;
    $where = [];
    $args = [];
    if ($viewer['user']['role'] !== 'admin') { $where[] = 'l.user_id = ?'; $args[] = (int) $viewer['user']['id']; }
    $estado = (string) ($f['estado'] ?? '');
    if ($estado !== '') {
        if (!isset(LEAD_STATUSES[$estado])) throw new UcError(t('Estado «{v}» no válido. Valen: {l}.', ['v' => $estado, 'l' => implode(', ', array_keys(LEAD_STATUSES))]));
        $where[] = 'l.status = ?';
        $args[] = $estado;
    }
    $ws = (string) ($f['work_status'] ?? '');
    if ($ws !== '') { work_status_check($ws); $where[] = 'l.work_status = ?'; $args[] = $ws; }
    $qtext = trim((string) ($f['q'] ?? ''));
    if ($qtext !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qtext) . '%';
        $where[] = "(l.name LIKE ? ESCAPE '\\' OR l.phone LIKE ? ESCAPE '\\' OR l.email LIKE ? ESCAPE '\\')";
        array_push($args, $like, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $perPage = max(1, min(100, $perPage));
    $total = (int) val("SELECT COUNT(*) FROM leads l $sqlWhere", $args);
    $scope = $viewer['user']['role'] === 'admin' ? ['', []] : ['AND user_id = ?', [(int) $viewer['user']['id']]];
    $pending = (int) val("SELECT COUNT(*) FROM leads WHERE work_status IN ('nuevo','en_curso') {$scope[0]}", $scope[1]);
    $page = max(1, $page);
    $rs = rows("SELECT l.* FROM leads l $sqlWhere ORDER BY CASE l.work_status WHEN 'nuevo' THEN 0 WHEN 'en_curso' THEN 1 ELSE 2 END, l.created_at DESC, l.id DESC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $args);
    return ['items' => array_map('lead_view', $rs), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage), 'pending' => $pending];
}

/** Estado comercial del lead (nuevo, contactado, cerrado). */
function lead_set_status(mixed $id, ?array $viewer, string $status): array
{
    $l = lead_find($id, $viewer);
    if (!isset(LEAD_STATUSES[$status])) throw new UcError(t('Estado «{v}» no válido. Valen: {l}.', ['v' => $status, 'l' => implode(', ', array_keys(LEAD_STATUSES))]));
    update('leads', ['status' => $status, 'updated_at' => now()], 'id = ?', [$l['id']]);
    audit('lead.status', '#' . $l['id'] . ' → ' . $status);
    return lead_get($l['id'], $viewer);
}

// ---------------------------------------------------------------- estado de trabajo

function work_status_check(string $ws): void
{
    if (!isset(WORK_STATUSES[$ws])) throw new UcError(t('work_status «{v}» no válido. Valen: nuevo, en_curso, hecho.', ['v' => $ws]));
}

function work_view(array $row): array
{
    $ws = $row['work_status'] ?? null;
    return ['work_status' => $ws, 'work_status_label' => $ws ? t(WORK_STATUSES[$ws]) : null, 'work_note' => (string) ($row['work_note'] ?? ''),
        'work_by' => (string) ($row['work_by'] ?? ''), 'work_at' => $row['work_at'] ?? null];
}

/** Valida work_status y work_note (una línea, ≤ 200). La nota es opcional: sin ella se conserva la que había. */
function work_input(array $in, array $row): array
{
    $allowed = ['work_status', 'work_note'];
    foreach (array_keys($in) as $k) if (!in_array($k, $allowed, true)) throw new UcError(t('Campo «{k}» desconocido. Valen: work_status, work_note.', ['k' => $k]));
    $ws = (string) ($in['work_status'] ?? '');
    if ($ws === '') throw new UcError(t('Falta work_status: nuevo, en_curso o hecho.'));
    work_status_check($ws);
    $note = array_key_exists('work_note', $in) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $in['work_note'])) : (string) $row['work_note'];
    if (mb_strlen($note) > WORK_NOTE_MAX) throw new UcError(t('La nota tiene {n} caracteres: como mucho {m}, en una línea.', ['n' => mb_strlen($note), 'm' => WORK_NOTE_MAX]));
    return ['work_status' => $ws, 'work_note' => $note, 'work_by' => mb_substr(Actor::$name, 0, 160), 'work_at' => now()];
}

/**
 * Marca el trabajo de una configuración aceptada (o pedida). No toca precio, piezas ni estado de venta: solo
 * work_status, work_note, work_by y work_at. Un comercial solo marca las suyas (las demás no las ve).
 */
function config_mark(string $code, ?array $viewer, array $in): array
{
    $cfg = config_find($code, $viewer);
    if (($viewer['kind'] ?? '') !== 'user' || !in_array($viewer['user']['role'] ?? '', ['admin', 'comercial'], true)) {
        throw new UcError(t('Solo el equipo (comercial o administrador) marca el trabajo de una configuración.'), [], 403);
    }
    if ($cfg['status'] !== 'accepted') {
        throw new UcError(t('La configuración {c} está «{s}»: el estado de trabajo es para las aceptadas (un presupuesto pedido se trabaja en su lead: lead_mark).', ['c' => $cfg['code'], 's' => t(CONFIG_STATUSES[$cfg['status']])]), [], 409);
    }
    $d = work_input($in, $cfg);
    update('configurations', $d, 'id = ?', [$cfg['id']]); // updated_at no cambia: no es un cambio de la configuración
    audit('config.work', $cfg['code'] . ' → ' . $d['work_status'] . ($d['work_note'] !== '' ? ' · ' . $d['work_note'] : ''));
    return config_get($cfg['code'], $viewer);
}

function lead_mark(mixed $id, ?array $viewer, array $in): array
{
    $l = lead_find($id, $viewer);
    $estado = null;
    if (array_key_exists('estado', $in)) { $estado = (string) $in['estado']; unset($in['estado']); }
    $d = work_input($in, $l);
    if ($estado !== null) {
        if (!isset(LEAD_STATUSES[$estado])) throw new UcError(t('Estado «{v}» no válido. Valen: {l}.', ['v' => $estado, 'l' => implode(', ', array_keys(LEAD_STATUSES))]));
        $d['status'] = $estado;
    }
    update('leads', $d + ['updated_at' => now()], 'id = ?', [$l['id']]);
    audit('lead.work', '#' . $l['id'] . ' → ' . $d['work_status'] . ($estado ? " · $estado" : '') . ($d['work_note'] !== '' ? ' · ' . $d['work_note'] : ''));
    return lead_get($l['id'], $viewer);
}
