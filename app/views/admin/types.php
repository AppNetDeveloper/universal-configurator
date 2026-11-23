<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $types */ ?>
<div class="page-head"><h1><?= te('Tipos de módulo') ?></h1></div>
<p class="hint"><?= te('Cada tipo tiene su tabla de precios por tramos de medida (de 41 a 60 cm = X €). No se cobra por centímetro.') ?></p>
<div class="two-col">
  <div class="card flush">
    <table class="table">
      <thead><tr><th><?= te('Tipo') ?></th><th class="hide-sm"><?= te('Familia') ?></th><th class="r hide-sm"><?= te('Medidas (mm)') ?></th><th class="r"><?= te('Tramos') ?></th></tr></thead>
      <tbody>
      <?php foreach ($types as $t): ?>
        <tr class="<?= $t['active'] ? '' : 'is-off' ?>"><td><a href="/admin/tipos/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a> <span class="small muted mono"><?= e($t['ref']) ?></span><?= $t['active'] ? '' : ' <span class="badge badge-cancelled">' . te('desactivado') . '</span>' ?></td>
          <td class="hide-sm"><?= e($t['family']) ?></td><td class="r num hide-sm"><?= (int) $t['width_mm'] ?>×<?= (int) $t['height_mm'] ?>×<?= (int) $t['depth_mm'] ?></td>
          <td class="r num"><?= (int) $t['tiers'] ?><?= $t['tiers'] ? '' : ' <span class="badge badge-warn">' . te('sin precio') . '</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$types): ?><tr><td colspan="4" class="muted"><?= te('Aún no hay tipos.') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="/admin/tipos/crear" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Nuevo tipo') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" placeholder="<?= te('p. ej. Bajo 1 puerta') ?>" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Referencia') ?><input name="ref" maxlength="60" value="<?= e(old('ref')) ?>"></label>
    <label><?= te('Familia') ?><input name="family" maxlength="60" list="families" placeholder="bajo, alto, columna…" value="<?= e(old('family')) ?>"></label>
    <datalist id="families"><?php foreach (module_families() as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
    <div class="row-form tight">
      <label><?= te('Ancho (mm)') ?><input type="number" name="width_mm" min="1" class="w-num" value="<?= e(old('width_mm', '600')) ?>"></label>
      <label><?= te('Alto (mm)') ?><input type="number" name="height_mm" min="1" class="w-num" value="<?= e(old('height_mm', '720')) ?>"></label>
      <label><?= te('Fondo (mm)') ?><input type="number" name="depth_mm" min="1" class="w-num" value="<?= e(old('depth_mm', '560')) ?>"></label>
    </div>
    <p class="small muted"><?= te('Tablero 19 mm y trasera 8 mm por defecto; se cambian, con el despiece, en la ficha del tipo.') ?></p>
    <button class="btn btn-primary" type="submit"><?= te('Crear') ?></button>
  </form>
</div>
