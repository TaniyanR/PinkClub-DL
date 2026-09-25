<?php
declare(strict_types=1);

require_once __DIR__ . '/site_settings.php';

function pcf_indexnow_origin(): string
{
    $parts = parse_url((string)BASE_URL);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

function pcf_indexnow_key(): string
{
    $key = (string)setting_get('indexnow.key', '');
    return preg_match('/^[a-zA-Z0-9-]{8,128}$/D', $key) === 1 ? $key : '';
}

function pcf_indexnow_enabled(): bool
{
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $origin = parse_url(pcf_indexnow_origin());
    $expectedHost = is_array($origin)
        ? strtolower((string)($origin['host'] ?? '')) . (isset($origin['port']) ? ':' . $origin['port'] : '')
        : '';
    if (PHP_SAPI !== 'cli' && $requestHost !== $expectedHost) {
        return false;
    }
    return setting_get('indexnow.enabled', '0') === '1'
        && setting_get('indexnow.origin', '') === pcf_indexnow_origin()
        && pcf_indexnow_key() !== '';
}

function pcf_indexnow_valid_url(string $url): bool
{
    $origin = pcf_indexnow_origin();
    if ($origin === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20]/', $url)
        || !str_starts_with($url, $origin . '/')) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    // FL has no actress/genre/maker individual pages. Queue canonical routes only.
    $path = (string)($parts['path'] ?? '');
    $basePath = rtrim((string)parse_url(public_url('index.php'), PHP_URL_PATH), '/');
    $prefix = $basePath === '' ? '' : (str_ends_with($basePath, '/index.php') ? substr($basePath, 0, -10) : $basePath);
    if ($prefix !== '' && !str_starts_with($path, $prefix . '/')) {
        return false;
    }
    $route = substr($path, strlen($prefix));
    parse_str((string)($parts['query'] ?? ''), $query);
    if ($route === '/' && $query === []) {
        return true;
    }
    if ($route === '/item.php') {
        return array_keys($query) === ['id'] && is_string($query['id'])
            && preg_match('/^[1-9][0-9]*$/D', $query['id']) === 1;
    }
    return $route === '/page.php' && array_keys($query) === ['slug'] && is_string($query['slug'])
        && preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $query['slug']) === 1;
}

function pcf_indexnow_enqueue(string $url): void
{
    if (!pcf_indexnow_valid_url($url) || !db_table_exists('indexnow_queue')) {
        return;
    }
    db()->prepare('INSERT INTO indexnow_queue (url_hash,url,origin) VALUES (?,?,?) ON DUPLICATE KEY UPDATE revision=revision+1,attempts=0,next_attempt_at=NOW(),updated_at=NOW()')
        ->execute([hash('sha256', $url), $url, pcf_indexnow_origin()]);
}

function pcf_indexnow_item_changed(int $id): void
{
    // Saving an item only queues work; public/API requests never contact IndexNow.
    if ($id < 1 || !db_table_exists('indexnow_item_state')) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT content_id,title,url,affiliate_url,image_small,image_large,raw_json,release_date FROM items WHERE id=?');
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item || (($item['release_date'] ?? '') !== '' && substr((string)$item['release_date'], 0, 10) > date('Y-m-d'))) {
            return;
        }
        $hash = hash('sha256', pcf_indexnow_origin() . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $state = db()->prepare('SELECT fingerprint FROM indexnow_item_state WHERE item_id=?');
        $state->execute([$id]);
        if ($state->fetchColumn() === $hash) {
            return;
        }
        pcf_indexnow_enqueue(public_url('item.php') . '?id=' . $id);
        db()->prepare('INSERT INTO indexnow_item_state (item_id,fingerprint) VALUES (?,?) ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint)')
            ->execute([$id, $hash]);
    } catch (Throwable $e) {
        error_log('IndexNow enqueue failed: ' . $e->getMessage());
    }
}

function pcf_indexnow_payload(array $urls): array
{
    $urls = array_values(array_unique(array_filter($urls, 'pcf_indexnow_valid_url')));
    return [
        'host' => (string)parse_url(pcf_indexnow_origin(), PHP_URL_HOST),
        'key' => pcf_indexnow_key(),
        'keyLocation' => pcf_indexnow_origin() . '/indexnow-key.php',
        'urlList' => array_slice($urls, 0, 10000),
    ];
}

function pcf_indexnow_http(array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'retry_after' => 300];
    }
    $retryAfter = 0;
    $curl = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static fn($handle, string $data): int => strlen($data),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
            if (stripos($header, 'Retry-After:') === 0) {
                $value = trim(substr($header, 12));
                $retryAfter = ctype_digit($value) ? (int)$value : max(0, (int)strtotime($value) - time());
            }
            return strlen($header);
        },
    ]);
    $ok = curl_exec($curl);
    $status = $ok === false ? 0 : (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'retry_after' => $retryAfter];
}

// Called only by an authenticated admin action, timer/cron, or CLI.
function pcf_indexnow_dispatch(?callable $transport = null): array
{
    if (!pcf_indexnow_enabled() || !db_table_exists('indexnow_queue')) {
        return ['status' => 'disabled', 'count' => 0];
    }
    $lockName = 'pcf-indexnow-' . substr(hash('sha256', pcf_indexnow_origin()), 0, 40);
    $lock = db()->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        return ['status' => 'busy', 'count' => 0];
    }
    try {
        if ((int)setting_get('indexnow.next_dispatch_at', '0') > time()) {
            return ['status' => 'cooldown', 'count' => 0];
        }
        $stmt = db()->prepare('SELECT * FROM indexnow_queue WHERE origin=? AND next_attempt_at<=NOW() ORDER BY next_attempt_at LIMIT 1000');
        $stmt->execute([pcf_indexnow_origin()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return ['status' => 'idle', 'count' => 0];
        }
        $payload = pcf_indexnow_payload(array_column($rows, 'url'));
        if ($payload['urlList'] === []) {
            return ['status' => 'invalid', 'count' => 0];
        }
        try {
            $result = ($transport ?? 'pcf_indexnow_http')($payload);
        } catch (Throwable $e) {
            error_log('IndexNow transport failed: ' . $e->getMessage());
            $result = ['status' => 0, 'retry_after' => 300];
        }
        $code = (int)($result['status'] ?? 0);
        foreach ($rows as $row) {
            if (!in_array($row['url'], $payload['urlList'], true)) {
                continue;
            }
            if (in_array($code, [200, 202], true)) {
                db()->prepare('DELETE FROM indexnow_queue WHERE url_hash=? AND revision=?')->execute([$row['url_hash'], $row['revision']]);
            } else {
                $delay = max(
                    min(86400, (int)($result['retry_after'] ?? 0)),
                    min(86400, 300 * (2 ** min(8, (int)$row['attempts'])))
                );
                if (in_array($code, [400, 403, 422], true)) {
                    $delay = max($delay, 86400);
                }
                db()->prepare('UPDATE indexnow_queue SET attempts=attempts+1,last_status=?,next_attempt_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE url_hash=? AND revision=?')
                    ->execute([$code, $delay, $row['url_hash'], $row['revision']]);
            }
        }
        $globalDelay = in_array($code, [200, 202], true)
            ? 60
            : (in_array($code, [400, 403, 422], true) ? 86400 : max(300, min(86400, (int)($result['retry_after'] ?? 0))));
        setting_set('indexnow.next_dispatch_at', (string)(time() + $globalDelay));
        setting_set('indexnow.last_result', json_encode(['at' => date(DATE_ATOM), 'http' => $code, 'count' => count($payload['urlList'])]));
        return [
            'status' => in_array($code, [200, 202], true) ? 'accepted' : 'retry',
            'http' => $code,
            'count' => count($payload['urlList']),
        ];
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function pcf_indexnow_backfill(): int
{
    if (!db_table_exists('indexnow_queue')) {
        return 0;
    }
    require_once __DIR__ . '/repository.php';
    $key = 'indexnow.backfill.' . substr(hash('sha256', pcf_indexnow_origin()), 0, 16);
    $cursor = max(0, (int)setting_get($key, '0'));
    $stmt = db()->prepare('SELECT id FROM items WHERE id > ? AND ' . items_front_release_where() . ' ORDER BY id LIMIT 1000');
    $stmt->execute([$cursor]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        pcf_indexnow_enqueue(public_url('item.php') . '?id=' . (int)$id);
    }
    if ($ids !== []) {
        setting_set($key, (string)end($ids));
    }
    return count($ids);
}
