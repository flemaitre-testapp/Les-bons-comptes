<?php
// Ajout d'une dépense. Avec ?replace=ID : correction d'une opération existante
// (l'ancienne est annulée avec renvoi vers la nouvelle, rien n'est effacé).
$me = current_user();
$p = parties();
$A = $p['A'];
$B = $p['B'];
$defaultBp = (int)setting('default_part_a_bp', '5000');

$replace = isset($_GET['replace']) ? load_entry((int)$_GET['replace']) : null;
if ($replace && (!can_cancel($replace, $me) || $replace['kind'] !== 'depense')) {
    flash('err', 'Tu ne peux pas corriger cette opération.');
    redirect('entry', ['id' => $replace['id']]);
}

$f = [
    'op_date' => $replace['op_date'] ?? date('Y-m-d'),
    'label' => $replace['label'] ?? '',
    'amount' => $replace ? number_format($replace['amount_cents'] / 100, 2, ',', '') : '',
    'category_id' => (string)($replace['category_id'] ?? ''),
    'paid_by' => (string)($replace['paid_by'] ?? $me['id']),
    'part_a' => str_replace('.', ',', (string)((($replace['part_a_bp'] ?? $defaultBp)) / 100)),
    'notes' => $replace['notes'] ?? '',
    'reason' => '',
];
$errors = [];

if (is_post()) {
    check_csrf();
    foreach ($f as $k => $v) {
        $f[$k] = post($k);
    }
    $amount = parse_money($f['amount']);
    $bp = parse_pct($f['part_a']);
    $payer = (int)$f['paid_by'];
    $cat = $f['category_id'] !== '' ? (int)$f['category_id'] : null;
    if (!valid_date($f['op_date'])) $errors[] = 'Date invalide.';
    if ($f['label'] === '' || mb_strlen($f['label']) > 120) $errors[] = 'Libellé obligatoire (120 caractères max).';
    if (!$amount) $errors[] = 'Montant invalide.';
    if ($bp === null) $errors[] = 'Répartition invalide (entre 0 et 100 %).';
    if (!in_array($payer, [(int)$A['id'], (int)$B['id']], true)) $errors[] = 'Indique qui a payé.';
    if ($cat && !q('SELECT 1 FROM categories WHERE id = ?', [$cat])->fetchColumn()) $errors[] = 'Catégorie inconnue.';
    if ($replace && $f['reason'] === '') $errors[] = 'Indique la raison de la correction.';
    if (mb_strlen($f['notes']) > 2000) $errors[] = 'Note trop longue.';

    $receipt = null;
    if (!$errors) {
        try {
            $receipt = store_receipt('receipt');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if (!$receipt && $replace && $replace['receipt'] && !$errors && post('keep_receipt') === '1') {
        $receipt = [$replace['receipt'], $replace['receipt_name'], $replace['receipt_sha']];
    }

    if (!$errors) {
        db()->beginTransaction();
        $id = create_entry([
            'kind' => 'depense', 'op_date' => $f['op_date'], 'label' => $f['label'],
            'category_id' => $cat, 'amount_cents' => $amount, 'paid_by' => $payer, 'part_a_bp' => $bp,
            'notes' => $f['notes'],
            'receipt' => $receipt[0] ?? null, 'receipt_name' => $receipt[1] ?? null, 'receipt_sha' => $receipt[2] ?? null,
            'replaces' => $replace ? (int)$replace['id'] : null,
        ]);
        if ($replace) {
            $reason = 'Corrigée par l\'opération #' . $id . ' : ' . $f['reason'];
            q('UPDATE entries SET cancelled = 1, cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?',
                [$me['id'], now(), $reason, $replace['id']]);
            audit('entry.cancel', 'entry', (int)$replace['id'], ['raison' => $reason, 'remplacee_par' => $id]);
        }
        db()->commit();
        flash('ok', 'Dépense enregistrée. ' . user_name(other_party((int)$me['id'])) . ' pourra la valider.');
        redirect('entry', ['id' => $id]);
    }
}

layout_start($replace ? 'Corriger une dépense' : 'Nouvelle dépense', 'new');
?>
<section class="card narrow">
  <h1><?= $replace ? 'Corriger l\'opération #' . (int)$replace['id'] : 'Nouvelle dépense' ?></h1>
  <?php if ($replace): ?>
    <p class="muted">L'opération d'origine ne sera pas effacée : elle sera marquée « annulée » avec un lien vers celle-ci, visible par les deux.</p>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="form" id="dep-form"
        data-a="<?= h($A['display_name']) ?>" data-b="<?= h($B['display_name']) ?>" data-default="<?= h((string)($defaultBp / 100)) ?>">
    <?= csrf_field() ?>
    <label>Libellé <input name="label" value="<?= h($f['label']) ?>" maxlength="120" placeholder="Ex. Cantine septembre, chaussures..." required></label>
    <div class="row2">
      <label>Montant (€) <input name="amount" id="amount" value="<?= h($f['amount']) ?>" inputmode="decimal" placeholder="0,00" required></label>
      <label>Date <input type="date" name="op_date" value="<?= h($f['op_date']) ?>" required></label>
    </div>
    <label>Catégorie
      <select name="category_id">
        <option value="">(aucune)</option>
        <?php foreach (categories() as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="field">
      <span class="lbl">Qui a payé ?</span>
      <div class="seg">
        <?php foreach ([$A, $B] as $u): ?>
          <label><input type="radio" name="paid_by" value="<?= (int)$u['id'] ?>" <?= $f['paid_by'] === (string)$u['id'] ? 'checked' : '' ?>><span><?= h($u['display_name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field">
      <span class="lbl">Répartition</span>
      <div class="chips">
        <button type="button" data-pct="<?= h((string)($defaultBp / 100)) ?>">Règle habituelle (<?= h($A['display_name']) ?> <?= pct($defaultBp) ?>)</button>
        <button type="button" data-pct="50">50 / 50</button>
        <button type="button" data-pct="100">100 % <?= h($A['display_name']) ?></button>
        <button type="button" data-pct="0">100 % <?= h($B['display_name']) ?></button>
      </div>
      <label class="inline">Part de <?= h($A['display_name']) ?> (%) <input name="part_a" id="part_a" value="<?= h($f['part_a']) ?>" inputmode="decimal" required></label>
      <p class="hint" id="split-preview"></p>
    </div>
    <label>Note (facultatif) <textarea name="notes" rows="2" maxlength="2000"><?= h($f['notes']) ?></textarea></label>
    <label>Justificatif : photo du ticket ou PDF (facultatif)
      <input type="file" name="receipt" accept="image/*,application/pdf">
    </label>
    <?php if ($replace && $replace['receipt']): ?>
      <label class="check"><input type="checkbox" name="keep_receipt" value="1" checked> Garder le justificatif d'origine si aucun nouveau n'est joint</label>
    <?php endif; ?>
    <?php if ($replace): ?>
      <label>Raison de la correction <input name="reason" value="<?= h($f['reason']) ?>" maxlength="300" required placeholder="Ex. erreur de montant"></label>
    <?php endif; ?>
    <button class="btn">Enregistrer</button>
  </form>
</section>
<?php layout_end();
