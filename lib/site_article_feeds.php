<?php
declare(strict_types=1);

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/site_settings.php';

function site_article_feed_configs(): array
{
    return [
        'general_10' => ['type' => 'general', 'interval' => 10, 'limit' => 20, 'title' => '総合RSS（10分）', 'description' => '総合RSS（10分間隔）'],
        'general_60' => ['type' => 'general', 'interval' => 60, 'limit' => 10, 'title' => '総合RSS（1時間）', 'description' => '総合RSS（1時間間隔）'],
        'free_10' => ['type' => 'free', 'interval' => 10, 'limit' => 20, 'days' => 7, 'title' => 'ランダムRSS（10分）', 'description' => 'ランダムRSS（10分間隔）'],
        'free_60' => ['type' => 'free', 'interval' => 60, 'limit' => 10, 'days' => 30, 'title' => 'ランダムRSS（1時間）', 'description' => 'ランダムRSS（1時間間隔）'],
    ];
}

function site_article_feed_ensure_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS site_article_feed_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        feed_key VARCHAR(32) NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        content_id VARCHAR(128) NULL,
        published_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_site_article_feed_item (feed_key, item_id),
        INDEX idx_site_article_feed_published (feed_key, published_at),
        INDEX idx_site_article_feed_item_date (feed_key, item_id, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function site_article_feed_words(string $value): array
{
    $parts = preg_split('/[\r\n,、]+/u', $value) ?: [];
    $words = [];
    foreach ($parts as $part) {
        $word = trim((string)$part);
        if ($word !== '') {
            $words[] = $word;
        }
    }
    return array_values(array_unique($words));
}

function site_article_feed_keyword_words(): array
{
    return site_article_feed_words(site_setting_get('site.keywords', '') . "\n" . site_setting_get('item_sync_compound_keywords', ''));
}

function site_article_feed_ng_words(): array
{
    return site_article_feed_words(site_setting_get('item_sync_exclude_keywords', ''));
}

function site_article_feed_text(array $item): string
{
    return implode(' ', [
        (string)($item['title'] ?? ''),
        (string)($item['category_name'] ?? ''),
        (string)($item['actress_names'] ?? ''),
        (string)($item['genre_names'] ?? ''),
        (string)($item['maker_names'] ?? ''),
        (string)($item['tag_names'] ?? ''),
        (string)($item['raw_json'] ?? ''),
    ]);
}

function site_article_feed_contains_word(string $text, array $words): bool
{
    foreach ($words as $word) {
        if ($word !== '' && mb_stripos($text, $word, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

function site_article_feed_image(array $item): string
{
    foreach (['image_large', 'image_small', 'image_list'] as $key) {
        $value = trim((string)($item[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($key === 'image_list') {
            $parts = preg_split('/[\r\n,|\s]+/', $value) ?: [];
            foreach ($parts as $part) {
                $candidate = trim((string)$part);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            continue;
        }
        return $value;
    }
    return '';
}

function site_article_feed_has_movie(array $item): bool
{
    foreach (['sample_movie_url_720', 'sample_movie_url_644', 'sample_movie_url_560', 'sample_movie_url_476'] as $key) {
        if (trim((string)($item[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function site_article_feed_item_url(array $item): string
{
    $contentId = trim((string)($item['content_id'] ?? ''));
    if ($contentId !== '') {
        return public_url('item.php?cid=' . rawurlencode($contentId));
    }
    return public_url('item.php?id=' . (int)($item['id'] ?? 0));
}

function site_article_feed_table_exists(string $table): bool
{
    static $cache = [];

    if (!in_array($table, ['item_actresses', 'item_genres', 'item_makers', 'item_tags', 'tags'], true)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function site_article_feed_candidate_rows(string $feedKey, string $type, int $days = 0): array
{
    $where = items_product_source_where('i') . ' AND TRIM(COALESCE(i.title, "")) <> "" AND (TRIM(COALESCE(i.image_large, "")) <> "" OR TRIM(COALESCE(i.image_small, "")) <> "" OR TRIM(COALESCE(i.image_list, "")) <> "") AND (TRIM(COALESCE(i.sample_movie_url_476, "")) <> "" OR TRIM(COALESCE(i.sample_movie_url_560, "")) <> "" OR TRIM(COALESCE(i.sample_movie_url_644, "")) <> "" OR TRIM(COALESCE(i.sample_movie_url_720, "")) <> "")';
    $params = [':feed_key' => $feedKey];
    if ($type === 'general') {
        $where .= ' AND NOT EXISTS (SELECT 1 FROM site_article_feed_items f WHERE f.feed_key = :feed_key AND f.item_id = i.id)';
        $order = 'i.release_date DESC, i.updated_at DESC, i.id DESC';
    } else {
        if ($days > 0) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM site_article_feed_items f WHERE f.feed_key = :feed_key AND f.item_id = i.id AND f.published_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY))';
        }
        $order = 'RAND()';
    }

    $actressSelect = site_article_feed_table_exists('item_actresses') ? '(SELECT GROUP_CONCAT(DISTINCT ia.actress_name SEPARATOR " ") FROM item_actresses ia WHERE ia.item_id = i.id)' : "''";
    $genreSelect = site_article_feed_table_exists('item_genres') ? '(SELECT GROUP_CONCAT(DISTINCT ig.genre_name SEPARATOR " ") FROM item_genres ig WHERE ig.item_id = i.id)' : "''";
    $makerSelect = site_article_feed_table_exists('item_makers') ? '(SELECT GROUP_CONCAT(DISTINCT im.maker_name SEPARATOR " ") FROM item_makers im WHERE im.item_id = i.id)' : "''";
    $tagSelect = site_article_feed_table_exists('item_tags') && site_article_feed_table_exists('tags') ? '(SELECT GROUP_CONCAT(DISTINCT t.name SEPARATOR " ") FROM item_tags it INNER JOIN tags t ON t.id = it.tag_id WHERE it.item_id = i.id)' : "''";

    $sql = 'SELECT i.*, ' . $actressSelect . ' AS actress_names, ' . $genreSelect . ' AS genre_names, ' . $makerSelect . ' AS maker_names, ' . $tagSelect . ' AS tag_names FROM items i WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function site_article_feed_select_items(string $feedKey, array $config, int $limit, bool $excludeDelivered = false): array
{
    $limit = max(1, min(50, $limit));
    $ngWords = site_article_feed_ng_words();
    $keywordWords = site_article_feed_keyword_words();
    $dayCandidates = ($config['type'] ?? '') === 'free' ? [(int)($config['days'] ?? 0), max(1, (int)floor(((int)($config['days'] ?? 0)) / 2)), 1, 0] : [0];
    $selected = [];
    $seen = [];

    if ($excludeDelivered) {
        $delivered = db()->prepare('SELECT item_id FROM site_article_feed_items WHERE feed_key = :feed_key');
        $delivered->execute([':feed_key' => $feedKey]);
        foreach ($delivered->fetchAll(PDO::FETCH_COLUMN) ?: [] as $itemId) {
            $seen[(int)$itemId] = true;
        }
    }

    foreach ($dayCandidates as $days) {
        $rows = site_article_feed_candidate_rows($feedKey, (string)$config['type'], $days);
        $valid = [];
        $matched = [];
        foreach ($rows as $row) {
            $itemId = (int)($row['id'] ?? 0);
            if ($itemId <= 0 || isset($seen[$itemId])) {
                continue;
            }
            $text = site_article_feed_text($row);
            if (site_article_feed_image($row) === '' || !site_article_feed_has_movie($row) || site_article_feed_contains_word($text, $ngWords)) {
                continue;
            }
            $valid[] = $row;
            if (($config['type'] ?? '') === 'general' && $keywordWords !== [] && site_article_feed_contains_word($text, $keywordWords)) {
                $matched[] = $row;
            }
        }

        $ordered = ($config['type'] ?? '') === 'general' && $matched !== [] ? array_merge($matched, $valid) : $valid;
        foreach ($ordered as $row) {
            $itemId = (int)($row['id'] ?? 0);
            if ($itemId <= 0 || isset($seen[$itemId])) {
                continue;
            }
            $seen[$itemId] = true;
            $selected[] = $row;
            if (count($selected) >= $limit) {
                return $selected;
            }
        }
    }

    return $selected;
}

function site_article_feed_select_item(string $feedKey, array $config): ?array
{
    $items = site_article_feed_select_items($feedKey, $config, 1);
    return $items[0] ?? null;
}

function site_article_feed_count_items(string $feedKey): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM site_article_feed_items WHERE feed_key = :feed_key');
    $stmt->execute([':feed_key' => $feedKey]);
    return (int)$stmt->fetchColumn();
}

function site_article_feed_insert_item(string $feedKey, array $config, array $item, int $offsetSeconds = 0): void
{
    try {
        if (($config['type'] ?? '') === 'general') {
            $exists = db()->prepare('SELECT 1 FROM site_article_feed_items WHERE feed_key = :feed_key AND item_id = :item_id LIMIT 1');
            $exists->execute([':feed_key' => $feedKey, ':item_id' => (int)$item['id']]);
            if ($exists->fetchColumn()) {
                return;
            }
        }

        $insert = db()->prepare('INSERT INTO site_article_feed_items(feed_key, item_id, content_id, published_at, created_at, updated_at) VALUES(:feed_key, :item_id, :content_id, DATE_SUB(NOW(), INTERVAL ' . max(0, $offsetSeconds) . ' SECOND), NOW(), NOW())');
        $insert->execute([':feed_key' => $feedKey, ':item_id' => (int)$item['id'], ':content_id' => trim((string)($item['content_id'] ?? ''))]);
    } catch (Throwable $e) {
        error_log('[site_article_feed] insert failed: ' . $e->getMessage());
    }
}

function site_article_feed_rebalance_existing_published_at_once(array $configs): void
{
    $flagKey = 'site_article_feed_published_at_rebalanced_v1';
    if (site_setting_get($flagKey, '') === '1') {
        return;
    }

    $pdo = db();
    try {
        $baseStmt = $pdo->query('SELECT NOW()');
        $baseValue = $baseStmt ? $baseStmt->fetchColumn() : false;
        $basePublishedAt = is_string($baseValue) ? $baseValue : '';
    } catch (Throwable) {
        $basePublishedAt = '';
    }
    if ($basePublishedAt === '') {
        $basePublishedAt = date('Y-m-d H:i:s');
    }

    try {
        $pdo->beginTransaction();
        foreach ($configs as $feedKey => $config) {
            $limit = max(1, (int)($config['limit'] ?? 1));
            $intervalSeconds = max(0, (int)($config['interval'] ?? 0) * 60);

            $select = $pdo->prepare('SELECT id FROM site_article_feed_items WHERE feed_key = :feed_key ORDER BY published_at DESC, id DESC LIMIT :limit');
            $select->bindValue(':feed_key', (string)$feedKey, PDO::PARAM_STR);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);

            foreach ($ids as $index => $id) {
                if ($id <= 0) {
                    continue;
                }
                $offsetSeconds = (int)$index * $intervalSeconds;
                $update = $pdo->prepare('UPDATE site_article_feed_items SET published_at = DATE_SUB(:base_published_at, INTERVAL ' . $offsetSeconds . ' SECOND) WHERE id = :id AND feed_key = :feed_key');
                $update->execute([
                    ':base_published_at' => $basePublishedAt,
                    ':id' => $id,
                    ':feed_key' => (string)$feedKey,
                ]);
            }
        }
        site_setting_set($flagKey, '1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[site_article_feed] rebalance failed: ' . $e->getMessage());
    }
}

function site_article_feed_publish_initial(string $feedKey, array $config, int $limit, int $startIndex = 0): void
{
    $items = site_article_feed_select_items($feedKey, $config, $limit, true);
    $intervalSeconds = max(0, (int)($config['interval'] ?? 0) * 60);
    foreach ($items as $index => $item) {
        $offsetSeconds = ((int)$startIndex + (int)$index) * $intervalSeconds;
        site_article_feed_insert_item($feedKey, $config, $item, $offsetSeconds);
    }
}

function site_article_feed_maybe_publish(string $feedKey, array $config): void
{
    $currentCount = site_article_feed_count_items($feedKey);
    $limit = (int)$config['limit'];
    if ($currentCount < $limit) {
        site_article_feed_publish_initial($feedKey, $config, $limit - $currentCount, $currentCount);
        return;
    }

    $stmt = db()->prepare('SELECT published_at FROM site_article_feed_items WHERE feed_key = :feed_key ORDER BY published_at DESC LIMIT 1');
    $stmt->execute([':feed_key' => $feedKey]);
    $last = $stmt->fetchColumn();
    if (is_string($last) && strtotime($last) !== false && time() - (int)strtotime($last) < ((int)$config['interval'] * 60)) {
        return;
    }
    $item = site_article_feed_select_item($feedKey, $config);
    if ($item === null) {
        return;
    }
    site_article_feed_insert_item($feedKey, $config, $item);
}

function site_article_feed_items(string $feedKey, int $limit): array
{
    $stmt = db()->prepare('SELECT f.published_at AS feed_published_at, i.* FROM site_article_feed_items f INNER JOIN items i ON i.id = f.item_id WHERE f.feed_key = :feed_key ORDER BY f.published_at DESC, f.id DESC LIMIT :limit');
    $stmt->bindValue(':feed_key', $feedKey, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function site_article_feed_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function site_article_feed_render(string $feedKey): void
{
    $configs = site_article_feed_configs();
    if (!isset($configs[$feedKey])) {
        http_response_code(404);
        return;
    }
    $config = $configs[$feedKey];
    try {
        site_article_feed_ensure_table();
        site_article_feed_rebalance_existing_published_at_once($configs);
        site_article_feed_maybe_publish($feedKey, $config);
        $items = site_article_feed_items($feedKey, (int)$config['limit']);
    } catch (Throwable) {
        $items = [];
    }

    $siteTitle = trim(site_setting_get('site.title', site_setting_get('site.name', APP_NAME)));
    if ($siteTitle === '') {
        $siteTitle = APP_NAME;
    }
    $siteUrl = trim(site_setting_get('site.url', app_url()));
    if ($siteUrl === '') {
        $siteUrl = app_url();
    }

    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<rss version="2.0">
  <channel>
    <title><?= site_article_feed_xml($siteTitle . ' - ' . (string)$config['title']) ?></title>
    <link><?= site_article_feed_xml($siteUrl) ?></link>
    <description><?= site_article_feed_xml((string)$config['description']) ?></description>
    <language>ja</language>
    <lastBuildDate><?= site_article_feed_xml(date(DATE_RSS)) ?></lastBuildDate>
<?php foreach ($items as $item): ?>
<?php
        $title = trim((string)($item['title'] ?? ''));
        $link = site_article_feed_item_url($item);
        $image = site_article_feed_image($item);
        $publishedAt = trim((string)($item['feed_published_at'] ?? ''));
        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
        $description = '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"><img src="' . htmlspecialchars($image, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"></a>';
?>
    <item>
      <title><?= site_article_feed_xml($title) ?></title>
      <link><?= site_article_feed_xml($link) ?></link>
      <guid isPermaLink="false"><?= site_article_feed_xml($feedKey . ':' . (trim((string)($item['content_id'] ?? '')) !== '' ? trim((string)$item['content_id']) : (string)((int)($item['id'] ?? 0)))) ?></guid>
      <pubDate><?= site_article_feed_xml(date(DATE_RSS, $timestamp !== false ? $timestamp : time())) ?></pubDate>
      <description><![CDATA[<?= $description ?>]]></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
<?php
}
