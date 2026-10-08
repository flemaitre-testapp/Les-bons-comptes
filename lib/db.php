<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/budget.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) {
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin','member')),
    active INTEGER NOT NULL DEFAULT 1,
    must_change_pw INTEGER NOT NULL DEFAULT 0,
    pw_reset_notice INTEGER NOT NULL DEFAULT 0,
    last_login_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS recurring (
    id INTEGER PRIMARY KEY,
    label TEXT NOT NULL,
    category_id INTEGER REFERENCES categories(id),
    amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
    paid_by INTEGER NOT NULL REFERENCES users(id),
    part_a_bp INTEGER NOT NULL CHECK (part_a_bp BETWEEN 0 AND 10000),
    day_of_month INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS entries (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('depense','remboursement')),
    op_date TEXT NOT NULL,
    label TEXT NOT NULL,
    category_id INTEGER REFERENCES categories(id),
    amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
    paid_by INTEGER NOT NULL REFERENCES users(id),
    beneficiary INTEGER REFERENCES users(id),
    part_a_bp INTEGER CHECK (part_a_bp IS NULL OR part_a_bp BETWEEN 0 AND 10000),
    notes TEXT,
    receipt TEXT,
    receipt_name TEXT,
    receipt_sha TEXT,
    recurring_id INTEGER REFERENCES recurring(id),
    period TEXT,
    status TEXT NOT NULL DEFAULT 'en_attente' CHECK (status IN ('en_attente','valide','conteste')),
    status_by INTEGER REFERENCES users(id),
    status_at TEXT,
    cancelled INTEGER NOT NULL DEFAULT 0,
    cancelled_by INTEGER REFERENCES users(id),
    cancelled_at TEXT,
    cancel_reason TEXT,
    replaces INTEGER REFERENCES entries(id),
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    content_hash TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_entries_date ON entries(op_date);
CREATE UNIQUE INDEX IF NOT EXISTS ux_recurring_period ON entries(recurring_id, period)
    WHERE recurring_id IS NOT NULL AND cancelled = 0;
CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY,
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY,
    ts TEXT NOT NULL,
    user_id INTEGER,
    action TEXT NOT NULL,
    entity TEXT,
    entity_id INTEGER,
    details TEXT,
    ip TEXT,
    prev_hash TEXT NOT NULL,
    hash TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_audit_entity ON audit_log(entity, entity_id);
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY,
    ip TEXT,
    username TEXT,
    ts INTEGER NOT NULL,
    success INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);
PRAGMA user_version = 1;
SQL);
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function setting(string $k, ?string $default = null): ?string
{
    $v = q('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $k, string $v): void
{
    q('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
}
