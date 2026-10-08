<?php
$p = parties();
$fl = [
    'month' => (string)($_GET['month'] ?? ''),
    'cat' => (string)($_GET['cat'] ?? ''),
    'who' => (string)($_GET['who'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
    'show_cancelled' => (string)($_GET['show_cancelled'] ?? ''),
];

$where = ['1 = 1'];
$args = [];
if (preg_match('/^\d{4}-\d{2}$/', $fl['month'])) {
    $where[] = 'substr(op_date, 1, 7) = ?';
    $args[] = $fl['month'];
}
if ($fl['cat'] === 'remb') {
    $where[] = "kind = 'remboursement'";
} elseif (ctype_digit($fl['cat'])) {
    $where[] = 'category_id = ?';
    $args[] = (int)$fl['cat'];
}
if (ctype_digit($fl['who'])) {
    $where[] = 'paid_by = ?';
    $args[] = (int)$fl['who'];
}
if (in_array($fl['status'], ['en_attente', 'valide', 'conteste'], true)) {
    $where[] = 'status = ? AND cancelled = 0';
    $args[] = $fl['status'];
}
if ($fl['q'] !== '') {
    $where[] = '(label LIKE ? OR notes LIKE ?)';
    $args[] = '%' . $fl['q'] . '%';
    $args[] = '%' . $fl['q'] . '%';
}
if (!empty($_GET['todo'])) {
    $where[] = ok_col((int)current_user()['id']) . ' = 0';
}
if ($fl['show_cancelled'] !== '1') {
    $where[] = 'cancelled = 0';
}
$rows = q('SELECT * FROM entries WHERE ' . implode(' AND ', $where) . ' ORDER BY op_date DESC, id DESC LIMIT 1000', $args)->fetchAll();

$totDep = 0;
$totRemb = 0;
foreach ($rows as $r) {
    if ($r['cancelled']) continue;
    if ($r['kind'] === 'depense') $totDep += (int)$r['amount_cents']; else $totRemb += (int)$r['amount_cents'];
}
$months = q('SELECT DISTINCT substr(op_date, 1, 7) m FROM entries ORDER BY m DESC')->fetchAll(PDO::FETCH_COLUMN);

layout_start('Opérations', 'entries');
?>
<div class="page-head">
  <h1>Dépenses</h1>
  <a class="btn btn-ghost small" href="<?= url('export', array_filter($fl)) ?>">Export CSV</a>
</div>
<form class="filters" method="get">
  <input type="hidden" name="p" value="entries">
  <select name="month"><option value="">Tous les mois</option>
    <?php foreach ($months as $m): ?><option value="<?= h($m) ?>" <?= $fl['month'] === $m ? 'selected' : '' ?>><?= h(ucfirst(month_label($m))) ?></option><?php endforeach; ?>
  </select>
  <input name="q" value="<?= h($fl['q']) ?>" placeholder="Rechercher...">
  <label class="check"><input type="checkbox" name="show_cancelled" value="1" <?= $fl['show_cancelled'] === '1' ? 'checked' : '' ?>> Afficher les annulées</label>
  <button class="btn small">Filtrer</button>
</form>
<p class="totals"><?= count($rows) ?> opération(s) · Dépenses : <strong><?= money($totDep) ?></strong> · Remboursements : <strong><?= money($totRemb) ?></strong></p>
<?= legend() ?>
<section class="card flush">
  <?php if ($rows): ?>
    <div class="list"><?php foreach ($rows as $e) echo entry_row($e); ?></div>
  <?php else: ?><p class="empty">Aucune opération.</p><?php endif; ?>
</section>
<?php layout_end();
