<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/app_features.php';
require_once __DIR__ . '/../../lib/rss_display_balance.php';
require_once __DIR__ . '/../../lib/rss_access_trade.php';
require_once __DIR__ . '/../../lib/rss_access_trade_host.php';
require_once __DIR__ . '/../../lib/rss_access_trade_candidate.php';
require_once __DIR__ . '/../../lib/db.php';

$items = [];
try {
    rss_widget_bootstrap(false);
    $candidates = rss_trade_candidate_pool(20, true, 14);
    $items = rss_trade_select_host_aware($candidates, 5, 2, 30);
} catch (Throwable $e) {
    error_log('[rss] image access-trade selection skipped: ' . $e->getMessage());
    $items = [];
}
?>
<div class="rss-widget rss-widget--image" data-rss-fragment="image">
    <?php if ($items !== []) : ?>
    <ul class="rss-image-list">
        <?php foreach ($items as $item) : ?>
            <li class="rss-image-list__item">
                <?php if (trim((string)($item['image_url'] ?? '')) !== '') : ?>
                    <?php $rssImageUrl = public_url('rss-image.php') . '?' . http_build_query(['source' => (int)($item['source_id'] ?? 0), 'url' => (string)($item['link'] ?? '')], '', '&', PHP_QUERY_RFC3986); ?>
                    <img src="<?php echo e($rssImageUrl); ?>" alt="" loading="lazy" decoding="async" onerror="this.closest('li').remove();">
                <?php endif; ?>
                <a href="<?php echo e(rss_trade_out_url($item)); ?>" target="_blank" rel="noopener"><?php echo e((string)($item['title'] ?? '')); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else : ?>
        <p class="sidebar-empty">画像RSSの記事がありません。</p>
    <?php endif; ?>
</div>
<?php rss_fragment_loader_script(); ?>
