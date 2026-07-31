<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$safeTextSetting = static function (string $key, string $default = ''): string {
    if (function_exists('front_safe_text_setting')) {
        return front_safe_text_setting($key, $default);
    }

    try {
        if (function_exists('setting')) {
            $value = setting($key, $default);
            return is_string($value) ? $value : $default;
        }
        if (function_exists('app_setting_get')) {
            $value = app_setting_get($key, $default);
            return is_string($value) ? $value : $default;
        }
    } catch (Throwable $e) {
        if (function_exists('app_log_error')) {
            app_log_error('footer safe text setting fallback failed: ' . $key, $e);
        }
    }

    return $default;
};

$siteName = trim($safeTextSetting('site_name', ''));
if ($siteName === '') {
    $siteName = trim($safeTextSetting('site.title', ''));
}
if ($siteName === '') {
    $siteName = 'PinkClub-FD';
}

$copyrightStartYear = (int)date('Y');
try {
    $pdo = db();
    $startDate = null;
    foreach (['date_published', 'release_date', 'created_at'] as $column) {
        $stmt = $pdo->query("SELECT MIN(" . $column . ") FROM items WHERE " . $column . " IS NOT NULL AND " . $column . " <> ''");
        $value = $stmt ? trim((string)$stmt->fetchColumn()) : '';
        if ($value !== '') {
            $startDate = $value;
            break;
        }
    }
    if ($startDate !== null) {
        $timestamp = strtotime($startDate);
        if ($timestamp !== false) {
            $copyrightStartYear = (int)date('Y', $timestamp);
        }
    }
} catch (Throwable $e) {
}
$currentYear = (int)date('Y');
$copyrightYears = $copyrightStartYear >= $currentYear
    ? (string)$currentYear
    : $copyrightStartYear . '-' . $currentYear;

?>
  <?php $pageType = function_exists('ad_current_page_type') ? ad_current_page_type() : 'home'; ?>
  </div>
  <?php if (site_setting_get('link.rss_display.pc_text_bottom', '1') === '1'): ?>
  <div class="site-main__rss only-pc">
    <?php render_shared_content_ad_row('content_bottom', $pageType); ?>
  </div>
  <?php endif; ?>
  </main>
</div>
<button type="button" class="page-top-button" aria-label="トップに戻る">↑ トップへ</button>
<?php if (function_exists('render_ad') && (!function_exists('should_show_ad') || should_show_ad('sp_footer_above', $pageType, 'sp'))): ?>
<div class="only-sp site-ad site-ad--sp-footer-above"><?php render_ad('sp_footer_above', $pageType, 'sp'); ?></div>
<?php endif; ?>
<?php if (site_setting_get('link.rss_display.sp_footer_above', '1') === '1'): ?>
<div class="site-main__rss only-sp">
  <?php render_shared_mobile_rss_widget(); ?>
</div>
<?php endif; ?>
<footer class="site-footer">
  <div class="site-footer__credit">
    <a href="https://click.duga.jp/aff/api/43846-01" target="_blank" rel="noopener noreferrer"><img src="https://ad.duga.jp/img/webservice_142.gif" alt="DUGAウェブサービス" width="142" height="18" border="0"></a>
  </div>
  <div class="site-footer__copy">© <?= e($copyrightYears) ?> <a href="<?= e(public_url('')) ?>"><?= e($siteName) ?></a></div>
</footer>
<script>
(function () {
  var header = document.querySelector('.site-header');
  var button = document.querySelector('.page-top-button');
  if (!header || !button) return;
  var updateButton = function () { button.classList.toggle('is-visible', header.getBoundingClientRect().bottom <= 0); };
  button.addEventListener('click', function () {
    if ('scrollBehavior' in document.documentElement.style) window.scrollTo({ top: 0, behavior: 'smooth' });
    else window.scrollTo(0, 0);
  });
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) { button.classList.toggle('is-visible', !entries[0].isIntersecting); });
    observer.observe(header);
  } else {
    window.addEventListener('scroll', updateButton);
    window.addEventListener('resize', updateButton);
  }
  updateButton();
}());
</script>
<script>
(function () {
  if (!navigator.sendBeacon) return;
  if (window.__pcfAnalyticsSent === true) return;
  window.__pcfAnalyticsSent = true;
  var data = new FormData();
  data.append('path', window.location.pathname + window.location.search);
  data.append('referrer', document.referrer || '');
  try {
    var params = new URLSearchParams(window.location.search);
    data.append('ref', params.get('ref') || '');
  } catch (e) {
    data.append('ref', '');
  }
  navigator.sendBeacon('<?= e(public_url('analytics.php')) ?>', data);
}());
</script>
<?php $rankingRefreshQueue = function_exists('pcf_public_ranking_refresh_queue') ? pcf_public_ranking_refresh_queue() : []; ?>
<?php if ($rankingRefreshQueue !== []): ?>
<script>
(function () {
  if (!navigator.sendBeacon) return;
  var endpoint = <?= json_encode(public_url('ranking_refresh.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var queue = <?= json_encode($rankingRefreshQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  queue.forEach(function (entry) {
    var data = new FormData();
    data.append('type', entry.type || '');
    data.append('period', entry.period || '');
    navigator.sendBeacon(endpoint, data);
  });
}());
</script>
<?php endif; ?>
</body>
</html>
