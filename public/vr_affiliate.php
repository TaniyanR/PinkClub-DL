<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$itemId = max(0, (int)($_GET['id'] ?? 0));
if ($itemId <= 0) {
    http_response_code(404);
    exit;
}

try {
    $stmt = db()->prepare('SELECT title, affiliate_url, raw_json FROM items WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $item = false;
}

if (!is_array($item)) {
    http_response_code(404);
    exit;
}

$title = trim((string)($item['title'] ?? ''));
if (preg_match('/(?:【|\[|［)?\s*VR\s*(?:】|\]|］)?/iu', $title) !== 1) {
    http_response_code(404);
    exit;
}

$affiliateUrl = trim((string)($item['affiliate_url'] ?? ''));
if ($affiliateUrl === '') {
    $raw = json_decode((string)($item['raw_json'] ?? ''), true);
    if (is_array($raw)) {
        foreach (['affiliateURL', 'affiliate_url'] as $key) {
            $candidate = trim((string)($raw[$key] ?? ''));
            if ($candidate !== '') {
                $affiliateUrl = $candidate;
                break;
            }
        }
    }
}

if ($affiliateUrl === '' || filter_var($affiliateUrl, FILTER_VALIDATE_URL) === false) {
    http_response_code(404);
    exit;
}

$host = strtolower((string)parse_url($affiliateUrl, PHP_URL_HOST));
$allowed = $host === 'duga.jp'
    || $host === 'duga.co.jp'
    || $host === 'duga.com'
    || str_ends_with($host, '.duga.jp')
    || str_ends_with($host, '.duga.co.jp')
    || str_ends_with($host, '.duga.com');

if (!$allowed) {
    http_response_code(404);
    exit;
}

header('Cache-Control: private, no-store');
header('Location: ' . $affiliateUrl, true, 302);
exit;
