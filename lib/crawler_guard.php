<?php

declare(strict_types=1);

require_once __DIR__ . '/rate_limit.php';

function pcf_crawler_guard_request_path(): string
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = (string)parse_url($requestUri, PHP_URL_PATH);
    if ($path === '') {
        $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    }

    return $path;
}

function pcf_crawler_guard_is_public_heavy_path(string $path): bool
{
    return preg_match('#/(?:public/)?item\.php$#', $path) === 1;
}

function pcf_crawler_guard_is_known_crawler(string $userAgent): bool
{
    if ($userAgent === '') {
        return false;
    }

    return preg_match('/(?:Applebot|GPTBot|Googlebot|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|facebookexternalhit|Twitterbot|AhrefsBot|SemrushBot|MJ12bot|DotBot|PetalBot|Bytespider|ClaudeBot|Amazonbot|CensysInspect|DataForSeoBot)/i', $userAgent) === 1;
}

function pcf_crawler_guard_redirect_rank_period_crawler(string $path): void
{
    if (!isset($_GET['rank_period'])) {
        return;
    }

    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!pcf_crawler_guard_is_known_crawler($userAgent)) {
        return;
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $query = (string)parse_url($requestUri, PHP_URL_QUERY);
    $queryParams = [];
    if ($query !== '') {
        parse_str($query, $queryParams);
    } else {
        $queryParams = $_GET;
    }

    unset($queryParams['rank_period']);

    $location = $path;
    $canonicalQuery = http_build_query($queryParams);
    if ($canonicalQuery !== '') {
        $location .= '?' . $canonicalQuery;
    }

    header('Location: ' . $location, true, 301);
    exit;
}

function pcf_crawler_guard_check(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $path = pcf_crawler_guard_request_path();
    if (!pcf_crawler_guard_is_public_heavy_path($path)) {
        return;
    }

    pcf_crawler_guard_redirect_rank_period_crawler($path);

    if (isset($_GET['rank_period'])) {
        rate_limit_check('public_rank_period_' . basename($path), 20, 60);
    }

    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (pcf_crawler_guard_is_known_crawler($userAgent)) {
        rate_limit_check('public_crawler_' . basename($path), 10, 60);
    }
}
