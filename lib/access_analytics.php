<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crawler_guard.php';
require_once __DIR__ . '/site_settings.php';

function analytics_beacon_marker_hash(): string
{
    return hash('sha256', 'pinkclub-browser-beacon');
}

function analytics_visitor_hash(string $ua): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        $ip = 'unknown';
    }
    $salt = trim((string)config_get('security.ip_hash_salt', ''));
    if ($salt === '') {
        $dbName = (string)config_get('db.name', config_get('db.dbname', 'pinkclub'));
        $salt = hash('sha256', __DIR__ . '|' . $dbName . '|pinkclub-analytics');
    }

    return hash_hmac('sha256', $ip, $salt);
}

function analytics_maybe_cleanup_old_logs(int $retentionDays = 730, int $batchSize = 2000, bool $forceCheck = false): void
{
    if (!$forceCheck && mt_rand(1, 20) !== 1) {
        return;
    }

    $retentionDays = max(365, min(3650, $retentionDays));
    $batchSize = max(100, min(5000, $batchSize));
    $lastCleanupAt = (int)(setting_get('analytics.cleanup.last_at', '0') ?? '0');
    if ($lastCleanupAt > 0 && $lastCleanupAt > time() - 21600) {
        return;
    }

    try {
        setting_set('analytics.cleanup.last_at', (string)time());
        $cutoffDateTime = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));
        $cutoffDate = date('Y-m-d', strtotime('-' . $retentionDays . ' days'));
        $targets = [
            ['table' => 'site_events', 'column' => 'created_at', 'cutoff' => $cutoffDateTime],
            ['table' => 'in_logs', 'column' => 'created_at', 'cutoff' => $cutoffDateTime],
            ['table' => 'out_logs', 'column' => 'created_at', 'cutoff' => $cutoffDateTime],
            ['table' => 'item_out_click_daily', 'column' => 'clicked_at', 'cutoff' => $cutoffDateTime],
            ['table' => 'page_views', 'column' => 'viewed_at', 'cutoff' => $cutoffDateTime],
            ['table' => 'visit_sessions', 'column' => 'stat_date', 'cutoff' => $cutoffDate],
        ];

        $deletedRows = 0;
        foreach ($targets as $target) {
            if (!db_table_exists((string)$target['table'])) {
                continue;
            }
            $sql = sprintf(
                'DELETE FROM `%s` WHERE `%s` < :cutoff ORDER BY `%s` ASC LIMIT %d',
                $target['table'],
                $target['column'],
                $target['column'],
                $batchSize
            );
            $stmt = db()->prepare($sql);
            $stmt->execute([':cutoff' => $target['cutoff']]);
            $deletedRows += $stmt->rowCount();
        }

        setting_set('analytics.cleanup.last_success_at', date('Y-m-d H:i:s'));
        setting_set('analytics.cleanup.last_deleted_rows', (string)$deletedRows);
        setting_set('analytics.cleanup.retention_days', (string)$retentionDays);
    } catch (Throwable $e) {
        error_log('analytics log cleanup failed: ' . $e->getMessage());
        try {
            setting_set('analytics.cleanup.last_error', mb_substr($e->getMessage(), 0, 500));
        } catch (Throwable) {
        }
    }
}

function analytics_track_beacon(): void
{
    if (!analytics_ensure_tables()) {
        return;
    }

    try {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (pcf_crawler_guard_is_known_crawler($ua)) {
        return;
    }
    $hash = analytics_visitor_hash($ua);
    $rawPath = (string)($_POST['path'] ?? '/');
    $path = (string)parse_url($rawPath, PHP_URL_PATH);
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }

    $queryParams = [];
    parse_str((string)(parse_url($rawPath, PHP_URL_QUERY) ?? ''), $queryParams);
    unset($queryParams['rank_period']);
    $requestQuery = http_build_query($queryParams);
    $pageKey = $path . ($requestQuery !== '' ? '?' . $requestQuery : '');
    $pathForStats = mb_substr($pageKey, 0, 255);
    $today = date('Y-m-d');
    $referrer = (string)($_POST['referrer'] ?? '');
    $refererHost = parse_url($referrer, PHP_URL_HOST) ?: '';
    $refCode = trim((string)($_POST['ref'] ?? ''));

    $pdo = db();
    $visitStmt = $pdo->prepare('INSERT IGNORE INTO visit_sessions(stat_date,visitor_hash,first_seen_at) VALUES(:d,:h,NOW())');
    $visitStmt->execute([':d' => $today, ':h' => $hash]);
    $isUniqueVisitor = $visitStmt->rowCount() === 1;

    $pdo->prepare("INSERT INTO site_events(event_type,path,referrer,ua_hash,ip_hash,session_id_hash,created_at) VALUES('pv',:path,:referrer,:ua,:ip,:marker,NOW())")->execute([
        ':path' => $pathForStats,
        ':referrer' => $referrer !== '' ? mb_substr($referrer, 0, 500) : null,
        ':ua' => $ua !== '' ? hash('sha256', $ua) : null,
        ':ip' => $hash,
        ':marker' => analytics_beacon_marker_hash(),
    ]);
    $pdo->prepare('INSERT INTO daily_stats(stat_date,pv,uu,in_count,out_count,updated_at) VALUES(:d,1,:uu,0,0,NOW()) ON DUPLICATE KEY UPDATE pv = pv + 1, uu = uu + VALUES(uu), updated_at = NOW()')->execute([':d' => $today, ':uu' => $isUniqueVisitor ? 1 : 0]);

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($refCode !== '' || ($refererHost !== '' && !str_contains(strtolower($refererHost), $host))) {
        $pdo->prepare('INSERT INTO in_logs(created_at,ref_code,referer_host,path) VALUES(NOW(),:ref,:host,:path)')->execute([
            ':ref' => $refCode,
            ':host' => mb_substr((string)$refererHost, 0, 255),
            ':path' => mb_substr($path, 0, 255),
        ]);
        $pdo->prepare('UPDATE daily_stats SET in_count = in_count + 1, updated_at = NOW() WHERE stat_date=:d')->execute([':d' => $today]);
    }
    } catch (Throwable $e) {
        analytics_disable_for_request($e);
    }
}

function analytics_log_out(string $targetUrl, string $refCode, string $path): void
{
    if (!analytics_ensure_tables()) {
        return;
    }
    try {
    $today = date('Y-m-d');
    $pdo = db();
    $pdo->prepare('INSERT INTO out_logs(created_at,ref_code,target_url,path) VALUES(NOW(),:ref,:url,:path)')->execute([
        ':ref' => mb_substr($refCode, 0, 64),
        ':url' => mb_substr($targetUrl, 0, 1000),
        ':path' => mb_substr($path, 0, 255),
    ]);
    $pdo->prepare('INSERT INTO daily_stats(stat_date,pv,uu,in_count,out_count,updated_at) VALUES(:d,0,0,0,1,NOW()) ON DUPLICATE KEY UPDATE out_count = out_count + 1, updated_at = NOW()')->execute([':d' => $today]);

    if (db_table_exists('item_out_click_daily')) {
        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE affiliate_url = :url LIMIT 1');
        $itemStmt->execute([':url' => $targetUrl]);
        $itemId = (int)($itemStmt->fetchColumn() ?: 0);
        if ($itemId > 0) {
            $visitorHash = analytics_visitor_hash((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $clickStmt = $pdo->prepare(
                'INSERT IGNORE INTO item_out_click_daily(item_id, click_date, visitor_hash, clicked_at)
                 VALUES(:item_id, :click_date, :visitor_hash, NOW())'
            );
            $clickStmt->execute([
                ':item_id' => $itemId,
                ':click_date' => $today,
                ':visitor_hash' => $visitorHash,
            ]);
        }
    }
    } catch (Throwable $e) {
        analytics_disable_for_request($e);
    }
}

function analytics_ensure_tables(): bool
{
    return empty($GLOBALS['__analytics_disabled_for_request']);
}

function analytics_disable_for_request(Throwable $e): void
{
    $GLOBALS['__analytics_disabled_for_request'] = true;
    error_log('analytics disabled: ' . $e->getMessage());
}
