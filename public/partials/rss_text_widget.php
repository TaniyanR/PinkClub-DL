<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/app_features.php';
require_once __DIR__ . '/../../lib/rss_display_balance.php';
require_once __DIR__ . '/../../lib/rss_access_trade.php';
require_once __DIR__ . '/../../lib/rss_access_trade_host.php';
require_once __DIR__ . '/../../lib/rss_access_trade_candidate.php';
require_once __DIR__ . '/../../lib/db.php';

rss_widget_bootstrap(false);

$items = [];
try {
    $candidates = rss_trade_candidate_pool(40, false, 14);
    $maxItems = 20;
    if (isset($GLOBALS['pcf_rss_widget_max_items'])) {
        $maxItems = min(40, max(0, (int)$GLOBALS['pcf_rss_widget_max_items']));
    }
    $items = rss_trade_select_host_aware($candidates, $maxItems, max(1, $maxItems), 30);
} catch (Throwable) {
    error_log('[rss] text access-trade selection skipped');
    $items = [];
}
?>
<div class="rss-widget rss-widget--text block" data-rss-fragment="text">
    <div class="rss-box">
        <?php if ($items !== []) : ?>
            <ul class="rss-list">
                <?php foreach ($items as $item) : ?>
                    <li class="rss-list__item">
                        <a href="<?php echo e(rss_trade_out_url($item)); ?>" target="_blank" rel="noopener noreferrer"><?php echo e((string)($item['title'] ?? '')); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p class="sidebar-empty">テキストRSSの記事がありません。</p>
        <?php endif; ?>
    </div>
</div>
<?php rss_fragment_loader_script(); ?>
