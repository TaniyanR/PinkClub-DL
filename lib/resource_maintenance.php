<?php
declare(strict_types=1);

function pcf_resource_cleanup(?PDO $pdo = null, int $batchSize = 500): array
{
    $pdo ??= db();
    $batchSize = max(50, min(2000, $batchSize));
    $deleted = ['api_logs' => 0, 'sync_logs' => 0, 'cache_files' => 0];

    foreach ([
        ['table' => 'api_logs', 'days' => 7],
        ['table' => 'sync_logs', 'days' => 90],
    ] as $target) {
        try {
            $sql = sprintf(
                'DELETE FROM `%s` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY id ASC LIMIT %d',
                $target['table'],
                $target['days'],
                $batchSize
            );
            $deleted[$target['table']] = $pdo->exec($sql) ?: 0;
        } catch (Throwable $e) {
            error_log('[resource_cleanup] ' . $target['table'] . ': ' . $e->getMessage());
        }
    }

    $cacheDirectory = dirname(__DIR__) . '/storage/cache/public-pages';
    $cutoff = time() - 86400;
    // Clear a large backlog without creating one long CPU spike. The normal
    // DB batch stays small; cache removal gets a two-second time budget and a
    // hard ceiling so hundreds of thousands of old files can drain over time.
    $cacheDeleteLimit = max(10000, $batchSize);
    $deadline = microtime(true) + 2.0;
    if (is_dir($cacheDirectory)) {
        try {
            $files = new FilesystemIterator($cacheDirectory, FilesystemIterator::SKIP_DOTS);
            foreach ($files as $file) {
                if ($deleted['cache_files'] >= $cacheDeleteLimit || microtime(true) >= $deadline) {
                    break;
                }
                if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'html') {
                    continue;
                }
                if ($file->getMTime() < $cutoff && @unlink($file->getPathname())) {
                    $deleted['cache_files']++;
                }
            }
        } catch (Throwable $e) {
            error_log('[resource_cleanup] public page cache: ' . $e->getMessage());
        }
    }

    return $deleted;
}
