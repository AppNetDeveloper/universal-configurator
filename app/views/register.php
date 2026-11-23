<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<section class="card narrow">
  <h1><?= te('Crear cuenta') ?></h1>
  <form method="post" action="/registro" class="stack">
    <?= csrf_field() ?>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Correo') ?><input type="email" name="email" required autocomplete="email" value="<?= e(old('email')) ?>"></label>
    <p class="small muted"><?= te('Te enviaremos un enlace para activar la cuenta y poner tu contraseña.') ?></p>
    <button class="btn btn-primary btn-block" type="submit"><?= te('Crear cuenta') ?></button>
  </form>
  <p class="small"><a href="/"><?= te('Ya tengo cuenta') ?></a></p>
</section>
