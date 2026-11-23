<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** Resumen compacto para el cliente (paso 3 y enlace compartido). @var array $cfg @var string $tax */ ?>
    <section class="card sum" aria-label="<?= te('Resumen') ?>">
      <h2><?= te('Lo que llevas') ?> <span class="muted small">· <?= (int) $cfg['count'] ?> <?= $cfg['count'] === 1 ? te('módulo') : te('módulos') ?></span></h2>
      <?php foreach ($cfg['walls'] as $w): if (!$w['items']) continue; ?>
        <h3><?= e($w['name']) ?></h3>
        <ul class="sum-list" role="list">
        <?php foreach ($w['items'] as $it): ?>
          <li class="<?= $it['valid'] ? '' : 'is-invalid' ?>"><?= module_icon_svg(module_type_get((int) $it['module_type_id']) ?? []) ?>
            <span class="sum-name"><?= e($it['type_name']) ?><?php if (!$it['valid']): ?><span class="line-error"><?= e($it['error']) ?></span><?php endif; ?></span>
            <span class="num sum-w"><?= e($it['width_text']) ?> cm</span><span class="num sum-p"><?= e(money($it['price_cents'])) ?></span></li>
        <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
      <dl class="sum-total">
        <div><dt><?= te('Total sin IVA') ?></dt><dd class="num"><?= e(money($cfg['total_cents'])) ?></dd></div>
        <div class="muted"><dt><?= te('IVA ({p} %)', ['p' => $tax]) ?></dt><dd class="num"><?= e(money($cfg['tax_cents'])) ?></dd></div>
        <div><dt><?= te('Total con IVA') ?></dt><dd class="num"><?= e(money($cfg['total_with_tax_cents'])) ?></dd></div>
      </dl>
      <p class="fine"><?= te('El IVA se muestra a título informativo. Aquí no se paga nada.') ?></p>
    </section>
