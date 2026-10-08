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

function login_blocked(string $username): bool
{
    $since = time() - FAIL_WINDOW;
    $n = (int)q('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ts > ? AND (ip = ? OR username = ?)',
        [$since, client_ip(), mb_strtolower($username)])->fetchColumn();
    return $n >= MAX_FAILS;
}

function attempt_login(string $username, string $password): bool
{
    q('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400 * 30]);
    $u = q('SELECT * FROM users WHERE username = ? AND active = 1', [$username])->fetch();
    $ok = $u && password_verify($password, $u['password_hash']);
    q('INSERT INTO login_attempts(ip, username, ts, success) VALUES(?, ?, ?, ?)',
        [client_ip(), mb_strtolower($username), time(), $ok ? 1 : 0]);
    if (!$ok) {
        audit('login.fail', 'user', $u ? (int)$u['id'] : null, ['identifiant' => mb_substr($username, 0, 60)]);
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
