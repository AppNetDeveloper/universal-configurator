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
const TOKEN_SCOPES = ['read' => 'Leer catálogo, configuraciones y leads', 'write' => 'Crear, cambiar, aceptar y compartir configuraciones; marcar el trabajo'];

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
    // Resumen corto de docs/MCP.md (el manual completo). Mismas reglas; si cambias una, cambia las dos.
    return <<<TXT
    Universal Configurator: configurador de productos por catálogo (cocinas, muebles, armarios). El catálogo es un árbol
    de nodos (Cocina → Modelo → Color); una configuración parte de un NODO FINAL (is_final) con su composición por defecto:
    paredes con módulos. Cada módulo tiene un tipo y un ancho en cm con un decimal como mucho (58,5); el alto y el fondo
    salen del tipo. Todas las medidas de salida van también en mm (width_mm, height_mm, depth_mm).

    FLUJO: catalog_tree (nodo final) → module_types (con node_id: tipos, medidas, tramos y fórmulas que valen ahí)
    → config_create (borrador; customer opcional) → config_update (ops: set_width, add, replace, remove, customer;
    todas o ninguna) → price_check (probar sin guardar) / config_get (precio, tramo y despiece) → cut_list (lista de
    corte) → config_accept SOLO si la persona lo ha confirmado expresamente tras ver el total.

    ESCRIBEN: config_create, config_update, config_accept, config_share, config_mark y lead_mark (permiso write). Las demás
    solo leen (read). Aceptar es del equipo (token de un comercial o un admin).

    LO NUEVO, SIN PROCESAR: config_list(pendientes=true) da las aceptadas o con presupuesto pedido cuyo trabajo está
    «nuevo» o «en_curso», las nuevas primero, y cuántas hay (pending). Por cada una: haz lo tuyo (planos, presupuesto…)
    → config_mark(code, work_status="hecho", work_note="Planos enviados"). Los LEADS (clientes que pidieron presupuesto
    desde el configurador, con nombre y teléfono): lead_list(work_status="nuevo") → lead_get → lead_mark. Marcar no toca
    el precio ni el despiece. ESTADOS de una configuración: draft (borrador), requested (presupuesto pedido por el cliente:
    precio guardado, ya no se edita), accepted, cancelled.
    PRECIO: tramos [desde, hasta] cm, ambos incluidos, con un precio fijo por tramo; no se cobra por cm. Un ancho que cae
    en un hueco entre dos tramos se cobra como el tramo de ARRIBA (tier_rule "next_up" y tier_note lo dicen). Por debajo
    del primer tramo, por encima del último o fuera del mínimo/máximo del tipo no es válido: el error dice qué vale. Total en EUR SIN IVA; el IVA aparte.
    Aceptar congela precio y despiece; una aceptada no se edita: config_create con copy_of la duplica.

    La aplicación NO dibuja planos: los dibujas tú. Para dibujar en CAD usa config_get (medidas y despiece) o cut_list: medidas en mm, piezas con cantidad, espesor,
    largo y ancho (redondeo a 0,5 mm), veta y canto; cada pared trae direction (x o y) y su origen en mm, y sus módulos
    van en orden desde ese origen. El precio y las piezas los calcula SIEMPRE la app: no los recalcules ni los inventes.

    ERRORES: dicen qué falló (con el valor recibido), qué vale, un ejemplo de llamada y «No se ha hecho nada».
    Un parámetro que la herramienta no conoce también es un error (nunca se ignora). No inventes datos del cliente.
    TXT;
}

function mcp_token_note(): string
{
    $t = McpContext::$token;
    $o = $t['owner'];
    $note = "\n\nEste token actúa como {$o['name']} (" . (ROLES[$o['role']] ?? $o['role']) . '). Permisos: ' . implode(', ', $t['scopes']) . '.';
    $note .= $o['role'] === 'admin' ? ' Ve todas las configuraciones.' : ' Solo ve y toca las configuraciones de esa persona.';
    if (!in_array('write', $t['scopes'], true)) $note .= " Sin el permiso «write» no puedes crear, cambiar, aceptar, compartir ni marcar el trabajo (esas herramientas no aparecen).";
    elseif (!in_array($o['role'], ACCEPT_ROLES, true)) $note .= ' Su dueño es un cliente: no acepta (eso es del equipo).';
    return $note;
}

/** Cada herramienta: description (empieza por cuándo usarla), scope, inputSchema, example (para los errores) y handler(array $args). */
function mcp_tools(): array
{
    $obj = fn(array $props = [], array $required = []) => ['type' => 'object', 'properties' => (object) $props, 'additionalProperties' => false] + ($required ? ['required' => $required] : []);
    $int = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $str = fn(string $d) => ['type' => 'string', 'description' => $d];
    $cm = ['type' => ['number', 'string'], 'description' => 'Ancho en cm con un decimal como mucho: 60, 58.5 o "58,5"'];
    $code = $str('Código de la configuración, p. ej. UC-7K3F9QXM');
    $customer = $obj(['name' => $str('Nombre del cliente'), 'email' => $str('Correo'), 'phone' => $str('Teléfono')]);
    $op = $obj([
        'op' => ['type' => 'string', 'enum' => CONFIG_OPS, 'description' => 'set_width | remove | add | replace | customer'],
        'item_id' => $int('Línea (set_width, remove, replace)'), 'wall_id' => $int('Pared (add)'),
        'module_type_id' => $int('Tipo de módulo (add, replace)'), 'width_cm' => $cm, 'width_mm' => $int('Ancho en mm enteros (alternativa a width_cm)'),
        'name' => $str('customer: nombre del cliente'), 'email' => $str('customer: correo'), 'phone' => $str('customer: teléfono'), 'notes' => $str('customer: notas'),
    ], ['op']);

    return [
        'catalog_tree' => [
            'description' => 'Úsala primero, para encontrar el nodo final que hay que configurar. Árbol de nodos activos del catálogo (id, nombre, descripción, imagen o color, hijos y si es final).',
            'scope' => 'read', 'inputSchema' => $obj(), 'example' => [],
            'handler' => fn(array $a) => ['tree' => mcp_tree(node_tree(true))],
        ],
        'module_types' => [
            'description' => 'Úsala antes de añadir o sustituir módulos (con node_id), para saber qué tipos hay, sus medidas y qué anchos valen. Devuelve cada tipo con medidas por defecto en mm (width_mm, height_mm, depth_mm, mínimo y máximo), espesores (board_mm = T, back_mm = B), tramos de precio y las fórmulas del despiece (parts). Con node_id añade effective_tiers: los tramos que valen en ese nodo.',
            'scope' => 'read', 'inputSchema' => $obj(['node_id' => $int('Nodo (opcional) para ver los tramos que valen ahí')]), 'example' => ['node_id' => 12],
            'handler' => fn(array $a) => ['types' => mcp_module_types(isset($a['node_id']) ? parse_int($a['node_id'], 'node_id') : null), 'currency' => 'EUR', 'prices_include_tax' => false],
        ],
        'config_list' => [
            'description' => 'Úsala para buscar configuraciones y para saber qué hay NUEVO sin procesar (pendientes=true). Las tuyas, o todas si el token es de un admin; las pendientes de trabajar primero y luego de la más nueva a la más vieja, paginadas; «pending» dice cuántas hay pendientes. Filtros: status (draft, requested, accepted, cancelled), work_status (nuevo, en_curso, hecho), pendientes (aceptadas o pedidas con trabajo nuevo o en curso), from y to (AAAA-MM-DD), q (código, nombre, correo o teléfono).',
            'scope' => 'read', 'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => array_keys(CONFIG_STATUSES)], 'work_status' => ['type' => 'string', 'enum' => array_keys(WORK_STATUSES)],
                'pendientes' => ['type' => 'boolean', 'description' => 'true: solo lo aceptado o pedido con el trabajo «nuevo» o «en_curso»'],
                'from' => $str('Desde AAAA-MM-DD'), 'to' => $str('Hasta AAAA-MM-DD'),
                'q' => $str('Texto a buscar'), 'page' => $int('Página (1…)'), 'per_page' => $int('Por página (máx. 100, 25 por defecto)')]),
            'example' => ['pendientes' => true, 'per_page' => 25],
            'handler' => function (array $a) {
                if (isset($a['pendientes']) && !is_bool($a['pendientes'])) throw new UcError(t('pendientes es true o false (sin comillas).'));
                return config_list(mcp_viewer(), $a, isset($a['page']) ? parse_int($a['page'], 'page') : 1, isset($a['per_page']) ? parse_int($a['per_page'], 'per_page', 1, 100) : 25);
            },
        ],
        'config_get' => [
            'description' => 'Úsala para leer una configuración entera, también para dibujarla en CAD: nodo, cliente, estado en palabras, paredes con geometría (direction, origin_x_mm, origin_y_mm) y sus módulos en orden con width_mm/height_mm/depth_mm, tramo aplicado, precio (número y texto) y parts (el despiece calculado: cantidad, espesor, largo y ancho en mm). Total sin IVA, IVA y total con IVA. Si está aceptada, precios y piezas son la foto congelada.',
            'scope' => 'read', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            'handler' => fn(array $a) => ['config' => config_get((string) $a['code'], mcp_viewer())],
        ],
        'price_check' => [
            'description' => 'Úsala para probar precios y medidas SIN guardar nada (p. ej. antes de proponer un cambio): precio y tramo de cada módulo, total, IVA y qué medidas no son válidas (con los tramos posibles).',
            'scope' => 'read', 'inputSchema' => $obj(['node_id' => $int('Nodo final'), 'modules' => ['type' => 'array', 'description' => 'Módulos [{module_type_id, width_cm}]',
                'items' => $obj(['module_type_id' => $int('Tipo'), 'width_cm' => $cm, 'width_mm' => $int('Ancho en mm (alternativa)')], ['module_type_id'])]], ['node_id', 'modules']),
            'example' => ['node_id' => 12, 'modules' => [['module_type_id' => 3, 'width_cm' => 58.5], ['module_type_id' => 5, 'width_cm' => 80]]],
            'handler' => fn(array $a) => mcp_price_check($a),
        ],
        'cut_list' => [
            'description' => 'Úsala para fabricar o dibujar las piezas: lista de corte agregada de una configuración (piezas iguales sumadas por espesor, largo y ancho en mm, con veta, canto y el módulo de origen de cada una).',
            'scope' => 'read', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            'handler' => function (array $a) {
                $v = config_get((string) $a['code'], mcp_viewer());
                $cl = cut_list($v);
                return ['code' => $v['code'], 'status' => $v['status'], 'status_label' => $v['status_label'], 'frozen' => $v['frozen'], 'units' => 'mm',
                    'pieces' => array_sum(array_column($cl, 'qty')), 'cut_list' => $cl];
            },
        ],
        'config_create' => [
            'description' => 'ESCRIBE. Úsala para empezar una configuración: borrador desde un nodo final (node_id) con su composición por defecto, opcionalmente con los datos del cliente (customer: name, email, phone); o duplica una existente (copy_of, también aceptada) en un borrador nuevo con precios de hoy. Queda a nombre del dueño del token.',
            'scope' => 'write', 'inputSchema' => $obj(['node_id' => $int('Nodo final (ver catalog_tree)'), 'copy_of' => $str('Código de la configuración a duplicar'), 'customer' => $customer]),
            'example' => ['node_id' => 12, 'customer' => ['name' => 'Marta Ruiz', 'email' => 'marta@ejemplo.com', 'phone' => '600 000 000']],
            'handler' => function (array $a) {
                if (isset($a['customer']) && !is_array($a['customer'])) throw new UcError(t('customer tiene que ser un objeto {name, email, phone}.'));
                if (!empty($a['copy_of'])) {
                    if (isset($a['node_id']) || isset($a['customer'])) throw new UcError(t('Con copy_of no se pasan node_id ni customer: duplica tal cual y luego usa config_update.'));
                    return ['config' => config_copy((string) $a['copy_of'], mcp_viewer())];
                }
                if (!isset($a['node_id'])) throw new UcError(t('Falta node_id (un nodo final de catalog_tree) o copy_of (un código).'));
                return ['config' => config_create(parse_int($a['node_id'], 'node_id'), mcp_viewer(), (array) ($a['customer'] ?? []))];
            },
        ],
        'config_update' => [
            'description' => 'ESCRIBE. Úsala para cambiar un borrador: lista de operaciones, todas o ninguna: set_width {item_id, width_cm}, remove {item_id}, add {wall_id, module_type_id, width_cm?}, replace {item_id, module_type_id, width_cm?}, customer {name?, email?, phone?, notes?}. width_cm admite un decimal (58,5). Sin width_cm, add usa un ancho válido y replace conserva el ancho si vale (si no, el del tramo más cercano, y lo dice en notes). Devuelve la configuración con el total y el despiece nuevos. Una aceptada no se edita.',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code, 'ops' => ['type' => 'array', 'description' => 'Operaciones, en orden', 'items' => $op]], ['code', 'ops']),
            'example' => ['code' => 'UC-7K3F9QXM', 'ops' => [['op' => 'set_width', 'item_id' => 41, 'width_cm' => 58.5], ['op' => 'add', 'wall_id' => 7, 'module_type_id' => 3, 'width_cm' => 80]]],
            'handler' => fn(array $a) => config_apply_ops((string) $a['code'], is_array($a['ops']) ? $a['ops'] : [], mcp_viewer()),
        ],
        'config_accept' => [
            'description' => 'ESCRIBE. Úsala solo cuando la persona haya confirmado expresamente, tras ver el total, que acepta: acepta un borrador y congela su precio y su despiece; después ya no se edita. AVISO: la aceptación la debe confirmar la persona.',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            // Irreversible: congela precio y despiece. Se publica (destructiveHint + mcpu/effects) para que la IA pida el sí.
            'effects' => ['irreversible'],
            'handler' => fn(array $a) => ['config' => config_accept((string) $a['code'], mcp_viewer()),
                'note' => t('Aceptada y con el precio y el despiece congelados. Confirma a la persona el código y el total; para cambiarla habrá que duplicarla.')],
        ],
        'config_share' => [
            'description' => 'ESCRIBE (crea un enlace). Úsala para mandar una configuración al cliente: enlace de solo lectura (sin datos personales en la URL ni en la página), firmado, que caduca en 30 días; y el enlace de WhatsApp con el texto listo. Una anulada no se comparte.',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code], ['code']), 'example' => ['code' => 'UC-7K3F9QXM'],
            'handler' => fn(array $a) => config_share((string) $a['code'], mcp_viewer()),
        ],
        'config_mark' => [
            'description' => 'ESCRIBE (solo el estado de trabajo). Úsala cuando hayas procesado una configuración aceptada o con presupuesto pedido: work_status nuevo | en_curso | hecho y una nota corta (una línea, ≤ 200). No toca el precio, el despiece ni el estado de venta. Queda registrado quién lo marcó (el nombre de este token).',
            'scope' => 'write', 'inputSchema' => $obj(['code' => $code, 'work_status' => ['type' => 'string', 'enum' => array_keys(WORK_STATUSES)], 'work_note' => $str('Nota corta, una línea (≤ 200)')], ['code', 'work_status']),
            'example' => ['code' => 'UC-7K3F9QXM', 'work_status' => 'hecho', 'work_note' => 'Planos y presupuesto enviados'],
            'handler' => fn(array $a) => ['config' => config_mark((string) $a['code'], mcp_viewer(), array_intersect_key($a, ['work_status' => 1, 'work_note' => 1]))],
        ],
        'lead_list' => [
            'description' => 'Úsala para ver los clientes que han pedido presupuesto desde el configurador («Quiero este presupuesto»): nombre, teléfono, correo, mensaje, su configuración y total, estado (nuevo, contactado, cerrado) y estado de trabajo. Los nuevos sin trabajar primero; «pending» dice cuántos hay. Un admin ve todos; un comercial, los de sus configuraciones.',
            'scope' => 'read', 'inputSchema' => $obj(['estado' => ['type' => 'string', 'enum' => array_keys(LEAD_STATUSES)], 'work_status' => ['type' => 'string', 'enum' => array_keys(WORK_STATUSES)],
                'q' => $str('Nombre, teléfono o correo'), 'page' => $int('Página (1…)'), 'per_page' => $int('Por página (máx. 100, 25 por defecto)')]),
            'example' => ['work_status' => 'nuevo'],
            'handler' => fn(array $a) => lead_list(mcp_viewer(), $a, isset($a['page']) ? parse_int($a['page'], 'page') : 1, isset($a['per_page']) ? parse_int($a['per_page'], 'per_page', 1, 100) : 25),
        ],
        'lead_get' => [
            'description' => 'Úsala para leer un lead entero antes de llamar o escribir al cliente: datos de contacto, mensaje, consentimiento, configuración (código y total) y estado de trabajo. Para ver los módulos, config_get con su configuration_code.',
            'scope' => 'read', 'inputSchema' => $obj(['lead_id' => $int('Id del lead (ver lead_list)')], ['lead_id']), 'example' => ['lead_id' => 3],
            'handler' => fn(array $a) => ['lead' => lead_get($a['lead_id'], mcp_viewer())],
        ],
        'lead_mark' => [
            'description' => 'ESCRIBE (solo el seguimiento). Úsala cuando hayas trabajado un lead: work_status nuevo | en_curso | hecho, una nota corta (una línea, ≤ 200) y, si cambia, el estado comercial (estado: nuevo, contactado, cerrado). No toca la configuración.',
            'scope' => 'write', 'inputSchema' => $obj(['lead_id' => $int('Id del lead'), 'work_status' => ['type' => 'string', 'enum' => array_keys(WORK_STATUSES)],
                'work_note' => $str('Nota corta, una línea (≤ 200)'), 'estado' => ['type' => 'string', 'enum' => array_keys(LEAD_STATUSES)]], ['lead_id', 'work_status']),
            'example' => ['lead_id' => 3, 'work_status' => 'hecho', 'estado' => 'contactado', 'work_note' => 'Llamado; visita el jueves'],
            'handler' => fn(array $a) => ['lead' => lead_mark($a['lead_id'], mcp_viewer(), array_intersect_key($a, ['work_status' => 1, 'work_note' => 1, 'estado' => 1]))],
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
    if ($nodeId !== null && (!node_get($nodeId) || !node_branch_active($nodeId))) throw new UcError(t('El nodo {id} no existe o no está disponible. Mira los nodos con catalog_tree.', ['id' => $nodeId]));
    $out = [];
    foreach (module_types_all() as $t) {
        $tid = (int) $t['id'];
        $general = array_map('tier_row', rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NULL ORDER BY from_mm', [$tid]));
        $byNode = [];
        foreach (rows('SELECT * FROM price_tiers WHERE module_type_id = ? AND node_id IS NOT NULL ORDER BY node_id, from_mm', [$tid]) as $r) {
            if (!node_branch_active((int) $r['node_id'])) continue; // precios de ramas desactivadas: no se enseñan
            $byNode[(int) $r['node_id']][] = tier_row($r);
        }
        $item = ['id' => $tid, 'name' => $t['name'], 'ref' => $t['ref'], 'family' => $t['family'], 'description' => $t['description'],
            'width_mm' => (int) $t['width_mm'], 'height_mm' => (int) $t['height_mm'], 'depth_mm' => (int) $t['depth_mm'],
            'min_width_mm' => $t['min_width_mm'] === null ? null : (int) $t['min_width_mm'], 'max_width_mm' => $t['max_width_mm'] === null ? null : (int) $t['max_width_mm'],
            'board_mm' => (float) $t['board_mm'], 'back_mm' => (float) $t['back_mm'],
            'tiers' => tiers_public($general),
            'node_tiers' => array_map(fn($nid, $ts) => ['node_id' => $nid, 'node' => node_path_text($nid), 'tiers' => tiers_public($ts)], array_keys($byNode), $byNode),
            'parts' => parts_definition($t),
            'parts_default' => array_map(fn($p) => array_diff_key($p, ['formulas' => 1]), piezas_de_linea(['module_type_id' => $tid]))];
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
    if (!node_get($nodeId) || !node_branch_active($nodeId)) throw new UcError(t('El nodo {id} no existe o no está disponible. Mira los nodos con catalog_tree.', ['id' => $nodeId]));
    if (!is_array($a['modules']) || !array_is_list($a['modules'])) throw new UcError(t('modules debe ser una lista [{module_type_id, width_cm}].'));
    if (count($a['modules']) > CONFIG_MAX_LINES) throw new UcError(t('Demasiados módulos: {n}. Como mucho {max} por llamada.', ['n' => count($a['modules']), 'max' => CONFIG_MAX_LINES]));
    $items = [];
    foreach ($a['modules'] as $i => $m) {
        if (!is_array($m)) throw new UcError(t('modules[{i}] debe ser un objeto {module_type_id, width_cm}.', ['i' => $i]));
        reject_unknown_keys($m, ['module_type_id', 'width_cm', 'width_mm'], "modules[$i]");
        $w = op_width_mm($m) ?? throw new UcError(t('modules[{i}] necesita width_cm (p. ej. 58,5).', ['i' => $i]));
        $items[] = ['module_type_id' => parse_int($m['module_type_id'] ?? null, "modules[$i].module_type_id"), 'width_mm' => $w];
    }
    $p = precio_configuracion($nodeId, [['name' => '', 'items' => $items]]);
    $lines = array_map(fn($l) => ['module_type_id' => $l['module_type_id'], 'type_name' => $l['type_name'], 'width_cm' => $l['width_cm'], 'width_mm' => $l['width_mm'],
        'valid' => $l['valid'], 'tier_from_cm' => $l['tier_from_cm'], 'tier_to_cm' => $l['tier_to_cm'], 'tier_rule' => $l['tier_rule'], 'tier_note' => $l['tier_note'],
        'price' => eur($l['price']), 'price_text' => money($l['price']),
        'error' => $l['error'] ?? null, 'tiers' => $l['valid'] ? [] : tiers_public($l['tiers'])], $p['walls'][0]['items']);
    return ['node_id' => $nodeId, 'node' => node_path_text($nodeId), 'is_final' => node_is_final($nodeId), 'lines' => $lines, 'valid' => $p['valid'],
        'invalid_count' => $p['invalid_count'], 'currency' => 'EUR', 'total' => eur($p['total']), 'total_text' => money($p['total']), 'tax_rate' => $p['tax_rate'],
        'tax' => eur($p['tax']), 'total_with_tax' => eur($p['total_with_tax']), 'saved' => false];
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
        $effects = $t['effects'] ?? [];
        $tool = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['inputSchema'],
            'annotations' => ['readOnlyHint' => $t['scope'] === 'read', 'destructiveHint' => in_array('irreversible', $effects, true),
                'idempotentHint' => $t['scope'] === 'read']];
        // Convención de MCP Universal (`_meta["mcpu/effects"]` ⊂ money, third_party, irreversible, bulk): la IA pide el sí.
        if ($effects) $tool['_meta'] = ['mcpu/effects' => $effects];
        $out[] = $tool;
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
        reject_unknown_keys($args, array_keys((array) $tool['inputSchema']['properties']), $name);
        foreach ($tool['inputSchema']['required'] ?? [] as $req) {
            if (!isset($args[$req]) || $args[$req] === '') throw new UcError(t("Falta el parámetro obligatorio '{p}'.", ['p' => $req]));
        }
        $result = ($tool['handler'])($args);
        if ($tool['scope'] === 'read') audit("mcp.$name", mb_substr(json_enc(array_intersect_key($args, ['code' => 1, 'node_id' => 1, 'status' => 1, 'q' => 1, 'from' => 1, 'to' => 1, 'work_status' => 1, 'pendientes' => 1, 'lead_id' => 1, 'estado' => 1])), 0, 200)); // las escrituras ya dejan su propia línea
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
        if (!throttle('mcp-fail:' . client_ip_key(), 20, 900)) return [429, ['error' => 'Demasiados intentos']];
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
    $raw = request_body_limited();
    if ($raw === null) { json_response(mcp_error(null, -32600, 'Petición demasiado grande (máximo ' . intdiv(UC_MAX_BODY, 1024) . ' KB). No se ha hecho nada.'), 413); return; }
    [$status, $body] = mcp_process(request_header('Authorization'), $raw);
    if ($status === 401) header('WWW-Authenticate: Bearer realm="universal-configurator"');
    if ($body === null) { http_response_code($status); return; }
    json_response($body, $status);
}
