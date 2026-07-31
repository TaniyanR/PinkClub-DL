<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/app_features.php';
require_once __DIR__ . '/../../lib/db.php';

rss_widget_bootstrap(false);

$items = [];
try {
    $items = array_merge(rss_widget_direct_items(250, false), rss_pick_display_items(250, false, 14));
} catch (Throwable $e) {
    $items = [];
}

if (is_array($items) && count($items) > 1) {
    shuffle($items);
}

$rssUsedKeys = [];
if (isset($GLOBALS['pcf_rss_widget_used_keys']) && is_array($GLOBALS['pcf_rss_widget_used_keys'])) {
    $rssUsedKeys = $GLOBALS['pcf_rss_widget_used_keys'];
}
$maxItems = 50;
if (isset($GLOBALS['pcf_rss_widget_max_items'])) {
    $maxItems = min(50, max(0, (int)$GLOBALS['pcf_rss_widget_max_items']));
}

$filteredItems = [];
$deferredItems = [];
$sourceCounts = [];
$maxItemsSourceLimit = 5;
foreach ($items as $item) {
    if (!is_array($item)) {
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
        if ($maxItems > 0) {
            $deferredItems[] = $item;
        }
        continue;
    }
    if ($key !== '') {
        $rssUsedKeys[$key] = true;
    }
    if ($sourceKey !== '') {
        $sourceCounts[$sourceKey] = ($sourceCounts[$sourceKey] ?? 0) + 1;
    }
    $filteredItems[] = $item;
    if ($maxItems > 0 && count($filteredItems) >= $maxItems) {
        break;
    }
}

if ($maxItems > 0 && count($filteredItems) < $maxItems && $deferredItems !== []) {
    foreach ($deferredItems as $item) {
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
$GLOBALS['pcf_rss_widget_used_keys'] = $rssUsedKeys;
?>
<div class="rss-widget rss-widget--text block">
    <div class="rss-box">
        <?php if ($items !== []) : ?>
            <ul class="rss-list">
                <?php foreach ($items as $item) : ?>
                    <li class="rss-list__item">
                        <a href="<?php echo e((string)($item['link'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo e((string)($item['title'] ?? '')); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p class="sidebar-empty">テキストRSSの記事がありません。</p>
        <?php endif; ?>
    </div>
</div>
