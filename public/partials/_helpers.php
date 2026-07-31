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


if (!function_exists('rss_widget_direct_items')) {
    function rss_widget_direct_items(int $limit, bool $requireImage = false): array
    {
        static $cache = [];
        if ($limit <= 0 || !function_exists('db')) {
            return [];
        }

        $cacheKey = $limit . '|' . ($requireImage ? '1' : '0');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            $stmt = db()->query('SELECT ps.name AS source_name, pr.feed_url FROM partner_rss pr INNER JOIN partner_sites ps ON ps.id = pr.partner_site_id WHERE pr.feed_url <> "" AND COALESCE(pr.show_rss, pr.is_enabled, 1) = 1 ORDER BY RAND() LIMIT 50');
            $sources = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            try {
                $stmt = db()->query('SELECT ps.name AS source_name, pr.feed_url FROM partner_rss pr INNER JOIN partner_sites ps ON ps.id = pr.partner_site_id WHERE pr.feed_url <> "" AND pr.is_enabled = 1 ORDER BY RAND() LIMIT 50');
                $sources = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable) {
                $sources = [];
            }
        }

        if (!is_array($sources) || $sources === []) {
            return [];
        }

        shuffle($sources);
        $items = [];
        $seen = [];
        $perSourceLimit = $requireImage ? 5 : 5;
        $context = stream_context_create(['http' => ['timeout' => 2, 'user_agent' => 'PinkClubRSS/1.0']]);
        foreach ($sources as $source) {
            $feedUrl = trim((string)($source['feed_url'] ?? ''));
            if ($feedUrl === '') {
                continue;
            }

            $xmlRaw = @file_get_contents($feedUrl, false, $context);
            if (!is_string($xmlRaw) || $xmlRaw === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlRaw);
            if ($xml === false) {
                continue;
            }

            $feedItems = $xml->channel->item ?? $xml->item ?? $xml->entry ?? [];
            $sourceItems = [];
            foreach ($feedItems as $feedItem) {
                $link = trim((string)($feedItem->link ?? ''));
                if ($link === '') {
                    foreach ($feedItem->link ?? [] as $linkNode) {
                        $attrs = $linkNode->attributes();
                        $href = trim((string)($attrs['href'] ?? ''));
                        if ($href !== '') {
                            $link = $href;
                            break;
                        }
                    }
                }
                $title = trim((string)($feedItem->title ?? ''));
                if ($title === '' || $link === '') {
                    continue;
                }

                $imageUrl = function_exists('rss_extract_first_image_url') ? rss_extract_first_image_url($feedItem) : '';
                if ($requireImage && $imageUrl === '') {
                    continue;
                }

                $guid = trim((string)($feedItem->guid ?? $feedItem->id ?? $link));
                $key = function_exists('rss_normalize_url') ? rss_normalize_url($link) : mb_strtolower($link);
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                if ($key !== '') {
                    $seen[$key] = true;
                }

                $publishedAt = trim((string)($feedItem->pubDate ?? $feedItem->published ?? $feedItem->updated ?? ''));
                $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
                $sourceItems[] = [
                    'title' => $title,
                    'link' => $link,
                    'guid' => $guid,
                    'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '',
                    'image_url' => $imageUrl,
                    'source_id' => 0,
                    'source_name' => (string)($source['source_name'] ?? ''),
                ];

                if (count($sourceItems) >= $perSourceLimit) {
                    break;
                }
            }

            if ($sourceItems !== []) {
                shuffle($sourceItems);
                $items = array_merge($items, $sourceItems);
            }
        }

        if ($items === []) {
            $cache[$cacheKey] = [];
            return [];
        }

        shuffle($items);
        $cache[$cacheKey] = array_slice($items, 0, $limit);
        return $cache[$cacheKey];
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
