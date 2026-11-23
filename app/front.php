<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Pantallas públicas y del configurador (tablet primero): entrar, registro, olvido, invitado, los 3 pasos y
 * «mis configuraciones». Plantillas en app/views; todo texto con t() y toda salida con e().
 */

function render_template(string $file, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require $file;
    return (string) ob_get_clean();
}

/** Pinta una vista dentro del marco común (views/layout.php). */
function render(string $view, array $vars = [], int $status = 200): void
{
    $GLOBALS['__old'] = (array) ($_SESSION['old'] ?? []); // valores de un formulario que falló: valen para esta pantalla y se borran
    unset($_SESSION['old']);
    $common = ['user' => current_user(), 'guest' => is_guest(), 'flashes' => flash_take(), 'title' => '', 'wide' => false, 'section' => ''];
    $vars += $common;
    $vars['content'] = render_template(UC_ROOT . "/app/views/$view.php", $vars);
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo render_template(UC_ROOT . '/app/views/layout.php', $vars);
}

function asset(string $path): string
{
    $f = UC_PUBLIC . $path;
    return $path . '?v=' . substr(md5(UC_VERSION . '|' . (is_file($f) ? filemtime($f) : 0)), 0, 8);
}

/** Valores de un formulario que falló (para no hacer escribir de nuevo). Nunca guarda contraseñas. */
function old(string $key, string $default = ''): string
{
    $v = $GLOBALS['__old'][$key] ?? $default;
    return is_scalar($v) ? (string) $v : $default;
}

function keep_old(array $post): void
{
    $_SESSION['old'] = array_diff_key($post, ['password' => 1, 'password2' => 1, '_csrf' => 1, 'admin_password' => 1]);
}

/** Barra de progreso fina: Elige → Ajusta → Listo (los pasos hechos se pueden tocar). */
function steps_bar(int $active, ?string $code = null): string
{
    $labels = [1 => t('Elige'), 2 => t('Ajusta'), 3 => t('Listo')];
    $links = [1 => '/configurar', 2 => $code ? "/configurar/$code" : null, 3 => $code ? "/configurar/$code/resumen" : null];
    $h = '<nav class="steps" aria-label="' . te('Paso {n} de 3', ['n' => $active]) . '"><ol>';
    foreach ($labels as $n => $l) {
        $cls = 'step' . ($n === $active ? ' active' : '') . ($n < $active ? ' done' : '');
        $inner = '<span class="bar" aria-hidden="true"></span><span class="lbl">' . $n . ' · ' . e($l) . '</span>';
        $h .= '<li class="' . $cls . '">' . ($links[$n] && $n < $active ? '<a href="' . e($links[$n]) . '">' . $inner . '</a>'
            : '<span' . ($n === $active ? ' aria-current="step"' : '') . '>' . $inner . '</span>') . '</li>';
    }
    return $h . '</ol></nav>';
}

/** ¿Quien mira es el cliente final (invitado o cliente con cuenta)? Para él, nada de jerga de oficina. */
function viewer_is_client(?array $viewer): bool
{
    return $viewer !== null && ($viewer['kind'] === 'guest' || ($viewer['user']['role'] ?? '') === 'cliente');
}

/** Hijos visibles de un nodo; si solo hay uno y no es final, se baja solo (no se pregunta lo que no se puede elegir). */
function step1_skip_single(?int $parent): ?int
{
    for ($i = 0; $i < 20; $i++) {
        $kids = node_children($parent);
        if (count($kids) !== 1 || node_is_final((int) $kids[0]['id'])) return $parent;
        $parent = (int) $kids[0]['id'];
    }
    return $parent;
}

/** «Atrás» desde un nivel: el antepasado más cercano con más de una opción (o el inicio). */
function step1_back(?int $nodeId): ?string
{
    if ($nodeId === null) return null;
    $n = node_get($nodeId);
    while ($n) {
        $up = $n['parent_id'] === null ? null : (int) $n['parent_id'];
        if (count(node_children($up)) > 1) return '/configurar' . ($up ? '?nodo=' . $up : '');
        if ($up === null) return null;
        $n = node_get($up);
    }
    return null;
}

/** Muestra de un nodo: color (#RRGGBB) o imagen. */
function node_visual(?string $image): string
{
    if ($image === null || $image === '') return '';
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $image)) return '<span class="swatch" style="background:' . e($image) . '" aria-hidden="true"></span>';
    if (str_starts_with($image, 'ill:')) { $svg = illustration_from_value($image); return $svg === '' ? '' : '<span class="node-ill">' . $svg . '</span>'; }
    return '<img class="node-img" src="' . e($image) . '" alt="" loading="lazy">';
}

/** Exige persona o invitado; si no, a la pantalla de entrada. */
function front_require_viewer(): array
{
    $v = session_viewer();
    if (!$v) {
        flash(t('Entra con tu cuenta para configurar.'), 'error');
        redirect('/');
    }
    Actor::set(viewer_label($v));
    return $v;
}

function front_handle(string $path, string $method): void
{
    $post = $method === 'POST';
    if ($post) csrf_check();
    $u = current_user();
    if ($u) Actor::set($u['email']);

    // --- acceso
    if ($path === '/') {
        if ($u || is_guest()) redirect('/configurar');
        // Para el cliente final, lo primero es «Empezar» (si se permite configurar sin cuenta); entrar, discreto debajo.
        if (guest_allowed()) { render('home', ['title' => t('Configura tus muebles'), 'roots' => node_children(null), 'bare' => true]); return; }
        render('login', ['title' => t('Entrar'), 'providers' => auth_providers_enabled()]);
        return;
    }
    if ($path === '/entrar' && !$post) {
        if ($u) redirect(is_admin($u) ? '/admin' : '/configurar');
        render('login', ['title' => t('Entrar'), 'providers' => auth_providers_enabled(), 'team' => true]);
        return;
    }
    if (preg_match('#^/ver/([A-Za-z0-9.\-]{1,80})$#', $path, $m)) {
        $cfg = share_resolve($m[1]);
        if (!$cfg) { render('message', ['title' => t('Enlace no válido'), 'message' => t('Este enlace ha caducado o ya no está disponible. Pide uno nuevo a quien te lo envió.')], 404); return; }
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        render('shared', ['title' => t('Configuración compartida'), 'cfg' => config_view($cfg), 'bare' => true]);
        return;
    }
    if ($path === '/entrar' && $post) {
        try {
            login_attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            unset($_SESSION['old']);
            redirect(is_admin(current_user()) ? '/admin' : '/configurar');
        } catch (UcError $ex) {
            keep_old($_POST);
            flash($ex->getMessage(), 'error');
            redirect('/');
        }
    }
    if ($path === '/salir' && $post) { session_logout(); flash(t('Has salido.')); redirect('/'); }
    if ($path === '/invitado' && $post) {
        try { guest_start(); redirect('/configurar'); } catch (UcError $ex) { flash($ex->getMessage(), 'error'); redirect('/'); }
    }
    if ($path === '/registro') {
        if (!setting_bool('permitir_registro')) { render('message', ['title' => t('Registro cerrado'), 'message' => t('El registro no está abierto. Pide una cuenta a la empresa.')], 404); return; }
        if ($post) {
            try {
                $msg = register_user((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''));
                unset($_SESSION['old']);
                flash($msg);
                redirect('/');
            } catch (UcError $ex) { keep_old($_POST); flash($ex->getMessage(), 'error'); redirect('/registro'); }
        }
        render('register', ['title' => t('Crear cuenta')]);
        return;
    }
    if ($path === '/olvide') {
        if ($post) {
            password_reset_request((string) ($_POST['email'] ?? ''));
            flash(t('Si ese correo tiene cuenta, te hemos enviado un enlace para poner una contraseña nueva (vale 1 hora).'));
            redirect('/');
        }
        render('forgot', ['title' => t('Recuperar contraseña')]);
        return;
    }
    if ($path === '/restablecer') {
        $tok = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        if ($post) {
            try {
                if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) throw new UcError(t('Las dos contraseñas no coinciden.'));
                $usr = password_reset_consume($tok, (string) ($_POST['password'] ?? ''));
                session_login($usr);
                flash(t('Contraseña guardada.'));
                redirect(is_admin($usr) ? '/admin' : '/configurar');
            } catch (UcError $ex) { flash($ex->getMessage(), 'error'); redirect('/restablecer?token=' . rawurlencode($tok)); }
        }
        $who = password_reset_user($tok);
        render('reset', ['title' => t('Nueva contraseña'), 'token' => $tok, 'who' => $who]);
        return;
    }
    if ($path === '/auth/bionicadri') {
        try { redirect(auth_provider('bionicadri')->authorizeUrl(require_public_url() . '/auth/bionicadri/callback')); }
        catch (UcError $ex) { render('message', ['title' => t('No disponible'), 'message' => $ex->getMessage()], 404); return; }
    }
    if ($path === '/auth/bionicadri/callback') {
        try {
            $usr = auth_provider('bionicadri')->callback($_GET, require_public_url() . '/auth/bionicadri/callback');
            redirect(is_admin($usr) ? '/admin' : '/configurar');
        } catch (UcError $ex) { flash($ex->getMessage(), 'error'); redirect('/'); }
    }

    // --- configurador
    if ($path === '/configurar') {
        $viewer = front_require_viewer();
        if ($post) {
            try {
                $cfg = config_create(parse_int($_POST['node_id'] ?? null, 'node_id'), $viewer);
                redirect('/configurar/' . $cfg['code']);
            } catch (UcError $ex) { flash($ex->getMessage(), 'error'); redirect('/configurar'); }
        }
        $parent = isset($_GET['nodo']) && ctype_digit((string) $_GET['nodo']) ? (int) $_GET['nodo'] : null;
        if ($parent !== null && (!node_get($parent) || !node_branch_active($parent))) { flash(t('Esa opción ya no está disponible.'), 'error'); redirect('/configurar'); }
        $skip = step1_skip_single($parent);
        if ($skip !== $parent) redirect('/configurar?nodo=' . $skip); // un nivel con una sola opción no se pregunta
        $nodes = array_map(fn($n) => $n + ['is_final' => node_is_final((int) $n['id'])], node_children($parent));
        render('step1', ['title' => t('Configurar'), 'parent' => $parent ? node_get($parent) : null, 'path' => $parent ? node_path($parent) : [], 'nodes' => $nodes,
            'back' => step1_back($parent), 'client' => viewer_is_client($viewer)]);
        return;
    }
    if (preg_match('#^/configurar/(UC-[A-Z0-9]{8})(/(resumen|aceptar|duplicar|pedir|compartir))?$#', $path, $m)) {
        $viewer = front_require_viewer();
        $code = $m[1];
        $action = $m[3] ?? '';
        try {
            if ($post && $action === 'aceptar') {
                config_accept($code, $viewer, ['name' => $_POST['name'] ?? null, 'email' => $_POST['email'] ?? null, 'phone' => $_POST['phone'] ?? null], !empty($_POST['consent']));
                unset($_SESSION['old']);
                flash(t('Configuración aceptada. Precio guardado.'));
                redirect("/configurar/$code/resumen");
            }
            if ($post && $action === 'pedir') {
                $r = config_request($code, $viewer, $_POST);
                unset($_SESSION['old']);
                $_SESSION['requested'][$code] = ['mailed' => $r['mailed']];
                redirect("/configurar/$code/resumen");
            }
            if ($post && $action === 'compartir') {
                $_SESSION['share'][$code] = config_share($code, $viewer);
                redirect("/configurar/$code/resumen#compartir");
            }
            if ($post && $action === 'duplicar') {
                $cfg = config_copy($code, $viewer);
                flash(t('Copia creada: {c}. Puedes cambiarla.', ['c' => $cfg['code']]));
                redirect('/configurar/' . $cfg['code']);
            }
            $cfg = config_get($code, $viewer);
        } catch (UcError $ex) {
            if ($ex->status === 404) { render('message', ['title' => t('No encontrada'), 'message' => $ex->getMessage()], 404); return; }
            keep_old($_POST);
            flash($ex->getMessage(), 'error');
            redirect("/configurar/$code/resumen");
        }
        $client = viewer_is_client($viewer);
        if ($action === 'resumen' || $cfg['status'] !== 'draft') {
            render('step3', ['title' => t('Tu presupuesto'), 'cfg' => $cfg, 'isGuest' => $viewer['kind'] === 'guest', 'client' => $client,
                'share' => $_SESSION['share'][$code] ?? null, 'requested' => $_SESSION['requested'][$code] ?? null]);
            return;
        }
        $types = array_map(fn($t) => $t + ['icon' => module_icon_svg($t)], module_types_for_node($cfg['node']['id']));
        render('step2', ['title' => t('Ajusta los módulos'), 'cfg' => $cfg, 'types' => $types, 'client' => $client,
            'canUndo' => !empty($_SESSION['undo'][$code]), 'wide' => true]);
        return;
    }
    if ($path === '/mis-configuraciones') {
        $viewer = front_require_viewer();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        render('my_configs', ['title' => t('Mis configuraciones'), 'list' => config_list($viewer, [], $page, 25)]);
        return;
    }

    render('message', ['title' => t('No encontrada'), 'message' => t('Esta página no existe.')], 404);
}
