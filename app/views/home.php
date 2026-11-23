<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $roots Familias del catálogo (para la imagen y la frase) */
$names = array_map(fn($r) => mb_strtolower($r['name']), array_slice($roots, 0, 4));
$list = $names ? ucfirst(count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' ' . t('y') . ' ' . end($names) : $names[0]) : '';
$pics = array_values(array_filter(array_slice($roots, 0, 3), fn($r) => node_visual((string) $r['image']) !== ''));
?>
<section class="hero">
  <div class="hero-art" aria-hidden="true">
    <?php foreach ($pics as $i => $r): ?><div class="hero-pic hero-pic-<?= $i ?>"><?= node_visual((string) $r['image']) ?></div><?php endforeach; ?>
  </div>
  <div class="hero-copy">
    <p class="eyebrow"><?= e(setting('empresa')) ?><?= $list !== '' ? ' · ' . e($list) : '' ?></p>
    <h1><?= te('Diseña tus muebles a medida y mira el precio al momento') ?></h1>
    <p class="hero-sub"><?= te('Eliges el modelo, ajustas cada módulo con un dedo y te llevas el presupuesto. Sin registrarte.') ?></p>
    <form method="post" action="/invitado" class="hero-go"><?= csrf_field() ?>
      <button class="btn btn-primary btn-xl" type="submit"><?= te('Empezar') ?> <span aria-hidden="true">→</span></button>
    </form>
    <ul class="hero-points">
      <li><?= te('3 pasos, unos 2 minutos') ?></li>
      <li><?= te('Precio a la vista en todo momento') ?></li>
      <li><?= te('Te lo guardamos o te llamamos') ?></li>
    </ul>
  </div>
</section>
<p class="team-link"><?= te('¿Eres del equipo de {e}?', ['e' => setting('empresa')]) ?> <a href="/entrar"><?= te('Entrar') ?></a>
  <?php if (setting_bool('permitir_registro')): ?> · <a href="/registro"><?= te('Crear una cuenta') ?></a><?php endif; ?></p>
