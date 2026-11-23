<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $users @var ?array $link */ ?>
<div class="page-head"><h1><?= te('Personas') ?></h1></div>
<?php if ($link): ?>
  <div class="card reveal">
    <p><strong><?= te('Enlace para poner la contraseña de {c}', ['c' => $link['email']]) ?></strong> <span class="small muted"><?= te('(vale 72 horas; un solo uso)') ?></span></p>
    <input type="text" readonly value="<?= e($link['link']) ?>" class="copy-field" data-copy>
  </div>
<?php endif; ?>
<div class="two-col">
  <div class="card flush">
    <table class="table">
      <thead><tr><th><?= te('Persona') ?></th><th><?= te('Rol') ?></th><th><?= te('Activa') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $us): $fid = 'u' . (int) $us['id']; ?>
        <tr class="<?= $us['active'] ? '' : 'is-off' ?>">
          <td><input form="<?= $fid ?>" name="name" value="<?= e($us['name']) ?>" aria-label="<?= te('Nombre') ?>"><div class="small muted"><?= e($us['email']) ?><?= $us['auth_source'] === 'bionicadri' ? ' · BionicEye' : '' ?><?= $us['password_hash'] === null && $us['auth_source'] === 'local' ? ' · ' . te('sin contraseña aún') : '' ?><?= $us['last_login_at'] ? ' · ' . te('último acceso {f}', ['f' => date('d/m/Y', strtotime($us['last_login_at']))]) : '' ?></div></td>
          <td><select form="<?= $fid ?>" name="role" aria-label="<?= te('Rol') ?>"><?php foreach (ROLES as $r => $l): ?><option value="<?= e($r) ?>"<?= $us['role'] === $r ? ' selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select></td>
          <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= $us['active'] ? ' checked' : '' ?> aria-label="<?= te('Activa') ?>"></td>
          <td class="r nowrap">
            <form id="<?= $fid ?>" method="post" action="/admin/usuarios/<?= (int) $us['id'] ?>" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= te('Guardar') ?></button></form>
            <?php if ($us['auth_source'] === 'local'): ?><form method="post" action="/admin/usuarios/<?= (int) $us['id'] ?>/enlace" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" title="<?= te('Crea un enlace nuevo para poner la contraseña') ?>"><?= te('Enlace') ?></button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="/admin/usuarios/crear" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Dar de alta') ?></h2>
    <p class="small muted"><?= te('Le llega por correo un enlace para poner su contraseña. Si el correo no sale, te mostramos el enlace para que se lo pases.') ?></p>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Correo') ?><input type="email" name="email" required value="<?= e(old('email')) ?>"></label>
    <label><?= te('Rol') ?><select name="role"><?php foreach (ROLES as $r => $l): ?><option value="<?= e($r) ?>"<?= old('role', 'comercial') === $r ? ' selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-primary" type="submit"><?= te('Dar de alta') ?></button>
  </form>
</div>
