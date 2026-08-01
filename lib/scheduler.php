<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app.php';

function scheduler_tick(): array
{
    $pdo = db();
    scheduler_ensure_schedule_table($pdo);
    scheduler_seed_default_schedules($pdo);
    scheduler_apply_auto_settings($pdo);

    $stmt = $pdo->query("SELECT * FROM api_schedules WHERE is_enabled = 1 AND schedule_type = 'items' ORDER BY id");
    $schedules = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $jobs = [];
    foreach ($schedules as $schedule) {
        if (!scheduler_is_due($schedule)) {
            continue;
        }
        $scheduleType = (string)$schedule['schedule_type'];
        $lockUntil = date('Y-m-d H:i:s', time() + ($scheduleType === 'items' ? 300 : 55));
        $locked = $pdo->prepare('UPDATE api_schedules SET lock_until = :lock_until WHERE id = :id AND (lock_until IS NULL OR lock_until < NOW())');
        $locked->execute(['lock_until' => $lockUntil, 'id' => $schedule['id']]);
        if ($locked->rowCount() === 0) {
            $jobs[] = ['schedule_type' => $scheduleType, 'status' => 'skipped', 'synced_count' => 0, 'message' => 'ロック取得失敗のためスキップ'];
            continue;
        }

        try {
            $result = scheduler_run_schedule($schedule);
            $jobStatus = scheduler_schedule_result_status($result);
            if ($jobStatus === 'success') {
                $pdo->prepare('UPDATE api_schedules SET last_run_at = NOW(), lock_until = NULL WHERE id = ?')->execute([$schedule['id']]);
            } else {
                $pdo->prepare('UPDATE api_schedules SET lock_until = NULL WHERE id = ?')->execute([$schedule['id']]);
            }
            $jobs[] = array_merge(['schedule_type' => $scheduleType, 'status' => $jobStatus], $result);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE api_schedules SET lock_until = NULL WHERE id = ?')->execute([$schedule['id']]);
            $jobs[] = ['schedule_type' => $scheduleType, 'status' => 'error', 'synced_count' => 0, 'message' => $e->getMessage()];
        }
    }

    if ($jobs !== []) {
        $hasError = false;
        $hasSuccess = false;
        $syncedCount = 0;
        foreach ($jobs as $job) {
            if (($job['status'] ?? '') === 'error') {
                $hasError = true;
            }
            if (($job['status'] ?? '') === 'success') {
                $hasSuccess = true;
            }
            $syncedCount += (int)($job['synced_count'] ?? 0);
        }
        return [
            'status' => $hasError ? 'error' : ($hasSuccess ? 'ran' : 'idle'),
            'schedule_type' => (string)($jobs[0]['schedule_type'] ?? ''),
            'synced_count' => $syncedCount,
            'message' => scheduler_jobs_message($jobs),
            'jobs' => $jobs,
        ];
    }

    return ['status' => 'idle', 'message' => '実行対象なし', 'jobs' => []];
}

function scheduler_schedule_result_status(array $result): string
{
    $message = (string)($result['message'] ?? '');
    if ($message === 'ロック取得失敗のためスキップ' || $message === 'アプリケーションID / 代理店ID 未設定のためスキップ') {
        return 'skipped';
    }
    return 'success';
}

function scheduler_jobs_message(array $jobs): string
{
    $messages = [];
    foreach ($jobs as $job) {
        $type = (string)($job['schedule_type'] ?? '');
        $label = match ($type) {
            'items' => '商品',
            default => $type,
        };
        $message = (string)($job['message'] ?? '');
        $messages[] = $label . ': ' . $message;
    }
    return implode(' / ', $messages);
}

function scheduler_run_schedule(array $schedule): array
{
    $settings = settings_get();
    $type = (string)$schedule['schedule_type'];

    return match ($type) {
        'items' => scheduler_run_items_schedule(duga_sync_service('items'), $settings),
        default => ['synced_count' => 0, 'message' => '未対応スケジュールです'],
    };
}

function scheduler_run_items_schedule(DugaSyncService $service, array $settings): array
{
    $compoundRaw = scheduler_split_lines(site_setting_get('item_sync_compound_keywords', ''), 5);
    $excludeKeywords = scheduler_split_lines(site_setting_get('item_sync_exclude_keywords', ''), 5);
    $compoundKeyword = '';
    foreach ($compoundRaw as $raw) {
        $generated = scheduler_build_compound_keyword($raw);
        if ($generated !== '') {
            $compoundKeyword = $generated;
            break;
        }
    }

    // 取得漏れを避けるためAPIは新着順。公開側の「新着作品」はrelease_date順で表示する。
    $extraParams = ['sort' => 'new'];
    if ($compoundKeyword !== '') {
        $extraParams['keyword'] = $compoundKeyword;
    }

    $pdo = db();
    scheduler_ensure_job_state_table($pdo);
    $pdo->prepare("INSERT INTO sync_job_state (job_key, next_offset, updated_at) VALUES ('items', 1, NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at")->execute();
    $lockUntil = date('Y-m-d H:i:s', time() + 300);
    $lockStmt = $pdo->prepare("UPDATE sync_job_state SET lock_until = :lock_until WHERE job_key = 'items' AND (lock_until IS NULL OR lock_until < NOW())");
    $lockStmt->execute([':lock_until' => $lockUntil]);
    if ($lockStmt->rowCount() === 0) {
        return ['synced_count' => 0, 'message' => 'ロック取得失敗のためスキップ'];
    }
    $skip = scheduler_skip_missing_credentials($pdo, 'items');
    if ($skip !== null) {
        $pdo->prepare("UPDATE sync_job_state SET lock_until = NULL, updated_at = NOW() WHERE job_key = 'items'")->execute();
        return $skip;
    }
    $normalizerVersion = '5';
    $needsNormalizerRefresh = site_setting_get('duga_normalizer_version', '') !== $normalizerVersion;
    $stateStmt = $pdo->prepare("SELECT next_offset FROM sync_job_state WHERE job_key = 'items' LIMIT 1");
    $stateStmt->execute();
    $offset = $needsNormalizerRefresh ? 1 : max(1, (int)$stateStmt->fetchColumn());
    if ($offset > 50000) {
        $offset = 1;
    }
    if (!$needsNormalizerRefresh && $offset < 101) {
        $offset = 101;
    }

    try {
        $result = $service->syncItemsBatch(
            (string)($settings['site'] ?? 'DUGA'),
            (string)($settings['service'] ?? 'digital'),
            (string)($settings['floor'] ?? 'videoa'),
            settings_allowed_item_sync_batch((int)($settings['item_sync_batch'] ?? 100)),
            $offset,
            $extraParams,
            $excludeKeywords
        );
        $nextOffset = max(1, (int)($result['next_offset'] ?? 1));
        if ($nextOffset > 50000) {
            $nextOffset = 1;
        }
        $message = (string)($result['message'] ?? '商品を同期しました');
        $pdo->prepare("UPDATE sync_job_state SET next_offset = :next_offset, last_run_at = NOW(), last_success = 1, last_message = :message, lock_until = NULL, updated_at = NOW() WHERE job_key = 'items'")
            ->execute([':next_offset' => $nextOffset, ':message' => $message]);
        site_setting_set_many(['last_item_sync_at' => date('Y-m-d H:i:s'), 'item_sync_offset' => (string)$nextOffset, 'duga_normalizer_version' => $normalizerVersion]);

        return ['synced_count' => (int)($result['synced_count'] ?? 0), 'message' => $message];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE sync_job_state SET last_run_at = NOW(), last_success = 0, last_message = :message, lock_until = NULL, updated_at = NOW() WHERE job_key = 'items'")
            ->execute([':message' => mb_substr($e->getMessage(), 0, 1000)]);
        throw $e;
    }
}


function scheduler_skip_missing_credentials(PDO $pdo, string $jobKey): ?array
{
    $cred = api_credential_get('items');
    if (trim((string)($cred['api_id'] ?? '')) !== '' && trim((string)($cred['affiliate_id'] ?? '')) !== '') {
        return null;
    }

    $message = 'アプリケーションID / 代理店ID 未設定のためスキップ';
    $pdo->prepare('UPDATE sync_job_state SET last_run_at = NOW(), last_success = 0, last_message = :message, lock_until = NULL, updated_at = NOW() WHERE job_key = :job_key')
        ->execute([':message' => $message, ':job_key' => $jobKey]);

    return ['synced_count' => 0, 'message' => $message];
}

function scheduler_split_lines(string $value, int $max = 5): array
{
    $lines = preg_split('/\R/u', $value) ?: [];
    $result = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $result[] = $line;
        if (count($result) >= $max) {
            break;
        }
    }
    return $result;
}

function scheduler_build_compound_keyword(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parts = array_map('trim', explode(',', $value, 2));
    if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
        return $parts[1] . 'は' . $parts[0] . 'が大好き';
    }

    return $value;
}

function scheduler_apply_auto_settings(PDO $pdo): void
{
    $enabled = settings_bool('item_sync_enabled', false) ? 1 : 0;
    $interval = max(1, settings_int('item_sync_interval_minutes', 60));
    $pdo->prepare("UPDATE api_schedules SET interval_minutes = :interval, is_enabled = :enabled, updated_at = NOW() WHERE schedule_type = 'items'")
        ->execute([':interval' => $interval, ':enabled' => $enabled]);
    $pdo->prepare("UPDATE api_schedules SET is_enabled = 0, updated_at = NOW() WHERE schedule_type <> 'items'")
        ->execute();
}

function scheduler_is_due(array $schedule): bool
{
    $interval = max(1, (int)($schedule['interval_minutes'] ?? 60));
    $lastRun = isset($schedule['last_run_at']) ? strtotime((string)$schedule['last_run_at']) : false;
    if ($lastRun === false || $lastRun <= 0) return true;
    return $lastRun <= (time() - ($interval * 60));
}

function scheduler_ensure_schedule_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_schedules (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,schedule_type VARCHAR(32) NOT NULL UNIQUE,interval_minutes INT NOT NULL DEFAULT 60,is_enabled TINYINT(1) NOT NULL DEFAULT 1,last_run_at DATETIME NULL,lock_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    scheduler_ensure_columns($pdo, 'api_schedules', [
        'lock_until' => 'ALTER TABLE api_schedules ADD COLUMN lock_until DATETIME NULL AFTER last_run_at',
    ]);
}

function scheduler_ensure_job_state_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_job_state (job_key VARCHAR(64) PRIMARY KEY,next_offset INT NOT NULL DEFAULT 1,next_initial VARCHAR(10) NULL,last_run_at DATETIME NULL,last_success TINYINT(1) NOT NULL DEFAULT 0,last_message TEXT NULL,lock_until DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    scheduler_ensure_columns($pdo, 'sync_job_state', [
        'lock_until' => 'ALTER TABLE sync_job_state ADD COLUMN lock_until DATETIME NULL AFTER last_message',
    ]);
}

function scheduler_ensure_columns(PDO $pdo, string $table, array $alterSqlByColumn): void
{
    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table);
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $column) {
        $columns[(string)($column['Field'] ?? '')] = true;
    }
    foreach ($alterSqlByColumn as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec((string)$sql);
        }
    }
}

function scheduler_job_keys(): array
{
    return ['items'];
}

function scheduler_seed_default_schedules(PDO $pdo): void
{
    foreach (scheduler_job_keys() as $type) {
        $pdo->prepare('INSERT INTO api_schedules(schedule_type, interval_minutes, is_enabled, created_at, updated_at) VALUES(?, 60, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at')->execute([$type]);
    }
    scheduler_seed_job_state($pdo);
}

function scheduler_seed_job_state(PDO $pdo): void
{
    scheduler_ensure_job_state_table($pdo);
    foreach (scheduler_job_keys() as $jobKey) {
        $pdo->prepare('INSERT INTO sync_job_state (job_key, next_offset, updated_at) VALUES (:job_key, 1, NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at')
            ->execute([':job_key' => $jobKey]);
    }
}

function maybe_run_scheduled_jobs(): void
{
    $result = scheduler_tick();
    if (($result['status'] ?? '') === 'error') {
        throw new RuntimeException((string)($result['message'] ?? 'scheduler error'));
    }
}
