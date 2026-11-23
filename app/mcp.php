<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Servidor MCP (Model Context Protocol) sobre HTTP, sin estado: JSON-RPC 2.0 por POST a /mcp.
 * Cada petición se autentica con un token (Authorization: Bearer uc_…), guardado como hash.
 * Ámbitos: read (leer) y write (crear, cambiar y aceptar). El token actúa como su dueño: un token de un
 * comercial solo ve y toca sus configuraciones; uno de un admin, todas. Dueño desactivado = token sin valor.
 */

const MCP_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const TOKEN_SCOPES = ['read' => 'Leer catálogo y configuraciones', 'write' => 'Crear, cambiar y aceptar configuraciones'];

// ---------------------------------------------------------------- tokens

/** Crea un token y devuelve el valor en claro (se muestra UNA vez; se guarda solo su hash). */
function token_create(string $name, array $scopes, ?int $userId): string
{
    $scopes = array_values(array_intersect(array_keys(TOKEN_SCOPES), $scopes));
    if (!$scopes) throw new UcError(t('Elige al menos un permiso (read o write).'));
    if (in_array('write', $scopes, true) && !in_array('read', $scopes, true)) array_unshift($scopes, 'read');
    if (trim($name) === '') throw new UcError(t('Ponle un nombre al token (p. ej. «ADRI»).'));
    $owner = $userId ? user_by_id($userId) : null;
    if (!$owner || !$owner['active']) throw new UcError(t('Elige una persona activa: el token actúa con sus permisos.'));
    $raw = 'uc_' . bin2hex(random_bytes(20));
    insert('mcp_tokens', ['name' => mb_substr(trim($name), 0, 80), 'token_hash' => hash('sha256', $raw), 'prefix' => substr($raw, 0, 9),
        'scopes' => implode(',', $scopes), 'user_id' => $userId, 'created_at' => now()]);
    audit('token.create', trim($name) . ' (' . implode(',', $scopes) . ') → ' . $owner['email']);
    return $raw;
}

function token_revoke(int $id): void
{
    update('mcp_tokens', ['revoked_at' => now()], 'id = ? AND revoked_at IS NULL', [$id]);
    audit('token.revoke', "#$id");
}

function tokens_all(): array
{
    return rows('SELECT t.*, u.name AS owner_name, u.email AS owner_email, u.role AS owner_role FROM mcp_tokens t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.revoked_at IS NOT NULL, t.id DESC');
}

/** Token válido (no revocado, dueño activo) con 'scopes' (array) y 'owner' (fila de users). */
function token_authenticate(string $raw): ?array
{
    if (!preg_match('/^uc_[a-f0-9]{40}$/', $raw)) return null;
    $t = row('SELECT * FROM mcp_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $raw)]);
    if (!$t) return null;
    $owner = $t['user_id'] ? user_by_id((int) $t['user_id']) : null;
    if (!$owner || !$owner['active']) return null;
    q('UPDATE mcp_tokens SET last_used_at = ? WHERE id = ?', [now(), $t['id']]);
    $t['scopes'] = explode(',', $t['scopes']);
    $t['owner'] = $owner;
    return $t;
}

final class McpContext
{
    public static array $token = [];
}

function mcp_viewer(): array
{
    return ['kind' => 'user', 'user' => McpContext::$token['owner']];
}

// ---------------------------------------------------------------- instrucciones y herramientas

function mcp_instructions(): string
{
    return <<<TXT
    Universal Configurator: configurador de productos por catálogo (cocinas, muebles, armarios…). El catálogo es un
    árbol de nodos (p. ej. Cocina → Modelo → Color); una configuración parte de un NODO FINAL (is_final) con su
    composición por defecto: paredes con módulos. Cada módulo tiene un tipo y un ancho en CENTÍMETROS ENTEROS.

    PRECIO (una verdad, en el servidor): cada tipo de módulo tiene tramos de medida [desde_cm, hasta_cm] (ambos incluidos)
    con un precio fijo por tramo; NO se cobra por centímetro. Un ancho que no cae en ningún tramo NO es válido: el error
    dice qué tramos hay. Un nodo puede tener tramos propios que sustituyen a los generales (module_types con node_id
    da los que valen ahí). Total = suma de líneas, en EUR y SIN IVA; el IVA va aparte (tax_rate, tax, total_with_tax).
    Nunca calcules tú el precio: usa price_check (sin guardar) o lo que devuelven config_get / config_update.

    Flujo recomendado:
    1. catalog_tree para encontrar el nodo final; module_types (con node_id) para ver tipos, fondos (depth_mm) y tramos.
    2. price_check para probar una composición sin guardar nada.
    3. config_create con node_id (borrador con la composición por defecto) o con copy_of (duplica otra).
    4. config_update con ops: set_width {item_id, width_cm}, remove {item_id}, add {wall_id, module_type_id, width_cm?},
       replace {item_id, module_type_id, width_cm?}, customer {name, email, phone, notes}. Van todas o ninguna.
       Los id de líneas (items) y paredes (walls) salen en config_get.
    5. config_accept SOLO cuando la persona lo haya confirmado expresamente: congela el precio y ya no se puede editar
       (para cambiar algo aceptado, config_create con copy_of).

    GEOMETRÍA para planos: cada pared tiene direction (x o y: el eje por el que avanza), origin_x_mm y origin_y_mm
    (su origen en mm); sus módulos van en orden desde el origen con width_cm (cm) y depth_mm (fondo del tipo, mm).
    La aplicación NO dibuja planos ni hace PDFs: eso lo haces tú con estos datos exactos.

    NO INVENTES DATOS: ni medidas, ni precios, ni datos del cliente que no te haya dado la persona o no estén aquí.
    Si algo no se puede hacer (permiso del token, configuración de otra persona, aceptada), dilo claramente; nunca
    digas que lo has hecho. Todas las acciones quedan en el registro de actividad del panel.
    TXT;
}

function mcp_token_note(): string
{
    $t = McpContext::$token;
    $o = $t['owner'];
    $note = "\n\nEste token actúa como {$o['name']} (" . (ROLES[$o['role']] ?? $o['role']) . '). Permisos: ' . implode(', ', $t['scopes']) . '.';
    $note .= $o['role'] === 'admin' ? ' Ve todas las configuraciones.' : ' Solo ve y toca las configuraciones de esa persona.';
    if (!in_array('write', $t['scopes'], true)) $note .= " Sin el permiso «write» no puedes crear, cambiar ni aceptar (esas herramientas no aparecen).";
    return $note;
}

/** Cada herramienta: description, scope, inputSchema, example (para los errores) y handler(array $args). */
function mcp_tools(): array
{
    $obj = fn(array $props = [], array $required = []) => ['type' => 'object', 'properties' => (object) $props] + ($required ? ['required' => $required] : []);
    $int = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $str = fn(string $d) => ['type' => 'string', 'description' => $d];
    $code = $str('Código de la configuración, p. ej. UC-7K3F9QXM');
    $op = $obj([
        'op' => ['type' => 'string', 'enum' => CONFIG_OPS, 'description' => 'set_width | remove | add | replace | customer'],
        'item_id' => $int('Línea (set_width, remove, replace)'), 'wall_id' => $int('Pared (add)'),
        'module_type_id' => $int('Tipo de módulo (add, replace)'), 'width_cm' => $int('Ancho en cm enteros (set_width; opcional en add/replace)'),
        'name' => $str('customer: nombre del cliente'), 'email' => $str('customer: correo'), 'phone' => $str('customer: teléfono'), 'notes' => $str('customer: notas'),
    ], ['op']);

    return [
        'catalog_tree' => [
            'description' => 'Árbol de nodos activos del catálogo (id, nombre, descripción, imagen o color, hijos y si es final). Una configuración se crea desde un nodo final.',
            'scope' => 'read', 'inputSchema' => $obj(), 'example' => [],
            'handler' => fn(array $a) => ['tree' => mcp_tree(node_tree(true))],
        ],
        'module_types' => [
            'description' => 'Tipos de módulo activos con su familia, referencia, fondo (depth_mm) y tramos de precio: generales (tiers) y por nodo (node_tiers). Con node_id añade effective_tiers: los que valen en ese nodo (los de su antepasado más cercano con tramos, o los generales).',
            'scope' => 'read', 'inputSchema' => $obj(['node_id' => $int('Nodo (opcional) para ver los tramos que valen ahí')]), 'example' => ['node_id' => 12],
            'handler' => fn(array $a) => ['types' => mcp_module_types(isset($a['node_id']) ? parse_int($a['node_id'], 'node_id') : null), 'currency' => 'EUR', 'prices_include_tax' => false],
        ],
        'config_list' => [
            'description' => 'Configuraciones (las tuyas, o todas si el token es de un admin), de la más nueva a la más vieja, paginadas. Filtros: status (draft, accepted, cancelled), from y to (fecha de creación AAAA-MM-DD), q (código, nombre, correo o teléfono del cliente).',
            'scope' => 'read', 'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => array_keys(CONFIG_STATUSES)], 'from' => $str('Desde AAAA-MM-DD'), 'to' => $str('Hasta AAAA-MM-DD'),
                'q' => $str('Texto a buscar'), 'page' => $int('Página (1…)'), 'per_page' => $int('Por página (máx. 100, 25 por defecto)')]),
            'example' => ['status' => 'accepted', 'from' => '2026-11-01', 'per_page' => 25],
            'handler' => fn(array $a) => config_list(mcp_viewer(), $a, isset($a['page']) ? parse_int($a['page'], 'page') : 1, isset($a['per_page']) ? parse_int($a['per_page'], 'per_page', 1, 100) : 25),
        ],
        'config_get' => [
            'description' => 'Una configuración entera: nodo (camino), cliente, estado, paredes con su geometría (direction, origin_x_mm, origin_y_mm) y sus módulos en orden (id de línea, tipo, width_cm, depth_mm, tramo y precio de cada línea), total sin IVA, IVA y total con IVA. Si está aceptada, los precios son la foto congelada.',
            'scope' => 'read', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            'handler' => fn(array $a) => ['config' => config_get((string) $a['code'], mcp_viewer())],
        ],
        'price_check' => [
            'description' => 'Precio de una lista de módulos para un nodo final, SIN guardar nada: precio y tramo de cada línea, total, IVA y qué medidas no son válidas (con los tramos posibles).',
            'scope' => 'read', 'inputSchema' => $obj(['node_id' => $int('Nodo final'), 'modules' => ['type' => 'array', 'description' => 'Módulos [{module_type_id, width_cm}]',
                'items' => $obj(['module_type_id' => $int('Tipo'), 'width_cm' => $int('Ancho en cm enteros')], ['module_type_id', 'width_cm'])]], ['node_id', 'modules']),
            'example' => ['node_id' => 12, 'modules' => [['module_type_id' => 3, 'width_cm' => 60], ['module_type_id' => 5, 'width_cm' => 80]]],
            'handler' => fn(array $a) => mcp_price_check($a),
        ],
        'config_create' => [
            'description' => 'Crea un borrador desde un nodo final (node_id) con su composición por defecto, o duplica una configuración existente (copy_of, también si está aceptada) en un borrador nuevo con los precios de hoy. Queda a nombre del dueño del token.',
            'scope' => 'write', 'inputSchema' => $obj(['node_id' => $int('Nodo final (ver catalog_tree)'), 'copy_of' => $str('Código de la configuración a duplicar')]),
            'example' => ['node_id' => 12],
            'handler' => function (array $a) {
                if (!empty($a['copy_of'])) return ['config' => config_copy((string) $a['copy_of'], mcp_viewer())];
                if (!isset($a['node_id'])) throw new UcError(t('Falta node_id (un nodo final de catalog_tree) o copy_of (un código).'));
                return ['config' => config_create(parse_int($a['node_id'], 'node_id'), mcp_viewer())];
            },
        ],
        'config_update' => [
            'description' => 'Cambia un borrador con una lista de operaciones (todas o ninguna): set_width {item_id, width_cm}, remove {item_id}, add {wall_id, module_type_id, width_cm?}, replace {item_id, module_type_id, width_cm?}, customer {name?, email?, phone?, notes?}. Sin width_cm, add usa un ancho válido sugerido y replace conserva el ancho si vale (si no, el del tramo más cercano, y lo dice en notes). Devuelve la configuración y el total nuevo. Una aceptada no se edita.',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code, 'ops' => ['type' => 'array', 'description' => 'Operaciones, en orden', 'items' => $op]], ['code', 'ops']),
            'example' => ['code' => 'UC-7K3F9QXM', 'ops' => [['op' => 'set_width', 'item_id' => 41, 'width_cm' => 60], ['op' => 'add', 'wall_id' => 7, 'module_type_id' => 3, 'width_cm' => 80]]],
            'handler' => fn(array $a) => config_apply_ops((string) $a['code'], is_array($a['ops']) ? $a['ops'] : [], mcp_viewer()),
        ],
        'config_accept' => [
            'description' => 'Acepta un borrador y congela su precio (tramo y precio de cada línea, total e IVA); después ya no se edita. AVISO: la aceptación la debe confirmar la persona: llama a esta herramienta solo si te lo ha pedido expresamente tras ver el total.',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            'handler' => fn(array $a) => ['config' => config_accept((string) $a['code'], mcp_viewer()),
                'note' => t('Aceptada y con el precio congelado. Confirma a la persona el código y el total; para cambiarla habrá que duplicarla.')],
        ],
    ];
}

function mcp_tree(array $tree): array
{
    return array_map(fn($n) => ['id' => $n['id'], 'name' => $n['name'], 'description' => $n['description'], 'image' => $n['image'],
        'is_final' => $n['is_final'], 'children' => mcp_tree($n['children'])], $tree);
}

function mcp_module_types(?int $nodeId): array
{
    if ($nodeId !== null && !node_get($nodeId)) throw new UcError(t('El nodo {id} no existe. Mira los nodos con catalog_tree.', ['id' => $nodeId]));
    $out = [];
    foreach (module_types_all() as $t) {
        $tid = (int) $t['id'];
        $general = rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NULL ORDER BY from_cm', [$tid]);
        $byNode = [];
        foreach (rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NOT NULL ORDER BY node_id, from_cm', [$tid]) as $r) {
            $byNode[(int) $r['node_id']][] = ['id' => (int) $r['id'], 'from_cm' => (int) $r['from_cm'], 'to_cm' => (int) $r['to_cm'], 'price' => (int) $r['price']];
        }
        $item = ['id' => $tid, 'name' => $t['name'], 'ref' => $t['ref'], 'family' => $t['family'], 'description' => $t['description'], 'depth_mm' => (int) $t['depth_mm'],
            'tiers' => tiers_public(array_map(fn($r) => ['from_cm' => (int) $r['from_cm'], 'to_cm' => (int) $r['to_cm'], 'price' => (int) $r['price']], $general)),
            'node_tiers' => array_map(fn($nid, $ts) => ['node_id' => $nid, 'node' => node_path_text($nid), 'tiers' => tiers_public($ts)], array_keys($byNode), $byNode)];
        if ($nodeId !== null) {
            $eff = tiers_effective($tid, $nodeId);
            $item['effective_tiers'] = tiers_public($eff['tiers']);
            $item['effective_from_node_id'] = $eff['node_id'];
        }
        $out[] = $item;
    }
    return $out;
}

function mcp_price_check(array $a): array
{
    $nodeId = parse_int($a['node_id'], 'node_id');
    if (!node_get($nodeId)) throw new UcError(t('El nodo {id} no existe. Mira los nodos con catalog_tree.', ['id' => $nodeId]));
    if (!is_array($a['modules']) || !array_is_list($a['modules'])) throw new UcError(t('modules debe ser una lista [{module_type_id, width_cm}].'));
    $items = [];
    foreach ($a['modules'] as $i => $m) {
        if (!is_array($m)) throw new UcError(t('modules[{i}] debe ser un objeto {module_type_id, width_cm}.', ['i' => $i]));
        $items[] = ['module_type_id' => parse_int($m['module_type_id'] ?? null, "modules[$i].module_type_id"), 'width_cm' => parse_int($m['width_cm'] ?? null, "modules[$i].width_cm", 1, 10000)];
    }
    $p = precio_configuracion($nodeId, [['name' => '', 'items' => $items]]);
    $lines = array_map(fn($l) => ['module_type_id' => $l['module_type_id'], 'type_name' => $l['type_name'], 'width_cm' => $l['width_cm'], 'valid' => $l['valid'],
        'tier_from_cm' => $l['tier_from_cm'], 'tier_to_cm' => $l['tier_to_cm'], 'price' => eur($l['price']), 'error' => $l['error'] ?? null,
        'tiers' => $l['valid'] ? [] : tiers_public($l['tiers'])], $p['walls'][0]['items']);
    return ['node_id' => $nodeId, 'node' => node_path_text($nodeId), 'is_final' => node_is_final($nodeId), 'lines' => $lines, 'valid' => $p['valid'],
        'invalid_count' => $p['invalid_count'], 'currency' => 'EUR', 'total' => eur($p['total']), 'tax_rate' => $p['tax_rate'], 'tax' => eur($p['tax']),
        'total_with_tax' => eur($p['total_with_tax']), 'saved' => false];
}

function mcp_tool_allowed(array $tool): bool
{
    return in_array($tool['scope'], McpContext::$token['scopes'], true);
}

function mcp_tools_for_token(): array
{
    $out = [];
    foreach (mcp_tools() as $name => $t) {
        if (!mcp_tool_allowed($t)) continue;
        $out[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['inputSchema'],
            'annotations' => ['readOnlyHint' => $t['scope'] === 'read', 'destructiveHint' => false, 'idempotentHint' => $t['scope'] === 'read']];
    }
    return $out;
}

/** Ejecuta una herramienta. Error «smart»: qué falló (con el valor), qué vale, un ejemplo y «no se ha hecho nada». */
function mcp_call(string $name, array $args): array
{
    $tools = mcp_tools();
    $tool = $tools[$name] ?? null;
    try {
        if (!$tool) throw new UcError(t('Herramienta desconocida: «{n}». Existen: {lista}.', ['n' => $name, 'lista' => implode(', ', array_keys($tools))]));
        if (!mcp_tool_allowed($tool)) throw new UcError(t("Este token no tiene el permiso '{s}' necesario para {n}. Pide a un administrador un token con «{s}» (panel → Tokens MCP).", ['s' => $tool['scope'], 'n' => $name]));
        foreach ($tool['inputSchema']['required'] ?? [] as $req) {
            if (!isset($args[$req]) || $args[$req] === '') throw new UcError(t("Falta el parámetro obligatorio '{p}'.", ['p' => $req]));
        }
        $result = ($tool['handler'])($args);
        if ($tool['scope'] === 'read') audit("mcp.$name", mb_substr(json_enc(array_intersect_key($args, ['code' => 1, 'node_id' => 1, 'status' => 1, 'q' => 1, 'from' => 1, 'to' => 1])), 0, 200)); // las escrituras ya dejan su propia línea
        return ['content' => [['type' => 'text', 'text' => json_enc($result, true)]], 'structuredContent' => (object) $result, 'isError' => false];
    } catch (UcError $ex) {
        $txt = 'Error: ' . $ex->getMessage();
        $data = array_diff_key($ex->data, ['op_index' => 1]);
        if ($data) $txt .= "\nDetalle: " . json_enc($data);
        if ($tool) $txt .= "\nEjemplo de llamada: $name " . json_enc((object) $tool['example']);
        $txt .= "\n" . t('No se ha hecho nada.');
        return ['content' => [['type' => 'text', 'text' => $txt]], 'isError' => true];
    } catch (Throwable $ex) {
        error_log('[universal-configurator mcp] ' . $ex);
        return ['content' => [['type' => 'text', 'text' => 'Error interno al ejecutar la herramienta. ' . t('No se ha hecho nada.')]], 'isError' => true];
    }
}

function mcp_error(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcp_dispatch(array $msg): ?array
{
    $id = $msg['id'] ?? null;
    $method = $msg['method'] ?? null;
    if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
        return array_key_exists('id', $msg) ? mcp_error($id, -32600, 'Petición JSON-RPC no válida') : null;
    }
    if (!array_key_exists('id', $msg)) return null; // notificación (p. ej. notifications/initialized)
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $ok = fn(array $result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
    switch ($method) {
        case 'initialize':
            $asked = (string) ($params['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($asked, MCP_PROTOCOL_VERSIONS, true) ? $asked : MCP_PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'universal-configurator', 'title' => 'Universal Configurator', 'version' => UC_VERSION],
                'instructions' => mcp_instructions() . mcp_token_note(),
            ]);
        case 'ping':
            return $ok([]);
        case 'tools/list':
            return $ok(['tools' => mcp_tools_for_token()]);
        case 'tools/call':
            return $ok(mcp_call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []));
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
        default:
            return mcp_error($id, -32601, "Método no soportado: $method");
    }
}

/**
 * Procesa una petición MCP. @return array{0:int, 1:?array} [estado HTTP, cuerpo (null = sin cuerpo)]
 */
function mcp_process(?string $authorization, string $raw): array
{
    $secret = preg_match('/^Bearer\s+(\S+)$/i', (string) $authorization, $m) ? $m[1] : '';
    $token = $secret !== '' ? token_authenticate($secret) : null;
    if (!$token) {
        if (!throttle('mcp-fail:' . client_ip(), 20, 900)) return [429, ['error' => 'Demasiados intentos']];
        return [401, ['error' => 'Token MCP no válido o revocado. Créalo en el panel: Tokens MCP.']];
    }
    McpContext::$token = $token;
    Actor::set('MCP: ' . $token['name'] . ' (' . $token['owner']['email'] . ')');
    $body = json_decode($raw, true);
    if (!is_array($body)) return [400, mcp_error(null, -32700, 'JSON no válido')];
    $batch = array_is_list($body) && $body !== [];
    if ($batch && count($body) > 20) return [400, mcp_error(null, -32600, 'Máximo 20 peticiones por lote')];
    $responses = [];
    foreach ($batch ? $body : [$body] as $msg) {
        $r = is_array($msg) ? mcp_dispatch($msg) : mcp_error(null, -32600, 'Petición no válida');
        if ($r !== null) $responses[] = $r;
    }
    if (!$responses) return [202, null];
    return [200, $batch ? $responses : $responses[0]];
}

/** Entrada HTTP de /mcp. */
function mcp_handle(string $method): void
{
    header('Cache-Control: no-store');
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
        http_response_code(204);
        return;
    }
    if ($method !== 'POST') {
        header('Allow: POST');
        json_response(['error' => 'Usa POST con JSON-RPC 2.0. Endpoint MCP de Universal Configurator.'], 405);
        return;
    }
    $dest = strtolower((string) request_header('Sec-Fetch-Dest'));
    if ($dest !== '' && $dest !== 'empty') { json_response(['error' => 'forbidden'], 403); return; }
    [$status, $body] = mcp_process(request_header('Authorization'), (string) file_get_contents('php://input'));
    if ($status === 401) header('WWW-Authenticate: Bearer realm="universal-configurator"');
    if ($body === null) { http_response_code($status); return; }
    json_response($body, $status);
}
