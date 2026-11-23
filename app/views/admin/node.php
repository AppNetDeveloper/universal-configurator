<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $node @var array $path @var array $children @var ?int $layoutFrom @var int $configs */ ?>
<nav class="crumbs"><a href="/admin/catalogo"><?= te('Catálogo') ?></a><?php foreach ($path as $p): ?><span>›</span><a href="/admin/catalogo/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?php endforeach; ?></nav>
<div class="page-head"><h1><?= node_visual($node['image']) ?> <?= e($node['name']) ?></h1>
  <a class="btn btn-ghost" href="/admin/composicion/<?= (int) $node['id'] ?>"><?= te('Composición por defecto') ?></a></div>
<div class="two-col">
  <form method="post" action="/admin/catalogo/<?= (int) $node['id'] ?>" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Datos') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" value="<?= e(old('name', $node['name'])) ?>"></label>
    <label><?= te('Identificador (slug)') ?><input name="slug" value="<?= e(old('slug', $node['slug'])) ?>"></label>
    <label><?= te('Descripción') ?><textarea name="description" rows="2"><?= e(old('description', $node['description'])) ?></textarea></label>
    <label><?= te('Imagen o color') ?><input name="image" placeholder="https://… o #C8A165" value="<?= e(old('image', (string) $node['image'])) ?>"></label>
    <button class="btn btn-primary" type="submit"><?= te('Guardar') ?></button>
  </form>
  <div class="stack">
    <div class="card stack">
      <h2><?= te('Estado') ?></h2>
      <p class="muted"><?= $node['active'] ? te('Activo: aparece en el configurador.') : te('Desactivado: no aparece (ni sus hijos).') ?>
        <?= $layoutFrom ? ($layoutFrom === (int) $node['id'] ? te('Tiene composición propia.') : te('Hereda la composición de «{n}».', ['n' => node_get($layoutFrom)['name']])) : te('Sin composición por defecto.') ?>
        <?= $configs ? te('{n} configuraciones lo usan.', ['n' => $configs]) : '' ?></p>
      <form method="post" action="/admin/catalogo/<?= (int) $node['id'] ?>/activar" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="back" value="/catalogo/<?= (int) $node['id'] ?>"><input type="hidden" name="active" value="<?= $node['active'] ? '' : '1' ?>">
        <button class="btn btn-ghost" type="submit"><?= $node['active'] ? te('Desactivar') : te('Activar') ?></button></form>
      <?php if (!$children && !$configs): ?>
      <form method="post" action="/admin/catalogo/<?= (int) $node['id'] ?>/borrar" class="inline" data-confirm="<?= te('¿Borrar «{n}»?', ['n' => $node['name']]) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= te('Borrar') ?></button></form>
      <?php endif; ?>
    </div>
    <div class="card stack">
      <h2><?= te('Hijos') ?></h2>
      <?php if ($children): ?><ul class="plain"><?php foreach ($children as $ch): ?>
        <li class="tree-row<?= $ch['active'] ? '' : ' is-off' ?>"><?= node_visual($ch['image']) ?><a class="tree-name" href="/admin/catalogo/<?= (int) $ch['id'] ?>"><?= e($ch['name']) ?></a>
          <span class="tree-actions"><?php foreach ([[-1, '↑'], [1, '↓']] as [$d, $ico]): ?><form method="post" action="/admin/catalogo/<?= (int) $ch['id'] ?>/mover" class="inline"><?= csrf_field() ?><input type="hidden" name="dir" value="<?= $d ?>"><input type="hidden" name="back" value="/catalogo/<?= (int) $node['id'] ?>"><button class="icon-btn" aria-label="<?= $d < 0 ? te('Subir') : te('Bajar') ?>"><?= $ico ?></button></form><?php endforeach; ?></span></li>
      <?php endforeach; ?></ul><?php else: ?><p class="muted small"><?= te('Sin hijos: es un nodo final.') ?></p><?php endif; ?>
      <form method="post" action="/admin/catalogo/crear" class="stack">
        <?= csrf_field() ?><input type="hidden" name="parent_id" value="<?= (int) $node['id'] ?>">
        <label><?= te('Nuevo hijo') ?><input name="name" required maxlength="120" placeholder="<?= te('Nombre') ?>"></label>
        <input name="description" placeholder="<?= te('Descripción (opcional)') ?>">
        <input name="image" placeholder="<?= te('Imagen o color #RRGGBB (opcional)') ?>">
        <button class="btn btn-primary" type="submit"><?= te('Añadir hijo') ?></button>
      </form>
    </div>
  </div>
</div>
