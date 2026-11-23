<section class="card narrow">
  <h1><?= te('Crear cuenta') ?></h1>
  <form method="post" action="/registro" class="stack">
    <?= csrf_field() ?>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Correo') ?><input type="email" name="email" required autocomplete="email" value="<?= e(old('email')) ?>"></label>
    <label><?= te('Contraseña (mínimo 8 caracteres)') ?><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label><?= te('Repite la contraseña') ?><input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-primary btn-block" type="submit"><?= te('Crear cuenta') ?></button>
  </form>
  <p class="small"><a href="/"><?= te('Ya tengo cuenta') ?></a></p>
</section>
