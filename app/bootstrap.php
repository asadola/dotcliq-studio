<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

date_default_timezone_set('Africa/Nairobi');
mb_internal_encoding('UTF-8');

if (!is_file(ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}
require ROOT . '/config.php';

$debug = defined('APP_DEBUG') && APP_DEBUG;
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/services.php';

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[DotCliq] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:system-ui;background:#14030b;color:#fbeff2;display:grid;place-items:center;min-height:100vh;margin:0">'
        . '<div style="max-width:520px;padding:24px;text-align:center"><h2>Something went wrong</h2>'
        . '<p style="color:#b69aa3">The error has been logged. Please go back and try again.</p>'
        . ($debug ? '<pre style="text-align:left;white-space:pre-wrap;background:#25080f;padding:12px;border-radius:8px">' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>' : '')
        . '<a style="color:#f6b900" href="index.php">Back to dashboard</a></div></body>';
});

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('dcsid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Idle timeout: 4 hours
if (!empty($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > 14400) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_seen'] = time();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
