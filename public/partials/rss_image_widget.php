<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/app_features.php';
require_once __DIR__ . '/../../lib/db.php';

$items = [];
try {
    rss_widget_bootstrap(false);
    $items = array_merge(rss_widget_direct_items(20, true), rss_pick_display_items(20, true, 14));
    if (count($items) > 1) {
        shuffle($items);
    }
} catch (Throwable $e) {
    error_log('[rss] image widget skipped: ' . $e->getMessage());
    $items = [];
}

$rssUsedKeys = [];
if (isset($GLOBALS['pcf_rss_widget_used_keys']) && is_array($GLOBALS['pcf_rss_widget_used_keys'])) {
    $rssUsedKeys = $GLOBALS['pcf_rss_widget_used_keys'];
}
if ($items !== []) {
    $filteredItems = [];
    $deferredItems = [];
    $sourceCounts = [];
    $maxItems = 5;
    $maxItemsSourceLimit = 2;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (trim((string)($item['image_url'] ?? '')) === '') {
            continue;
        }
        $key = rss_normalize_display_key($item);
        if ($key === '') {
            $key = mb_strtolower(trim((string)($item['title'] ?? '')));
        }
        if ($key !== '' && isset($rssUsedKeys[$key])) {
            continue;
        }
        $sourceKey = mb_strtolower(trim((string)($item['source_name'] ?? '')));
        if ($maxItemsSourceLimit > 0 && $sourceKey !== '' && ($sourceCounts[$sourceKey] ?? 0) >= $maxItemsSourceLimit) {
            $deferredItems[] = $item;
            continue;
        }
        if ($key !== '') {
            $rssUsedKeys[$key] = true;
        }
        if ($sourceKey !== '') {
            $sourceCounts[$sourceKey] = ($sourceCounts[$sourceKey] ?? 0) + 1;
        }
        $filteredItems[] = $item;
        if (count($filteredItems) >= $maxItems) {
            break;
        }
    }
    if (count($filteredItems) < $maxItems && $deferredItems !== []) {
        foreach ($deferredItems as $item) {
            if (trim((string)($item['image_url'] ?? '')) === '') {
                continue;
            }
            $key = rss_normalize_display_key($item);
            if ($key === '') {
                $key = mb_strtolower(trim((string)($item['title'] ?? '')));
            }
            if ($key !== '' && isset($rssUsedKeys[$key])) {
                continue;
            }
            if ($key !== '') {
                $rssUsedKeys[$key] = true;
            }
            $filteredItems[] = $item;
            if (count($filteredItems) >= $maxItems) {
                break;
            }
        }
    }
    $items = $filteredItems;
}
$GLOBALS['pcf_rss_widget_used_keys'] = $rssUsedKeys;
?>
<div class="rss-widget rss-widget--image">
    <?php if ($items !== []) : ?>
    <ul class="rss-image-list">
        <?php foreach ($items as $item) : ?>
            <li class="rss-image-list__item">
                <?php if (trim((string)($item['image_url'] ?? '')) !== '') : ?>
                    <img src="<?php echo e((string)$item['image_url']); ?>" alt="" loading="lazy" onerror="this.closest('li').remove();">
                <?php endif; ?>
                <a href="<?php echo e((string)($item['link'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo e((string)($item['title'] ?? '')); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else : ?>
        <p class="sidebar-empty">画像RSSの記事がありません。</p>
    <?php endif; ?>
</div>
