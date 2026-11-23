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
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/') ?: (PHP_SAPI === 'cli' ? '' : base_url());
        if ($url !== '' && !preg_match('#^https?://[^\s/]+$#', $url)) throw new UcError(t('Dirección pública no válida: «{v}» (ej. https://configurador.miempresa.com).', ['v' => $url]));
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

/** Instalador web: todas las rutas llevan aquí hasta que exista data/config.php. */
function install_handle(string $path, string $method): void
{
    if ($path !== '/install' && !str_starts_with($path, '/assets/')) redirect('/install');
    session_start_safe();
    $errors = [];
    if ($method === 'POST') {
        if (!csrf_valid((string) ($_POST['_csrf'] ?? ''))) {
            $errors[] = t('La sesión ha caducado: vuelve a enviar el formulario.');
        } else {
            try {
                $id = uc_install($_POST);
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
        'values' => array_diff_key($_POST, ['admin_password' => 1, '_csrf' => 1]), 'timezones' => timezone_identifiers_list()]);
}

// ---------------------------------------------------------------- datos de ejemplo

/** Una cocina: 3 modelos × 4 colores, 9 tipos de módulo con tramos sin solapes y composiciones recta, en L y paralela. */
function seed_sample(): void
{
    if (val('SELECT 1 FROM nodes LIMIT 1')) throw new UcError(t('El catálogo ya tiene datos: los datos de ejemplo solo se cargan en un catálogo vacío.'));
    tx(function () {
        $root = node_create(null, ['name' => 'Cocina', 'description' => 'Configura tu cocina módulo a módulo.']);
        $models = [
            'linea' => node_create($root, ['name' => 'Línea', 'description' => 'Cocina recta de un solo frente.']),
            'duo' => node_create($root, ['name' => 'Dúo', 'description' => 'Cocina en L de dos frentes.']),
            'escala' => node_create($root, ['name' => 'Escala', 'description' => 'Cocina en paralelo: dos frentes enfrentados.']),
        ];
        $colors = [['Blanco', '#F8FAFC', 'Laca blanca mate'], ['Roble', '#C8A165', 'Madera natural de roble'], ['Antracita', '#374151', 'Gris antracita mate'], ['Madera', '#8B5E3C', 'Nogal oscuro']];
        foreach ($models as $mid) foreach ($colors as [$n, $hex, $d]) node_create($mid, ['name' => $n, 'image' => $hex, 'description' => $d]);

        // [nombre, ref, familia, fondo mm, [[desde, hasta, €], …]]
        $types = [
            'B1P' => ['Bajo 1 puerta', 'bajo', 560, [[30, 40, 85], [41, 50, 95], [51, 60, 110]]],
            'B2P' => ['Bajo 2 puertas', 'bajo', 560, [[61, 80, 150], [81, 100, 175], [101, 120, 205]]],
            'CAJ3' => ['Cajonera 3 cajones', 'bajo', 560, [[40, 50, 160], [51, 60, 185], [61, 80, 225], [81, 90, 250]]],
            'FRG' => ['Bajo fregadero', 'bajo', 560, [[60, 80, 140], [81, 100, 165], [101, 120, 190]]],
            'LVV' => ['Lavavajillas integrable', 'bajo', 560, [[45, 45, 90], [60, 60, 110]]],
            'CHO' => ['Columna horno', 'columna', 560, [[60, 60, 260]]],
            'CFR' => ['Columna frigorífico', 'columna', 600, [[60, 60, 240], [61, 90, 310]]],
            'A1P' => ['Alto 1 puerta', 'alto', 330, [[30, 40, 60], [41, 60, 75]]],
            'A2P' => ['Alto 2 puertas', 'alto', 330, [[61, 80, 105], [81, 100, 125]]],
        ];
        $tid = [];
        $sort = 10;
        foreach ($types as $ref => [$name, $family, $depth, $tiers]) {
            $tid[$ref] = module_type_save(['name' => $name, 'ref' => $ref, 'family' => $family, 'depth_mm' => $depth, 'sort' => $sort += 10]);
            foreach ($tiers as [$f, $to, $eur]) tier_save($tid[$ref], null, $f, $to, $eur * 100);
        }
        // Escala lleva una cajonera de gama alta: tramos propios que sustituyen a los generales en sus colores.
        tier_save($tid['CAJ3'], $models['escala'], 40, 60, 21000);
        tier_save($tid['CAJ3'], $models['escala'], 61, 90, 27000);

        $wall = function (int $node, string $name, string $dir, int $x, int $y, array $mods) use ($tid) {
            $w = layout_wall_save($node, ['name' => $name, 'direction' => $dir, 'origin_x_mm' => $x, 'origin_y_mm' => $y]);
            foreach ($mods as [$ref, $cm]) default_module_save($w, $tid[$ref], $cm);
        };
        // Recta
        $wall($models['linea'], 'Frente', 'x', 0, 0, [['CFR', 60], ['B1P', 40], ['FRG', 80], ['LVV', 60], ['CAJ3', 60], ['CHO', 60]]);
        $wall($models['linea'], 'Altos del frente', 'x', 600, 0, [['A1P', 40], ['A2P', 80], ['A1P', 60]]);
        // En L: la pared B arranca en la esquina y avanza en Y
        $wall($models['duo'], 'Pared A', 'x', 0, 0, [['FRG', 80], ['LVV', 60], ['CAJ3', 60], ['B1P', 40]]);
        $wall($models['duo'], 'Pared B', 'y', 0, 600, [['B2P', 80], ['CHO', 60], ['CFR', 60]]);
        $wall($models['duo'], 'Altos pared A', 'x', 0, 0, [['A2P', 80], ['A1P', 60], ['A1P', 60]]);
        // Paralela: dos frentes enfrentados a 2,4 m
        $wall($models['escala'], 'Frente A', 'x', 0, 0, [['CFR', 60], ['FRG', 100], ['LVV', 60], ['B1P', 40]]);
        $wall($models['escala'], 'Frente B', 'x', 0, 2400, [['CHO', 60], ['CAJ3', 60], ['B2P', 100], ['CAJ3', 80]]);
        $wall($models['escala'], 'Altos frente A', 'x', 600, 0, [['A2P', 100], ['A1P', 60]]);
    });
    audit('sample.load', 'cocina de ejemplo');
}
