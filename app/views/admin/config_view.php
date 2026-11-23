<?php /** @var array $cfg */ ?>
<nav class="crumbs"><a href="/admin/configuraciones"><?= te('Configuraciones') ?></a><span>›</span><span class="mono"><?= e($cfg['code']) ?></span></nav>
<div class="page-head">
  <h1><span class="mono"><?= e($cfg['code']) ?></span> <span class="badge badge-<?= e($cfg['status']) ?>"><?= e($cfg['status_label']) ?></span></h1>
  <div class="btn-row">
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>.json"><?= te('JSON') ?></a>
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>.csv"><?= te('CSV') ?></a>
    <?php if ($cfg['status'] === 'draft'): ?><a class="btn btn-ghost" href="/configurar/<?= e($cfg['code']) ?>"><?= te('Editar') ?></a><?php endif; ?>
    <form method="post" action="/configurar/<?= e($cfg['code']) ?>/duplicar" class="inline"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= te('Duplicar') ?></button></form>
    <?php if ($cfg['status'] !== 'cancelled'): ?><form method="post" action="/admin/configuraciones/<?= e($cfg['code']) ?>/anular" class="inline" data-confirm="<?= te('¿Anular {c}?', ['c' => $cfg['code']]) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= te('Anular') ?></button></form><?php endif; ?>
  </div>
</div>
<div class="summary-grid">
  <div class="stack">
    <?php foreach ($cfg['walls'] as $w): ?>
    <section class="card flush">
      <div class="card-head"><strong><?= e($w['name']) ?></strong> <span class="small muted"><?= te('avanza en {d} desde ({x}, {y}) mm', ['d' => $w['direction'], 'x' => $w['origin_x_mm'], 'y' => $w['origin_y_mm']]) ?></span></div>
      <table class="table">
        <thead><tr><th><?= te('Módulo') ?></th><th class="r"><?= te('Ancho') ?></th><th class="r hide-sm"><?= te('Fondo') ?></th><th class="r"><?= te('Tramo') ?></th><th class="r"><?= te('Precio') ?></th></tr></thead>
        <tbody>
        <?php foreach ($w['items'] as $it): ?>
          <tr class="<?= $it['valid'] ? '' : 'is-invalid' ?>"><td><span class="fam"><?= e($it['family']) ?></span> <?= e($it['type_name']) ?> <span class="small muted mono"><?= e($it['ref']) ?></span><?php if (!$it['valid']): ?><span class="line-error"><?= e($it['error']) ?></span><?php endif; ?></td>
            <td class="r num"><?= (int) $it['width_cm'] ?> cm</td><td class="r num hide-sm"><?= (int) $it['depth_mm'] ?> mm</td>
            <td class="r num"><?= $it['valid'] ? (int) $it['tier_from_cm'] . '–' . (int) $it['tier_to_cm'] : '—' ?></td><td class="r num"><?= e(money($it['price_cents'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$w['items']): ?><tr><td colspan="5" class="muted"><?= te('Sin módulos.') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php endforeach; ?>
  </div>
  <aside class="card totals">
    <dl>
      <div><dt><?= te('Producto') ?></dt><dd><?= e($cfg['node']['path_text']) ?></dd></div>
      <div><dt><?= te('Cliente') ?></dt><dd><?= e($cfg['customer']['name'] ?: '—') ?><br><span class="small muted"><?= e(trim($cfg['customer']['email'] . ' · ' . $cfg['customer']['phone'], ' ·')) ?></span></dd></div>
      <div><dt><?= te('Hecha por') ?></dt><dd><?= e($cfg['user']['name'] ?? t('Invitado')) ?></dd></div>
      <div><dt><?= te('Creada') ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($cfg['created_at']))) ?></dd></div>
      <?php if ($cfg['accepted_at']): ?><div><dt><?= te('Aceptada') ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($cfg['accepted_at']))) ?></dd></div><?php endif; ?>
      <div><dt><?= te('Total sin IVA') ?></dt><dd class="num big"><?= e(money($cfg['total_cents'])) ?></dd></div>
      <div><dt><?= te('IVA ({p} %)', ['p' => $cfg['tax_rate']]) ?></dt><dd class="num"><?= e(money($cfg['tax_cents'])) ?></dd></div>
      <div><dt><?= te('Total con IVA') ?></dt><dd class="num"><?= e(money($cfg['total_with_tax_cents'])) ?></dd></div>
    </dl>
    <?php if ($cfg['frozen']): ?><p class="fine"><?= te('Precios congelados al aceptar.') ?></p><?php endif; ?>
    <?php if ($cfg['notes'] !== ''): ?><p><strong><?= te('Notas') ?></strong><br><?= nl2br(e($cfg['notes'])) ?></p><?php endif; ?>
  </aside>
</div>
