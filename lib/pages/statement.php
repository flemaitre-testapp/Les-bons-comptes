<?php
// Relevé imprimable (Imprimer > Enregistrer en PDF) sur une période.
$A = parties()['A'];
$B = parties()['B'];
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-t'));
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = date('Y-m-t');

$opening = balance('all', date('Y-m-d', strtotime($from . ' -1 day')));
$closing = balance('all', $to);
$rows = q('SELECT * FROM entries WHERE op_date BETWEEN ? AND ? ORDER BY op_date, id', [$from, $to])->fetchAll();
$integrity = verify_integrity();

$sum = ['dep' => 0, 'paid' => [(int)$A['id'] => 0, (int)$B['id'] => 0], 'share' => [(int)$A['id'] => 0, (int)$B['id'] => 0]];
foreach ($rows as $e) {
    if ($e['cancelled'] || $e['kind'] !== 'depense') continue;
    $sum['dep'] += (int)$e['amount_cents'];
    $sum['paid'][(int)$e['paid_by']] += (int)$e['amount_cents'];
    [$pa, $pb] = split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']);
    $sum['share'][(int)$A['id']] += $pa;
    $sum['share'][(int)$B['id']] += $pb;
}

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
  <p class="muted">Période du <?= fdate($from) ?> au <?= fdate($to) ?> · Édité le <?= fdate(now(), true) ?> par <?= h(current_user()['display_name']) ?></p>
  <p class="muted">Entre <?= h($A['display_name']) ?> et <?= h($B['display_name']) ?>.</p>

  <div class="grid2">
    <div><h2>Solde au <?= fdate(date('Y-m-d', strtotime($from . ' -1 day'))) ?></h2><p><?= h(balance_sentence($opening)) ?></p></div>
    <div><h2>Solde au <?= fdate($to) ?></h2><p><strong><?= h(balance_sentence($closing)) ?></strong></p></div>
  </div>

  <h2>Synthèse de la période</h2>
  <table class="tbl">
    <thead><tr><th></th><th class="r"><?= h($A['display_name']) ?></th><th class="r"><?= h($B['display_name']) ?></th></tr></thead>
    <tbody>
      <tr><td>Dépenses payées</td><td class="r"><?= money($sum['paid'][(int)$A['id']]) ?></td><td class="r"><?= money($sum['paid'][(int)$B['id']]) ?></td></tr>
      <tr><td>Part à sa charge</td><td class="r"><?= money($sum['share'][(int)$A['id']]) ?></td><td class="r"><?= money($sum['share'][(int)$B['id']]) ?></td></tr>
    </tbody>
  </table>
  <p>Total des dépenses communes sur la période : <strong><?= money($sum['dep']) ?></strong></p>

  <h2>Détail des opérations</h2>
  <?php if ($rows): ?>
  <table class="tbl small">
    <thead><tr><th>N°</th><th>Date</th><th>Libellé</th><th>Payé par</th><th class="r">Montant</th><th class="r">Part <?= h($A['display_name']) ?></th><th class="r">Part <?= h($B['display_name']) ?></th><th>Statut</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e):
        $isDep = $e['kind'] === 'depense';
        [$pa, $pb] = $isDep ? split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']) : [0, 0]; ?>
      <tr class="<?= $e['cancelled'] ? 'cancelled' : '' ?>">
        <td>#<?= (int)$e['id'] ?></td>
        <td><?= fdate($e['op_date']) ?></td>
        <td><?= h($e['label']) ?><?= $isDep ? '' : ' <em>(remboursement)</em>' ?><?= $e['receipt'] ? ' 📎' : '' ?></td>
        <td><?= h(user_name((int)$e['paid_by'])) ?><?= $isDep ? '' : ' → ' . h(user_name((int)$e['beneficiary'])) ?></td>
        <td class="r"><?= money((int)$e['amount_cents']) ?></td>
        <td class="r"><?= $isDep ? money($pa) : '' ?></td>
        <td class="r"><?= $isDep ? money($pb) : '' ?></td>
        <td><?= $e['cancelled'] ? 'Annulée' : ['en_attente' => 'À valider', 'valide' => 'Validée', 'conteste' => 'Contestée'][$e['status']] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="empty">Aucune opération sur la période.</p><?php endif; ?>

  <p class="hash">Intégrité des données au moment de l'édition :
    <?= $integrity['ok'] ? 'vérifiée, aucune modification détectée' : 'ANOMALIE DÉTECTÉE (voir Journal)' ?>
    · <?= (int)$integrity['lines'] ?> lignes de journal · empreinte finale <code><?= h(substr($integrity['last_hash'], 0, 24)) ?>…</code></p>
</section>
<?php layout_end();
