<?php /** @var array $checks @var bool $ok @var array $errors @var array $values @var array $timezones @var string $proposedUrl */
$v = fn(string $k, string $d = '') => e((string) ($values[$k] ?? $d));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= te('Instalar Universal Configurator') ?></title>
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body>
<main class="wrap install">
  <header class="install-head">
    <span class="mark mark-lg" aria-hidden="true"></span>
    <h1><?= te('Instalar Universal Configurator') ?></h1>
    <p class="muted"><?= te('Crea la base de datos y la cuenta de administración. Tarda menos de un minuto.') ?></p>
  </header>
  <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>
  <section class="card">
    <h2><?= te('Requisitos') ?></h2>
    <ul class="checks">
      <?php foreach ($checks as [$label, $pass, $detail]): ?>
        <li class="<?= $pass ? 'pass' : 'fail' ?>"><span class="mk"><?= $pass ? '✓' : '✗' ?></span><span><?= e($label) ?><span class="small muted block"><?= e($detail) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <form method="post" action="/install" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Tu empresa') ?></h2>
    <label><?= te('Nombre de la empresa') ?><input name="empresa" required maxlength="120" value="<?= $v('empresa') ?>"></label>
    <label><?= te('URL pública de la aplicación') ?><input name="url" required type="url" value="<?= e((string) ($values['url'] ?? $proposedUrl)) ?>">
      <span class="small muted block"><?= te('Confírmala: es la dirección de los enlaces que se envían por correo. Hemos puesto la que usas ahora.') ?></span></label>
    <label><?= te('Zona horaria') ?>
      <select name="timezone"><?php foreach ($timezones as $tz): ?><option<?= $tz === ($values['timezone'] ?? 'Europe/Madrid') ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select>
    </label>
    <h2><?= te('Administración') ?></h2>
    <label><?= te('Tu nombre') ?><input name="admin_name" required maxlength="120" value="<?= $v('admin_name') ?>"></label>
    <label><?= te('Correo') ?><input type="email" name="admin_email" required autocomplete="username" value="<?= $v('admin_email') ?>"></label>
    <label><?= te('Contraseña (mínimo 8 caracteres)') ?><input type="password" name="admin_password" required minlength="8" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="sample" value="1"<?= !$values || !empty($values['sample']) ? ' checked' : '' ?>> <span><?= te('Cargar datos de ejemplo (una cocina con 3 modelos, 4 colores y 9 tipos de módulo). Se pueden cargar más tarde desde Ajustes.') ?></span></label>
    <button class="btn btn-primary btn-block btn-lg" type="submit"<?= $ok ? '' : ' disabled' ?>><?= te('Instalar') ?></button>
  </form>
  <footer class="foot"><?= uc_credit() ?></footer>
</main>
</body>
</html>
