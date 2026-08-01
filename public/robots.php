<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/plain; charset=UTF-8');

$base = rtrim((string)BASE_URL, '/');
$base = preg_replace('#/?public/robots\.php$#', '', $base) ?: $base;
$base = rtrim($base, '/');
echo "User-agent: *\n";
echo "Disallow: /admin/\n";
echo "Disallow: /public/forgot_password.php\n";
echo "Disallow: /public/reset_password.php\n";
echo "Disallow: /item.php?*rank_period=\n";
echo "Disallow: /public/item.php?*rank_period=\n";
echo "Crawl-delay: 10\n";
echo "Sitemap: {$base}/public/sitemap.php\n";
