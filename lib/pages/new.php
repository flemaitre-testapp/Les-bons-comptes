<?php
// Ajout d'une dépense. Avec ?replace=ID : correction d'une opération existante
// (l'ancienne est annulée avec renvoi vers la nouvelle, rien n'est effacé).
$me = current_user();
$p = parties();
$A = $p['A'];
$B = $p['B'];

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
    'mode' => $replace ? split_mode_of((int)$replace['part_a_bp'], $replace['fair_a_bp'] === null ? null : (int)$replace['fair_a_bp']) : setting('default_mode', 'base'),
    'part_a' => $replace ? str_replace('.', ',', (string)($replace['part_a_bp'] / 100)) : '',
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
    $bp = resolve_split($f['mode'], $f['part_a']);
    $payer = (int)$f['paid_by'];
    $cat = $f['category_id'] !== '' ? (int)$f['category_id'] : null;
    if (!valid_date($f['op_date'])) $errors[] = 'Date invalide.';
    if ($f['label'] === '' || mb_strlen($f['label']) > 120) $errors[] = 'Libellé obligatoire (120 caractères max).';
    if (!$amount) $errors[] = 'Montant invalide.';
    if ($bp === null) $errors[] = 'Répartition invalide.';
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
        flash('ok', 'Dépense enregistrée.');
        redirect('dashboard');
    }
}

layout_start($replace ? 'Corriger une dépense' : 'Nouvelle dépense', 'new');
?>
<section class="card narrow">
  <h1><?= $replace ? 'Corriger l\'opération #' . (int)$replace['id'] : 'Nouvelle dépense' ?></h1>
  <?php if ($replace): ?>
    <p class="muted">L'opération d'origine reste visible, barrée, avec un lien vers celle-ci.</p>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="form" id="dep-form"
        data-a="<?= h($A['display_name']) ?>" data-b="<?= h($B['display_name']) ?>">
    <?= csrf_field() ?>
    <label>Quoi ? <input name="label" value="<?= h($f['label']) ?>" maxlength="120" placeholder="Ex. Cantine, chaussures, nounou..." required></label>
    <div class="row2">
      <label>Montant (€) <input name="amount" id="amount" value="<?= h($f['amount']) ?>" inputmode="decimal" placeholder="0,00" required></label>
      <label>Date <input type="date" name="op_date" value="<?= h($f['op_date']) ?>" required></label>
    </div>
    <div class="field">
      <span class="lbl">Qui a payé ?</span>
      <div class="seg">
        <?php foreach ([$A, $B] as $u): ?>
          <label><input type="radio" name="paid_by" value="<?= (int)$u['id'] ?>" <?= $f['paid_by'] === (string)$u['id'] ? 'checked' : '' ?>><span><?= h($u['display_name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?= split_fields($f['mode'], $f['part_a']) ?>
    <details class="more"<?= ($f['category_id'] !== '' || $f['notes'] !== '' || $replace) ? ' open' : '' ?>>
      <summary>Catégorie, note, justificatif</summary>
      <label>Catégorie
        <select name="category_id">
          <option value="">(aucune)</option>
          <?php foreach (categories() as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $f['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Note <textarea name="notes" rows="2" maxlength="2000"><?= h($f['notes']) ?></textarea></label>
      <label>Photo du ticket ou PDF <input type="file" name="receipt" accept="image/*,application/pdf"></label>
      <?php if ($replace && $replace['receipt']): ?>
        <label class="check"><input type="checkbox" name="keep_receipt" value="1" checked> Garder le justificatif d'origine</label>
      <?php endif; ?>
    </details>
    <?php if ($replace): ?>
      <label>Raison de la correction <input name="reason" value="<?= h($f['reason']) ?>" maxlength="300" required placeholder="Ex. erreur de montant"></label>
    <?php endif; ?>
    <button class="btn">Enregistrer</button>
  </form>
</section>
<?php layout_end();
