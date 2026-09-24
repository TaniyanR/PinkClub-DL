<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/../lib/rate_limit.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    exit;
}

$expectedHost = strtolower((string)(parse_url(defined('BASE_URL') ? (string)BASE_URL : '', PHP_URL_HOST) ?: 'pinkclub-dl.com'));
$originHost = strtolower((string)(parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST) ?: ''));
$refererHost = strtolower((string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: ''));
if (($originHost === '' && $refererHost === '')
    || ($originHost !== '' && !hash_equals($expectedHost, $originHost))
    || ($refererHost !== '' && !hash_equals($expectedHost, $refererHost))) {
    http_response_code(204);
    exit;
}

if (!rate_limit_allow('public_ranking_refresh', 8, 60)) {
    http_response_code(204);
    exit;
}

$type = trim((string)($_POST['type'] ?? ''));
$period = trim((string)($_POST['period'] ?? ''));
if ($type !== 'items' || !in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    http_response_code(204);
    exit;
}

$lockDirectory = dirname(__DIR__) . '/storage/cache';
if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
    http_response_code(204);
    exit;
}

$refreshKey = hash('sha256', $type . '|' . $period);
$lockPath = $lockDirectory . '/ranking-refresh-' . $refreshKey . '.lock';
$cooldownPath = $lockDirectory . '/ranking-refresh-' . $refreshKey . '.cooldown';
$lockHandle = @fopen($lockPath, 'c');
if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    http_response_code(204);
    exit;
}

$lastRun = is_file($cooldownPath) ? (int)@filemtime($cooldownPath) : 0;
if ($lastRun > time() - 60) {
    @flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    http_response_code(204);
    exit;
}
@touch($cooldownPath);

try {
    pcf_public_weighted_ranking($type, $period);
    foreach (pcf_public_ranking_refresh_queue() as $queued) {
        if (($queued['type'] ?? '') === $type && ($queued['period'] ?? '') === $period) {
            pcf_public_weighted_ranking($type, $period, 200, true);
            break;
        }
    }
} catch (Throwable $e) {
    error_log('ranking refresh failed: ' . $e->getMessage());
}

@flock($lockHandle, LOCK_UN);
fclose($lockHandle);
http_response_code(204);
