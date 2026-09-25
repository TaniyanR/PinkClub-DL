<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/local_config_writer.php';
require_once __DIR__ . '/site_settings.php';

function app_session_start(): void
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
        session_start();
    }
}

function app_settings(): array
{
    $local = local_config_load();
    $settings = $local['settings'] ?? [];
    return is_array($settings) ? $settings : [];
}

function app_setting_get(string $key, mixed $default = null): mixed
{
    $settings = app_settings();
    return $settings[$key] ?? $default;
}

function app_setting_set_many(array $values): void
{
    $local = local_config_load();
    $settings = $local['settings'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }
    foreach ($values as $k => $v) {
        $settings[(string)$k] = $v;
    }
    $local['settings'] = $settings;
    local_config_write($local);
}

function app_ip_hash_salt(): string
{
    $salt = (string)config_get('security.ip_hash_salt', 'pinkclub-default-salt');
    return $salt !== '' ? $salt : 'pinkclub-default-salt';
}

function sanitize_page_html(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
    $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? '';
    return trim($html);
}

function rss_extract_first_image_url(SimpleXMLElement $item): string
{
    $namespaces = $item->getNameSpaces(true);

    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);
        if (isset($media->content)) {
            foreach ($media->content as $content) {
                $attrs = $content->attributes();
                $url = trim((string)($attrs['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }
        if (isset($media->thumbnail)) {
            foreach ($media->thumbnail as $thumb) {
                $attrs = $thumb->attributes();
                $url = trim((string)($attrs['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }
    }

    if (isset($item->enclosure)) {
        foreach ($item->enclosure as $enclosure) {
            $attrs = $enclosure->attributes();
            $type = strtolower(trim((string)($attrs['type'] ?? '')));
            $url = trim((string)($attrs['url'] ?? ''));
            if ($url !== '' && ($type === '' || str_contains($type, 'image/'))) {
                return $url;
            }
        }
    }

    $description = (string)($item->description ?? '');
    if ($description !== '' && preg_match("/<img[^>]+src=['\"]([^'\"]+)['\"]/i", $description, $matches) === 1) {
        return trim((string)($matches[1] ?? ''));
    }

    if (isset($namespaces['content'])) {
        $content = $item->children($namespaces['content']);
        $encoded = (string)($content->encoded ?? '');
        if ($encoded !== '' && preg_match("/<img[^>]+src=['\"]([^'\"]+)['\"]/i", $encoded, $matches) === 1) {
            return trim((string)($matches[1] ?? ''));
        }
    }

    return '';
}

function rss_http_url(string $value): string
{
    $url = trim($value);
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || !in_array($port, [80, 443], true)
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return '';
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return '';
    }

    return $url;
}

function rss_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function rss_resolve_public_ips(string $host): array
{
    $host = strtolower(trim($host, '[]'));
    if ($host === '') {
        return [];
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return rss_public_ip($host) ? [$host] : [];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '' && rss_public_ip($ip)) {
                $ips[$ip] = true;
            }
        }
    }
    if ($ips === []) {
        foreach ((array)@gethostbynamel($host) as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '' && rss_public_ip($ip)) {
                $ips[$ip] = true;
            }
        }
    }

    return array_keys($ips);
}

function rss_redirect_url(string $currentUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return '';
    }
    if (str_starts_with($location, '//')) {
        $scheme = strtolower((string)(parse_url($currentUrl, PHP_URL_SCHEME) ?: 'https'));
        return rss_http_url($scheme . ':' . $location);
    }
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
        return rss_http_url($location);
    }

    $parts = parse_url($currentUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $base = $scheme . '://' . $host . $port;
    $currentPath = (string)($parts['path'] ?? '/');

    if (str_starts_with($location, '?')) {
        return rss_http_url($base . $currentPath . $location);
    }
    if (str_starts_with($location, '/')) {
        return rss_http_url($base . $location);
    }

    $dir = rtrim(str_replace('\\', '/', dirname($currentPath)), '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    $segments = [];
    foreach (explode('/', $dir . '/' . $location) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return rss_http_url($base . '/' . implode('/', $segments));
}

function rss_http_fetch_once(string $url, int $timeoutSec, int $maxBytes): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true)) {
        return null;
    }

    $ips = rss_resolve_public_ips($host);
    if ($ips === []) {
        return null;
    }

    foreach ($ips as $ip) {
        $body = '';
        $location = '';
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(4, $timeoutSec)),
            CURLOPT_TIMEOUT => max(1, min(12, $timeoutSec)),
            CURLOPT_USERAGENT => 'PinkClubDL-RSS/1.1 (+https://pinkclub-dl.com/)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveIp],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge) {
            return null;
        }
        if ($ok === false) {
            continue;
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            return ['redirect' => $location];
        }
        if ($status >= 200 && $status < 300 && $body !== '') {
            return ['body' => $body];
        }
    }

    return null;
}

function rss_http_fetch(string $url, int $timeoutSec = 4, int $maxBytes = 5242880, int $maxRedirects = 3): ?string
{
    $current = rss_http_url($url);
    if ($current === '') {
        return null;
    }

    $seen = [];
    for ($hop = 0; $hop <= max(0, min(5, $maxRedirects)); $hop++) {
        if (isset($seen[$current])) {
            return null;
        }
        $seen[$current] = true;

        $result = rss_http_fetch_once($current, $timeoutSec, $maxBytes);
        if (!is_array($result)) {
            return null;
        }
        if (isset($result['body']) && is_string($result['body'])) {
            return $result['body'];
        }
        if ($hop >= $maxRedirects) {
            return null;
        }
        $current = rss_redirect_url($current, (string)($result['redirect'] ?? ''));
        if ($current === '') {
            return null;
        }
    }

    return null;
}

function rss_fetch_source(int $sourceId, int $timeoutSec = 4): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM rss_sources WHERE id=:id AND is_enabled=1');
    $stmt->execute([':id' => $sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($source)) {
        return ['ok' => false, 'message' => 'source not found'];
    }

    $feedUrl = rss_http_url((string)($source['feed_url'] ?? ''));
    if ($feedUrl === '') {
        return ['ok' => false, 'message' => 'unsafe feed url'];
    }
    $xmlRaw = rss_http_fetch($feedUrl, $timeoutSec, 5 * 1024 * 1024, 3);
    if (!is_string($xmlRaw) || $xmlRaw === '') {
        return ['ok' => false, 'message' => 'fetch failed'];
    }
    if (stripos($xmlRaw, '<!DOCTYPE') !== false || stripos($xmlRaw, '<!ENTITY') !== false) {
        return ['ok' => false, 'message' => 'unsafe xml'];
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);
    if ($xml === false) {
        return ['ok' => false, 'message' => 'xml parse failed'];
    }

    $ngCategoryWords = preg_split('/\R+/', site_setting_get('rss.ng_category_words', '')) ?: [];
    $ngTagWords = preg_split('/\R+/', site_setting_get('rss.ng_tag_words', '')) ?: [];
    $ngCategoryWords = array_values(array_filter(array_map('trim', $ngCategoryWords), static fn(string $v): bool => $v !== ''));
    $ngTagWords = array_values(array_filter(array_map('trim', $ngTagWords), static fn(string $v): bool => $v !== ''));

    $items = $xml->channel->item ?? [];
    $insertWithImage = null;
    try {
        $insertWithImage = $pdo->prepare('INSERT IGNORE INTO rss_items (source_id,title,url,published_at,summary,guid,image_url,created_at) VALUES (:sid,:title,:url,:pub,:summary,:guid,:image,NOW())');
    } catch (Throwable) {
        $insertWithImage = null;
    }
    $insertWithoutImage = $pdo->prepare('INSERT IGNORE INTO rss_items (source_id,title,url,published_at,summary,guid,created_at) VALUES (:sid,:title,:url,:pub,:summary,:guid,NOW())');

    $processedItems = 0;
    foreach ($items as $item) {
        if ($processedItems >= 200) {
            break;
        }
        $processedItems++;

        $link = rss_http_url((string)($item->link ?? ''));
        if ($link === '') {
            continue;
        }
        $guid = trim((string)($item->guid ?? $link));
        if ($guid === '') {
            $guid = $link;
        }

        $categories = [];
        foreach ($item->category ?? [] as $c) {
            $categories[] = trim((string)$c);
        }

        $isBlocked = false;
        foreach ($ngCategoryWords as $ng) {
            foreach ($categories as $cat) {
                if ($cat !== '' && mb_stripos($cat, $ng) !== false) {
                    $isBlocked = true;
                    break 2;
                }
            }
        }

        if (!$isBlocked) {
            $tagsText = trim((string)($item->keywords ?? ''));
            foreach ($ngTagWords as $ng) {
                if ($tagsText !== '' && mb_stripos($tagsText, $ng) !== false) {
                    $isBlocked = true;
                    break;
                }
            }
        }
        if ($isBlocked) {
            continue;
        }

        $imageUrl = rss_http_url(rss_extract_first_image_url($item));
        $publishedTimestamp = strtotime((string)($item->pubDate ?? ''));
        if ($publishedTimestamp === false || $publishedTimestamp <= 0) {
            $publishedTimestamp = time();
        }
        $params = [
            ':sid' => $sourceId,
            ':title' => mb_substr((string)($item->title ?? ''), 0, 255),
            ':url' => mb_substr($link, 0, 500),
            ':pub' => date('Y-m-d H:i:s', $publishedTimestamp),
            ':summary' => mb_substr(strip_tags((string)($item->description ?? '')), 0, 2000),
            ':guid' => mb_substr($guid, 0, 500),
        ];

        if ($insertWithImage instanceof PDOStatement) {
            try {
                $insertWithImage->execute($params + [':image' => mb_substr($imageUrl, 0, 1000)]);
                continue;
            } catch (Throwable) {
            }
        }
        $insertWithoutImage->execute($params);
    }

    $pdo->prepare('UPDATE rss_sources SET last_fetched_at=NOW() WHERE id=:id')->execute([':id' => $sourceId]);
    return ['ok' => true, 'message' => 'updated'];
}

function rss_table_column_exists(string $table, string $column): bool
{
    if (!in_array($table, ['rss_sources', 'partner_rss'], true)) {
        return false;
    }
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function rss_ensure_tables(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS rss_sources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,feed_url VARCHAR(1000) NOT NULL,source_type VARCHAR(32) NOT NULL DEFAULT "general",source_ref_id BIGINT UNSIGNED NULL,is_enabled TINYINT(1) NOT NULL DEFAULT 1,last_fetched_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_rss_source_feed (feed_url),INDEX idx_rss_sources_type_ref (source_type,source_ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rss_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL,title VARCHAR(255) NOT NULL,url VARCHAR(500) NOT NULL,published_at DATETIME NULL,summary TEXT NULL,guid VARCHAR(500) NOT NULL,image_url TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_rss_guid (source_id,guid),INDEX idx_rss_pub (published_at),CONSTRAINT fk_rss_items_source FOREIGN KEY (source_id) REFERENCES rss_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!rss_table_column_exists('rss_sources', 'source_type')) {
        $pdo->exec('ALTER TABLE rss_sources ADD COLUMN source_type VARCHAR(32) NOT NULL DEFAULT "general" AFTER feed_url');
    }
    if (!rss_table_column_exists('rss_sources', 'source_ref_id')) {
        $pdo->exec('ALTER TABLE rss_sources ADD COLUMN source_ref_id BIGINT UNSIGNED NULL AFTER source_type');
    }
    if (!rss_table_column_exists('partner_rss', 'show_rss')) {
        try {
            $pdo->exec('ALTER TABLE partner_rss ADD COLUMN show_rss TINYINT(1) NOT NULL DEFAULT 1');
        } catch (Throwable) {
        }
    }
    try {
        $pdo->exec('CREATE INDEX idx_rss_sources_type_ref ON rss_sources(source_type, source_ref_id)');
    } catch (Throwable) {
    }
}

function rss_sync_partner_sources(): void
{
    $pdo = db();
    $partnerFeeds = $pdo->query('SELECT pr.id AS rss_id, ps.name, pr.feed_url, COALESCE(pr.show_rss, pr.is_enabled, 1) AS rss_enabled FROM partner_rss pr INNER JOIN partner_sites ps ON ps.id = pr.partner_site_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $find = $pdo->prepare('SELECT id FROM rss_sources WHERE feed_url = :feed LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO rss_sources(name,feed_url,source_type,source_ref_id,is_enabled,created_at,updated_at) VALUES(:name,:feed,"partner_link",:ref,:enabled,NOW(),NOW())');
    $update = $pdo->prepare('UPDATE rss_sources SET name=:name,source_type="partner_link",source_ref_id=:ref,is_enabled=:enabled,updated_at=NOW() WHERE id=:id');
    $seenIds = [];

    foreach ($partnerFeeds as $feed) {
        $feedUrl = trim((string)($feed['feed_url'] ?? ''));
        if ($feedUrl === '') {
            continue;
        }
        $rssId = (int)($feed['rss_id'] ?? 0);
        if ($rssId > 0) {
            $seenIds[] = $rssId;
        }
        $name = trim((string)($feed['name'] ?? 'RSS'));
        $enabled = (int)($feed['rss_enabled'] ?? 0) === 1 && rss_http_url($feedUrl) !== '' ? 1 : 0;
        $find->execute([':feed' => $feedUrl]);
        $id = (int)($find->fetchColumn() ?: 0);
        if ($id > 0) {
            $update->execute([':name' => $name, ':ref' => $rssId > 0 ? $rssId : null, ':enabled' => $enabled, ':id' => $id]);
            continue;
        }
        $insert->execute([':name' => $name, ':feed' => $feedUrl, ':ref' => $rssId > 0 ? $rssId : null, ':enabled' => $enabled]);
    }

    if ($seenIds !== []) {
        $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
        $stmt = $pdo->prepare('UPDATE rss_sources SET is_enabled=0, updated_at=NOW() WHERE source_type = "partner_link" AND source_ref_id IS NOT NULL AND source_ref_id NOT IN (' . $placeholders . ')');
        $stmt->execute($seenIds);
    } else {
        $pdo->exec('UPDATE rss_sources SET is_enabled=0, updated_at=NOW() WHERE source_type = "partner_link"');
    }
}

function rss_refresh_stale_sources(int $maxSources = 1, int $staleAfterSec = 900, int $fetchTimeoutSec = 2): void
{
    $pdo = db();
    $stmt = $pdo->query('SELECT id,last_fetched_at FROM rss_sources WHERE is_enabled=1 AND source_type = "partner_link" ORDER BY COALESCE(last_fetched_at, "1970-01-01 00:00:00") ASC, id ASC');
    $sources = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $refreshed = 0;

    foreach ($sources as $source) {
        $lastFetched = strtotime((string)($source['last_fetched_at'] ?? '')) ?: 0;
        if ($lastFetched >= time() - max(60, $staleAfterSec)) {
            continue;
        }
        rss_fetch_source((int)$source['id'], $fetchTimeoutSec);
        $refreshed++;
        if ($refreshed >= max(1, $maxSources)) {
            break;
        }
    }
}

function rss_widget_bootstrap(bool $syncSources = true): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    try {
        rss_ensure_tables();
        if ($syncSources) {
            rss_sync_partner_sources();
        }
    } catch (Throwable $e) {
        error_log('[rss] sidebar bootstrap skipped: ' . $e->getMessage());
    }
}

function rss_normalize_url(string $url): string
{
    $trimmed = rss_http_url($url);
    if ($trimmed === '') {
        return '';
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    foreach (array_keys($query) as $key) {
        $lower = strtolower((string)$key);
        if (str_starts_with($lower, 'utm_') || in_array($lower, ['gclid', 'fbclid'], true)) {
            unset($query[$key]);
        }
    }
    ksort($query);
    $normalizedQuery = http_build_query($query);
    return $scheme . '|' . $host . '|' . $path . ($normalizedQuery !== '' ? '?' . $normalizedQuery : '');
}

function rss_normalize_display_key(array $item): string
{
    $link = rss_normalize_url((string)($item['link'] ?? ''));
    if ($link !== '') {
        return $link;
    }

    $guid = trim((string)($item['guid'] ?? ''));
    if ($guid !== '') {
        return 'guid|' . mb_strtolower($guid);
    }

    return mb_strtolower(trim((string)($item['title'] ?? '')));
}

function rss_pick_display_items(int $limit, bool $requireImage = false, int $days = 14): array
{
    if ($limit <= 0) {
        return [];
    }

    $days = max(1, $days);
    $pdo = db();
    $rows = [];

    $sqlWithImage = 'SELECT ri.source_id, rs.name AS source_name, ri.title, ri.url, ri.guid, ri.published_at, ri.image_url '
        . 'FROM rss_items ri '
        . 'INNER JOIN rss_sources rs ON rs.id = ri.source_id '
        . 'WHERE rs.is_enabled = 1 AND rs.source_type = "partner_link" AND ri.published_at >= DATE_SUB(NOW(), INTERVAL :days DAY) '
        . 'ORDER BY ri.published_at DESC, ri.id DESC';

    $sqlWithoutImage = 'SELECT ri.source_id, rs.name AS source_name, ri.title, ri.url, ri.guid, ri.published_at '
        . 'FROM rss_items ri '
        . 'INNER JOIN rss_sources rs ON rs.id = ri.source_id '
        . 'WHERE rs.is_enabled = 1 AND rs.source_type = "partner_link" AND ri.published_at >= DATE_SUB(NOW(), INTERVAL :days DAY) '
        . 'ORDER BY ri.published_at DESC, ri.id DESC';

    try {
        $stmt = $pdo->prepare($sqlWithImage);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $stmt = $pdo->prepare($sqlWithoutImage);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    $seedBase = random_int(1, PHP_INT_MAX);
    $seenKeys = [];
    $sourceBuckets = [];

    foreach ($rows as $row) {
        $sourceId = (int)($row['source_id'] ?? 0);
        if ($sourceId <= 0) {
            continue;
        }

        $itemLink = rss_http_url((string)($row['url'] ?? ''));
        if ($itemLink === '') {
            continue;
        }
        $imageUrl = rss_http_url((string)($row['image_url'] ?? ''));
        if ($requireImage && $imageUrl === '') {
            continue;
        }

        $item = [
            'title' => (string)($row['title'] ?? ''),
            'link' => $itemLink,
            'guid' => (string)($row['guid'] ?? ''),
            'published_at' => (string)($row['published_at'] ?? ''),
            'image_url' => $imageUrl,
            'source_id' => $sourceId,
            'source_name' => (string)($row['source_name'] ?? ''),
        ];

        $dedupeKey = rss_normalize_display_key($item);
        if ($dedupeKey !== '' && isset($seenKeys[$dedupeKey])) {
            continue;
        }
        if ($dedupeKey !== '') {
            $seenKeys[$dedupeKey] = true;
        }

        if (!isset($sourceBuckets[$sourceId])) {
            $sourceBuckets[$sourceId] = [];
        }
        $sourceBuckets[$sourceId][] = $item;
    }

    if ($sourceBuckets === []) {
        return [];
    }

    $stableShuffle = static function (array $items, int $seed): array {
        usort($items, static function (array $a, array $b) use ($seed): int {
            $ka = crc32(json_encode($a, JSON_UNESCAPED_UNICODE) . '|' . $seed);
            $kb = crc32(json_encode($b, JSON_UNESCAPED_UNICODE) . '|' . $seed);
            return $ka <=> $kb;
        });
        return $items;
    };

    $sourceOrder = array_keys($sourceBuckets);
    usort($sourceOrder, static function (int $a, int $b) use ($seedBase): int {
        return crc32((string)$a . '|' . $seedBase) <=> crc32((string)$b . '|' . $seedBase);
    });

    foreach ($sourceBuckets as $sourceId => $items) {
        $sourceBuckets[$sourceId] = $stableShuffle($items, $seedBase ^ $sourceId);
    }

    $picked = [];
    while (count($picked) < $limit) {
        $addedInLoop = false;
        foreach ($sourceOrder as $sourceId) {
            if (count($picked) >= $limit) {
                break;
            }
            if (!isset($sourceBuckets[$sourceId]) || $sourceBuckets[$sourceId] === []) {
                continue;
            }
            $item = array_shift($sourceBuckets[$sourceId]);
            if (is_array($item)) {
                $picked[] = $item;
                $addedInLoop = true;
            }
        }
        if (!$addedInLoop) {
            break;
        }
    }

    return $picked;
}
