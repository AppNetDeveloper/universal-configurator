<?php /** @var array $rows @var int $page @var int $pages */ ?>
<div class="page-head"><h1><?= te('Actividad') ?></h1></div>
<div class="card flush">
  <table class="table">
    <thead><tr><th><?= te('Cuándo') ?></th><th><?= te('Quién') ?></th><th><?= te('Qué') ?></th><th class="hide-sm"><?= te('Detalle') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="nowrap small"><?= e(date('d/m/Y H:i:s', strtotime($r['at']))) ?></td><td class="small"><?= e($r['actor']) ?></td><td><code><?= e($r['action']) ?></code></td><td class="small hide-sm"><?= e($r['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($pages > 1): ?><p class="pager"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">← <?= te('Más recientes') ?></a><?php endif; ?> <span><?= $page ?> / <?= $pages ?></span> <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>"><?= te('Más antiguos') ?> →</a><?php endif; ?></p><?php endif; ?>
