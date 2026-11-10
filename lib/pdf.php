<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf_widths.php';

/** Petit générateur PDF sans dépendance : A4, Helvetica, texte et rectangles. */
class MiniPdf
{
    public const W = 595.28;
    public const H = 841.89;
    private array $pages = [];
    private string $cur = '';

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = ' ';
    }

    private static function enc(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}"], ' ', $s);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $out === false ? '' : $out;
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        $t = 0;
        $w = PDF_WIDTHS[$bold ? 'F2' : 'F1'];
        foreach (str_split(self::enc($s)) as $c) {
            if ($c !== '') {
                $t += $w[ord($c)];
            }
        }
        return $t * $size / 1000;
    }

    /** Coupe un texte trop long pour tenir dans une largeur. */
    public function fit(string $s, float $max, float $size, bool $bold = false): string
    {
        if ($this->width($s, $size, $bold) <= $max) {
            return $s;
        }
        while ($s !== '' && $this->width($s . '...', $size, $bold) > $max) {
            $s = mb_substr($s, 0, -1);
        }
        return rtrim($s) . '...';
    }

    /** $y part du haut de la page. $align : L ou R (x = bord droit). */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0], string $align = 'L'): void
    {
        if ($align === 'R') {
            $x -= $this->width($s, $size, $bold);
        } elseif ($align === 'C') {
            $x -= $this->width($s, $size, $bold) / 2;
        }
        $esc = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s));
        $this->cur .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y, $esc);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    /** Rectangle à coins arrondis, rempli. */
    public function roundRect(float $x, float $y, float $w, float $h, float $r, array $rgb): void
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $x1 = $x;
        $x2 = $x + $w;
        $yt = self::H - $y;
        $yb = self::H - $y - $h;
        $this->cur .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2])
            . sprintf("%.2F %.2F m\n", $x1 + $r, $yt)
            . sprintf("%.2F %.2F l\n", $x2 - $r, $yt)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x2 - $r + $k, $yt, $x2, $yt - $r + $k, $x2, $yt - $r)
            . sprintf("%.2F %.2F l\n", $x2, $yb + $r)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x2, $yb + $r - $k, $x2 - $r + $k, $yb, $x2 - $r, $yb)
            . sprintf("%.2F %.2F l\n", $x1 + $r, $yb)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1 + $r - $k, $yb, $x1, $yb + $r - $k, $x1, $yb + $r)
            . sprintf("%.2F %.2F l\n", $x1, $yt - $r)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1, $yt - $r + $k, $x1 + $r - $k, $yt, $x1 + $r, $yt)
            . "f\n";
    }

    public function circle(float $cx, float $cy, float $r, array $rgb): void
    {
        $this->roundRect($cx - $r, $cy - $r, 2 * $r, 2 * $r, $r, $rgb);
    }

    /** Ajoute un contenu (pied de page...) sur chaque page, une fois le document terminé. */
    public function eachPage(callable $fn): void
    {
        $this->pages[] = $this->cur;
        $all = $this->pages;
        $n = count($all);
        foreach ($all as $i => $c) {
            $this->cur = $c;
            $fn($this, $i + 1, $n);
            $all[$i] = $this->cur;
        }
        $this->cur = array_pop($all);
        $this->pages = $all;
    }

    public function output(): string
    {
        $pages = array_merge($this->pages, [$this->cur]);
        $n = count($pages);
        $objs = [];
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + 2 * $i) . ' 0 R';
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pages as $i => $c) {
            $p = 5 + 2 * $i;
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $p + 1);
            $objs[$p + 1] = '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "\nendstream";
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $id => $o) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objs) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        return $out . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

/** PDF récapitulatif d'un mois (YYYY-MM) : le résultat, comment on y arrive, puis le détail. */
function recap_pdf(string $m): string
{
    $s = month_summary($m);
    $A = party_a_id();
    $B = other_party($A);
    $nA = user_name($A);
    $nB = user_name($B);

    $ink = [.11, .14, .13];
    $muted = [.45, .47, .46];
    $line = [.88, .86, .82];
    $paper = [.965, .95, .925];
    $brand = [.12, .23, .20];
    $green = [.18, .49, .31];
    $cA = [.145, .388, .659];
    $cB = [.784, .392, .231];
    $col = fn(int $uid) => $uid === $A ? $cA : $cB;
    $white = [1, 1, 1];

    $pdf = new MiniPdf();
    $L = 42.0;
    $R = MiniPdf::W - 42;
    $W = $R - $L;
    $bottom = MiniPdf::H - 70;

    // En-tête
    $pdf->text($L, 50, 'LES BONS COMPTES', 8, true, $muted);
    $pdf->text($R, 50, 'Édité le ' . date('d/m/Y'), 8, false, $muted, 'R');
    $pdf->text($L, 80, 'Récap de ' . month_label($m), 24, true, $ink);

    // Résultat du mois
    $y = 100.0;
    $pdf->roundRect($L, $y, $W, 104, 14, $brand);
    if ($s['rest'] === 0) {
        $big = 'Mois soldé';
        $who = 'Personne ne doit rien à personne ce mois-ci.';
    } elseif ($s['trop']) {
        $big = money(abs($s['rest']));
        $who = user_name($s['debtor']) . ' a reçu trop et doit rendre cette somme à ' . user_name($s['creditor']) . '.';
    } else {
        $big = money(abs($s['rest']));
        $who = user_name($s['debtor']) . ' doit cette somme à ' . user_name($s['creditor']) . '.';
    }
    $pdf->text($L + 22, $y + 28, 'RESTE À RÉGLER POUR ' . mb_strtoupper(month_label($m)), 8.5, true, [.72, .83, .79]);
    $pdf->text($L + 22, $y + 66, $big, 32, true, $white);
    $pdf->text($L + 22, $y + 88, $who, 10.5, false, [.88, .93, .91]);

    // Validation
    $deps = array_values(array_filter($s['rows'], fn($e) => true));
    $n = count($deps);
    $okA = count(array_filter($deps, fn($e) => !empty($e['ok_a'])));
    $okB = count(array_filter($deps, fn($e) => !empty($e['ok_b'])));
    $y += 122;
    $pdf->text($L, $y, 'Lignes validées : ' . $nA . ' ' . $okA . '/' . $n . '   ·   ' . $nB . ' ' . $okB . '/' . $n, 9, false, $muted);

    // Comment on arrive à ce montant
    $steps = [];
    $bucket = ['depB' => 0, 'depA' => 0, 'recB' => 0, 'recA' => 0, 'pay' => 0];
    $tot = ['all' => 0, 'a' => 0, 'b' => 0];
    foreach ($s['rows'] as $e) {
        [$oa, $ob] = line_owed($e);
        if ($e['kind'] !== 'depense') {
            $bucket['pay'] += (int)$e['paid_by'] === $A ? -(int)$e['amount_cents'] : (int)$e['amount_cents'];
            continue;
        }
        $rec = !empty($e['recette']);
        $payA = (int)$e['paid_by'] === $A;
        $bucket[($rec ? 'rec' : 'dep') . ($payA ? 'A' : 'B')] += $oa - $ob;
        if (!$rec) {
            $ls = line_shares($e);
            $tot['all'] += (int)$e['amount_cents'];
            $tot['a'] += $ls['a'];
            $tot['b'] += $ls['b'];
        }
    }
    if ($s['carry']) $steps[] = ['Report du mois précédent', 'Ce qui restait à régler', $s['carry']];
    if ($bucket['depB']) $steps[] = ['Dépenses payées par ' . $nB, 'Part de ' . $nA . ' à lui rembourser', $bucket['depB']];
    if ($bucket['depA']) $steps[] = ['Dépenses payées par ' . $nA, 'Part de ' . $nB . ' à lui rembourser', $bucket['depA']];
    if ($bucket['recB']) $steps[] = ['Argent perçu par ' . $nB, 'Part qui revient à ' . $nA, $bucket['recB']];
    if ($bucket['recA']) $steps[] = ['Argent perçu par ' . $nA, 'Part qui revient à ' . $nB, $bucket['recA']];
    if ($bucket['pay']) $steps[] = ['Paiements déjà faits', 'Virements, chèques, espèces', $bucket['pay']];

    $y += 34;
    $pdf->text($L, $y, 'Comment on arrive à ce montant', 13, true, $ink);
    $y += 14;
    $rowH = 34.0;
    $boxH = count($steps) * $rowH + 44;
    $pdf->roundRect($L, $y, $W, $boxH, 10, $paper);
    $yy = $y + 22;
    foreach ($steps as $i => [$t, $sub, $v]) {
        $owes = $v > 0 ? $A : $B;
        $pdf->text($L + 16, $yy, $t, 10.5, true, $ink);
        $pdf->text($L + 16, $yy + 13, $sub, 8.5, false, $muted);
        $tag = $owes === $A ? $nA . ' doit' : $nB . ' doit';
        $amt = money(abs($v));
        $pdf->text($R - 16, $yy + 5, $amt, 11.5, true, $col($owes), 'R');
        $pdf->text($R - 16 - $pdf->width($amt, 11.5, true) - 8, $yy + 5, $tag, 8.5, false, $muted, 'R');
        $yy += $rowH;
        $pdf->rect($L + 16, $yy - 13, $W - 32, .6, $line);
    }
    $final = $s['rest'] === 0 ? 'Mois soldé' : money(abs($s['rest']));
    $pdf->text($L + 16, $yy + 4, 'Résultat', 11, true, $ink);
    if ($s['rest'] !== 0) {
        $pdf->text($R - 16, $yy + 4, $final, 13, true, $col($s['debtor']), 'R');
        $pdf->text($R - 16 - $pdf->width($final, 13, true) - 8, $yy + 4, user_name($s['debtor']) . ' doit', 9, true, $ink, 'R');
    } else {
        $pdf->text($R - 16, $yy + 4, $final, 12, true, $green, 'R');
    }
    $y += $boxH + 30;

    // Répartition des dépenses du mois
    if ($tot['all'] > 0) {
        $pdf->text($L, $y, 'Les dépenses du mois : ' . money($tot['all']), 13, true, $ink);
        $y += 14;
        $wa = $W * $tot['a'] / $tot['all'];
        $pdf->roundRect($L, $y, $W, 14, 7, $cB);
        if ($wa > 0) $pdf->roundRect($L, $y, max($wa, 14), 14, 7, $cA);
        if ($wa > 7 && $wa < $W - 7) $pdf->rect($L + $wa - 7, $y, 7, 14, $cA);
        $y += 30;
        $pdf->circle($L + 5, $y - 3.5, 4.5, $cA);
        $pdf->text($L + 15, $y, 'Part de ' . $nA . ' : ' . money($tot['a']) . ' (' . pct((int)round($tot['a'] * 10000 / $tot['all'])) . ')', 9.5, false, $ink);
        $pdf->circle($L + $W / 2 + 5, $y - 3.5, 4.5, $cB);
        $pdf->text($L + $W / 2 + 15, $y, 'Part de ' . $nB . ' : ' . money($tot['b']) . ' (' . pct((int)round($tot['b'] * 10000 / $tot['all'])) . ')', 9.5, false, $ink);
        $y += 34;
    }

    // Détail ligne par ligne
    $groups = [
        'Charges fixes' => fn($e) => $e['kind'] === 'depense' && empty($e['recette']) && $e['recurring_id'],
        'Dépenses ponctuelles' => fn($e) => $e['kind'] === 'depense' && empty($e['recette']) && !$e['recurring_id'],
        'Argent perçu à partager' => fn($e) => $e['kind'] === 'depense' && !empty($e['recette']),
        'Paiements' => fn($e) => $e['kind'] !== 'depense',
    ];
    $xPay = $L + 196;
    $xRule = $L + 270;
    $rTot = $L + 384;
    $rA = $L + 448;
    $rB = $R - 8;
    $newPage = function () use ($pdf, &$y, $L, $muted, $m) {
        $pdf->addPage();
        $pdf->text($L, 50, 'LES BONS COMPTES · Récap de ' . month_label($m), 8, true, $muted);
        $y = 82.0;
    };
    $tableHead = function (string $title, bool $pay) use ($pdf, &$y, $L, $R, $xPay, $xRule, $rTot, $rA, $rB, $ink, $muted, $line, $nA, $nB) {
        $pdf->text($L, $y, $title, 11.5, true, $ink);
        $y += 18;
        $pdf->text($L + 8, $y, $pay ? 'Date' : 'Libellé', 7.5, true, $muted);
        $pdf->text($xPay, $y, $pay ? 'De' : (str_starts_with($title, 'Argent') ? 'Perçu par' : 'Payé par'), 7.5, true, $muted);
        if (!$pay) {
            $pdf->text($xRule, $y, 'Répartition', 7.5, true, $muted);
            $pdf->text($rTot, $y, 'Total', 7.5, true, $muted, 'R');
            $pdf->text($rA, $y, $nA, 7.5, true, $muted, 'R');
            $pdf->text($rB, $y, $nB, 7.5, true, $muted, 'R');
        } else {
            $pdf->text($rTot, $y, 'À', 7.5, true, $muted, 'R');
            $pdf->text($rB, $y, 'Montant', 7.5, true, $muted, 'R');
        }
        $y += 6;
        $pdf->rect($L, $y, $R - $L, .8, $line);
        $y += 15;
    };

    $first = true;
    foreach ($groups as $title => $test) {
        $rows = array_values(array_filter($s['rows'], $test));
        if (!$rows) continue;
        $pay = $title === 'Paiements';
        if ($first) {
            $newPage();
            $pdf->text($L, $y - 6, 'Le détail', 16, true, $ink);
            $y += 22;
            $first = false;
        } elseif ($y > $bottom - 80) {
            $newPage();
        }
        $tableHead($title, $pay);
        $sum = ['t' => 0, 'a' => 0, 'b' => 0];
        foreach ($rows as $i => $e) {
            if ($y > $bottom - 10) {
                $newPage();
                $tableHead($title . ' (suite)', $pay);
            }
            if ($i % 2 === 1) $pdf->rect($L, $y - 12, $R - $L, 19, $paper);
            $who = (int)$e['paid_by'];
            $pdf->circle($xPay + 6, $y - 3, 6, $col($who));
            $pdf->text($xPay + 6, $y, mb_substr(user_name($who), 0, 1), 7, true, $white, 'C');
            if ($pay) {
                $pdf->text($L + 8, $y, fdate($e['op_date']), 9, false, $ink);
                $pdf->text($xPay + 17, $y, user_name($who), 9, false, $ink);
                $pdf->text($rTot, $y, user_name((int)$e['beneficiary']), 9, false, $ink, 'R');
                $pdf->text($rB, $y, money((int)$e['amount_cents']), 9, true, $ink, 'R');
            } else {
                $ls = line_shares($e);
                $lab = $e['label'] . ($ls['litige'] ? ' (contestée)' : '');
                $pdf->text($L + 8, $y, $pdf->fit($lab, $xPay - $L - 16, 9), 9, false, $ink);
                $pdf->text($xPay + 17, $y, $pdf->fit(user_name($who), $xRule - $xPay - 22, 9), 9, false, $muted);
                $pdf->text($xRule, $y, $pdf->fit(split_label($e), $rTot - $xRule - 52, 8.5), 8.5, false, $muted);
                $pdf->text($rTot, $y, money((int)$e['amount_cents']), 9, false, $ink, 'R');
                $pdf->text($rA, $y, money($ls['a']), 9, true, $cA, 'R');
                $pdf->text($rB, $y, money($ls['b']), 9, true, $cB, 'R');
                $sum['t'] += (int)$e['amount_cents'];
                $sum['a'] += $ls['a'];
                $sum['b'] += $ls['b'];
            }
            $y += 19;
        }
        if (!$pay && count($rows) > 1) {
            $pdf->rect($L, $y - 12, $R - $L, .8, $line);
            $y += 4;
            $pdf->text($L + 8, $y, 'Total', 9, true, $ink);
            $pdf->text($rTot, $y, money($sum['t']), 9, true, $ink, 'R');
            $pdf->text($rA, $y, money($sum['a']), 9, true, $cA, 'R');
            $pdf->text($rB, $y, money($sum['b']), 9, true, $cB, 'R');
            $y += 8;
        }
        $y += 26;
    }

    // Pied de page
    $pdf->eachPage(function (MiniPdf $p, int $i, int $n) use ($L, $R, $muted, $line, $m) {
        $p->rect($L, MiniPdf::H - 46, $R - $L, .6, $line);
        $p->text($L, MiniPdf::H - 32, 'Chaque montant et chaque modification sont inscrits dans le journal de l\'appli Les bons comptes.', 7.5, false, $muted);
        $p->text($R, MiniPdf::H - 32, 'Page ' . $i . '/' . $n, 7.5, false, $muted, 'R');
    });
    return $pdf->output();
}

/** Envoie le récap par mail. Le texte est le même chaque mois. */
function send_recap(string $m, string $to, string $cc): bool
{
    $B = user_name(other_party(party_a_id()));
    $A = user_name(party_a_id());
    $label = month_label($m);
    $subject = 'Récap budget ' . $label;
    $body = "Bonjour $B,\n\nTu trouveras en pièce jointe le récapitulatif de $label.\n\nBonne journée,\n$A\n";
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $from = 'budget@' . $host;
    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'From: =?UTF-8?B?' . base64_encode($A) . "?= <$from>",
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    if ($cc !== '') {
        $headers[] = 'Cc: ' . $cc;
        $headers[] = 'Reply-To: ' . $cc;
    }
    $file = 'recap-' . $m . '.pdf';
    $msg = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body))
        . "--$boundary\r\nContent-Type: application/pdf; name=\"$file\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$file\"\r\n\r\n"
        . chunk_split(base64_encode(recap_pdf($m)))
        . "--$boundary--\r\n";
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $msg, implode("\r\n", $headers), '-f' . $from);
}
