<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    exit;
}

$type = trim((string)($_GET['type'] ?? ''));
if (!in_array($type, ['text', 'image', 'bottom'], true)) {
    http_response_code(400);
    exit;
}

$cacheDir = dirname(__DIR__) . '/storage/cache/rss-fragments';
$ttl = 180;
$cacheFile = $cacheDir . '/v4-' . $type . '.html';
$lockFile = $cacheDir . '/.v4-' . $type . '.lock';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') {
        header('X-PCF-RSS-Fragment: HIT');
        echo $cached;
        exit;
    }
}

$lock = is_dir($cacheDir) ? @fopen($lockFile, 'c') : false;
$hasLock = is_resource($lock) && @flock($lock, LOCK_EX | LOCK_NB);
if (!$hasLock && is_file($cacheFile)) {
    $stale = @file_get_contents($cacheFile);
    if (is_string($stale) && $stale !== '') {
        header('X-PCF-RSS-Fragment: STALE');
        echo $stale;
        if (is_resource($lock)) {
            fclose($lock);
        }
        exit;
    }
}
if (is_resource($lock) && !$hasLock) {
    $hasLock = @flock($lock, LOCK_EX);
}

if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') {
        header('X-PCF-RSS-Fragment: HIT-AFTER-WAIT');
        echo $cached;
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        exit;
    }
}

$GLOBALS['pcf_rss_fragment_request'] = true;
ob_start();
try {
    if ($type === 'text') {
        include __DIR__ . '/partials/rss_text_widget.php';
    } elseif ($type === 'image') {
        include __DIR__ . '/partials/rss_image_widget.php';
    } else {
        render_shared_content_ad_row('content_bottom', 'home');
    }
    $html = (string)ob_get_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('[rss] fragment generation failed: ' . $e->getMessage());
    $html = '';
}

if ($html !== '' && is_dir($cacheDir) && is_writable($cacheDir)) {
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $suffix = uniqid('', true);
    }
    $tmp = $cacheDir . '/.' . basename($cacheFile) . '.' . $suffix . '.tmp';
    if (@file_put_contents($tmp, $html, LOCK_EX) !== false) {
        @rename($tmp, $cacheFile);
    } else {
        @unlink($tmp);
    }
}

if (is_resource($lock)) {
    @flock($lock, LOCK_UN);
    fclose($lock);
}

if ($html === '' && is_file($cacheFile)) {
    $html = (string)@file_get_contents($cacheFile);
}

header('X-PCF-RSS-Fragment: MISS');
echo $html;
