<?php
declare(strict_types=1);

/*
 * Journal chaîné : chaque ligne contient le hash de la précédente.
 * Modifier ou supprimer une ligne après coup casse la chaîne, ce que
 * la page "Journal" détecte et affiche à tout le monde.
 */

const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

function audit_line_hash(array $r): string
{
    return hash('sha256', implode('|', [
        $r['prev_hash'], $r['ts'], (string)$r['user_id'], $r['action'],
        (string)$r['entity'], (string)$r['entity_id'], (string)$r['details'], (string)$r['ip'],
    ]));
}

function audit(string $action, ?string $entity = null, ?int $entityId = null, array $details = []): void
{
    $prev = q('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: GENESIS_HASH;
    $row = [
        'ts' => now(),
        'user_id' => $_SESSION['uid'] ?? null,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'ip' => client_ip(),
        'prev_hash' => $prev,
    ];
    $row['hash'] = audit_line_hash($row);
    q('INSERT INTO audit_log(ts, user_id, action, entity, entity_id, details, ip, prev_hash, hash)
       VALUES(:ts, :user_id, :action, :entity, :entity_id, :details, :ip, :prev_hash, :hash)', $row);
}

/** Empreinte d'une opération au moment de sa création (montant, date, payeur, répartition...). */
function entry_hash(array $e): string
{
    $data = [
        $e['kind'], $e['op_date'], $e['label'], $e['category_id'] === null ? null : (int)$e['category_id'],
        (int)$e['amount_cents'], (int)$e['paid_by'], $e['beneficiary'] === null ? null : (int)$e['beneficiary'],
        $e['part_a_bp'] === null ? null : (int)$e['part_a_bp'],
        $e['fair_a_bp'] === null ? null : (int)$e['fair_a_bp'], (string)$e['notes'],
        (string)$e['receipt_sha'], (int)$e['created_by'], $e['created_at'],
    ];
    if (!empty($e['recette'])) {
        $data[] = 'recette';
    }
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** Vérifie la chaîne du journal et l'empreinte de chaque opération. */
function verify_integrity(): array
{
    $problems = [];
    $prev = GENESIS_HASH;
    $count = 0;
    foreach (q('SELECT * FROM audit_log ORDER BY id') as $r) {
        $count++;
        if ($r['prev_hash'] !== $prev || audit_line_hash($r) !== $r['hash']) {
            $problems[] = 'Journal : la ligne #' . $r['id'] . ' a été modifiée ou une ligne précédente a été supprimée.';
            break;
        }
        $prev = $r['hash'];
    }
    foreach (q('SELECT * FROM entries ORDER BY id') as $e) {
        if (entry_hash($e) !== $e['content_hash']) {
            $problems[] = 'Opération #' . $e['id'] . ' : son contenu ne correspond plus à son empreinte d\'origine.';
        }
        $created = q("SELECT details FROM audit_log WHERE entity = 'entry' AND entity_id = ? AND action = 'entry.create'", [$e['id']])->fetchColumn();
        $d = $created ? json_decode($created, true) : null;
        if (!$d || ($d['hash'] ?? '') !== $e['content_hash']) {
            $problems[] = 'Opération #' . $e['id'] . ' : empreinte absente ou différente dans le journal.';
        }
        if ($e['receipt'] && is_file(UPLOAD_DIR . '/' . $e['receipt'])
            && hash_file('sha256', UPLOAD_DIR . '/' . $e['receipt']) !== $e['receipt_sha']) {
            $problems[] = 'Opération #' . $e['id'] . ' : le justificatif a été remplacé.';
        }
    }
    return ['ok' => !$problems, 'problems' => $problems, 'lines' => $count, 'last_hash' => $prev];
}

const AUDIT_LABELS = [
    'setup' => 'Installation de l\'application',
    'login' => 'Connexion',
    'login.fail' => 'Connexion refusée',
    'logout' => 'Déconnexion',
    'password.change' => 'Changement de mot de passe',
    'password.reset' => 'Mot de passe réinitialisé par l\'admin',
    'user.update' => 'Compte modifié',
    'entry.create' => 'Opération ajoutée',
    'entry.validate' => 'Ligne validée',
    'entry.unvalidate' => 'Validation retirée',
    'entry.contest' => 'Ligne contestée',
    'entry.contest_withdraw' => 'Contestation retirée',
    'entry.contest_accept' => 'Ajustement accepté',
    'proposal.create' => 'Ajustement proposé',
    'proposal.accept' => 'Proposition acceptée',
    'proposal.refuse' => 'Proposition refusée',
    'proposal.withdraw' => 'Proposition retirée',
    'entry.cancel' => 'Opération annulée',
    'entry.comment' => 'Commentaire',
    'recurring.create' => 'Charge récurrente créée',
    'recurring.update' => 'Charge récurrente modifiée',
    'recurring.generate' => 'Charges récurrentes générées',
    'settings.update' => 'Réglages modifiés',
    'income.update' => 'Revenus mis à jour',
    'category.create' => 'Catégorie créée',
    'category.update' => 'Catégorie modifiée',
    'export' => 'Export des données',
];
