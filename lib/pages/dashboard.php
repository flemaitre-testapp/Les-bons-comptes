<?php
// Vue mensuelle : toutes les lignes du mois, % et montants de chacun,
// validation séparée Florian / Julie, total du mois selon la règle et selon les revenus.
$me = current_user();
$uid = (int)$me['id'];
$now = date('Y-m');
$m = (string)($_GET['m'] ?? $now);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
    $m = $now;
}
$start = setting('start_month');
if ($start === null) {
    $first = q('SELECT MIN(substr(op_date, 1, 7)) FROM entries')->fetchColumn();
    $start = $first && $first < $now ? $first : $now;
    set_setting('start_month', $start);
}

// Les charges mensuelles reviennent chaque mois (depuis le début du suivi, jusqu'au mois en cours)
if ($m >= $start && $m <= $now && ($n = generate_recurring($m))) {
    flash('info', $n . ' charge(s) mensuelle(s) de ' . month_label($m) . ' ajoutée(s).');
}

if (is_post()) {
    check_csrf();
    $e = load_entry((int)post('id'));
    if ($e && !$e['cancelled'] && post('action') === 'ok') {
        db()->beginTransaction();
        set_ok($e, $uid, post('on') === '1');
        db()->commit();
    }
    header('Location: ' . url('dashboard', ['m' => $m]) . '#e' . (int)post('id'));
    exit;
}

if ($me['pw_reset_notice']) {
    flash('info', 'Ton mot de passe a été réinitialisé par l\'administrateur. Cette action figure dans le journal.');
    q('UPDATE users SET pw_reset_notice = 0 WHERE id = ?', [$uid]);
}

$p = parties();
$A = (int)$p['A']['id'];
$B = (int)$p['B']['id'];
$nameA = $p['A']['display_name'];
$nameB = $p['B']['display_name'];
$first = $m . '-01';
$last = date('Y-m-t', strtotime($first));
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));

$rows = q("SELECT * FROM entries WHERE cancelled = 0 AND op_date BETWEEN ? AND ?
           ORDER BY CASE WHEN recurring_id IS NULL THEN 1 ELSE 0 END,
                    CASE WHEN part_a_bp IN (0, 10000) THEN 1 ELSE 0 END, op_date, id", [$first, $last])->fetchAll();
$mb = balance('all', $last, $first);
$global = balance('all');
$ib = income_bp();

$myCol = ok_col($uid);
$todoMe = 0;
$okBoth = 0;
foreach ($rows as $e) {
    if (!$e[$myCol]) $todoMe++;
    if ($e['ok_a'] && $e['ok_b']) $okBoth++;
}
$otherTodo = (int)q("SELECT COUNT(*) FROM entries WHERE cancelled = 0 AND $myCol = 0 AND substr(op_date, 1, 7) <> ?", [$m])->fetchColumn();

layout_start(ucfirst(month_label($m)), 'dashboard');
?>
<div class="month-nav">
  <a href="<?= url('dashboard', ['m' => $prev]) ?>" aria-label="Mois précédent">‹</a>
  <h1><?= h(ucfirst(month_label($m))) ?></h1>
  <a href="<?= url('dashboard', ['m' => $next]) ?>" aria-label="Mois suivant">›</a>
</div>

<section class="hero">
  <p class="hero-label">Bilan de <?= h(month_label($m)) ?></p>
  <p class="hero-amount"><?= money($mb['amount']) ?></p>
  <p class="hero-sentence"><?= $mb['amount'] ? h(user_name($mb['debtor']) . ' doit ' . money($mb['amount']) . ' à ' . user_name($mb['creditor']) . ' pour ce mois') : 'Mois équilibré' ?></p>
  <p class="hero-note">Solde global à date : <?= h(balance_sentence($global)) ?></p>
  <div class="hero-actions">
    <a class="btn" href="<?= url('new') ?>">+ Dépense</a>
    <a class="btn btn-ghost" href="<?= url('transfer') ?>">⇄ Remboursement</a>
  </div>
</section>

<?php if ($todoMe || $otherTodo): ?>
  <div class="flash flash-info">
    <?php if ($todoMe): ?><?= $todoMe ?> ligne(s) à valider par toi ce mois-ci.<?php endif; ?>
    <?php if ($otherTodo): ?> <?= $otherTodo ?> autre(s) sur d'autres mois (<a href="<?= url('entries', ['todo' => 1]) ?>">voir</a>).<?php endif; ?>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Total du mois</h2>
  <table class="tbl cmp">
    <thead><tr><th></th><th class="r"><?= h($nameA) ?></th><th class="r"><?= h($nameB) ?></th></tr></thead>
    <tbody>
      <tr><td>Dépenses du mois</td><td class="r" colspan="2"><strong><?= money($mb['total']) ?></strong></td></tr>
      <tr class="em"><td>Part selon votre règle</td><td class="r"><?= money($mb['share'][$A]) ?></td><td class="r"><?= money($mb['share'][$B]) ?></td></tr>
      <?php if ($ib !== null): ?>
      <tr><td>Part selon les revenus (<?= pct($ib) ?> / <?= pct(10000 - $ib) ?>)</td><td class="r"><?= money($mb['fair'][$A]) ?></td><td class="r"><?= money($mb['fair'][$B]) ?></td></tr>
      <tr><td>Différence</td><td class="r"><?= money($mb['over'][$A], true) ?></td><td class="r"><?= money($mb['over'][$B], true) ?></td></tr>
      <?php endif; ?>
      <tr><td>A avancé ce mois</td><td class="r"><?= money($mb['out'][$A]) ?></td><td class="r"><?= money($mb['out'][$B]) ?></td></tr>
    </tbody>
  </table>
  <?php if ($ib !== null && ($s = fairness_sentence($mb))): ?><p class="fair-line"><?= h($s) ?></p><?php endif; ?>
</section>

<section class="card flush">
  <div class="card-head pad"><h2>Lignes du mois</h2><span class="muted small-txt"><?= $okBoth ?>/<?= count($rows) ?> validées par les deux</span></div>
  <div class="pad-x"><?= legend() ?></div>
  <?php if (!$rows): ?><p class="empty">Aucune ligne ce mois-ci.</p><?php endif; ?>
  <?php foreach ($rows as $e):
      $isDep = $e['kind'] === 'depense';
      [$pa, $pb] = $isDep ? split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']) : [0, 0];
      $bp = (int)$e['part_a_bp']; ?>
    <div class="mrow tone-row tone-<?= entry_tone($e) ?><?= $e['status'] === 'conteste' ? ' contested' : '' ?>" id="e<?= (int)$e['id'] ?>">
      <a class="mrow-head" href="<?= url('entry', ['id' => $e['id']]) ?>">
        <span class="mrow-title"><?= h($e['label']) ?><?= $e['receipt'] ? ' 📎' : '' ?></span>
        <span class="amt"><?= money((int)$e['amount_cents']) ?></span>
      </a>
      <div class="mrow-meta">
        <?= fdate($e['op_date']) ?> · payé par <?= h(user_name((int)$e['paid_by'])) ?>
        <?= $isDep ? '' : ' → ' . h(user_name((int)$e['beneficiary'])) ?>
        <?= $e['status'] === 'conteste' ? ' · <strong class="ko">contestée</strong>' : '' ?>
      </div>
      <?php if ($isDep): ?>
      <div class="split">
        <span class="split-a"><?= h($nameA) ?> <?= pct($bp) ?> · <strong><?= money($pa) ?></strong></span>
        <span class="split-b"><?= h($nameB) ?> <?= pct(10000 - $bp) ?> · <strong><?= money($pb) ?></strong></span>
      </div>
      <div class="bar" aria-hidden="true"><span data-w="<?= $bp / 100 ?>"></span></div>
      <?php endif; ?>
      <div class="oks">
        <?php foreach ([$A => 'ok_a', $B => 'ok_b'] as $who => $col):
            $on = (bool)$e[$col];
            $label = ($on ? '✓ ' : '') . user_name($who) . ($on ? ' a validé' : ' : à valider'); ?>
          <?php if ($who === $uid): ?>
            <form method="post" class="ok-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="ok"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
              <button class="ok <?= $on ? 'ok-on' : 'ok-off mine' ?>" title="<?= $on ? 'Retirer ma validation' : 'Valider cette ligne' ?>"><?= $on ? '✓ Validé par moi' : 'Valider' ?></button>
            </form>
          <?php else: ?>
            <span class="ok <?= $on ? 'ok-on' : 'ok-off' ?>"><?= h($label) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<p class="center"><a href="<?= url('entries') ?>">Rechercher dans toutes les dépenses</a> · <a href="<?= url('statement', ['from' => $first, 'to' => $last]) ?>">Relevé PDF du mois</a></p>
<?php layout_end();
