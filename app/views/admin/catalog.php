<?php // Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE) ?>
<?php /** @var array $tree */
$renderTree = function (array $nodes, int $depth) use (&$renderTree) {
    if (!$nodes) return;
    echo '<ul class="tree' . ($depth === 0 ? ' tree-root' : '') . '">';
    foreach ($nodes as $n) {
        echo '<li class="' . ($n['active'] ? '' : 'is-off') . '"><div class="tree-row">';
        echo node_visual($n['image']) . '<a class="tree-name" href="/admin/catalogo/' . (int) $n['id'] . '">' . e($n['name']) . '</a>';
        echo $n['is_final'] ? ' <span class="badge">' . te('final') . '</span>' : '';
        echo $n['active'] ? '' : ' <span class="badge badge-cancelled">' . te('desactivado') . '</span>';
        echo '<span class="tree-actions">';
        foreach ([[-1, '↑', t('Subir')], [1, '↓', t('Bajar')]] as [$dir, $ico, $lbl]) {
            echo '<form method="post" action="/admin/catalogo/' . (int) $n['id'] . '/mover" class="inline">' . csrf_field() . '<input type="hidden" name="dir" value="' . $dir . '"><button class="icon-btn" title="' . e($lbl) . '" aria-label="' . e($lbl) . '">' . $ico . '</button></form>';
        }
        echo '<a class="icon-btn" href="/admin/composicion/' . (int) $n['id'] . '" title="' . te('Composición por defecto') . '">▦</a>';
        echo '</span></div>';
        $renderTree($n['children'], $depth + 1);
        echo '</li>';
    }
    echo '</ul>';
};
?>
<div class="page-head"><h1><?= te('Catálogo') ?></h1></div>
<p class="hint"><?= te('Árbol sin límite de niveles (p. ej. Cocina → Modelo → Color). Se configura desde un nodo final (sin hijos activos). La composición por defecto (▦) puede ir en el modelo y la heredan sus colores.') ?></p>
<div class="two-col">
  <div class="card"><?php if ($tree) $renderTree($tree, 0); else echo '<p class="muted">' . te('Vacío. Crea el primer nodo o carga los datos de ejemplo en Ajustes.') . '</p>'; ?></div>
  <form method="post" action="/admin/catalogo/crear" class="card stack">
    <?= csrf_field() ?>
    <h2><?= te('Nuevo nodo raíz') ?></h2>
    <label><?= te('Nombre') ?><input name="name" required maxlength="120" value="<?= e(old('name')) ?>"></label>
    <label><?= te('Descripción') ?><input name="description" value="<?= e(old('description')) ?>"></label>
    <label><?= te('Imagen o color') ?><input name="image" placeholder="https://… o #C8A165" value="<?= e(old('image')) ?>"></label>
    <button class="btn btn-primary" type="submit"><?= te('Crear') ?></button>
  </form>
</div>
