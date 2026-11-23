<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
// Proceso de la prueba de concurrencia: php tests/_race_worker.php <accept|edit> <código> <id de usuario> <segundos>
// Imprime una línea por resultado: ok | 4xx | 503 | 500 <mensaje>.

[, $mode, $code, $uid, $secs] = $argv;
require dirname(__DIR__) . '/app/bootstrap.php';
$viewer = ['kind' => 'user', 'user' => user_by_id((int) $uid)];
$types = array_map('intval', array_column(rows('SELECT id FROM module_types ORDER BY id'), 'id'));
$end = microtime(true) + (float) $secs;
$i = 0;
while (microtime(true) < $end) {
    try {
        if ($mode === 'accept') { config_accept($code, $viewer); echo "ok\n"; }
        else {
            $item = (int) val('SELECT i.id FROM config_items i JOIN config_walls w ON w.id = i.config_wall_id JOIN configurations c ON c.id = w.configuration_id WHERE c.code = ? ORDER BY i.id LIMIT 1', [$code]);
            config_apply_ops($code, [['op' => 'replace', 'item_id' => $item, 'module_type_id' => $types[$i++ % count($types)]]], $viewer);
            echo "ok\n";
        }
    } catch (UcError $e) {
        echo $e->status, "\n";
    } catch (Throwable $e) {
        echo '500 ', str_replace("\n", ' ', $e->getMessage()), "\n";
    }
}
