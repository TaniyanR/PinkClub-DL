<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/access_analytics.php';
require_once __DIR__ . '/../lib/crawler_guard.php';
require_once __DIR__ . '/../lib/public_page_cache.php';

pcf_crawler_guard_check();

$publicScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if ($publicScriptName === 'setup_check.php' && function_exists('setup_guard_enforce_for_setup_page')) {
    setup_guard_enforce_for_setup_page();
}

$longCachePublicPages = [
    'index.php',
    'items.php',
    'item.php',
    'search.php',
    'directory.php',
    'posts.php',
    'post.php',
    'page.php',
];
$publicPageCacheTtl = in_array($publicScriptName, $longCachePublicPages, true) ? 600 : 120;
pcf_public_page_cache_start($publicPageCacheTtl);

// Cached responses do not need a DB read. On a cache miss, use the configured
// common OGP image when the page has not supplied a product-specific image.
if ((!isset($ogImage) || !is_string($ogImage) || trim($ogImage) === '') && function_exists('site_media_public_url')) {
    $defaultOgpImage = site_media_public_url('ogp');
    if ($defaultOgpImage !== '') {
        $ogImage = $defaultOgpImage;
    }
}

$readOnlyPublicPages = [
    'index.php',
    'items.php',
    'item.php',
    'search.php',
    'directory.php',
    'posts.php',
    'post.php',
    'article.php',
    'sample_images.php',
    'ranking_refresh.php',
    'analytics.php',
    'analytics_engagement.php',
    'page_view_beacon.php',
    'out.php',
    'vr_affiliate.php',
    'feed.php',
    'rss.php',
];
if (session_status() === PHP_SESSION_ACTIVE && in_array($publicScriptName, $readOnlyPublicPages, true)) {
    session_write_close();
}
