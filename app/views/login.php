<?php /** @var array $providers */ ?>
<section class="auth">
  <div class="auth-intro">
    <p class="eyebrow"><?= te('Configurador') ?></p>
    <h1><?= te('Diseña tu producto y conoce el precio al momento.') ?></h1>
    <p class="muted"><?= te('Elige el modelo, ajusta cada módulo a tu medida y guarda el presupuesto.') ?></p>
  </div>
  <div class="card auth-card">
    <h2><?= te('Entrar') ?></h2>
    <form method="post" action="/entrar" class="stack">
      <?= csrf_field() ?>
      <label><?= te('Correo') ?><input type="email" name="email" required autocomplete="username" value="<?= e(old('email')) ?>"></label>
      <label><?= te('Contraseña') ?><input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary btn-block" type="submit"><?= te('Entrar') ?></button>
    </form>
    <p class="small"><a href="/olvide"><?= te('¿Has olvidado tu contraseña?') ?></a></p>
    <?php foreach ($providers as $p): if ($p->id() === 'local') continue; ?>
      <a class="btn btn-ghost btn-block" href="/auth/<?= e($p->id()) ?>"><?= e($p->label()) ?></a>
    <?php endforeach; ?>
    <?php if (setting_bool('permitir_registro') || guest_allowed()): ?>
    <div class="auth-alt">
      <?php if (setting_bool('permitir_registro')): ?><a class="btn btn-ghost btn-block" href="/registro"><?= te('Crear una cuenta') ?></a><?php endif; ?>
      <?php if (guest_allowed()): ?>
      <form method="post" action="/invitado"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit"><?= te('Configurar sin cuenta') ?></button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
