<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(204);
    exit;
}

try {
    analytics_track_beacon();
} catch (Throwable $e) {
    error_log('analytics beacon failed: ' . $e->getMessage());
}

http_response_code(204);
