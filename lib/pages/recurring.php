<?php
require_admin();
$A = parties()['A'];
$B = parties()['B'];

if (is_post()) {
    check_csrf();
    $action = post('action');
    $amount = parse_money(post('amount'));
    $choice = post('mode');
    $bp = resolve_split($choice, post('part_a'));
    $mode = $choice === 'half' ? 'half' : ($choice === 'income' ? 'income' : 'custom');
    $payer = (int)post('paid_by');
    $day = max(1, min(28, (int)post('day', '1')));
    $label = post('label');
    $cat = post('category_id') !== '' ? (int)post('category_id') : null;
    $valid = $label !== '' && mb_strlen($label) <= 100 && $amount && $bp !== null
        && in_array($payer, [(int)$A['id'], (int)$B['id']], true);

    if ($action === 'add') {
        if (!$valid) {
            flash('err', 'Libellé, montant, payeur et répartition sont obligatoires.');
        } else {
            q('INSERT INTO recurring(label, category_id, amount_cents, paid_by, mode, part_a_bp, day_of_month, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
                [$label, $cat, $amount, $payer, $mode, $bp, $day, now()]);
            audit('recurring.create', 'recurring', (int)db()->lastInsertId(), [
                'libelle' => $label, 'montant' => money($amount), 'payeur' => user_name($payer), 'repartition' => rec_label($mode, $bp),
            ]);
            flash('ok', 'Charge mensuelle ajoutée.');
        }
    } elseif ($action === 'update') {
        $rid = (int)post('id');
        $old = q('SELECT * FROM recurring WHERE id = ?', [$rid])->fetch();
        if ($old && $valid) {
            $active = post('active') === '1' ? 1 : 0;
            db()->beginTransaction();
            q('UPDATE recurring SET label = ?, category_id = ?, amount_cents = ?, paid_by = ?, mode = ?, part_a_bp = ?, day_of_month = ?, active = ? WHERE id = ?',
                [$label, $cat, $amount, $payer, $mode, $bp, $day, $active, $rid]);
            $new = q('SELECT * FROM recurring WHERE id = ?', [$rid])->fetch();
            $nb = apply_to_current_month(
                fn($e) => (int)$e['recurring_id'] === $rid ? [(int)$new['amount_cents'], rec_bp($new)] : null,
                'Charge mensuelle modifiée dans les réglages'
            );
            audit('recurring.update', 'recurring', $rid, [
                'avant' => $old['label'] . ' ' . money((int)$old['amount_cents']) . ', payé par ' . user_name((int)$old['paid_by']) . ', ' . rec_label($old['mode'], (int)$old['part_a_bp']) . ($old['active'] ? '' : ', arrêtée'),
                'apres' => $label . ' ' . money($amount) . ', payé par ' . user_name($payer) . ', ' . rec_label($mode, $bp) . ($active ? '' : ', arrêtée'),
            ]);
            db()->commit();
            flash('ok', 'Charge mise à jour' . ($nb ? ' et répercutée sur ' . month_label(date('Y-m')) : '') . '. Les mois passés ne changent pas.');
        } else {
            flash('err', 'Champs invalides.');
        }
    }
    redirect('recurring');
}

$rows = q('SELECT * FROM recurring ORDER BY active DESC, CASE WHEN part_a_bp IN (0, 10000) THEN 1 ELSE 0 END, label')->fetchAll();
$cats = categories();
$total = ['commun' => 0, 'perso' => 0];
foreach ($rows as $r) {
    if (!$r['active']) continue;
    $bp = rec_bp($r);
    $total[($bp === 0 || $bp === 10000) ? 'perso' : 'commun'] += (int)$r['amount_cents'];
}

$fields = function (?array $r) use ($A, $B, $cats) {
    $sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
    $m = $r ? ($r['mode'] === 'custom' ? split_mode_of((int)$r['part_a_bp'], null) : $r['mode']) : 'base';
    ob_start(); ?>
    <input name="label" value="<?= h($r['label'] ?? '') ?>" placeholder="Libellé" maxlength="100" required>
    <input name="amount" value="<?= $r ? h(number_format($r['amount_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="Montant total" inputmode="decimal" class="w-s" required>
    <select name="paid_by">
      <?php foreach ([$A, $B] as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $sel($u['id'], $r['paid_by'] ?? $B['id']) ?>>Payé par <?= h($u['display_name']) ?></option><?php endforeach; ?>
    </select>
    <?= split_chips($m, $m === 'custom' && $r ? str_replace('.', ',', (string)($r['part_a_bp'] / 100)) : '') ?>
    <select name="category_id"><option value="">(catégorie)</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $sel($c['id'], $r['category_id'] ?? '') ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="day" value="<?= (int)($r['day_of_month'] ?? 1) ?>">
    <?php return ob_get_clean();
};

layout_start('Charges mensuelles', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('recurring'); ?>
<section class="card">
  <h2>Charges mensuelles</h2>
  <p class="muted">Ajoutées automatiquement le 1er de chaque mois. Montants totaux, avant répartition.</p>
  <p class="legend"><span class="tone tone-mensuel">Commune <?= money($total['commun']) ?>/mois</span> <span class="tone tone-perso">Perso <?= money($total['perso']) ?>/mois</span></p>
  <?php foreach ($rows as $r): $bp = rec_bp($r); $tone = ($bp === 0 || $bp === 10000) ? 'perso' : 'mensuel'; ?>
    <form method="post" class="inline-form rec-row tone-row tone-<?= $tone ?><?= $r['active'] ? '' : ' inactive' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?= $fields($r) ?>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>> active</label>
      <button class="btn btn-ghost small">OK</button>
    </form>
  <?php endforeach; ?>
  <h3>Ajouter une charge mensuelle</h3>
  <form method="post" class="inline-form rec-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?= $fields(null) ?>
    <button class="btn small">Ajouter</button>
  </form>
</section>
<?php layout_end();
