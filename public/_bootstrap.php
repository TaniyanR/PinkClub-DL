<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/access_analytics.php';
require_once __DIR__ . '/../lib/crawler_guard.php';
require_once __DIR__ . '/../lib/public_page_cache.php';

pcf_crawler_guard_check();

$publicScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
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

$readOnlyPublicPages = [
    'index.php',
    'items.php',
    'item.php',
    'search.php',
    'directory.php',
    'posts.php',
    'article.php',
    'sample_images.php',
    'ranking_refresh.php',
    'analytics.php',
    'page_view_beacon.php',
    'out.php',
    'vr_affiliate.php',
    'feed.php',
    'rss.php',
];
if (session_status() === PHP_SESSION_ACTIVE && in_array($publicScriptName, $readOnlyPublicPages, true)) {
    session_write_close();
}
