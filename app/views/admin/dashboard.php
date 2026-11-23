<?php /** @var array $counts @var array $latest */ ?>
<div class="page-head"><h1><?= te('Panel') ?></h1><a class="btn btn-primary" href="/configurar"><?= te('Nueva configuración') ?></a></div>
<div class="stats">
  <a class="stat" href="/admin/configuraciones?status=draft"><span class="stat-n"><?= (int) $counts['drafts'] ?></span><span class="stat-l"><?= te('Borradores') ?></span></a>
  <a class="stat" href="/admin/configuraciones?status=accepted"><span class="stat-n"><?= (int) $counts['accepted'] ?></span><span class="stat-l"><?= te('Aceptadas') ?></span></a>
  <a class="stat" href="/admin/catalogo"><span class="stat-n"><?= (int) $counts['nodes'] ?></span><span class="stat-l"><?= te('Nodos del catálogo') ?></span></a>
  <a class="stat" href="/admin/tipos"><span class="stat-n"><?= (int) $counts['types'] ?></span><span class="stat-l"><?= te('Tipos de módulo') ?></span></a>
  <a class="stat" href="/admin/usuarios"><span class="stat-n"><?= (int) $counts['users'] ?></span><span class="stat-l"><?= te('Personas activas') ?></span></a>
</div>
<?php if ($counts['nodes'] === 0): ?>
  <div class="notice"><?= te('El catálogo está vacío.') ?> <a href="/admin/ajustes"><?= te('Carga los datos de ejemplo') ?></a> <?= te('o crea el primer nodo en Catálogo.') ?></div>
<?php endif; ?>
<h2><?= te('Últimas configuraciones') ?></h2>
<?php include __DIR__ . '/_config_table.php'; ?>
