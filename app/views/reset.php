<?php /** @var string $token @var ?array $who */ ?>
<section class="card narrow">
  <h1><?= te('Nueva contraseña') ?></h1>
  <?php if (!$who): ?>
    <p class="muted"><?= te('El enlace no es válido o ha caducado.') ?></p>
    <p><a class="btn btn-primary" href="/olvide"><?= te('Pedir un enlace nuevo') ?></a></p>
  <?php else: ?>
    <p class="muted"><?= te('Cuenta: {c}', ['c' => $who['email']]) ?></p>
    <form method="post" action="/restablecer" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label><?= te('Contraseña (mínimo 8 caracteres)') ?><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label><?= te('Repite la contraseña') ?><input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block" type="submit"><?= te('Guardar contraseña') ?></button>
    </form>
  <?php endif; ?>
</section>
