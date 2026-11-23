<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** Tarjeta «Conecta Adri» (solo en el panel; al cliente final nunca). Con Adri en uso, una línea y nada más. @var array $adri @var array $counts */
$acc = (int) $counts['accepted'];
$req = (int) $counts['requested'];
?>
<?php if ($adri['connected']): ?>
  <p class="adri-on"><span class="dot-on" aria-hidden="true"></span><?= te('Adri conectada · último uso {cuando}', ['cuando' => $adri['days'] === 0 ? t('hoy') : ($adri['days'] === 1 ? t('ayer') : t('hace {n} días', ['n' => $adri['days']]))]) ?>
    · <a href="/admin/configuraciones?pendientes=1"><?= te('{n} pendientes de trabajar', ['n' => (int) $counts['pending']]) ?></a></p>
<?php else: ?>
<section class="card adri-card" aria-labelledby="adri-title">
  <div class="adri-copy">
    <p class="eyebrow"><?= te('Con Adri') ?></p>
    <h2 id="adri-title"><?= te('Conecta Adri y olvídate de dibujar') ?></h2>
    <p><?php if ($acc + $req > 0): ?>
      <?= te('Tienes {a} configuraciones aceptadas y {p} presupuestos pedidos. Con Adri conectada, de cada una saldrían sus planos (planta, alzados, cada módulo y cada pieza en DXF y PDF), el presupuesto en PDF con tu marca y la lista de corte para el taller.', ['a' => $acc, 'p' => $req]) ?>
    <?php else: ?>
      <?= te('Cuando aceptes tu primera configuración, Adri puede sacar sus planos (planta, alzados, cada módulo y cada pieza en DXF y PDF), el presupuesto en PDF con tu marca y la lista de corte para el taller.') ?>
    <?php endif; ?></p>
    <p class="small muted"><?= te('Además, configura hablando por chat o WhatsApp, persigue los presupuestos y pasa lo aceptado a producción. Se conecta por MCP con un token de esta instalación; la app no envía nada fuera.') ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="/admin/tokens"><?= te('Crear el token para Adri') ?></a>
      <a class="btn btn-ghost" href="https://www.bionicadri.com" target="_blank" rel="noopener"><?= te('Qué es Adri') ?> ↗</a>
    </div>
  </div>
  <figure class="adri-samples">
    <img src="/assets/adri/planta.png" alt="<?= te('Ejemplo: planta de una cocina en L acotada') ?>" width="520" height="735" loading="lazy">
    <img src="/assets/adri/alzado.png" alt="<?= te('Ejemplo: alzado de una pared con sus cotas') ?>" width="760" height="537" loading="lazy">
    <img src="/assets/adri/piezas.png" alt="<?= te('Ejemplo: despiece de una cajonera pieza a pieza') ?>" width="520" height="735" loading="lazy">
    <figcaption class="small muted"><?= te('Planos reales hechos por Adri desde una configuración de esta app.') ?></figcaption>
  </figure>
</section>
<?php endif; ?>
