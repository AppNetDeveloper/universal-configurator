<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $type @var array $tiers @var array $nodes */
$nodeLabel = array_column($nodes, 'label', 'id');
$nodeOpts = function (?int $sel) use ($nodes) {
    $h = '<option value="">' . te('General (todos los nodos)') . '</option>';
    foreach ($nodes as $n) $h .= '<option value="' . $n['id'] . '"' . ($sel === $n['id'] ? ' selected' : '') . '>' . e($n['label']) . '</option>';
    return $h;
};
$groups = [];
foreach ($tiers as $tr) $groups[$tr['node_id'] === null ? 0 : (int) $tr['node_id']][] = $tr;
?>
<nav class="crumbs"><a href="/admin/tipos"><?= te('Tipos de módulo') ?></a><span>›</span><span><?= e($type['name']) ?></span></nav>
<div class="page-head"><h1><?= e($type['name']) ?></h1>
  <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/activar" class="inline"><?= csrf_field() ?><input type="hidden" name="active" value="<?= $type['active'] ? '' : '1' ?>">
  <button class="btn btn-ghost" type="submit"><?= $type['active'] ? te('Desactivar') : te('Activar') ?></button></form></div>
<div class="two-col">
  <div class="stack">
    <section class="card">
      <h2><?= te('Tramos de precio') ?></h2>
      <p class="hint"><?= te('Desde y hasta incluidos, en cm enteros. Sin solapes. Los tramos de un nodo sustituyen a los generales en ese nodo y sus hijos.') ?></p>
      <?php if (!$groups): ?><p class="flash flash-error"><?= te('Sin tramos: este tipo no se puede usar todavía.') ?></p><?php endif; ?>
      <?php foreach ($groups as $nid => $list): ?>
        <h3><?= $nid === 0 ? te('Generales') : te('Solo en {n}', ['n' => $nodeLabel[$nid] ?? '#' . $nid]) ?></h3>
        <table class="table">
          <thead><tr><th><?= te('Desde (cm)') ?></th><th><?= te('Hasta (cm)') ?></th><th><?= te('Precio sin IVA (€)') ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $tr): $fid = 'tr' . (int) $tr['id']; ?>
            <tr>
              <td><input form="<?= $fid ?>" type="number" name="from_cm" min="1" value="<?= (int) $tr['from_cm'] ?>" class="w-num" aria-label="<?= te('Desde (cm)') ?>"></td>
              <td><input form="<?= $fid ?>" type="number" name="to_cm" min="1" value="<?= (int) $tr['to_cm'] ?>" class="w-num" aria-label="<?= te('Hasta (cm)') ?>"></td>
              <td><input form="<?= $fid ?>" name="price" inputmode="decimal" value="<?= e(number_format($tr['price'] / 100, 2, ',', '')) ?>" class="w-money" aria-label="<?= te('Precio') ?>"></td>
              <td class="r nowrap">
                <form id="<?= $fid ?>" method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo/<?= (int) $tr['id'] ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="node_id" value="<?= $tr['node_id'] === null ? '' : (int) $tr['node_id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= te('Guardar') ?></button></form>
                <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo/<?= (int) $tr['id'] ?>/borrar" class="inline"><?= csrf_field() ?><button class="icon-btn danger" aria-label="<?= te('Borrar tramo') ?>">✕</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
      <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo" class="row-form tight">
        <?= csrf_field() ?>
        <label><?= te('Desde (cm)') ?><input type="number" name="from_cm" min="1" required class="w-num" value="<?= e(old('from_cm')) ?>"></label>
        <label><?= te('Hasta (cm)') ?><input type="number" name="to_cm" min="1" required class="w-num" value="<?= e(old('to_cm')) ?>"></label>
        <label><?= te('Precio (€)') ?><input name="price" required inputmode="decimal" class="w-money" value="<?= e(old('price')) ?>"></label>
        <label><?= te('Para') ?><select name="node_id"><?= $nodeOpts(old('node_id') !== '' ? (int) old('node_id') : null) ?></select></label>
        <button class="btn btn-primary" type="submit">+ <?= te('Añadir tramo') ?></button>
      </form>
    </section>
  </div>
  <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Datos') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" value="<?= e(old('name', $type['name'])) ?>"></label>
    <label><?= te('Referencia') ?><input name="ref" maxlength="60" value="<?= e(old('ref', $type['ref'])) ?>"></label>
    <label><?= te('Familia') ?><input name="family" maxlength="60" value="<?= e(old('family', $type['family'])) ?>"></label>
    <label><?= te('Fondo (mm)') ?><input type="number" name="depth_mm" min="1" value="<?= e(old('depth_mm', (string) $type['depth_mm'])) ?>"></label>
    <label><?= te('Orden') ?><input type="number" name="sort" min="0" value="<?= e(old('sort', (string) $type['sort'])) ?>"></label>
    <label><?= te('Descripción') ?><textarea name="description" rows="3"><?= e(old('description', $type['description'])) ?></textarea></label>
    <button class="btn btn-primary" type="submit"><?= te('Guardar') ?></button>
  </form>
</div>
