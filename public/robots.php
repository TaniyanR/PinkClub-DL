<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/plain; charset=UTF-8');

$base = rtrim((string)BASE_URL, '/');
$base = preg_replace('#/?public(?:/robots\.php)?$#', '', $base) ?: $base;
$base = rtrim($base, '/');
echo "User-agent: *\n";
echo "Disallow: /admin/\n";
echo "Disallow: /forgot_password.php\n";
echo "Disallow: /reset_password.php\n";
echo "Disallow: /search.php\n";
echo "Disallow: /out.php\n";
echo "Disallow: /vr_affiliate.php\n";
echo "Disallow: /sample_images.php\n";
echo "Disallow: /analytics.php\n";
echo "Disallow: /page_view_beacon.php\n";
echo "Disallow: /ranking_refresh.php\n";
echo "Disallow: /public/\n";
echo "Disallow: /*?*rank_period=\n";
echo "Sitemap: {$base}/sitemap.php\n";
