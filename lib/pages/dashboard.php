<?php
$me = current_user();
$ym = date('Y-m');

// Charges mensuelles du mois en cours : ajoutées automatiquement.
if ($n = generate_recurring($ym)) {
    flash('info', $n . ' charge(s) mensuelle(s) de ' . month_label($ym) . ' ajoutée(s) automatiquement.');
}

if ($me['pw_reset_notice']) {
    flash('info', 'Ton mot de passe a été réinitialisé par l\'administrateur. Cette action figure dans le journal.');
    q('UPDATE users SET pw_reset_notice = 0 WHERE id = ?', [$me['id']]);
}

$p = parties();
$A = (int)$p['A']['id'];
$B = (int)$p['B']['id'];
$bal = balance('all');
$fair = fairness_sentence($bal);
$toValidate = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'en_attente' AND created_by <> ? ORDER BY op_date DESC", [$me['id']])->fetchAll();
$contested = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'conteste' ORDER BY op_date DESC")->fetchAll();
$monthRows = q("SELECT * FROM entries WHERE cancelled = 0 AND substr(op_date, 1, 7) = ? ORDER BY CASE WHEN recurring_id IS NULL THEN 1 ELSE 0 END, op_date DESC, id DESC", [$ym])->fetchAll();

// Ce que chacun doit ce mois-ci selon les parts
$mShare = [$A => 0, $B => 0];
$mTotal = 0;
foreach ($monthRows as $e) {
    if ($e['kind'] !== 'depense') continue;
    [$a, $b] = split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']);
    $mShare[$A] += $a;
    $mShare[$B] += $b;
    $mTotal += (int)$e['amount_cents'];
}

layout_start('Accueil', 'dashboard');
?>
<section class="hero">
  <p class="hero-label">Solde à date</p>
  <p class="hero-amount"><?= money($bal['amount']) ?></p>
  <p class="hero-sentence"><?= h(balance_sentence($bal)) ?></p>
  <?php if ($fair): ?><p class="hero-note"><?= h($fair) ?></p><?php endif; ?>
  <div class="hero-actions">
    <a class="btn" href="<?= url('new') ?>">+ Dépense</a>
    <a class="btn btn-ghost" href="<?= url('transfer') ?>">⇄ Remboursement</a>
  </div>
</section>

<?php if ($toValidate): ?>
<section class="card attention">
  <h2>À valider (<?= count($toValidate) ?>)</h2>
  <div class="list"><?php foreach ($toValidate as $e) echo entry_row($e); ?></div>
</section>
<?php endif; ?>

<?php if ($contested): ?>
<section class="card">
  <h2>Contestées (<?= count($contested) ?>)</h2>
  <div class="list"><?php foreach ($contested as $e) echo entry_row($e); ?></div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2><?= h(ucfirst(month_label($ym))) ?></h2><a href="<?= url('entries') ?>">Tout voir</a></div>
  <ul class="kv">
    <li><span>Total des dépenses</span><strong><?= money($mTotal) ?></strong></li>
    <li><span>Part de <?= h(user_name($A)) ?></span><strong><?= money($mShare[$A]) ?></strong></li>
    <li><span>Part de <?= h(user_name($B)) ?></span><strong><?= money($mShare[$B]) ?></strong></li>
  </ul>
  <?= legend() ?>
  <?php if ($monthRows): ?>
    <div class="list"><?php foreach ($monthRows as $e) echo entry_row($e); ?></div>
  <?php else: ?>
    <p class="empty">Rien ce mois-ci pour l'instant.</p>
  <?php endif; ?>
</section>
<?php layout_end();
