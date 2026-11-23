<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $type @var array $tiers @var array $nodes @var array $parts @var array $preview */
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
      <p class="hint"><?= te('Desde y hasta incluidos, en cm con un decimal como mucho (60,1). Sin solapes. Los tramos de un nodo sustituyen a los generales en ese nodo y sus hijos.') ?></p>
      <?php if (!$groups): ?><p class="flash flash-error"><?= te('Sin tramos: este tipo no se puede usar todavía.') ?></p><?php endif; ?>
      <?php foreach ($groups as $nid => $list): ?>
        <h3><?= $nid === 0 ? te('Generales') : te('Solo en {n}', ['n' => $nodeLabel[$nid] ?? '#' . $nid]) ?></h3>
        <table class="table">
          <thead><tr><th><?= te('Desde (cm)') ?></th><th><?= te('Hasta (cm)') ?></th><th><?= te('Precio sin IVA (€)') ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $tr): $fid = 'tr' . (int) $tr['id']; ?>
            <tr>
              <td><input form="<?= $fid ?>" name="from_cm" inputmode="decimal" value="<?= e(cm_text($tr['from_mm'])) ?>" class="w-num" aria-label="<?= te('Desde (cm)') ?>"></td>
              <td><input form="<?= $fid ?>" name="to_cm" inputmode="decimal" value="<?= e(cm_text($tr['to_mm'])) ?>" class="w-num" aria-label="<?= te('Hasta (cm)') ?>"></td>
              <td><input form="<?= $fid ?>" name="price" inputmode="decimal" value="<?= e(number_format($tr['price'] / 100, 2, ',', '')) ?>" class="w-money" aria-label="<?= te('Precio') ?>"></td>
              <td class="r nowrap">
                <form id="<?= $fid ?>" method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo/<?= (int) $tr['id'] ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="node_id" value="<?= $tr['node_id'] === null ? '' : (int) $tr['node_id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= te('Guardar') ?></button></form>
                <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo/<?= (int) $tr['id'] ?>/borrar" class="inline"><?= csrf_field() ?><button class="icon-btn danger" aria-label="<?= te('Borrar tramo') ?>">✕</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php foreach (tier_gaps($list) as $g): ?><p class="notice small"><?= te('Hueco entre {a} y {b} cm: un ancho como {c} cm no tiene precio. Si debe tenerlo, empieza el tramo siguiente en {d}.', ['a' => cm_text($g['from_mm']), 'b' => cm_text($g['to_mm']), 'c' => cm_text($g['from_mm'] + 5), 'd' => cm_text($g['from_mm'] + 1)]) ?></p><?php endforeach; ?>
      <?php endforeach; ?>
      <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/tramo" class="row-form tight">
        <?= csrf_field() ?>
        <label><?= te('Desde (cm)') ?><input name="from_cm" inputmode="decimal" required class="w-num" placeholder="41" value="<?= e(old('from_cm')) ?>"></label>
        <label><?= te('Hasta (cm)') ?><input name="to_cm" inputmode="decimal" required class="w-num" placeholder="60" value="<?= e(old('to_cm')) ?>"></label>
        <label><?= te('Precio (€)') ?><input name="price" required inputmode="decimal" class="w-money" value="<?= e(old('price')) ?>"></label>
        <label><?= te('Para') ?><select name="node_id"><?= $nodeOpts(old('node_id') !== '' ? (int) old('node_id') : null) ?></select></label>
        <button class="btn btn-primary" type="submit">+ <?= te('Añadir tramo') ?></button>
      </form>
    </section>
    <section class="card" id="despiece">
      <h2><?= te('Despiece') ?></h2>
      <p class="hint"><?= te('Fórmulas con W (ancho), H (alto), D (fondo) del módulo en mm, T (tablero) y B (trasera); números, + - * / y paréntesis. Se comprueban al guardar con las medidas por defecto. Las piezas se calculan redondeadas a 0,5 mm.') ?></p>
      <div class="table-scroll">
      <table class="table parts-edit">
        <thead><tr><th><?= te('Pieza') ?></th><th><?= te('Cant.') ?></th><th><?= te('Espesor') ?></th><th><?= te('Largo') ?></th><th><?= te('Ancho') ?></th><th><?= te('Veta') ?></th><th><?= te('Canto') ?></th><th class="r"><?= te('Con las medidas por defecto') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($parts as $i => $pt): $fid = 'pt' . (int) $pt['id']; $pv = $preview[$i] ?? null; ?>
          <tr data-part-row>
            <td><input form="<?= $fid ?>" name="name" value="<?= e($pt['name']) ?>" aria-label="<?= te('Pieza') ?>"></td>
            <td><input form="<?= $fid ?>" type="number" name="qty" min="1" value="<?= (int) $pt['qty'] ?>" class="w-xs" aria-label="<?= te('Cantidad') ?>"></td>
            <td><input form="<?= $fid ?>" name="thickness" value="<?= e($pt['thickness_expr']) ?>" class="w-expr-s mono" data-expr="thickness" aria-label="<?= te('Espesor') ?>"></td>
            <td><input form="<?= $fid ?>" name="length_expr" value="<?= e($pt['length_expr']) ?>" class="w-expr mono" data-expr="length_expr" aria-label="<?= te('Largo') ?>"></td>
            <td><input form="<?= $fid ?>" name="width_expr" value="<?= e($pt['width_expr']) ?>" class="w-expr mono" data-expr="width_expr" aria-label="<?= te('Ancho') ?>"></td>
            <td><select form="<?= $fid ?>" name="grain" aria-label="<?= te('Veta') ?>"><?php foreach (GRAINS as $g => $gl): ?><option value="<?= e($g) ?>"<?= $pt['grain'] === $g ? ' selected' : '' ?>><?= te($gl) ?></option><?php endforeach; ?></select></td>
            <td><input form="<?= $fid ?>" name="edge_banding" value="<?= e($pt['edge_banding']) ?>" class="w-xs2" aria-label="<?= te('Canto') ?>"></td>
            <td class="r num small" data-preview><?php if ($pv && $pv['error'] === null): ?><?= (int) $pv['qty'] ?> × <?= e(mm_text($pv['thickness_mm'])) ?> · <?= e(mm_text($pv['length_mm'])) ?> × <?= e(mm_text($pv['width_mm'])) ?> mm<?php elseif ($pv): ?><span class="line-error"><?= e($pv['error']) ?></span><?php endif; ?></td>
            <td class="r nowrap">
              <form id="<?= $fid ?>" method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/pieza/<?= (int) $pt['id'] ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="sort" value="<?= (int) $pt['sort'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= te('Guardar') ?></button></form>
              <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/pieza/<?= (int) $pt['id'] ?>/borrar" class="inline"><?= csrf_field() ?><button class="icon-btn danger" aria-label="<?= te('Borrar pieza') ?>">✕</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr data-part-row class="new-row">
            <td><input form="ptnew" name="name" required placeholder="<?= te('p. ej. Lateral') ?>" value="<?= e(old('name') !== '' && old('length_expr') !== '' ? old('name') : '') ?>" aria-label="<?= te('Pieza') ?>"></td>
            <td><input form="ptnew" type="number" name="qty" min="1" value="<?= e(old('qty', '1')) ?>" class="w-xs" aria-label="<?= te('Cantidad') ?>"></td>
            <td><input form="ptnew" name="thickness" value="<?= e(old('thickness', 'T')) ?>" class="w-expr-s mono" data-expr="thickness" aria-label="<?= te('Espesor') ?>"></td>
            <td><input form="ptnew" name="length_expr" required placeholder="H" value="<?= e(old('length_expr')) ?>" class="w-expr mono" data-expr="length_expr" aria-label="<?= te('Largo') ?>"></td>
            <td><input form="ptnew" name="width_expr" required placeholder="D" value="<?= e(old('width_expr')) ?>" class="w-expr mono" data-expr="width_expr" aria-label="<?= te('Ancho') ?>"></td>
            <td><select form="ptnew" name="grain" aria-label="<?= te('Veta') ?>"><?php foreach (GRAINS as $g => $gl): ?><option value="<?= e($g) ?>"><?= te($gl) ?></option><?php endforeach; ?></select></td>
            <td><input form="ptnew" name="edge_banding" class="w-xs2" placeholder="1L" aria-label="<?= te('Canto') ?>"></td>
            <td class="r num small" data-preview></td>
            <td class="r"><form id="ptnew" method="post" action="/admin/tipos/<?= (int) $type['id'] ?>/pieza" class="inline"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">+ <?= te('Añadir') ?></button></form></td>
          </tr>
        </tbody>
      </table>
      </div>
      <p class="small muted" data-parts-vars data-url="/admin/tipos/<?= (int) $type['id'] ?>/despiece/probar" data-csrf="<?= e(csrf_token()) ?>"><?= te('Medidas por defecto: W={w}, H={h}, D={d}, T={t}, B={b} mm.', ['w' => $type['width_mm'], 'h' => $type['height_mm'], 'd' => $type['depth_mm'], 't' => mm_text((float) $type['board_mm']), 'b' => mm_text((float) $type['back_mm'])]) ?></p>
    </section>
  </div>
  <form method="post" action="/admin/tipos/<?= (int) $type['id'] ?>" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Datos') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" value="<?= e(old('name', $type['name'])) ?>"></label>
    <label><?= te('Referencia') ?><input name="ref" maxlength="60" value="<?= e(old('ref', $type['ref'])) ?>"></label>
    <label><?= te('Familia') ?><input name="family" maxlength="60" value="<?= e(old('family', $type['family'])) ?>"></label>
    <h3><?= te('Medidas por defecto (mm)') ?></h3>
    <div class="row-form tight">
      <label><?= te('Ancho') ?><input type="number" name="width_mm" min="1" class="w-num" value="<?= e(old('width_mm', (string) $type['width_mm'])) ?>"></label>
      <label><?= te('Alto') ?><input type="number" name="height_mm" min="1" class="w-num" value="<?= e(old('height_mm', (string) $type['height_mm'])) ?>"></label>
      <label><?= te('Fondo') ?><input type="number" name="depth_mm" min="1" class="w-num" value="<?= e(old('depth_mm', (string) $type['depth_mm'])) ?>"></label>
    </div>
    <div class="row-form tight">
      <label><?= te('Ancho mínimo') ?><input type="number" name="min_width_mm" min="1" class="w-num" value="<?= e(old('min_width_mm', (string) $type['min_width_mm'])) ?>"></label>
      <label><?= te('Ancho máximo') ?><input type="number" name="max_width_mm" min="1" class="w-num" value="<?= e(old('max_width_mm', (string) $type['max_width_mm'])) ?>"></label>
    </div>
    <div class="row-form tight">
      <label><?= te('Tablero T') ?><input name="board_mm" inputmode="decimal" class="w-num" value="<?= e(old('board_mm', mm_text((float) $type['board_mm']))) ?>"></label>
      <label><?= te('Trasera B') ?><input name="back_mm" inputmode="decimal" class="w-num" value="<?= e(old('back_mm', mm_text((float) $type['back_mm']))) ?>"></label>
    </div>
    <label><?= te('Orden') ?><input type="number" name="sort" min="0" value="<?= e(old('sort', (string) $type['sort'])) ?>"></label>
    <label><?= te('Descripción') ?><textarea name="description" rows="3"><?= e(old('description', $type['description'])) ?></textarea></label>
    <button class="btn btn-primary" type="submit"><?= te('Guardar') ?></button>
  </form>
</div>
