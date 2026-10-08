<?php
// Relevé simple et imprimable (Imprimer > Enregistrer en PDF).
$A = parties()['A'];
$B = parties()['B'];
$a = (int)$A['id'];
$b = (int)$B['id'];
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-t'));
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = date('Y-m-t');

$per = balance('all', $to, $from);
$closing = balance('all', $to);
$rows = q('SELECT * FROM entries WHERE cancelled = 0 AND op_date BETWEEN ? AND ? ORDER BY op_date, id', [$from, $to])->fetchAll();
$integrity = verify_integrity();
$ib = income_bp();
$incA = (int)setting('income_a', '0');
$incB = (int)setting('income_b', '0');

layout_start('Relevé', 'statement');
?>
<form class="filters no-print" method="get">
  <input type="hidden" name="p" value="statement">
  <label>Du <input type="date" name="from" value="<?= h($from) ?>"></label>
  <label>Au <input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn small">Afficher</button>
  <button type="button" class="btn btn-ghost small" data-print>Imprimer / PDF</button>
</form>

<section class="card statement">
  <h1>Relevé des dépenses communes</h1>
  <p class="muted"><?= h($A['display_name']) ?> et <?= h($B['display_name']) ?> · du <?= fdate($from) ?> au <?= fdate($to) ?> · édité le <?= fdate(now(), true) ?></p>

  <table class="tbl sum">
    <thead><tr><th></th><th class="r"><?= h($A['display_name']) ?></th><th class="r"><?= h($B['display_name']) ?></th></tr></thead>
    <tbody>
      <tr><td>A payé</td><td class="r"><?= money($per['out'][$a]) ?></td><td class="r"><?= money($per['out'][$b]) ?></td></tr>
      <tr><td>Part convenue</td><td class="r"><?= money($per['share'][$a]) ?></td><td class="r"><?= money($per['share'][$b]) ?></td></tr>
      <?php if ($ib !== null): ?>
      <tr><td>Part selon les revenus (<?= pct($ib) ?> / <?= pct(10000 - $ib) ?>)</td><td class="r"><?= money($per['fair'][$a]) ?></td><td class="r"><?= money($per['fair'][$b]) ?></td></tr>
      <tr class="em"><td>Écart avec la part selon les revenus</td><td class="r"><?= money($per['over'][$a], true) ?></td><td class="r"><?= money($per['over'][$b], true) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <p>Total des dépenses communes sur la période : <strong><?= money($per['total']) ?></strong></p>
  <p class="statement-balance">Solde au <?= fdate($to) ?> : <strong><?= h(balance_sentence($closing)) ?></strong></p>
  <?php if ($s = fairness_sentence($per)): ?><p class="statement-balance">Sur la période : <strong><?= h($s) ?></strong></p><?php endif; ?>

  <h2>Détail</h2>
  <?php if ($rows): ?>
  <table class="tbl small">
    <thead><tr><th>Date</th><th>Dépense</th><th>Payé par</th><th>Répartition</th><th class="r">Montant</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): $isDep = $e['kind'] === 'depense'; ?>
      <tr>
        <td><?= fdate($e['op_date']) ?></td>
        <td><?= h($e['label']) ?><?= $e['status'] === 'conteste' ? ' <em>(contestée)</em>' : '' ?></td>
        <td><?= h(user_name((int)$e['paid_by'])) ?><?= $isDep ? '' : ' → ' . h(user_name((int)$e['beneficiary'])) ?></td>
        <td><?= $isDep ? h(split_label($e)) : 'Remboursement' ?></td>
        <td class="r"><?= money((int)$e['amount_cents']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="empty">Aucune opération sur la période.</p><?php endif; ?>

  <p class="hash">
    <?php if ($ib !== null): ?>Part selon les revenus : répartition proportionnelle aux revenus mensuels déclarés (<?= h($A['display_name']) ?> <?= money($incA) ?>, <?= h($B['display_name']) ?> <?= money($incB) ?>), conformément au principe de contribution de chaque parent à proportion de ses ressources (art. 371-2 du Code civil).<br><?php endif; ?>
    Intégrité des données : <?= $integrity['ok'] ? 'vérifiée, aucune modification détectée' : 'ANOMALIE DÉTECTÉE (voir Journal)' ?> · empreinte <code><?= h(substr($integrity['last_hash'], 0, 16)) ?></code></p>
</section>
<?php layout_end();
