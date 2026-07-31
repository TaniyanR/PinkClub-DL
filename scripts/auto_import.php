<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/app_features.php';
require_once __DIR__ . '/../lib/access_analytics.php';

function main(): int
{
    try {
        maybe_run_scheduled_jobs();
        rss_widget_bootstrap();
        rss_refresh_stale_sources(1000, 1800, 2);
        analytics_maybe_cleanup_old_logs(730, 2000, true);
        echo '[' . date('Y-m-d H:i:s') . "] maybe_run_scheduled_jobs() executed\n";
        return 0;
    } catch (Throwable $e) {
        error_log('[auto_import] ' . $e->getMessage());
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main());
}
