<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Datos de ejemplo (opcionales): tres familias —Cocina, Armarios y Baños— con modelos, acabados, tipos de módulo con
 * medidas reales, tramos, despiece por fórmulas, composiciones e ilustraciones generadas por la app.
 * Los tramos de cada tipo cuelgan de la raíz de su familia: así cada familia solo ofrece sus módulos.
 *
 * seed_demo() (bin/uc demo) añade además comerciales, clientes y configuraciones ficticias para ver la app llena.
 * Nunca se ejecuta sola.
 */

const DEMO_PASSWORD = 'demo-2026-clave';
const DEMO_DOMAIN = 'example.com';

// Despiece común. W ancho, H alto, D fondo (mm); T tablero, B trasera. [nombre, cant., espesor, largo, ancho, veta, canto]
const PARTS_CASCO_BAJO = [['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'], ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'],
    ['Travesaño', 2, 'T', 'W - 2*T', '100', 'largo', ''], ['Trasera', 1, 'B', 'H - 3', 'W - 3', '', '']];
const PARTS_CASCO_ALTO = [['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'], ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'],
    ['Techo', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'], ['Trasera', 1, 'B', 'H - 3', 'W - 3', '', '']];
const PART_BALDA = ['Balda', 1, 'T', 'W - 2*T - 2', 'D - 40', 'largo', '1L'];
const PART_PUERTA_1 = ['Puerta', 1, 'T', 'H - 4', 'W - 4', 'largo', '4L'];
const PART_PUERTA_2 = ['Puerta', 2, 'T', 'H - 4', '(W - 7) / 2', 'largo', '4L'];
const PARTS_CAJONES_3 = [['Frente de cajón', 3, 'T', 'W - 4', '(H - 12) / 3', 'largo', '4L'], ['Lateral de cajón', 6, '16', 'D - 60', '150', 'largo', '1L'],
    ['Frente y trasera de caja', 6, '16', 'W - 2*T - 58', '150', 'largo', '1L'], ['Fondo de cajón', 3, 'B', 'D - 62', 'W - 2*T - 26', '', '']];

/** Crea una familia: raíz, modelos con acabados (con su ilustración), tipos con tramos en la raíz y composiciones. */
function seed_family(array $f): array
{
    $root = node_create(null, ['name' => $f['name'], 'description' => $f['description'], 'image' => 'ill:' . $f['ill'] . ':#E2E8F0']);
    $models = [];
    foreach ($f['models'] as $key => $m) {
        $models[$key] = node_create($root, ['name' => $m['name'], 'description' => $m['description'], 'image' => 'ill:' . $m['ill'] . ':#E2E8F0']);
        foreach ($f['finishes'] as [$n, $hex, $d]) node_create($models[$key], ['name' => $n, 'image' => 'ill:' . $m['ill'] . ':' . $hex, 'description' => $d]);
    }
    $tid = [];
    foreach ($f['types'] as $ref => [$name, $family, [$w, $h, $d], $tiers, $parts]) {
        $tid[$ref] = module_type_save(['name' => $name, 'ref' => $ref, 'family' => $family, 'width_mm' => $w, 'height_mm' => $h, 'depth_mm' => $d,
            'min_width_mm' => min(array_column($tiers, 0)) * 10, 'max_width_mm' => max(array_column($tiers, 1)) * 10, 'board_mm' => 19, 'back_mm' => 8,
            'sort' => count(module_types_all(false)) * 10 + 10, 'description' => $f['type_descriptions'][$ref] ?? '']);
        foreach ($tiers as [$from, $to, $eur]) tier_save($tid[$ref], $root, $from, $to, $eur * 100);
        foreach ($parts as $i => [$pn, $q, $th, $len, $wid, $grain, $edge]) {
            module_part_save($tid[$ref], ['name' => $pn, 'qty' => $q, 'thickness' => $th, 'length_expr' => $len, 'width_expr' => $wid, 'grain' => $grain, 'edge_banding' => $edge, 'sort' => ($i + 1) * 10]);
        }
    }
    foreach ($f['layouts'] as $key => $walls) {
        foreach ($walls as [$wname, $dir, $x, $y, $mods]) {
            $wid = layout_wall_save($models[$key], ['name' => $wname, 'direction' => $dir, 'origin_x_mm' => $x, 'origin_y_mm' => $y]);
            foreach ($mods as [$ref, $cm]) default_module_save($wid, $tid[$ref], $cm);
        }
    }
    return ['root' => $root, 'models' => $models, 'types' => $tid];
}

function seed_sample(): void
{
    if (val('SELECT 1 FROM nodes LIMIT 1')) throw new UcError(t('El catálogo ya tiene datos: los datos de ejemplo solo se cargan en un catálogo vacío.'));
    tx(function () {
        $kitchen = seed_family([
            'name' => 'Cocina', 'ill' => 'cocina-linea',
            'description' => 'Cocinas por módulos fabricadas a medida en tablero de 19 mm, con trasera de 8 mm y herrajes de cierre amortiguado.',
            'models' => [
                'linea' => ['name' => 'Línea', 'ill' => 'cocina-linea', 'description' => 'Un solo frente, todo a mano: la distribución más sencilla para cocinas estrechas o abiertas al salón. Altos a juego y columna para frigorífico.'],
                'duo' => ['name' => 'Dúo', 'ill' => 'cocina-l', 'description' => 'Dos frentes en L que aprovechan la esquina y dejan el centro libre. Separa la zona de agua de la de cocción.'],
                'escala' => ['name' => 'Escala', 'ill' => 'cocina-paralela', 'description' => 'Dos frentes enfrentados para trabajar sin dar pasos: fregadero y almacenaje a un lado, cocción y cajones de gran capacidad al otro.'],
            ],
            'finishes' => [
                ['Blanco', '#F8FAFC', 'Laca blanca mate antihuellas, con cantos del mismo color.'],
                ['Roble', '#C8A165', 'Melamina texturizada en roble natural, veta horizontal continua.'],
                ['Antracita', '#374151', 'Laminado gris antracita supermate, de tacto sedoso y fácil limpieza.'],
                ['Madera', '#8B5E3C', 'Nogal oscuro en chapa natural, barnizado mate al agua.'],
            ],
            'types' => [
                'B1P' => ['Bajo 1 puerta', 'bajo', [450, 720, 560], [[30, 40, 85], [41, 50, 95], [51, 60, 110]], [...PARTS_CASCO_BAJO, PART_BALDA, PART_PUERTA_1]],
                'B2P' => ['Bajo 2 puertas', 'bajo', [800, 720, 560], [[61, 80, 150], [81, 100, 175], [101, 120, 205]], [...PARTS_CASCO_BAJO, PART_BALDA, PART_PUERTA_2]],
                'CAJ3' => ['Cajonera 3 cajones', 'bajo', [600, 720, 560], [[40, 50, 160], [51, 60, 185], [61, 80, 225], [81, 90, 250]], [...PARTS_CASCO_BAJO, ...PARTS_CAJONES_3]],
                'FRG' => ['Bajo fregadero', 'bajo', [800, 720, 560], [[60, 80, 140], [81, 100, 165], [101, 120, 190]], [...PARTS_CASCO_BAJO, PART_PUERTA_2]],
                'LVV' => ['Lavavajillas integrable', 'bajo', [600, 720, 560], [[45, 45, 90], [60, 60, 110]], [['Frente', 1, 'T', 'H - 4', 'W - 4', 'largo', '4L']]],
                'CHO' => ['Columna horno', 'columna', [600, 2100, 560], [[60, 60, 260]], [...PARTS_CASCO_ALTO, ['Balda del horno', 1, 'T', 'W - 2*T', 'D - 20', 'largo', '1L'], PART_BALDA,
                    ['Puerta inferior', 1, 'T', '716', 'W - 4', 'largo', '4L'], ['Puerta superior', 1, 'T', 'H - 720 - 600 - 12', 'W - 4', 'largo', '4L']]],
                'CFR' => ['Columna frigorífico', 'columna', [600, 2100, 600], [[60, 60, 240], [61, 90, 310]], [...PARTS_CASCO_ALTO, PART_BALDA,
                    ['Puerta inferior', 1, 'T', '1300', 'W - 4', 'largo', '4L'], ['Puerta superior', 1, 'T', 'H - 1300 - 8', 'W - 4', 'largo', '4L']]],
                'A1P' => ['Alto 1 puerta', 'alto', [400, 720, 330], [[30, 40, 60], [41, 60, 75]], [...PARTS_CASCO_ALTO, ['Balda', 1, 'T', 'W - 2*T - 2', 'D - 20 - B', 'largo', '1L'], PART_PUERTA_1]],
                'A2P' => ['Alto 2 puertas', 'alto', [800, 720, 330], [[61, 80, 105], [81, 100, 125]], [...PARTS_CASCO_ALTO, ['Balda', 1, 'T', 'W - 2*T - 2', 'D - 20 - B', 'largo', '1L'], PART_PUERTA_2]],
            ],
            'type_descriptions' => [
                'B1P' => 'Bajo de una puerta con balda regulable. Patas niveladoras y zócalo.', 'CAJ3' => 'Tres cajones de extracción total con freno; cajón inferior alto para ollas.',
                'FRG' => 'Bajo para fregadero, sin balda, con trasera recortable para tomas.', 'CFR' => 'Columna para frigorífico integrable con dos puertas.',
            ],
            'layouts' => [
                'linea' => [['Frente', 'x', 0, 0, [['CFR', 60], ['B1P', 40], ['FRG', 80], ['LVV', 60], ['CAJ3', 60], ['CHO', 60]]],
                    ['Altos del frente', 'x', 600, 0, [['A1P', 40], ['A2P', 80], ['A1P', 60]]]],
                'duo' => [['Frente', 'x', 0, 0, [['FRG', 80], ['LVV', 60], ['CAJ3', 60], ['B1P', 40]]], ['Lateral', 'y', 0, 600, [['B2P', 80], ['CHO', 60], ['CFR', 60]]],
                    ['Altos del frente', 'x', 0, 0, [['A2P', 80], ['A1P', 60], ['A1P', 60]]]],
                'escala' => [['Lado del fregadero', 'x', 0, 0, [['CFR', 60], ['FRG', 100], ['LVV', 60], ['B1P', 40]]], ['Lado de la placa', 'x', 0, 2400, [['CHO', 60], ['CAJ3', 60], ['B2P', 100], ['CAJ3', 80]]],
                    ['Altos del fregadero', 'x', 600, 0, [['A2P', 100], ['A1P', 60]]]],
            ],
        ]);
        // Escala lleva una cajonera de gama alta: tramos propios que sustituyen a los de la familia en sus acabados.
        tier_save($kitchen['types']['CAJ3'], $kitchen['models']['escala'], 40, 60, 21000);
        tier_save($kitchen['types']['CAJ3'], $kitchen['models']['escala'], 61, 90, 27000);

        $armCasco = [['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'], ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'], ['Techo', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'],
            ['Trasera', 1, 'B', 'H - 3', 'W - 3', '', ''], ['Balda maletero', 1, 'T', 'W - 2*T - 2', 'D - 40', 'largo', '1L']];
        seed_family([
            'name' => 'Armarios', 'ill' => 'armario-batiente',
            'description' => 'Armarios de suelo a techo a medida, con interiores modulares: barra, baldas, maletero y cajoneras.',
            'models' => [
                'corredera' => ['name' => 'Corredera', 'ill' => 'armario-corredera', 'description' => 'Puertas correderas sobre guía superior con freno: no ocupan espacio al abrir. Ideal para dormitorios con paso estrecho.'],
                'batiente' => ['name' => 'Batiente', 'ill' => 'armario-batiente', 'description' => 'Puertas batientes con bisagra de cierre suave y apertura a 110°: todo el interior a la vista de una vez.'],
            ],
            'finishes' => [
                ['Blanco nieve', '#F3F4F6', 'Melamina blanca de poro fino, resistente al rayado.'],
                ['Roble natural', '#C8A165', 'Roble texturizado con veta vertical, cálido y luminoso.'],
                ['Gris grafito', '#4B5563', 'Gris grafito mate, a juego con tiradores negros.'],
                ['Arena', '#D6C7A8', 'Tono arena suave que combina con textiles claros.'],
            ],
            'types' => [
                'AR1P' => ['Armario 1 puerta', 'armario', [500, 2400, 600], [[40, 50, 210], [51, 60, 235]], [...$armCasco, ['Balda', 3, 'T', 'W - 2*T - 2', 'D - 40', 'largo', '1L'], PART_PUERTA_1]],
                'AR2P' => ['Armario 2 puertas', 'armario', [900, 2400, 600], [[80, 90, 340], [91, 100, 375]], [...$armCasco, ['Balda', 2, 'T', 'W - 2*T - 2', 'D - 40', 'largo', '1L'], PART_PUERTA_2]],
                'ARES' => ['Módulo esquina', 'armario', [1000, 2400, 600], [[90, 100, 420], [101, 110, 455]], [...$armCasco, ['Balda', 2, 'T', 'W - 2*T - 2', 'D - 40', 'largo', '1L'],
                    ['Puerta', 1, 'T', 'H - 4', 'W - D - 4', 'largo', '4L'], ['Panel de rincón', 1, 'T', 'H - 4', 'D - 20', 'largo', '1L']]],
                'ARCI' => ['Cajonera interior', 'interior', [800, 600, 500], [[50, 60, 190], [61, 80, 230], [81, 90, 255]], [['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'],
                    ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'], ['Techo', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'], ...PARTS_CAJONES_3]],
                'ARCR' => ['Armario corredera 2 hojas', 'armario', [2000, 2400, 650], [[150, 180, 690], [181, 200, 760], [201, 240, 860]], [...$armCasco,
                    ['Divisor central', 1, 'T', 'H - 2*T', 'D - 100', 'largo', '1L'], ['Balda', 4, 'T', '(W - 3*T) / 2 - 2', 'D - 120', 'largo', '1L'],
                    ['Hoja corredera', 2, 'T', 'H - 40', '(W + 30) / 2', 'largo', '4L']]],
            ],
            'type_descriptions' => [
                'ARCR' => 'Dos hojas correderas que se solapan 30 mm; interior con divisor central y baldas.', 'ARES' => 'Esquina en L con panel de rincón y puerta batiente.',
                'ARCI' => 'Cajonera de tres cajones para el interior de un cuerpo de armario.',
            ],
            'layouts' => [
                'corredera' => [['Frente', 'x', 0, 0, [['ARCR', 200]]]],
                'batiente' => [['Frente', 'x', 0, 0, [['AR2P', 90], ['AR1P', 50], ['AR2P', 90]]], ['Rincón', 'y', 0, 600, [['ARES', 100]]]],
            ],
        ]);

        seed_family([
            'name' => 'Baños', 'ill' => 'bano-lavabo',
            'description' => 'Muebles de baño suspendidos resistentes a la humedad: tablero hidrófugo y cantos sellados.',
            'models' => [
                'suspendido' => ['name' => 'Suspendido', 'ill' => 'bano-lavabo', 'description' => 'Mueble de lavabo suspendido con dos cajones y columna a juego: el suelo queda libre y el baño parece más grande.'],
                'doble' => ['name' => 'Doble seno', 'ill' => 'bano-lavabo', 'description' => 'Mueble ancho para dos lavabos, pensado para baños compartidos.'],
            ],
            'finishes' => [
                ['Blanco brillo', '#FFFFFF', 'Lacado blanco brillo, refleja la luz del baño.'],
                ['Roble claro', '#D9B88F', 'Roble claro hidrófugo con veta horizontal.'],
                ['Verde salvia', '#9CAF88', 'Verde salvia mate, un color sereno y actual.'],
            ],
            'types' => [
                'BLS' => ['Mueble de lavabo suspendido', 'baño', [800, 500, 460], [[60, 80, 310], [81, 100, 360], [101, 120, 410]], [['Lateral', 2, 'T', 'H', 'D', 'largo', '1L'],
                    ['Base', 1, 'T', 'W - 2*T', 'D', 'largo', '1L'], ['Travesaño', 2, 'T', 'W - 2*T', '80', 'largo', ''], ['Trasera', 1, 'B', 'H - 3', 'W - 3', '', ''],
                    ['Frente de cajón', 2, 'T', 'W - 4', '(H - 8) / 2', 'largo', '4L']]],
                'BCO' => ['Columna de baño', 'baño', [350, 1600, 300], [[30, 40, 190], [41, 50, 215]], [...PARTS_CASCO_ALTO,
                    ['Balda', 3, 'T', 'W - 2*T - 2', 'D - 20', 'largo', '1L'], PART_PUERTA_1]],
            ],
            'type_descriptions' => ['BLS' => 'Suspendido a 85 cm, dos cajones con hueco para el sifón.', 'BCO' => 'Columna estrecha con tres baldas regulables.'],
            'layouts' => [
                'suspendido' => [['Pared del lavabo', 'x', 0, 0, [['BLS', 80], ['BCO', 35]]]],
                'doble' => [['Pared del lavabo', 'x', 0, 0, [['BLS', 120], ['BCO', 40]]]],
            ],
        ]);
    });
    audit('sample.load', 'cocina, armarios y baños de ejemplo');
}

/**
 * Demostración: 3 comerciales, 4 clientes y 12 configuraciones de las tres familias (aceptadas, en borrador y una
 * anulada), con anchos variados (con decimal y en hueco de tramo) y un token MCP revocado. Carga los datos de ejemplo
 * si el catálogo está vacío. Nunca se ejecuta sola (bin/uc demo).
 * @return array{users: list<array{email:string, role:string, password:string}>, configs: int}
 */
function seed_demo(): array
{
    if (val('SELECT 1 FROM users WHERE email LIKE ?', ['%@' . DEMO_DOMAIN])) throw new UcError(t('La demostración ya está cargada (hay personas @{d}).', ['d' => DEMO_DOMAIN]));
    if (!val('SELECT 1 FROM nodes LIMIT 1')) seed_sample();
    $admin = row("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1") ?? throw new UcError(t('Hace falta un administrador antes de cargar la demostración.'));
    $people = [['Laura Martín', 'comercial'], ['Diego Navarro', 'comercial'], ['Sara Molina', 'comercial'],
        ['Javier Ortega', 'cliente'], ['Elena Ruiz', 'cliente'], ['Pablo Serrano', 'cliente'], ['Marta Gil', 'cliente']];
    $users = [];
    $ids = [];
    foreach ($people as [$name, $role]) {
        $email = strtolower(str_replace(['í', 'á'], ['i', 'a'], explode(' ', $name)[0] . '.' . explode(' ', $name)[1])) . '@' . DEMO_DOMAIN;
        $ids[$name] = user_create($email, $name, $role, DEMO_PASSWORD);
        $users[] = ['email' => $email, 'role' => $role, 'password' => DEMO_PASSWORD];
    }
    $leaf = fn(string $model, string $finish) => (int) val('SELECT c.id FROM nodes c JOIN nodes m ON m.id = c.parent_id WHERE m.name = ? AND c.name = ?', [$model, $finish]);
    $type = fn(string $ref) => (int) val('SELECT id FROM module_types WHERE ref = ?', [$ref]);
    // [dueño, modelo, acabado, cliente [nombre, correo, teléfono], cambios [[ref, ancho cm]], estado, días atrás, trabajo [estado, nota]]
    // Los clientes no aceptan (eso es del equipo): piden el presupuesto («requested») y dejan un lead.
    $plan = [
        ['Laura Martín', 'Línea', 'Roble', ['Ana Belén Castro', 'anabelen@' . DEMO_DOMAIN, '611 204 377'], [['CAJ3', '58,5']], 'accepted', 21, ['hecho', 'Planos y presupuesto enviados; medición el día 18']],
        ['Laura Martín', 'Dúo', 'Blanco', ['Óscar Prieto', 'oscar.prieto@' . DEMO_DOMAIN, '622 418 905'], [['CAJ3', '50,5'], ['B1P', '45']], 'draft', 3],
        ['Laura Martín', 'Corredera', 'Gris grafito', ['Lucía Herrera', 'lucia.h@' . DEMO_DOMAIN, '633 902 118'], [['ARCR', '215']], 'accepted', 14, ['en_curso', 'Planos hechos; falta confirmar el hueco de la puerta']],
        ['Diego Navarro', 'Escala', 'Antracita', ['Familia Romero', 'romero@' . DEMO_DOMAIN, '644 551 230'], [['B2P', '92,5']], 'accepted', 9, ['hecho', 'Pedido pasado a taller']],
        ['Diego Navarro', 'Batiente', 'Roble natural', ['Iván Delgado', 'ivan.delgado@' . DEMO_DOMAIN, '655 013 772'], [['AR1P', '55']], 'draft', 2],
        ['Diego Navarro', 'Suspendido', 'Verde salvia', ['Nuria Campos', 'nuria.c@' . DEMO_DOMAIN, '666 330 481'], [['BLS', '90']], 'cancelled', 30],
        ['Sara Molina', 'Línea', 'Blanco', ['Hotel Mirador', 'compras@' . DEMO_DOMAIN, '966 120 340'], [['FRG', '100']], 'accepted', 6],
        ['Sara Molina', 'Doble seno', 'Roble claro', ['Clínica Dental Sur', 'admin@' . DEMO_DOMAIN, '952 778 120'], [['BLS', '110']], 'draft', 1],
        ['Javier Ortega', 'Dúo', 'Madera', [], [['B2P', '75']], 'draft', 4],
        ['Elena Ruiz', 'Corredera', 'Arena', ['Elena Ruiz', 'elena.ruiz@' . DEMO_DOMAIN, '677 401 220'], [['ARCR', '180,5']], 'requested', 11],
        ['Pablo Serrano', 'Suspendido', 'Blanco brillo', [], [['BCO', '40,5']], 'draft', 5],
        ['Marta Gil', 'Batiente', 'Blanco nieve', ['Marta Gil', 'marta.gil@' . DEMO_DOMAIN, '688 912 004'], [['AR2P', '95']], 'requested', 8],
    ];
    $n = 0;
    foreach ($plan as $row) {
        [$owner, $model, $finish, $cust, $changes, $status, $daysAgo] = $row;
        $work = $row[7] ?? null;
        $u = user_by_id($ids[$owner]);
        $v = ['kind' => 'user', 'user' => $u];
        $cfg = config_create($leaf($model, $finish), $v, $cust ? ['name' => $cust[0], 'email' => $cust[1], 'phone' => $cust[2]] : []);
        $ops = [];
        foreach ($changes as [$ref, $cm]) {
            foreach ($cfg['walls'] as $w) foreach ($w['items'] as $it) if ($it['module_type_id'] === $type($ref)) { $ops[] = ['op' => 'set_width', 'item_id' => $it['id'], 'width_cm' => $cm]; continue 3; }
            $ops[] = ['op' => 'add', 'wall_id' => $cfg['walls'][0]['id'], 'module_type_id' => $type($ref), 'width_cm' => $cm];
        }
        if ($ops) config_apply_ops($cfg['code'], $ops, $v);
        if ($status === 'accepted') config_accept($cfg['code'], $v);
        if ($status === 'requested') config_request($cfg['code'], $v, ['name' => $cust[0], 'email' => $cust[1], 'phone' => $cust[2], 'consent' => 1], false);
        if ($work) { Actor::set('MCP: ADRI (' . $u['email'] . ')'); config_mark($cfg['code'], ['kind' => 'user', 'user' => $admin], ['work_status' => $work[0], 'work_note' => $work[1]]); Actor::set('cli'); }
        if ($status === 'cancelled') config_cancel($cfg['code'], ['kind' => 'user', 'user' => $admin]);
        $at = date('Y-m-d H:i:s', time() - $daysAgo * 86400 - random_int(0, 36000));
        q('UPDATE configurations SET created_at = ?, updated_at = ?, accepted_at = CASE WHEN accepted_at IS NULL THEN NULL ELSE ? END,
            requested_at = CASE WHEN requested_at IS NULL THEN NULL ELSE ? END WHERE code = ?', [$at, $at, $at, $at, $cfg['code']]);
        q('UPDATE leads SET created_at = ?, updated_at = ? WHERE configuration_id = (SELECT id FROM configurations WHERE code = ?)', [$at, $at, $cfg['code']]);
        $n++;
    }
    // Dos clientes finales sin cuenta (invitados) que piden el presupuesto desde el móvil.
    foreach ([['Dúo', 'Antracita', 'Raúl Ortiz', 'raul.ortiz@' . DEMO_DOMAIN, '699 230 114', 'Llamadme por la tarde, por favor.', 1],
              ['Suspendido', 'Roble claro', 'Carmen Soto', '', '611 870 552', '', 0]] as [$model, $finish, $name, $email, $phone, $msg, $daysAgo]) {
        $g = ['kind' => 'guest', 'codes' => []];
        $cfg = config_create($leaf($model, $finish), $g);
        $g['codes'][] = $cfg['code'];
        config_request($cfg['code'], $g, ['name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $msg, 'consent' => 1], false);
        $at = date('Y-m-d H:i:s', time() - $daysAgo * 86400 - random_int(3600, 20000));
        q('UPDATE configurations SET created_at = ?, updated_at = ?, requested_at = ? WHERE code = ?', [$at, $at, $at, $cfg['code']]);
        q('UPDATE leads SET created_at = ?, updated_at = ? WHERE configuration_id = (SELECT id FROM configurations WHERE code = ?)', [$at, $at, $cfg['code']]);
        $n++;
    }
    q("UPDATE leads SET status = 'contactado', work_status = 'en_curso', work_note = 'Llamada hecha; visita el jueves', work_by = 'laura.martin@example.com', work_at = ?
        WHERE name = 'Elena Ruiz'", [now()]);
    throttle_clear('lead:' . client_ip_key());
    // Un token de demostración ya revocado (para enseñar la pantalla de tokens sin descubrir ninguno vivo).
    $raw = token_create('ADRI (demo, revocado)', ['read', 'write'], (int) $ids['Laura Martín']);
    token_revoke((int) val('SELECT id FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $raw)]));
    audit('demo.load', "$n configuraciones de demostración");
    return ['users' => $users, 'configs' => $n];
}
