<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $list @var array $filters @var array $users */
$f = fn(string $k) => e((string) ($filters[$k] ?? ''));
$qs = http_build_query(array_filter($filters, fn($v) => $v !== ''));
?>
<div class="page-head"><h1><?= te('Configuraciones') ?></h1><a class="btn btn-ghost" href="/admin/configuraciones.csv<?= $qs ? '?' . e($qs) : '' ?>"><?= te('Descargar CSV') ?></a></div>
<form method="get" action="/admin/configuraciones" class="card row-form filters">
  <label><?= te('Buscar') ?><input name="q" value="<?= $f('q') ?>" placeholder="<?= te('código, cliente, correo, teléfono') ?>"></label>
  <label><?= te('Estado') ?><select name="status"><option value=""><?= te('Todos') ?></option><?php foreach (CONFIG_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($filters['status'] ?? '') === $k ? ' selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select></label>
  <label><?= te('Desde') ?><input type="date" name="from" value="<?= $f('from') ?>"></label>
  <label><?= te('Hasta') ?><input type="date" name="to" value="<?= $f('to') ?>"></label>
  <label><?= te('Hecha por') ?><select name="user_id"><option value=""><?= te('Cualquiera') ?></option><?php foreach ($users as $us): ?><option value="<?= (int) $us['id'] ?>"<?= (string) ($filters['user_id'] ?? '') === (string) $us['id'] ? ' selected' : '' ?>><?= e($us['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn btn-primary" type="submit"><?= te('Filtrar') ?></button>
  <?php if ($qs): ?><a class="btn btn-ghost" href="/admin/configuraciones"><?= te('Quitar filtros') ?></a><?php endif; ?>
</form>
<p class="small muted"><?= te('{n} configuraciones', ['n' => $list['total']]) ?></p>
<?php include __DIR__ . '/_config_table.php'; ?>
<?php if ($list['pages'] > 1): ?><p class="pager"><?php for ($p = 1; $p <= $list['pages']; $p++): ?><a href="?<?= e(http_build_query(array_filter($filters, fn($v) => $v !== '') + ['page' => $p])) ?>"<?= $p === $list['page'] ? ' aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?></p><?php endif; ?>
