<?php

declare(strict_types=1);

function normalize_configured_base_url(string $value): string
{
    $normalized = rtrim(trim($value), '/');
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace(
        '#/(index\.php|login\.php|login0718\.php|admin/login\.php|admin(?:/index\.php)?|public(?:/index\.php)?)/*$#i',
        '',
        $normalized
    );
    if (!is_string($normalized)) {
        return '';
    }

    $normalized = rtrim($normalized, '/');
    $parts = parse_url($normalized);
    if (!is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
        || trim((string)$parts['host']) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        return '';
    }

    return $normalized;
}

$configuredBaseUrl = normalize_configured_base_url((string)getenv('BASE_URL'));

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
    return ($normalized === '' || $normalized === '.') ? '' : $normalized;
}

function detect_base_path_from_request_uri(string $requestUri): string
{
    $path = (string)parse_url($requestUri, PHP_URL_PATH);
    if ($path === '' || $path === '/') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    foreach ([
        '#/(?:public|admin)(?:/.*)?$#i',
        '#/index\.php(?:/.*)?$#i',
        '#/[^/]+\.php(?:/.*)?$#i',
    ] as $pattern) {
        $candidate = preg_replace($pattern, '', $normalized);
        if (is_string($candidate) && $candidate !== $normalized) {
            $normalized = $candidate;
            break;
        }
    }

    $normalized = rtrim($normalized, '/');
    return ($normalized === '' || $normalized === '.') ? '' : $normalized;
}

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

    $configuredPath = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    if ($configuredPath !== '' && $configuredPath !== '/') {
        return $trimmed;
    }

    return $trimmed . $detectedPath;
}

function normalize_request_host(string $host): string
{
    $host = trim($host);
    if ($host === '' || preg_match('/[\x00-\x20\x7f\/\\\\?#@]/', $host) === 1) {
        return 'localhost';
    }

    if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/i', $host, $ipv6Parts) === 1) {
        if (filter_var($ipv6Parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return 'localhost';
        }
        if (isset($ipv6Parts[2])) {
            $port = (int)$ipv6Parts[2];
            if ($port < 1 || $port > 65535) {
                return 'localhost';
            }
        }
        return strtolower($host);
    }

    if (preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|[0-9.]+)(?::[0-9]{1,5})?$/i', $host) !== 1) {
        return 'localhost';
    }

    $portSeparator = strrpos($host, ':');
    if ($portSeparator !== false) {
        $port = (int)substr($host, $portSeparator + 1);
        if ($port < 1 || $port > 65535) {
            return 'localhost';
        }
    }

    return strtolower($host);
}

function trusted_fallback_base_url(string $detectedPath): string
{
    $rawHost = normalize_request_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    $parsed = parse_url('http://' . $rawHost);
    $host = is_array($parsed) ? strtolower(trim((string)($parsed['host'] ?? ''), '[]')) : '';
    $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : null;
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

    if ($isLocal) {
        $requestScheme = strtolower(trim((string)($_SERVER['REQUEST_SCHEME'] ?? '')));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $requestScheme === 'https' || $isHttps ? 'https' : 'http';
        $displayHost = $host === '::1' ? '[::1]' : $host;
        if ($port !== null && $port >= 1 && $port <= 65535) {
            $displayHost .= ':' . $port;
        }
        return rtrim($scheme . '://' . $displayHost . $detectedPath, '/');
    }

    return rtrim('https://pinkclub-dl.com' . $detectedPath, '/');
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = detect_base_path($scriptName);
if ($basePath === '') {
    $basePath = detect_base_path_from_request_uri((string)($_SERVER['REQUEST_URI'] ?? ''));
}

$baseUrl = $configuredBaseUrl !== ''
    ? apply_detected_path_to_base_url($configuredBaseUrl, $basePath)
    : trusted_fallback_base_url($basePath);

if (!defined('APP_NAME')) {
    define('APP_NAME', 'PinkClub-DL');
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
$mailConfig = [];
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
        if (is_array($localConfig) && isset($localConfig['mail']) && is_array($localConfig['mail'])) {
            $mailConfig = $localConfig['mail'];
        }
    } catch (Throwable) {
        $GLOBALS['config_local_error'] = 'config.local.php could not be loaded';
    }
}

return [
    'db' => $dbConfig,
    'mail' => $mailConfig,
    'security' => [
        'session_name' => 'pinkclub_dl_session',
    ],
    'duga' => [
        'endpoint' => 'https://affapi.duga.jp/search',
        'banner_id' => '01',
    ],
    'pagination' => [
        'per_page' => 32,
    ],
];
