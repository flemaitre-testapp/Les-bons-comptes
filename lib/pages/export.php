<?php
// Export CSV (format Excel français : point-virgule, BOM UTF-8).
$type = ($_GET['type'] ?? '') === 'journal' ? 'journal' : 'operations';
$A = parties()['A'];
$B = parties()['B'];

audit('export', null, null, ['type' => $type]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="budget-' . $type . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$put = fn(array $row) => fputcsv($out, $row, ';', '"', '');
$num = fn(int $c) => number_format($c / 100, 2, ',', '');

if ($type === 'journal') {
    $put(['N°', 'Date et heure', 'Utilisateur', 'Action', 'Objet', 'Détails', 'IP', 'Empreinte précédente', 'Empreinte']);
    foreach (q('SELECT * FROM audit_log ORDER BY id') as $r) {
        $put([$r['id'], $r['ts'], user_name($r['user_id'] ? (int)$r['user_id'] : null), AUDIT_LABELS[$r['action']] ?? $r['action'],
            $r['entity'] ? $r['entity'] . ' #' . $r['entity_id'] : '', (string)$r['details'], $r['ip'], $r['prev_hash'], $r['hash']]);
    }
    exit;
}

$where = ['1 = 1'];
$args = [];
if (preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? ''))) {
    $where[] = 'substr(op_date, 1, 7) = ?';
    $args[] = $_GET['month'];
}
$put(['N°', 'Date', 'Type', 'Libellé', 'Catégorie', 'Montant', 'Payé par', 'Bénéficiaire',
    'Répartition', 'Part ' . $A['display_name'], 'Part ' . $B['display_name'],
    'Part selon revenus ' . $A['display_name'], 'Part selon revenus ' . $B['display_name'], 'Statut', 'Statut par', 'Statut le',
    'Annulée', 'Raison annulation', 'Remplace n°', 'Saisi par', 'Saisi le', 'Note', 'Justificatif', 'Empreinte']);
foreach (q('SELECT * FROM entries WHERE ' . implode(' AND ', $where) . ' ORDER BY op_date, id', $args) as $e) {
    $isDep = $e['kind'] === 'depense';
    [$pa, $pb] = $isDep ? split_amount((int)$e['amount_cents'], (int)$e['part_a_bp']) : [0, 0];
    [$fa, $fb] = $isDep && $e['fair_a_bp'] !== null ? split_amount((int)$e['amount_cents'], (int)$e['fair_a_bp']) : [null, null];
    $put([
        $e['id'], fdate($e['op_date']), $isDep ? 'Dépense' : 'Remboursement', $e['label'],
        category_name($e['category_id'] ? (int)$e['category_id'] : null), $num((int)$e['amount_cents']),
        user_name((int)$e['paid_by']), $e['beneficiary'] ? user_name((int)$e['beneficiary']) : '',
        $isDep ? split_label($e) : '', $isDep ? $num($pa) : '', $isDep ? $num($pb) : '',
        $fa === null ? '' : $num($fa), $fb === null ? '' : $num($fb),
        ['en_attente' => 'À valider', 'valide' => 'Validée', 'conteste' => 'Contestée'][$e['status']],
        $e['status_by'] ? user_name((int)$e['status_by']) : '', (string)$e['status_at'],
        $e['cancelled'] ? 'Oui' : 'Non', (string)$e['cancel_reason'], (string)$e['replaces'],
        user_name((int)$e['created_by']), $e['created_at'], (string)$e['notes'],
        $e['receipt'] ? 'Oui' : 'Non', $e['content_hash'],
    ]);
}
exit;
