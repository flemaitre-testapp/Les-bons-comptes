<?php
declare(strict_types=1);

/** Les deux parties : A = admin (créé en premier), B = l'autre. */
function parties(): array
{
    static $p = null;
    if ($p === null) {
        $rows = q("SELECT * FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, id LIMIT 2")->fetchAll();
        $p = ['A' => $rows[0] ?? null, 'B' => $rows[1] ?? null];
    }
    return $p;
}

function party_a_id(): int
{
    return (int)parties()['A']['id'];
}

function user_name(?int $id): string
{
    foreach (parties() as $u) {
        if ($u && (int)$u['id'] === $id) {
            return $u['display_name'];
        }
    }
    return $id ? 'Utilisateur #' . $id : '';
}

function other_party(int $uid): int
{
    $p = parties();
    return (int)((int)$p['A']['id'] === $uid ? $p['B']['id'] : $p['A']['id']);
}

/** Répartit un montant : [part A, part B] en centimes, sans perte d'un centime. */
function split_amount(int $cents, int $partABp): array
{
    $a = (int)round($cents * $partABp / 10000);
    return [$a, $cents - $a];
}

/**
 * Solde : positif = on doit de l'argent à cette personne.
 * $mode : 'all' (tout sauf annulé), 'valid' (uniquement validé), 'no_contest' (hors contesté)
 */
function balance(string $mode = 'all', ?string $until = null, ?string $from = null): array
{
    $p = parties();
    $A = (int)$p['A']['id'];
    $B = (int)$p['B']['id'];
    $z = [$A => 0, $B => 0];
    $r = ['paid' => $z, 'share' => $z, 'sent' => $z, 'received' => $z, 'fair' => $z, 'total' => 0];
    $incomeBp = income_bp();
    $sql = 'SELECT * FROM entries WHERE cancelled = 0';
    $args = [];
    if ($mode === 'valid') {
        $sql .= " AND status = 'valide'";
    } elseif ($mode === 'no_contest') {
        $sql .= " AND status <> 'conteste'";
    }
    if ($until) {
        $sql .= ' AND op_date <= ?';
        $args[] = $until;
    }
    if ($from) {
        $sql .= ' AND op_date >= ?';
        $args[] = $from;
    }
    foreach (q($sql, $args) as $e) {
        $amt = (int)$e['amount_cents'];
        $payer = (int)$e['paid_by'];
        if ($e['kind'] === 'depense') {
            $r['paid'][$payer] += $amt;
            [$a, $b] = split_amount($amt, (int)$e['part_a_bp']);
            $r['share'][$A] += $a;
            $r['share'][$B] += $b;
            $r['total'] += $amt;
            $fairBp = $e['fair_a_bp'] !== null ? (int)$e['fair_a_bp'] : $incomeBp;
            // Une dépense perso reste à 100 % à son bénéficiaire, revenus ou pas
            if (in_array((int)$e['part_a_bp'], [0, 10000], true)) {
                $fairBp = (int)$e['part_a_bp'];
            }
            if ($fairBp !== null) {
                [$fa, $fb] = split_amount($amt, $fairBp);
                $r['fair'][$A] += $fa;
                $r['fair'][$B] += $fb;
            }
        } else {
            $r['sent'][$payer] += $amt;
            $r['received'][(int)$e['beneficiary']] += $amt;
        }
    }
    $net = [];
    foreach ([$A, $B] as $id) {
        $net[$id] = $r['paid'][$id] + $r['sent'][$id] - $r['share'][$id] - $r['received'][$id];
    }
    $r['net'] = $net;
    // Ce que chacun a réellement sorti de sa poche, et l'écart avec sa part selon les revenus
    foreach ([$A, $B] as $id) {
        $r['out'][$id] = $r['paid'][$id] + $r['sent'][$id] - $r['received'][$id];
        // Écart entre la part convenue et la part qu'imposeraient les revenus
        $r['over'][$id] = $r['share'][$id] - $r['fair'][$id];
    }
    if ($net[$A] > 0) {
        $r['debtor'] = $B; $r['creditor'] = $A; $r['amount'] = $net[$A];
    } elseif ($net[$B] > 0) {
        $r['debtor'] = $A; $r['creditor'] = $B; $r['amount'] = $net[$B];
    } else {
        $r['debtor'] = null; $r['creditor'] = null; $r['amount'] = 0;
    }
    return $r;
}

/** Part de A selon les revenus (basis points), null si revenus non renseignés. */
function income_bp(): ?int
{
    $a = (int)setting('income_a', '0');
    $b = (int)setting('income_b', '0');
    return ($a + $b) > 0 ? (int)round($a * 10000 / ($a + $b)) : null;
}

function income_of(int $uid): int
{
    return (int)setting($uid === party_a_id() ? 'income_a' : 'income_b', '0');
}

/** Règle de base convenue : part de A (basis points). */
function base_bp(): int
{
    return (int)setting('base_part_a_bp', '4000');
}

function rule_label(int $bp): string
{
    return rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',') . '/' . rtrim(rtrim(number_format((10000 - $bp) / 100, 2, ',', ''), '0'), ',');
}

/** Libellé court d'une répartition : "Règle 40/60", "50/50", "Perso Florian"... */
function split_label(array $e): string
{
    $bp = (int)$e['part_a_bp'];
    if ($bp === 10000) return 'Perso ' . user_name(party_a_id());
    if ($bp === 0) return 'Perso ' . user_name((int)parties()['B']['id']);
    if ($bp === base_bp()) return 'Règle ' . rule_label($bp);
    if ($bp === 5000) return '50/50';
    if ($e['fair_a_bp'] !== null && $bp === (int)$e['fair_a_bp']) return 'Selon revenus';
    return user_name(party_a_id()) . ' ' . pct($bp);
}

/** Famille de couleur d'une opération : perso, mensuel commun, ponctuel commun, remboursement. */
function entry_tone(array $e): string
{
    if ($e['kind'] !== 'depense') return 'remb';
    $bp = (int)$e['part_a_bp'];
    if ($bp === 10000 || $bp === 0) return 'perso';
    return $e['recurring_id'] ? 'mensuel' : 'ponctuel';
}

/** Phrase factuelle sur l'écart entre ce que chacun a payé et sa part selon les revenus. */
function fairness_sentence(array $b): ?string
{
    $A = party_a_id();
    if (income_bp() === null || !$b['total']) return null;
    $over = $b['over'][$A];
    if (abs($over) < 100) return 'La règle convenue correspond à la part de chacun selon les revenus.';
    $who = $over > 0 ? $A : (int)parties()['B']['id'];
    return 'Avec la règle convenue, ' . user_name($who) . ' prend en charge ' . money(abs($over)) . ' de plus que sa part selon les revenus.';
}

function balance_sentence(array $b): string
{
    if (!$b['amount']) {
        return 'Comptes à l\'équilibre';
    }
    return user_name($b['debtor']) . ' doit ' . money($b['amount']) . ' à ' . user_name($b['creditor']);
}

/** Crée une opération + sa ligne de journal dans une transaction. Retourne l'id. */
function create_entry(array $d): int
{
    $e = [
        'kind' => $d['kind'],
        'op_date' => $d['op_date'],
        'label' => $d['label'],
        'category_id' => $d['category_id'] ?? null,
        'amount_cents' => (int)$d['amount_cents'],
        'paid_by' => (int)$d['paid_by'],
        'beneficiary' => $d['beneficiary'] ?? null,
        'part_a_bp' => $d['part_a_bp'] ?? null,
        'fair_a_bp' => $d['kind'] === 'depense' ? income_bp() : null,
        'notes' => (string)($d['notes'] ?? ''),
        'receipt' => $d['receipt'] ?? null,
        'receipt_name' => $d['receipt_name'] ?? null,
        'receipt_sha' => $d['receipt_sha'] ?? null,
        'recurring_id' => $d['recurring_id'] ?? null,
        'period' => $d['period'] ?? null,
        'replaces' => $d['replaces'] ?? null,
        'created_by' => (int)$_SESSION['uid'],
        'created_at' => now(),
    ];
    $e['content_hash'] = entry_hash($e);
    // Celui qui saisit valide d'office sa propre ligne (pas pour les charges mensuelles automatiques)
    if (empty($d['recurring_id'])) {
        $e[ok_col((int)$_SESSION['uid'])] = 1;
        $e[ok_col((int)$_SESSION['uid']) . '_at'] = $e['created_at'];
    }
    $cols = array_keys($e);
    q('INSERT INTO entries(' . implode(',', $cols) . ') VALUES(:' . implode(',:', $cols) . ')', $e);
    $id = (int)db()->lastInsertId();
    audit('entry.create', 'entry', $id, [
        'type' => $e['kind'], 'date' => $e['op_date'], 'libelle' => $e['label'],
        'montant' => money($e['amount_cents']), 'payeur' => user_name($e['paid_by']),
        'repartition' => $e['part_a_bp'] === null ? null : split_label($e),
        'remplace' => $e['replaces'], 'hash' => $e['content_hash'],
    ]);
    return $id;
}

function load_entry(int $id): ?array
{
    $e = q('SELECT * FROM entries WHERE id = ?', [$id])->fetch();
    return $e ?: null;
}

/** Qui doit valider : la partie qui n'a pas saisi l'opération. */
function validator_of(array $e): int
{
    return other_party((int)$e['created_by']);
}

function can_cancel(array $e, array $u): bool
{
    return !$e['cancelled'] && ($u['role'] === 'admin' || (int)$e['created_by'] === (int)$u['id']);
}

/** Charges récurrentes actives pas encore générées pour un mois donné (YYYY-MM). */
function pending_recurring(string $ym): array
{
    return q('SELECT r.* FROM recurring r WHERE r.active = 1 AND NOT EXISTS (
        SELECT 1 FROM entries e WHERE e.recurring_id = r.id AND e.period = ? AND e.cancelled = 0
    ) ORDER BY r.day_of_month, r.label', [$ym])->fetchAll();
}

function ok_col(int $uid): string
{
    return $uid === party_a_id() ? 'ok_a' : 'ok_b';
}

/** Coche ou décoche la validation d'une partie et met à jour le statut global. */
function set_ok(array $e, int $uid, bool $on): void
{
    $col = ok_col($uid);
    $e[$col] = $on ? 1 : 0;
    $both = $e['ok_a'] && $e['ok_b'];
    $status = $both ? 'valide' : ($e['status'] === 'conteste' ? 'conteste' : 'en_attente');
    q("UPDATE entries SET $col = ?, {$col}_at = ?, status = ?, status_by = ?, status_at = ? WHERE id = ?",
        [$on ? 1 : 0, $on ? now() : null, $status, $uid, now(), $e['id']]);
    audit($on ? 'entry.validate' : 'entry.unvalidate', 'entry', (int)$e['id'], ['libelle' => $e['label'], 'montant' => money((int)$e['amount_cents'])]);
}

function status_badge(array $e): string
{
    if ($e['cancelled']) {
        return '<span class="badge b-cancel">Annulée</span>';
    }
    return [
        'en_attente' => '<span class="badge b-wait">À valider</span>',
        'valide' => '<span class="badge b-ok">Validée</span>',
        'conteste' => '<span class="badge b-ko">Contestée</span>',
    ][$e['status']];
}

/** Convertit le choix du formulaire en part de A (basis points). */
function resolve_split(string $mode, string $custom): ?int
{
    return match ($mode) {
        'base' => base_bp(),
        'half' => 5000,
        'perso_a' => 10000,
        'perso_b' => 0,
        'income' => income_bp(),
        'custom' => parse_pct($custom),
        default => null,
    };
}

/** Bloc de formulaire "Répartition". */
function split_fields(string $mode, string $custom): string
{
    $A = user_name(party_a_id());
    $B = user_name((int)parties()['B']['id']);
    $base = base_bp();
    $opts = [
        'base' => ['Règle de base : ' . $A . ' ' . pct($base) . ', ' . $B . ' ' . pct(10000 - $base), $base / 100],
        'half' => ['50 / 50', 50],
        'perso_a' => ['Dépense perso de ' . $A . ' (100 % ' . $A . ')', 100],
        'perso_b' => ['Dépense perso de ' . $B . ' (100 % ' . $B . ')', 0],
        'custom' => ['Autre', ''],
    ];
    $html = '<div class="field"><span class="lbl">Répartition</span><div class="seg seg-col">';
    foreach ($opts as $k => [$l, $bp]) {
        $html .= '<label><input type="radio" name="mode" value="' . $k . '"' . ($mode === $k ? ' checked' : '')
            . ' data-bp="' . $bp . '"><span>' . h($l) . '</span></label>';
    }
    $html .= '</div><label class="inline custom-pct"' . ($mode === 'custom' ? '' : ' hidden') . '>Part de ' . h($A)
        . ' (%) <input name="part_a" id="part_a" value="' . h($custom) . '" inputmode="decimal"></label>'
        . '<p class="hint" id="split-preview"></p></div>';
    return $html;
}

/** Retrouve le mode d'une répartition existante. */
function split_mode_of(int $bp, ?int $fairBp): string
{
    return match (true) {
        $bp === base_bp() => 'base',
        $bp === 5000 => 'half',
        $bp === 10000 => 'perso_a',
        $bp === 0 => 'perso_b',
        default => 'custom',
    };
}

function rec_label(string $mode, int $bp): string
{
    return match ($mode) {
        'half' => '50/50',
        'income' => 'selon revenus',
        default => split_label(['part_a_bp' => $bp, 'fair_a_bp' => null]),
    };
}

function rec_bp(array $r): int
{
    return match ($r['mode']) {
        'half' => 5000,
        'income' => income_bp() ?? 5000,
        default => (int)$r['part_a_bp'],
    };
}

/** Ajoute les charges mensuelles d'un mois (YYYY-MM) pas encore saisies. Retourne le nombre ajouté. */
function generate_recurring(string $period): int
{
    $todo = pending_recurring($period);
    if (!$todo) return 0;
    db()->beginTransaction();
    foreach ($todo as $r) {
        $day = min((int)$r['day_of_month'], (int)date('t', strtotime($period . '-01')));
        create_entry([
            'kind' => 'depense',
            'op_date' => sprintf('%s-%02d', $period, $day),
            'label' => $r['label'],
            'category_id' => $r['category_id'],
            'amount_cents' => (int)$r['amount_cents'],
            'paid_by' => (int)$r['paid_by'],
            'part_a_bp' => rec_bp($r),
            'notes' => 'Charge mensuelle ' . month_label($period),
            'recurring_id' => (int)$r['id'],
            'period' => $period,
        ]);
    }
    audit('recurring.generate', null, null, ['mois' => month_label($period), 'nombre' => count($todo)]);
    db()->commit();
    return count($todo);
}
