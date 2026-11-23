<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $tokens @var ?string $newToken @var array $owners @var string $mcpUrl */ ?>
<div class="page-head"><h1><?= te('Tokens MCP') ?></h1></div>
<p class="hint"><?= te('Para que ADRI (u otra IA) lea y cambie configuraciones por MCP. El token actúa como la persona elegida: si es comercial, solo ve las suyas. «read» solo lee; «write» además crea, cambia y acepta.') ?></p>
<?php if ($newToken): ?>
  <div class="card reveal" role="alert">
    <p><strong><?= te('Copia el token ahora: no se volverá a mostrar.') ?></strong></p>
    <label><?= te('Token') ?><input type="text" readonly value="<?= e($newToken) ?>" class="copy-field mono" data-copy></label>
    <label><?= te('Dirección MCP') ?><input type="text" readonly value="<?= e($mcpUrl) ?>" class="copy-field mono" data-copy></label>
    <p class="small muted"><?= te('Cabecera: Authorization: Bearer <token>') ?></p>
  </div>
<?php endif; ?>
<div class="two-col">
  <div class="card flush">
    <table class="table">
      <thead><tr><th><?= te('Nombre') ?></th><th><?= te('Actúa como') ?></th><th><?= te('Permisos') ?></th><th class="hide-sm"><?= te('Último uso') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tokens as $tk): ?>
        <tr class="<?= $tk['revoked_at'] ? 'is-off' : '' ?>">
          <td><?= e($tk['name']) ?><div class="small muted mono"><?= e($tk['prefix']) ?>…</div></td>
          <td><?= e($tk['owner_name'] ?? '—') ?><div class="small muted"><?= e(ROLES[$tk['owner_role'] ?? ''] ?? '') ?></div></td>
          <td><?php foreach (explode(',', $tk['scopes']) as $sc): ?><span class="badge"><?= e($sc) ?></span> <?php endforeach; ?></td>
          <td class="hide-sm small"><?= $tk['last_used_at'] ? e(date('d/m/Y H:i', strtotime($tk['last_used_at']))) : '—' ?></td>
          <td class="r"><?php if ($tk['revoked_at']): ?><span class="badge badge-cancelled"><?= te('revocado') ?></span><?php else: ?>
            <form method="post" action="/admin/tokens/<?= (int) $tk['id'] ?>/revocar" class="inline" data-confirm="<?= te('¿Revocar «{n}»? Deja de funcionar al momento.', ['n' => $tk['name']]) ?>"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit"><?= te('Revocar') ?></button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tokens): ?><tr><td colspan="5" class="muted"><?= te('Aún no hay tokens.') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="/admin/tokens/crear" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Nuevo token') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="80" placeholder="ADRI" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Actúa como') ?><select name="user_id"><?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"<?= (int) $o['id'] === (int) current_user()['id'] ? ' selected' : '' ?>><?= e($o['name'] . ' · ' . t(ROLES[$o['role']])) ?></option><?php endforeach; ?></select></label>
    <fieldset class="checks-inline"><legend><?= te('Permisos') ?></legend>
      <?php foreach (TOKEN_SCOPES as $sc => $desc): ?><label class="check"><input type="checkbox" name="scopes[]" value="<?= e($sc) ?>"<?= $sc === 'read' ? ' checked' : '' ?>> <span><strong><?= e($sc) ?></strong> · <?= te($desc) ?></span></label><?php endforeach; ?>
    </fieldset>
    <button class="btn btn-primary" type="submit"><?= te('Crear token') ?></button>
  </form>
</div>
