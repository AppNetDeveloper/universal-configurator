<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $cfg @var bool $isGuest */
$draft = $cfg['status'] === 'draft';
?>
<?= steps_bar(3, $cfg['code']) ?>
<div class="step-head row-between">
  <div>
    <p class="eyebrow"><?= e($cfg['node']['path_text']) ?> · <span class="mono"><?= e($cfg['code']) ?></span></p>
    <h1><?= $draft ? te('Resumen y precio') : te('Configuración {estado}', ['estado' => mb_strtolower($cfg['status_label'])]) ?></h1>
  </div>
  <span class="badge badge-<?= e($cfg['status']) ?>"><?= e($cfg['status_label']) ?></span>
</div>
<?php if (!$draft && $cfg['accepted_at']): ?>
  <p class="notice"><?= te('Aceptada el {f}. El precio quedó guardado y ya no cambia aunque cambien las tarifas. Para modificarla, duplícala.', ['f' => date('d/m/Y H:i', strtotime($cfg['accepted_at']))]) ?></p>
<?php endif; ?>
<div class="summary-grid">
  <div class="card flush">
    <table class="table">
      <thead><tr><th><?= te('Módulo') ?></th><th class="r"><?= te('Ancho') ?></th><th class="r hide-sm"><?= te('Tramo') ?></th><th class="r"><?= te('Precio') ?></th></tr></thead>
      <?php foreach ($cfg['walls'] as $w): if (!$w['items']) continue; ?>
        <tbody>
          <tr class="group"><th colspan="4"><?= e($w['name']) ?></th></tr>
          <?php foreach ($w['items'] as $it): ?>
            <tr class="<?= $it['valid'] ? '' : 'is-invalid' ?>">
              <td><span class="fam"><?= e($it['family']) ?></span> <?= e($it['type_name']) ?><?php if (!$it['valid']): ?><span class="line-error"><?= e($it['error']) ?></span><?php endif; ?></td>
              <td class="r num"><?= (int) $it['width_cm'] ?> cm</td>
              <td class="r num hide-sm"><?= $it['valid'] ? (int) $it['tier_from_cm'] . '–' . (int) $it['tier_to_cm'] . ' cm' : '—' ?></td>
              <td class="r num"><?= e(money($it['price_cents'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endforeach; ?>
    </table>
  </div>
  <aside class="card totals">
    <dl>
      <div><dt><?= te('Total sin IVA') ?></dt><dd class="num big"><?= e(money($cfg['total_cents'])) ?></dd></div>
      <div><dt><?= te('IVA ({p} %)', ['p' => rtrim(rtrim(number_format($cfg['tax_rate'], 2, ',', ''), '0'), ',')]) ?></dt><dd class="num"><?= e(money($cfg['tax_cents'])) ?></dd></div>
      <div class="muted"><dt><?= te('Total con IVA') ?></dt><dd class="num"><?= e(money($cfg['total_with_tax_cents'])) ?></dd></div>
    </dl>
    <p class="fine"><?= te('Precios sin IVA. El IVA se muestra aparte a título informativo. Aquí no se paga nada.') ?></p>
    <?php if ($draft): ?>
      <?php if (!$cfg['valid']): ?>
        <p class="flash flash-error"><?= $cfg['count'] === 0 ? te('No hay ningún módulo: añade al menos uno antes de aceptar.') : te('Hay medidas sin tramo: corrígelas antes de aceptar.') ?></p>
        <a class="btn btn-ghost btn-block" href="/configurar/<?= e($cfg['code']) ?>">← <?= te('Volver a los módulos') ?></a>
      <?php else: ?>
      <form method="post" action="/configurar/<?= e($cfg['code']) ?>/aceptar" class="stack">
        <?= csrf_field() ?>
        <label><?= $isGuest ? te('Tu nombre') : te('Cliente') ?><input name="name" maxlength="160" <?= $isGuest ? 'required' : '' ?> autocomplete="name" value="<?= e(old('name', $cfg['customer']['name'])) ?>"></label>
        <label><?= te('Correo') ?><input type="email" name="email" maxlength="160" <?= $isGuest ? 'required' : '' ?> autocomplete="email" value="<?= e(old('email', $cfg['customer']['email'])) ?>"></label>
        <label><?= te('Teléfono') ?><input type="tel" name="phone" maxlength="40" <?= $isGuest ? 'required' : '' ?> autocomplete="tel" value="<?= e(old('phone', $cfg['customer']['phone'])) ?>"></label>
        <?php if ($isGuest): ?>
          <label class="check"><input type="checkbox" name="consent" value="1" required> <span><?= te('Acepto que {e} guarde estos datos para preparar y enviarme este presupuesto (protección de datos).', ['e' => setting('empresa')]) ?></span></label>
        <?php endif; ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= te('Aceptar configuración') ?></button>
      </form>
      <a class="btn btn-ghost btn-block" href="/configurar/<?= e($cfg['code']) ?>">← <?= te('Seguir cambiando') ?></a>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($cfg['customer']['name'] !== ''): ?><p><strong><?= e($cfg['customer']['name']) ?></strong><br><span class="muted"><?= e(trim($cfg['customer']['email'] . ' · ' . $cfg['customer']['phone'], ' ·')) ?></span></p><?php endif; ?>
      <form method="post" action="/configurar/<?= e($cfg['code']) ?>/duplicar"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit"><?= te('Duplicar para cambiarla') ?></button></form>
    <?php endif; ?>
  </aside>
</div>
