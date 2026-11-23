<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Instalador (web en el primer arranque, y CLI con bin/uc): comprueba requisitos, crea data/config.php, la base de
 * datos y el administrador. Datos de ejemplo: una cocina con 3 modelos, 4 colores, 9 tipos de módulo con tramos
 * y composiciones (recta, en L y paralela).
 */

/** @return list<array{0:string,1:bool,2:string}> [etiqueta, ok, detalle] */
function install_requirements(): array
{
    $out = [];
    $out[] = ['PHP 8.2 o superior', PHP_VERSION_ID >= 80200, 'Versión actual: ' . PHP_VERSION];
    foreach (['pdo_sqlite' => 'la base de datos', 'mbstring' => 'el texto en UTF-8', 'sodium' => 'cifrar la contraseña SMTP', 'json' => 'la API y el MCP'] as $ext => $why) {
        $out[] = ["Extensión $ext", extension_loaded($ext), extension_loaded($ext) ? 'Disponible' : "Falta: necesaria para $why"];
    }
    if (!is_dir(UC_DATA)) @mkdir(UC_DATA, 0770, true);
    $ok = is_dir(UC_DATA) && is_writable(UC_DATA);
    $out[] = ['Carpeta de datos escribible', $ok, $ok ? UC_DATA : 'No se puede escribir en ' . UC_DATA . ' (da permisos de escritura al usuario de PHP)'];
    return $out;
}

function install_requirements_ok(): bool
{
    foreach (install_requirements() as $c) if (!$c[1]) return false;
    return true;
}

function install_write_atomic(string $file, string $content): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new UcError('No se pudo escribir ' . basename($file));
    }
}

/** Instalación completa. Entrada: empresa, admin_name, admin_email, admin_password, timezone, sample. Devuelve el id del admin. */
function uc_install(array $in): int
{
    if (is_installed()) throw new UcError(t('Universal Configurator ya está instalado.'));
    if (!install_requirements_ok()) throw new UcError(t('El servidor no cumple los requisitos (revisa la lista).'));
    $empresa = trim((string) ($in['empresa'] ?? ''));
    $tz = trim((string) ($in['timezone'] ?? '')) ?: 'Europe/Madrid';
    if ($empresa === '') throw new UcError(t('Escribe el nombre de la empresa.'));
    if (!in_array($tz, timezone_identifiers_list(), true)) throw new UcError(t('Zona horaria no válida.'));
    if (!filter_var(trim((string) ($in['admin_email'] ?? '')), FILTER_VALIDATE_EMAIL)) throw new UcError(t('El correo del administrador no es válido.'));
    $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
    if ($url === '') throw new UcError(t('Escribe la URL pública de la aplicación (la de los enlaces de los correos), p. ej. https://configurador.miempresa.com.'));
    if (!valid_public_url($url)) throw new UcError(t('URL pública no válida: «{v}». Pon solo el origen, p. ej. https://configurador.miempresa.com.', ['v' => mb_substr($url, 0, 80)]));
    validate_password((string) ($in['admin_password'] ?? ''));

    $lock = fopen(UC_DATA . '/.install.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new UcError(t('Ya hay una instalación en curso.'));
    if (is_file(db_path()) && filesize(db_path()) > 0) {
        throw new UcError(t('Ya existe una base de datos en {d}. Si es una restauración, recupera también data/config.php: el instalador no la sobrescribe.', ['d' => UC_DATA]));
    }
    if (!is_file(UC_DATA . '/.htaccess')) install_write_atomic(UC_DATA . '/.htaccess', "Require all denied\n");
    date_default_timezone_set($tz);
    try {
        migrate();
        Actor::set('instalador');
        settings_save(['empresa' => mb_substr($empresa, 0, 120), 'url_publica' => $url]);
        $id = user_create((string) $in['admin_email'], trim((string) ($in['admin_name'] ?? '')) ?: 'Administrador', 'admin', (string) $in['admin_password']);
        if (!empty($in['sample'])) seed_sample();
        audit('install', 'Universal Configurator ' . UC_VERSION);
        @chmod(db_path(), 0600);
    } catch (Throwable $ex) {
        foreach (['', '-wal', '-shm'] as $s) @unlink(db_path() . $s);
        throw $ex;
    }
    // config.php se escribe el último: is_installed() depende de él.
    $cfg = ['secret' => bin2hex(random_bytes(32)), 'timezone' => $tz, 'debug' => false, 'installed_at' => date('c'), 'version' => UC_VERSION];
    install_write_atomic(UC_DATA . '/config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
    @chmod(UC_DATA . '/config.php', 0600);
    flock($lock, LOCK_UN);
    return $id;
}

function install_code_file(): string
{
    return UC_DATA . '/install-code.txt';
}

/**
 * Código de instalación de un solo uso: mientras no existe data/config.php cualquiera podría abrir el instalador web
 * y crear el administrador. Con el código (que solo puede leer quien entra al servidor) solo instala el dueño.
 */
function install_code(): string
{
    $f = install_code_file();
    if (!is_file($f)) {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) $code .= ($i && $i % 4 === 0 ? '-' : '') . $alphabet[random_int(0, strlen($alphabet) - 1)];
        $old = umask(0077);
        install_write_atomic($f, $code . "\n");
        umask($old);
        @chmod($f, 0600);
    }
    return trim((string) file_get_contents($f));
}

/** Instalador web: todas las rutas llevan aquí hasta que exista data/config.php. */
function install_handle(string $path, string $method): void
{
    if ($path !== '/install' && !str_starts_with($path, '/assets/')) redirect('/install');
    session_start_safe();
    $errors = [];
    if (is_dir(UC_DATA) && is_writable(UC_DATA)) install_code();
    if ($method === 'POST') {
        $norm = fn(string $c) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c)); // con o sin guiones, en mayúsculas o no
        $given = $norm((string) ($_POST['install_code'] ?? ''));
        if (!csrf_valid((string) ($_POST['_csrf'] ?? ''))) {
            $errors[] = t('La sesión ha caducado: vuelve a enviar el formulario.');
        } elseif (!is_file(install_code_file()) || $given === '' || !hash_equals($norm(install_code()), $given)) {
            $errors[] = t('El código de instalación no es correcto. Está en el servidor, en {f} (por ejemplo: cat {f}).', ['f' => install_code_file()]);
        } else {
            try {
                $id = uc_install($_POST);
                @unlink(install_code_file());
                session_login(user_by_id($id));
                flash(t('Instalación terminada. Bienvenido al panel.'));
                redirect('/admin');
            } catch (UcError $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo render_template(UC_ROOT . '/app/views/install.php', ['checks' => install_requirements(), 'ok' => install_requirements_ok(), 'errors' => $errors,
        'values' => array_diff_key($_POST, ['admin_password' => 1, '_csrf' => 1]), 'timezones' => timezone_identifiers_list(),
        'proposedUrl' => base_url(), 'codeFile' => install_code_file()]); // solo se PROPONE (la persona la confirma); lo que se guarda es lo que envía el formulario
}
