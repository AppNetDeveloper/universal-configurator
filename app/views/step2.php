<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $cfg @var array $types @var bool $client @var bool $canUndo */
$path = array_column($cfg['node']['path'], 'name');
?>
<?= steps_bar(2, $cfg['code']) ?>
<div class="cfg-head">
  <h1><?= e(implode(' · ', array_slice($path, -2))) ?><?php if (!$client): ?> <span class="mono code-tag"><?= e($cfg['code']) ?></span><?php endif; ?></h1>
  <details class="how">
    <summary><?= te('Toca un módulo para cambiar su ancho · ¿Cómo va el precio?') ?></summary>
    <p><?= te('Cada módulo tiene unos tramos de medida con su precio (por ejemplo, de 41 a 60 cm, 190 €): dentro de un tramo el precio no cambia. Puedes poner un decimal (58,5). Si una medida cae entre dos tramos, se cobra como el de arriba y te lo decimos.') ?></p>
  </details>
</div>
<div class="cfg2">
  <aside class="plan" id="plan" aria-label="<?= te('Esquema') ?>"></aside>
  <div id="configurator" class="mods"><p class="muted"><?= te('Cargando…') ?></p></div>
</div>
<noscript><p class="flash flash-error"><?= te('Para cambiar los módulos hace falta JavaScript. Puedes ver el resumen igualmente.') ?></p></noscript>
<script type="application/json" id="uc-data"><?= json_encode(['config' => api_lean(['config' => $cfg])['config'], 'types' => $types, 'csrf' => csrf_token(), 'canUndo' => $canUndo, 'client' => $client,
    'fallbackIcon' => module_icon_svg([]), 'texts' => [
    'remove' => t('Quitar'), 'replace' => t('Cambiar por otro'), 'add' => t('Añadir módulo'), 'width' => t('Ancho'), 'tiers' => t('Tramos de medida'),
    'module' => t('módulo'), 'modules' => t('módulos'), 'saving' => t('Guardando…'), 'saved' => t('Guardado'), 'error' => t('No se pudo guardar. Revisa la conexión.'),
    'empty' => t('Aún no hay módulos aquí.'), 'dims' => t('{w} × {h} × {d} mm (ancho × alto × fondo)'), 'invalid' => t('Medida no válida'),
    'chooseType' => t('¿Qué módulo pones?'), 'from' => t('desde'), 'removed' => t('Has quitado «{n}».'), 'undo' => t('Deshacer'), 'undone' => t('Hecho: deshecho.'),
    'more' => t('Más opciones'), 'less' => t('Menos'), 'close' => t('Cerrar'), 'current' => t('el actual'), 'next' => t('Siguiente'), 'nothingHere' => t('No hay otros módulos para este modelo.'),
]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div class="totalbar" role="region" aria-label="<?= te('Total') ?>">
  <div class="wrap wide totalbar-in">
    <button type="button" class="btn btn-ghost btn-undo" id="undo" aria-label="<?= te('Deshacer el último cambio') ?>"<?= $canUndo ? '' : ' hidden' ?>><span aria-hidden="true">↶</span> <span class="undo-t"><?= te('Deshacer') ?></span></button>
    <span id="save-state" class="save-state" aria-live="polite"></span>
    <span class="total-box"><span class="total-label"><?= te('Total sin IVA') ?></span><strong id="total-live" class="total-live"><?= e(money($cfg['total_cents'])) ?></strong></span>
    <a class="btn btn-primary" id="to-summary" href="/configurar/<?= e($cfg['code']) ?>/resumen"><?= te('Siguiente') ?> <span aria-hidden="true">→</span></a>
  </div>
</div>
<script src="<?= e(asset('/assets/configurador.js')) ?>" defer></script>
