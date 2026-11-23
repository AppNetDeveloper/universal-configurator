<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var ?array $parent @var array $path @var array $nodes */ ?>
<?= steps_bar(1) ?>
<div class="step-head">
  <nav class="crumbs" aria-label="<?= te('Ruta') ?>">
    <a href="/configurar"><?= te('Inicio') ?></a>
    <?php foreach ($path as $p): ?><span aria-hidden="true">›</span><a href="/configurar?nodo=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?php endforeach; ?>
  </nav>
  <h1><?= $parent ? te('Elige en «{n}»', ['n' => $parent['name']]) : te('¿Qué quieres configurar?') ?></h1>
  <p class="hint"><?= te('Toca una opción. Al llegar a la última se carga su composición de módulos por defecto.') ?></p>
</div>
<?php if (!$nodes): ?>
  <div class="empty"><p><?= te('Todavía no hay nada en el catálogo.') ?></p><?php if (is_admin(current_user())): ?><a class="btn btn-primary" href="/admin/ajustes"><?= te('Cargar datos de ejemplo') ?></a><?php endif; ?></div>
<?php else: ?>
<?php $colorsOnly = !array_filter($nodes, fn($n) => !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $n['image'])); ?>
<div class="grid-nodes<?= $colorsOnly ? ' is-colors' : '' ?>">
  <?php foreach ($nodes as $n): ?>
    <?php if ($n['is_final']): ?>
      <form method="post" action="/configurar" class="node-card-form">
        <?= csrf_field() ?><input type="hidden" name="node_id" value="<?= (int) $n['id'] ?>">
        <button type="submit" class="node-card is-final">
          <?= node_visual($n['image']) ?>
          <span class="node-name"><?= e($n['name']) ?></span>
          <?php if ($n['description'] !== ''): ?><span class="node-desc"><?= e($n['description']) ?></span><?php endif; ?>
          <span class="node-go"><?= te('Empezar con este') ?> →</span>
        </button>
      </form>
    <?php else: ?>
      <a class="node-card" href="/configurar?nodo=<?= (int) $n['id'] ?>">
        <?= node_visual($n['image']) ?>
        <span class="node-name"><?= e($n['name']) ?></span>
        <?php if ($n['description'] !== ''): ?><span class="node-desc"><?= e($n['description']) ?></span><?php endif; ?>
        <span class="node-go"><?= te('Ver opciones') ?> →</span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($parent): ?><p class="back-row"><a class="btn btn-ghost" href="/configurar<?= $parent['parent_id'] ? '?nodo=' . (int) $parent['parent_id'] : '' ?>">← <?= te('Atrás') ?></a></p><?php endif; ?>
