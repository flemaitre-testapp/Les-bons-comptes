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
    if ($action === 'pay') {
        $amount = parse_money(post('amount'));
        $payer = (int)post('paid_by');
        $date = post('date');
        if (!$amount || !valid_date($date) || !in_array($payer, [party_a_id(), (int)parties()['B']['id']], true)) {
            flash('err', 'Paiement : montant, date et payeur obligatoires.');
        } else {
            db()->beginTransaction();
            $pid = create_entry([
                'kind' => 'remboursement', 'op_date' => $date, 'label' => 'Paiement ' . month_label($m),
                'amount_cents' => $amount, 'paid_by' => $payer, 'beneficiary' => other_party($payer),
                'notes' => trim('Paiement effectué le ' . fdate($date) . ' pour ' . month_label($m) . '. ' . post('note')),
                'period' => $m,
            ]);
            db()->commit();
            flash('ok', 'Paiement de ' . money($amount) . ' enregistré, daté du ' . fdate($date) . '.');
            header('Location: ' . url('dashboard', ['m' => $m]) . '#e' . $pid);
            exit;
        }
        redirect('dashboard', ['m' => $m]);
    }
    if ($action === 'ok_all') {
        $col = ok_col($uid);
        db()->beginTransaction();
        $n = 0;
        foreach (q("SELECT * FROM entries WHERE cancelled = 0 AND $col = 0 AND substr(op_date, 1, 7) = ?", [$m])->fetchAll() as $row) {
            set_ok($row, $uid, true);
            $n++;
        }
        db()->commit();
        flash('ok', $n . ' ligne(s) validée(s).');
        redirect('dashboard', ['m' => $m]);
    }
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
                flash('err', 'Montant, répartition et commentaire obligatoires.');
            } elseif ($amount === (int)$e['amount_cents'] && $bp === (int)$e['part_a_bp']) {
                flash('info', 'Rien n\'a changé.');
            } elseif (is_admin()) {
                $anchor = adjust_entry($e, $amount, $bp, $reason, post('future') === '1');
                flash('ok', 'Ligne ajustée. L\'ancienne version reste visible dans l\'historique.');
            } else {
                propose_adjust($e, $uid, $amount, $bp, $reason, post('future') === '1');
                flash('ok', 'Proposition envoyée. Elle s\'appliquera quand ' . user_name(party_a_id()) . ' l\'aura validée.');
            }
        } elseif (in_array($action, ['prop_accept', 'prop_refuse'], true) && is_admin() && ($pr = pending_proposal((int)$e['id']))) {
            if ($action === 'prop_accept') {
                $anchor = accept_proposal($pr, $e);
                flash('ok', 'Proposition acceptée, ligne ajustée.');
            } elseif ($reason === '') {
                flash('err', 'Indique pourquoi tu refuses.');
            } else {
                refuse_proposal($pr, $reason);
                flash('ok', 'Proposition refusée. Le motif est visible par les deux.');
            }
        } elseif ($action === 'prop_withdraw' && ($pr = pending_proposal((int)$e['id'])) && (int)$pr['user_id'] === $uid) {
            q("UPDATE proposals SET status = 'retiree', decided_by = ?, decided_at = ? WHERE id = ?", [$uid, now(), $pr['id']]);
            audit('proposal.withdraw', 'entry', (int)$e['id'], ['proposition' => (int)$pr['id']]);
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

$rows = q("SELECT * FROM entries WHERE cancelled = 0 AND (
               (op_date BETWEEN ? AND ? AND NOT (kind = 'remboursement' AND period IS NOT NULL AND period <> ?))
               OR (kind = 'remboursement' AND period = ?))
           ORDER BY CASE WHEN kind = 'remboursement' THEN 2 WHEN recurring_id IS NULL THEN 1 ELSE 0 END,
                    CASE WHEN part_a_bp IN (0, 10000) THEN 1 ELSE 0 END, op_date, id", [$first, $last, $m, $m])->fetchAll();
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
// Dû pour le mois (dépenses) puis paiements effectués. Positif = Florian doit à Julie.
$du = 0;
$paid = 0;
$payments = [];
foreach ($rows as $e) {
    if ($e['kind'] === 'depense') {
        [$oa, $ob] = line_owed($e);
        $du += $oa - $ob;
    } else {
        $paid += (int)$e['paid_by'] === $A ? (int)$e['amount_cents'] : -(int)$e['amount_cents'];
        $payments[] = $e;
    }
}
$rest = $du - $paid;
$trop = $rest !== 0 && ($du === 0 || ($rest > 0) !== ($du > 0));
$debtor = $rest > 0 ? $A : $B;
$creditor = $rest > 0 ? $B : $A;
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
  <p class="hero-label"><?= $trop ? 'Trop perçu' : 'Reste à payer' ?> · <?= h(month_label($m)) ?></p>
  <p class="hero-amount"><?= money(abs($rest)) ?></p>
  <p class="hero-sentence">
    <?php if ($rest === 0): ?>Mois soldé
    <?php elseif ($trop): ?><?= h(user_name($debtor)) ?> a reçu <?= money(abs($rest)) ?> de trop : à rendre à <?= h(user_name($creditor)) ?>
    <?php else: ?><?= h(user_name($debtor)) ?> doit encore <?= money(abs($rest)) ?> à <?= h(user_name($creditor)) ?>
    <?php endif; ?>
  </p>
  <div class="hero-sum">
    <span>Dû pour le mois<strong><?= $du ? h(user_name($du > 0 ? $A : $B)) . ' → ' . money(abs($du)) : '0,00 €' ?></strong></span>
    <span>Paiements effectués<strong><?= money(abs($paid)) ?></strong></span>
    <span><?= $trop ? 'Trop perçu' : 'Reste' ?><strong class="<?= $trop ? 'trop' : '' ?>"><?= money(abs($rest)) ?></strong></span>
  </div>
  <?php if ($litigeTotal): ?><p class="hero-note">Hors <?= money($litigeTotal) ?> en litige (contestation en cours).</p><?php endif; ?>
  <p class="hero-note">Solde global, tous mois confondus : <?= h(balance_sentence($global)) ?></p>
  <div class="hero-actions">
    <button type="button" class="btn" data-open="pay-dialog">✓ Paiement effectué</button>
    <a class="btn btn-ghost" href="<?= url('new') ?>">+ Dépense</a>
  </div>
</section>

<dialog class="sheet" id="pay-dialog">
  <button type="button" class="sheet-close" data-close aria-label="Fermer">×</button>
  <form method="post" class="form pay-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="0">
    <h2>Paiement effectué · <?= h(month_label($m)) ?></h2>
    <div class="field"><span class="lbl">Qui a payé ?</span>
      <div class="seg">
        <?php foreach ([$A, $B] as $who): ?>
          <label><input type="radio" name="paid_by" value="<?= $who ?>" <?= $who === ($rest !== 0 ? $debtor : $A) ? 'checked' : '' ?>><span class="pay-<?= $who === $A ? 'a' : 'b' ?>"><?= h(user_name($who)) ?> → <?= h(user_name(other_party($who))) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="row2">
      <label>Montant (€) <input name="amount" value="<?= $rest ? h(number_format(abs($rest) / 100, 2, ',', '')) : '' ?>" inputmode="decimal" required></label>
      <label>Date du paiement <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
    </div>
    <label>Note (virement, chèque, espèces...) <input name="note" maxlength="300"></label>
    <p class="hint">Le paiement est rattaché à <?= h(month_label($m)) ?> et garde sa vraie date. L'autre partie le voit et peut le valider.</p>
    <button class="btn">Enregistrer le paiement</button>
  </form>
</dialog>

<?php if ($payments): ?>
<section class="card payments">
  <h2>Paiements de <?= h(month_label($m)) ?></h2>
  <ul class="kv">
    <?php foreach ($payments as $e): ?>
      <li class="pay-line pay-<?= (int)$e['paid_by'] === $A ? 'a' : 'b' ?>"><span><?= fdate($e['op_date']) ?> · <?= h(user_name((int)$e['paid_by'])) ?> → <?= h(user_name((int)$e['beneficiary'])) ?>
        <?= $e['ok_a'] && $e['ok_b'] ? ' ✓' : '' ?></span><strong><?= money((int)$e['amount_cents']) ?></strong></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php $nbProp = is_admin() ? (int)q("SELECT COUNT(*) FROM proposals p JOIN entries e ON e.id = p.entry_id WHERE p.status = 'en_attente' AND e.cancelled = 0")->fetchColumn() : 0; ?>
<?php if ($nbProp): ?><div class="flash flash-info"><?= $nbProp ?> proposition(s) d'ajustement de <?= h($nameB) ?> à valider.</div><?php endif; ?>
<?php if ($todoMe || $otherTodo): ?>
  <div class="flash flash-info">
    <?php if ($todoMe): ?><?= $todoMe ?> ligne(s) à valider par toi ce mois-ci.<?php endif; ?>
    <?php if ($otherTodo): ?> <?= $otherTodo ?> autre(s) sur d'autres mois (<a href="<?= url('entries', ['todo' => 1]) ?>">voir</a>).<?php endif; ?>
  </div>
<?php endif; ?>

<section class="card flush">
  <div class="card-head pad"><h2>Lignes du mois</h2><span class="muted small-txt"><?= $okBoth ?>/<?= count($rows) ?> validées par les deux</span></div>
  <div class="pad-x"><?= legend() ?></div>
  <?php if ($todoMe): ?>
    <form method="post" class="pad-x valid-all" data-confirm="Valider les <?= $todoMe ?> ligne(s) de <?= h(month_label($m)) ?> que tu n'as pas encore validées ?">
      <?= csrf_field() ?><input type="hidden" name="action" value="ok_all"><input type="hidden" name="id" value="0">
      <button class="btn small">✓ Tout valider (<?= $todoMe ?>)</button>
    </form>
  <?php endif; ?>
  <?php if (!$rows): ?><p class="empty">Aucune ligne ce mois-ci.</p><?php endif; ?>
  <div class="tiles">
  <?php foreach ($rows as $e):
      $id0 = (int)$e['id'];
      $isDep0 = $e['kind'] === 'depense';
      $ls0 = $isDep0 ? line_shares($e) : null;
      $hasProp = $isDep0 && pending_proposal($id0);
      $nbC = count($comments[$id0] ?? []);
      $contested0 = $e['disputed_by'] !== null && !$e['dispute_ok']; ?>
    <?php $todo0 = !$e[$myCol]; $both0 = $e['ok_a'] && $e['ok_b']; ?>
    <button type="button" class="tile payer-<?= (int)$e['paid_by'] === $A ? 'a' : 'b' ?><?= $todo0 ? ' t-todo' : '' ?><?= $both0 ? ' t-done' : '' ?><?= $contested0 ? ' t-ko' : '' ?><?= $hasProp ? ' t-prop' : '' ?>" data-open="d<?= $id0 ?>">
      <?php if ($todo0): ?><span class="t-flag">À valider</span><?php elseif ($both0): ?><span class="t-check" title="Validé par les deux">✓</span><?php endif; ?>
      <span class="t-label"><?= h($e['label']) ?></span>
      <span class="t-amt"><?= money((int)$e['amount_cents']) ?></span>
      <?php if ($isDep0): ?>
        <span class="t-split"><span class="c-a"><?= h(mb_substr($nameA, 0, 1)) ?> <?= money($ls0['a']) ?></span><br><span class="c-b"><?= h(mb_substr($nameB, 0, 1)) ?> <?= money($ls0['b']) ?></span></span>
      <?php else: ?>
        <span class="t-split"><?= h(user_name((int)$e['paid_by'])) ?> → <?= h(user_name((int)$e['beneficiary'])) ?></span>
      <?php endif; ?>
      <span class="t-foot">
        <span class="dot <?= $e['ok_a'] ? 'on' : '' ?>" title="<?= h($nameA) ?>"><?= h(mb_substr($nameA, 0, 1)) ?></span>
        <span class="dot <?= $e['ok_b'] ? 'on' : '' ?>" title="<?= h($nameB) ?>"><?= h(mb_substr($nameB, 0, 1)) ?></span>
        <?php if ($nbC): ?><span class="t-ic">💬<?= $nbC ?></span><?php endif; ?>
        <?php if ($hasProp): ?><span class="t-ic" title="Proposition en attente">✎</span><?php endif; ?>
        <span class="t-type tt-<?= entry_tone($e) ?>"><?= ['mensuel' => 'Mensuel', 'ponctuel' => 'Ponctuel', 'perso' => 'Perso', 'remb' => 'Paiement', 'recette' => 'Recette'][entry_tone($e)] ?></span>
      </span>
    </button>
  <?php endforeach; ?>
  </div>
  <?php foreach ($rows as $e):
      $id = (int)$e['id'];
      $isDep = $e['kind'] === 'depense';
      $bp = (int)$e['part_a_bp'];
      $ls = $isDep ? line_shares($e) : null;
      $disputed = $e['disputed_by'] !== null;
      $cs = $comments[$id] ?? []; ?>
    <dialog class="sheet" id="d<?= $id ?>">
    <button type="button" class="sheet-close" data-close aria-label="Fermer">×</button>
    <div class="mrow tone-row tone-<?= entry_tone($e) ?><?= $disputed && !$e['dispute_ok'] ? ' contested' : '' ?>" id="e<?= $id ?>">
      <a class="mrow-head" href="<?= url('entry', ['id' => $id]) ?>">
        <span class="mrow-title"><?= h($e['label']) ?><?= $e['receipt'] ? ' 📎' : '' ?><?= $e['replaces'] ? ' <span class="tag">ajustée</span>' : '' ?></span>
        <span class="amt"><?= money((int)$e['amount_cents']) ?></span>
      </a>
      <div class="mrow-meta"><?= fdate($e['op_date']) ?> · <?= paid_word($e) ?> <?= h(user_name((int)$e['paid_by'])) ?><?= $isDep ? ' · ' . h(split_label($e)) : ' → ' . h(user_name((int)$e['beneficiary'])) ?></div>
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
      <?php if ($isDep && ($pr = pending_proposal($id))): ?>
        <div class="proposal">
          <div><strong><?= h(user_name((int)$pr['user_id'])) ?> propose</strong> : <?= money((int)$pr['amount_cents']) ?> · <?= h(split_label(['part_a_bp' => (int)$pr['part_a_bp'], 'fair_a_bp' => null])) ?><?= $pr['future'] ? ' · aussi les mois suivants' : '' ?>
            <?php [$npa, $npb] = split_amount((int)$pr['amount_cents'], (int)$pr['part_a_bp']); ?>
            <br><span class="muted"><?= h($nameA) ?> <?= money($npa) ?> · <?= h($nameB) ?> <?= money($npb) ?> · « <?= h($pr['reason']) ?> »</span></div>
          <?php if (is_admin()): ?>
            <div class="prop-actions">
              <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_accept"><input type="hidden" name="id" value="<?= $id ?>"><button class="ok mine">✓ Accepter</button></form>
              <form method="post" class="inline-form ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_refuse"><input type="hidden" name="id" value="<?= $id ?>">
                <input name="reason" maxlength="500" placeholder="Motif du refus" required><button class="ok">Refuser</button></form>
            </div>
          <?php elseif ((int)$pr['user_id'] === $uid): ?>
            <div class="prop-actions"><span class="muted small-txt">En attente de validation par <?= h(user_name(party_a_id())) ?>.</span>
              <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="ok">Retirer</button></form></div>
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
        <summary>Commenter<?= can_adjust($e, $me) ? (is_admin() ? ', ajuster' : ', proposer un ajustement') : '' ?><?= $isDep && !$disputed ? ', contester' : '' ?></summary>
        <form method="post" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>">
          <input name="reason" maxlength="2000" placeholder="Commentaire..." required>
          <button class="btn btn-ghost small">Envoyer</button>
        </form>
        <?php if (can_adjust($e, $me)): ?>
          <form method="post" class="form adjust">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= $id ?>">
            <strong><?= is_admin() ? 'Ajuster cette ligne' : 'Proposer un ajustement' ?></strong>
            <?php if (!is_admin()): ?><span class="muted small-txt">Ta proposition s'appliquera une fois validée par <?= h(user_name(party_a_id())) ?>.</span><?php endif; ?>
            <div class="row2">
              <label>Montant réel (€) <input name="amount" value="<?= h(number_format($e['amount_cents'] / 100, 2, ',', '')) ?>" inputmode="decimal" required></label>
              <span></span>
            </div>
            <?php $cur = split_mode_of($bp, null); ?>
            <span class="lbl-sm">Répartition</span>
            <?= split_chips($cur, $cur === 'custom' ? str_replace('.', ',', (string)($bp / 100)) : '') ?>
            <input name="reason" maxlength="500" placeholder="Pourquoi ? (obligatoire)" required>
            <?php if ($e['recurring_id']): ?><label class="check"><input type="checkbox" name="future" value="1"> Appliquer aussi aux mois suivants</label><?php endif; ?>
            <button class="btn small"><?= is_admin() ? 'Ajuster' : 'Envoyer la proposition' ?></button>
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
    </dialog>
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
