<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/site_settings.php';

if (headers_sent() === false) {
    header('X-Robots-Tag: noindex, nofollow');
}

$siteTitle = trim(site_title_setting(''));
if ($siteTitle === '') {
    $siteTitle = 'サイトタイトル未設定';
}
$rawPageTitle = isset($pageTitle) && $pageTitle !== '' ? (string)$pageTitle : 'ログイン';
$fullTitle = $rawPageTitle . ' | ' . $siteTitle;
$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$faviconUrl = $faviconPath !== '' ? public_url($faviconPath) : '';
$faviconType = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/x-icon';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($fullTitle); ?></title>
    <?php if ($faviconUrl !== '') : ?>
        <link rel="icon" href="<?php echo e($faviconUrl); ?>" sizes="any" type="<?php echo e($faviconType); ?>">
        <link rel="shortcut icon" href="<?php echo e($faviconUrl); ?>" type="<?php echo e($faviconType); ?>">
        <link rel="apple-touch-icon" href="<?php echo e($faviconUrl); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo e(asset_url('css/style.css')); ?>">
</head>
<body>
<div class="login-shell">
    <?php if (!(isset($hideLoginHeaderBrand) && $hideLoginHeaderBrand === true)) : ?>
        <header class="login-header">
            <a href="<?php echo e(public_url('index.php')); ?>"><?php echo e($siteTitle); ?></a>
        </header>
    <?php endif; ?>
    <main class="login-main">
