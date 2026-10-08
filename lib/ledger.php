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
    $r = ['paid' => $z, 'share' => $z, 'sent' => $z, 'received' => $z, 'fair' => $z, 'litige' => $z, 'total' => 0];
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
            $ls = line_shares($e);
            $r['share'][$A] += $ls['a'];
            $r['share'][$B] += $ls['b'];
            if ($ls['litige']) {
                $r['litige'][(int)$e['disputed_by']] += $ls['litige'];
            }
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

/**
 * Parts effectives d'une dépense. Si une partie conteste, sa part est calculée
 * sur le montant qu'elle accepte ; l'autre partie porte le reste.
 * 'litige' = ce que la contestation retire à la part de celui qui conteste (0 si l'ajustement a été accepté).
 */
function line_shares(array $e): array
{
    $amt = (int)$e['amount_cents'];
    [$a0, $b0] = split_amount($amt, (int)$e['part_a_bp']);
    $r = ['a' => $a0, 'b' => $b0, 'a0' => $a0, 'b0' => $b0, 'litige' => 0, 'adjusted' => false];
    if (($e['disputed_by'] ?? null) !== null && $e['accepted_cents'] !== null) {
        [$aa, $ab] = split_amount((int)$e['accepted_cents'], (int)$e['part_a_bp']);
        if ((int)$e['disputed_by'] === party_a_id()) {
            $r['a'] = $aa;
            $r['b'] = $amt - $aa;
            $diff = $a0 - $aa;
        } else {
            $r['b'] = $ab;
            $r['a'] = $amt - $ab;
            $diff = $b0 - $ab;
        }
        $r['adjusted'] = true;
        $r['litige'] = $e['dispute_ok'] ? 0 : $diff;
    }
    return $r;
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
    if (empty($d['recurring_id']) || !empty($d['auto_ok'])) {
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

/** Peut-on ajuster cette ligne ? Charges mensuelles : les deux. Sinon : auteur ou admin. */
/** L'admin ajuste directement ; l'autre partie peut proposer un ajustement sur toute dépense. */
function can_adjust(array $e, array $u): bool
{
    return !$e['cancelled'] && $e['kind'] === 'depense';
}

function pending_proposal(int $entryId): ?array
{
    $p = q("SELECT * FROM proposals WHERE entry_id = ? AND status = 'en_attente' ORDER BY id DESC LIMIT 1", [$entryId])->fetch();
    return $p ?: null;
}

function propose_adjust(array $e, int $uid, int $amount, int $bp, string $reason, bool $future): void
{
    q("UPDATE proposals SET status = 'retiree', decided_by = ?, decided_at = ? WHERE entry_id = ? AND status = 'en_attente'", [$uid, now(), $e['id']]);
    q('INSERT INTO proposals(entry_id, user_id, amount_cents, part_a_bp, future, reason, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)',
        [$e['id'], $uid, $amount, $bp, $future ? 1 : 0, $reason, now()]);
    $pid = (int)db()->lastInsertId();
    q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)',
        [$e['id'], $uid, 'Propose ' . money($amount) . ' · ' . split_label(['part_a_bp' => $bp, 'fair_a_bp' => null]) . ' : ' . $reason, now()]);
    audit('proposal.create', 'entry', (int)$e['id'], [
        'proposition' => $pid, 'libelle' => $e['label'],
        'avant' => money((int)$e['amount_cents']) . ', ' . split_label($e),
        'propose' => money($amount) . ', ' . split_label(['part_a_bp' => $bp, 'fair_a_bp' => null]) . ($future ? ' (et mois suivants)' : ''),
        'motif' => $reason,
    ]);
}

/** L'admin accepte : la ligne est ajustée et considérée validée par les deux. Retourne l'id de la nouvelle ligne. */
function accept_proposal(array $p, array $e): int
{
    $reason = 'Proposition de ' . user_name((int)$p['user_id']) . ' acceptée : ' . $p['reason'];
    $id = adjust_entry($e, (int)$p['amount_cents'], (int)$p['part_a_bp'], $reason, (bool)$p['future']);
    $col = ok_col((int)$p['user_id']);
    q("UPDATE entries SET $col = 1, {$col}_at = ? WHERE id = ?", [now(), $id]);
    q("UPDATE entries SET status = 'valide' WHERE id = ? AND ok_a = 1 AND ok_b = 1", [$id]);
    q("UPDATE proposals SET status = 'acceptee', decided_by = ?, decided_at = ? WHERE id = ?", [$_SESSION['uid'], now(), $p['id']]);
    audit('proposal.accept', 'entry', (int)$e['id'], ['proposition' => (int)$p['id'], 'nouvelle_ligne' => $id]);
    return $id;
}

function refuse_proposal(array $p, string $note): void
{
    q("UPDATE proposals SET status = 'refusee', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?", [$_SESSION['uid'], now(), $note, $p['id']]);
    q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)',
        [$p['entry_id'], $_SESSION['uid'], 'Proposition refusée : ' . $note, now()]);
    audit('proposal.refuse', 'entry', (int)$p['entry_id'], ['proposition' => (int)$p['id'], 'motif' => $note]);
}

/**
 * Ajuste une dépense (montant et/ou répartition) : l'ancienne version est annulée
 * avec renvoi vers la nouvelle, rien n'est effacé. Option : appliquer aux mois suivants.
 */
function adjust_entry(array $e, int $amount, int $bp, string $reason, bool $future): int
{
    $uid = (int)$_SESSION['uid'];
    q('UPDATE entries SET cancelled = 1, cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?',
        [$uid, now(), 'Ajustée : ' . $reason, $e['id']]);
    $id = create_entry([
        'kind' => 'depense', 'op_date' => $e['op_date'], 'label' => $e['label'], 'category_id' => $e['category_id'],
        'amount_cents' => $amount, 'paid_by' => (int)$e['paid_by'], 'part_a_bp' => $bp, 'notes' => (string)$e['notes'],
        'receipt' => $e['receipt'], 'receipt_name' => $e['receipt_name'], 'receipt_sha' => $e['receipt_sha'],
        'recurring_id' => $e['recurring_id'], 'period' => $e['period'], 'replaces' => (int)$e['id'], 'auto_ok' => true,
    ]);
    q('UPDATE entries SET cancel_reason = ? WHERE id = ?', ['Ajustée par la ligne #' . $id . ' : ' . $reason, $e['id']]);
    audit('entry.cancel', 'entry', (int)$e['id'], [
        'raison' => $reason, 'remplacee_par' => $id,
        'avant' => money((int)$e['amount_cents']) . ', ' . split_label($e),
        'apres' => money($amount) . ', ' . split_label(['part_a_bp' => $bp, 'fair_a_bp' => null]),
    ]);
    q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$id, $uid, $reason, now()]);
    if ($future && $e['recurring_id']) {
        $r = q('SELECT * FROM recurring WHERE id = ?', [$e['recurring_id']])->fetch();
        if ($r) {
            $mode = $bp === 5000 ? 'half' : 'custom';
            q('UPDATE recurring SET amount_cents = ?, part_a_bp = ?, mode = ? WHERE id = ?', [$amount, $bp, $mode, $r['id']]);
            audit('recurring.update', 'recurring', (int)$r['id'], [
                'libelle' => $r['label'],
                'avant' => money((int)$r['amount_cents']) . ', ' . rec_label($r['mode'], (int)$r['part_a_bp']),
                'apres' => money($amount) . ', ' . rec_label($mode, $bp) . ' (mois suivants)',
            ]);
        }
    }
    return $id;
}

/** Contestation chiffrée : la personne indique le montant total qu'elle accepte (0 = refus). */
function contest_entry(array $e, int $uid, int $acceptedCents, string $reason): void
{
    $col = ok_col($uid);
    q("UPDATE entries SET status = 'conteste', status_by = ?, status_at = ?, disputed_by = ?, accepted_cents = ?, dispute_ok = 0,
       $col = 0, {$col}_at = NULL WHERE id = ?", [$uid, now(), $uid, $acceptedCents, $e['id']]);
    q('INSERT INTO comments(entry_id, user_id, body, created_at) VALUES(?, ?, ?, ?)', [$e['id'], $uid, 'Contestation : ' . $reason, now()]);
    audit('entry.contest', 'entry', (int)$e['id'], [
        'libelle' => $e['label'], 'montant' => money((int)$e['amount_cents']),
        'montant_accepte' => money($acceptedCents), 'motif' => $reason,
    ]);
}

function withdraw_contest(array $e, int $uid): void
{
    q("UPDATE entries SET status = 'en_attente', disputed_by = NULL, accepted_cents = NULL, dispute_ok = 0 WHERE id = ?", [$e['id']]);
    audit('entry.contest_withdraw', 'entry', (int)$e['id'], ['libelle' => $e['label']]);
    $e['status'] = 'en_attente';
    set_ok($e, $uid, true);
}

/** L'autre partie accepte l'ajustement demandé : la ligne est validée avec le montant retenu. */
function accept_contest(array $e, int $uid): void
{
    q("UPDATE entries SET dispute_ok = 1, status = 'valide', ok_a = 1, ok_b = 1,
       ok_a_at = COALESCE(ok_a_at, ?), ok_b_at = COALESCE(ok_b_at, ?) WHERE id = ?", [now(), now(), $e['id']]);
    audit('entry.contest_accept', 'entry', (int)$e['id'], ['libelle' => $e['label'], 'montant_retenu' => money((int)$e['accepted_cents'])]);
}

/** Qui doit quoi pour une ligne : [doit A→B, doit B→A] en centimes. */
function line_owed(array $e): array
{
    $A = party_a_id();
    $amt = (int)$e['amount_cents'];
    if ($e['kind'] !== 'depense') {
        // Remboursement : réduit la dette de celui qui verse
        return (int)$e['paid_by'] === $A ? [-$amt, 0] : [0, -$amt];
    }
    $ls = line_shares($e);
    return (int)$e['paid_by'] === $A ? [0, $ls['b']] : [$ls['a'], 0];
}

/** Choix de répartition en boutons (pas de menu déroulant). */
function split_chips(string $current, string $customVal = ''): string
{
    $A = user_name(party_a_id());
    $Bn = user_name((int)parties()['B']['id']);
    $opts = [
        'base' => 'Règle ' . rule_label(base_bp()),
        'half' => '50 / 50',
        'perso_a' => 'Perso ' . $A,
        'perso_b' => 'Perso ' . $Bn,
        'custom' => 'Autre %',
    ];
    $html = '<div class="pills">';
    foreach ($opts as $k => $l) {
        $html .= '<label><input type="radio" name="mode" value="' . $k . '"' . ($current === $k ? ' checked' : '') . '><span>' . h($l) . '</span></label>';
    }
    return $html . '<input name="part_a" class="pill-pct" value="' . h($customVal) . '" placeholder="% ' . h($A) . '" inputmode="decimal" title="Part de ' . h($A) . ' si « Autre »"></div>';
}
