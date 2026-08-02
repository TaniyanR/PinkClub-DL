<?php
declare(strict_types=1);

require_once __DIR__ . '/public/_bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/public/partials/public_ui.php';

function redirect_canonical_home_url(): void
{
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?: '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
    $homePath = $basePath . '/';
    $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($requestPath === '') {
        $requestPath = '/';
    }

    $homePaths = [
        $homePath,
        $basePath . '/index.php',
        $basePath . '/index.com',
        $basePath . '/public/',
        $basePath . '/public/index.php',
    ];

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (in_array($requestPath, $homePaths, true) && (!$isHttps || $requestPath !== $homePath)) {
        $canonicalUrl = rtrim(BASE_URL, '/') . '/';
        if (str_starts_with($canonicalUrl, 'http://')) {
            $canonicalUrl = 'https://' . substr($canonicalUrl, 7);
        }
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($queryString !== '') {
            $canonicalUrl .= '?' . $queryString;
        }
        header('Location: ' . $canonicalUrl, true, 301);
        exit;
    }
}

redirect_canonical_home_url();

function seeded_shuffle(array $rows, int $seed): array
{
    $count = count($rows);
    if ($count <= 1) {
        return $rows;
    }

    mt_srand($seed);
    for ($i = $count - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    }
    return $rows;
}

function pick_random_items(array $rows, int $seed, int $limit = 15): array
{
    $rows = seeded_shuffle($rows, $seed);
    return array_slice($rows, 0, $limit);
}


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

function collect_image_urls_from_value(mixed $value, array &$urls): void
{
    if (is_string($value)) {
        foreach (parse_index_image_urls($value) as $candidate) {
            $url = trim((string)$candidate);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        collect_image_urls_from_value($child, $urls);
    }
}

function pick_sample_movie_urls_from_raw(array $raw): array
{
    $urls = [];
    foreach (['sampleMovieURL', 'sample_movie_url', 'sampleMovieUrl', 'samplemovie', 'sampleMovie', 'sample_movie'] as $movieKeyName) {
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
        error_log('public/index.php query failed: ' . $e->getMessage());
        return [];
    }
}


function home_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    if (!in_array($table, ['item_genres', 'item_series', 'item_makers'], true)) {
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

function fetch_items_with_order_fallback(PDO $pdo, array $orderByCandidates, int $limit, int $offset = 0): array
{
    $limit = max(1, min(300, $limit));
    $offset = max(0, $offset);
    $sourceWhere = items_product_source_where();
    $sourceWhereSql = $sourceWhere !== '' ? ' WHERE ' . $sourceWhere : '';

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
                    if (trim((string)$image) !== '') {
                        $hasImageSample = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$hasImageSample) {
        foreach (parse_index_image_urls((string)($item['image_list'] ?? '')) as $image) {
            if (trim((string)$image) !== '') {
                $hasImageSample = true;
                break;
            }
        }
    }

    return ['movie_url' => $firstMovieUrl, 'movie_urls' => $movieUrls, 'has_images' => $hasImageSample];
}

function pick_full_package_image(array $item): string
{
    if (function_exists('pcf_item_image')) {
        return pcf_item_image($item);
    }

    return trim((string)($item['image_large'] ?? $item['image_small'] ?? ''));
}

function render_item_card(array $item, int $width = 180, ?array $taxonomy = null, bool $preferFullPackageImage = false, bool $lazyLoad = true): void
{
    $itemUrl = app_url('public/item.php?id=' . (int)$item['id']);
    $title = (string)($item['title'] ?? '');
    $sample = item_sample_state($item);
    $affiliateUrl = trim((string)($item['affiliate_url'] ?? $item['url'] ?? ''));
    $sampleFallbackUrl = $affiliateUrl !== '' ? public_url('out.php') . '?' . http_build_query(['to' => $affiliateUrl]) : '';
    $movieClass = ($sample['movie_url'] !== '' || $sampleFallbackUrl !== '') ? 'sample-button sample-button--enabled' : 'sample-button sample-button--disabled';
    $thumbUrl = trim((string)($item['image_small'] ?? ''));
    if ($preferFullPackageImage) {
        $fullPackageImage = pick_full_package_image($item);
        if ($fullPackageImage !== '') {
            $thumbUrl = $fullPackageImage;
        }
    }
    if ($thumbUrl === '') {
        $thumbUrl = trim((string)($item['image_large'] ?? ''));
    }
    $imageFallbacks = [];
    if (function_exists('pcf_item_image_candidates')) {
        $imageFallbacks = array_values(array_filter(pcf_item_image_candidates($item), static fn($url): bool => trim((string)$url) !== '' && trim((string)$url) !== $thumbUrl));
    }
    $imageFallbackAttr = $imageFallbacks !== [] ? ' data-image-fallbacks="' . e((string)json_encode($imageFallbacks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"' : '';
    ?>
    <article class="card rail-card rail-card--<?= (int)$width ?>" style="width:<?= (int)$width ?>px;min-width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;">
      <?php if ($thumbUrl !== ''): ?>
        <a href="<?= e($itemUrl) ?>"><img class="thumb" src="<?= e($thumbUrl) ?>" alt="<?= e($title) ?>"<?= $lazyLoad ? ' loading="lazy"' : '' ?> decoding="async"<?= $imageFallbackAttr ?> style="width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;"></a>
      <?php else: ?>
        <div class="rail-card__noimage" style="width:<?= (int)$width ?>px;height:<?= (int)$width ?>px;">画像なし</div>
      <?php endif; ?>
      <a class="rail-card__title" href="<?= e($itemUrl) ?>"><?= e($title) ?></a>
      <div class="sample-buttons">
        <?php $releaseDateRaw = trim((string)($item['release_date'] ?? '')); ?>
        <span style="display:block;width:100%;padding:12px 10px;text-align:center;color:#000;background:transparent;border:1px solid #000;border-radius:4px;font-size:14px;font-weight:700;box-sizing:border-box;"><?= $releaseDateRaw !== '' ? '発売日：' . e(format_date($releaseDateRaw)) : '発売日' ?></span>
        <?php if ($sample['movie_url'] !== ''): ?>
          <button type="button" class="<?= e($movieClass) ?> sample-movie-trigger" data-movie-url="<?= e((string)$sample['movie_url']) ?>" data-movie-title="<?= e($title) ?>">サンプル動画</button>
        <?php elseif ($sampleFallbackUrl !== ''): ?>
          <a class="<?= e($movieClass) ?>" href="<?= e($sampleFallbackUrl) ?>" target="_blank" rel="noopener noreferrer">サンプル動画</a>
        <?php else: ?>
          <button type="button" class="<?= e($movieClass) ?>" disabled>サンプル動画</button>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

function safe_include_partial(string $filePath): void
{
    try {
        include $filePath;
    } catch (Throwable $e) {
        error_log('public/index.php include failed: ' . $filePath . ' ' . $e->getMessage());
    }
}

function safe_render_home_ad(string $positionKey): void
{
    try {
        if (!function_exists('get_ad_code') || !function_exists('render_ad')) {
            return;
        }

        if (get_ad_code($positionKey) === null) {
            return;
        }

        echo '<div class="site-ad">';
        render_ad($positionKey, 'home', 'pc');
        echo '</div>';
    } catch (Throwable $e) {
        error_log('public/index.php ad render failed: ' . $positionKey . ' ' . $e->getMessage());
    }
}

$title = 'トップ';
$itemCount = 0;
$page = max(1, (int)get('page', 1));
$per = 32;
$pg = paginate(0, $page, $per);
$latestItems = [];

try {
    $pdo = db();
    $sourceWhere = items_product_source_where();
    $sourceWhereSql = $sourceWhere !== '' ? ' WHERE ' . $sourceWhere : '';
    $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM items' . $sourceWhereSql)->fetchColumn();

    if ($itemCount > 0) {
        $pg = paginate($itemCount, $page, $per);
        $latestRows = fetch_items_with_order_fallback($pdo, [
            'release_date DESC, updated_at DESC, id DESC',
            'date_published DESC, updated_at DESC, id DESC',
            'updated_at DESC, id DESC',
            'id DESC',
        ], $per + 8, (int)$pg['offset']);
        $usedItemKeys = [];
        $latestItems = take_unique_items_for_home($latestRows, $usedItemKeys, $per);
    }
} catch (Throwable $e) {
    error_log('public/index.php load failed: ' . $e->getMessage());
}

$title = 'トップ';
$pageDescription = function_exists('setting_site_tagline') ? setting_site_tagline('') : '';
$homeUrl = public_url('');
$canonicalUrl = $homeUrl
    . ((int)($pg['page'] ?? 1) > 1 ? '?' . http_build_query(['page' => (int)$pg['page']]) : '');
if ((int)($pg['page'] ?? 1) > 1) {
    $relPrev = $homeUrl . '?' . http_build_query(['page' => (int)$pg['page'] - 1]);
}
if ((int)($pg['page'] ?? 1) < (int)($pg['pages'] ?? 1)) {
    $relNext = $homeUrl . '?' . http_build_query(['page' => (int)$pg['page'] + 1]);
}
require __DIR__ . '/public/partials/header.php';
?>

<?php if ($itemCount === 0): ?>
  <div class="card"><p>まだ商品データが同期されていません。管理画面のAPI設定から「同期実行（DB保存）」を行ってください。</p></div>
<?php elseif ($latestItems === []): ?>
  <div class="card">
    <h2>表示できる商品データがありません</h2>
    <p><a class="button button--primary" href="<?= e(public_url('items.php')) ?>">商品一覧を見る</a></p>
  </div>
<?php else: ?>
  <section class="rail-section pinkclub-fl-product-section">
    <h2>新着作品</h2>
    <div class="pinkclub-fl-product-grid">
      <?php foreach ($latestItems as $index => $item): ?>
        <?php render_item_card($item, 200, null, true, $index >= 4); ?>
      <?php endforeach; ?>
    </div>
    <?php pcf_render_pagination($pg, $homeUrl); ?>
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
<?php require __DIR__ . '/public/partials/footer.php'; ?>
