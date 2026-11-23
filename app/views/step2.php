<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $cfg @var array $types */ ?>
<?= steps_bar(2, $cfg['code']) ?>
<div class="step-head row-between">
  <div>
    <p class="eyebrow"><?= e($cfg['node']['path_text']) ?> · <span class="mono"><?= e($cfg['code']) ?></span></p>
    <h1><?= te('Módulos') ?></h1>
    <p class="hint"><?= te('Ajusta el ancho de cada módulo con − / + o escribiéndolo (admite un decimal: 58,5). Cada medida cae en un tramo con su precio: no se cobra por centímetro.') ?></p>
  </div>
</div>
<div id="configurator" class="walls" aria-live="polite"><p class="muted"><?= te('Cargando…') ?></p></div>
<noscript><p class="flash flash-error"><?= te('Para editar los módulos hace falta JavaScript. Puedes ver el resumen igualmente.') ?></p></noscript>
<script type="application/json" id="uc-data"><?= json_encode(['config' => $cfg, 'types' => $types, 'csrf' => csrf_token(), 'texts' => [
    'remove' => t('Quitar'), 'replace' => t('Sustituir por…'), 'add' => t('Añadir módulo'), 'width' => t('Ancho'), 'tier' => t('Tramo'), 'tiers' => t('Tramos'),
    'module' => t('módulo'), 'modules' => t('módulos'), 'saving' => t('Guardando…'), 'saved' => t('Guardado'), 'error' => t('No se pudo guardar'),
    'empty' => t('Sin módulos en esta pared.'), 'dims' => t('{w} × {h} × {d} mm (ancho × alto × fondo)'), 'confirmRemove' => t('¿Quitar este módulo?'), 'invalid' => t('Medida no válida'), 'chooseType' => t('Elige un módulo…'),
]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div class="totalbar" role="region" aria-label="<?= te('Total') ?>">
  <div class="wrap wide totalbar-in">
    <span id="mod-count" class="muted"><?= (int) $cfg['count'] ?> <?= te('módulos') ?></span>
    <span id="save-state" class="save-state" aria-live="polite"></span>
    <span class="total-label"><?= te('Total sin IVA') ?></span>
    <strong id="total-live" class="total-live"><?= e(money($cfg['total_cents'])) ?></strong>
    <a class="btn btn-primary" id="to-summary" href="/configurar/<?= e($cfg['code']) ?>/resumen"><?= te('Resumen') ?> →</a>
  </div>
</div>
