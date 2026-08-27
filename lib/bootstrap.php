<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../config/config.php';
if (!is_array($config)) $config = [];
$GLOBALS['app_config'] = $config;

function pcf_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    session_start();
}
function pcf_session_is_required(): bool
{
    if (PHP_SAPI === 'cli') return false;
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $sessionName = session_name();
    $hasSessionCookie = $sessionName !== '' && isset($_COOKIE[$sessionName]);
    if (!str_contains($requestPath, '/admin/') && in_array($scriptName, ['analytics.php', 'ranking_refresh.php'], true)) return false;
    if (!str_contains($requestPath, '/admin/') && $scriptName === 'page_view_beacon.php' && !$hasSessionCookie) return false;
    if ($hasSessionCookie) return true;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return true;
    if (str_contains($requestPath, '/admin/')) return true;
    return in_array($scriptName, ['login0718.php','forgot_password.php','reset_password.php','setup_check.php'], true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionLifetime = (int)($config['security']['session_lifetime'] ?? 86400);
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_name($config['security']['session_name'] ?? 'pinkclub_dl_session');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params(['lifetime'=>$sessionLifetime,'path'=>'/','domain'=>'','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Lax']);
    if (pcf_session_is_required()) pcf_session_start();
}

date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/helpers.php';
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($requestPath, '/admin/') || in_array($scriptName, ['login0718.php','forgot_password.php','reset_password.php','setup_check.php'], true)) header('X-Robots-Tag: noindex, nofollow', true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/setup_guard.php';
setup_guard_bootstrap_installed_marker();
require_once __DIR__ . '/paginator.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mail.php';
