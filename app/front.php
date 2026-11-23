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

function steps_bar(int $active, ?string $code = null): string
{
    $labels = [1 => t('Modelo'), 2 => t('Módulos'), 3 => t('Resumen y precio')];
    $links = [1 => '/configurar', 2 => $code ? "/configurar/$code" : null, 3 => $code ? "/configurar/$code/resumen" : null];
    $h = '<nav class="steps" aria-label="' . te('Progreso') . '">';
    foreach ($labels as $n => $l) {
        $cls = 'step' . ($n === $active ? ' active' : '') . ($n < $active ? ' done' : '');
        $inner = '<span class="dot">' . $n . '</span><span class="lbl">' . e($l) . '</span>';
        $h .= $links[$n] && $n !== $active ? '<a class="' . $cls . '" href="' . e($links[$n]) . '">' . $inner . '</a>'
            : '<span class="' . $cls . '"' . ($n === $active ? ' aria-current="step"' : '') . '>' . $inner . '</span>';
    }
    return $h . '</nav>';
}

/** Muestra de un nodo: color (#RRGGBB) o imagen. */
function node_visual(?string $image): string
{
    if ($image === null || $image === '') return '';
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $image)) return '<span class="swatch" style="background:' . e($image) . '" aria-hidden="true"></span>';
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
        render('login', ['title' => t('Entrar'), 'providers' => auth_providers_enabled()]);
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
        $nodes = array_map(fn($n) => $n + ['is_final' => node_is_final((int) $n['id'])], node_children($parent));
        render('step1', ['title' => t('Configurar'), 'parent' => $parent ? node_get($parent) : null, 'path' => $parent ? node_path($parent) : [], 'nodes' => $nodes]);
        return;
    }
    if (preg_match('#^/configurar/(UC-[A-Z0-9]{8})(/(resumen|aceptar|duplicar))?$#', $path, $m)) {
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
        if ($action === 'resumen' || $cfg['status'] !== 'draft') {
            render('step3', ['title' => t('Resumen {c}', ['c' => $code]), 'cfg' => $cfg, 'isGuest' => $viewer['kind'] === 'guest']);
            return;
        }
        render('step2', ['title' => t('Módulos {c}', ['c' => $code]), 'cfg' => $cfg, 'types' => module_types_for_node($cfg['node']['id']), 'wide' => true]);
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
