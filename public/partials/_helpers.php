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
        if ($html === null) {
            return;
        }
        echo $html;
    }
}

if (!function_exists('should_show_ad')) {
    function should_show_ad(string $position_key, string $page_type = 'home', string $device = 'pc'): bool
    {
        return get_ad_code($position_key) !== null;
    }
}


if (!function_exists('render_shared_text_rss_widget')) {
    function render_shared_text_rss_widget(): void
    {
        $prevUsedKeys = $GLOBALS['pcf_rss_widget_used_keys'] ?? null;
        $prevMaxItems = $GLOBALS['pcf_rss_widget_max_items'] ?? null;

        $GLOBALS['pcf_rss_widget_used_keys'] = [];
        unset($GLOBALS['pcf_rss_widget_max_items']);

        include __DIR__ . '/rss_text_widget.php';

        if ($prevUsedKeys === null) {
            unset($GLOBALS['pcf_rss_widget_used_keys']);
        } else {
            $GLOBALS['pcf_rss_widget_used_keys'] = $prevUsedKeys;
        }

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
        $prevUsedKeys = $GLOBALS['pcf_rss_widget_used_keys'] ?? null;
        $prevMaxItems = $GLOBALS['pcf_rss_widget_max_items'] ?? null;

        $GLOBALS['pcf_rss_widget_used_keys'] = [];
        unset($GLOBALS['pcf_rss_widget_max_items']);

        include __DIR__ . '/rss_text_widget.php';

        if ($prevUsedKeys === null) {
            unset($GLOBALS['pcf_rss_widget_used_keys']);
        } else {
            $GLOBALS['pcf_rss_widget_used_keys'] = $prevUsedKeys;
        }

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
        // Keep this helper limited to bottom placement to avoid top-of-content duplication.
        if ($position_key !== 'content_bottom') {
            return;
        }

        $prevUsedKeys = $GLOBALS['pcf_rss_widget_used_keys'] ?? null;
        $prevMaxItems = $GLOBALS['pcf_rss_widget_max_items'] ?? null;

        // Reset widget tracking so this row can render independently from sidebar/top widgets.
        $GLOBALS['pcf_rss_widget_used_keys'] = [];
        $GLOBALS['pcf_rss_widget_max_items'] = 50;

        ob_start();
        include __DIR__ . '/rss_text_widget.php';
        $leftRssHtml = trim((string)ob_get_clean());

        // Render right column independently so both columns can fill to max count.
        $GLOBALS['pcf_rss_widget_used_keys'] = [];

        ob_start();
        include __DIR__ . '/rss_text_widget.php';
        $rightRssHtml = trim((string)ob_get_clean());

        if ($prevUsedKeys === null) {
            unset($GLOBALS['pcf_rss_widget_used_keys']);
        } else {
            $GLOBALS['pcf_rss_widget_used_keys'] = $prevUsedKeys;
        }

        if ($prevMaxItems === null) {
            unset($GLOBALS['pcf_rss_widget_max_items']);
        } else {
            $GLOBALS['pcf_rss_widget_max_items'] = $prevMaxItems;
        }

        $emptyWidget = '<div class="rss-widget rss-widget--text block"><div class="rss-box"><p class="sidebar-empty">テキストRSSの記事がありません。</p></div></div>';
        if ($leftRssHtml === '') {
            $leftRssHtml = $emptyWidget;
        }
        if ($rightRssHtml === '') {
            $rightRssHtml = $emptyWidget;
        }

        echo '<div class="content-ad-row content-ad-row--rss-split" style="margin-top:20px;">';
        echo '<div class="content-ad-row__rss">' . $leftRssHtml . '</div>';
        echo '<div class="content-ad-row__rss">' . $rightRssHtml . '</div>';
        echo '</div>';
    }
}
