<?php
$me = current_user();
$e = load_entry((int)($_GET['id'] ?? 0));
if (!$e) {
    flash('err', 'Opération introuvable.');
    redirect('entries');
}
$id = (int)$e['id'];
$isValidator = true; // chaque partie valide (ou conteste) pour elle-même
$myOk = (bool)$e[ok_col((int)$me['id'])];

if (is_post()) {
    check_csrf();
    $action = post('action');
    $comment = post('comment');
    if (mb_strlen($comment) > 2000) {
        flash('err', 'Commentaire trop long.');
        redirect('entry', ['id' => $id]);
    }
    db()->beginTransaction();
    if ($action === 'comment' && $comment !== '') {
        q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$id, $me['id'], $comment, now()]);
        audit('entry.comment', 'entry', $id, ['texte' => $comment]);
        flash('ok', 'Commentaire ajouté.');
    } elseif (($action === 'validate' || $action === 'unvalidate') && !$e['cancelled']) {
        if ($action === 'validate' && $e['status'] === 'conteste') {
            q("UPDATE entries SET status = 'en_attente' WHERE id = ?", [$id]);
            $e['status'] = 'en_attente';
        }
        set_ok($e, (int)$me['id'], $action === 'validate');
        if ($comment !== '') {
            q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$id, $me['id'], $comment, now()]);
        }
        flash('ok', $action === 'validate' ? 'Ligne validée.' : 'Validation retirée.');
    } elseif ($action === 'contest' && $isValidator && !$e['cancelled']) {
        if ($comment === '') {
            db()->rollBack();
            flash('err', 'Explique pourquoi tu contestes.');
            redirect('entry', ['id' => $id]);
        }
        $col = ok_col((int)$me['id']);
        q("UPDATE entries SET status = 'conteste', status_by = ?, status_at = ?, $col = 0, {$col}_at = NULL WHERE id = ?", [$me['id'], now(), $id]);
        q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$id, $me['id'], $comment, now()]);
        audit('entry.contest', 'entry', $id, ['motif' => $comment]);
        flash('ok', 'Opération contestée. Le motif est visible par les deux.');
    } elseif ($action === 'cancel' && can_cancel($e, $me)) {
        if ($comment === '') {
            db()->rollBack();
            flash('err', 'Indique la raison de l\'annulation.');
            redirect('entry', ['id' => $id]);
        }
        q('UPDATE entries SET cancelled = 1, cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?',
            [$me['id'], now(), $comment, $id]);
        audit('entry.cancel', 'entry', $id, ['raison' => $comment]);
        flash('ok', 'Opération annulée. Elle reste visible, barrée, avec la raison.');
    }
    db()->commit();
    redirect('entry', ['id' => $id]);
}

$comments = q('SELECT * FROM comments WHERE entry_id = ? ORDER BY id', [$id])->fetchAll();
$history = q("SELECT * FROM audit_log WHERE entity = 'entry' AND entity_id = ? ORDER BY id", [$id])->fetchAll();
$replacedBy = q('SELECT id FROM entries WHERE replaces = ?', [$id])->fetchColumn();
$isDep = $e['kind'] === 'depense';
$A = parties()['A'];
$B = parties()['B'];

layout_start('Opération #' . $id, 'entries');
?>
<p class="back"><a href="<?= url('dashboard', ['m' => substr($e['op_date'], 0, 7)]) ?>">← <?= h(ucfirst(month_label(substr($e['op_date'], 0, 7)))) ?></a></p>
<section class="card<?= $e['cancelled'] ? ' is-cancelled' : '' ?>">
  <div class="card-head">
    <h1><?= h($e['label']) ?></h1>
    <?= status_badge($e) ?>
  </div>
  <p class="big"><?= money((int)$e['amount_cents']) ?></p>
  <ul class="kv">
    <li><span>N°</span><strong>#<?= $id ?></strong></li>
    <li><span>Type</span><strong><?= $isDep ? 'Dépense commune' : 'Remboursement' ?></strong></li>
    <li><span>Date</span><strong><?= fdate($e['op_date']) ?></strong></li>
    <?php if ($isDep): ?>
      <li><span>Payé par</span><strong><?= h(user_name((int)$e['paid_by'])) ?></strong></li>
      <li><span>Catégorie</span><strong><?= h(category_name($e['category_id'] ? (int)$e['category_id'] : null) ?: '(aucune)') ?></strong></li>
      <?php [$pa, $pb] = split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']); ?>
      <li><span>Part de <?= h($A['display_name']) ?></span><strong><?= money($pa) ?> (<?= pct((int)$e['part_a_bp']) ?>)</strong></li>
      <li><span>Part de <?= h($B['display_name']) ?></span><strong><?= money($pb) ?> (<?= pct(10000 - (int)$e['part_a_bp']) ?>)</strong></li>
      <?php if ($e['fair_a_bp'] !== null && (int)$e['fair_a_bp'] !== (int)$e['part_a_bp']): [$fa, $fb] = split_amount((int)$e['amount_cents'], (int)$e['fair_a_bp']); ?>
        <li><span>Selon les revenus, cela aurait été</span><strong><?= h($A['display_name']) ?> <?= money($fa) ?> · <?= h($B['display_name']) ?> <?= money($fb) ?></strong></li>
      <?php endif; ?>
    <?php else: ?>
      <li><span>Versé par</span><strong><?= h(user_name((int)$e['paid_by'])) ?></strong></li>
      <li><span>À</span><strong><?= h(user_name((int)$e['beneficiary'])) ?></strong></li>
    <?php endif; ?>
    <li><span>Saisi par</span><strong><?= h(user_name((int)$e['created_by'])) ?>, le <?= fdate($e['created_at'], true) ?></strong></li>
    <?php foreach ([(int)$A['id'] => 'ok_a', (int)$B['id'] => 'ok_b'] as $who => $col): ?>
      <li><span>Validation de <?= h(user_name($who)) ?></span><strong><?= $e[$col] ? '✓ le ' . fdate($e[$col . '_at'], true) : 'en attente' ?></strong></li>
    <?php endforeach; ?>
    <?php if ($e['status'] === 'conteste'): ?>
      <li><span>Contestée par</span><strong><?= h(user_name((int)$e['status_by'])) ?>, le <?= fdate($e['status_at'], true) ?></strong></li>
    <?php endif; ?>
    <?php if ($e['replaces']): ?>
      <li><span>Remplace</span><strong><a href="<?= url('entry', ['id' => $e['replaces']]) ?>">#<?= (int)$e['replaces'] ?></a></strong></li>
    <?php endif; ?>
  </ul>
  <?php if ($e['notes'] !== '' && $e['notes'] !== null): ?><p class="note"><?= nl2br(h($e['notes'])) ?></p><?php endif; ?>
  <?php if ($e['receipt']): ?>
    <p><a class="btn btn-ghost" href="<?= url('file', ['id' => $id]) ?>" target="_blank" rel="noopener">📎 Voir le justificatif<?= $e['receipt_name'] ? ' (' . h($e['receipt_name']) . ')' : '' ?></a></p>
  <?php endif; ?>
  <?php if ($e['cancelled']): ?>
    <div class="flash flash-err">Annulée par <?= h(user_name((int)$e['cancelled_by'])) ?> le <?= fdate($e['cancelled_at'], true) ?> : <?= h($e['cancel_reason']) ?>
      <?php if ($replacedBy): ?> · <a href="<?= url('entry', ['id' => $replacedBy]) ?>">voir l'opération #<?= (int)$replacedBy ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
  <p class="hash" title="Empreinte numérique de l'opération telle que saisie">Empreinte : <code><?= h(substr($e['content_hash'], 0, 16)) ?>…</code></p>
</section>

<?php if (!$e['cancelled'] && ($isValidator || can_cancel($e, $me))): ?>
<section class="card">
  <h2>Actions</h2>
  <?php if ($isValidator): ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Commentaire (obligatoire pour contester)
        <textarea name="comment" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <?php if (!$myOk): ?><button class="btn" name="action" value="validate">✓ Valider</button>
        <?php else: ?><button class="btn btn-ghost" name="action" value="unvalidate">Retirer ma validation</button><?php endif; ?>
        <?php if ($e['status'] !== 'conteste'): ?><button class="btn btn-warn" name="action" value="contest">✗ Contester</button><?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
  <?php if (can_cancel($e, $me)): ?>
    <details class="danger">
      <summary>Corriger ou annuler</summary>
      <?php if ($isDep): ?>
        <p><a class="btn btn-ghost" href="<?= url('new', ['replace' => $id]) ?>">Corriger (crée une nouvelle version)</a></p>
      <?php endif; ?>
      <form method="post" class="form" data-confirm="Annuler cette opération ? Elle restera visible, barrée.">
        <?= csrf_field() ?>
        <label>Raison de l'annulation <input name="comment" maxlength="300" required></label>
        <button class="btn btn-warn" name="action" value="cancel">Annuler l'opération</button>
      </form>
    </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Discussion</h2>
  <?php if ($comments): ?>
    <ul class="comments">
      <?php foreach ($comments as $c): ?>
        <li class="<?= (int)$c['user_id'] === (int)$me['id'] ? 'mine' : '' ?>">
          <span class="c-meta"><?= h(user_name((int)$c['user_id'])) ?> · <?= fdate($c['created_at'], true) ?></span>
          <span class="c-body"><?= nl2br(h($c['body'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><p class="empty">Aucun commentaire.</p><?php endif; ?>
  <form method="post" class="form inline-form">
    <?= csrf_field() ?>
    <input name="comment" maxlength="2000" placeholder="Ajouter un commentaire..." required>
    <button class="btn" name="action" value="comment">Envoyer</button>
  </form>
</section>

<section class="card">
  <h2>Historique</h2>
  <ul class="timeline">
    <?php foreach ($history as $hrow): ?>
      <li><span class="t-date"><?= fdate($hrow['ts'], true) ?></span>
        <span><?= h(AUDIT_LABELS[$hrow['action']] ?? $hrow['action']) ?> par <?= h(user_name($hrow['user_id'] ? (int)$hrow['user_id'] : null)) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php layout_end();
