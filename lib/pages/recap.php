<?php
// Récap mensuel : aperçu du PDF (GET) et envoi par mail (POST). Réservé à l'administrateur.
require_admin();
$m = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
    $m = date('Y-m');
}

if (is_post()) {
    check_csrf();
    $to = (string)setting('recap_to', '');
    $cc = (string)setting('recap_cc', '');
    if ($to === '') {
        flash('err', 'Renseigne d\'abord l\'adresse dans Admin.');
    } elseif (send_recap($m, $to, $cc)) {
        set_setting('recap_sent_' . $m, now());
        audit('recap.send', null, null, ['mois' => month_label($m), 'a' => $to, 'copie' => $cc]);
        flash('ok', 'Récap de ' . month_label($m) . ' envoyé à ' . $to . '.');
    } else {
        flash('err', 'L\'envoi a échoué. Rien n\'est parti.');
    }
    redirect('dashboard', ['m' => $m]);
}

$pdf = recap_pdf($m);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="recap-' . $m . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
