<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** Enlace compartido: solo lectura, sin datos del cliente. @var array $cfg */
$tax = rtrim(rtrim(number_format($cfg['tax_rate'], 2, ',', ''), '0'), ',');
$path = implode(' · ', array_column($cfg['node']['path'], 'name'));
?>
<section class="done-hero">
  <p class="eyebrow"><?= e($path) ?></p>
  <h1><?= te('Una configuración de {e}', ['e' => setting('empresa')]) ?></h1>
  <p class="price-big"><strong class="num"><?= e(money($cfg['total_cents'])) ?></strong> <span><?= te('+ IVA') ?></span></p>
  <p class="muted"><?= $cfg['frozen'] ? te('Precio guardado el {f}.', ['f' => date('d/m/Y', strtotime((string) ($cfg['requested_at'] ?? $cfg['accepted_at'] ?? $cfg['updated_at'])))]) : te('Precio de hoy, según las tarifas actuales.') ?></p>
</section>
<div class="client-grid">
  <div class="client-main">
    <div class="card stack">
      <h2><?= te('¿Quieres una así?') ?></h2>
      <p class="muted"><?= te('Configura la tuya en dos minutos y mira el precio al momento.') ?></p>
      <a class="btn btn-primary btn-block" href="/"><?= te('Configurar la mía') ?></a>
    </div>
  </div>
  <?php $sumTitle = t('Qué incluye'); require __DIR__ . '/_sum.php'; ?>
</div>
