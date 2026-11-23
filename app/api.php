<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * API JSON de la tablet (/api/*): sesión (persona o invitado) + CSRF en la cabecera X-CSRF-Token para todo POST.
 * Cada cambio llama al servidor y la tablet pinta lo que devuelve (el precio nunca se calcula en JavaScript).
 *
 *   GET  /api/nodes?parent=ID                     hijos de un nodo (sin parent: raíces) y el camino
 *   GET  /api/module-types?node=ID                tipos activos con los tramos que valen en ese nodo
 *   GET  /api/configurations                      mis configuraciones (filtros: status, from, to, q, page)
 *   POST /api/configurations {node_id | copy_of}  crea un borrador (o duplica)
 *   GET  /api/configurations/{code}
 *   POST /api/configurations/{code}/ops           {op, …} o {ops: [{op, …}, …]}
 *   POST /api/configurations/{code}/accept        {name, email, phone, consent}
 *   POST /api/configurations/{code}/duplicate
 */

/** @return array{0:int, 1:array} [estado HTTP, cuerpo] */
function api_dispatch(string $method, string $path, array $body, ?string $csrf): array
{
    try {
        if ($method === 'POST' && !csrf_valid($csrf)) return [419, ['ok' => false, 'error' => t('Sesión caducada: recarga la página.')]];
        $viewer = session_viewer();
        if ($viewer === null) return [401, ['ok' => false, 'error' => t('Entra con tu cuenta para continuar.')]];
        Actor::set(viewer_label($viewer));

        if ($method === 'GET' && $path === '/api/nodes') {
            $parent = isset($body['parent']) && $body['parent'] !== '' ? parse_int($body['parent'], 'parent') : null;
            if ($parent !== null && (!node_get($parent) || !node_branch_active($parent))) return [404, ['ok' => false, 'error' => t('Ese nodo no existe.')]];
            $nodes = array_map(fn($n) => ['id' => (int) $n['id'], 'name' => $n['name'], 'description' => $n['description'], 'image' => $n['image'],
                'is_final' => node_is_final((int) $n['id'])], node_children($parent));
            return [200, ['ok' => true, 'nodes' => $nodes, 'path' => $parent ? node_path($parent) : []]];
        }
        if ($method === 'GET' && $path === '/api/module-types') {
            $node = parse_int($body['node'] ?? null, 'node');
            if (!node_get($node)) return [404, ['ok' => false, 'error' => t('Ese nodo no existe.')]];
            return [200, ['ok' => true, 'types' => module_types_for_node($node)]];
        }
        if ($path === '/api/configurations') {
            if ($method === 'GET') {
                return [200, ['ok' => true] + config_list($viewer, $body, (int) ($body['page'] ?? 1), (int) ($body['per_page'] ?? 25))];
            }
            if ($method === 'POST') {
                $cfg = !empty($body['copy_of']) ? config_copy((string) $body['copy_of'], $viewer) : config_create(parse_int($body['node_id'] ?? null, 'node_id'), $viewer);
                return [201, ['ok' => true, 'config' => $cfg]];
            }
        }
        if (preg_match('#^/api/configurations/(UC-[A-Za-z0-9]{8})(/(ops|accept|duplicate))?$#', $path, $m)) {
            $code = $m[1];
            $action = $m[3] ?? '';
            if ($method === 'GET' && $action === '') return [200, ['ok' => true, 'config' => config_get($code, $viewer)]];
            if ($method === 'POST' && $action === 'ops') {
                $ops = isset($body['ops']) && is_array($body['ops']) ? $body['ops'] : [$body];
                return [200, ['ok' => true] + config_apply_ops($code, $ops, $viewer)];
            }
            if ($method === 'POST' && $action === 'accept') {
                $cfg = config_accept($code, $viewer, array_intersect_key($body, ['name' => 1, 'email' => 1, 'phone' => 1]), !empty($body['consent']));
                return [200, ['ok' => true, 'config' => $cfg]];
            }
            if ($method === 'POST' && $action === 'duplicate') return [201, ['ok' => true, 'config' => config_copy($code, $viewer)]];
        }
        return [404, ['ok' => false, 'error' => t('Ruta de la API desconocida.')]];
    } catch (UcError $ex) {
        return [$ex->status, ['ok' => false, 'error' => $ex->getMessage()] + $ex->data];
    }
}

/** Tipos activos con los tramos que valen en un nodo (para añadir y sustituir en la tablet). */
function module_types_for_node(int $nodeId): array
{
    $out = [];
    foreach (module_types_all() as $t) {
        $eff = tiers_effective((int) $t['id'], $nodeId);
        if (!$eff['tiers']) continue; // sin tramos para este modelo no se puede usar
        $out[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'ref' => $t['ref'], 'family' => $t['family'], 'depth_mm' => (int) $t['depth_mm'],
            'tiers' => tiers_public($eff['tiers']), 'suggested_width_cm' => ancho_sugerido((int) $t['id'], $nodeId)];
    }
    return $out;
}

/** Entrada HTTP de /api/*. */
function api_handle(string $method, string $path): void
{
    session_start_safe();
    if ($method === 'GET') {
        $body = $_GET;
    } else {
        $raw = (string) file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) { json_response(['ok' => false, 'error' => t('JSON no válido.')], 400); return; }
    }
    [$status, $out] = api_dispatch($method, $path, $body, request_header('X-CSRF-Token'));
    json_response($out, $status);
}
