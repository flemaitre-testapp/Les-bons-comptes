<?php
$me = current_user();
$ym = date('Y-m');

if (is_post() && post('action') === 'generate') {
    check_csrf();
    $period = post('period');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        redirect('dashboard');
    }
    $todo = pending_recurring($period);
    db()->beginTransaction();
    $n = 0;
    foreach ($todo as $r) {
        $day = min((int)$r['day_of_month'], (int)date('t', strtotime($period . '-01')));
        create_entry([
            'kind' => 'depense',
            'op_date' => sprintf('%s-%02d', $period, $day),
            'label' => $r['label'] . ' (' . month_label($period) . ')',
            'category_id' => $r['category_id'],
            'amount_cents' => (int)$r['amount_cents'],
            'paid_by' => (int)$r['paid_by'],
            'part_a_bp' => (int)$r['part_a_bp'],
            'notes' => 'Charge récurrente',
            'recurring_id' => (int)$r['id'],
            'period' => $period,
        ]);
        $n++;
    }
    if ($n) {
        audit('recurring.generate', null, null, ['mois' => month_label($period), 'nombre' => $n]);
    }
    db()->commit();
    flash('ok', $n . ' charge(s) récurrente(s) ajoutée(s) pour ' . month_label($period) . '.');
    redirect('dashboard');
}

$bal = balance('all');
$balNoContest = balance('no_contest');
$toValidate = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'en_attente' AND created_by <> ? ORDER BY op_date DESC", [$me['id']])->fetchAll();
$contested = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'conteste' ORDER BY op_date DESC")->fetchAll();
$recent = q('SELECT * FROM entries ORDER BY id DESC LIMIT 8')->fetchAll();
$pendingRec = pending_recurring($ym);
$p = parties();

$month = q("SELECT paid_by, kind, SUM(amount_cents) s FROM entries WHERE cancelled = 0 AND substr(op_date, 1, 7) = ? GROUP BY paid_by, kind", [$ym])->fetchAll();
$monthDep = 0;
$monthByPayer = [];
foreach ($month as $m) {
    if ($m['kind'] === 'depense') {
        $monthDep += (int)$m['s'];
        $monthByPayer[(int)$m['paid_by']] = ($monthByPayer[(int)$m['paid_by']] ?? 0) + (int)$m['s'];
    }
}

if ($me['pw_reset_notice']) {
    flash('info', 'Ton mot de passe a été réinitialisé par l\'administrateur. Cette action figure dans le journal.');
    q('UPDATE users SET pw_reset_notice = 0 WHERE id = ?', [$me['id']]);
}

layout_start('Accueil', 'dashboard');
?>
<section class="hero <?= $bal['amount'] ? '' : 'even' ?>">
  <p class="hero-label">Solde à date</p>
  <p class="hero-amount"><?= $bal['amount'] ? money($bal['amount']) : '0,00 €' ?></p>
  <p class="hero-sentence"><?= h(balance_sentence($bal)) ?></p>
  <?php if ($contested && ($balNoContest['amount'] !== $bal['amount'] || $balNoContest['debtor'] !== $bal['debtor'])): ?>
    <p class="hero-note">Hors opérations contestées : <?= h(balance_sentence($balNoContest)) ?></p>
  <?php endif; ?>
  <div class="hero-actions">
    <a class="btn" href="<?= url('new') ?>">+ Dépense</a>
    <a class="btn btn-ghost" href="<?= url('transfer') ?>">⇄ Remboursement</a>
  </div>
</section>

<?php if ($toValidate): ?>
<section class="card attention">
  <h2>À valider par toi (<?= count($toValidate) ?>)</h2>
  <p class="muted">Ces opérations ont été saisies par <?= h(user_name(other_party((int)$me['id']))) ?>. Ouvre-les pour valider ou contester.</p>
  <div class="list"><?php foreach ($toValidate as $e) echo entry_row($e); ?></div>
</section>
<?php endif; ?>

<?php if ($contested): ?>
<section class="card">
  <h2>Contestées (<?= count($contested) ?>)</h2>
  <div class="list"><?php foreach ($contested as $e) echo entry_row($e); ?></div>
</section>
<?php endif; ?>

<?php if ($pendingRec): ?>
<section class="card">
  <h2>Charges récurrentes de <?= h(month_label($ym)) ?></h2>
  <p class="muted"><?= count($pendingRec) ?> charge(s) pas encore ajoutée(s) ce mois-ci :
    <?= h(implode(', ', array_map(fn($r) => $r['label'] . ' ' . money((int)$r['amount_cents']), $pendingRec))) ?></p>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="generate"><input type="hidden" name="period" value="<?= h($ym) ?>">
    <button class="btn btn-ghost">Ajouter les charges de <?= h(month_label($ym)) ?></button>
  </form>
</section>
<?php endif; ?>

<section class="grid2">
  <div class="card">
    <h2>Ce mois-ci</h2>
    <p class="big"><?= money($monthDep) ?></p>
    <p class="muted">de dépenses communes en <?= h(month_label($ym)) ?></p>
    <ul class="kv">
      <?php foreach ($p as $u): ?>
        <li><span><?= h($u['display_name']) ?> a payé</span><strong><?= money($monthByPayer[(int)$u['id']] ?? 0) ?></strong></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <h2>Depuis le début</h2>
    <ul class="kv">
      <?php foreach ($p as $u): $id = (int)$u['id']; ?>
        <li class="kv-head"><span><?= h($u['display_name']) ?></span></li>
        <li><span>Dépenses payées</span><strong><?= money($bal['paid'][$id]) ?></strong></li>
        <li><span>Sa part des dépenses</span><strong><?= money($bal['share'][$id]) ?></strong></li>
        <li><span>Remboursements versés</span><strong><?= money($bal['sent'][$id]) ?></strong></li>
        <li><span>Remboursements reçus</span><strong><?= money($bal['received'][$id]) ?></strong></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Dernières saisies</h2><a href="<?= url('entries') ?>">Tout voir</a></div>
  <?php if ($recent): ?>
    <div class="list"><?php foreach ($recent as $e) echo entry_row($e); ?></div>
  <?php else: ?>
    <p class="empty">Aucune opération pour l'instant. Commence par ajouter une dépense.</p>
  <?php endif; ?>
</section>
<?php layout_end();
