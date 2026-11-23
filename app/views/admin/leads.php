<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $leads @var array $filters */
$f = fn(string $k) => (string) ($filters[$k] ?? '');
$qs = http_build_query(array_filter($filters, fn($v) => $v !== ''));
?>
<div class="page-head"><h1><?= te('Leads') ?></h1>
  <p class="muted small"><?= te('Lo que deja el cliente con «Quiero este presupuesto». {n} sin trabajar.', ['n' => $leads['pending']]) ?></p></div>
<form method="get" action="/admin/leads" class="card row-form filters">
  <label><?= te('Buscar') ?><input name="q" value="<?= e($f('q')) ?>" placeholder="<?= te('nombre, teléfono o correo') ?>"></label>
  <label><?= te('Estado') ?><select name="estado"><option value=""><?= te('Todos') ?></option><?php foreach (LEAD_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $f('estado') === $k ? ' selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select></label>
  <label><?= te('Trabajo') ?><select name="work_status"><option value=""><?= te('Todos') ?></option><?php foreach (WORK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $f('work_status') === $k ? ' selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select></label>
  <button class="btn btn-primary" type="submit"><?= te('Filtrar') ?></button>
  <?php if ($qs): ?><a class="btn btn-ghost" href="/admin/leads"><?= te('Quitar filtros') ?></a><?php endif; ?>
</form>
<?php if (!$leads['items']): ?>
  <div class="empty"><p><?= te('Todavía no hay leads. Aparecen cuando un cliente pide el presupuesto desde el configurador.') ?></p></div>
<?php else: ?>
<div class="lead-list">
<?php foreach ($leads['items'] as $l): ?>
  <article class="card lead-card work-<?= e($l['work_status']) ?>">
    <div class="lead-top">
      <div>
        <strong class="lead-name"><?= e($l['name']) ?></strong>
        <span class="muted small"><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></span>
        <p class="lead-contact"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $l['phone'])) ?>"><?= e($l['phone']) ?></a><?php if ($l['email'] !== ''): ?> · <a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a><?php endif; ?></p>
      </div>
      <div class="lead-cfg">
        <?php if ($l['configuration_code']): ?><a class="mono" href="/admin/configuraciones/<?= e($l['configuration_code']) ?>"><?= e($l['configuration_code']) ?></a><br><strong class="num"><?= e((string) $l['configuration_total_text']) ?></strong><?php endif; ?>
      </div>
    </div>
    <?php if ($l['message'] !== ''): ?><p class="lead-msg">«<?= e($l['message']) ?>»</p><?php endif; ?>
    <form method="post" action="/admin/leads/<?= (int) $l['id'] ?>" class="row-form tight lead-form-row">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= $qs ? '?' . e($qs) : '' ?>">
      <label><?= te('Estado') ?><select name="estado"><?php foreach (LEAD_STATUSES as $k => $lb): ?><option value="<?= e($k) ?>"<?= $l['estado'] === $k ? ' selected' : '' ?>><?= te($lb) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Trabajo') ?><select name="work_status"><?php foreach (WORK_STATUSES as $k => $lb): ?><option value="<?= e($k) ?>"<?= $l['work_status'] === $k ? ' selected' : '' ?>><?= te($lb) ?></option><?php endforeach; ?></select></label>
      <label class="grow"><?= te('Nota (una línea)') ?><input name="work_note" maxlength="200" value="<?= e($l['work_note']) ?>"></label>
      <button class="btn btn-ghost" type="submit"><?= te('Guardar') ?></button>
    </form>
    <?php if ($l['work_by'] !== ''): ?><p class="small muted"><?= te('Último cambio: {q}, {f}', ['q' => $l['work_by'], 'f' => date('d/m/Y H:i', strtotime((string) $l['work_at']))]) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php if ($leads['pages'] > 1): ?><p class="pager"><?php for ($p = 1; $p <= $leads['pages']; $p++): ?><a href="?<?= e(http_build_query(array_filter($filters, fn($v) => $v !== '') + ['page' => $p])) ?>"<?= $p === $leads['page'] ? ' aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?></p><?php endif; ?>
<?php endif; ?>
