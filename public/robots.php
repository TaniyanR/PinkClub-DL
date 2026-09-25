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
echo "Disallow: /public/setup_check.php\n";
echo "Disallow: /out.php?to=\n";
echo "Disallow: /public/out.php?to=\n";
echo "Disallow: /vr_affiliate.php\n";
echo "Disallow: /public/vr_affiliate.php\n";
if ($base !== '') {
    echo "Sitemap: {$base}/sitemap.php\n";
}
