<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/public_rankings.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    exit;
}

$type = trim((string)($_POST['type'] ?? ''));
$period = trim((string)($_POST['period'] ?? ''));
if (
    $type !== 'items'
    || !in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)
) {
    http_response_code(204);
    exit;
}

$lockDirectory = dirname(__DIR__) . '/storage/cache';
if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
    http_response_code(204);
    exit;
}

$lockPath = $lockDirectory . '/ranking-refresh-' . hash('sha256', $type . '|' . $period) . '.lock';
$lockHandle = @fopen($lockPath, 'c');
if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    http_response_code(204);
    exit;
}

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
