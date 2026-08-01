<?php

declare(strict_types=1);

/**
 * ジャンル・メーカーなどの公開ディレクトリ一覧をJSONへ事前生成する。
 * キャッシュが期限切れ、または元データが更新済みでも古い内容を先に返し、
 * 終了処理で安全に新しいJSONへ差し替える。
 */
function pcf_public_directory_cache_rows(string $kind, int $ttlSeconds = 21600): array
{
    $config = pcf_public_directory_cache_config($kind);
    if ($config === null) {
        return [];
    }

    $ttlSeconds = max(300, min(86400, $ttlSeconds));
    $cacheFile = pcf_public_directory_cache_file($kind);
    $cached = pcf_public_directory_cache_read($cacheFile);

    if ($cached !== null) {
        $generatedAt = (int)($cached['generated_at'] ?? 0);
        $cachedSourceVersion = (int)($cached['source_version'] ?? 0);
        $currentSourceVersion = pcf_public_directory_cache_source_version($kind);
        $rows = is_array($cached['rows'] ?? null) ? $cached['rows'] : [];

        $isFreshByTime = $generatedAt > 0 && (time() - $generatedAt) < $ttlSeconds;
        $isFreshBySource = $currentSourceVersion <= 0 || $cachedSourceVersion === $currentSourceVersion;
        if ($isFreshByTime && $isFreshBySource) {
            return $rows;
        }

        pcf_public_directory_cache_schedule_refresh($kind);
        return $rows;
    }

    $rebuilt = pcf_public_directory_cache_rebuild($kind);
    return $rebuilt ?? [];
}

function pcf_public_directory_cache_rebuild(string $kind): ?array
{
    $config = pcf_public_directory_cache_config($kind);
    if ($config === null || !function_exists('db')) {
        return null;
    }

    $cacheFile = pcf_public_directory_cache_file($kind);
    $cacheDirectory = dirname($cacheFile);
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        return null;
    }
    if (!is_writable($cacheDirectory)) {
        return null;
    }

    $lockFile = $cacheDirectory . '/.' . $kind . '.lock';
    $lockHandle = @fopen($lockFile, 'c');
    if ($lockHandle === false) {
        return null;
    }

    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        @fclose($lockHandle);
        return null;
    }

    try {
        $table = $config['table'];
        if (in_array($kind, ['genres', 'makers'], true)) {
            $relation = $kind === 'genres' ? 'item_genres' : 'item_makers';
            $stmt = db()->query(
                "SELECT {$table}.id, {$table}.duga_id, {$table}.name
                 FROM {$table}
                 WHERE {$table}.name IS NOT NULL
                   AND {$table}.name <> ''
                   AND EXISTS (
                     SELECT 1
                     FROM {$relation}
                     INNER JOIN items ON items.id = {$relation}.item_id
                     WHERE {$relation}.duga_id = {$table}.duga_id
                       AND " . items_product_source_where('items') . "
                   )
                 ORDER BY {$table}.name ASC, {$table}.id ASC"
            );
        } else {
            $stmt = db()->query("SELECT id, duga_id, name FROM {$table} WHERE name IS NOT NULL AND name <> '' ORDER BY name ASC, id ASC");
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $payload = json_encode([
            'version' => 1,
            'kind' => $kind,
            'generated_at' => time(),
            'source_version' => pcf_public_directory_cache_source_version($kind),
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable) {
            $suffix = uniqid('', true);
        }
        $temporaryFile = $cacheDirectory . '/.' . basename($cacheFile) . '.' . $suffix . '.tmp';
        if (@file_put_contents($temporaryFile, $payload, LOCK_EX) === false) {
            @unlink($temporaryFile);
            return null;
        }
        if (!@rename($temporaryFile, $cacheFile)) {
            @unlink($temporaryFile);
            return null;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('public directory cache rebuild failed: ' . $kind . ': ' . $e->getMessage());
        return null;
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}

function pcf_public_directory_cache_source_version(string $kind): int
{
    $config = pcf_public_directory_cache_config($kind);
    if ($config === null || !function_exists('db')) {
        return 0;
    }

    try {
        $table = $config['table'];
        $value = db()->query("SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) FROM {$table}")->fetchColumn();
        return max(0, (int)$value);
    } catch (Throwable) {
        return 0;
    }
}

function pcf_public_directory_cache_schedule_refresh(string $kind): void
{
    static $scheduled = [];
    if (isset($scheduled[$kind])) {
        return;
    }
    $scheduled[$kind] = true;

    register_shutdown_function(static function () use ($kind): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        pcf_public_directory_cache_rebuild($kind);
    });
}

function pcf_public_directory_cache_read(string $cacheFile): ?array
{
    if (!is_file($cacheFile) || !is_readable($cacheFile)) {
        return null;
    }

    $json = @file_get_contents($cacheFile);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
        return null;
    }

    return $decoded;
}

function pcf_public_directory_cache_file(string $kind): string
{
    $suffix = in_array($kind, ['genres', 'makers'], true) ? '-public-v2' : '';
    return dirname(__DIR__) . '/storage/cache/public-directories/' . $kind . $suffix . '.json';
}

function pcf_public_directory_cache_config(string $kind): ?array
{
    return match ($kind) {
        'genres' => ['table' => 'genres'],
        'makers' => ['table' => 'makers'],
        default => null,
    };
}
