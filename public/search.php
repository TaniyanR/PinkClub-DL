<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/repository.php';

function search_item_has_product_source(array $item): bool
{
    if ((string)($item['item_source'] ?? '') === 'duga_product') {
        return true;
    }

    foreach (['affiliate_url', 'service_code', 'floor_code', 'sample_movie_url_476', 'sample_movie_url_560', 'sample_movie_url_644', 'sample_movie_url_720'] as $key) {
        if (trim((string)($item[$key] ?? '')) !== '') {
            return true;
        }
    }

    $rawJson = (string)($item['raw_json'] ?? '');
    if ($rawJson !== '') {
        $raw = json_decode($rawJson, true);
        if (is_array($raw)) {
            foreach (['affiliateURL', 'service_code', 'floor_code', 'sampleMovieURL'] as $key) {
                if (isset($raw[$key])) {
                    return true;
                }
            }
        }
    }

    return false;
}

function search_relation_checks(string $term, int $termIndex, array &$params): array
{
    $relations = [
        ['table' => 'item_actresses', 'column' => 'actress_name'],
        ['table' => 'item_genres', 'column' => 'genre_name'],
        ['table' => 'item_makers', 'column' => 'maker_name'],
        ['table' => 'item_labels', 'column' => 'label_name'],
        ['table' => 'item_series', 'column' => 'series_name'],
        ['table' => 'item_directors', 'column' => 'director_name'],
        ['table' => 'item_authors', 'column' => 'author_name'],
    ];
    $checks = [];

    $like = '%' . addcslashes($term, '\%_') . '%';
    foreach ($relations as $relationIndex => $relation) {
        $table = $relation['table'];
        $column = $relation['column'];
        if (!db_table_exists($table) || !db_column_exists($table, $column)) {
            continue;
        }

        $joins = [];
        if (db_column_exists($table, 'item_id')) {
            $joins[] = 'r.item_id = items.id';
        }
        if (db_column_exists($table, 'content_id')) {
            $joins[] = 'r.content_id = items.content_id';
        }
        if ($joins === []) {
            continue;
        }

        $likeParam = ':q_relation_' . $termIndex . '_' . $relationIndex;
        $params[$likeParam] = $like;
        $checks[] = 'EXISTS (SELECT 1 FROM `' . $table . '` r'
            . ' WHERE (' . implode(' OR ', $joins) . ')'
            . ' AND r.`' . $column . '` LIKE ' . $likeParam . " ESCAPE '\\\\')";
    }

    return $checks;
}

function search_exact_relation_check(string $type, string $param): string
{
    $relations = [
        'actress' => ['table' => 'item_actresses', 'column' => 'actress_name'],
        'genre' => ['table' => 'item_genres', 'column' => 'genre_name'],
        'maker' => ['table' => 'item_makers', 'column' => 'maker_name'],
        'label' => ['table' => 'item_labels', 'column' => 'label_name'],
        'series' => ['table' => 'item_series', 'column' => 'series_name'],
    ];
    if (!isset($relations[$type])) {
        return '';
    }

    $table = $relations[$type]['table'];
    $column = $relations[$type]['column'];
    if (!db_table_exists($table) || !db_column_exists($table, $column)) {
        return '';
    }

    $joins = [];
    if (db_column_exists($table, 'item_id')) {
        $joins[] = 'r.item_id = items.id';
    }
    if (db_column_exists($table, 'content_id')) {
        $joins[] = 'r.content_id = items.content_id';
    }
    if ($joins === []) {
        return '';
    }

    return 'EXISTS (SELECT 1 FROM `' . $table . '` r'
        . ' WHERE (' . implode(' OR ', $joins) . ')'
        . ' AND r.`' . $column . '` = ' . $param . ')';
}

function search_item_raw(array $item): array
{
    $rawJson = (string)($item['raw_json'] ?? '');
    if ($rawJson === '') {
        return [];
    }

    $decoded = json_decode($rawJson, true);
    return is_array($decoded) ? $decoded : [];
}

function search_item_affiliate_url(array $item): string
{
    $affiliateUrl = trim((string)($item['affiliate_url'] ?? ''));
    if ($affiliateUrl !== '') {
        return $affiliateUrl;
    }

    $raw = search_item_raw($item);
    return trim((string)($raw['affiliateURL'] ?? ''));
}

function search_partner_rss_lookup(array $items): ?array
{
    $titles = [];
    $urls = [];
    $images = [];

    foreach ($items as $item) {
        $title = trim(pcf_item_title($item));
        $url = trim((string)($item['url'] ?? ''));
        $affiliateUrl = search_item_affiliate_url($item);
        $imageSmall = trim((string)($item['image_small'] ?? ''));
        $imageLarge = trim((string)($item['image_large'] ?? ''));

        if ($title !== '') {
            $titles[$title] = true;
        }
        foreach ([$url, $affiliateUrl] as $candidateUrl) {
            if ($candidateUrl !== '') {
                $urls[$candidateUrl] = true;
            }
        }
        foreach ([$imageSmall, $imageLarge] as $candidateImage) {
            if ($candidateImage !== '') {
                $images[$candidateImage] = true;
            }
        }
    }

    $where = [];
    $params = [];
    foreach ([
        'title' => array_keys($titles),
        'url' => array_keys($urls),
        'image_url' => array_keys($images),
    ] as $column => $values) {
        if ($values === []) {
            continue;
        }

        $placeholders = [];
        foreach ($values as $index => $value) {
            $placeholder = ':rss_' . $column . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }
        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    if ($where === []) {
        return ['titles' => [], 'urls' => [], 'images' => []];
    }

    try {
        $stmt = db()->prepare(
            'SELECT title, url, image_url FROM rss_items WHERE ' . implode(' OR ', $where)
        );
        $stmt->execute($params);

        $lookup = ['titles' => [], 'urls' => [], 'images' => []];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $image = trim((string)($row['image_url'] ?? ''));
            if ($title !== '') {
                $lookup['titles'][$title] = true;
            }
            if ($url !== '') {
                $lookup['urls'][$url] = true;
            }
            if ($image !== '') {
                $lookup['images'][$image] = true;
            }
        }

        return $lookup;
    } catch (Throwable) {
        return null;
    }
}

function search_item_matches_partner_rss(array $item, ?array $lookup = null): bool
{
    $title = trim(pcf_item_title($item));
    $url = trim((string)($item['url'] ?? ''));
    $affiliateUrl = search_item_affiliate_url($item);
    $imageSmall = trim((string)($item['image_small'] ?? ''));
    $imageLarge = trim((string)($item['image_large'] ?? ''));

    if ($title === '' && $url === '' && $affiliateUrl === '' && $imageSmall === '' && $imageLarge === '') {
        return false;
    }

    if ($lookup !== null) {
        return isset($lookup['titles'][$title])
            || isset($lookup['urls'][$url])
            || isset($lookup['urls'][$affiliateUrl])
            || isset($lookup['images'][$imageSmall])
            || isset($lookup['images'][$imageLarge]);
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM rss_items ri INNER JOIN rss_sources rs ON rs.id = ri.source_id WHERE rs.source_type = "partner_link" AND (ri.title = :title OR ri.url = :url OR ri.url = :affiliate_url OR ri.image_url = :image_small OR ri.image_url = :image_large) LIMIT 1');
        $stmt->execute([':title' => $title, ':url' => $url, ':affiliate_url' => $affiliateUrl, ':image_small' => $imageSmall, ':image_large' => $imageLarge]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable) {
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM rss_items WHERE title = :title OR url = :url OR url = :affiliate_url OR image_url = :image_small OR image_url = :image_large LIMIT 1');
        $stmt->execute([':title' => $title, ':url' => $url, ':affiliate_url' => $affiliateUrl, ':image_small' => $imageSmall, ':image_large' => $imageLarge]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function search_normalize_query(string $value): string
{
    $value = str_replace('　', ' ', trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function search_query_terms(string $query): array
{
    $query = search_normalize_query($query);
    if ($query === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $query) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $term = trim((string)$part);
        if ($term === '') {
            continue;
        }
        $terms[$term] = true;
    }

    return array_slice(array_keys($terms), 0, 8);
}

function search_compact_text(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace("/[\s　「」『』【】（）()［］\[\]｛｝{}・,，、。.!！?？:：;；ー－―‐\"'“”‘’]+/u", '', $value) ?? '';
}

function search_text_contains(string $haystack, string $needle): bool
{
    $haystack = (string)$haystack;
    $needle = trim($needle);
    if ($needle === '') {
        return false;
    }

    if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
        return true;
    }

    $compactHaystack = search_compact_text($haystack);
    $compactNeedle = search_compact_text($needle);
    return $compactNeedle !== '' && mb_strpos($compactHaystack, $compactNeedle, 0, 'UTF-8') !== false;
}

function search_item_matches_query(array $item, string $query): bool
{
    $terms = search_query_terms($query);
    if ($terms === []) {
        return false;
    }

    $title = pcf_item_title($item);
    $rawJson = (string)($item['raw_json'] ?? '');
    $contentId = trim((string)($item['content_id'] ?? ''));
    $productId = trim((string)($item['product_id'] ?? ''));

    foreach ($terms as $term) {
        if ($contentId === $term || $productId === $term) {
            return true;
        }
        if (search_text_contains($title, $term) || search_text_contains($rawJson, $term)) {
            return true;
        }
    }

    return false;
}

function search_item_is_displayable(array $item, ?array $rssLookup = null): bool
{
    if (search_item_matches_partner_rss($item, $rssLookup)) {
        return false;
    }

    if (!search_item_has_product_source($item)) {
        return false;
    }

    if (pcf_item_title($item) === 'タイトル未設定') {
        return false;
    }

    if (trim(pcf_item_image($item)) === '') {
        return false;
    }

    return true;
}

function search_fetch_items(string $query, int $limit, int $offset, string $exactType = ''): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $terms = search_query_terms($query);
    if ($terms === []) {
        return [];
    }

    $params = [];
    $exactParam = ':q_exact';
    $exactCheck = $exactType !== '' ? search_exact_relation_check($exactType, $exactParam) : '';
    if ($exactCheck !== '') {
        $params[$exactParam] = $query;
        $whereSql = $exactCheck;
    } else {
        $termWhere = [];
        foreach ($terms as $index => $term) {
            $titleParam = ':q_title_' . $index;
            $contentParam = ':q_content_id_' . $index;
            $productParam = ':q_product_id_' . $index;
            $like = '%' . addcslashes($term, '\%_') . '%';
            $params[$titleParam] = $like;
            $params[$contentParam] = $term;
            $params[$productParam] = $term;
            $termChecks = [
                "title LIKE {$titleParam} ESCAPE '\\\\'",
                "content_id = {$contentParam}",
                "product_id = {$productParam}",
                ...search_relation_checks($term, $index, $params),
            ];
            $termWhere[] = '(' . implode(' OR ', $termChecks) . ')';
        }
        $whereSql = '(' . implode(' OR ', $termWhere) . ')';
    }
    $orderSqlCandidates = [
        'release_date DESC, id DESC',
        'date_published DESC, id DESC',
        'updated_at DESC, id DESC',
        'id DESC',
    ];

    foreach ($orderSqlCandidates as $orderSql) {
        try {
            $chunkSize = max($limit + 1, 25);
            $cursor = 0;
            $targetCount = $offset + $limit + 1;
            $maxLoops = 30;
            $collected = [];

            for ($i = 0; $i < $maxLoops; $i++) {
                $stmt = db()->prepare('SELECT * FROM items WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT :l OFFSET :o');
                foreach ($params as $paramName => $paramValue) {
                    $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
                }
                $stmt->bindValue(':l', $chunkSize, PDO::PARAM_INT);
                $stmt->bindValue(':o', $cursor, PDO::PARAM_INT);
                $stmt->execute();
                $chunk = $stmt->fetchAll() ?: [];
                if ($chunk === []) {
                    break;
                }

                $rawFetched = count($chunk);
                $rssLookup = search_partner_rss_lookup($chunk);
                $chunk = array_values(array_filter(
                    $chunk,
                    static fn(array $row): bool => search_item_is_displayable($row, $rssLookup)
                ));
                $collected = dedupe_items_by_key(array_merge($collected, $chunk));
                if (count($collected) >= $targetCount) {
                    break;
                }

                $cursor += $rawFetched;
                if ($rawFetched < $chunkSize) {
                    break;
                }
            }

            return array_slice($collected, $offset, $limit + 1);
        } catch (Throwable $exception) {
            error_log('public/search.php failed: ' . $exception->getMessage());
        }
    }

    return [];
}

$searchQuery = safe_str($_GET['q'] ?? '', 200);
$searchType = safe_str($_GET['type'] ?? '', 20);
if (!in_array($searchType, ['actress', 'genre', 'maker', 'label', 'series'], true)) {
    $searchType = '';
}
$page = normalize_int((int)($_GET['page'] ?? 1), 1, 100000);
$limit = 32;
$offset = ($page - 1) * $limit;
$searchRows = search_fetch_items($searchQuery, $limit, $offset, $searchType);
[$searchItems, $searchHasNext] = paginate_items($searchRows, $limit);

$title = '検索結果';
$pageDescription = $searchQuery !== '' ? mb_strimwidth('「' . $searchQuery . '」の商品検索結果です。', 0, 150, '…', 'UTF-8') : 'キーワードを入力して商品を検索できます。';
$canonicalQuery = [];
if ($searchQuery !== '') {
    $canonicalQuery['q'] = $searchQuery;
}
if ($searchType !== '') {
    $canonicalQuery['type'] = $searchType;
}
if ($page > 1) {
    $canonicalQuery['page'] = $page;
}
$canonicalUrl = public_url('search.php') . ($canonicalQuery !== [] ? '?' . http_build_query($canonicalQuery) : '');
if ($page > 1) {
    $relPrev = public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'type' => $searchType, 'page' => $page - 1]);
}
if ($searchHasNext) {
    $relNext = public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'type' => $searchType, 'page' => $page + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_hero('検索結果', $searchQuery !== '' ? '「' . $searchQuery . '」の商品検索結果です。' : 'キーワードを入力して商品を検索できます。'); ?>

<?php if ($searchQuery === ''): ?>
  <?php pcf_render_empty('検索キーワードを入力してください。'); ?>
<?php elseif ($searchItems !== []): ?>
  <section class="pcf-related-grid pinkclub-fl-related-grid">
    <?php foreach ($searchItems as $item): ?>
      <?php pcf_render_item_card(is_array($item) ? $item : [], 180, true); ?>
    <?php endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($page > 1): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'type' => $searchType, 'page' => $page - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$page) ?></span>
    <?php if ($searchHasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'type' => $searchType, 'page' => $page + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('検索条件に一致する商品がありません。'); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
