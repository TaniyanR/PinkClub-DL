<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    exit;
}

if (!analytics_request_is_valid_browser_beacon()) {
    http_response_code(204);
    exit;
}

$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (analytics_request_is_automated($userAgent)) {
    http_response_code(204);
    exit;
}

$id = max(0, (int)($_POST['id'] ?? 0));
$contentId = trim((string)($_POST['content_id'] ?? $_POST['cid'] ?? ''));
$item = null;

try {
    if ($id > 0) {
        $stmt = db()->prepare('SELECT id FROM items WHERE id = :id AND ' . items_front_release_where() . ' LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $item = $row;
        }
    } elseif ($contentId !== '') {
        $row = fetch_item_by_content_id($contentId);
        if (is_array($row)) {
            $item = $row;
        }
    }

    if (!is_array($item) || (int)($item['id'] ?? 0) < 1) {
        http_response_code(204);
        exit;
    }

    $ipHash = analytics_visitor_hash($userAgent);
    $ua = mb_substr($userAgent, 0, 255);

    $viewStmt = db()->prepare(
        'SELECT id FROM page_views
         WHERE item_id = :item_id
           AND ip_hash = :ip_hash
           AND viewed_at >= CURDATE()
           AND viewed_at < CURDATE() + INTERVAL 1 DAY
         LIMIT 1'
    );
    $viewStmt->execute([
        ':item_id' => (int)$item['id'],
        ':ip_hash' => $ipHash,
    ]);

    if (!$viewStmt->fetch()) {
        $insertStmt = db()->prepare(
            'INSERT INTO page_views (item_id, viewed_at, ip_hash, user_agent)
             VALUES (:item_id, NOW(), :ip_hash, :user_agent)'
        );
        $insertStmt->execute([
            ':item_id' => (int)$item['id'],
            ':ip_hash' => $ipHash,
            ':user_agent' => $ua,
        ]);
    }
} catch (Throwable $e) {
    error_log('page view beacon failed: ' . $e->getMessage());
}

http_response_code(204);
