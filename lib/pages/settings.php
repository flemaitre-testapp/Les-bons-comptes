<?php
require_admin();
$A = parties()['A'];
$B = parties()['B'];

if (is_post()) {
    check_csrf();
    $action = post('action');
    if ($action === 'default_split') {
        $bp = parse_pct(post('part_a'));
        if ($bp === null) {
            flash('err', 'Pourcentage invalide.');
        } else {
            $old = (int)setting('default_part_a_bp', '5000');
            set_setting('default_part_a_bp', (string)$bp);
            audit('settings.update', null, null, ['repartition_defaut' => pct($old) . ' → ' . pct($bp) . ' pour ' . $A['display_name']]);
            flash('ok', 'Répartition par défaut mise à jour (les opérations passées ne changent pas).');
        }
    } elseif ($action === 'cat_add') {
        $name = post('name');
        if ($name === '' || mb_strlen($name) > 60) {
            flash('err', 'Nom de catégorie invalide.');
        } elseif (q('SELECT 1 FROM categories WHERE name = ?', [$name])->fetchColumn()) {
            flash('err', 'Cette catégorie existe déjà.');
        } else {
            q('INSERT INTO categories(name, sort) VALUES(?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM categories))', [$name]);
            audit('category.create', 'category', (int)db()->lastInsertId(), ['nom' => $name]);
            flash('ok', 'Catégorie ajoutée.');
        }
    } elseif ($action === 'cat_update') {
        $cid = (int)post('id');
        $cat = q('SELECT * FROM categories WHERE id = ?', [$cid])->fetch();
        $name = post('name');
        $active = post('active') === '1' ? 1 : 0;
        if ($cat && $name !== '' && mb_strlen($name) <= 60) {
            try {
                q('UPDATE categories SET name = ?, active = ? WHERE id = ?', [$name, $active, $cid]);
                audit('category.update', 'category', $cid, ['avant' => $cat['name'] . ($cat['active'] ? '' : ' (masquée)'), 'apres' => $name . ($active ? '' : ' (masquée)')]);
                flash('ok', 'Catégorie mise à jour.');
            } catch (PDOException $ex) {
                flash('err', 'Ce nom est déjà utilisé.');
            }
        }
    }
    redirect('settings');
}

$bp = (int)setting('default_part_a_bp', '5000');
layout_start('Admin', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('settings'); ?>
<section class="card">
  <h2>Répartition par défaut</h2>
  <p class="muted">Proposée automatiquement à chaque nouvelle dépense, modifiable au cas par cas. Changer ce réglage ne modifie pas les opérations déjà saisies.</p>
  <form method="post" class="form inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="default_split">
    <label class="inline">Part de <?= h($A['display_name']) ?> (%) <input name="part_a" value="<?= h(str_replace('.', ',', (string)($bp / 100))) ?>" inputmode="decimal" required></label>
    <span class="muted">→ <?= h($B['display_name']) ?> : <?= pct(10000 - $bp) ?></span>
    <button class="btn small">Enregistrer</button>
  </form>
</section>
<section class="card">
  <h2>Catégories</h2>
  <div class="cat-list">
    <?php foreach (categories(false) as $c): ?>
      <form method="post" class="inline-form cat-row">
        <?= csrf_field() ?><input type="hidden" name="action" value="cat_update"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <input name="name" value="<?= h($c['name']) ?>" maxlength="60" required>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> active</label>
        <button class="btn btn-ghost small">OK</button>
      </form>
    <?php endforeach; ?>
  </div>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="cat_add">
    <input name="name" placeholder="Nouvelle catégorie" maxlength="60" required>
    <button class="btn small">Ajouter</button>
  </form>
</section>
<section class="card">
  <h2>Sauvegarde</h2>
  <p class="muted">Télécharge régulièrement les deux exports et garde-les hors du serveur (mail, cloud). En cas de litige, ce sont eux, avec les relevés PDF envoyés à l'autre partie, qui font foi.</p>
  <p><a class="btn btn-ghost small" href="<?= url('export') ?>">Export des opérations</a> <a class="btn btn-ghost small" href="<?= url('export', ['type' => 'journal']) ?>">Export du journal</a></p>
</section>
<?php layout_end();
