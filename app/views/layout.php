<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php
/** @var string $content @var string $title @var ?array $user @var bool $guest @var array $flashes @var string $section @var bool $wide */
$bare = !empty($bare); // portada y enlace compartido: solo la marca arriba
$client = $guest || ($user['role'] ?? '') === 'cliente';
$empresa = setting('empresa');
$logo = setting('logo');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<title><?= e(($title !== '' ? $title . ' · ' : '') . $empresa) ?></title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
<script src="<?= e(asset('/assets/app.js')) ?>" defer></script>
</head>
<body class="<?= trim(($section !== '' ? 'is-admin' : '') . ($client ? ' is-client' : '') . ($bare ? ' is-bare' : '')) ?>">
<header class="topbar">
  <div class="wrap topbar-in">
    <a class="brand" href="/">
      <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" class="brand-logo"><?php else: ?><span class="mark" aria-hidden="true"></span><?php endif; ?>
      <span><?= e($empresa) ?></span>
    </a>
    <?php if ($guest && !$user && !$bare): ?>
    <nav class="mainnav" aria-label="<?= te('Menú') ?>"><a href="/configurar"><?= te('Empezar de nuevo') ?></a></nav>
    <?php elseif ($user && !$bare): ?>
    <nav class="mainnav" aria-label="<?= te('Menú') ?>">
      <a href="/configurar"<?= str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/configurar') ? ' aria-current="page"' : '' ?>><?= te('Configurar') ?></a>
      <a href="/mis-configuraciones"<?= str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/mis-') ? ' aria-current="page"' : '' ?>><?= te('Mis configuraciones') ?></a>
      <?php if (is_admin($user)): ?><a href="/admin"<?= $section !== '' ? ' aria-current="page"' : '' ?>><?= te('Panel') ?></a><?php endif; ?>
      <form method="post" action="/salir" class="inline"><?= csrf_field() ?><button type="submit" class="linkbtn" title="<?= e($user['email'] ?? t('Invitado')) ?>"><?= te('Salir') ?></button></form>
    </nav>
    <?php endif; ?>
  </div>
  <?php if ($section !== '' && is_admin($user)): ?>
  <nav class="subnav" aria-label="<?= te('Panel') ?>"><div class="wrap wide subnav-in">
    <?php foreach (ADMIN_SECTIONS as $href => $label): ?>
      <a href="/admin<?= $href === '/' ? '' : e($href) ?>"<?= $section === $href ? ' aria-current="page"' : '' ?>><?= te($label) ?></a>
    <?php endforeach; ?>
  </div></nav>
  <?php endif; ?>
</header>
<main class="wrap<?= $wide ? ' wide' : '' ?>" id="main">
  <?php if (is_admin($user) && public_url() === null): ?>
    <div class="flash flash-error" role="alert"><?= te('Falta la URL pública de la aplicación: no se envían enlaces por correo (restablecer contraseña, altas) hasta que la pongas.') ?> <a href="/admin/ajustes"><?= te('Configúrala en Ajustes') ?></a></div>
  <?php endif; ?>
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="foot"><div class="wrap"><?= uc_credit() ?></div></footer>
</body>
</html>
