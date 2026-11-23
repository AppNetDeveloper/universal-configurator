<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $backups @var int $lastAuto */ ?>
<div class="page-head"><h1><?= te('Copias de seguridad') ?></h1>
  <form method="post" action="/admin/copias/crear" class="inline"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= te('Hacer copia ahora') ?></button></form></div>
<div class="notice key-warn"><strong><?= te('La copia contiene las claves de la instalación') ?></strong> — <?= te('lleva la base de datos, las imágenes y la configuración con la clave que cifra la contraseña del correo y firma los enlaces. Guárdala como guardas una contraseña; al descargarla puedes cifrarla.') ?></div>
<div class="two-col">
  <div class="stack">
    <?php if (!$backups): ?><div class="empty"><p><?= te('Todavía no hay copias. Haz la primera con «Hacer copia ahora».') ?></p></div><?php else: ?>
    <ul class="bk-list" role="list">
    <?php foreach ($backups as $b): ?>
      <li class="card bk-item">
        <div class="bk-head">
          <div><strong><?= e(date('d/m/Y H:i', strtotime($b['created_at']))) ?></strong>
            <?php if ($b['auto']): ?><span class="badge"><?= te('automática') ?></span><?php elseif (str_contains($b['name'], '-antes')): ?><span class="badge"><?= te('antes de restaurar o actualizar') ?></span><?php elseif (str_contains($b['name'], '-subida')): ?><span class="badge"><?= te('subida') ?></span><?php endif; ?>
            <div class="small muted mono clip-name"><?= e($b['name']) ?></div></div>
          <div class="bk-meta"><span class="num"><?= e(bk_human_size($b['size'])) ?></span><span class="small muted"><?= $b['version'] ? 'v' . e($b['version']) : '' ?></span></div>
        </div>
        <details class="bk-actions"><summary><?= te('Descargar, restaurar o borrar') ?></summary>
          <form method="post" action="/admin/copias/<?= e($b['name']) ?>/descargar" class="stack">
            <?= csrf_field() ?>
            <label><?= te('Contraseña para cifrarla (opcional, 10 caracteres o más)') ?><input type="password" name="password" autocomplete="new-password" minlength="10"></label>
            <button class="btn btn-ghost btn-sm" type="submit"><?= te('Descargar') ?></button>
          </form>
          <form method="post" action="/admin/copias/<?= e($b['name']) ?>/restaurar" class="stack restore-form">
            <?= csrf_field() ?>
            <p class="small"><?= te('Restaurar sustituye TODOS los datos de ahora por los de esta copia (antes se guarda una copia de los de ahora). Escribe RESTAURAR para confirmarlo.') ?></p>
            <input name="confirm" autocomplete="off" placeholder="RESTAURAR" aria-label="<?= te('Escribe RESTAURAR') ?>">
            <label class="check"><input type="checkbox" name="with_config" value="1"> <span><?= te('Restaurar también las claves (solo si la copia viene de otra instalación; si no, se conserva la de aquí y la contraseña SMTP de la copia puede no leerse)') ?></span></label>
            <button class="btn btn-danger btn-sm" type="submit"><?= te('Restaurar') ?></button>
          </form>
          <form method="post" action="/admin/copias/<?= e($b['name']) ?>/borrar" data-confirm="<?= te('¿Borrar la copia {n}?', ['n' => $b['name']]) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm danger" type="submit"><?= te('Borrar') ?></button></form>
        </details>
      </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="stack">
    <form method="post" action="/admin/copias/ajustes" class="card stack">
      <?= csrf_field() ?>
      <h2><?= te('Copias automáticas') ?></h2>
      <label><?= te('Frecuencia') ?><select name="backup_auto"><?php foreach (['off' => t('Desactivadas'), 'daily' => t('Una al día'), 'weekly' => t('Una a la semana')] as $k => $l): ?><option value="<?= e($k) ?>"<?= setting('backup_auto') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Cuántas se guardan (las más nuevas)') ?><input type="number" name="backup_keep" min="1" max="365" class="w-num" value="<?= e(setting('backup_keep')) ?>"></label>
      <p class="small muted"><?= te('Se hacen al paso tras una visita (con PHP-FPM) o con cron:') ?> <code>php bin/uc backup --auto</code>.
        <?= $lastAuto ? te('Última automática: {f}.', ['f' => date('d/m/Y H:i', $lastAuto)]) : '' ?></p>
      <button class="btn btn-ghost" type="submit"><?= te('Guardar') ?></button>
    </form>
    <form method="post" action="/admin/copias/subir" enctype="multipart/form-data" class="card stack">
      <?= csrf_field() ?>
      <h2><?= te('Subir una copia') ?></h2>
      <p class="small muted"><?= te('Un .zip de esta aplicación (o el .ucbk cifrado con su contraseña). Se comprueba entero antes de guardarlo: archivos, huellas, versión.') ?></p>
      <input type="file" name="copia" accept=".zip,.ucbk" required>
      <label><?= te('Contraseña (si está cifrada)') ?><input type="password" name="password" autocomplete="off"></label>
      <label><?= te('Para restaurarla ya, escribe RESTAURAR (si no, solo se añade a la lista)') ?><input name="confirm" autocomplete="off" placeholder="RESTAURAR"></label>
      <label class="check"><input type="checkbox" name="with_config" value="1"> <span><?= te('Restaurar también las claves de la copia (por defecto se conserva la clave de esta instalación)') ?></span></label>
      <button class="btn btn-ghost" type="submit"><?= te('Subir') ?></button>
    </form>
  </div>
</div>
