<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $list */ ?>
<div class="step-head row-between">
  <h1><?= te('Mis configuraciones') ?></h1>
  <a class="btn btn-primary" href="/configurar"><?= te('Nueva configuración') ?></a>
</div>
<?php if (!$list['items']): ?>
  <div class="empty"><p><?= te('Aún no tienes configuraciones.') ?></p></div>
<?php else: ?>
<div class="card flush">
  <table class="table">
    <thead><tr><th><?= te('Código') ?></th><th class="hide-sm"><?= te('Producto') ?></th><th class="hide-sm"><?= te('Cliente') ?></th><th><?= te('Estado') ?></th><th class="r"><?= te('Total sin IVA') ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['items'] as $c): ?>
      <tr>
        <td><a class="mono" href="/configurar/<?= e($c['code']) ?><?= $c['status'] === 'draft' ? '' : '/resumen' ?>"><?= e($c['code']) ?></a><div class="small muted"><?= e(date('d/m/Y H:i', strtotime($c['created_at']))) ?></div></td>
        <td class="hide-sm"><?= e($c['node']) ?></td>
        <td class="hide-sm"><?= e($c['customer_name'] ?: '—') ?></td>
        <td><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status_label']) ?></span></td>
        <td class="r num"><?= e(money($c['total_cents'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($list['pages'] > 1): ?><p class="pager"><?php for ($p = 1; $p <= $list['pages']; $p++): ?><a href="?page=<?= $p ?>"<?= $p === $list['page'] ? ' aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?></p><?php endif; ?>
<?php endif; ?>
