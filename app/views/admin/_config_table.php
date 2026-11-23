<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php $rowsList = $latest ?? $list['items'] ?? []; ?>
<?php if (!$rowsList): ?><div class="empty"><p><?= te('No hay configuraciones.') ?></p></div><?php else: ?>
<div class="card flush">
<table class="table">
  <thead><tr><th><?= te('Código') ?></th><th class="hide-sm"><?= te('Producto') ?></th><th><?= te('Cliente') ?></th><th class="hide-sm"><?= te('Hecha por') ?></th><th class="hide-sm"><?= te('Estado') ?></th><th class="hide-sm"><?= te('Trabajo') ?></th><th class="r"><?= te('Total sin IVA') ?></th></tr></thead>
  <tbody>
  <?php foreach ($rowsList as $c): ?>
    <tr>
      <td><a class="mono nowrap" href="/admin/configuraciones/<?= e($c['code']) ?>"><?= e($c['code']) ?></a><div class="small muted"><?= e(date('d/m/Y H:i', strtotime($c['created_at']))) ?></div><div class="show-sm"><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status_label']) ?></span></div></td>
      <td class="hide-sm"><?= e($c['node']) ?></td>
      <td><?= e($c['customer_name'] ?: '—') ?><?php if ($c['customer_email']): ?><div class="small muted hide-sm"><?= e($c['customer_email']) ?></div><?php endif; ?></td>
      <td class="hide-sm"><?= e($c['user'] ?? t('Invitado')) ?></td>
      <td class="hide-sm"><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status_label']) ?></span><?php if (!$c['valid'] && $c['status'] === 'draft'): ?> <span class="badge badge-warn"><?= te('medidas sin tramo') ?></span><?php endif; ?></td>
      <td class="hide-sm"><?php if ($c['work_status']): ?><span class="badge work-badge work-<?= e($c['work_status']) ?>"><?= e($c['work_status_label']) ?></span><?php if ($c['work_note'] !== ''): ?><div class="small muted clip" title="<?= e($c['work_note'] . ' · ' . $c['work_by']) ?>"><?= e($c['work_note']) ?></div><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td class="r num"><?= e(money($c['total_cents'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
