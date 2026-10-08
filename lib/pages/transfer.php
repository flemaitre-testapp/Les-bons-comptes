<?php
// Remboursement / virement de l'un vers l'autre.
$me = current_user();
$p = parties();
$A = $p['A'];
$B = $p['B'];
$bal = balance('all');

$f = [
    'op_date' => date('Y-m-d'),
    'amount' => $bal['amount'] ? number_format($bal['amount'] / 100, 2, ',', '') : '',
    'paid_by' => (string)($bal['debtor'] ?? $me['id']),
    'label' => 'Remboursement',
    'notes' => '',
];
$errors = [];

if (is_post()) {
    check_csrf();
    foreach ($f as $k => $v) {
        $f[$k] = post($k);
    }
    $amount = parse_money($f['amount']);
    $payer = (int)$f['paid_by'];
    if (!valid_date($f['op_date'])) $errors[] = 'Date invalide.';
    if (!$amount) $errors[] = 'Montant invalide.';
    if (!in_array($payer, [(int)$A['id'], (int)$B['id']], true)) $errors[] = 'Indique qui a versé l\'argent.';
    if ($f['label'] === '' || mb_strlen($f['label']) > 120) $errors[] = 'Libellé obligatoire.';
    $receipt = null;
    if (!$errors) {
        try {
            $receipt = store_receipt('receipt');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if (!$errors) {
        db()->beginTransaction();
        $id = create_entry([
            'kind' => 'remboursement', 'op_date' => $f['op_date'], 'label' => $f['label'],
            'amount_cents' => $amount, 'paid_by' => $payer, 'beneficiary' => other_party($payer),
            'notes' => $f['notes'],
            'receipt' => $receipt[0] ?? null, 'receipt_name' => $receipt[1] ?? null, 'receipt_sha' => $receipt[2] ?? null,
        ]);
        db()->commit();
        flash('ok', 'Remboursement enregistré.');
        redirect('entry', ['id' => $id]);
    }
}

layout_start('Remboursement', 'new');
?>
<section class="card narrow">
  <h1>Remboursement</h1>
  <p class="muted">Un virement, un chèque ou des espèces donnés directement à l'autre pour solder ou réduire ce qui est dû. Solde actuel : <strong><?= h(balance_sentence($bal)) ?></strong>.</p>
  <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <div class="field">
      <span class="lbl">Qui a versé l'argent ?</span>
      <div class="seg">
        <?php foreach ([$A, $B] as $u): ?>
          <label><input type="radio" name="paid_by" value="<?= (int)$u['id'] ?>" <?= $f['paid_by'] === (string)$u['id'] ? 'checked' : '' ?>><span><?= h($u['display_name']) ?> → <?= h(user_name(other_party((int)$u['id']))) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="row2">
      <label>Montant (€) <input name="amount" value="<?= h($f['amount']) ?>" inputmode="decimal" required></label>
      <label>Date <input type="date" name="op_date" value="<?= h($f['op_date']) ?>" required></label>
    </div>
    <label>Libellé <input name="label" value="<?= h($f['label']) ?>" maxlength="120" required></label>
    <label>Note (facultatif) <textarea name="notes" rows="2" maxlength="2000"><?= h($f['notes']) ?></textarea></label>
    <label>Preuve : capture du virement (facultatif) <input type="file" name="receipt" accept="image/*,application/pdf"></label>
    <button class="btn">Enregistrer</button>
  </form>
</section>
<?php layout_end();
