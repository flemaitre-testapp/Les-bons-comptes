<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$cfg = [
    'data_dir'  => APP_ROOT . '/data',
    'app_name'  => 'Budget commun',
    'timezone'  => 'Europe/Paris',
];
if (is_file(APP_ROOT . '/config.local.php')) {
    $local = require APP_ROOT . '/config.local.php';
    if (is_array($local)) {
        $cfg = array_merge($cfg, $local);
    }
}

define('DATA_DIR', rtrim($cfg['data_dir'], '/'));
define('UPLOAD_DIR', DATA_DIR . '/justificatifs');
define('APP_NAME', $cfg['app_name']);
date_default_timezone_set($cfg['timezone']);

foreach ([DATA_DIR, UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}
if (!is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/php-error.log');

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/ledger.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/view.php';

// Session
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('budget_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '28800');
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
