<?php
declare(strict_types=1);

set_time_limit(50);

require_once __DIR__ . '/../public/_bootstrap.php';

function timer_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !auth_user()) {
    timer_json(['ran' => false, 'saved_items' => 0, 'message' => 'session_expired', 'at' => date('Y-m-d H:i:s')], 401);
}
auth_require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    timer_json(['ran' => false, 'saved_items' => 0, 'message' => 'POST only', 'at' => date('Y-m-d H:i:s')], 405);
}
csrf_validate_or_fail((string)post('_csrf', ''));

$now = date('Y-m-d H:i:s');
timer_json(['ran' => false, 'saved_items' => 0, 'message' => '自動更新はcron専用です', 'at' => $now]);
