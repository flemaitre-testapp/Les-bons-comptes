<?php
// Vue mensuelle : lignes du mois, % et montants de chacun, validation Florian / Julie,
// ajustement (montant, règle), contestation chiffrée, détail de qui doit quoi.
$me = current_user();
$uid = (int)$me['id'];
$now = date('Y-m');
$m = (string)($_GET['m'] ?? $now);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
    $m = $now;
}
$start = setting('start_month');
if ($start === null) {
    $firstMonth = q('SELECT MIN(substr(op_date, 1, 7)) FROM entries')->fetchColumn();
    $start = $firstMonth && $firstMonth < $now ? $firstMonth : $now;
    set_setting('start_month', $start);
}
if ($m >= $start && $m <= $now && ($n = generate_recurring($m))) {
    flash('info', $n . ' charge(s) mensuelle(s) de ' . month_label($m) . ' ajoutée(s).');
}

if (is_post()) {
    check_csrf();
    $e = load_entry((int)post('id'));
    $action = post('action');
    $reason = post('reason');
    $anchor = (int)post('id');
    if ($e && !$e['cancelled']) {
        db()->beginTransaction();
        if ($action === 'ok') {
            set_ok($e, $uid, post('on') === '1');
        } elseif ($action === 'comment' && $reason !== '' && mb_strlen($reason) <= 2000) {
            q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$e['id'], $uid, $reason, now()]);
            audit('entry.comment', 'entry', (int)$e['id'], ['texte' => $reason]);
        } elseif ($action === 'contest' && $e['kind'] === 'depense') {
            $acc = parse_money(post('accepted') === '' ? '0' : post('accepted'));
            if ($reason === '' || $acc === null || $acc > (int)$e['amount_cents']) {
                flash('err', 'Pour contester : indique le montant que tu acceptes (0 pour refuser) et un motif.');
            } else {
                contest_entry($e, $uid, $acc, $reason);
                flash('ok', 'Contestation enregistrée. Le calcul tient compte du montant que tu acceptes ; l\'écart reste affiché en litige.');
            }
        } elseif ($action === 'withdraw' && (int)$e['disputed_by'] === $uid) {
            withdraw_contest($e, $uid);
        } elseif ($action === 'accept' && $e['disputed_by'] !== null && (int)$e['disputed_by'] !== $uid) {
            accept_contest($e, $uid);
            flash('ok', 'Ajustement accepté.');
        } elseif ($action === 'adjust' && can_adjust($e, $me)) {
            $amount = parse_money(post('amount'));
            $bp = resolve_split(post('mode'), post('part_a'));
            if (!$amount || $bp === null || $reason === '') {
                flash('err', 'Pour ajuster : montant, répartition et commentaire obligatoires.');
            } elseif ($amount === (int)$e['amount_cents'] && $bp === (int)$e['part_a_bp']) {
                flash('info', 'Rien n\'a changé.');
            } else {
                $anchor = adjust_entry($e, $amount, $bp, $reason, post('future') === '1');
                flash('ok', 'Ligne ajustée. L\'ancienne version reste visible dans l\'historique.');
            }
        }
        db()->commit();
    }
    header('Location: ' . url('dashboard', ['m' => $m]) . '#e' . $anchor);
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
           ORDER BY CASE WHEN kind = 'remboursement' THEN 2 WHEN recurring_id IS NULL THEN 1 ELSE 0 END,
                    CASE WHEN part_a_bp IN (0, 10000) THEN 1 ELSE 0 END, op_date, id", [$first, $last])->fetchAll();
$mb = balance('all', $last, $first);
$global = balance('all');
$ib = income_bp();

$comments = [];
if ($rows) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $rows));
    foreach (q("SELECT * FROM comments WHERE entry_id IN ($ids) ORDER BY id") as $c) {
        $comments[(int)$c['entry_id']][] = $c;
    }
}
// Les commentaires d'une version précédente restent attachés à la ligne ajustée
foreach ($rows as $e) {
    $prevId = $e['replaces'];
    $guard = 0;
    while ($prevId && $guard++ < 20) {
        foreach (q('SELECT * FROM comments WHERE entry_id = ? ORDER BY id', [$prevId]) as $c) {
            $comments[(int)$e['id']][] = $c;
        }
        $prevId = q('SELECT replaces FROM entries WHERE id = ?', [$prevId])->fetchColumn();
    }
    if (!empty($comments[(int)$e['id']])) {
        usort($comments[(int)$e['id']], fn($x, $y) => $x['id'] <=> $y['id']);
    }
}

$myCol = ok_col($uid);
$todoMe = 0;
$okBoth = 0;
$owedA = 0;
$owedB = 0;
foreach ($rows as $e) {
    if (!$e[$myCol]) $todoMe++;
    if ($e['ok_a'] && $e['ok_b']) $okBoth++;
    [$oa, $ob] = line_owed($e);
    $owedA += $oa;
    $owedB += $ob;
}
$otherTodo = (int)q("SELECT COUNT(*) FROM entries WHERE cancelled = 0 AND $myCol = 0 AND substr(op_date, 1, 7) <> ?", [$m])->fetchColumn();
$litigeTotal = $mb['litige'][$A] + $mb['litige'][$B];

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
  <p class="hero-sentence"><?= $mb['amount'] ? h(user_name($mb['debtor']) . ' doit ' . money($mb['amount']) . ' à ' . user_name($mb['creditor'])) : 'Mois équilibré' ?></p>
  <?php if ($litigeTotal): ?><p class="hero-note">Dont <?= money($litigeTotal) ?> retirés par une contestation en cours (détail plus bas).</p><?php endif; ?>
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

<section class="card flush">
  <div class="card-head pad"><h2>Lignes du mois</h2><span class="muted small-txt"><?= $okBoth ?>/<?= count($rows) ?> validées par les deux</span></div>
  <div class="pad-x"><?= legend() ?></div>
  <?php if (!$rows): ?><p class="empty">Aucune ligne ce mois-ci.</p><?php endif; ?>
  <?php foreach ($rows as $e):
      $id = (int)$e['id'];
      $isDep = $e['kind'] === 'depense';
      $bp = (int)$e['part_a_bp'];
      $ls = $isDep ? line_shares($e) : null;
      $disputed = $e['disputed_by'] !== null;
      $cs = $comments[$id] ?? []; ?>
    <div class="mrow tone-row tone-<?= entry_tone($e) ?><?= $disputed && !$e['dispute_ok'] ? ' contested' : '' ?>" id="e<?= $id ?>">
      <a class="mrow-head" href="<?= url('entry', ['id' => $id]) ?>">
        <span class="mrow-title"><?= h($e['label']) ?><?= $e['receipt'] ? ' 📎' : '' ?><?= $e['replaces'] ? ' <span class="tag">ajustée</span>' : '' ?></span>
        <span class="amt"><?= money((int)$e['amount_cents']) ?></span>
      </a>
      <div class="mrow-meta"><?= fdate($e['op_date']) ?> · payé par <?= h(user_name((int)$e['paid_by'])) ?><?= $isDep ? ' · ' . h(split_label($e)) : ' → ' . h(user_name((int)$e['beneficiary'])) ?></div>
      <?php if ($isDep): ?>
        <div class="split">
          <span><?= h($nameA) ?> <?= pct($bp) ?> · <?php if ($ls['a'] !== $ls['a0']): ?><s><?= money($ls['a0']) ?></s> <?php endif; ?><strong><?= money($ls['a']) ?></strong></span>
          <span class="split-b"><?= h($nameB) ?> <?= pct(10000 - $bp) ?> · <?php if ($ls['b'] !== $ls['b0']): ?><s><?= money($ls['b0']) ?></s> <?php endif; ?><strong><?= money($ls['b']) ?></strong></span>
        </div>
        <div class="bar" aria-hidden="true"><span data-w="<?= $bp / 100 ?>"></span></div>
      <?php endif; ?>
      <?php if ($disputed): ?>
        <div class="dispute">
          <?php $dn = user_name((int)$e['disputed_by']); ?>
          <?php if ($e['dispute_ok']): ?>
            Contestée par <?= h($dn) ?> : n'accepte que <strong><?= money((int)$e['accepted_cents']) ?></strong> sur <?= money((int)$e['amount_cents']) ?>. Ajustement accepté par <?= h(user_name(other_party((int)$e['disputed_by']))) ?>.
          <?php else: ?>
            Contestée par <?= h($dn) ?> : n'accepte que <strong><?= money((int)$e['accepted_cents']) ?></strong> sur <?= money((int)$e['amount_cents']) ?>, soit <strong><?= money($ls['litige']) ?></strong> en litige.
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($cs): ?>
        <ul class="mini-comments">
          <?php foreach (array_slice($cs, -3) as $c): ?>
            <li><strong><?= h(user_name((int)$c['user_id'])) ?></strong> <span class="muted">· <?= fdate($c['created_at']) ?></span> : <?= h($c['body']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="oks">
        <?php foreach ([$A => 'ok_a', $B => 'ok_b'] as $who => $col): $on = (bool)$e[$col]; ?>
          <?php if ($who === $uid): ?>
            <form method="post" class="ok-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="ok"><input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
              <button class="ok <?= $on ? 'ok-on' : 'ok-off mine' ?>"><?= $on ? '✓ Validé par moi' : 'Valider' ?></button>
            </form>
          <?php else: ?>
            <span class="ok <?= $on ? 'ok-on' : 'ok-off' ?>"><?= $on ? '✓ ' . h(user_name($who)) . ' a validé' : h(user_name($who)) . ' : à valider' ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($disputed && (int)$e['disputed_by'] === $uid && !$e['dispute_ok']): ?>
          <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="ok">Retirer ma contestation</button></form>
        <?php elseif ($disputed && (int)$e['disputed_by'] !== $uid && !$e['dispute_ok']): ?>
          <form method="post" class="ok-form" data-confirm="Accepter que <?= h(user_name((int)$e['disputed_by'])) ?> ne compte que <?= h(money((int)$e['accepted_cents'])) ?> pour cette ligne ?"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="id" value="<?= $id ?>"><button class="ok">Accepter l'ajustement</button></form>
        <?php endif; ?>
      </div>
      <details class="row-actions">
        <summary>Commenter<?= can_adjust($e, $me) ? ', ajuster' : '' ?><?= $isDep && !$disputed ? ', contester' : '' ?></summary>
        <form method="post" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>">
          <input name="reason" maxlength="2000" placeholder="Commentaire..." required>
          <button class="btn btn-ghost small">Envoyer</button>
        </form>
        <?php if (can_adjust($e, $me)): ?>
          <form method="post" class="form adjust">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= $id ?>">
            <strong>Ajuster cette ligne</strong>
            <div class="row2">
              <label>Montant réel (€) <input name="amount" value="<?= h(number_format($e['amount_cents'] / 100, 2, ',', '')) ?>" inputmode="decimal" required></label>
              <span></span>
            </div>
            <?php $cur = split_mode_of($bp, null); ?>
            <span class="lbl-sm">Répartition</span>
            <?= split_chips($cur, $cur === 'custom' ? str_replace('.', ',', (string)($bp / 100)) : '') ?>
            <input name="reason" maxlength="500" placeholder="Pourquoi ? (obligatoire)" required>
            <?php if ($e['recurring_id']): ?><label class="check"><input type="checkbox" name="future" value="1"> Appliquer aussi aux mois suivants</label><?php endif; ?>
            <button class="btn small">Ajuster</button>
          </form>
        <?php endif; ?>
        <?php if ($isDep && !$disputed): ?>
          <form method="post" class="form adjust">
            <?= csrf_field() ?><input type="hidden" name="action" value="contest"><input type="hidden" name="id" value="<?= $id ?>">
            <strong>Contester</strong>
            <label>Montant total que j'accepte (€, 0 = je refuse) <input name="accepted" value="0" inputmode="decimal"></label>
            <input name="reason" maxlength="500" placeholder="Motif (obligatoire)" required>
            <button class="btn btn-warn small">Contester</button>
          </form>
        <?php endif; ?>
      </details>
    </div>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Détail : qui doit quoi</h2>
  <div class="tbl-wrap">
  <table class="tbl small owed">
    <thead><tr><th>Ligne</th><th class="r">Montant</th><th class="r"><?= h($nameA) ?> doit</th><th class="r"><?= h($nameB) ?> doit</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): [$oa, $ob] = line_owed($e); $ls = $e['kind'] === 'depense' ? line_shares($e) : null; ?>
      <tr class="tone-<?= entry_tone($e) ?>">
        <td><?= h($e['label']) ?><?= $ls && $ls['litige'] ? ' <em class="ko">(contestée)</em>' : '' ?></td>
        <td class="r"><?= money((int)$e['amount_cents']) ?></td>
        <td class="r"><?= $oa ? money($oa) : '' ?></td>
        <td class="r"><?= $ob ? money($ob) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td>Totaux</td><td class="r"><?= money($mb['total']) ?></td><td class="r"><?= money($owedA) ?></td><td class="r"><?= money($owedB) ?></td></tr>
      <tr class="em"><td colspan="2">Reste à régler pour le mois</td><td class="r" colspan="2"><?= $owedA === $owedB ? 'Équilibré' : h(user_name($owedA > $owedB ? $A : $B) . ' doit ' . money(abs($owedA - $owedB))) ?></td></tr>
    </tfoot>
  </table>
  </div>
  <?php if ($litigeTotal): ?>
    <p class="hint">Contestations en cours : <?= money($litigeTotal) ?> non comptés. Si l'autre partie avait raison, ce montant s'ajouterait à ce que doit <?= h(user_name($mb['litige'][$A] ? $A : $B)) ?>.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Votre règle et la règle légale</h2>
  <table class="tbl cmp">
    <thead><tr><th></th><th class="r"><?= h($nameA) ?></th><th class="r"><?= h($nameB) ?></th></tr></thead>
    <tbody>
      <tr class="em"><td>Part selon votre règle</td><td class="r"><?= money($mb['share'][$A]) ?></td><td class="r"><?= money($mb['share'][$B]) ?></td></tr>
      <?php if ($ib !== null): ?>
      <tr><td>Part selon les revenus (<?= pct($ib) ?> / <?= pct(10000 - $ib) ?>)</td><td class="r"><?= money($mb['fair'][$A]) ?></td><td class="r"><?= money($mb['fair'][$B]) ?></td></tr>
      <tr><td>Différence</td><td class="r"><?= money($mb['over'][$A], true) ?></td><td class="r"><?= money($mb['over'][$B], true) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($ib !== null && ($s = fairness_sentence($mb))): ?><p class="fair-line"><?= h($s) ?></p><?php endif; ?>
  <p class="hint">Règle légale : chaque parent contribue aux frais des enfants à proportion de ses ressources (art. 371-2 du Code civil). Les dépenses perso restent à 100 % à leur bénéficiaire.</p>
</section>
<p class="center"><a href="<?= url('entries') ?>">Rechercher dans toutes les dépenses</a> · <a href="<?= url('statement', ['from' => $first, 'to' => $last]) ?>">Relevé PDF du mois</a></p>
<?php layout_end();
