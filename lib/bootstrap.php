<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../config/config.php';
if (!is_array($config)) {
    $config = [];
}
$GLOBALS['app_config'] = $config;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionLifetime = (int)($config['security']['session_lifetime'] ?? 86400);
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_name($config['security']['session_name'] ?? 'pinkclub_duga_session');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/helpers.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/paginator.php';
require_once __DIR__ . '/app.php';
