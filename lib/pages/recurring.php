<?php
require_admin();
$A = parties()['A'];
$B = parties()['B'];

if (is_post()) {
    check_csrf();
    $action = post('action');
    $amount = parse_money(post('amount'));
    $mode = in_array(post('mode'), ['half', 'income', 'custom'], true) ? post('mode') : 'half';
    $bp = $mode === 'custom' ? parse_pct(post('part_a')) : 5000;
    $payer = (int)post('paid_by');
    $day = max(1, min(31, (int)post('day', '1')));
    $label = post('label');
    $cat = post('category_id') !== '' ? (int)post('category_id') : null;
    $valid = $label !== '' && mb_strlen($label) <= 100 && $amount && $bp !== null
        && in_array($payer, [(int)$A['id'], (int)$B['id']], true);

    if ($action === 'add') {
        if (!$valid) {
            flash('err', 'Champs invalides : libellé, montant, payeur et répartition sont obligatoires.');
        } else {
            q('INSERT INTO recurring(label, category_id, amount_cents, paid_by, mode, part_a_bp, day_of_month, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
                [$label, $cat, $amount, $payer, $mode, $bp, $day, now()]);
            audit('recurring.create', 'recurring', (int)db()->lastInsertId(), [
                'libelle' => $label, 'montant' => money($amount), 'payeur' => user_name($payer), 'repartition' => rec_label($mode, $bp), 'jour' => $day,
            ]);
            flash('ok', 'Charge récurrente ajoutée. Elle sera proposée chaque mois sur l\'accueil.');
        }
    } elseif ($action === 'update') {
        $rid = (int)post('id');
        $old = q('SELECT * FROM recurring WHERE id = ?', [$rid])->fetch();
        if ($old && $valid) {
            $active = post('active') === '1' ? 1 : 0;
            q('UPDATE recurring SET label = ?, category_id = ?, amount_cents = ?, paid_by = ?, mode = ?, part_a_bp = ?, day_of_month = ?, active = ? WHERE id = ?',
                [$label, $cat, $amount, $payer, $mode, $bp, $day, $active, $rid]);
            audit('recurring.update', 'recurring', $rid, [
                'avant' => $old['label'] . ' ' . money((int)$old['amount_cents']) . ', ' . user_name((int)$old['paid_by']) . ', ' . rec_label($old['mode'], (int)$old['part_a_bp']) . ($old['active'] ? '' : ', inactive'),
                'apres' => $label . ' ' . money($amount) . ', ' . user_name($payer) . ', ' . rec_label($mode, $bp) . ($active ? '' : ', inactive'),
            ]);
            flash('ok', 'Charge mise à jour (les mois déjà générés ne changent pas).');
        } else {
            flash('err', 'Champs invalides.');
        }
    }
    redirect('recurring');
}

$rows = q('SELECT * FROM recurring ORDER BY active DESC, day_of_month, label')->fetchAll();
$cats = categories();

$fields = function (?array $r) use ($A, $B, $cats) {
    $m = $r['mode'] ?? setting('default_mode', 'half');
    $sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
    ob_start(); ?>
    <input name="label" value="<?= h($r['label'] ?? '') ?>" placeholder="Libellé (ex. Nounou)" maxlength="100" required>
    <input name="amount" value="<?= $r ? h(number_format($r['amount_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="Montant" inputmode="decimal" class="w-s" required>
    <select name="category_id"><option value="">(catégorie)</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $sel($c['id'], $r['category_id'] ?? '') ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="paid_by">
      <?php foreach ([$A, $B] as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $sel($u['id'], $r['paid_by'] ?? $A['id']) ?>>Payé par <?= h($u['display_name']) ?></option><?php endforeach; ?>
    </select>
    <select name="mode">
      <option value="half" <?= $m === 'half' ? 'selected' : '' ?>>50 / 50</option>
      <option value="income" <?= $m === 'income' ? 'selected' : '' ?>>Selon revenus</option>
      <option value="custom" <?= $m === 'custom' ? 'selected' : '' ?>>Autre (% <?= h($A['display_name']) ?>)</option>
    </select>
    <input name="part_a" value="<?= $m === 'custom' ? h(str_replace('.', ',', (string)($r['part_a_bp'] / 100))) : '' ?>" class="w-xs" inputmode="decimal" placeholder="%">
    <label class="inline">Jour <input name="day" type="number" min="1" max="31" value="<?= (int)($r['day_of_month'] ?? 1) ?>" class="w-xs"></label>
    <?php return ob_get_clean();
};

layout_start('Charges récurrentes', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('recurring'); ?>
<section class="card">
  <h2>Charges récurrentes</h2>
  <p class="muted">« Selon revenus » utilise les revenus du moment où la charge est ajoutée. Les charges fixes de chaque mois (nounou, épargne, assurance, activités...). Chaque début de mois, un bouton sur l'accueil permet de toutes les ajouter d'un coup. Elles arrivent « à valider » comme n'importe quelle dépense.</p>
  <?php foreach ($rows as $r): ?>
    <form method="post" class="inline-form rec-row<?= $r['active'] ? '' : ' inactive' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?= $fields($r) ?>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>> active</label>
      <button class="btn btn-ghost small">Enregistrer</button>
    </form>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="empty">Aucune charge récurrente.</p><?php endif; ?>
  <h3>Ajouter</h3>
  <form method="post" class="inline-form rec-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?= $fields(null) ?>
    <button class="btn small">Ajouter</button>
  </form>
</section>
<?php layout_end();
