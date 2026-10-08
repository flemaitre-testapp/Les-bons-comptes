<?php
require_admin();
$A = parties()['A'];
$B = parties()['B'];

if (is_post()) {
    check_csrf();
    $action = post('action');
    if ($action === 'incomes') {
        $a = parse_money(post('inc_a'));
        $b = parse_money(post('inc_b'));
        $mode = in_array(post('default_mode'), ['half', 'income'], true) ? post('default_mode') : 'half';
        if ($a === null || $b === null || $a + $b === 0) {
            flash('err', 'Revenus invalides.');
        } else {
            $oldA = (int)setting('income_a', '0');
            $oldB = (int)setting('income_b', '0');
            $oldMode = setting('default_mode', 'half');
            set_setting('income_a', (string)$a);
            set_setting('income_b', (string)$b);
            set_setting('default_mode', $mode);
            if ($oldA !== $a || $oldB !== $b) {
                audit('income.update', null, null, [
                    $A['display_name'] => money($oldA) . ' → ' . money($a),
                    $B['display_name'] => money($oldB) . ' → ' . money($b),
                    'nouvelle_part_selon_revenus' => $A['display_name'] . ' ' . pct(income_bp()) . ', ' . $B['display_name'] . ' ' . pct(10000 - income_bp()),
                ]);
            }
            if ($oldMode !== $mode) {
                audit('settings.update', null, null, ['repartition_par_defaut' => $mode === 'half' ? '50/50' : 'selon revenus']);
            }
            flash('ok', 'Réglages enregistrés. Les opérations déjà saisies gardent leur calcul d\'origine.');
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

$incA = (int)setting('income_a', '0');
$incB = (int)setting('income_b', '0');
$ib = income_bp();
$mode = setting('default_mode', 'half');
$fmt = fn(int $c) => str_replace('.', ',', rtrim(rtrim(number_format($c / 100, 2, '.', ''), '0'), '.'));
layout_start('Admin', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('settings'); ?>
<section class="card">
  <h2>Revenus et répartition</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="incomes">
    <div class="row2">
      <label>Revenu mensuel de <?= h($A['display_name']) ?> (€) <input name="inc_a" value="<?= h($fmt($incA)) ?>" inputmode="decimal" required></label>
      <label>Revenu mensuel de <?= h($B['display_name']) ?> (€) <input name="inc_b" value="<?= h($fmt($incB)) ?>" inputmode="decimal" required></label>
    </div>
    <?php if ($ib !== null): ?>
      <p class="hint">Part selon revenus : <strong><?= h($A['display_name']) ?> <?= pct($ib) ?></strong>, <strong><?= h($B['display_name']) ?> <?= pct(10000 - $ib) ?></strong>.</p>
    <?php endif; ?>
    <label>Répartition proposée par défaut
      <select name="default_mode">
        <option value="half" <?= $mode === 'half' ? 'selected' : '' ?>>50 / 50</option>
        <option value="income" <?= $mode === 'income' ? 'selected' : '' ?>>Selon revenus</option>
      </select>
    </label>
    <p class="hint">Quelle que soit la répartition choisie, l'appli calcule toujours en parallèle la part de chacun selon les revenus, pour montrer qui contribue plus que sa part. Un changement de revenus ne modifie pas les opérations déjà saisies et est inscrit au journal.</p>
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
  <p class="muted">Télécharge régulièrement les exports et garde-les hors du serveur. Chaque mois, envoie le relevé PDF par mail à l'autre partie.</p>
  <p><a class="btn btn-ghost small" href="<?= url('export') ?>">Export des opérations</a> <a class="btn btn-ghost small" href="<?= url('export', ['type' => 'journal']) ?>">Export du journal</a></p>
</section>
<?php layout_end();
