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
    foreach ($todo as $r) {
        $day = min((int)$r['day_of_month'], (int)date('t', strtotime($period . '-01')));
        create_entry([
            'kind' => 'depense',
            'op_date' => sprintf('%s-%02d', $period, $day),
            'label' => $r['label'] . ' (' . month_label($period) . ')',
            'category_id' => $r['category_id'],
            'amount_cents' => (int)$r['amount_cents'],
            'paid_by' => (int)$r['paid_by'],
            'part_a_bp' => rec_bp($r),
            'notes' => 'Charge mensuelle',
            'recurring_id' => (int)$r['id'],
            'period' => $period,
        ]);
    }
    if ($todo) {
        audit('recurring.generate', null, null, ['mois' => month_label($period), 'nombre' => count($todo)]);
    }
    db()->commit();
    flash('ok', count($todo) . ' charge(s) ajoutée(s) pour ' . month_label($period) . '.');
    redirect('dashboard');
}

if ($me['pw_reset_notice']) {
    flash('info', 'Ton mot de passe a été réinitialisé par l\'administrateur. Cette action figure dans le journal.');
    q('UPDATE users SET pw_reset_notice = 0 WHERE id = ?', [$me['id']]);
}

$p = parties();
$bal = balance('all');
$month = balance('all', date('Y-m-t'), date('Y-m-01'));
$fair = fairness_sentence($bal);
$toValidate = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'en_attente' AND created_by <> ? ORDER BY op_date DESC", [$me['id']])->fetchAll();
$contested = q("SELECT * FROM entries WHERE cancelled = 0 AND status = 'conteste' ORDER BY op_date DESC")->fetchAll();
$recent = q('SELECT * FROM entries WHERE cancelled = 0 ORDER BY op_date DESC, id DESC LIMIT 8')->fetchAll();
$pendingRec = pending_recurring($ym);

layout_start('Accueil', 'dashboard');
?>
<section class="hero">
  <p class="hero-label">Solde à date</p>
  <p class="hero-amount"><?= money($bal['amount']) ?></p>
  <p class="hero-sentence"><?= h(balance_sentence($bal)) ?></p>
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

<?php if ($pendingRec && is_admin()): ?>
<section class="card">
  <form method="post" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="generate"><input type="hidden" name="period" value="<?= h($ym) ?>">
    <span class="grow"><?= count($pendingRec) ?> charge(s) mensuelle(s) pas encore ajoutée(s) pour <?= h(month_label($ym)) ?>.</span>
    <button class="btn btn-ghost small">Ajouter</button>
  </form>
</section>
<?php endif; ?>

<?php if ($fair): ?>
<section class="card fair">
  <h2>Contribution au regard des revenus</h2>
  <p class="fair-big"><?= h($fair) ?></p>
  <table class="tbl">
    <thead><tr><th><?= h(ucfirst(month_label($ym))) ?></th><th class="r">A payé</th><th class="r">Revenu</th><th class="r">Effort</th></tr></thead>
    <tbody>
    <?php foreach ($p as $u): $id = (int)$u['id']; $inc = income_of($id); ?>
      <tr><td><?= h($u['display_name']) ?></td><td class="r"><?= money($month['out'][$id]) ?></td><td class="r"><?= money($inc) ?></td>
        <td class="r"><strong><?= $inc ? pct((int)round(max(0, $month['out'][$id]) * 10000 / $inc)) : '' ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">Effort = part du revenu mensuel consacrée aux dépenses communes ce mois-ci. Part selon revenus : <?= h(user_name(party_a_id())) ?> <?= pct(income_bp()) ?>, <?= h(user_name((int)$p['B']['id'])) ?> <?= pct(10000 - income_bp()) ?>.</p>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>Dernières dépenses</h2><a href="<?= url('entries') ?>">Tout voir</a></div>
  <?php if ($recent): ?>
    <div class="list"><?php foreach ($recent as $e) echo entry_row($e); ?></div>
  <?php else: ?>
    <p class="empty">Aucune opération pour l'instant.</p>
  <?php endif; ?>
</section>
<?php layout_end();
