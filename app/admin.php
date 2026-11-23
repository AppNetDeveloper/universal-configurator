<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Panel de administración (/admin, solo rol admin): catálogo en árbol, tipos de módulo y tramos, composición por
 * defecto, configuraciones (filtros, ver, JSON/CSV), personas, ajustes, tokens MCP y registro de actividad.
 * Patrón: GET pinta, POST (con CSRF) cambia y redirige con un aviso; los errores vuelven al formulario.
 */

const ADMIN_SECTIONS = [
    '/' => 'Inicio', '/catalogo' => 'Catálogo', '/tipos' => 'Tipos y tramos', '/configuraciones' => 'Configuraciones', '/leads' => 'Leads',
    '/usuarios' => 'Personas', '/ajustes' => 'Ajustes', '/tokens' => 'Tokens MCP', '/copias' => 'Copias', '/actualizaciones' => 'Actualizaciones',
    '/actividad' => 'Actividad',
];

function admin_render(string $view, string $section, array $vars = [], int $status = 200): void
{
    render("admin/$view", $vars + ['section' => $section, 'wide' => true], $status);
}

function admin_back(string $to, ?UcError $ex = null): never
{
    if ($ex) { keep_old($_POST); flash($ex->getMessage(), 'error'); }
    redirect('/admin' . $to);
}

/**
 * ¿Está Adri conectada? Sí, si algún token MCP sin revocar se ha usado en los últimos 30 días. Solo mira la base de
 * esta instalación: la app no llama a casa.
 * @return array{connected: bool, days: ?int, last: ?string}
 */
function adri_status(): array
{
    $last = val('SELECT MAX(last_used_at) FROM mcp_tokens WHERE revoked_at IS NULL');
    if (!$last) return ['connected' => false, 'days' => null, 'last' => null];
    $days = (int) floor((time() - strtotime((string) $last)) / 86400);
    return ['connected' => $days <= 30, 'days' => $days, 'last' => (string) $last];
}

function admin_handle(string $path, string $method): void
{
    $u = current_user();
    if (!$u) { flash(t('Entra con una cuenta de administrador.'), 'error'); redirect('/'); }
    if (!is_admin($u)) { render('message', ['title' => t('Sin acceso'), 'message' => t('El panel es solo para administradores.')], 403); return; }
    Actor::set($u['email']);
    $post = $method === 'POST';
    if ($post) csrf_check();
    $m = [];
    $match = function (string $re) use ($path, &$m) { return (bool) preg_match('#^' . $re . '$#', $path, $m); };

    try {
        // ---- inicio
        if ($path === '/' && !$post) {
            admin_render('dashboard', '/', [
                'title' => t('Panel'),
                'counts' => ['nodes' => (int) val('SELECT COUNT(*) FROM nodes'), 'types' => (int) val('SELECT COUNT(*) FROM module_types WHERE active = 1'),
                    'drafts' => (int) val("SELECT COUNT(*) FROM configurations WHERE status = 'draft'"), 'accepted' => (int) val("SELECT COUNT(*) FROM configurations WHERE status = 'accepted'"),
                    'users' => (int) val('SELECT COUNT(*) FROM users WHERE active = 1'),
                    'requested' => (int) val("SELECT COUNT(*) FROM configurations WHERE status = 'requested'"),
                    'leads_new' => (int) val("SELECT COUNT(*) FROM leads WHERE work_status = 'nuevo'"),
                    'pending' => (int) val("SELECT COUNT(*) FROM configurations WHERE status = 'accepted' AND work_status IN ('nuevo','en_curso')")],
                'latest' => config_list(['kind' => 'user', 'user' => $u], [], 1, 8)['items'],
                'adri' => adri_status(),
            ]);
            return;
        }

        // ---- catálogo
        if ($path === '/catalogo' && !$post) { admin_render('catalog', '/catalogo', ['title' => t('Catálogo'), 'tree' => node_tree(false)]); return; }
        if ($path === '/catalogo/crear' && $post) {
            $parent = ($_POST['parent_id'] ?? '') !== '' ? parse_int($_POST['parent_id'], 'parent_id') : null;
            try { $nid = node_create($parent, $_POST); } catch (UcError $ex) { admin_back($parent ? "/catalogo/$parent" : '/catalogo', $ex); }
            unset($_SESSION['old']);
            flash(t('Nodo «{n}» creado.', ['n' => trim((string) $_POST['name'])]));
            admin_back($parent ? "/catalogo/$parent" : '/catalogo');
        }
        if ($match('/catalogo/(\d+)')) {
            $nid = (int) $m[1];
            $node = node_get($nid) ?? throw new UcError(t('Ese nodo no existe.'), [], 404);
            if ($post) {
                try { node_update($nid, $_POST); } catch (UcError $ex) { admin_back("/catalogo/$nid", $ex); }
                unset($_SESSION['old']);
                flash(t('Guardado.'));
                admin_back("/catalogo/$nid");
            }
            admin_render('node', '/catalogo', ['title' => $node['name'], 'node' => $node, 'path' => node_path($nid), 'children' => node_children($nid, false),
                'layoutFrom' => layout_for($nid)['node_id'], 'configs' => (int) val('SELECT COUNT(*) FROM configurations WHERE node_id = ?', [$nid])]);
            return;
        }
        if ($post && $match('/catalogo/(\d+)/imagen')) {
            $nid = (int) $m[1];
            $node = node_get($nid) ?? throw new UcError(t('Ese nodo no existe.'), [], 404);
            try {
                $url = image_store_upload($_FILES['imagen'] ?? []);
                node_update($nid, ['name' => $node['name'], 'slug' => $node['slug'], 'description' => $node['description'], 'image' => $url]);
            } catch (UcError $ex) { admin_back("/catalogo/$nid", $ex); }
            flash(t('Imagen subida.'));
            admin_back("/catalogo/$nid");
        }
        if ($post && $match('/catalogo/(\d+)/(activar|mover|borrar)')) {
            $nid = (int) $m[1];
            $parent = node_get($nid)['parent_id'] ?? null;
            $back = isset($_POST['back']) && preg_match('#^/[a-z0-9/]*$#', (string) $_POST['back']) ? (string) $_POST['back'] : '/catalogo';
            try {
                if ($m[2] === 'activar') node_set_active($nid, !empty($_POST['active']));
                if ($m[2] === 'mover') node_move($nid, (int) ($_POST['dir'] ?? 1));
                if ($m[2] === 'borrar') { node_delete($nid); flash(t('Nodo borrado.')); admin_back($parent ? "/catalogo/$parent" : '/catalogo'); }
            } catch (UcError $ex) { admin_back($back, $ex); }
            admin_back($back);
        }

        // ---- composición por defecto
        if ($match('/composicion/(\d+)') && !$post) {
            $nid = (int) $m[1];
            $node = node_get($nid) ?? throw new UcError(t('Ese nodo no existe.'), [], 404);
            $walls = layout_walls_of($nid);
            foreach ($walls as &$w) {
                $w['modules'] = rows('SELECT d.*, t.name AS type_name FROM default_modules d JOIN module_types t ON t.id = d.module_type_id WHERE d.layout_wall_id = ? ORDER BY d.sort, d.id', [$w['id']]);
                $w['price'] = precio_configuracion($nid, [['items' => $w['modules']]]);
            }
            unset($w);
            $inherited = layout_for($nid);
            // solo los tipos que tienen tramos para este nodo (los de su familia) y, por si acaso, los que ya usa
            $types = module_types_for_node($nid);
            $have = array_column($types, 'id');
            foreach ($walls as $w) foreach ($w['modules'] as $md) if (!in_array((int) $md['module_type_id'], $have, true)) {
                $t = module_type_get((int) $md['module_type_id']);
                if ($t) { $types[] = ['id' => (int) $t['id'], 'name' => $t['name'] . ' · ' . t('sin tramos aquí'), 'ref' => $t['ref']]; $have[] = (int) $t['id']; }
            }
            admin_render('layout_edit', '/catalogo', ['title' => t('Composición de {n}', ['n' => $node['name']]), 'node' => $node, 'path' => node_path($nid), 'walls' => $walls,
                'inheritedFrom' => !$walls && $inherited['node_id'] ? node_get($inherited['node_id']) : null, 'types' => $types, 'isFinal' => node_is_final($nid)]);
            return;
        }
        if ($post && $match('/composicion/(\d+)/(pared|modulo)(?:/(\d+))?(?:/(borrar|mover))?')) {
            $nid = (int) $m[1];
            $kind = $m[2];
            $oid = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            $act = $m[4] ?? '';
            try {
                if ($kind === 'pared' && $act === 'borrar') layout_wall_delete($nid, (int) $oid);
                elseif ($kind === 'pared') layout_wall_save($nid, $_POST, $oid);
                else {
                    $wid = parse_int($_POST['wall_id'] ?? null, 'wall_id');
                    if (!val('SELECT 1 FROM layout_walls WHERE id = ? AND node_id = ?', [$wid, $nid])) throw new UcError(t('Esa pared no es de este nodo.'), [], 404);
                    if ($act === 'borrar') default_module_delete($wid, (int) $oid);
                    elseif ($act === 'mover') default_module_move($wid, (int) $oid, (int) ($_POST['dir'] ?? 1));
                    else default_module_save($wid, parse_int($_POST['module_type_id'] ?? null, t('Tipo')), $_POST['width_cm'] ?? null, $oid);
                }
            } catch (UcError $ex) { admin_back("/composicion/$nid", $ex); }
            unset($_SESSION['old']);
            flash(t('Composición guardada.'));
            admin_back("/composicion/$nid");
        }

        // ---- tipos y tramos
        if ($path === '/tipos' && !$post) {
            $types = module_types_all(false);
            foreach ($types as &$t) $t['tiers'] = (int) val('SELECT COUNT(*) FROM price_tiers WHERE module_type_id = ?', [$t['id']]);
            admin_render('types', '/tipos', ['title' => t('Tipos de módulo'), 'types' => $types]);
            return;
        }
        if ($path === '/tipos/crear' && $post) {
            try { $tid = module_type_save($_POST); } catch (UcError $ex) { admin_back('/tipos', $ex); }
            unset($_SESSION['old']);
            flash(t('Tipo creado. Ahora añade sus tramos de precio.'));
            admin_back("/tipos/$tid");
        }
        if ($match('/tipos/(\d+)')) {
            $tid = (int) $m[1];
            $type = module_type_get($tid) ?? throw new UcError(t('Ese tipo no existe.'), [], 404);
            if ($post) {
                try { module_type_save($_POST, $tid); } catch (UcError $ex) { admin_back("/tipos/$tid", $ex); }
                unset($_SESSION['old']);
                flash(t('Guardado.'));
                admin_back("/tipos/$tid");
            }
            $tiers = array_map(fn($r) => tier_row($r) + ['node_id' => $r['node_id'] === null ? null : (int) $r['node_id']],
                rows('SELECT * FROM price_tiers WHERE module_type_id = ? ORDER BY node_id IS NOT NULL, node_id, from_mm', [$tid]));
            admin_render('type_edit', '/tipos', ['title' => $type['name'], 'type' => $type, 'tiers' => $tiers, 'nodes' => admin_node_options(),
                'parts' => module_parts_of($tid), 'preview' => piezas_de_linea(['module_type_id' => $tid])]);
            return;
        }
        if ($post && $match('/tipos/(\d+)/despiece/probar')) {
            // Vista previa en vivo de una pieza (JSON para el panel): no guarda nada.
            $type = module_type_get((int) $m[1]) ?? throw new UcError(t('Ese tipo no existe.'), [], 404);
            $vars = part_vars($type);
            $res = [];
            foreach (['thickness', 'length_expr', 'width_expr'] as $k) {
                try { $v = round_half(expr_value((string) ($_POST[$k] ?? ''), $vars)); $res[$k] = $v > 0 ? ['ok' => true, 'mm' => $v, 'text' => mm_text($v) . ' mm'] : ['ok' => false, 'error' => t('da {v} mm: tiene que ser mayor que 0', ['v' => mm_text($v)])]; }
                catch (UcError $ex) { $res[$k] = ['ok' => false, 'error' => $ex->getMessage()]; }
            }
            json_response(['ok' => true, 'vars' => $vars, 'result' => $res]);
            return;
        }
        if ($post && $match('/tipos/(\d+)/pieza(?:/(\d+))?(/borrar)?')) {
            $tid = (int) $m[1];
            $pid = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            try {
                if (!empty($m[3])) { module_part_delete($tid, (int) $pid); flash(t('Pieza borrada.')); admin_back("/tipos/$tid"); }
                module_part_save($tid, $_POST, $pid);
            } catch (UcError $ex) { admin_back("/tipos/$tid", $ex); }
            unset($_SESSION['old']);
            flash(t('Pieza guardada.'));
            admin_back("/tipos/$tid");
        }
        if ($post && $match('/tipos/(\d+)/activar')) {
            module_type_set_active((int) $m[1], !empty($_POST['active']));
            admin_back('/tipos/' . (int) $m[1]);
        }
        if ($post && $match('/tipos/(\d+)/tramo(?:/(\d+))?(/borrar)?')) {
            $tid = (int) $m[1];
            $trid = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            try {
                if (!empty($m[3])) { tier_delete($tid, (int) $trid); flash(t('Tramo borrado.')); admin_back("/tipos/$tid"); }
                $node = ($_POST['node_id'] ?? '') !== '' ? parse_int($_POST['node_id'], 'node_id') : null;
                tier_save($tid, $node, $_POST['from_cm'] ?? null, $_POST['to_cm'] ?? null, parse_money($_POST['price'] ?? '', t('Precio')), $trid);
            } catch (UcError $ex) { admin_back("/tipos/$tid", $ex); }
            unset($_SESSION['old']);
            flash(t('Tramo guardado.'));
            admin_back("/tipos/$tid");
        }

        // ---- configuraciones
        $viewer = ['kind' => 'user', 'user' => $u];
        $filters = array_intersect_key($_GET, ['status' => 1, 'from' => 1, 'to' => 1, 'q' => 1, 'user_id' => 1, 'work_status' => 1, 'pendientes' => 1]);
        if ($path === '/configuraciones' && !$post) {
            try { $list = config_list($viewer, $filters, page_clamp((int) ($_GET['page'] ?? 1)), 25); }
            catch (UcError $ex) { flash($ex->getMessage(), 'error'); $filters = []; $list = config_list($viewer, [], 1, 25); }
            admin_render('configs', '/configuraciones', ['title' => t('Configuraciones'), 'list' => $list, 'filters' => $filters,
                'users' => rows("SELECT id, name FROM users WHERE role != 'cliente' OR id IN (SELECT DISTINCT user_id FROM configurations) ORDER BY name")]);
            return;
        }
        if ($path === '/configuraciones.csv' && !$post) {
            $views = [];
            for ($p = 1; $p <= 50; $p++) {
                $l = config_list($viewer, $filters, $p, 100);
                foreach ($l['items'] as $it) $views[] = config_get($it['code'], $viewer);
                if ($p >= $l['pages']) break;
            }
            admin_download('configuraciones-' . date('Ymd-His') . '.csv', 'text/csv', config_export_csv($views));
            audit('config.export', count($views) . ' configuraciones (CSV)');
            return;
        }
        if ($match('/configuraciones/(UC-[A-Z0-9]{8})(\.json|\.csv|-despiece\.csv|-corte\.csv|/anular|/trabajo|/aceptar)?')) {
            $code = $m[1];
            $ext = $m[2] ?? '';
            if ($post && $ext === '/trabajo') {
                try { config_mark($code, $viewer, ['work_status' => (string) ($_POST['work_status'] ?? ''), 'work_note' => (string) ($_POST['work_note'] ?? '')]); }
                catch (UcError $ex) { admin_back("/configuraciones/$code", $ex); }
                flash(t('Estado de trabajo guardado.'));
                admin_back("/configuraciones/$code");
            }
            if ($post && $ext === '/aceptar') {
                try { config_accept($code, $viewer); } catch (UcError $ex) { admin_back("/configuraciones/$code", $ex); }
                flash(t('Configuración aceptada.'));
                admin_back("/configuraciones/$code");
            }
            if ($post && $ext === '/anular') {
                config_cancel($code, $viewer);
                flash(t('Configuración anulada.'));
                admin_back("/configuraciones/$code");
            }
            $cfg = config_get($code, $viewer);
            if ($ext === '.json') { admin_download("$code.json", 'application/json', config_export_json($cfg)); audit('config.export', "$code (JSON)"); return; }
            if ($ext === '.csv') { admin_download("$code.csv", 'text/csv', config_export_csv([$cfg])); audit('config.export', "$code (CSV)"); return; }
            if ($ext === '-despiece.csv') { admin_download("$code-despiece.csv", 'text/csv', config_export_parts_csv($cfg)); audit('config.export', "$code (despiece CSV)"); return; }
            if ($ext === '-corte.csv') { admin_download("$code-corte.csv", 'text/csv', config_export_cut_csv($cfg)); audit('config.export', "$code (lista de corte CSV)"); return; }
            $lead = row('SELECT id FROM leads WHERE configuration_id = (SELECT id FROM configurations WHERE code = ?) ORDER BY id DESC LIMIT 1', [$code]);
            admin_render('config_view', '/configuraciones', ['title' => $code, 'cfg' => $cfg, 'cut' => cut_list($cfg), 'adri' => adri_status(),
                'lead' => $lead ? lead_get((int) $lead['id'], $viewer) : null]);
            return;
        }

        // ---- leads (lo que deja el cliente final con «Quiero este presupuesto»)
        if ($path === '/leads' && !$post) {
            $lf = array_intersect_key($_GET, ['estado' => 1, 'work_status' => 1, 'q' => 1]);
            try { $leads = lead_list($viewer, $lf, page_clamp((int) ($_GET['page'] ?? 1)), 25); }
            catch (UcError $ex) { flash($ex->getMessage(), 'error'); $lf = []; $leads = lead_list($viewer, [], 1, 25); }
            admin_render('leads', '/leads', ['title' => t('Leads'), 'leads' => $leads, 'filters' => $lf]);
            return;
        }
        if ($post && $match('/leads/(\d+)')) {
            try { lead_mark((int) $m[1], $viewer, ['estado' => (string) ($_POST['estado'] ?? ''), 'work_status' => (string) ($_POST['work_status'] ?? ''), 'work_note' => (string) ($_POST['work_note'] ?? '')]); }
            catch (UcError $ex) { admin_back('/leads', $ex); }
            flash(t('Lead #{id} guardado.', ['id' => (int) $m[1]]));
            admin_back('/leads' . (isset($_POST['back']) && preg_match('#^\?[a-z0-9_=&%-]*$#i', (string) $_POST['back']) ? (string) $_POST['back'] : ''));
        }

        // ---- copias de seguridad
        if ($path === '/copias' && !$post) {
            admin_render('backups', '/copias', ['title' => t('Copias de seguridad'), 'backups' => backup_list(),
                'lastAuto' => (int) val("SELECT value FROM meta WHERE key = 'last_auto_backup'")]);
            return;
        }
        if ($path === '/copias/crear' && $post) {
            try { $b = backup_create(); } catch (UcError $ex) { admin_back('/copias', $ex); }
            flash(t('Copia hecha: {n} ({s}).', ['n' => $b['name'], 's' => bk_human_size($b['size'])]));
            admin_back('/copias');
        }
        if ($path === '/copias/ajustes' && $post) {
            $mode = (string) ($_POST['backup_auto'] ?? 'off');
            $keep = (int) ($_POST['backup_keep'] ?? 7);
            if (!in_array($mode, ['off', 'daily', 'weekly'], true)) admin_back('/copias', new UcError(t('Frecuencia no válida.')));
            if ($keep < 1 || $keep > 365) admin_back('/copias', new UcError(t('Guarda entre 1 y 365 copias automáticas.')));
            settings_save(['backup_auto' => $mode, 'backup_keep' => (string) $keep]);
            audit('backup.settings', "$mode · $keep");
            flash(t('Ajustes de las copias guardados.'));
            admin_back('/copias');
        }
        if ($path === '/copias/subir' && $post) {
            try {
                $f = $_FILES['copia'] ?? [];
                if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new UcError(t('No se pudo subir el archivo (código {c}). Si pesa mucho, súbelo al servidor y usa: php bin/uc restore <archivo> --yes', ['c' => (int) ($f['error'] ?? 4)]));
                $name = backup_import((string) $f['tmp_name'], (string) ($_POST['password'] ?? ''), true);
                if (trim((string) ($_POST['confirm'] ?? '')) !== '') {
                    $r = backup_restore($name, (string) $_POST['confirm'], !empty($_POST['with_config']));
                    flash(t('Copia subida y restaurada. Antes se guardó el estado anterior en {s}.', ['s' => $r['safety']]) . ($r['warnings'] ? ' ' . implode(' ', $r['warnings']) : ''));
                    admin_back('/copias');
                }
            } catch (UcError $ex) { admin_back('/copias', $ex); }
            flash(t('Copia subida y comprobada: {n}. Para volver a ella, pulsa «Restaurar» en la lista.', ['n' => $name]));
            admin_back('/copias');
        }
        if ($post && $match('/copias/(' . preg_quote(BK_PREFIX, '#') . '[0-9a-z-]+\.zip)/(descargar|borrar|restaurar)')) {
            [$name, $act] = [$m[1], $m[2]];
            try {
                if ($act === 'borrar') { backup_delete($name); flash(t('Copia borrada.')); admin_back('/copias'); }
                if ($act === 'restaurar') {
                    $r = backup_restore($name, (string) ($_POST['confirm'] ?? ''), !empty($_POST['with_config']));
                    flash(t('Restaurada {n}. Antes se guardó el estado anterior en {s}.', ['n' => $name, 's' => $r['safety']]) . ($r['warnings'] ? ' ' . implode(' ', $r['warnings']) : ''));
                    admin_back('/copias');
                }
                admin_backup_download($name, (string) ($_POST['password'] ?? ''));
                return;
            } catch (UcError $ex) { admin_back('/copias', $ex); }
        }

        // ---- actualizaciones
        if ($path === '/actualizaciones' && !$post) {
            admin_render('updates', '/actualizaciones', ['title' => t('Actualizaciones'), 'st' => update_status()]);
            return;
        }
        if ($path === '/actualizaciones/buscar' && $post) {
            $st = update_check();
            flash($st['error'] !== '' ? t('No se ha podido comprobar: {e}', ['e' => $st['error']]) : ($st['latest'] ? t('Hay una versión nueva: {v}.', ['v' => $st['latest']['version']]) : t('Tienes la última versión.')), $st['error'] !== '' ? 'error' : 'ok');
            admin_back('/actualizaciones');
        }
        if ($path === '/actualizaciones/ajustes' && $post) {
            settings_save(['update_check_daily' => !empty($_POST['update_check_daily']) ? '1' : '0']);
            audit('update.settings', !empty($_POST['update_check_daily']) ? 'buscar a diario' : 'solo a mano');
            flash(t('Guardado.'));
            admin_back('/actualizaciones');
        }
        if ($path === '/actualizaciones/actualizar' && $post) {
            if (($_POST['confirm'] ?? '') !== '1') admin_back('/actualizaciones', new UcError(t('Marca la casilla de confirmación para actualizar.')));
            try { $r = update_run_remote($u['email']); } catch (UcError $ex) { admin_back('/actualizaciones', $ex); }
            // La segunda mitad la hace la siguiente petición, ya con el código nuevo (public/index.php).
            redirect('/admin/actualizaciones?fin=' . rawurlencode($r['to']));
        }
        if ($path === '/actualizaciones/volver' && $post) {
            if (($_POST['confirm'] ?? '') !== '1') admin_back('/actualizaciones', new UcError(t('Marca la casilla de confirmación.')));
            try { $r = update_manual_rollback($u['email']); flash($r['message']); } catch (UcError $ex) { admin_back('/actualizaciones', $ex); }
            admin_back('/actualizaciones');
        }

        // ---- personas
        if ($path === '/usuarios' && !$post) {
            admin_render('users', '/usuarios', ['title' => t('Personas'), 'users' => rows('SELECT * FROM users ORDER BY active DESC, role, name'),
                'link' => $_SESSION['invite_link'] ?? null]);
            unset($_SESSION['invite_link']);
            return;
        }
        if ($path === '/usuarios/crear' && $post) {
            try { [$nid, $link, $sent] = user_invite((string) ($_POST['email'] ?? ''), (string) ($_POST['name'] ?? ''), (string) ($_POST['role'] ?? 'comercial')); }
            catch (UcError $ex) { admin_back('/usuarios', $ex); }
            unset($_SESSION['old']);
            $_SESSION['invite_link'] = ['email' => strtolower(trim((string) $_POST['email'])), 'link' => $link, 'sent' => $sent];
            flash($sent ? t('Alta hecha: le hemos enviado el enlace para poner su contraseña.') : t('Alta hecha, pero el correo no salió: pásale tú el enlace de abajo.'), $sent ? 'ok' : 'error');
            admin_back('/usuarios');
        }
        if ($post && $match('/usuarios/(\d+)(/enlace)?')) {
            $uid = (int) $m[1];
            $target = user_by_id($uid) ?? throw new UcError(t('Esa persona no existe.'), [], 404);
            try {
                if (!empty($m[2])) {
                    if ($target['auth_source'] !== 'local') throw new UcError(t('Esta persona entra con BionicEye: no tiene contraseña local.'));
                    require_public_url();
                    $_SESSION['invite_link'] = ['email' => $target['email'], 'link' => reset_link(password_reset_create($uid, INVITE_TTL)), 'sent' => false];
                    audit('user.reset_link', $target['email']);
                    flash(t('Enlace nuevo creado (72 horas). Pásaselo a la persona.'));
                } else {
                    user_update($uid, ['name' => $_POST['name'] ?? $target['name'], 'role' => $_POST['role'] ?? $target['role'], 'active' => !empty($_POST['active'])]);
                    flash(t('Guardado.'));
                }
            } catch (UcError $ex) { admin_back('/usuarios', $ex); }
            admin_back('/usuarios');
        }

        // ---- ajustes
        if ($path === '/ajustes' && !$post) {
            admin_render('settings', '/ajustes', ['title' => t('Ajustes'), 'catalogEmpty' => !val('SELECT 1 FROM nodes LIMIT 1')]);
            return;
        }
        if ($path === '/ajustes' && $post) {
            try { admin_settings_save($_POST); } catch (UcError $ex) { admin_back('/ajustes', $ex); }
            flash(t('Ajustes guardados.'));
            admin_back('/ajustes');
        }
        if ($path === '/ajustes/ejemplo' && $post) {
            try { seed_sample(); } catch (UcError $ex) { admin_back('/ajustes', $ex); }
            flash(t('Datos de ejemplo cargados: una cocina con 3 modelos y 4 colores.'));
            admin_back('/catalogo');
        }
        if ($path === '/ajustes/correo-prueba' && $post) {
            try { mail_send([$u['email']], t('Prueba de correo'), t('Si lees esto, el correo de {e} funciona.', ['e' => setting('empresa')])); flash(t('Correo de prueba enviado a {c}.', ['c' => $u['email']])); }
            catch (UcError $ex) { flash($ex->getMessage(), 'error'); }
            admin_back('/ajustes');
        }

        // ---- tokens MCP
        if ($path === '/tokens' && !$post) {
            admin_render('tokens', '/tokens', ['title' => t('Tokens MCP'), 'tokens' => tokens_all(), 'newToken' => $_SESSION['new_token'] ?? null,
                'owners' => rows("SELECT id, name, email, role FROM users WHERE active = 1 AND role IN ('admin','comercial') ORDER BY role, name"), 'mcpUrl' => (public_url() ?? '') . '/mcp']);
            unset($_SESSION['new_token']);
            return;
        }
        if ($path === '/tokens/crear' && $post) {
            try { $raw = token_create((string) ($_POST['name'] ?? ''), (array) ($_POST['scopes'] ?? []), (int) ($_POST['user_id'] ?? 0)); }
            catch (UcError $ex) { admin_back('/tokens', $ex); }
            unset($_SESSION['old']);
            $_SESSION['new_token'] = $raw;
            admin_back('/tokens');
        }
        if ($post && $match('/tokens/(\d+)/revocar')) {
            token_revoke((int) $m[1]);
            flash(t('Token revocado: deja de funcionar al momento.'));
            admin_back('/tokens');
        }

        // ---- actividad
        if ($path === '/actividad' && !$post) {
            $page = page_clamp((int) ($_GET['page'] ?? 1));
            $total = (int) val('SELECT COUNT(*) FROM audit_log');
            admin_render('activity', '/actividad', ['title' => t('Actividad'), 'rows' => rows('SELECT * FROM audit_log ORDER BY id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50)),
                'page' => $page, 'pages' => max(1, (int) ceil($total / 50))]);
            return;
        }
    } catch (UcError $ex) {
        render('message', ['title' => t('Aviso'), 'message' => $ex->getMessage(), 'section' => '/'], $ex->status >= 400 && $ex->status < 600 ? $ex->status : 422);
        return;
    }
    render('message', ['title' => t('No encontrada'), 'message' => t('Esta página del panel no existe.')], 404);
}

function admin_download(string $name, string $mime, string $body): void
{
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    header('Cache-Control: no-store');
    echo $body;
}

/**
 * Descarga de una copia (solo por POST con CSRF desde el panel; data/backups nunca se sirve). Con contraseña, se cifra
 * antes en un temporal que se borra al terminar.
 */
function admin_backup_download(string $name, string $password): void
{
    $path = backup_path($name);
    $tmp = null;
    if ($password !== '') {
        $tmp = tempnam(bk_dir(), '.cif');
        bk_encrypt_file($path, $tmp, $password);
        $path = $tmp;
        $name = preg_replace('/\.zip$/', '.ucbk', $name);
    }
    audit('backup.download', $name . ($tmp ? ' (cifrada)' : ''));
    session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    if ($tmp) @unlink($tmp);
}

/** Nodos para un desplegable: «Cocina › Línea › Blanco», en orden de árbol. */
function admin_node_options(?int $parent = null, int $depth = 0): array
{
    $out = [];
    foreach (node_children($parent, false) as $n) {
        $out[] = ['id' => (int) $n['id'], 'label' => node_path_text((int) $n['id']), 'depth' => $depth];
        array_push($out, ...admin_node_options((int) $n['id'], $depth + 1));
    }
    return $out;
}

function admin_settings_save(array $in): void
{
    $empresa = trim((string) ($in['empresa'] ?? ''));
    if ($empresa === '') throw new UcError(t('Escribe el nombre de la empresa.'));
    $logo = trim((string) ($in['logo'] ?? ''));
    if ($logo !== '' && !preg_match('#^(https://|/)[^\s"\'<>]+$#', $logo)) throw new UcError(t('Logo: pon una dirección https://… o /…; recibido «{v}».', ['v' => mb_substr($logo, 0, 60)]));
    $iva = str_replace(',', '.', trim((string) ($in['iva'] ?? '21')));
    if (!is_numeric($iva) || (float) $iva < 0 || (float) $iva > 100) throw new UcError(t('IVA: pon un porcentaje entre 0 y 100; recibido «{v}».', ['v' => $iva]));
    $transport = (string) ($in['mail_transport'] ?? 'mail');
    if (!in_array($transport, ['mail', 'smtp', 'log'], true)) throw new UcError(t('Forma de envío de correo no válida.'));
    $from = trim((string) ($in['mail_from'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El remitente «{v}» no es un correo válido.', ['v' => $from]));
    $enc = (string) ($in['smtp_encryption'] ?? 'tls');
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) $enc = 'tls';
    $be = rtrim(trim((string) ($in['bionicadri_url'] ?? '')), '/');
    if ($be !== '' && !preg_match('#^https://[^\s/]+#', $be)) throw new UcError(t('BionicEye: la dirección tiene que empezar por https://; recibido «{v}».', ['v' => mb_substr($be, 0, 60)]));
    $url = rtrim(trim((string) ($in['url_publica'] ?? '')), '/');
    if ($url === '') throw new UcError(t('La URL pública es obligatoria: es la base de los enlaces que se envían por correo.'));
    if (!valid_public_url($url)) throw new UcError(t('Dirección pública: pon solo el origen, p. ej. https://configurador.miempresa.com; recibido «{v}».', ['v' => mb_substr($url, 0, 80)]));
    $values = [
        'url_publica' => $url, 'empresa' => mb_substr($empresa, 0, 120), 'logo' => $logo, 'iva' => (string) (float) $iva,
        'permitir_registro' => !empty($in['permitir_registro']) ? '1' : '0', 'permitir_invitado' => !empty($in['permitir_invitado']) ? '1' : '0',
        'mail_transport' => $transport, 'mail_from' => $from, 'smtp_host' => trim((string) ($in['smtp_host'] ?? '')),
        'smtp_port' => (string) parse_int(($in['smtp_port'] ?? '') === '' ? 587 : $in['smtp_port'], t('Puerto SMTP'), 1, 65535),
        'smtp_encryption' => $enc, 'smtp_username' => trim((string) ($in['smtp_username'] ?? '')),
        'bionicadri_url' => $be, 'bionicadri_client_id' => trim((string) ($in['bionicadri_client_id'] ?? '')),
    ];
    if (($in['smtp_password'] ?? '') !== '') $values['smtp_password'] = secret_encrypt((string) $in['smtp_password']);
    settings_save($values);
    audit('settings.update', 'IVA ' . $values['iva'] . ' %, registro ' . $values['permitir_registro'] . ', invitado ' . $values['permitir_invitado']);
}
