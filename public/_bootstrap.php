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
    'index.php','items.php','item.php','search.php','actresses.php','actress.php','genres.php','genre.php',
    'makers.php','maker.php','series.php','series_list.php','series_detail.php','series_one.php','labels.php','label.php',
    'authors.php','author.php','directory.php','posts.php','post.php','page.php',
];
$socialCardUserAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isSocialCardCrawler = $publicScriptName === 'item.php'
    && $socialCardUserAgent !== ''
    && preg_match('/(?:Twitterbot|facebookexternalhit|Discordbot|Slackbot|LinkedInBot)/i', $socialCardUserAgent) === 1;

if ($isSocialCardCrawler) {
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('X-PCF-Page-Cache: BYPASS-SOCIAL');
} elseif (!in_array($publicScriptName, ['setup_check.php','rss_trade_fragment.php'], true)) {
    $publicPageCacheTtl = in_array($publicScriptName, $longCachePublicPages, true) ? 600 : 120;
    pcf_public_page_cache_start($publicPageCacheTtl);
}

$readOnlyPublicPages = [
    'index.php','items.php','item.php','search.php','actresses.php','actresses_group.php','actress.php','genres.php','genre.php',
    'makers.php','maker.php','maker_resolve.php','series.php','series_list.php','series_detail.php','series_one.php','labels.php','label.php',
    'authors.php','author.php','directory.php','posts.php','article.php','sample_images.php','recently_viewed_items.php','ranking_refresh.php',
    'analytics.php','page_view_beacon.php','out.php','vr_affiliate.php','feed.php','rss.php','rss_trade_fragment.php','social-image.php',
];
if (session_status() === PHP_SESSION_ACTIVE && in_array($publicScriptName, $readOnlyPublicPages, true)) {
    session_write_close();
}
