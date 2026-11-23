<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $cfg @var bool $isGuest @var bool $client @var ?array $share @var ?array $requested */
$draft = $cfg['status'] === 'draft';
$code = $cfg['code'];
$path = implode(' · ', array_column($cfg['node']['path'], 'name'));
$tax = rtrim(rtrim(number_format($cfg['tax_rate'], 2, ',', ''), '0'), ',');
?>
<?= steps_bar(3, $code) ?>
<?php if ($client): ?>
  <?php if ($cfg['status'] === 'requested'): ?>
  <section class="done-hero is-ok" aria-live="polite">
    <span class="done-mark" aria-hidden="true">✓</span>
    <p class="eyebrow"><?= e($path) ?></p>
    <h1><?= te('¡Recibido! Te llamaremos pronto') ?></h1>
    <p><?= te('Tu configuración queda guardada a tu nombre con este precio:') ?> <strong class="num"><?= e(money($cfg['total_cents'])) ?></strong> <?= te('+ IVA') ?>.</p>
    <?php if (!empty($requested['mailed'])): ?><p class="muted"><?= te('Te hemos enviado a tu correo el enlace para verla cuando quieras.') ?></p><?php endif; ?>
  </section>
  <?php elseif ($draft): ?>
  <section class="done-hero">
    <p class="eyebrow"><?= e($path) ?></p>
    <h1><?= te('Tu presupuesto') ?></h1>
    <p class="price-big"><strong class="num"><?= e(money($cfg['total_cents'])) ?></strong> <span><?= te('+ IVA') ?></span></p>
    <p class="muted num"><?= te('Con IVA ({p} %): {t}', ['p' => $tax, 't' => money($cfg['total_with_tax_cents'])]) ?></p>
  </section>
  <?php else: ?>
  <section class="done-hero">
    <p class="eyebrow"><?= e($path) ?></p>
    <h1><?= e(t(CONFIG_STATUSES_CLIENT[$cfg['status']])) ?></h1>
    <p class="price-big"><strong class="num"><?= e(money($cfg['total_cents'])) ?></strong> <span><?= te('+ IVA') ?></span></p>
  </section>
  <?php endif; ?>

  <div class="client-grid">
    <div class="client-main">
    <?php if ($draft && !$cfg['valid']): ?>
      <p class="flash flash-error"><?= $cfg['count'] === 0 ? te('No hay ningún módulo: añade al menos uno.') : te('Hay medidas que no valen: corrígelas antes de pedir el presupuesto.') ?></p>
      <a class="btn btn-primary" href="/configurar/<?= e($code) ?>">← <?= te('Volver a los módulos') ?></a>
    <?php elseif ($draft): ?>
      <form method="post" action="/configurar/<?= e($code) ?>/pedir" class="card lead-form stack" id="pedir">
        <?= csrf_field() ?>
        <h2><?= te('Quiero este presupuesto') ?></h2>
        <p class="muted"><?= te('Déjanos tu nombre y tu teléfono: te llamamos para confirmarlo y te guardamos la configuración con este precio.') ?></p>
        <label><?= te('Nombre') ?><input name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name', $cfg['customer']['name'])) ?>"></label>
        <label><?= te('Teléfono') ?><input type="tel" name="phone" required maxlength="40" autocomplete="tel" inputmode="tel" value="<?= e(old('phone', $cfg['customer']['phone'])) ?>"></label>
        <label><?= te('Correo (opcional: te enviamos el enlace para verla)') ?><input type="email" name="email" maxlength="160" autocomplete="email" value="<?= e(old('email', $cfg['customer']['email'])) ?>"></label>
        <label><?= te('¿Algo que debamos saber? (opcional)') ?><textarea name="message" rows="2" maxlength="500"><?= e(old('message')) ?></textarea></label>
        <label class="hp" aria-hidden="true"><?= te('No rellenes este campo') ?><input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="consent" value="1" required> <span><?= te('Acepto que {e} guarde estos datos para llamarme y preparar este presupuesto (protección de datos).', ['e' => setting('empresa')]) ?></span></label>
        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= te('Quiero este presupuesto') ?> · <span class="num"><?= e(money($cfg['total_cents'])) ?></span></button>
      </form>
    <?php endif; ?>
    <?php if ($cfg['status'] !== 'cancelled' && $cfg['valid']): ?>
      <div class="share-box card" id="compartir">
        <?php if ($share): ?>
          <h2><?= te('Tu enlace') ?></h2>
          <p class="muted small"><?= te('Para verla cuando quieras (sin tus datos). Vale {d} días.', ['d' => $share['days']]) ?></p>
          <div class="copy-row"><input id="share-url" class="copy-field" readonly value="<?= e($share['url']) ?>" aria-label="<?= te('Enlace') ?>"><button type="button" class="btn btn-ghost" data-copy-from="share-url" data-copied="<?= te('Copiado') ?>"><?= te('Copiar') ?></button></div>
          <a class="btn btn-wa btn-block" href="<?= e($share['whatsapp_url']) ?>" target="_blank" rel="noopener"><?= te('Abrir WhatsApp') ?></a>
        <?php else: ?>
          <form method="post" action="/configurar/<?= e($code) ?>/compartir" data-share-wa><?= csrf_field() ?>
            <button class="btn btn-wa btn-block" type="submit"><?= te('Envíamela por WhatsApp') ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($draft): ?><a class="btn btn-ghost btn-block" href="/configurar/<?= e($code) ?>">← <?= te('Seguir cambiando') ?></a><?php endif; ?>
    <?php if (!$draft): ?><a class="btn btn-ghost btn-block" href="/configurar"><?= te('Configurar otra') ?></a><?php endif; ?>
    </div>
    <?php require __DIR__ . '/_sum.php'; ?>
  </div>
  <?php if ($draft && $cfg['valid']): ?>
  <div class="totalbar only-sm" role="region" aria-label="<?= te('Total') ?>"><div class="wrap totalbar-in">
    <span class="total-box"><span class="total-label"><?= te('Total sin IVA') ?></span><strong class="total-live"><?= e(money($cfg['total_cents'])) ?></strong></span>
    <a class="btn btn-primary" href="#pedir"><?= te('Lo quiero') ?></a>
  </div></div>
  <?php endif; ?>

<?php else: /* el equipo: comercial o administrador */ ?>
<div class="step-head row-between">
  <div>
    <p class="eyebrow"><?= e($path) ?> · <span class="mono"><?= e($code) ?></span></p>
    <h1><?= $draft ? te('Resumen y precio') : te('Configuración: {estado}', ['estado' => mb_strtolower($cfg['status_label'])]) ?></h1>
  </div>
  <span class="badge badge-<?= e($cfg['status']) ?>"><?= e($cfg['status_label']) ?></span>
</div>
<?php if ($cfg['status'] === 'accepted' && $cfg['accepted_at']): ?>
  <p class="notice"><?= te('Aceptada el {f}. El precio quedó guardado y ya no cambia aunque cambien las tarifas. Para modificarla, duplícala.', ['f' => date('d/m/Y H:i', strtotime($cfg['accepted_at']))]) ?></p>
<?php elseif ($cfg['status'] === 'requested'): ?>
  <p class="notice"><?= te('El cliente pidió este presupuesto el {f}. El precio que vio quedó guardado: al aceptarla se mantiene.', ['f' => date('d/m/Y H:i', strtotime((string) $cfg['requested_at']))]) ?></p>
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
              <td><?= e($it['type_name']) ?><?php if ($it['ref'] !== ''): ?> <span class="muted small"><?= e($it['ref']) ?></span><?php endif; ?><?php if (!$it['valid']): ?><span class="line-error"><?= e($it['error']) ?></span><?php endif; ?></td>
              <td class="r num"><?= e($it['width_text']) ?> cm</td>
              <td class="r num hide-sm"><?= e($it['tier_text'] ?? '—') ?></td>
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
      <div><dt><?= te('IVA ({p} %)', ['p' => $tax]) ?></dt><dd class="num"><?= e(money($cfg['tax_cents'])) ?></dd></div>
      <div class="muted"><dt><?= te('Total con IVA') ?></dt><dd class="num"><?= e(money($cfg['total_with_tax_cents'])) ?></dd></div>
    </dl>
    <?php if ($draft): ?>
      <?php if (!$cfg['valid']): ?>
        <p class="flash flash-error"><?= $cfg['count'] === 0 ? te('No hay ningún módulo: añade al menos uno antes de aceptar.') : te('Hay medidas sin tramo: corrígelas antes de aceptar.') ?></p>
        <a class="btn btn-ghost btn-block" href="/configurar/<?= e($code) ?>">← <?= te('Volver a los módulos') ?></a>
      <?php else: ?>
      <form method="post" action="/configurar/<?= e($code) ?>/aceptar" class="stack">
        <?= csrf_field() ?>
        <label><?= te('Cliente') ?><input name="name" maxlength="160" autocomplete="name" value="<?= e(old('name', $cfg['customer']['name'])) ?>"></label>
        <label><?= te('Correo') ?><input type="email" name="email" maxlength="160" autocomplete="email" value="<?= e(old('email', $cfg['customer']['email'])) ?>"></label>
        <label><?= te('Teléfono') ?><input type="tel" name="phone" maxlength="40" autocomplete="tel" value="<?= e(old('phone', $cfg['customer']['phone'])) ?>"></label>
        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= te('Aceptar configuración') ?></button>
      </form>
      <a class="btn btn-ghost btn-block" href="/configurar/<?= e($code) ?>">← <?= te('Seguir cambiando') ?></a>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($cfg['customer']['name'] !== ''): ?><p><strong><?= e($cfg['customer']['name']) ?></strong><br><span class="muted"><?= e(trim($cfg['customer']['email'] . ' · ' . $cfg['customer']['phone'], ' ·')) ?></span></p><?php endif; ?>
      <?php if ($cfg['status'] === 'requested'): ?>
        <form method="post" action="/configurar/<?= e($code) ?>/aceptar"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit"><?= te('Aceptar (cerrar la venta)') ?></button></form>
      <?php endif; ?>
      <form method="post" action="/configurar/<?= e($code) ?>/duplicar"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit"><?= te('Duplicar para cambiarla') ?></button></form>
    <?php endif; ?>
    <?php if ($cfg['status'] !== 'cancelled' && $cfg['valid']): ?>
      <div class="share-box" id="compartir">
      <?php if ($share): ?>
        <div class="copy-row"><input id="share-url" class="copy-field" readonly value="<?= e($share['url']) ?>" aria-label="<?= te('Enlace para el cliente') ?>"><button type="button" class="btn btn-ghost" data-copy-from="share-url" data-copied="<?= te('Copiado') ?>"><?= te('Copiar') ?></button></div>
        <a class="btn btn-wa btn-block" href="<?= e($share['whatsapp_url']) ?>" target="_blank" rel="noopener"><?= te('Enviar por WhatsApp') ?></a>
      <?php else: ?>
        <form method="post" action="/configurar/<?= e($code) ?>/compartir" data-share-wa><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit"><?= te('Enlace para el cliente (WhatsApp)') ?></button></form>
      <?php endif; ?>
      </div>
    <?php endif; ?>
  </aside>
</div>
<?php endif; ?>
