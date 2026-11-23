<?php /** @var bool $catalogEmpty */ $s = fn(string $k) => e(setting($k)); ?>
<div class="page-head"><h1><?= te('Ajustes') ?></h1></div>
<?php if ($catalogEmpty): ?>
<form method="post" action="/admin/ajustes/ejemplo" class="card notice-card"><?= csrf_field() ?>
  <div><strong><?= te('Catálogo vacío') ?></strong><p class="small muted"><?= te('Carga una cocina de ejemplo: 3 modelos (recta, en L, paralela), 4 colores, 9 tipos de módulo con tramos y sus composiciones.') ?></p></div>
  <button class="btn btn-primary" type="submit"><?= te('Cargar datos de ejemplo') ?></button>
</form>
<?php endif; ?>
<form method="post" action="/admin/ajustes" class="settings-grid">
  <?= csrf_field() ?>
  <section class="card stack">
    <h2><?= te('Empresa') ?></h2>
    <label><?= te('Nombre') ?><input name="empresa" required maxlength="120" value="<?= $s('empresa') ?>"></label>
    <label><?= te('Logo (dirección de la imagen)') ?><input name="logo" placeholder="https://… o /…" value="<?= $s('logo') ?>"></label>
    <label><?= te('IVA (%) — solo se muestra aparte') ?><input name="iva" inputmode="decimal" value="<?= $s('iva') ?>"></label>
  </section>
  <section class="card stack">
    <h2><?= te('Acceso') ?></h2>
    <label class="check"><input type="checkbox" name="permitir_registro" value="1"<?= setting_bool('permitir_registro') ? ' checked' : '' ?>> <span><?= te('Registro libre de clientes') ?></span></label>
    <label class="check"><input type="checkbox" name="permitir_invitado" value="1"<?= setting_bool('permitir_invitado') ? ' checked' : '' ?>> <span><?= te('Configurar sin cuenta (invitado: deja sus datos y su consentimiento al aceptar)') ?></span></label>
    <h3><?= te('BionicEye (entrada con la cuenta de BionicEye)') ?></h3>
    <p class="small muted"><?= te('Opcional. Con la dirección y el client_id de la aplicación OAuth (Passport, con PKCE) aparece el botón «Entrar con BionicEye». Dirección de vuelta: {u}', ['u' => base_url() . '/auth/bionicadri/callback']) ?></p>
    <label><?= te('Dirección de BionicEye') ?><input name="bionicadri_url" placeholder="https://…" value="<?= $s('bionicadri_url') ?>"></label>
    <label>client_id<input name="bionicadri_client_id" value="<?= $s('bionicadri_client_id') ?>"></label>
  </section>
  <section class="card stack">
    <h2><?= te('Correo') ?></h2>
    <label><?= te('Forma de envío') ?><select name="mail_transport">
      <?php foreach (['mail' => t('mail() del servidor'), 'smtp' => 'SMTP', 'log' => t('Solo registrar en data/mail.log (pruebas)')] as $k => $l): ?><option value="<?= e($k) ?>"<?= setting('mail_transport') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></label>
    <label><?= te('Remitente') ?><input type="email" name="mail_from" value="<?= $s('mail_from') ?>"></label>
    <div class="row-form tight">
      <label><?= te('Servidor SMTP') ?><input name="smtp_host" value="<?= $s('smtp_host') ?>"></label>
      <label><?= te('Puerto') ?><input type="number" name="smtp_port" class="w-num" value="<?= $s('smtp_port') ?>"></label>
      <label><?= te('Cifrado') ?><select name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => t('Ninguno')] as $k => $l): ?><option value="<?= e($k) ?>"<?= setting('smtp_encryption') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label><?= te('Usuario SMTP') ?><input name="smtp_username" autocomplete="off" value="<?= $s('smtp_username') ?>"></label>
    <label><?= te('Contraseña SMTP') ?><input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= setting('smtp_password') !== '' ? te('(guardada: déjala vacía para no cambiarla)') : '' ?>"></label>
  </section>
  <div class="settings-actions"><button class="btn btn-primary btn-lg" type="submit"><?= te('Guardar ajustes') ?></button></div>
</form>
<form method="post" action="/admin/ajustes/correo-prueba" class="inline"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= te('Enviarme un correo de prueba') ?></button></form>
