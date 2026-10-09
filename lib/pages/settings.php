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
        $base = parse_pct(post('base'));
        if ($a === null || $b === null || $a + $b === 0 || $base === null) {
            flash('err', 'Valeurs invalides.');
        } else {
            $oldA = (int)setting('income_a', '0');
            $oldB = (int)setting('income_b', '0');
            $oldBase = base_bp();
            set_setting('income_a', (string)$a);
            set_setting('income_b', (string)$b);
            set_setting('base_part_a_bp', (string)$base);
            if ($oldA !== $a || $oldB !== $b) {
                audit('income.update', null, null, [
                    $A['display_name'] => money($oldA) . ' → ' . money($a),
                    $B['display_name'] => money($oldB) . ' → ' . money($b),
                    'nouvelle_part_selon_revenus' => $A['display_name'] . ' ' . pct(income_bp()) . ', ' . $B['display_name'] . ' ' . pct(10000 - income_bp()),
                ]);
            }
            if ($oldBase !== $base) {
                audit('settings.update', null, null, ['regle_de_base' => rule_label($oldBase) . ' → ' . rule_label($base)]);
                db()->beginTransaction();
                q("UPDATE recurring SET part_a_bp = ? WHERE mode = 'custom' AND part_a_bp = ?", [$base, $oldBase]);
                $nb = apply_to_current_month(
                    fn($e) => (int)$e['part_a_bp'] === $oldBase ? [(int)$e['amount_cents'], $base] : null,
                    'Règle de base modifiée : ' . rule_label($oldBase) . ' → ' . rule_label($base)
                );
                db()->commit();
                if ($nb) {
                    flash('info', $nb . ' ligne(s) de ' . month_label(date('Y-m')) . ' recalculée(s) avec la nouvelle règle.');
                }
            }
            flash('ok', 'Réglages enregistrés. Le mois en cours est recalculé, les mois passés gardent leur calcul.');
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
$fmt = fn(int $c) => str_replace('.', ',', rtrim(rtrim(number_format($c / 100, 2, '.', ''), '0'), '.'));
layout_start('Admin', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('settings'); ?>
<section class="card">
  <h2>Règle de répartition et revenus</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="incomes">
    <div class="row2">
      <label>Revenu mensuel de <?= h($A['display_name']) ?> (€) <input name="inc_a" value="<?= h($fmt($incA)) ?>" inputmode="decimal" required></label>
      <label>Revenu mensuel de <?= h($B['display_name']) ?> (€) <input name="inc_b" value="<?= h($fmt($incB)) ?>" inputmode="decimal" required></label>
    </div>
    <?php if ($ib !== null): ?>
      <p class="hint">Part selon revenus : <strong><?= h($A['display_name']) ?> <?= pct($ib) ?></strong>, <strong><?= h($B['display_name']) ?> <?= pct(10000 - $ib) ?></strong>.</p>
    <?php endif; ?>
    <label>Règle de base : part de <?= h($A['display_name']) ?> (%) <input name="base" value="<?= h(str_replace('.', ',', (string)(base_bp() / 100))) ?>" inputmode="decimal" required></label>
    <p class="hint">Règle actuelle : <?= h($A['display_name']) ?> <?= pct(base_bp()) ?>, <?= h($B['display_name']) ?> <?= pct(10000 - base_bp()) ?>. Proposée par défaut pour chaque dépense. L'appli calcule en parallèle la part de chacun selon les revenus. Un changement recalcule le mois en cours (les mois passés ne bougent pas) et est inscrit au journal.</p>
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
