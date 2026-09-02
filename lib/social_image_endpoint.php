<?php

declare(strict_types=1);

const PCF_SOCIAL_IMAGE_MAX_BYTES = 4500000;
const PCF_SOCIAL_IMAGE_SUCCESS_TTL = 259200;
const PCF_SOCIAL_IMAGE_ERROR_TTL = 180;
const PCF_SOCIAL_IMAGE_MAX_REDIRECTS = 3;
const PCF_SOCIAL_IMAGE_MAX_CANDIDATES = 8;
const PCF_SOCIAL_IMAGE_MAX_DIMENSION = 4096;
const PCF_SOCIAL_IMAGE_MAX_PIXELS = 16777216;
const PCF_SOCIAL_IMAGE_FETCH_BUDGET_SECONDS = 8.0;
const PCF_SOCIAL_IMAGE_GENERIC_FALLBACK = 'iVBORw0KGgoAAAANSUhEUgAAAlgAAAE7CAIAAACOjGjiAAAD40lEQVR42u3VsQ0AEBRAQUOIWiQW0JrSxGwgav+Sm+A1L+25ACCsJAEARggARggARggARggARggARggAn48wlwoAYRkhAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIgBGqAIARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAmCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAHAfYesDAMIyQgCMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEAAjVAEAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwTACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHgYYR7LgAIywgBMEIAMEIAMEIAMEIAMEIA+NwBgj//HEYGgn8AAAAASUVORK5CYII=';

function pcf_social_image_host_allowed(string $host, array $roots): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }
    foreach ($roots as $root) {
        $root = strtolower(rtrim(trim((string)$root), '.'));
        if ($root !== '' && ($host === $root || str_ends_with($host, '.' . $root))) {
            return true;
        }
    }
    return false;
}

function pcf_social_image_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function pcf_social_image_resolve_public_ips(string $host): array
{
    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '' && pcf_social_image_public_ip($ip)) {
                $ips[$ip] = true;
            }
        }
    }
    if ($ips === []) {
        foreach ((array)@gethostbynamel($host) as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '' && pcf_social_image_public_ip($ip)) {
                $ips[$ip] = true;
            }
        }
    }
    $resolved = array_keys($ips);
    usort($resolved, static function (string $left, string $right): int {
        $leftV4 = filter_var($left, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $rightV4 = filter_var($right, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        return $leftV4 === $rightV4 ? 0 : ($leftV4 ? -1 : 1);
    });
    return array_slice($resolved, 0, 2);
}

function pcf_social_image_normalize_url(string $value, array $hostRoots): string
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
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http', 'https'], true)
        || !pcf_social_image_host_allowed($host, $hostRoots)
        || !in_array($port, [80, 443], true)
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return '';
    }

    $normalized = 'https://' . $host . (string)($parts['path'] ?? '/');
    if (isset($parts['query']) && (string)$parts['query'] !== '') {
        $normalized .= '?' . (string)$parts['query'];
    }
    return $normalized;
}

function pcf_social_image_collect_urls(mixed $value, array $hostRoots, array &$urls): void
{
    if (is_array($value)) {
        foreach ($value as $child) {
            pcf_social_image_collect_urls($child, $hostRoots, $urls);
        }
        return;
    }
    if (!is_string($value)) {
        return;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return;
    }
    if (($trimmed[0] ?? '') === '[' || ($trimmed[0] ?? '') === '{') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            pcf_social_image_collect_urls($decoded, $hostRoots, $urls);
            return;
        }
    }
    foreach (preg_split('/[\r\n,|\s]+/', $trimmed) ?: [] as $part) {
        $url = pcf_social_image_normalize_url((string)$part, $hostRoots);
        if ($url !== '') {
            $urls[$url] = true;
        }
    }
}

function pcf_social_image_candidates(array $item, array $config): array
{
    $hostRoots = (array)($config['allowed_hosts'] ?? []);
    $urls = [];
    $provider = $config['candidate_provider'] ?? null;
    if (is_callable($provider)) {
        try {
            foreach ((array)$provider($item) as $candidate) {
                pcf_social_image_collect_urls($candidate, $hostRoots, $urls);
            }
        } catch (Throwable) {
        }
    }
    foreach ((array)($config['candidate_fields'] ?? []) as $field) {
        $field = (string)$field;
        if ($field !== '' && array_key_exists($field, $item)) {
            pcf_social_image_collect_urls($item[$field], $hostRoots, $urls);
        }
    }
    $raw = $item['raw_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ((array)($config['raw_candidate_fields'] ?? []) as $field) {
                $field = (string)$field;
                if ($field !== '' && array_key_exists($field, $decoded)) {
                    pcf_social_image_collect_urls($decoded[$field], $hostRoots, $urls);
                }
            }
        }
    }
    return array_slice(array_keys($urls), 0, PCF_SOCIAL_IMAGE_MAX_CANDIDATES);
}

function pcf_social_image_inspect(string $bytes, string $reportedType = ''): array
{
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (strlen($bytes) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
        return [];
    }
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) {
            return [];
        }
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        $type = strtolower((string)($info['mime'] ?? ''));
        if ($width <= 0 || $height <= 0
            || $width > PCF_SOCIAL_IMAGE_MAX_DIMENSION
            || $height > PCF_SOCIAL_IMAGE_MAX_DIMENSION
            || $width * $height > PCF_SOCIAL_IMAGE_MAX_PIXELS
            || !in_array($type, $allowed, true)) {
            return [];
        }
        return ['type' => $type, 'width' => $width, 'height' => $height];
    }

    $type = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $type = strtolower((string)@finfo_buffer($finfo, $bytes));
            @finfo_close($finfo);
        }
    }
    if ($type === '') {
        $type = strtolower(trim((string)(explode(';', $reportedType, 2)[0] ?? '')));
    }
    return in_array($type, $allowed, true) ? ['type' => $type, 'width' => 0, 'height' => 0] : [];
}

function pcf_social_image_redirect_url(string $currentUrl, string $location, array $hostRoots): string
{
    $location = trim($location);
    if ($location === '') {
        return '';
    }
    if (str_starts_with($location, '//')) {
        return pcf_social_image_normalize_url('https:' . $location, $hostRoots);
    }
    if (preg_match('#^https?://#i', $location) === 1) {
        return pcf_social_image_normalize_url($location, $hostRoots);
    }
    $parts = parse_url($currentUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $base = 'https://' . (string)$parts['host'];
    if (str_starts_with($location, '/')) {
        return pcf_social_image_normalize_url($base . $location, $hostRoots);
    }
    if (str_starts_with($location, '?')) {
        return pcf_social_image_normalize_url($base . (string)($parts['path'] ?? '/') . $location, $hostRoots);
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string)($parts['path'] ?? '/'))), '/');
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
    return pcf_social_image_normalize_url($base . '/' . implode('/', $segments), $hostRoots);
}

function pcf_social_image_fetch_once(string $url, array $config, float $deadline): ?array
{
    if (!function_exists('curl_init') || microtime(true) >= $deadline) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    $hostRoots = (array)($config['allowed_hosts'] ?? []);
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !pcf_social_image_host_allowed($host, $hostRoots)) {
        return null;
    }

    foreach (pcf_social_image_resolve_public_ips($host) as $ip) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.15) {
            return null;
        }
        $body = '';
        $reportedType = '';
        $location = '';
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $timeoutMs = max(250, min(4500, (int)floor($remaining * 1000)));
        $connectTimeoutMs = max(200, min(1800, $timeoutMs));
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_USERAGENT => (string)($config['user_agent'] ?? 'PinkClub-SocialCard/2.0'),
            CURLOPT_REFERER => (string)($config['referer'] ?? ''),
            CURLOPT_HTTPHEADER => ['Accept: image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host . ':443:' . $pin],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$reportedType, &$location, &$tooLarge): int {
                if (stripos($header, 'Content-Type:') === 0) {
                    $reportedType = trim(substr($header, 13));
                } elseif (stripos($header, 'Content-Length:') === 0) {
                    $length = (int)trim(substr($header, 15));
                    if ($length > PCF_SOCIAL_IMAGE_MAX_BYTES) {
                        $tooLarge = true;
                    }
                } elseif (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if ($tooLarge || strlen($body) + strlen($chunk) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $tooLarge) {
            continue;
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            return ['redirect' => $location];
        }
        if ($status >= 200 && $status < 300 && $body !== '') {
            $inspection = pcf_social_image_inspect($body, $reportedType);
            if ($inspection !== []) {
                return ['bytes' => $body] + $inspection;
            }
        }
    }
    return null;
}

function pcf_social_image_fetch(string $url, array $config, float $deadline): ?array
{
    $hostRoots = (array)($config['allowed_hosts'] ?? []);
    $current = pcf_social_image_normalize_url($url, $hostRoots);
    $seen = [];
    for ($hop = 0; $current !== '' && $hop <= PCF_SOCIAL_IMAGE_MAX_REDIRECTS; $hop++) {
        if (isset($seen[$current]) || microtime(true) >= $deadline) {
            return null;
        }
        $seen[$current] = true;
        $result = pcf_social_image_fetch_once($current, $config, $deadline);
        if (!is_array($result)) {
            return null;
        }
        if (isset($result['bytes'], $result['type'])) {
            return $result;
        }
        if ($hop >= PCF_SOCIAL_IMAGE_MAX_REDIRECTS) {
            return null;
        }
        $current = pcf_social_image_redirect_url($current, (string)($result['redirect'] ?? ''), $hostRoots);
    }
    return null;
}

function pcf_social_image_not_modified(string $etag, int $mtime): bool
{
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag)) {
        return true;
    }
    if ($ifNoneMatch !== '' || $mtime <= 0) {
        return false;
    }
    $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    return $ifModifiedSince !== false && $ifModifiedSince >= $mtime;
}

function pcf_social_image_serve_file(string $path, string $type, bool $headOnly, string $cacheStatus): never
{
    $size = (int)@filesize($path);
    $mtime = (int)@filemtime($path);
    $etag = '"' . hash('sha256', basename($path) . '|' . (string)$size . '|' . (string)$mtime) . '"';
    header('Content-Type: ' . $type);
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    header('ETag: ' . $etag);
    header('X-PCF-Social-Image: ' . $cacheStatus);
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }
    if (pcf_social_image_not_modified($etag, $mtime)) {
        http_response_code(304);
        exit;
    }
    if ($size >= 0) {
        header('Content-Length: ' . $size);
    }
    if (!$headOnly) {
        readfile($path);
    }
    exit;
}

function pcf_social_image_serve_bytes(string $bytes, string $type, bool $headOnly, string $cacheStatus): never
{
    $etag = '"' . hash('sha256', $bytes) . '"';
    header('Content-Type: ' . $type);
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=' . PCF_SOCIAL_IMAGE_ERROR_TTL);
    header('ETag: ' . $etag);
    header('X-PCF-Social-Image: ' . $cacheStatus);
    if (pcf_social_image_not_modified($etag, 0)) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($bytes));
    if (!$headOnly) {
        echo $bytes;
    }
    exit;
}

function pcf_social_image_logo_path(): string
{
    try {
        if (function_exists('site_setting_get')) {
            $path = trim((string)site_setting_get('site.logo_path', ''));
            if ($path !== '') {
                return $path;
            }
        }
        if (function_exists('setting')) {
            $path = trim((string)setting('site.logo_path', ''));
            if ($path === '') {
                $path = trim((string)setting('site_logo', ''));
            }
            return $path;
        }
    } catch (Throwable) {
    }
    return '';
}

function pcf_social_image_fallback(bool $headOnly, string $cacheStatus = 'FALLBACK'): never
{
    $logo = pcf_social_image_logo_path();
    if ($logo !== '') {
        $relative = ltrim($logo, '/');
        foreach ([dirname(__DIR__) . '/' . $relative, dirname(__DIR__) . '/public/' . $relative] as $path) {
            if (!is_file($path) || !is_readable($path) || (int)@filesize($path) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') {
                continue;
            }
            $inspection = pcf_social_image_inspect($bytes);
            if ($inspection !== []) {
                pcf_social_image_serve_file($path, (string)$inspection['type'], $headOnly, $cacheStatus);
            }
        }
    }
    $generic = base64_decode(PCF_SOCIAL_IMAGE_GENERIC_FALLBACK, true);
    if (!is_string($generic) || $generic === '') {
        http_response_code(503);
        exit;
    }
    pcf_social_image_serve_bytes($generic, 'image/png', $headOnly, $cacheStatus);
}

function pcf_social_image_extension(string $type): string
{
    return match ($type) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

function pcf_social_image_run(array $config): never
{
    header('X-Content-Type-Options: nosniff', true);
    header('Referrer-Policy: no-referrer', true);
    header('X-Robots-Tag: noindex, nofollow', true);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    $headOnly = $method === 'HEAD';
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($id) || $id <= 0) {
        http_response_code(404);
        exit;
    }

    $itemSql = trim((string)($config['item_sql'] ?? 'SELECT * FROM items WHERE id = :id LIMIT 1'));
    try {
        $stmt = db()->prepare($itemSql);
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $item = false;
    }
    if (!is_array($item)) {
        http_response_code(404);
        exit;
    }

    $serviceKey = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($config['service_key'] ?? 'pinkclub'));
    $serviceKey = is_string($serviceKey) && $serviceKey !== '' ? strtolower($serviceKey) : 'pinkclub';
    $candidates = pcf_social_image_candidates($item, $config);
    $revision = hash('sha256', implode('|', [
        'v2',
        $serviceKey,
        (string)$id,
        (string)($item['updated_at'] ?? ''),
        ...$candidates,
    ]));

    $cacheDir = dirname(__DIR__) . '/storage/cache/social-images';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        pcf_social_image_fallback($headOnly, 'FALLBACK-STORAGE');
    }

    $cacheKey = hash('sha256', $serviceKey . '|' . (string)$id);
    $metaPath = $cacheDir . '/' . $cacheKey . '.json';
    $errorPath = $cacheDir . '/' . $cacheKey . '-' . substr($revision, 0, 12) . '.error';
    $lockPath = $cacheDir . '/' . $cacheKey . '.lock';
    $serveCached = static function (bool $allowStale = false, string $status = 'HIT') use ($cacheDir, $metaPath, $headOnly, $revision): void {
        if (!is_file($metaPath)) {
            return;
        }
        $meta = json_decode((string)@file_get_contents($metaPath), true);
        if (!is_array($meta) || !hash_equals($revision, (string)($meta['revision'] ?? ''))) {
            return;
        }
        $file = $cacheDir . '/' . basename((string)($meta['file'] ?? ''));
        $type = (string)($meta['type'] ?? '');
        if (!is_file($file) || $type === '' || (int)@filesize($file) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
            return;
        }
        $fresh = time() - (int)@filemtime($file) < PCF_SOCIAL_IMAGE_SUCCESS_TTL;
        if ($fresh || $allowStale) {
            pcf_social_image_serve_file($file, $type, $headOnly, $fresh ? $status : 'STALE');
        }
    };

    $serveCached();
    if (is_file($errorPath) && time() - (int)@filemtime($errorPath) < PCF_SOCIAL_IMAGE_ERROR_TTL) {
        $serveCached(true, 'STALE');
        pcf_social_image_fallback($headOnly, 'NEGATIVE');
    }

    $lock = @fopen($lockPath, 'c');
    if (is_resource($lock) && !@flock($lock, LOCK_EX | LOCK_NB)) {
        $serveCached(true, 'STALE');
        @flock($lock, LOCK_EX);
    }
    $serveCached();

    $deadline = microtime(true) + PCF_SOCIAL_IMAGE_FETCH_BUDGET_SECONDS;
    foreach ($candidates as $candidate) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $result = pcf_social_image_fetch((string)$candidate, $config, $deadline);
        if (!is_array($result)) {
            continue;
        }
        $bytes = (string)($result['bytes'] ?? '');
        $type = (string)($result['type'] ?? '');
        if ($bytes === '' || $type === '' || strlen($bytes) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
            continue;
        }
        $fileName = $cacheKey . '.' . pcf_social_image_extension($type);
        $path = $cacheDir . '/' . $fileName;
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $tmp = $path . '.' . $suffix . '.tmp';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            continue;
        }
        $meta = json_encode([
            'file' => $fileName,
            'type' => $type,
            'width' => (int)($result['width'] ?? 0),
            'height' => (int)($result['height'] ?? 0),
            'revision' => $revision,
            'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($meta)) {
            $metaTmp = $metaPath . '.' . $suffix . '.tmp';
            if (@file_put_contents($metaTmp, $meta, LOCK_EX) !== false) {
                @rename($metaTmp, $metaPath);
            } else {
                @unlink($metaTmp);
            }
        }
        foreach (glob($cacheDir . '/' . $cacheKey . '-*.error') ?: [] as $oldError) {
            @unlink($oldError);
        }
        foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
            $oldImage = $cacheDir . '/' . $cacheKey . '.' . $extension;
            if ($oldImage !== $path) {
                @unlink($oldImage);
            }
        }
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        pcf_social_image_serve_file($path, $type, $headOnly, 'MISS');
    }

    $serveCached(true, 'STALE');
    foreach (glob($cacheDir . '/' . $cacheKey . '-*.error') ?: [] as $oldError) {
        if ($oldError !== $errorPath) {
            @unlink($oldError);
        }
    }
    @touch($errorPath);
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    pcf_social_image_fallback($headOnly);
}
