<section class="card narrow">
  <h1><?= te('Recuperar contraseña') ?></h1>
  <p class="muted"><?= te('Escribe tu correo y te enviaremos un enlace para poner una contraseña nueva. El enlace vale 1 hora.') ?></p>
  <form method="post" action="/olvide" class="stack">
    <?= csrf_field() ?>
    <label><?= te('Correo') ?><input type="email" name="email" required autocomplete="email"></label>
    <button class="btn btn-primary btn-block" type="submit"><?= te('Enviar enlace') ?></button>
  </form>
  <p class="small"><a href="/"><?= te('Volver a entrar') ?></a></p>
</section>
