<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$routes = [
    'login', 'logout', 'setup', 'dashboard', 'entries', 'entry', 'new', 'transfer',
    'statement', 'export', 'audit', 'account', 'file',
    'settings', 'recurring', 'users',
];
$page = (string)($_GET['p'] ?? 'dashboard');
if (!in_array($page, $routes, true)) {
    $page = 'dashboard';
}

if (!has_users() && $page !== 'setup') {
    redirect('setup');
}
if (!in_array($page, ['login', 'setup'], true)) {
    require_login();
    $me = current_user();
    if ($me['must_change_pw'] && !in_array($page, ['account', 'logout'], true)) {
        flash('info', 'Choisis ton mot de passe personnel pour continuer.');
        redirect('account');
    }
}

try {
    require __DIR__ . '/lib/pages/' . $page . '.php';
} catch (Throwable $ex) {
    error_log((string)$ex);
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    http_response_code(500);
    echo 'Une erreur est survenue. Elle a été enregistrée dans le journal d\'erreurs du serveur.';
}
