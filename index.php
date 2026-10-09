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

seed_if_empty();
upgrade_data();
if (!has_users() && $page !== 'setup') {
    redirect('setup');
}
if (!in_array($page, ['login', 'setup'], true)) {
    require_login();
    $me = current_user();
    try {
        upgrade_oct2026();
    } catch (Throwable $ex) {
        error_log((string)$ex);
        @file_put_contents(DATA_DIR . '/erreurs.log', '[' . date('Y-m-d H:i:s') . '] reprise octobre : ' . $ex . "\n\n", FILE_APPEND);
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }
    try {
        reset_comments_once();
    } catch (Throwable $ex) {
        error_log((string)$ex);
        @file_put_contents(DATA_DIR . '/erreurs.log', '[' . date('Y-m-d H:i:s') . '] remise à zéro des commentaires : ' . $ex . "\n\n", FILE_APPEND);
    }
    if ($me['must_change_pw'] && !in_array($page, ['account', 'logout'], true)) {
        flash('info', 'Choisis ton mot de passe personnel pour continuer.');
        redirect('account');
    }
}

try {
    require __DIR__ . '/lib/pages/' . $page . '.php';
} catch (Throwable $ex) {
    $ref = strtoupper(bin2hex(random_bytes(3)));
    @file_put_contents(DATA_DIR . '/erreurs.log', '[' . date('Y-m-d H:i:s') . "] #$ref " . $ex . "\n\n", FILE_APPEND);
    error_log((string)$ex);
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable $ignored) {
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<p style="font:16px sans-serif;padding:16px">Une erreur est survenue (réf. ' . $ref . ').<br><small style="color:#666">'
        . htmlspecialchars(basename($ex->getFile()) . ':' . $ex->getLine() . ' · ' . $ex->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</small></p>';
}
