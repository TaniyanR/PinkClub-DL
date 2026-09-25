<?php

if (!function_exists('get_ad_code')) {
    function get_ad_code(string $position_key): ?string
    {
        if (!function_exists('db')) {
            return null;
        }
        try {
            $stmt = db()->prepare('SELECT snippet_html FROM code_snippets WHERE slot_key = :slot AND is_enabled = 1 LIMIT 1');
            $stmt->execute([':slot' => $position_key]);
            $html = $stmt->fetchColumn();
            $code = is_string($html) ? trim($html) : '';
            return $code !== '' ? $code : null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('render_ad')) {
    function render_ad(string $position_key, string $page_type = 'home', string $device = 'pc'): void
    {
        $html = get_ad_code($position_key);
        if ($html !== null) {
            echo $html;
        }
    }
}

if (!function_exists('should_show_ad')) {
    function should_show_ad(string $position_key, string $page_type = 'home', string $device = 'pc'): bool
    {
        return get_ad_code($position_key) !== null;
    }
}

if (!function_exists('rss_fragment_loader_script')) {
    function rss_fragment_loader_script(): void
    {
        static $rendered = false;
        if ($rendered || !empty($GLOBALS['pcf_rss_fragment_request'])) {
            return;
        }
        $rendered = true;
        $endpoint = function_exists('public_url') ? public_url('rss_trade_fragment.php') : '/rss_trade_fragment.php';
        ?>
<script>
(function () {
  var endpoint = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var refresh = function () {
    var groups = {};
    document.querySelectorAll('[data-rss-fragment]').forEach(function (node) {
      var type = node.getAttribute('data-rss-fragment');
      if (!type) return;
      if (!groups[type]) groups[type] = [];
      groups[type].push(node);
    });

    Object.keys(groups).forEach(function (type) {
      var nodes = groups[type];
      if (!nodes.length) return;
      nodes.forEach(function (node) { node.dataset.rssLoading = '1'; });

      fetch(endpoint + '?type=' + encodeURIComponent(type), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.text();
        })
        .then(function (html) {
          if (!html) return;
          var holder = document.createElement('div');
          holder.innerHTML = html.trim();
          var replacement = holder.firstElementChild;
          if (!replacement) return;
          nodes.forEach(function (node) {
            node.replaceWith(replacement.cloneNode(true));
          });
        })
        .catch(function () {})
        .finally(function () {
          nodes.forEach(function (node) { node.dataset.rssLoading = '0'; });
        });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
  } else {
    refresh();
  }
}());
</script>
        <?php
    }
}

if (!function_exists('render_shared_text_rss_widget')) {
    function render_shared_text_rss_widget(): void
    {
        $prevMaxItems = $GLOBALS['pcf_rss_widget_max_items'] ?? null;
        unset($GLOBALS['pcf_rss_widget_max_items']);
        include __DIR__ . '/rss_text_widget.php';
        if ($prevMaxItems === null) {
            unset($GLOBALS['pcf_rss_widget_max_items']);
        } else {
            $GLOBALS['pcf_rss_widget_max_items'] = $prevMaxItems;
        }
    }
}

if (!function_exists('render_shared_mobile_rss_widget')) {
    function render_shared_mobile_rss_widget(): void
    {
        $prevMaxItems = $GLOBALS['pcf_rss_widget_max_items'] ?? null;
        unset($GLOBALS['pcf_rss_widget_max_items']);
        include __DIR__ . '/rss_text_widget.php';
        if ($prevMaxItems === null) {
            unset($GLOBALS['pcf_rss_widget_max_items']);
        } else {
            $GLOBALS['pcf_rss_widget_max_items'] = $prevMaxItems;
        }
    }
}

if (!function_exists('render_shared_content_ad_row')) {
    function render_shared_content_ad_row(string $position_key, string $page_type): void
    {
        if ($position_key !== 'content_bottom') {
            return;
        }

        require_once __DIR__ . '/../../lib/app_features.php';
        require_once __DIR__ . '/../../lib/rss_display_balance.php';
        require_once __DIR__ . '/../../lib/rss_access_trade.php';
        require_once __DIR__ . '/../../lib/rss_access_trade_host.php';
        require_once __DIR__ . '/../../lib/rss_access_trade_candidate.php';

        $items = [];
        try {
            rss_widget_bootstrap(false);
            $candidates = rss_trade_candidate_pool(60, false, 14);
            $items = rss_trade_select_host_aware($candidates, 40, 40, 30);
        } catch (Throwable $e) {
            error_log('[rss] bottom access-trade widget skipped: ' . $e->getMessage());
            $items = [];
        }

        [$leftItems, $rightItems] = rss_trade_split_columns($items);

        $renderColumn = static function (array $columnItems): string {
            ob_start();
            echo '<div class="rss-widget rss-widget--text block"><div class="rss-box">';
            if ($columnItems === []) {
                echo '<p class="sidebar-empty">テキストRSSの記事がありません。</p>';
            } else {
                echo '<ul class="rss-list">';
                foreach ($columnItems as $item) {
                    $href = rss_trade_out_url($item);
                    echo '<li class="rss-list__item"><a href="' . e($href) . '" target="_blank" rel="noopener">' . e((string)($item['title'] ?? '')) . '</a></li>';
                }
                echo '</ul>';
            }
            echo '</div></div>';
            return (string)ob_get_clean();
        };

        echo '<div class="content-ad-row content-ad-row--rss-split" data-rss-fragment="bottom" style="margin-top:20px;">';
        echo '<div class="content-ad-row__rss">' . $renderColumn($leftItems) . '</div>';
        echo '<div class="content-ad-row__rss">' . $renderColumn($rightItems) . '</div>';
        echo '</div>';
        rss_fragment_loader_script();
    }
}
