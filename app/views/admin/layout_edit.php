<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $node @var array $path @var array $walls @var ?array $inheritedFrom @var array $types @var bool $isFinal */
$typeOpts = function (?int $sel) use ($types) {
    $h = '';
    foreach ($types as $t) $h .= '<option value="' . (int) $t['id'] . '"' . ($sel === (int) $t['id'] ? ' selected' : '') . '>' . e($t['name'] . ($t['ref'] ? " ({$t['ref']})" : '')) . '</option>';
    return $h;
};
?>
<nav class="crumbs"><a href="/admin/catalogo"><?= te('Catálogo') ?></a><?php foreach ($path as $p): ?><span>›</span><a href="/admin/catalogo/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?php endforeach; ?></nav>
<div class="page-head"><h1><?= te('Composición por defecto') ?></h1></div>
<p class="hint"><?= te('Paredes y módulos con los que arranca una configuración. Si este nodo no tiene composición propia, sus nodos finales usan la del antepasado más cercano que la tenga. Dirección x/y y origen en mm: la geometría que usa ADRI para el plano.') ?></p>
<?php if ($inheritedFrom): ?><p class="notice"><?= te('Ahora hereda la composición de «{n}».', ['n' => $inheritedFrom['name']]) ?> <a href="/admin/composicion/<?= (int) $inheritedFrom['id'] ?>"><?= te('Verla') ?></a>. <?= te('Si añades una pared aquí, este nodo tendrá la suya propia.') ?></p><?php endif; ?>
<?php foreach ($walls as $w): ?>
<section class="card wall-admin">
  <form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/pared/<?= (int) $w['id'] ?>" class="row-form">
    <?= csrf_field() ?>
    <label><?= te('Pared') ?><input name="name" required value="<?= e($w['name']) ?>"></label>
    <label><?= te('Dirección') ?><select name="direction"><option value="x"<?= $w['direction'] === 'x' ? ' selected' : '' ?>>x</option><option value="y"<?= $w['direction'] === 'y' ? ' selected' : '' ?>>y</option></select></label>
    <label><?= te('Origen X (mm)') ?><input type="number" name="origin_x_mm" value="<?= (int) $w['origin_x_mm'] ?>"></label>
    <label><?= te('Origen Y (mm)') ?><input type="number" name="origin_y_mm" value="<?= (int) $w['origin_y_mm'] ?>"></label>
    <button class="btn btn-ghost" type="submit"><?= te('Guardar pared') ?></button>
  </form>
  <table class="table">
    <thead><tr><th><?= te('Módulo') ?></th><th><?= te('Ancho (cm)') ?></th><th class="r"><?= te('Precio') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($w['modules'] as $i => $mod): $pl = $w['price']['walls'][0]['items'][$i]; ?>
      <tr class="<?= $pl['valid'] ? '' : 'is-invalid' ?>">
        <td colspan="2">
          <form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/modulo/<?= (int) $mod['id'] ?>" class="row-form tight">
            <?= csrf_field() ?><input type="hidden" name="wall_id" value="<?= (int) $w['id'] ?>">
            <select name="module_type_id" aria-label="<?= te('Módulo') ?>"><?= $typeOpts((int) $mod['module_type_id']) ?></select>
            <input type="number" name="width_cm" value="<?= (int) $mod['width_cm'] ?>" min="1" class="w-num" aria-label="<?= te('Ancho (cm)') ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= te('Guardar') ?></button>
          </form>
          <?php if (!$pl['valid']): ?><span class="line-error"><?= e($pl['error']) ?></span><?php endif; ?>
        </td>
        <td class="r num"><?= e(money($pl['price'])) ?></td>
        <td class="r nowrap">
          <?php foreach ([[-1, '↑'], [1, '↓']] as [$d, $ico]): ?><form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/modulo/<?= (int) $mod['id'] ?>/mover" class="inline"><?= csrf_field() ?><input type="hidden" name="wall_id" value="<?= (int) $w['id'] ?>"><input type="hidden" name="dir" value="<?= $d ?>"><button class="icon-btn" aria-label="<?= $d < 0 ? te('Subir') : te('Bajar') ?>"><?= $ico ?></button></form><?php endforeach; ?>
          <form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/modulo/<?= (int) $mod['id'] ?>/borrar" class="inline"><?= csrf_field() ?><input type="hidden" name="wall_id" value="<?= (int) $w['id'] ?>"><button class="icon-btn danger" aria-label="<?= te('Quitar') ?>">✕</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2" class="r muted"><?= te('Total de la pared') ?></td><td class="r num"><strong><?= e(money($w['price']['total'])) ?></strong></td><td></td></tr></tfoot>
  </table>
  <form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/modulo" class="row-form tight">
    <?= csrf_field() ?><input type="hidden" name="wall_id" value="<?= (int) $w['id'] ?>">
    <select name="module_type_id" aria-label="<?= te('Módulo') ?>"><?= $typeOpts(null) ?></select>
    <input type="number" name="width_cm" min="1" class="w-num" placeholder="cm" required aria-label="<?= te('Ancho (cm)') ?>">
    <button class="btn btn-primary btn-sm" type="submit">+ <?= te('Añadir módulo') ?></button>
  </form>
  <form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/pared/<?= (int) $w['id'] ?>/borrar" class="inline right" data-confirm="<?= te('¿Quitar la pared «{n}» y sus módulos?', ['n' => $w['name']]) ?>"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit"><?= te('Quitar pared') ?></button></form>
</section>
<?php endforeach; ?>
<form method="post" action="/admin/composicion/<?= (int) $node['id'] ?>/pared" class="card row-form">
  <?= csrf_field() ?>
  <label><?= te('Nueva pared') ?><input name="name" required placeholder="<?= te('p. ej. Frente') ?>"></label>
  <label><?= te('Dirección') ?><select name="direction"><option>x</option><option>y</option></select></label>
  <label><?= te('Origen X (mm)') ?><input type="number" name="origin_x_mm" value="0"></label>
  <label><?= te('Origen Y (mm)') ?><input type="number" name="origin_y_mm" value="0"></label>
  <button class="btn btn-primary" type="submit">+ <?= te('Añadir pared') ?></button>
</form>
