<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/app_features.php';
require_once __DIR__ . '/../lib/home_rotation_cache.php';
require_once __DIR__ . '/../lib/resource_maintenance.php';

/** @return resource|null */
function auto_import_lock()
{
    $lockDirectory = dirname(__DIR__) . '/storage/locks';
    if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
        throw new RuntimeException('cronロック用ディレクトリを作成できません');
    }
    $handle = @fopen($lockDirectory . '/auto-import.lock', 'c');
    if (!is_resource($handle)) {
        throw new RuntimeException('cronロックファイルを開けません');
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function main(): int
{
    $lockHandle = auto_import_lock();
    if (!is_resource($lockHandle)) {
        echo '[' . date('Y-m-d H:i:s') . "] auto_import skipped: another process is running\n";
        return 0;
    }

    try {
        maybe_run_scheduled_jobs();
        require_once __DIR__ . '/../lib/indexnow.php';
        pcf_indexnow_dispatch();
        require_once __DIR__ . '/../lib/public_rankings.php';
        pcf_public_ranking_warm_due();
        rss_widget_bootstrap();
        rss_refresh_stale_sources(2, 1800, 2);
        pcf_home_rotation_refresh();
        pcf_resource_cleanup(db(), 500);
        echo '[' . date('Y-m-d H:i:s') . "] maybe_run_scheduled_jobs() executed\n";
        return 0;
    } catch (Throwable $e) {
        error_log('[auto_import] ' . $e->getMessage());
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main());
}
