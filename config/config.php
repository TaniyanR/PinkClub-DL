<?php

declare(strict_types=1);

$configuredBaseUrl = trim((string) getenv('BASE_URL'));

function normalize_configured_base_url(string $value): string
{
    $normalized = rtrim(trim($value), '/');
    if ($normalized === '') {
        return '';
    }

    // Strip common entry points and folders if someone accidentally sets BASE_URL to them.
    // Examples:
    // - https://example.com/index.php            => https://example.com
    // - https://example.com/public/index.php     => https://example.com
    // - https://example.com/admin               => https://example.com
    // - https://example.com/admin/index.php      => https://example.com
    // - https://example.com/public              => https://example.com
    // - https://example.com/login0718.php        => https://example.com
    $normalized = preg_replace(
        '#/(index\.php|login\.php|login0718\.php|admin/login\.php|admin(?:/index\.php)?|public(?:/index\.php)?)/*$#i',
        '',
        $normalized
    );
    if (!is_string($normalized)) {
        return '';
    }

    return rtrim($normalized, '/');
}

/**
 * Resolve application base path from the current script location.
 *
 * Examples:
 * - /pinkclub-fd/public/index.php                => /pinkclub-fd
 * - /pinkclub-fd/admin/index.php                 => /pinkclub-fd
 */
function detect_base_path(string $scriptName): string
{
    $normalized = str_replace('\\', '/', $scriptName);
    if ($normalized === '' || $normalized === '/') {
        return '';
    }

    $patterns = [
        '#/(?:public|admin)(?:/.*)?$#i',
        '#/index\.php(?:/.*)?$#i',
        '#/[^/]+\.php(?:/.*)?$#i',
    ];

    foreach ($patterns as $pattern) {
        $candidate = preg_replace($pattern, '', $normalized);
        if (is_string($candidate) && $candidate !== $normalized) {
            $normalized = $candidate;
            break;
        }
    }

    $normalized = rtrim($normalized, '/');
    if ($normalized === '' || $normalized === '.') {
        return '';
    }

    return $normalized;
}

/**
 * Some servers expose SCRIPT_NAME as `/index.php` even when the app runs from a
 * subdirectory (e.g. `/pinkclub-fd/public/`). In that case infer the base
 * path from REQUEST_URI.
 */
function detect_base_path_from_request_uri(string $requestUri): string
{
    $path = (string) parse_url($requestUri, PHP_URL_PATH);
    if ($path === '' || $path === '/') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    $patterns = [
        '#/(?:public|admin)(?:/.*)?$#i',
        '#/index\.php(?:/.*)?$#i',
        '#/[^/]+\.php(?:/.*)?$#i',
    ];

    foreach ($patterns as $pattern) {
        $candidate = preg_replace($pattern, '', $normalized);
        if (is_string($candidate) && $candidate !== $normalized) {
            $normalized = $candidate;
            break;
        }
    }

    $normalized = rtrim($normalized, '/');
    if ($normalized === '' || $normalized === '.') {
        return '';
    }

    return $normalized;
}

/**
 * If BASE_URL is configured without a path (e.g. https://example.com),
 * and we detected the app is running under a subdirectory (e.g. /pinkclub-fd),
 * append the detected path. If BASE_URL already contains a non-root path,
 * do not modify it.
 */
function apply_detected_path_to_base_url(string $configuredUrl, string $detectedPath): string
{
    $trimmed = rtrim($configuredUrl, '/');
    if ($trimmed === '' || $detectedPath === '') {
        return $trimmed;
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return $trimmed;
    }

    $configuredPath = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
    if ($configuredPath !== '' && $configuredPath !== '/') {
        return $trimmed; // already has a path
    }

    return $trimmed . $detectedPath;
}

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = detect_base_path($scriptName);
if ($basePath === '') {
    $basePath = detect_base_path_from_request_uri((string) ($_SERVER['REQUEST_URI'] ?? ''));
}

if ($configuredBaseUrl !== '') {
    $baseUrl = apply_detected_path_to_base_url(
        normalize_configured_base_url($configuredBaseUrl),
        $basePath
    );
} else {
    $requestScheme = trim((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($requestScheme !== '') {
        $scheme = $requestScheme;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl = rtrim("{$scheme}://{$host}{$basePath}", '/');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'PinkClub-FD');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}
if (!defined('LOGIN_PATH')) {
    define('LOGIN_PATH', '/public/login0718.php');
}
if (!defined('ADMIN_HOME_PATH')) {
    define('ADMIN_HOME_PATH', '/admin/index.php');
}

$dbConfig = [
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => '',
    'user' => '',
    'pass' => '',
    'charset' => 'utf8mb4',
];
$localConfigPath = __DIR__ . '/../config.local.php';
if (is_file($localConfigPath)) {
    try {
        $localConfig = require $localConfigPath;
        if (is_array($localConfig) && isset($localConfig['db']) && is_array($localConfig['db'])) {
            $localDbConfig = $localConfig['db'];
            if (!isset($localDbConfig['dbname']) && isset($localDbConfig['name'])) {
                $localDbConfig['dbname'] = $localDbConfig['name'];
            }
            if (!isset($localDbConfig['pass']) && isset($localDbConfig['password'])) {
                $localDbConfig['pass'] = $localDbConfig['password'];
            }
            $dbConfig = array_replace($dbConfig, array_intersect_key($localDbConfig, $dbConfig));
        }
    } catch (Throwable $e) {
        $GLOBALS['config_local_error'] = $e->getMessage();
    }
}

return [
    'db' => $dbConfig,
    'security' => [
        'session_name' => 'pinkclub_duga_session',
    ],
    'duga' => [
        'endpoint' => 'https://api.duga.jp/search',
        'banner_id' => '01',
    ],
    'pagination' => [
        'per_page' => 32,
    ],
];
