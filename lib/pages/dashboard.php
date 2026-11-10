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
    $keepOpen = false; // la fiche ne reste ouverte qu'après un commentaire
    $editOpen = false; // ou après un changement de montant / répartition
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
            header('Location: ' . url('dashboard', ['m' => $m]));
            exit;
        }
        redirect('dashboard', ['m' => $m]);
    }
    if ($action === 'ok_all') {
        $col = ok_col($uid);
        db()->beginTransaction();
        $n = 0;
        foreach (q("SELECT * FROM entries WHERE cancelled = 0 AND $col = 0 AND (disputed_by IS NULL OR dispute_ok = 1) AND substr(op_date, 1, 7) = ?", [$m])->fetchAll() as $row) {
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
            $keepOpen = true;
        } elseif ($action === 'contest' && $e['kind'] === 'depense') {
            $acc = parse_money(post('accepted') === '' ? '0' : post('accepted'));
            if ($reason === '' || $acc === null || $acc > (int)$e['amount_cents']) {
                flash('err', 'Pour contester : indique le montant que tu acceptes (0 pour refuser) et un motif.');
            } else {
                contest_entry($e, $uid, $acc, $reason);
                flash('ok', 'Contestation enregistrée. Le calcul tient compte du montant que tu acceptes ; l\'écart reste affiché en litige.');
            }
        } elseif ($action === 'delete_line' && is_admin()) {
            delete_entry($e, $reason);
            flash('ok', '« ' . $e['label'] . ' » supprimée de ' . month_label($m) . '.');
            $keepOpen = false;
        } elseif ($action === 'stop_recurring' && is_admin() && $e['recurring_id']) {
            stop_recurring((int)$e['recurring_id'], $m);
            flash('ok', '« ' . $e['label'] . ' » supprimée à partir de ' . month_label($m) . '.');
            $keepOpen = false;
        } elseif ($action === 'cancel_line' && can_cancel($e, $me)) {
            if ($reason === '') {
                flash('err', 'Indique pourquoi tu annules cette ligne.');
            } else {
                q('UPDATE entries SET cancelled = 1, cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?', [$uid, now(), $reason, $e['id']]);
                skip_recurring_month($e);
                audit('entry.cancel', 'entry', (int)$e['id'], ['raison' => $reason, 'libelle' => $e['label'], 'montant' => money((int)$e['amount_cents'])]);
                flash('ok', '« ' . $e['label'] . ' » annulée. Elle n\'est plus comptée, l\'annulation reste visible dans le journal.');
            }
        } elseif ($action === 'withdraw' && (int)$e['disputed_by'] === $uid) {
            withdraw_contest($e, $uid);
        } elseif ($action === 'accept' && $e['disputed_by'] !== null && (int)$e['disputed_by'] !== $uid) {
            accept_contest($e, $uid);
            flash('ok', 'Ajustement accepté.');
        } elseif ($action === 'adjust' && can_adjust($e, $me)) {
            $editOpen = is_admin();
            $amount = parse_money(post('amount'));
            $bp = resolve_split(post('mode'), post('part_a'));
            if (is_admin() && $reason === '') {
                $reason = 'Correction';
            }
            if (!$amount || $bp === null || $reason === '') {
                flash('err', 'Montant, répartition et commentaire obligatoires.');
            } elseif ($amount === (int)$e['amount_cents'] && $bp === (int)$e['part_a_bp']) {
                flash('info', 'Rien n\'a changé.');
            } elseif (is_admin()) {
                $anchor = adjust_entry($e, $amount, $bp, $reason, $e['recurring_id'] && post('only_month') !== '1');
                flash('ok', 'Corrigé : ' . money($amount) . '.');
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
    if (post('ajax') === '1') {
        header('Content-Type: application/json');
        echo '{"ok":true}';
        exit;
    }
    header('Location: ' . url('dashboard', ['m' => $m]) . ($editOpen ? '#a' . $anchor : ($keepOpen ? '#e' . $anchor : '')));
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

$summary = month_summary($m);
$rows = $summary['rows'];
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
    $okA = ($okA ?? 0) + ($e['ok_a'] ? 1 : 0);
    $okB = ($okB ?? 0) + ($e['ok_b'] ? 1 : 0);
    [$oa, $ob] = line_owed($e);
    $owedA += $oa;
    $owedB += $ob;
}
['du' => $du, 'paid' => $paid, 'carry' => $carry, 'rest' => $rest, 'trop' => $trop, 'debtor' => $debtor, 'creditor' => $creditor] = $summary;
$owedA += max($carry, 0);
$owedB += max(-$carry, 0);
$otherTodo = (int)q("SELECT COUNT(*) FROM entries WHERE cancelled = 0 AND $myCol = 0 AND substr(op_date, 1, 7) <> ?", [$m])->fetchColumn();
$litigeTotal = $mb['litige'][$A] + $mb['litige'][$B];

$nbProp = is_admin() ? (int)q("SELECT COUNT(*) FROM proposals p JOIN entries e ON e.id = p.entry_id WHERE p.status = 'en_attente' AND e.cancelled = 0")->fetchColumn() : 0;

// Ce qui reste à traiter par moi : le reste est rangé.
$isBlocked = fn(array $e): bool => ($e['disputed_by'] !== null && !$e['dispute_ok']) || ($e['kind'] === 'depense' && is_admin() && pending_proposal((int)$e['id']));
$todoRows = array_values(array_filter($rows, fn($e) => !$e[$myCol] || $isBlocked($e)));
$doneRows = array_values(array_filter($rows, fn($e) => $e[$myCol] && !$isBlocked($e)));
$tickable = count(array_filter($todoRows, fn($e) => !$isBlocked($e)));

$line = function (array $e, bool $todo) use ($uid, $A, $nameA, $nameB, $comments, $isBlocked): string {
    $id = (int)$e['id'];
    $isDep = $e['kind'] === 'depense';
    $payerClass = (int)$e['paid_by'] === $A ? 'who-a' : 'who-b';
    $payerName = user_name((int)$e['paid_by']);
    $type = ['mensuel' => 'Fixe', 'ponctuel' => 'Ponctuel', 'perso' => 'Perso', 'remb' => 'Paiement', 'recette' => 'Recette'][entry_tone($e)];
    if ($isDep) {
        $ls = line_shares($e);
        $mine = $uid === $A ? $ls['a'] : $ls['b'];
        $verb = !empty($e['recette']) ? 'perçu' : 'payé';
        $sub = $type . ' · ' . ((int)$e['paid_by'] === $uid ? 'Tu as ' . $verb : h($payerName) . ' a ' . $verb);
    } else {
        $sub = $type . ' · ' . h($payerName) . ' → ' . h(user_name((int)$e['beneficiary'])) . ' · ' . fdate($e['op_date']);
    }
    $mine = $isDep ? $mine : null;
    $cs = $comments[$id] ?? [];
    $last = $cs ? end($cs) : null;
    $blocked = $isBlocked($e);
    ob_start(); ?>
    <li class="line" id="l<?= $id ?>">
      <button type="button" class="line-open" data-open="d<?= $id ?>">
        <span class="who <?= $payerClass ?>" title="Payé par <?= h($payerName) ?>"><?= h(mb_substr($payerName, 0, 1)) ?></span>
        <span class="line-main">
          <span class="line-title"><?= h($e['label']) ?></span>
          <span class="line-sub"><?= $sub ?></span>
          <?php if ($last): ?><span class="line-note"><?= h(user_name((int)$last['user_id'])) ?> : <?= h($last['body']) ?></span><?php endif; ?>
          <?php if ($blocked): ?><span class="line-alert">À régler : ouvre la fiche</span><?php endif; ?>
        </span>
        <span class="line-amt">
          <?php if ($mine !== null): ?>
            <span class="my-label"><?= !empty($e['recette']) ? 'Pour toi' : 'Ta part' ?></span>
            <span class="my-part"><?= money($mine) ?></span>
            <span class="line-total">sur <?= money((int)$e['amount_cents']) ?></span>
          <?php else: ?>
            <span class="my-part"><?= money((int)$e['amount_cents']) ?></span>
          <?php endif; ?>
        </span>
      </button>
      <?php if ($todo && !$blocked): ?>
        <form method="post" class="tick-form" data-tick="<?= $id ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="ok"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="on" value="1">
          <button class="tick" aria-label="Valider <?= h($e['label']) ?>">✓</button>
        </form>
      <?php endif; ?>
    </li>
    <?php return ob_get_clean();
};

layout_start(ucfirst(month_label($m)), 'dashboard');
?>
<div class="month-nav">
  <a href="<?= url('dashboard', ['m' => $prev]) ?>" aria-label="Mois précédent">‹</a>
  <h1><?= h(ucfirst(month_label($m))) ?></h1>
  <a href="<?= url('dashboard', ['m' => $next]) ?>" aria-label="Mois suivant">›</a>
</div>

<section class="hero">
  <p class="hero-sentence">
    <?php if ($rest === 0): ?>Mois soldé
    <?php elseif ($trop): ?><?= h(user_name($debtor)) ?> a reçu trop, à rendre à <?= h(user_name($creditor)) ?>
    <?php else: ?><?= h(user_name($debtor)) ?> doit à <?= h(user_name($creditor)) ?>
    <?php endif; ?>
  </p>
  <p class="hero-amount"><?= money(abs($rest)) ?></p>
  <p class="hero-note"><?php if ($carry): ?>Report du mois d'avant : <?= h(user_name($carry > 0 ? $A : $B)) ?> doit <?= money(abs($carry)) ?><br><?php endif; ?>Dû pour le mois : <?= $du === 0 ? 'rien' : h(user_name($du > 0 ? $A : $B)) . ' doit ' . money(abs($du)) ?><?php foreach ($summary['payments'] as $py): ?><br>Paiement : <?= h(user_name((int)$py['paid_by'])) ?> a versé <?= money((int)$py['amount_cents']) ?> à <?= h(user_name((int)$py['beneficiary'])) ?><?php endforeach; ?><?= $litigeTotal ? ' · ' . money($litigeTotal) . ' en litige' : '' ?></p>
  <div class="hero-actions">
    <a class="btn" href="<?= url('new') ?>">+ Dépense</a>
    <button type="button" class="btn btn-ghost" data-open="pay-dialog">Paiement reçu ou versé</button>
  </div>
</section>

<dialog class="sheet" id="pay-dialog">
  <button type="button" class="sheet-close" data-close aria-label="Fermer">×</button>
  <form method="post" class="form pay-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="0">
    <h2>Paiement · <?= h(month_label($m)) ?></h2>
    <p class="hint">Argent réellement versé de l'un à l'autre (virement, chèque, espèces). Pour la CAF ou une autre aide touchée par l'un de vous, utilise plutôt « + Dépense », puis « Argent perçu ».</p>
    <div class="field"><span class="lbl">Qui a payé ?</span>
      <div class="seg">
        <?php foreach ([$A, $B] as $who): ?>
          <label><input type="radio" name="paid_by" value="<?= $who ?>" <?= $who === ($rest !== 0 ? $debtor : $A) ? 'checked' : '' ?>><span><?= h(user_name($who)) ?> → <?= h(user_name(other_party($who))) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="row2">
      <label>Montant (€) <input name="amount" value="<?= $rest ? h(number_format(abs($rest) / 100, 2, ',', '')) : '' ?>" inputmode="decimal" required></label>
      <label>Date <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
    </div>
    <label>Note <input name="note" maxlength="300" placeholder="Virement, chèque, espèces..."></label>
    <button class="btn">Enregistrer</button>
  </form>
</dialog>

<?php if ($nbProp): ?><div class="flash flash-info"><?= $nbProp ?> proposition(s) de <?= h($nameB) ?> à traiter.</div><?php endif; ?>

<?php $total = count($rows); $doneNb = count($doneRows); ?>
<section class="card flush todo" id="todo" data-total="<?= $total ?>" data-done="<?= $doneNb ?>">
  <?php if ($total): ?>
  <div class="todo-head">
    <h2>À valider <span class="count" id="todo-count"><?= count($todoRows) ?></span></h2>
    <?php if ($tickable > 1): ?>
      <form method="post" id="ok-all" data-confirm="Tout valider pour <?= h(month_label($m)) ?> ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="ok_all"><input type="hidden" name="id" value="0">
        <button class="linkbtn">Tout valider</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="progress" aria-hidden="true"><span id="progress-bar"></span></div>
  <?php endif; ?>
  <ul class="lines" id="todo-list"><?php foreach ($todoRows as $e) echo $line($e, true); ?></ul>
  <p class="all-done" id="all-done"<?= $todoRows || !$total ? ' hidden' : '' ?>><span class="all-done-mark">✓</span>Tout est validé pour ce mois</p>
  <?php if (!$total): ?><p class="empty">Rien ce mois-ci.</p><?php endif; ?>
</section>
<?php if ($otherTodo): ?><p class="center"><a href="<?= url('entries', ['todo' => 1]) ?>"><?= $otherTodo ?> ligne(s) à valider sur d'autres mois</a></p><?php endif; ?>

<details class="card flush fold" id="done-fold"<?= $doneRows ? '' : ' hidden' ?>>
  <summary>Déjà validées (<span id="done-count"><?= $doneNb ?></span>)</summary>
  <ul class="lines" id="done-list"><?php foreach ($doneRows as $e) echo $line($e, false); ?></ul>
</details>

<?php foreach ($rows as $e):
    $id = (int)$e['id'];
    $isDep = $e['kind'] === 'depense';
    $bp = (int)$e['part_a_bp'];
    $ls = $isDep ? line_shares($e) : null;
    $disputed = $e['disputed_by'] !== null;
    $cs = $comments[$id] ?? [];
    $on = (bool)$e[$myCol]; ?>
  <dialog class="sheet" id="d<?= $id ?>">
    <button type="button" class="sheet-close" data-close aria-label="Fermer">×</button>
    <div class="fiche" id="e<?= $id ?>">
      <h2 class="fiche-title"><?= h($e['label']) ?><?= $e['receipt'] ? ' 📎' : '' ?></h2>
      <p class="fiche-amt"><?= money((int)$e['amount_cents']) ?></p>
      <p class="muted small-txt"><?= fdate($e['op_date']) ?> · <?= paid_word($e) ?> <?= h(user_name((int)$e['paid_by'])) ?><?= $isDep ? ' · ' . h(split_label($e)) : ' → ' . h(user_name((int)$e['beneficiary'])) ?></p>
      <?php if ($isDep): ?>
        <div class="parts">
          <span><?= h($nameA) ?><strong><?= money($ls['a']) ?></strong></span>
          <span><?= h($nameB) ?><strong><?= money($ls['b']) ?></strong></span>
        </div>
      <?php endif; ?>

      <?php if ($disputed): $dn = user_name((int)$e['disputed_by']); ?>
        <div class="dispute">
          <?= h($dn) ?> n'accepte que <strong><?= money((int)$e['accepted_cents']) ?></strong> sur <?= money((int)$e['amount_cents']) ?><?= $e['dispute_ok'] ? ' (accepté)' : '' ?>.
          <?php if (!$e['dispute_ok'] && ($mot = contest_reason($id))): ?><br>Motif : « <?= h($mot) ?> »<?php endif; ?>
          <?php if (!$e['dispute_ok']): ?>
            <div class="prop-actions">
            <?php if ((int)$e['disputed_by'] === $uid): ?>
              <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost small">Retirer ma contestation</button></form>
            <?php else: ?>
              <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn small">Accepter</button></form>
            <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($isDep && ($pr = pending_proposal($id))): ?>
        <div class="proposal">
          <strong><?= h(user_name((int)$pr['user_id'])) ?> propose</strong> <?= money((int)$pr['amount_cents']) ?> · <?= h(split_label(['part_a_bp' => (int)$pr['part_a_bp'], 'fair_a_bp' => null])) ?><br><span class="muted">« <?= h($pr['reason']) ?> »</span>
          <?php if (is_admin()): ?>
            <div class="prop-actions">
              <form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_accept"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn small">Accepter</button></form>
              <form method="post" class="inline-form ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_refuse"><input type="hidden" name="id" value="<?= $id ?>"><input name="reason" maxlength="500" placeholder="Motif du refus" required><button class="btn btn-ghost small">Refuser</button></form>
            </div>
          <?php elseif ((int)$pr['user_id'] === $uid): ?>
            <div class="prop-actions"><form method="post" class="ok-form"><?= csrf_field() ?><input type="hidden" name="action" value="prop_withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost small">Retirer</button></form></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="sheet-ok" data-sheet-ok="<?= $id ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="ok"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
        <button class="btn <?= $on ? 'btn-ghost' : 'btn-ok' ?>"><?= $on ? 'Remettre à valider' : '✓ Valider' ?></button>
      </form>

      <div class="fiche-block">
        <?php if ($cs): ?>
          <ul class="mini-comments">
            <?php foreach ($cs as $c): ?><li><strong><?= h(user_name((int)$c['user_id'])) ?></strong> <span class="muted">· <?= fdate($c['created_at']) ?></span><br><?= h($c['body']) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <form method="post" class="comment-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>">
          <input name="reason" maxlength="2000" placeholder="Ajouter un commentaire" required aria-label="Commentaire">
          <button class="btn btn-ghost small">Envoyer</button>
        </form>
      </div>

      <?php if (can_adjust($e, $me)): ?>
        <details class="fiche-more">
          <summary><?= is_admin() ? 'Montant et répartition' : 'Proposer un autre montant' ?></summary>
          <form method="post" class="form adjust"<?= is_admin() ? ' data-auto' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= $id ?>">
            <?php if ($e['recurring_id'] && is_admin()): ?><label class="check"><input type="checkbox" name="only_month" value="1"> Seulement ce mois-ci</label><?php endif; ?>
            <label>Montant (€) <input name="amount" value="<?= h(number_format($e['amount_cents'] / 100, 2, ',', '')) ?>" inputmode="decimal" required></label>
            <?php $cur = split_mode_of($bp, null); ?>
            <?= split_chips($cur, $cur === 'custom' ? str_replace('.', ',', (string)($bp / 100)) : '') ?>
            <?php if (!is_admin()): ?><input name="reason" maxlength="500" placeholder="Pourquoi ?" aria-label="Raison" required><?php endif; ?>
            <?php if ($e['recurring_id'] && !is_admin()): ?><label class="check"><input type="checkbox" name="future" value="1"> Aussi les mois suivants</label><?php endif; ?>
            <button class="btn small"><?= is_admin() ? 'Corriger' : 'Envoyer' ?></button>
          </form>
        </details>
      <?php endif; ?>

      <?php if (is_admin()): ?>
        <details class="fiche-more">
          <summary class="danger-txt">Supprimer</summary>
          <div class="del-actions">
            <form method="post" data-confirm="Supprimer « <?= h($e['label']) ?> » de <?= h(month_label($m)) ?> ?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_line"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-warn small"><?= $e['recurring_id'] ? 'Juste ce mois-ci' : 'Supprimer cette ligne' ?></button>
            </form>
            <?php if ($e['recurring_id']): ?>
              <form method="post" data-confirm="Supprimer la charge « <?= h($e['label']) ?> » à partir de <?= h(month_label($m)) ?> ? Elle ne reviendra plus, les mois d'avant ne changent pas.">
                <?= csrf_field() ?><input type="hidden" name="action" value="stop_recurring"><input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-warn small">Ce mois-ci et les suivants</button>
              </form>
            <?php endif; ?>
          </div>
        </details>
      <?php else: ?>
        <?php if ($isDep && !$disputed): ?>
          <details class="fiche-more">
            <summary>Contester</summary>
            <form method="post" class="form adjust">
              <?= csrf_field() ?><input type="hidden" name="action" value="contest"><input type="hidden" name="id" value="<?= $id ?>">
              <label>Montant total que j'accepte (€, 0 = je refuse) <input name="accepted" value="0" inputmode="decimal"></label>
              <input name="reason" maxlength="500" placeholder="Motif" aria-label="Motif" required>
              <button class="btn btn-warn small">Contester</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if (can_cancel($e, $me)): ?>
          <details class="fiche-more">
            <summary class="danger-txt">Annuler</summary>
            <form method="post" class="inline-form" data-confirm="Annuler « <?= h($e['label']) ?> » ?">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel_line"><input type="hidden" name="id" value="<?= $id ?>">
              <input name="reason" maxlength="300" placeholder="Raison" aria-label="Raison" required>
              <button class="btn btn-warn small">Annuler cette ligne</button>
            </form>
          </details>
        <?php endif; ?>
      <?php endif; ?>
      <p class="fiche-link"><a href="<?= url('entry', ['id' => $id]) ?>">Historique complet</a></p>
    </div>
  </dialog>
<?php endforeach; ?>

<details class="card fold-calc">
  <summary>Voir le détail du calcul</summary>
  <div class="tbl-wrap">
  <table class="tbl small">
    <thead><tr><th>Ligne</th><th class="r">Montant</th><th class="r"><?= h($nameA) ?> doit</th><th class="r"><?= h($nameB) ?> doit</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): [$oa, $ob] = line_owed($e); $ls = $e['kind'] === 'depense' ? line_shares($e) : null; ?>
      <tr>
        <td><?= h($e['label']) ?><?= $ls && $ls['litige'] ? ' <em class="ko">(contestée)</em>' : '' ?></td>
        <td class="r"><?= money((int)$e['amount_cents']) ?></td>
        <td class="r"><?= $oa ? money($oa) : '' ?></td>
        <td class="r"><?= $ob ? money($ob) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($carry): ?><tr><td>Report du mois d'avant</td><td class="r"></td><td class="r"><?= $carry > 0 ? money($carry) : '' ?></td><td class="r"><?= $carry < 0 ? money(-$carry) : '' ?></td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td>Totaux</td><td class="r"><?= money($mb['total']) ?></td><td class="r"><?= money($owedA) ?></td><td class="r"><?= money($owedB) ?></td></tr>
    </tfoot>
  </table>
  </div>
  <?php if ($ib !== null): $ov = $mb['over'][$A]; ?>
    <h3>Selon les revenus</h3>
    <p class="muted small-txt">Au prorata (<?= pct($ib) ?> / <?= pct(10000 - $ib) ?>, art. 371-2 du Code civil), <?= h($ov >= 0 ? $nameA : $nameB) ?> prend en charge <strong><?= money(abs($ov)) ?></strong> de plus ce mois-ci, et <strong><?= money(abs($global['over'][$A])) ?></strong> depuis le début (<?= h($global['over'][$A] >= 0 ? $nameA : $nameB) ?>).</p>
  <?php endif; ?>
</details>
<?php if (is_admin()):
    $recapTo = (string)setting('recap_to', '');
    $recapSent = setting('recap_sent_' . $m);
    $nbTodo = count($todoRows); ?>
<section class="card recap">
  <h2>Récap de <?= h(month_label($m)) ?></h2>
  <?php if ($recapSent): ?><p class="muted small-txt">Envoyé le <?= fdate($recapSent, true) ?></p><?php endif; ?>
  <div class="recap-actions">
    <a class="btn btn-ghost" href="<?= url('recap', ['m' => $m]) ?>" target="_blank" rel="noopener">Voir le PDF</a>
    <?php if ($recapTo !== ''): ?>
      <form method="post" action="<?= url('recap', ['m' => $m]) ?>" data-confirm="Envoyer le récap de <?= h(month_label($m)) ?> à <?= h($recapTo) ?> ?<?= $nbTodo ? ' Attention : ' . $nbTodo . ' ligne(s) ne sont pas encore validées.' : '' ?>">
        <?= csrf_field() ?><button class="btn"><?= $recapSent ? 'Renvoyer' : 'Envoyer à ' . h($nameB) ?></button>
      </form>
    <?php else: ?>
      <a class="btn" href="<?= url('settings') ?>">Renseigner l'adresse</a>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php layout_end();
