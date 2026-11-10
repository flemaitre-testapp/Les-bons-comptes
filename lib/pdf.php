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

/** Lignes « activités et placements » des enfants : catégorie ou libellé. */
function is_kids_line(array $e, array $catNames): bool
{
    $cat = $e['category_id'] ? ($catNames[(int)$e['category_id']] ?? '') : '';
    return (bool)preg_match('/activit|épargne|epargne|placement|livret/iu', $cat . ' ' . $e['label']);
}

/** PDF récapitulatif d'un mois (YYYY-MM), sur une page si possible. */
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

    $catNames = [];
    foreach (q('SELECT id, name FROM categories') as $c) {
        $catNames[(int)$c['id']] = $c['name'];
    }
    $comments = [];
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $s['rows'])) ?: '0';
    foreach (q("SELECT * FROM comments WHERE entry_id IN ($ids) ORDER BY id") as $c) {
        $comments[(int)$c['entry_id']][] = $c;
    }

    $pdf = new MiniPdf();
    $L = 36.0;
    $R = MiniPdf::W - 36;
    $W = $R - $L;
    $bottom = MiniPdf::H - 52;

    // En-tête
    $pdf->text($L, 40, 'LES BONS COMPTES', 7.5, true, $muted);
    $pdf->text($R, 40, 'Édité le ' . date('d/m/Y'), 7.5, false, $muted, 'R');
    $pdf->text($L, 64, 'Récap de ' . month_label($m), 20, true, $ink);

    // Totaux et lignes par blocs
    $bucket = ['depB' => 0, 'depA' => 0, 'recB' => 0, 'recA' => 0, 'pay' => 0];
    $tot = ['all' => 0, 'a' => 0, 'b' => 0];
    $kids = ['all' => 0, 'a' => 0];
    foreach ($s['rows'] as $e) {
        [$oa, $ob] = line_owed($e);
        if ($e['kind'] !== 'depense') {
            $bucket['pay'] += (int)$e['paid_by'] === $A ? -(int)$e['amount_cents'] : (int)$e['amount_cents'];
            continue;
        }
        $rec = !empty($e['recette']);
        $bucket[($rec ? 'rec' : 'dep') . ((int)$e['paid_by'] === $A ? 'A' : 'B')] += $oa - $ob;
        if (!$rec) {
            $ls = line_shares($e);
            $tot['all'] += (int)$e['amount_cents'];
            $tot['a'] += $ls['a'];
            $tot['b'] += $ls['b'];
            if (is_kids_line($e, $catNames)) {
                $kids['all'] += (int)$e['amount_cents'];
                $kids['a'] += $ls['a'];
            }
        }
    }

    // Bandeau résultat (gauche) + validations (droite)
    $y = 78.0;
    $cardH = 66.0;
    $pdf->roundRect($L, $y, $W, $cardH, 12, $brand);
    $pdf->text($L + 18, $y + 20, 'RESTE À RÉGLER', 7.5, true, [.72, .83, .79]);
    if ($s['rest'] === 0) {
        $pdf->text($L + 18, $y + 48, 'Mois soldé', 24, true, $white);
    } else {
        $big = money(abs($s['rest']));
        $pdf->text($L + 18, $y + 48, $big, 24, true, $white);
        $who = $s['trop'] ? user_name($s['debtor']) . ' a reçu trop, à rendre à ' . user_name($s['creditor'])
            : user_name($s['debtor']) . ' doit cette somme à ' . user_name($s['creditor']);
        $pdf->text($L + 18 + $pdf->width($big, 24, true) + 12, $y + 47, $who, 10, false, [.88, .93, .91]);
    }
    $n = count($s['rows']);
    $okA = count(array_filter($s['rows'], fn($e) => !empty($e['ok_a'])));
    $okB = count(array_filter($s['rows'], fn($e) => !empty($e['ok_b'])));
    $pdf->text($R - 18, $y + 20, 'LIGNES VALIDÉES', 7.5, true, [.72, .83, .79], 'R');
    $pdf->text($R - 18, $y + 36, $nA . ' ' . $okA . '/' . $n, 9.5, true, $white, 'R');
    $pdf->text($R - 18, $y + 50, $nB . ' ' . $okB . '/' . $n, 9.5, true, $white, 'R');
    $y += $cardH + 24;

    // Comment on arrive à ce montant (colonne gauche) + parts (colonne droite)
    $steps = [];
    if ($s['carry']) $steps[] = ['Report du mois précédent', '', $s['carry']];
    if ($bucket['depB']) $steps[] = ['Dépenses payées par ' . $nB, 'part de ' . $nA, $bucket['depB']];
    if ($bucket['depA']) $steps[] = ['Dépenses payées par ' . $nA, 'part de ' . $nB, $bucket['depA']];
    if ($bucket['recB']) $steps[] = ['Argent perçu par ' . $nB, 'part de ' . $nA, $bucket['recB']];
    if ($bucket['recA']) $steps[] = ['Argent perçu par ' . $nA, 'part de ' . $nB, $bucket['recA']];
    if ($bucket['pay']) $steps[] = ['Paiements déjà faits', '', $bucket['pay']];

    $colW = ($W - 16) / 2;
    $lx = $L;
    $rx = $L + $colW + 16;
    $top = $y;
    $pdf->text($lx, $y, 'Comment on arrive à ce montant', 10.5, true, $ink);
    $boxY = $y + 8;
    $rowH = 19.0;
    $boxH = (count($steps) + 1) * $rowH + 14;
    $pdf->roundRect($lx, $boxY, $colW, $boxH, 9, $paper);
    $yy = $boxY + 19;
    foreach ($steps as [$t, $sub, $v]) {
        $owes = $v > 0 ? $A : $B;
        $pdf->text($lx + 11, $yy, $t, 8.5, true, $ink);
        if ($sub !== '') $pdf->text($lx + 11 + $pdf->width($t, 8.5, true) + 4, $yy, '· ' . $sub, 7.5, false, $muted);
        $amt = money(abs($v));
        $pdf->text($lx + $colW - 11, $yy, $amt, 9, true, $col($owes), 'R');
        $pdf->text($lx + $colW - 11 - $pdf->width($amt, 9, true) - 5, $yy, mb_substr(user_name($owes), 0, 1) . ' doit', 7, false, $muted, 'R');
        $pdf->rect($lx + 11, $yy + 6, $colW - 22, .5, $line);
        $yy += $rowH;
    }
    $pdf->text($lx + 11, $yy + 1, 'Résultat', 9, true, $ink);
    if ($s['rest'] !== 0) {
        $final = money(abs($s['rest']));
        $pdf->text($lx + $colW - 11, $yy + 1, $final, 10, true, $col($s['debtor']), 'R');
        $pdf->text($lx + $colW - 11 - $pdf->width($final, 10, true) - 5, $yy + 1, user_name($s['debtor']) . ' doit', 8, true, $ink, 'R');
    } else {
        $pdf->text($lx + $colW - 11, $yy + 1, 'Mois soldé', 9.5, true, $green, 'R');
    }
    $leftEnd = $boxY + $boxH;

    // Colonne droite : part globale + activités et placements
    $yr = $top;
    if ($tot['all'] > 0) {
        $pdf->text($rx, $yr, 'Dépenses du mois : ' . money($tot['all']), 10.5, true, $ink);
        $yr += 12;
        $wa = $colW * $tot['a'] / $tot['all'];
        $pdf->roundRect($rx, $yr, $colW, 10, 5, $cB);
        if ($wa > 0) $pdf->roundRect($rx, $yr, max($wa, 10), 10, 5, $cA);
        if ($wa > 5 && $wa < $colW - 5) $pdf->rect($rx + $wa - 5, $yr, 5, 10, $cA);
        $yr += 24;
        $pdf->circle($rx + 4, $yr - 3, 3.5, $cA);
        $pdf->text($rx + 12, $yr, $nA . ' : ' . money($tot['a']) . ' (' . pct((int)round($tot['a'] * 10000 / $tot['all'])) . ')', 8.5, true, $ink);
        $yr += 14;
        $pdf->circle($rx + 4, $yr - 3, 3.5, $cB);
        $pdf->text($rx + 12, $yr, $nB . ' : ' . money($tot['b']) . ' (' . pct((int)round($tot['b'] * 10000 / $tot['all'])) . ')', 8.5, true, $ink);
        $yr += 14;
        $ib = income_bp();
        if ($ib !== null) {
            $pdf->text($rx, $yr, 'Selon les revenus : ' . $nA . ' ' . pct($ib) . ', ' . $nB . ' ' . pct(10000 - $ib), 7.5, false, $muted);
            $yr += 14;
        }
    }
    if ($kids['all'] > 0) {
        $yr += 4;
        $kp = (int)round($kids['a'] * 10000 / $kids['all']);
        $ibK = income_bp();
        $boxK = $ibK !== null ? 60.0 : 46.0;
        $pdf->roundRect($rx, $yr, $colW, $boxK, 9, [.91, .94, .98]);
        $pdf->text($rx + 11, $yr + 16, 'Activités et placements des enfants', 8.5, true, $ink);
        $pdf->text($rx + 11, $yr + 33, $nA . ' contribue à ' . pct($kp) . ' : ' . money($kids['a']) . ' sur ' . money($kids['all']), 9, true, $cA);
        if ($ibK !== null) {
            $pdf->text($rx + 11, $yr + 48, 'Selon les revenus, sa part serait de ' . pct($ibK) . ' : ' . money((int)round($kids['all'] * $ibK / 10000)) . '.', 7.5, false, $muted);
        }
        $yr += $boxK;
    }
    $y = max($leftEnd, $yr) + 24;

    // Détail
    $groups = [
        'Charges fixes' => fn($e) => $e['kind'] === 'depense' && empty($e['recette']) && $e['recurring_id'],
        'Dépenses ponctuelles' => fn($e) => $e['kind'] === 'depense' && empty($e['recette']) && !$e['recurring_id'],
        'Argent perçu à partager' => fn($e) => $e['kind'] === 'depense' && !empty($e['recette']),
        'Paiements' => fn($e) => $e['kind'] !== 'depense',
    ];
    $xPay = $L + 200;
    $xRule = $L + 268;
    $rTot = $L + 392;
    $rA = $L + 460;
    $rB = $R - 6;
    $rh = 14.0;
    $fs = 8.0;
    $newPage = function () use ($pdf, &$y, $L, $muted, $m) {
        $pdf->addPage();
        $pdf->text($L, 40, 'LES BONS COMPTES · Récap de ' . month_label($m), 7.5, true, $muted);
        $y = 64.0;
    };
    $tableHead = function (string $title, bool $pay) use ($pdf, &$y, $L, $R, $xPay, $xRule, $rTot, $rA, $rB, $ink, $muted, $line, $nA, $nB) {
        $pdf->text($L, $y, $title, 9.5, true, $ink);
        if (!$pay) {
            $pdf->text($xPay, $y, str_starts_with($title, 'Argent') ? 'Perçu par' : 'Payé par', 6.5, true, $muted);
            $pdf->text($xRule, $y, 'Répartition', 6.5, true, $muted);
            $pdf->text($rTot, $y, 'Total', 6.5, true, $muted, 'R');
            $pdf->text($rA, $y, $nA, 6.5, true, $muted, 'R');
            $pdf->text($rB, $y, $nB, 6.5, true, $muted, 'R');
        } else {
            $pdf->text($xPay, $y, 'De', 6.5, true, $muted);
            $pdf->text($rTot, $y, 'À', 6.5, true, $muted, 'R');
            $pdf->text($rB, $y, 'Montant', 6.5, true, $muted, 'R');
        }
        $y += 5;
        $pdf->rect($L, $y, $R - $L, .7, $line);
        $y += 12;
    };

    foreach ($groups as $title => $test) {
        $rows = array_values(array_filter($s['rows'], $test));
        if (!$rows) continue;
        $pay = $title === 'Paiements';
        if ($y > $bottom - 50) $newPage();
        $tableHead($title, $pay);
        $sum = ['t' => 0, 'a' => 0, 'b' => 0];
        foreach ($rows as $i => $e) {
            $cs = $comments[(int)$e['id']] ?? [];
            $need = $rh + count($cs) * 11;
            if ($y + $need > $bottom) {
                $newPage();
                $tableHead($title . ' (suite)', $pay);
            }
            if ($i % 2 === 1) $pdf->rect($L, $y - 10, $R - $L, $need, $paper);
            $who = (int)$e['paid_by'];
            $pdf->circle($xPay + 5, $y - 2.8, 5, $col($who));
            $pdf->text($xPay + 5, $y, mb_substr(user_name($who), 0, 1), 6, true, $white, 'C');
            if ($pay) {
                $pdf->text($L + 6, $y, fdate($e['op_date']), $fs, false, $ink);
                $pdf->text($xPay + 14, $y, user_name($who), $fs, false, $ink);
                $pdf->text($rTot, $y, user_name((int)$e['beneficiary']), $fs, false, $ink, 'R');
                $pdf->text($rB, $y, money((int)$e['amount_cents']), $fs, true, $ink, 'R');
            } else {
                $ls = line_shares($e);
                $lab = $e['label'] . ($ls['litige'] ? ' (contestée)' : '');
                $pdf->text($L + 6, $y, $pdf->fit($lab, $xPay - $L - 12, $fs), $fs, false, $ink);
                $pdf->text($xPay + 14, $y, $pdf->fit(user_name($who), $xRule - $xPay - 18, $fs), $fs, false, $muted);
                $pdf->text($xRule, $y, $pdf->fit(split_label($e), $rTot - $xRule - 48, 7.5), 7.5, false, $muted);
                $pdf->text($rTot, $y, money((int)$e['amount_cents']), $fs, false, $ink, 'R');
                $pdf->text($rA, $y, money($ls['a']), $fs, true, $cA, 'R');
                $pdf->text($rB, $y, money($ls['b']), $fs, true, $cB, 'R');
                $sum['t'] += (int)$e['amount_cents'];
                $sum['a'] += $ls['a'];
                $sum['b'] += $ls['b'];
            }
            foreach ($cs as $c) {
                $y += 11;
                $txt = '« ' . str_replace(["\r", "\n"], ' ', $c['body']) . ' » ' . user_name((int)$c['user_id']) . ', ' . fdate($c['created_at']);
                $pdf->text($L + 14, $y, $pdf->fit($txt, $R - $L - 20, 7), 7, false, $muted);
            }
            $y += $rh;
        }
        if (!$pay && count($rows) > 1) {
            $pdf->rect($L, $y - 9, $R - $L, .7, $line);
            $y += 2;
            $pdf->text($L + 6, $y, 'Total', $fs, true, $ink);
            $pdf->text($rTot, $y, money($sum['t']), $fs, true, $ink, 'R');
            $pdf->text($rA, $y, money($sum['a']), $fs, true, $cA, 'R');
            $pdf->text($rB, $y, money($sum['b']), $fs, true, $cB, 'R');
            $y += 4;
        }
        $y += 18;
    }

    $pdf->eachPage(function (MiniPdf $p, int $i, int $n) use ($L, $R, $muted, $line) {
        $p->rect($L, MiniPdf::H - 36, $R - $L, .5, $line);
        $p->text($L, MiniPdf::H - 24, 'Chaque montant et chaque modification sont inscrits dans le journal de l\'appli Les bons comptes.', 6.5, false, $muted);
        if ($n > 1) $p->text($R, MiniPdf::H - 24, 'Page ' . $i . '/' . $n, 6.5, false, $muted, 'R');
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
