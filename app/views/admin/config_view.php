<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $cfg @var array $cut */ ?>
<nav class="crumbs"><a href="/admin/configuraciones"><?= te('Configuraciones') ?></a><span>›</span><span class="mono"><?= e($cfg['code']) ?></span></nav>
<div class="page-head">
  <h1><span class="mono"><?= e($cfg['code']) ?></span> <span class="badge badge-<?= e($cfg['status']) ?>"><?= e($cfg['status_label']) ?></span></h1>
  <div class="btn-row">
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>.json"><?= te('JSON') ?></a>
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>.csv"><?= te('CSV') ?></a>
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>-despiece.csv"><?= te('Despiece CSV') ?></a>
    <a class="btn btn-ghost" href="/admin/configuraciones/<?= e($cfg['code']) ?>-corte.csv"><?= te('Lista de corte CSV') ?></a>
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
        <thead><tr><th><?= te('Módulo') ?></th><th class="r"><?= te('Ancho') ?></th><th class="r hide-sm"><?= te('Alto × fondo') ?></th><th class="r"><?= te('Tramo') ?></th><th class="r"><?= te('Precio') ?></th></tr></thead>
        <tbody>
        <?php foreach ($w['items'] as $it): ?>
          <tr class="<?= $it['valid'] ? '' : 'is-invalid' ?>"><td><span class="fam"><?= e($it['family']) ?></span> <?= e($it['type_name']) ?> <span class="small muted mono"><?= e($it['ref']) ?></span><?php if (!$it['valid']): ?><span class="line-error"><?= e($it['error']) ?></span><?php endif; ?></td>
            <td class="r num"><?= e($it['width_text']) ?> cm</td><td class="r num hide-sm"><?= (int) $it['height_mm'] ?> × <?= (int) $it['depth_mm'] ?> mm</td>
            <td class="r num"><?= e($it['tier_text'] ?? '—') ?></td><td class="r num"><?= e(money($it['price_cents'])) ?></td></tr>
          <?php if ($it['parts']): ?>
          <tr class="parts-row"><td colspan="5"><details><summary><?= te('Despiece ({n} piezas)', ['n' => array_sum(array_column($it['parts'], 'qty'))]) ?></summary>
            <table class="table mini"><thead><tr><th><?= te('Pieza') ?></th><th class="r"><?= te('Cant.') ?></th><th class="r"><?= te('Espesor') ?></th><th class="r"><?= te('Largo') ?></th><th class="r"><?= te('Ancho') ?></th><th class="hide-sm"><?= te('Veta · canto') ?></th></tr></thead><tbody>
            <?php foreach ($it['parts'] as $pp): ?><tr><td><?= e($pp['name']) ?><?php if ($pp['error']): ?><span class="line-error"><?= e($pp['error']) ?></span><?php endif; ?></td><td class="r num"><?= (int) $pp['qty'] ?></td><td class="r num"><?= e(mm_text($pp['thickness_mm'])) ?></td><td class="r num"><?= e(mm_text($pp['length_mm'])) ?></td><td class="r num"><?= e(mm_text($pp['width_mm'])) ?></td><td class="small hide-sm"><?= e(trim($pp['grain'] . ' · ' . $pp['edge_banding'], ' ·')) ?></td></tr><?php endforeach; ?>
            </tbody></table></details></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$w['items']): ?><tr><td colspan="5" class="muted"><?= te('Sin módulos.') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php endforeach; ?>
    <?php if ($cut): ?>
    <section class="card flush">
      <div class="card-head"><strong><?= te('Lista de corte') ?></strong> <span class="small muted"><?= te('{n} piezas · medidas en mm, redondeo a 0,5 mm', ['n' => array_sum(array_column($cut, 'qty'))]) ?></span></div>
      <table class="table">
        <thead><tr><th><?= te('Pieza') ?></th><th class="r"><?= te('Cant.') ?></th><th class="r"><?= te('Espesor') ?></th><th class="r"><?= te('Largo') ?></th><th class="r"><?= te('Ancho') ?></th><th class="hide-sm"><?= te('Origen') ?></th></tr></thead>
        <tbody>
        <?php foreach ($cut as $r): ?>
          <tr><td><?= e($r['name']) ?><div class="small muted"><?= e(trim($r['grain'] . ' · ' . $r['edge_banding'], ' ·')) ?></div></td><td class="r num"><?= (int) $r['qty'] ?></td><td class="r num"><?= e(mm_text($r['thickness_mm'])) ?></td><td class="r num"><?= e(mm_text($r['length_mm'])) ?></td><td class="r num"><?= e(mm_text($r['width_mm'])) ?></td>
            <td class="small hide-sm"><?= e(implode(' · ', array_map(fn($f) => $f['module'] . ' ' . str_replace('.', ',', (string) $f['width_cm']) . ' cm ×' . $f['qty'], $r['from']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>
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
