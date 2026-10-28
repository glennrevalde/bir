<?php
/**
 * Application bootstrap: configuration, error handling, session, autoload.
 * Requires PHP 7.2+ (tested on 8.3) and MySQL 5.5+.
 */

define('GSB_VERSION', '1.0.0');
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);

if (!is_file(BASE_PATH . '/config.php')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "config.php is missing. Copy config.sample.php to config.php.\n");
        exit(1);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title><body style="font-family:sans-serif;max-width:640px;margin:40px auto;line-height:1.5">'
        . '<h1>Setup needed</h1><p>Copy <code>config.sample.php</code> to <code>config.php</code>, set the database credentials and a 64-character <code>app_key</code>, '
        . 'import <code>database/gsmart_bir_clean.sql</code> (or the demo SQL), then reload this page.</p>'
        . '<p><a href="check.php">Run the requirements check</a></p></body>';
    exit;
}

$GLOBALS['GSB_CONFIG'] = require BASE_PATH . '/config.php';

function config($key, $default = null)
{
    $c = $GLOBALS['GSB_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($c) || !array_key_exists($part, $c)) {
            return $default;
        }
        $c = $c[$part];
    }
    return $c;
}

date_default_timezone_set(config('timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');

$libs = array('core', 'Db', 'Money', 'Tin', 'Crypto', 'Auth', 'Ctx', 'Audit', 'View', 'Mailer', 'Totp',
    'Xlsx', 'Csv', 'Pdf', 'Records', 'Imports', 'Specs', 'DatWriter', 'DatReader', 'Datasets', 'Checks',
    'Reports', 'Evidence', 'Certificates', 'Backup', 'Help', 'Workflow', 'Migrations', 'TaxCodes');
foreach ($libs as $lib) {
    require_once APP_PATH . '/lib/' . $lib . '.php';
}

$storage = storage_path();
foreach (array('', '/uploads', '/dat', '/pdf', '/evidence', '/backups', '/logs', '/tmp', '/signatures') as $dir) {
    if (!is_dir($storage . $dir)) {
        @mkdir($storage . $dir, 0775, true);
    }
}
if (!is_file($storage . '/.htaccess')) {
    @file_put_contents($storage . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}
ini_set('error_log', $storage . '/logs/php-error.log');

set_exception_handler(function ($e) {
    // Tell the person what went wrong in plain words, with a reference that matches the log line.
    list($plain, $ref) = explain_failure($e);
    error_log('[GSB] ' . $ref . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = config('debug') ? $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $plain : $plain;
    if (is_ajax()) {
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error' => $msg));
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Request failed</title>'
        . '<body style="font-family:system-ui,Segoe UI,Arial,sans-serif;background:#e6e9ef;color:#191c29;margin:0;padding:40px 16px">'
        . '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #d6dae3;border-radius:12px;padding:22px 24px;box-shadow:0 14px 28px -18px rgba(22,26,44,.45)">'
        . '<h1 style="font-size:20px;margin:0 0 10px">The request could not be completed</h1><p style="white-space:pre-wrap;line-height:1.5;margin:0 0 16px">' . htmlspecialchars($msg) . '</p>'
        . '<p style="margin:0"><a href="index.php" style="color:#2f3478;font-weight:600">Back to the dashboard</a></p></div></body>';
    exit;
});

if (defined('GSB_NO_SESSION')) {
    $_SESSION = array();
} elseif (PHP_SAPI !== 'cli') {
    session_name('GSBSESSID');
    $cookie = array('lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => (bool)config('secure_cookies', false));
    if (PHP_VERSION_ID >= 70300) {
        $cookie['samesite'] = 'Lax';
        session_set_cookie_params($cookie);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $cookie['secure'], true);
    }
    ini_set('session.use_strict_mode', '1');
    session_start();

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
