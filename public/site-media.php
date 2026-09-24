<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

header('X-Content-Type-Options: nosniff', true);
header('X-Robots-Tag: noindex, nofollow', true);
header('Referrer-Policy: no-referrer', true);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$key = strtolower(trim((string)($_GET['key'] ?? '')));
if (!site_media_key_allowed($key)) {
    http_response_code(404);
    exit;
}

$media = site_media_get($key);
if (!is_array($media)) {
    http_response_code(404);
    exit;
}

$bytes = (string)($media['media_data'] ?? '');
$mime = strtolower(trim((string)($media['mime_type'] ?? '')));
$allowedMimes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/x-icon',
    'image/vnd.microsoft.icon',
];
if ($bytes === '' || !in_array($mime, $allowedMimes, true)) {
    http_response_code(404);
    exit;
}

$storedSize = (int)($media['byte_size'] ?? 0);
if ($storedSize > 0 && $storedSize !== strlen($bytes)) {
    http_response_code(500);
    exit;
}

$sha256 = strtolower((string)($media['sha256'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $sha256) || !hash_equals($sha256, hash('sha256', $bytes))) {
    http_response_code(500);
    exit;
}

$etag = '"' . $sha256 . '"';
$updatedAtRaw = (string)($media['updated_at'] ?? '');
$updatedAt = $updatedAtRaw !== '' ? strtotime($updatedAtRaw) : false;
$versioned = isset($_GET['v']) && trim((string)$_GET['v']) !== '';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=' . ($versioned ? '31536000, immutable' : '86400'));
if ($updatedAt !== false) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updatedAt) . ' GMT');
}

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag)) {
    http_response_code(304);
    exit;
}
if ($ifNoneMatch === '' && $updatedAt !== false) {
    $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ifModifiedSince !== false && $ifModifiedSince >= $updatedAt) {
        http_response_code(304);
        exit;
    }
}

header('Content-Length: ' . strlen($bytes));
if ($method !== 'HEAD') {
    echo $bytes;
}

