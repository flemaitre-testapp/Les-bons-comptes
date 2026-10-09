<?php
declare(strict_types=1);

const PW_MIN = 10;
const MAX_FAILS = 6;          // tentatives ratées...
const FAIL_WINDOW = 900;      // ...sur 15 minutes

function has_users(): bool
{
    return (int)q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function current_user(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $row = q('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch();
        if ($row && hash_equals((string)($_SESSION['pwv'] ?? ''), substr($row['password_hash'], -16))) {
            $u = $row;
        } else {
            $_SESSION = [];
        }
    }
    return $u;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login');
    }
    if (time() - ($_SESSION['last_seen'] ?? time()) > 4 * 3600) {
        $_SESSION = [];
        flash('info', 'Session expirée, reconnecte-toi.');
        redirect('login');
    }
    $_SESSION['last_seen'] = time();
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        exit('Accès réservé à l\'administrateur.');
    }
}

function login_blocked(): bool
{
    $since = time() - FAIL_WINDOW;
    $n = (int)q('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ts > ? AND ip = ?',
        [$since, client_ip()])->fetchColumn();
    return $n >= MAX_FAILS;
}

/** Connexion par mot de passe seul : le mot de passe identifie la personne. */
function attempt_login(string $password): bool
{
    q('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400 * 30]);
    $u = null;
    foreach (q('SELECT * FROM users WHERE active = 1')->fetchAll() as $row) {
        if (password_verify($password, $row['password_hash'])) {
            $u = $row;
            break;
        }
    }
    $ok = $u !== null;
    q('INSERT INTO login_attempts(ip, username, ts, success) VALUES(?, ?, ?, ?)',
        [client_ip(), $u['username'] ?? '', time(), $ok ? 1 : 0]);
    if (!$ok) {
        audit('login.fail');
        return false;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [$u['password_hash'], $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pwv'] = substr($u['password_hash'], -16);
    $_SESSION['last_seen'] = time();
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
    audit('login', 'user', (int)$u['id']);
    return true;
}

function set_password(int $uid, string $password, bool $mustChange = false): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    q('UPDATE users SET password_hash = ?, must_change_pw = ? WHERE id = ?', [$hash, $mustChange ? 1 : 0, $uid]);
    if (($_SESSION['uid'] ?? null) === $uid) {
        $_SESSION['pwv'] = substr($hash, -16);
    }
}

/** Les mots de passe doivent rester différents puisqu'ils servent d'identifiant. */
function password_taken(string $pw, int $exceptUid): bool
{
    foreach (q('SELECT password_hash FROM users WHERE id <> ?', [$exceptUid]) as $r) {
        if (password_verify($pw, $r['password_hash'])) return true;
    }
    return false;
}

function password_problem(string $pw, string $confirm): ?string
{
    if (mb_strlen($pw) < PW_MIN) {
        return 'Le mot de passe doit faire au moins ' . PW_MIN . ' caractères.';
    }
    if ($pw !== $confirm) {
        return 'Les deux mots de passe ne correspondent pas.';
    }
    return null;
}

const DEFAULT_CATEGORIES = ['Logement & prêts', 'Assurances', 'Garde & nounou', 'École & cantine', 'Activités enfants',
    'Vêtements & équipement', 'Santé', 'Épargne enfants', 'Abonnements', 'Voyages & sorties',
    'Impôts & taxes', 'Transport', 'Cadeaux', 'Divers'];

/** Crée les comptes prédéfinis (lib/seed.php) au tout premier lancement. */
function seed_if_empty(): void
{
    $file = __DIR__ . '/seed.php';
    if (has_users() || !is_file($file)) {
        return;
    }
    $seed = require $file;
    db()->beginTransaction();
    foreach ($seed['users'] as $u) {
        q('INSERT INTO users(username, display_name, password_hash, role, must_change_pw, created_at) VALUES(?, ?, ?, ?, ?, ?)',
            [$u['username'], $u['display_name'], $u['hash'], $u['role'], (int)$u['must_change_pw'], now()]);
    }
    set_setting('income_a', (string)(int)$seed['income_a']);
    set_setting('income_b', (string)(int)$seed['income_b']);
    set_setting('default_mode', 'half');
    foreach (DEFAULT_CATEGORIES as $i => $c) {
        q('INSERT INTO categories(name, sort) VALUES(?, ?)', [$c, $i]);
    }
    audit('setup', null, null, [
        'comptes' => implode(', ', array_map(fn($u) => $u['display_name'] . ' (' . $u['username'] . ')', $seed['users'])),
        'revenus' => $seed['users'][0]['display_name'] . ' ' . money((int)$seed['income_a']) . ', ' . $seed['users'][1]['display_name'] . ' ' . money((int)$seed['income_b']),
    ]);
    db()->commit();
}

/**
 * Mise à niveau des données (une seule fois) : règle de base 40/60 et
 * charges mensuelles reprises du tableau Excel (payées par le second compte).
 */
function upgrade_data(): void
{
    if (!has_users()) {
        return;
    }
    if ((int)setting('data_v', '1') === 2) {
        upgrade_v3();
    }
    if ((int)setting('data_v', '1') >= 2) {
        return;
    }
    $B = (int)parties()['B']['id'];
    $cat = fn(string $n) => q('SELECT id FROM categories WHERE name = ?', [$n])->fetchColumn() ?: null;
    db()->beginTransaction();
    if (setting('base_part_a_bp') === null) {
        set_setting('base_part_a_bp', '4000');
    }
    set_setting('default_mode', 'base');
    if (!(int)q('SELECT COUNT(*) FROM recurring')->fetchColumn()) {
        $rows = [
            // libellé, montant total (€ cents), part de A, catégorie
            ['Prêt conso (baptême Aurore)', 17500, 4000, 'Logement & prêts'],
            ['Assurance habitation', 9445, 4000, 'Assurances'],
            ['Reste à charge nounou', 10000, 4000, 'Garde & nounou'],
            ['Épargne filles', 6000, 4000, 'Épargne enfants'],
            ['Activité Rose', 2700, 4000, 'Activités enfants'],
            ['Activité Louise', 4500, 4000, 'Activités enfants'],
            ['Taxe foncière', 15600, 4000, 'Impôts & taxes'],
            ['Deezer', 999, 4000, 'Abonnements'],
            ['Assurance GAV', 3332, 4000, 'Assurances'],
            ['Deezer Nico', 999, 10000, 'Abonnements'],
            ['Moto', 2929, 10000, 'Transport'],
            ['Assurance emprunteur', 1848, 10000, 'Assurances'],
        ];
        foreach ($rows as [$label, $amt, $bp, $c]) {
            q("INSERT INTO recurring(label, category_id, amount_cents, paid_by, mode, part_a_bp, day_of_month, created_at) VALUES(?, ?, ?, ?, 'custom', ?, 1, ?)",
                [$label, $cat($c), $amt, $B, $bp, now()]);
        }
        audit('recurring.create', null, null, ['source' => 'reprise du tableau Excel', 'nombre' => count($rows)]);
    }
    set_setting('data_v', '2');
    audit('settings.update', null, null, ['regle_de_base' => rule_label(base_bp())]);
    db()->commit();
    upgrade_v3();
}

/** v3 : le mot de passe du second compte est fixé par l'administrateur (celui de lib/seed.php). */
function upgrade_v3(): void
{
    $file = __DIR__ . '/seed.php';
    $seed = is_file($file) ? require $file : null;
    $member = q("SELECT * FROM users WHERE role = 'member' ORDER BY id LIMIT 1")->fetch();
    db()->beginTransaction();
    if ($member && $seed) {
        foreach ($seed['users'] as $su) {
            if ($su['role'] === 'member' && $su['hash'] !== $member['password_hash']) {
                q('UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE id = ?', [$su['hash'], $member['id']]);
                audit('password.reset', 'user', (int)$member['id'], ['compte' => $member['display_name'], 'motif' => 'mot de passe fixé par l\'administrateur']);
            }
        }
        q('UPDATE users SET must_change_pw = 0 WHERE id = ?', [$member['id']]);
    }
    set_setting('data_v', '3');
    db()->commit();
}

/**
 * Reprise des charges d'octobre 2026 telles que fixées par Florian le 09/10/2026.
 * S'exécute une seule fois, à la première connexion de l'administrateur, et passe
 * par les fonctions normales : tout est ajusté (jamais effacé) et inscrit au journal.
 */
function upgrade_oct2026(): void
{
    if (!is_admin() || setting('data_oct2026') === '1') {
        return;
    }
    $A = party_a_id();
    $B = (int)parties()['B']['id'];
    $m = '2026-10';
    $cat = fn(string $n) => q('SELECT id FROM categories WHERE name = ?', [$n])->fetchColumn() ?: null;
    $reason = 'Charges remises à plat par Florian le 09/10/2026';

    // 1. Charges mensuelles : [libellé, anciens libellés, montant, part de Florian, payé par, catégorie]
    $fixed = [
        ['Prêt conso (baptême Aurore)', ['Prêt conso (baptême Aurore)', 'Prêt conso'], 17500, 4000, $B, 'Logement & prêts'],
        ['Reste à charge nounou', [], 10000, 4000, $B, 'Garde & nounou'],
        ['Assurance habitation', [], 9445, 4000, $B, 'Assurances'],
        ['Épargne filles', [], 6000, 5000, $B, 'Épargne enfants'],
        ['Activité Rose', [], 2700, 5000, $B, 'Activités enfants'],
        ['Activité Louise', [], 4500, 5000, $B, 'Activités enfants'],
        ['Taxe foncière', ['Foncier'], 15600, 4000, $B, 'Impôts & taxes'],
        ['Deezer', [], 1999, 5000, $B, 'Abonnements'],
        ['Assurance GAV', [], 3332, 4000, $B, 'Assurances'],
        ['Voyage Rose', [], 8500, 5000, $B, 'Voyages & sorties'],
        ['Moto', [], 2929, 10000, $B, 'Transport'],
        ['Assurance emprunteur', [], 1848, 10000, $B, 'Assurances'],
    ];
    db()->beginTransaction();
    $keep = [];
    foreach ($fixed as [$label, $aliases, $amt, $bp, $payer, $c]) {
        $names = array_values(array_unique(array_merge([$label], $aliases)));
        $in = implode(',', array_fill(0, count($names), '?'));
        $r = q("SELECT * FROM recurring WHERE label IN ($in) ORDER BY active DESC, id LIMIT 1", $names)->fetch();
        $mode = $bp === 5000 ? 'half' : 'custom';
        if ($r) {
            q('UPDATE recurring SET label = ?, amount_cents = ?, part_a_bp = ?, mode = ?, paid_by = ?, active = 1 WHERE id = ?', [$label, $amt, $bp, $mode, $payer, $r['id']]);
            $rid = (int)$r['id'];
        } else {
            q('INSERT INTO recurring(label, category_id, amount_cents, paid_by, mode, part_a_bp, day_of_month, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)',
                [$label, $cat($c), $amt, $payer, $mode, $bp, now()]);
            $rid = (int)db()->lastInsertId();
        }
        $keep[] = $rid;
    }
    // Les autres (Deezer Nico...) sont arrêtées
    $stop = q('SELECT * FROM recurring WHERE active = 1 AND id NOT IN (' . implode(',', $keep) . ')')->fetchAll();
    foreach ($stop as $r) {
        q('UPDATE recurring SET active = 0 WHERE id = ?', [$r['id']]);
    }
    audit('recurring.update', null, null, ['motif' => $reason, 'charges' => count($keep), 'arretees' => implode(', ', array_column($stop, 'label'))]);
    db()->commit();

    // 2. Octobre : lignes générées puis alignées sur les nouvelles valeurs
    generate_recurring($m);
    db()->beginTransaction();
    foreach (q("SELECT * FROM entries WHERE cancelled = 0 AND kind = 'depense' AND recurring_id IS NOT NULL AND period = ?", [$m])->fetchAll() as $e) {
        $r = q('SELECT * FROM recurring WHERE id = ?', [$e['recurring_id']])->fetch();
        if (!$r['active']) {
            q('UPDATE entries SET cancelled = 1, cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?', [$A, now(), $reason . ' : charge retirée', $e['id']]);
            audit('entry.cancel', 'entry', (int)$e['id'], ['raison' => $reason . ' : charge retirée', 'libelle' => $e['label']]);
        } elseif ((int)$e['amount_cents'] !== (int)$r['amount_cents'] || (int)$e['part_a_bp'] !== rec_bp($r)) {
            adjust_entry($e, (int)$r['amount_cents'], rec_bp($r), $reason, false);
        }
    }
    db()->commit();
    generate_recurring($m); // Voyage Rose, nouvelle charge

    // 3. Ponctuels d'octobre, paiement déjà fait, PAJE, coiffeur
    db()->beginTransaction();
    $once = [
        ['Tenue de danse Rose', 2427, 5000, $B, 'Vêtements & équipement', 0, ''],
        ['Cadeau copine Rose', 1000, 5000, $B, 'Cadeaux', 0, ''],
        ['Trajet Paris', 510, 10000, $B, 'Transport', 0, ''],
        ['Coiffeur filles', 1500, 5000, $A, 'Divers', 0, ''],
        ['PAJE (perçue par Julie)', 19816, 5000, $B, 'Divers', 1, 'Allocation de 198,16 € perçue par Julie, à partager 50/50 : 99,08 € reviennent à Florian.'],
    ];
    foreach ($once as [$label, $amt, $bp, $payer, $c, $rec, $note]) {
        $exists = q('SELECT 1 FROM entries WHERE cancelled = 0 AND label = ? AND substr(op_date, 1, 7) = ?', [$label, $m])->fetchColumn();
        if (!$exists) {
            create_entry(['kind' => 'depense', 'op_date' => $m . '-09', 'label' => $label, 'category_id' => $cat($c),
                'amount_cents' => $amt, 'paid_by' => $payer, 'part_a_bp' => $bp, 'notes' => $note ?: 'Saisi le 09/10/2026', 'recette' => $rec]);
        }
    }
    $paid = q("SELECT 1 FROM entries WHERE cancelled = 0 AND kind = 'remboursement' AND period = ? AND amount_cents = 3200", [$m])->fetchColumn();
    if (!$paid) {
        create_entry(['kind' => 'remboursement', 'op_date' => $m . '-09', 'label' => 'Paiement ' . month_label($m),
            'amount_cents' => 3200, 'paid_by' => $A, 'beneficiary' => $B, 'period' => $m,
            'notes' => 'Déjà donné (32 €), enregistré le 09/10/2026.']);
    }
    set_setting('data_oct2026', '1');
    db()->commit();
}
