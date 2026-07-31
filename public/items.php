<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';

function take_unique_items_for_home(array $items, array &$usedKeys, int $limit): array
{
    $limit = max(1, $limit);
    $result = [];

    foreach (dedupe_items_by_key($items) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $contentId = strtolower(trim((string)($item['content_id'] ?? '')));
        $productId = strtolower(trim((string)($item['product_id'] ?? '')));
        $id = trim((string)($item['id'] ?? ''));
        $key = $contentId !== '' ? 'content_id:' . $contentId : ($productId !== '' ? 'product_id:' . $productId : ($id !== '' ? 'id:' . $id : ''));

        if ($key !== '' && isset($usedKeys[$key])) {
            continue;
        }
        if ($key !== '') {
            $usedKeys[$key] = true;
        }

        $result[] = $item;
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

function decode_item_raw(array $item): array
{
    $raw = [];
    if (is_string($item['raw_json'] ?? null) && $item['raw_json'] !== '') {
        $decoded = json_decode((string)$item['raw_json'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }
    return $raw;
}

function normalize_movie_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }

    return '';
}

function parse_index_image_urls(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $trimmed = trim($value);
    if ($trimmed !== '' && $trimmed[0] === '[') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }
    }

    $parts = preg_split('/[\r\n,|\s]+/', $value);
    if (!is_array($parts)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
}


function index_is_self_hosted_duga_image_url(string $url): bool
{
    $value = trim($url);
    if ($value === '') {
        return false;
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }

    if (!preg_match('#^/(?:uploads|images|img|cache|thumbnails|thumbs|wp-content/uploads)(?:/|$)#i', $path)) {
        return false;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if ($host === null || $host === false || $host === '') {
        return str_starts_with($value, '/');
    }

    $siteHost = parse_url(public_url(''), PHP_URL_HOST);
    return is_string($siteHost) && strcasecmp($host, $siteHost) === 0;
}

function collect_movie_urls_from_value(mixed $value, array &$urls): void
{
    if (is_string($value)) {
        $candidate = normalize_movie_url($value);
        if ($candidate !== '') {
            $urls[] = $candidate;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        collect_movie_urls_from_value($child, $urls);
    }
}

function pick_sample_movie_urls_from_raw(array $raw): array
{
    $urls = [];
    foreach (['sampleMovieURL', 'sample_movie_url', 'sampleMovieUrl'] as $movieKeyName) {
        $rawMovie = $raw[$movieKeyName] ?? null;

        if (is_string($rawMovie)) {
            $candidate = normalize_movie_url($rawMovie);
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }

        if (is_array($rawMovie)) {
            foreach (['size_720_480', 'size_644_414', 'size_560_360', 'size_476_306'] as $movieKey) {
                $candidate = normalize_movie_url((string)($rawMovie[$movieKey] ?? ''));
                if ($candidate !== '') {
                    $urls[] = $candidate;
                }
            }

            collect_movie_urls_from_value($rawMovie, $urls);
        }
    }

    return array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)$u), $urls))));
}

function query_all_safe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            if (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, (string)$value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('public/items.php query failed: ' . $e->getMessage());
        return [];
    }
}

function index_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (!in_array($table, ['rss_items', 'rss_sources'], true)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function index_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    if (!in_array($table, ['items', 'rss_sources'], true)) {
        return false;
    }
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column');
        $stmt->execute([':column' => $column]);
        $cache[$cacheKey] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$cacheKey];
    } catch (Throwable) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function index_items_front_release_where(): string
{
    return '(items.release_date IS NULL OR items.release_date = "" OR items.release_date <= CURDATE())';
}

function index_items_product_source_where(PDO $pdo): string
{
    static $where = null;
    if ($where !== null) {
        return $where;
    }

    $parts = [];
    if (index_column_exists($pdo, 'items', 'item_source')) {
        $parts[] = 'items.item_source = "duga_product"';
    }
    $parts[] = index_items_front_release_where();
    if (index_table_exists($pdo, 'rss_items') && index_table_exists($pdo, 'rss_sources') && index_column_exists($pdo, 'rss_sources', 'source_type')) {
        $parts[] = 'NOT EXISTS (SELECT 1 FROM rss_items ri INNER JOIN rss_sources rs ON rs.id = ri.source_id WHERE rs.source_type = "partner_link" AND (ri.title = items.title OR ri.url = items.url OR ri.url = items.affiliate_url))';
    }

    $where = $parts !== [] ? ' WHERE ' . implode(' AND ', $parts) : '';
    return $where;
}

function fetch_items_with_order_fallback(PDO $pdo, array $orderByCandidates, int $limit, int $offset = 0): array
{
    $limit = max(1, min(300, $limit));
    $offset = max(0, $offset);
    $sourceWhereSql = index_items_product_source_where($pdo);

    foreach ($orderByCandidates as $orderBy) {
        $rows = query_all_safe($pdo, 'SELECT * FROM items' . $sourceWhereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function item_sample_state(array $item): array
{
    $raw = decode_item_raw($item);
    $movieUrls = [];
    foreach (['sample_movie_url_720', 'sample_movie_url_644', 'sample_movie_url_560', 'sample_movie_url_476'] as $column) {
        $candidate = trim((string)($item[$column] ?? ''));
        if ($candidate !== '') {
            $movieUrls[] = $candidate;
        }
    }

    $movieUrls = array_values(array_unique(array_merge($movieUrls, pick_sample_movie_urls_from_raw($raw))));
    $firstMovieUrl = $movieUrls[0] ?? '';

    $hasImageSample = false;
    $sampleImageUrl = $raw['sampleImageURL'] ?? null;
    if (is_array($sampleImageUrl)) {
        foreach (['sample_l', 'sample_s'] as $sampleKey) {
            $images = $sampleImageUrl[$sampleKey]['image'] ?? null;
            if (is_array($images)) {
                foreach ($images as $image) {
                    $sampleImageCandidate = trim((string)$image);
                    if ($sampleImageCandidate !== '' && !index_is_self_hosted_duga_image_url($sampleImageCandidate)) {
                        $hasImageSample = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$hasImageSample) {
        foreach (parse_index_image_urls((string)($item['image_list'] ?? '')) as $image) {
            $sampleImageCandidate = trim((string)$image);
            if ($sampleImageCandidate !== '' && !index_is_self_hosted_duga_image_url($sampleImageCandidate)) {
                $hasImageSample = true;
                break;
            }
        }
    }

    return ['movie_url' => $firstMovieUrl, 'movie_urls' => $movieUrls, 'has_images' => $hasImageSample];
}

function pick_full_package_image(array $item): string
{
    foreach (['image_large', 'image_list', 'image_small'] as $key) {
        if ($key === 'image_list') {
            foreach (parse_index_image_urls((string)($item['image_list'] ?? '')) as $image) {
                $candidate = trim((string)$image);
                if ($candidate !== '' && !index_is_self_hosted_duga_image_url($candidate)) {
                    return $candidate;
                }
            }
            continue;
        }
        $candidate = trim((string)($item[$key] ?? ''));
        if ($candidate !== '' && !index_is_self_hosted_duga_image_url($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function render_item_card(array $item, int $width = 180, ?array $taxonomy = null, bool $preferFullPackageImage = false, bool $lazyLoad = true): void
{
    $itemUrl = app_url('public/item.php?id=' . (int)$item['id']);
    $title = (string)($item['title'] ?? '');
    $sample = item_sample_state($item);
    $movieClass = $sample['movie_url'] !== '' ? 'sample-button sample-button--enabled' : 'sample-button sample-button--disabled';
    $thumbUrl = trim((string)($item['image_small'] ?? ''));
    if ($preferFullPackageImage) {
        $fullPackageImage = pick_full_package_image($item);
        if ($fullPackageImage !== '' && !index_is_self_hosted_duga_image_url($fullPackageImage)) {
            $thumbUrl = $fullPackageImage;
        }
    }
    if ($thumbUrl !== '' && index_is_self_hosted_duga_image_url($thumbUrl)) {
        $thumbUrl = '';
    }
    if ($thumbUrl === '') {
        $thumbUrl = trim((string)($item['image_large'] ?? ''));
    }
    if ($thumbUrl !== '' && index_is_self_hosted_duga_image_url($thumbUrl)) {
        $thumbUrl = '';
    }
    ?>
    <article class="card rail-card rail-card--<?= (int)$width ?>" style="width:<?= (int)$width ?>px;min-width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;">
      <?php if ($thumbUrl !== ''): ?>
        <a href="<?= e($itemUrl) ?>"><img class="thumb" src="<?= e($thumbUrl) ?>" alt="<?= e($title) ?>"<?= $lazyLoad ? ' loading="lazy"' : '' ?> decoding="async" style="width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;"></a>
      <?php else: ?>
        <div class="rail-card__noimage" style="width:<?= (int)$width ?>px;height:<?= (int)$width ?>px;">画像なし</div>
      <?php endif; ?>
      <a class="rail-card__title" href="<?= e($itemUrl) ?>"><?= e($title) ?></a>
      <div class="sample-buttons">
        <?php $releaseDateRaw = trim((string)($item['release_date'] ?? '')); ?>
        <span style="display:block;width:100%;padding:12px 10px;text-align:center;color:#000;background:transparent;border:1px solid #000;border-radius:4px;font-size:14px;font-weight:700;box-sizing:border-box;"><?= $releaseDateRaw !== '' ? '発売日：' . e(format_date($releaseDateRaw)) : '発売日' ?></span>
        <button type="button" class="<?= e($movieClass) ?> sample-movie-trigger" <?= $sample['movie_url'] === '' ? 'disabled' : '' ?> data-movie-url="<?= e((string)$sample['movie_url']) ?>" data-movie-title="<?= e($title) ?>">サンプル動画</button>
      </div>
    </article>
    <?php
}

$title = '商品一覧';
$itemCount = 0;
$page = max(1, (int)get('page', 1));
$per = 32;
$pg = paginate(0, $page, $per);
$latestItems = [];
$fallbackItems = [];

try {
    $pdo = db();
    $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM items' . index_items_product_source_where($pdo))->fetchColumn();

    if ($itemCount > 0) {
        $pg = paginate($itemCount, $page, $per);
        $usedHomeItemKeys = [];
        $latestRows = fetch_items_with_order_fallback($pdo, [
            'release_date DESC, updated_at DESC, id DESC',
            'date_published DESC, updated_at DESC, id DESC',
            'updated_at DESC, id DESC',
            'id DESC',
        ], $per + 8, (int)$pg['offset']);
        $latestItems = take_unique_items_for_home($latestRows, $usedHomeItemKeys, $per);
        $fallbackItems = array_slice($latestItems, 0, 12);
    }
} catch (Throwable $e) {
    error_log('public/items.php failed: ' . $e->getMessage());
}

$pageDescription = 'DUGA商品一覧。最新作・人気作品を掲載。';
$canonicalUrl = public_url('items.php')
    . ((int)($pg['page'] ?? 1) > 1 ? '?' . http_build_query(['page' => (int)$pg['page']]) : '');
if ((int)($pg['page'] ?? 1) > 1) {
    $relPrev = public_url('items.php') . '?' . http_build_query(['page' => (int)$pg['page'] - 1]);
}
if ((int)($pg['page'] ?? 1) < (int)($pg['pages'] ?? 1)) {
    $relNext = public_url('items.php') . '?' . http_build_query(['page' => (int)$pg['page'] + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php if ($itemCount === 0): ?>
  <div class="card"><p>まだ商品データが同期されていません。管理画面のAPI設定から「同期実行（DB保存）」を行ってください。</p></div>
<?php elseif ($latestItems === []): ?>
  <div class="card">
    <h2>表示できる本文データがまだありません</h2>
    <p>商品データは存在しますが、新着作品を組み立てられませんでした。</p>
  </div>
  <?php if ($fallbackItems !== []): ?>
    <section class="rail-section">
      <h2>取得できた作品</h2>
      <div class="rail-row rail-row--180"><?php foreach ($fallbackItems as $index => $item) { render_item_card($item, 180, null, false, $index >= 6); } ?></div>
    </section>
  <?php endif; ?>
<?php else: ?>
  <section class="rail-section">
    <h2>新着作品</h2>
    <div class="pinkclub-fl-product-grid"><?php foreach ($latestItems as $index => $item) { render_item_card($item, 200, null, true, $index >= 4); } ?></div>
    <?php pcf_render_pagination($pg, public_url('items.php')); ?>
  </section>
<?php endif; ?>

<div id="sample-movie-modal" class="sample-movie-modal" aria-hidden="true">
  <div class="sample-movie-modal__overlay" data-movie-close="1"></div>
  <div class="sample-movie-modal__dialog" role="dialog" aria-modal="true" aria-label="サンプル動画プレイヤー">
    <button type="button" class="sample-movie-modal__close" data-movie-close="1" aria-label="閉じる">×</button>
    <div id="sample-movie-title" class="sample-movie-modal__title">サンプル動画</div>
    <div class="sample-movie-modal__frame-wrap">
      <iframe id="sample-movie-frame" class="sample-movie-modal__frame" src="about:blank" allow="autoplay; fullscreen" referrerpolicy="no-referrer"></iframe>
    </div>
  </div>
</div>
<script>
(() => {
  const modal = document.getElementById('sample-movie-modal');
  const frame = document.getElementById('sample-movie-frame');
  const titleNode = document.getElementById('sample-movie-title');
  if (!modal || !frame || !titleNode) return;

  const openMovie = (url, title) => {
    if (!url) return;
    const normalizedTitle = String(title || '').trim();
    titleNode.textContent = normalizedTitle !== '' ? normalizedTitle : 'サンプル動画';
    modal.style.setProperty('--movie-modal-width', '900px');
    frame.src = url;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };

  const closeMovie = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    modal.style.removeProperty('--movie-modal-width');
    titleNode.textContent = 'サンプル動画';
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.sample-movie-trigger');
    if (trigger && !trigger.disabled) {
      event.preventDefault();
      const card = trigger.closest('.rail-card');
      const fallbackTitle = card ? (card.querySelector('.rail-card__title')?.textContent || '') : '';
      openMovie(trigger.dataset.movieUrl || '', trigger.dataset.movieTitle || fallbackTitle);
      return;
    }

    if (event.target.closest('[data-movie-close="1"]')) {
      event.preventDefault();
      closeMovie();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeMovie();
    }
  });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
