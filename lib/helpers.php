<?php

declare(strict_types=1);

function app_config(): array
{
    $loaded = $GLOBALS['app_config'] ?? null;
    if (is_array($loaded)) {
        return $loaded;
    }

    $config = require __DIR__ . '/../config/config.php';
    $GLOBALS['app_config'] = is_array($config) ? $config : [];

    return $GLOBALS['app_config'];
}

function url_path(string $path): string
{
    return rtrim(BASE_URL, '/') . $path;
}

function app_url(string $path = ''): string
{
    $cleanPath = trim(str_replace(["\r", "\n"], '', $path));
    if ($cleanPath === '') {
        return rtrim(BASE_URL, '/');
    }
    if (str_starts_with($cleanPath, 'http://') || str_starts_with($cleanPath, 'https://')) {
        return $cleanPath;
    }

    return str_starts_with($cleanPath, '/') ? url_path($cleanPath) : url_path('/' . ltrim($cleanPath, '/'));
}

function asset_url(string $path): string
{
    $normalized = ltrim($path, '/');
    $url = rtrim(BASE_URL, '/') . '/assets/' . $normalized;

    $assetPath = __DIR__ . '/../assets/' . $normalized;
    if (!is_file($assetPath)) {
        $assetPath = __DIR__ . '/../public/assets/' . $normalized;
    }
    if (is_file($assetPath)) {
        $version = (string)filemtime($assetPath);
        if ($version !== '') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'v=' . rawurlencode($version);
        }
    }

    return $url;
}

if (!function_exists('login_url')) {
    function login_url(): string
    {
        return url_path(LOGIN_PATH);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return url_path('/admin/' . ltrim($path, '/'));
    }
}

function public_url(string $path = ''): string
{
    $normalized = ltrim($path, '/');
    if ($normalized === '' || strcasecmp($normalized, 'index.php') === 0) {
        return url_path('/');
    }

    return url_path('/' . $normalized);
}

function app_redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Splits an over-fetched list into [items, hasNext].
 * Callers fetch $limit + 1 rows; this function trims back to $limit
 * and returns true for $hasNext when the extra row was present.
 *
 * @return array{0: array, 1: bool}
 */
function paginate_items(array $rows, int $limit): array
{
    $hasNext = count($rows) > $limit;
    return [$hasNext ? array_slice($rows, 0, $limit) : $rows, $hasNext];
}

/**
 * Keeps first appearance order while removing duplicates by known item keys.
 */
function dedupe_items_by_key(array $items): array
{
    $seen = [];
    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $contentId = strtolower(trim((string)($item['content_id'] ?? '')));
        $productId = strtolower(trim((string)($item['product_id'] ?? '')));
        $id = trim((string)($item['id'] ?? ''));
        $key = $contentId !== '' ? 'content_id:' . $contentId : ($productId !== '' ? 'product_id:' . $productId : ($id !== '' ? 'id:' . $id : ''));

        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if ($key !== '') {
            $seen[$key] = true;
        }

        $result[] = $item;
    }

    return $result;
}
function build_url(string $path, array $params = []): string
{
    $filtered = array_filter(
        $params,
        static fn($v) => $v !== null && (string)$v !== ''
    );
    if ($filtered === []) {
        return $path;
    }
    $separator = str_contains($path, '?') ? '&' : '?';
    return $path . $separator . http_build_query($filtered);
}

/**
 * Builds an absolute canonical URL from a root-relative path and optional params.
 * Null or empty param values are omitted.
 */
function canonical_url(string $path, array $params = []): string
{
    $filtered = array_filter(
        $params,
        static fn($v) => $v !== null && (string)$v !== ''
    );
    $url = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    if ($filtered !== []) {
        $url .= '?' . http_build_query($filtered);
    }
    return $url;
}

/**
 * Safely parses an integer from user input, applying default and min/max bounds.
 * $min must be less than or equal to $max.
 */
function safe_int(mixed $value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '') {
        return $default;
    }
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) {
        return $default;
    }
    return max($min, min($max, (int)$int));
}

/**
 * Safely trims a string from user input to a maximum UTF-8 character length.
 */
function safe_str(mixed $value, int $maxLen): string
{
    $str = trim((string)$value);
    if ($maxLen > 0 && mb_strlen($str, 'UTF-8') > $maxLen) {
        $str = mb_substr($str, 0, $maxLen, 'UTF-8');
    }
    return $str;
}

/**
 * Sends a 404 response with a minimal HTML page and terminates execution.
 */
function abort_404(string $title = '404 Not Found', string $message = 'ページが見つかりませんでした。'): never
{
    http_response_code(404);
    echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><title>' . e($title) . '</title></head>'
        . '<body><h1>' . e($title) . '</h1><p>' . e($message) . '</p></body></html>';
    exit;
}

/**
 * Formats a date string into Japanese-style "Y年n月j日" notation.
 * Returns an empty string when the input is empty.
 * Returns the original date string when it cannot be parsed.
 */
function format_date(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return e($date);
    }
    return date('Y年n月j日', $timestamp);
}

/**
 * Formats an integer price value as a yen string (e.g. "¥1,980").
 * Returns an empty string for zero, null, or non-positive values.
 */
function format_price(mixed $price): string
{
    if ($price === null || $price === '' || $price === 0 || $price === '0') {
        return '';
    }
    $n = (int)$price;
    if ($n <= 0) {
        return '';
    }
    return '¥' . number_format($n);
}
