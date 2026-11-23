<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var ?array $parent @var array $path @var array $nodes @var ?string $back @var bool $client */
$finals = $nodes && !array_filter($nodes, fn($n) => !$n['is_final']);
$title = !$parent ? t('¿Qué quieres configurar?') : ($finals ? t('Elige el acabado') : t('Elige el modelo de {n}', ['n' => mb_strtolower($parent['name'])]));
?>
<?= steps_bar(1) ?>
<div class="step-head">
  <?php if ($path): ?>
  <nav class="crumbs" aria-label="<?= te('Lo que has elegido') ?>">
    <?php foreach ($path as $i => $p): ?><?= $i ? '<span aria-hidden="true">›</span>' : '' ?><span><?= e($p['name']) ?></span><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <h1><?= e($title) ?></h1>
</div>
<?php if (!$nodes): ?>
  <div class="empty"><p><?= te('Todavía no hay nada en el catálogo.') ?></p><?php if (is_admin(current_user())): ?><a class="btn btn-primary" href="/admin/ajustes"><?= te('Cargar datos de ejemplo') ?></a><?php endif; ?></div>
<?php else: ?>
<ul class="choices<?= $finals ? ' is-finishes' : '' ?>" role="list">
  <?php foreach ($nodes as $n): ?>
    <li>
    <?php if ($n['is_final']): ?>
      <form method="post" action="/configurar"><?= csrf_field() ?><input type="hidden" name="node_id" value="<?= (int) $n['id'] ?>">
        <button type="submit" class="choice">
          <span class="choice-pic"><?= node_visual($n['image']) ?: '<span class="choice-blank"></span>' ?></span>
          <span class="choice-name"><?= e($n['name']) ?></span>
          <?php if ($n['description'] !== ''): ?><span class="choice-desc"><?= e($n['description']) ?></span><?php endif; ?>
        </button>
      </form>
    <?php else: ?>
      <a class="choice" href="/configurar?nodo=<?= (int) $n['id'] ?>">
        <span class="choice-pic"><?= node_visual($n['image']) ?: '<span class="choice-blank"></span>' ?></span>
        <span class="choice-name"><?= e($n['name']) ?></span>
        <?php if ($n['description'] !== ''): ?><span class="choice-desc"><?= e($n['description']) ?></span><?php endif; ?>
      </a>
    <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($back !== null): ?><p class="back-row"><a class="btn btn-ghost" href="<?= e($back) ?>">← <?= te('Atrás') ?></a></p><?php endif; ?>
