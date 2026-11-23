<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $st */
$r = $st['result'];
?>
<div class="page-head"><h1><?= te('Actualizaciones') ?></h1>
  <form method="post" action="/admin/actualizaciones/buscar" class="inline"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= te('Buscar actualizaciones') ?></button></form></div>
<?php if (!empty($_GET['fin'])): ?><p class="notice"><?= te('Código cambiado. Si ves esta página, la actualización ha terminado: mira el resultado abajo.') ?></p><?php endif; ?>
<div class="two-col">
  <div class="stack">
    <section class="card update-card">
      <p class="eyebrow"><?= te('Versión instalada') ?></p>
      <p class="update-v">v<?= e($st['installed']) ?></p>
      <?php if ($st['latest']): ?>
        <div class="update-new">
          <p><strong><?= te('Hay una versión nueva: v{v}', ['v' => $st['latest']['version']]) ?></strong><?php if (!empty($st['latest']['released_at'])): ?> <span class="muted small">· <?= e(date('d/m/Y', strtotime($st['latest']['released_at']))) ?></span><?php endif; ?></p>
          <?php if (trim((string) ($st['latest']['changelog'] ?? '')) !== ''): ?><pre class="changelog"><?= e($st['latest']['changelog']) ?></pre><?php endif; ?>
          <?php if ($st['can']): ?>
          <form method="post" action="/admin/actualizaciones/actualizar" class="stack">
            <?= csrf_field() ?>
            <p class="small"><?= te('Antes se hace una copia de seguridad; el paquete se comprueba (firma y sha256); durante un minuto el configurador muestra «Volvemos en un momento». Si algo falla, vuelve solo a la versión anterior. Nunca se toca data/.') ?></p>
            <label class="check"><input type="checkbox" name="confirm" value="1" required> <span><?= te('Entendido: actualizar ahora') ?></span></label>
            <button class="btn btn-primary" type="submit"><?= te('Actualizar ahora') ?></button>
          </form>
          <?php else: ?><p class="flash flash-error"><?= e($st['why']) ?></p><?php endif; ?>
        </div>
      <?php elseif ($st['checked_at']): ?>
        <p class="muted"><?= $st['error'] ? te('No se ha podido comprobar: {e}', ['e' => $st['error']]) : te('Tienes la última versión.') ?> <span class="small">(<?= te('comprobado el {f}', ['f' => date('d/m/Y H:i', $st['checked_at'])]) ?>)</span></p>
      <?php else: ?>
        <p class="muted"><?= te('Todavía no se ha buscado. Pulsa «Buscar actualizaciones».') ?></p>
      <?php endif; ?>
    </section>
    <?php if ($r): ?>
    <section class="card">
      <h2><?= te('Última actualización') ?></h2>
      <p class="<?= !empty($r['ok']) ? '' : 'line-error' ?>"><?= e((string) ($r['message'] ?? '')) ?></p>
      <p class="small muted"><?= e(date('d/m/Y H:i', strtotime((string) ($r['at'] ?? 'now')))) ?></p>
      <?php if (!empty($st['last']) && ($st['last']['to'] ?? '') === $st['installed']): ?>
      <form method="post" action="/admin/actualizaciones/volver" class="stack">
        <?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="confirm" value="1" required> <span><?= te('Volver a la v{v} (solo el código; los datos de ahora se conservan)', ['v' => $st['last']['from']]) ?></span></label>
        <button class="btn btn-ghost" type="submit"><?= te('Volver a la versión anterior') ?></button>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
  <form method="post" action="/admin/actualizaciones/ajustes" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Cuándo se busca') ?></h2>
    <p class="small"><?= te('Buscar una versión nueva consulta las versiones públicas de github.com/{r}. Es la única conexión que hace la aplicación hacia fuera, y solo cuando pulsas «Buscar actualizaciones» o si activas esta casilla. Nunca envía datos tuyos y nunca se actualiza sola.', ['r' => $st['repo']]) ?></p>
    <label class="check"><input type="checkbox" name="update_check_daily" value="1"<?= $st['daily'] ? ' checked' : '' ?>> <span><?= te('Buscar sola una vez al día') ?></span></label>
    <button class="btn btn-ghost" type="submit"><?= te('Guardar') ?></button>
    <p class="small muted"><?= te('También desde el servidor: php bin/uc update:check · php bin/uc update --yes') ?></p>
  </form>
</div>
