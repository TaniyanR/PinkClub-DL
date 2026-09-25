<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PCF_SITE_MEDIA_KEYS = ['logo', 'favicon', 'ogp'];

function site_media_key_allowed(string $key): bool
{
    return in_array($key, PCF_SITE_MEDIA_KEYS, true);
}

function site_media_database_configured(): bool
{
    $config = app_config()['db'] ?? [];
    return is_array($config) && db_validate_config($config, true) === [];
}

function site_media_ensure_table(): bool
{
    static $attempted = false;
    static $ready = false;
    if ($attempted) {
        return $ready;
    }
    if (!site_media_database_configured()) {
        return false;
    }
    $attempted = true;

    // Existing installations should not need CREATE privilege on every visit.
    // Probe the table first and only run DDL when it is actually absent.
    try {
        db()->query('SELECT 1 FROM site_media LIMIT 1');
        $ready = true;
        return true;
    } catch (Throwable) {
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS site_media ('
            . 'media_key VARCHAR(32) NOT NULL,'
            . 'file_name VARCHAR(255) NOT NULL,'
            . 'mime_type VARCHAR(64) NOT NULL,'
            . 'width INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'height INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'byte_size INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'sha256 CHAR(64) NOT NULL,'
            . 'media_data LONGBLOB NOT NULL,'
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (media_key),'
            . 'KEY idx_site_media_updated_at (updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ready = true;
    } catch (Throwable $e) {
        error_log('[site_media] unable to ensure site_media table: ' . $e->getMessage());
    }

    return $ready;
}

function site_media_cache_clear(?string $key = null): void
{
    if ($key === null) {
        unset($GLOBALS['__site_media_meta_cache'], $GLOBALS['__site_media_blob_cache']);
        return;
    }
    foreach (['__site_media_meta_cache', '__site_media_blob_cache'] as $cacheName) {
        if (isset($GLOBALS[$cacheName]) && is_array($GLOBALS[$cacheName])) {
            unset($GLOBALS[$cacheName][$key]);
        }
    }
}

function site_media_meta_query(string $key): ?array
{
    $stmt = db()->prepare('SELECT media_key, file_name, mime_type, width, height, byte_size, sha256, created_at, updated_at FROM site_media WHERE media_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function site_media_blob_query(string $key): ?array
{
    $stmt = db()->prepare('SELECT media_key, file_name, mime_type, width, height, byte_size, sha256, media_data, created_at, updated_at FROM site_media WHERE media_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function site_media_query_with_repair(string $key, bool $withBlob): ?array
{
    if (!site_media_database_configured()) {
        return null;
    }
    try {
        return $withBlob ? site_media_blob_query($key) : site_media_meta_query($key);
    } catch (Throwable) {
        if (!site_media_ensure_table()) {
            return null;
        }
        try {
            return $withBlob ? site_media_blob_query($key) : site_media_meta_query($key);
        } catch (Throwable $e) {
            error_log('[site_media] read failed after schema repair: ' . $e->getMessage());
            return null;
        }
    }
}

function site_media_meta_get(string $key): ?array
{
    if (!site_media_key_allowed($key)) {
        return null;
    }

    if (isset($GLOBALS['__site_media_meta_cache'])
        && is_array($GLOBALS['__site_media_meta_cache'])
        && array_key_exists($key, $GLOBALS['__site_media_meta_cache'])) {
        $cached = $GLOBALS['__site_media_meta_cache'][$key];
        return is_array($cached) ? $cached : null;
    }

    $value = site_media_query_with_repair($key, false);
    if (!isset($GLOBALS['__site_media_meta_cache']) || !is_array($GLOBALS['__site_media_meta_cache'])) {
        $GLOBALS['__site_media_meta_cache'] = [];
    }
    $GLOBALS['__site_media_meta_cache'][$key] = $value;
    return $value;
}

function site_media_get(string $key): ?array
{
    if (!site_media_key_allowed($key)) {
        return null;
    }

    if (isset($GLOBALS['__site_media_blob_cache'])
        && is_array($GLOBALS['__site_media_blob_cache'])
        && array_key_exists($key, $GLOBALS['__site_media_blob_cache'])) {
        $cached = $GLOBALS['__site_media_blob_cache'][$key];
        return is_array($cached) ? $cached : null;
    }

    $value = site_media_query_with_repair($key, true);
    if (!isset($GLOBALS['__site_media_blob_cache']) || !is_array($GLOBALS['__site_media_blob_cache'])) {
        $GLOBALS['__site_media_blob_cache'] = [];
    }
    $GLOBALS['__site_media_blob_cache'][$key] = $value;
    if (is_array($value)) {
        $meta = $value;
        unset($meta['media_data']);
        if (!isset($GLOBALS['__site_media_meta_cache']) || !is_array($GLOBALS['__site_media_meta_cache'])) {
            $GLOBALS['__site_media_meta_cache'] = [];
        }
        $GLOBALS['__site_media_meta_cache'][$key] = $meta;
    }
    return $value;
}

function site_media_exists(string $key): bool
{
    return site_media_meta_get($key) !== null;
}

function site_media_put(string $key, string $fileName, string $mimeType, int $width, int $height, string $bytes): void
{
    if (!site_media_key_allowed($key)) {
        throw new InvalidArgumentException('Unsupported site media key.');
    }
    if ($bytes === '') {
        throw new InvalidArgumentException('Site media bytes are empty.');
    }

    $size = strlen($bytes);
    $sha256 = hash('sha256', $bytes);
    $stmt = db()->prepare(
        'INSERT INTO site_media (media_key, file_name, mime_type, width, height, byte_size, sha256, media_data, created_at, updated_at) '
        . 'VALUES (:key, :file_name, :mime_type, :width, :height, :byte_size, :sha256, :media_data, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), mime_type = VALUES(mime_type), width = VALUES(width), height = VALUES(height), byte_size = VALUES(byte_size), sha256 = VALUES(sha256), media_data = VALUES(media_data), updated_at = NOW()'
    );
    $stmt->bindValue(':key', $key, PDO::PARAM_STR);
    $stmt->bindValue(':file_name', $fileName, PDO::PARAM_STR);
    $stmt->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
    $stmt->bindValue(':width', max(0, $width), PDO::PARAM_INT);
    $stmt->bindValue(':height', max(0, $height), PDO::PARAM_INT);
    $stmt->bindValue(':byte_size', $size, PDO::PARAM_INT);
    $stmt->bindValue(':sha256', $sha256, PDO::PARAM_STR);
    $stmt->bindValue(':media_data', $bytes, PDO::PARAM_LOB);
    $stmt->execute();
    site_media_cache_clear($key);
}

function site_media_delete(string $key): void
{
    if (!site_media_key_allowed($key)) {
        return;
    }
    try {
        db()->prepare('DELETE FROM site_media WHERE media_key = :key')->execute([':key' => $key]);
    } catch (Throwable) {
        return;
    }
    site_media_cache_clear($key);
}

function site_media_public_path(string $key): string
{
    $media = site_media_meta_get($key);
    if (!is_array($media)) {
        return '';
    }

    $revision = substr((string)($media['sha256'] ?? ''), 0, 12);
    $mime = strtolower(trim((string)($media['mime_type'] ?? '')));
    $extension = match ($mime) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
        default => 'bin',
    };
    // Keep the harmless filename hint last so legacy pathinfo()-based favicon
    // code can still infer PNG vs ICO while the endpoint remains key based.
    $query = ['key' => $key];
    if ($revision !== '') {
        $query['v'] = $revision;
    }
    $query['file'] = $key . '.' . $extension;
    return 'site-media.php?' . http_build_query($query);
}

function site_media_public_url(string $key): string
{
    $path = site_media_public_path($key);
    return $path !== '' ? public_url($path) : '';
}

function site_media_url_or_legacy(string $key, string $legacyPath = ''): string
{
    $dbUrl = site_media_public_url($key);
    if ($dbUrl !== '') {
        return $dbUrl;
    }

    $legacyPath = ltrim(trim($legacyPath), '/');
    return $legacyPath !== '' ? public_versioned_url($legacyPath) : '';
}
