<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

function item_normalize_movie_url(string $url): string
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

function item_collect_movie_urls(mixed $value, array &$urls): void
{
    if (is_string($value)) {
        $candidate = item_normalize_movie_url($value);
        if ($candidate !== '') {
            $urls[] = $candidate;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        item_collect_movie_urls($child, $urls);
    }
}

function item_pick_movie_urls_from_raw(array $raw): array
{
    $urls = [];
    foreach (['sampleMovieURL', 'sample_movie_url', 'sampleMovieUrl', 'samplemovie', 'sampleMovie', 'sample_movie'] as $movieKeyName) {
        $rawMovie = $raw[$movieKeyName] ?? null;

        if (is_string($rawMovie)) {
            $candidate = item_normalize_movie_url($rawMovie);
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }

        if (is_array($rawMovie)) {
            foreach (['size_720_480', 'size_644_414', 'size_560_360', 'size_476_306'] as $movieKey) {
                $candidate = item_normalize_movie_url((string)($rawMovie[$movieKey] ?? ''));
                if ($candidate !== '') {
                    $urls[] = $candidate;
                }
            }

            item_collect_movie_urls($rawMovie, $urls);
        }
    }

    return array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)$u), $urls))));
}

function item_parse_image_urls(?string $value): array
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

function item_collect_sample_image_urls(mixed $value, array &$images): void
{
    if (is_string($value)) {
        foreach (item_parse_image_urls($value) as $candidate) {
            $url = trim((string)$candidate);
            if ($url !== '') {
                $images[] = $url;
            }
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        item_collect_sample_image_urls($child, $images);
    }
}

function item_pick_raw_text(array $raw, array $keys): string
{
    foreach ($keys as $key) {
        $value = $raw[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (is_string($child) && trim($child) !== '') {
                    return trim($child);
                }
                if (is_array($child)) {
                    foreach (['value', 'text', 'name'] as $childKey) {
                        if (isset($child[$childKey]) && is_string($child[$childKey]) && trim($child[$childKey]) !== '') {
                            return trim((string)$child[$childKey]);
                        }
                    }
                }
            }
        }
    }

    return '';
}

function item_tag_links_from_text(string $tagText): array
{
    $parts = preg_split('/[\s,、]+/u', trim($tagText));
    if (!is_array($parts)) {
        return [];
    }

    $links = [];
    foreach ($parts as $part) {
        $tagName = trim((string)$part);
        if ($tagName === '') {
            continue;
        }
        $tagName = ltrim($tagName, '#＃');
        if ($tagName === '') {
            continue;
        }
        $links[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $tagName])) . '">' . e($tagName) . '</a>';
    }

    return $links;
}

function item_collect_named_values(mixed $value, array &$values): void
{
    if (is_string($value)) {
        $text = trim((string)$value);
        if ($text !== '') {
            $values[] = $text;
        }
        return;
    }
    if (!is_array($value)) {
        return;
    }
    if (isset($value['name']) && is_string($value['name'])) {
        $name = trim((string)$value['name']);
        if ($name !== '') {
            $values[] = $name;
        }
    }
    foreach ($value as $child) {
        item_collect_named_values($child, $values);
    }
}

function item_is_invalid_description(string $text): bool
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($normalized === '') {
        return true;
    }

    return str_contains($normalized, 'ここから先は、アダルト商品を扱うアダルトサイトとなります')
        || str_contains($normalized, '18歳未満の方のアクセスは固くお断りします')
        || str_contains($normalized, '年齢認証')
        || str_contains($normalized, 'adult only');
}

function item_is_invalid_title(string $title): bool
{
    $normalized = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($normalized === '') {
        return true;
    }

    return str_contains($normalized, 'お問い合わせ')
        || str_contains($normalized, '問合せ')
        || $normalized === 'Privacy Policy'
        || $normalized === 'サイトについて';
}

function item_unique_rows(array $rows, array $keys): array
{
    $unique = [];
    $seen = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $parts = [];
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts === []) {
            $unique[] = $row;
            continue;
        }

        $signature = implode('|', $parts);
        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $unique[] = $row;
    }

    return $unique;
}

$id = (int)get('id', 0);
$contentId = trim((string)get('content_id', ''));
$cid = trim((string)get('cid', ''));

if ($contentId === '' && $cid !== '') {
    $contentId = $cid;
}

$item = false;
try {
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM items WHERE id = ? AND ' . items_front_release_where());
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (is_array($item)) {
            $itemTitle = trim((string)($item['title'] ?? ''));
            if (item_is_invalid_title($itemTitle)) {
                $itemByContentId = fetch_item_by_content_id((string)($item['content_id'] ?? ''));
                if (is_array($itemByContentId)) {
                    $item = $itemByContentId;
                }
            }
        }
    } elseif ($contentId !== '') {
        $item = fetch_item_by_content_id($contentId);
    }
} catch (Throwable) {
    $item = false;
}

if (!$item) {
    require __DIR__ . '/404.php';
}

$relatedItems = [];
$actresses = [];
$genres = [];
$makers = [];
$seriesList = [];
$authors = [];
$viewport = (string)($_COOKIE['pcf_viewport'] ?? '');
$clientHintMobile = trim((string)($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isMobileViewport = $viewport === 'sp' || $clientHintMobile === '?1' || ($userAgent !== '' && preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|webOS/i', $userAgent));

try {
    $relatedItems = fetch_related_items((string)$item['content_id'], 12);
} catch (Throwable) {
    $relatedItems = [];
}
try {
    $actresses = fetch_item_actresses((string)$item['content_id']);
} catch (Throwable) {
    $actresses = [];
}
try {
    $genres = fetch_item_genres((string)$item['content_id']);
} catch (Throwable) {
    $genres = [];
}
try {
    $makers = fetch_item_makers((string)$item['content_id']);
} catch (Throwable) {
    $makers = [];
}
try {
    $seriesList = fetch_item_series((string)$item['content_id']);
} catch (Throwable) {
    $seriesList = [];
}

if (db_table_exists('item_authors')) {
    try {
        $authorSql = db_column_exists('item_authors', 'item_id')
            ? 'SELECT DISTINCT authors.id, authors.name
               FROM items
               INNER JOIN item_authors ON item_authors.item_id = items.id
               INNER JOIN authors ON authors.duga_id = item_authors.duga_id
               WHERE items.content_id = ?
               ORDER BY authors.name'
            : 'SELECT author_id AS id, author_name AS name
               FROM item_authors
               WHERE content_id = ?
               ORDER BY author_name';
        $authorStmt = db()->prepare($authorSql);
        $authorStmt->execute([(string)$item['content_id']]);
        $authors = $authorStmt->fetchAll() ?: [];
    } catch (Throwable) {
        $authors = [];
    }
}

$relatedItems = dedupe_items_by_key($relatedItems);
if ($isMobileViewport) {
    $relatedItems = array_slice($relatedItems, 0, 10);
}
$actresses = item_unique_rows($actresses, ['id', 'name']);
$genres = item_unique_rows($genres, ['id', 'name']);
$makers = item_unique_rows($makers, ['id', 'name']);
$seriesList = item_unique_rows($seriesList, ['id', 'name']);
$authors = item_unique_rows($authors, ['id', 'name']);

$actresses = array_values(array_filter($actresses, static function ($row): bool {
    return is_array($row) && !pcf_is_noise_name((string)($row['name'] ?? ''));
}));
$genres = array_values(array_filter($genres, static function ($row): bool {
    return is_array($row) && !pcf_is_noise_name((string)($row['name'] ?? ''));
}));
$makers = array_values(array_filter($makers, static function ($row): bool {
    return is_array($row) && !pcf_is_noise_name((string)($row['name'] ?? ''));
}));
$seriesList = array_values(array_filter($seriesList, static function ($row): bool {
    return is_array($row) && !pcf_is_noise_name((string)($row['name'] ?? ''));
}));
$authors = array_values(array_filter($authors, static function ($row): bool {
    return is_array($row) && !pcf_is_noise_name((string)($row['name'] ?? ''));
}));

$raw = [];
if (is_string($item['raw_json'] ?? null) && $item['raw_json'] !== '') {
    $decoded = json_decode($item['raw_json'], true);
    if (is_array($decoded)) {
        $raw = $decoded;
    }
}

$sampleMovieUrl = '';
foreach (['sample_movie_url_720', 'sample_movie_url_644', 'sample_movie_url_560', 'sample_movie_url_476'] as $movieColumn) {
    $candidate = trim((string)($item[$movieColumn] ?? ''));
    if ($candidate !== '') {
        $sampleMovieUrl = item_normalize_movie_url($candidate);
        break;
    }
}
if ($sampleMovieUrl === '') {
    $sampleMovieUrls = item_pick_movie_urls_from_raw($raw);
    $sampleMovieUrl = (string)($sampleMovieUrls[0] ?? '');
}
$sampleMoviePath = (string)(parse_url($sampleMovieUrl, PHP_URL_PATH) ?: '');
$sampleMovieIsVideo = $sampleMovieUrl !== '' && preg_match('/\.(?:mp4|webm|ogg)$/i', $sampleMoviePath) === 1;
$sampleMoviePoster = '';
$rawSampleMovie = is_array($raw['samplemovie'] ?? null) ? $raw['samplemovie'] : [];
$rawSampleMovie = $rawSampleMovie !== []
    ? $rawSampleMovie
    : (is_array($raw['sampleMovie'] ?? null) ? $raw['sampleMovie'] : []);
$rawSampleMovieMedium = is_array($rawSampleMovie['midium'] ?? null)
    ? $rawSampleMovie['midium']
    : (is_array($rawSampleMovie['medium'] ?? null) ? $rawSampleMovie['medium'] : []);
if (isset($rawSampleMovieMedium['capture'])) {
    $sampleMoviePoster = item_normalize_movie_url((string)$rawSampleMovieMedium['capture']);
}

$sampleImages = [];
$sampleImagesSmall = [];
$sampleImageUrl = $raw['sampleImageURL'] ?? null;
if (is_array($sampleImageUrl)) {
    item_collect_sample_image_urls($sampleImageUrl['sample_l']['image'] ?? null, $sampleImages);
    item_collect_sample_image_urls($sampleImageUrl['sample_s']['image'] ?? null, $sampleImagesSmall);
} elseif (is_string($sampleImageUrl)) {
    item_collect_sample_image_urls($sampleImageUrl, $sampleImages);
}
$sampleImages = array_values(array_unique($sampleImages));
$sampleImagesSmall = array_values(array_unique($sampleImagesSmall));
$sampleImages = array_values(array_filter(array_slice($sampleImages, 0, 24), static fn($url) => !pcf_is_self_hosted_duga_image_url((string)$url)));
$sampleImagesSmall = array_values(array_filter(array_slice($sampleImagesSmall, 0, 24), static fn($url) => !pcf_is_self_hosted_duga_image_url((string)$url)));
$sampleImagesSmallLargeMap = [];
$sampleImageCount = max(count($sampleImages), count($sampleImagesSmall));
for ($i = 0; $i < $sampleImageCount; $i++) {
    $smallImage = trim((string)($sampleImagesSmall[$i] ?? $sampleImages[$i] ?? ''));
    $largeImage = trim((string)($sampleImages[$i] ?? $smallImage));
    if ($smallImage === '' || $largeImage === '') {
        continue;
    }
    $sampleImagesSmallLargeMap[] = ['small' => $smallImage, 'large' => $largeImage];
}

$fullPackageImage = trim((string)($item['image_large'] ?? ''));
if ($fullPackageImage === '') {
    $imageListRaw = (string)($item['image_list'] ?? '');
    $imageList = preg_split('/[\r\n,|\s]+/', $imageListRaw);
    if (is_array($imageList)) {
        foreach ($imageList as $imageListValue) {
            $candidate = trim((string)$imageListValue);
            if ($candidate !== '') {
                $fullPackageImage = $candidate;
                break;
            }
        }
    }
}

$desc = trim((string)($item['description'] ?? ''));
if ($desc === '') {
    $desc = item_pick_raw_text($raw, ['comment', 'description', 'caption', 'story', 'introduction']);
}
if ($desc === '') {
    $desc = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['comment', 'description', 'caption', 'story', 'introduction']);
}
if ($desc === '' && db_table_exists('articles')) {
    try {
        $articleStmt = db()->prepare('SELECT description FROM articles WHERE product_id IN (?, ?) ORDER BY id DESC LIMIT 1');
        $articleStmt->execute([(string)($item['content_id'] ?? ''), (string)($item['product_id'] ?? '')]);
        $articleRow = $articleStmt->fetch();
        if (is_array($articleRow)) {
            $desc = trim((string)($articleRow['description'] ?? ''));
        }
        if ($desc === '') {
            $articleTitle = trim((string)($item['title'] ?? ''));
            if ($articleTitle !== '') {
                $articleByTitleStmt = db()->prepare('SELECT description FROM articles WHERE title = ? ORDER BY id DESC LIMIT 1');
                $articleByTitleStmt->execute([$articleTitle]);
                $articleByTitleRow = $articleByTitleStmt->fetch();
                if (is_array($articleByTitleRow)) {
                    $desc = trim((string)($articleByTitleRow['description'] ?? ''));
                }
            }
        }
    } catch (Throwable) {
    }
}
if (item_is_invalid_description($desc)) {
    $desc = '';
}

$titleCandidates = [
    (string)($item['title'] ?? ''),
    trim((string)($raw['title'] ?? '')),
    trim((string)($raw['name'] ?? '')),
    trim((string)($raw['productTitle'] ?? '')),
    trim((string)($raw['iteminfo']['title'][0]['name'] ?? '')),
    trim((string)($raw['iteminfo']['title'][0]['value'] ?? '')),
];
$title = '商品詳細';
foreach ($titleCandidates as $candidateTitle) {
    $candidateTitle = trim((string)$candidateTitle);
    if ($candidateTitle === '') {
        continue;
    }
    if (item_is_invalid_title($candidateTitle)) {
        continue;
    }
    $title = $candidateTitle;
    break;
}
if ($title === '商品詳細') {
    $title = trim((string)($item['content_id'] ?? '')) !== '' ? (string)$item['content_id'] : '商品詳細';
}
$breadcrumbTitle = $title;
if (item_is_invalid_title($breadcrumbTitle)) {
    $breadcrumbTitle = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['title']);
}
if (item_is_invalid_title($breadcrumbTitle) || trim($breadcrumbTitle) === '') {
    $breadcrumbTitle = trim((string)($item['product_id'] ?? ''));
}
if (item_is_invalid_title($breadcrumbTitle) || trim($breadcrumbTitle) === '') {
    $breadcrumbTitle = trim((string)($item['content_id'] ?? ''));
}
if (trim($breadcrumbTitle) === '') {
    $breadcrumbTitle = '商品詳細';
}
$affiliateUrl = trim((string)($item['affiliate_url'] ?? ''));
$affiliateOutUrl = $affiliateUrl !== '' ? public_url('out.php') . '?' . http_build_query(['to' => $affiliateUrl]) : '';
$rawMakerName = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['maker', 'label']);
$rawSeriesName = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['series']);
$rawDirectorName = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['director']);
$deviceText = item_pick_raw_text($raw, ['supportedDevices', 'device', 'devices']);
$deliveryStartText = item_pick_raw_text($raw, ['date', 'deliveryStartDate', 'delivery_start_date']);
$labelName = item_pick_raw_text((array)($raw['iteminfo'] ?? []), ['label']);
$performerText = implode('、', array_values(array_filter(array_map(static fn($v) => trim((string)($v['name'] ?? '')), $actresses), static fn($v) => $v !== '')));
$genreText = implode('、', array_values(array_filter(array_map(static fn($v) => trim((string)($v['name'] ?? '')), $genres), static fn($v) => $v !== '')));
$performerLinks = [];
foreach ($actresses as $actressRow) {
    $actressName = trim((string)($actressRow['name'] ?? ''));
    if ($actressName === '') {
        continue;
    }
    $performerLinks[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $actressName])) . '">' . e($actressName) . '</a>';
}
$genreLinks = [];
foreach ($genres as $genreRow) {
    $genreName = trim((string)($genreRow['name'] ?? ''));
    if ($genreName === '') {
        continue;
    }
    $genreLinks[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $genreName])) . '">' . e($genreName) . '</a>';
}
$seriesLinks = [];
foreach ($seriesList as $seriesRow) {
    $seriesName = trim((string)($seriesRow['name'] ?? ''));
    if ($seriesName === '') {
        continue;
    }
    $seriesLinks[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $seriesName])) . '">' . e($seriesName) . '</a>';
}
$makerLinks = [];
foreach ($makers as $makerRow) {
    $makerName = trim((string)($makerRow['name'] ?? ''));
    if ($makerName === '') {
        continue;
    }
    $makerLinks[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $makerName])) . '">' . e($makerName) . '</a>';
}
$authorLinks = [];
foreach ($authors as $authorRow) {
    $authorName = trim((string)($authorRow['name'] ?? ''));
    if ($authorName === '') {
        continue;
    }
    $authorLinks[] = '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $authorName])) . '">' . e($authorName) . '</a>';
}
$tagText = item_pick_raw_text($raw, ['tag', 'tags']);
if ($tagText === '') {
    $tagValues = [];
    item_collect_named_values($raw['iteminfo']['genre'] ?? [], $tagValues);
    $tagValues = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $tagValues), static fn($v) => $v !== '')));
    if ($tagValues !== []) {
        $tagText = implode(' ', array_map(static fn($v) => str_starts_with($v, '#') ? $v : ('#' . $v), $tagValues));
    }
}
if ($deviceText === '') {
    $pcFlag = (int)($raw['sampleMovieURL']['pc_flag'] ?? $item['sample_movie_pc_flag'] ?? 0);
    $spFlag = (int)($raw['sampleMovieURL']['sp_flag'] ?? $item['sample_movie_sp_flag'] ?? 0);
    if ($pcFlag === 1 && $spFlag === 1) {
        $deviceText = 'PC / スマホ';
    } elseif ($pcFlag === 1) {
        $deviceText = 'PC';
    } elseif ($spFlag === 1) {
        $deviceText = 'スマホ';
    }
}
if ($tagText === '') {
    $tagText = '';
}
$tagLinks = $tagText !== '' ? item_tag_links_from_text($tagText) : [];
$releaseDateDisplay = (string)format_date((string)($item['release_date'] ?? ''));
if ($releaseDateDisplay === '') {
    $releaseDateDisplay = (string)format_date((string)($raw['date'] ?? ''));
}
$deliveryStartDisplay = (string)format_date((string)$deliveryStartText);
if ($deliveryStartDisplay === '') {
    $deliveryStartDisplay = trim((string)$deliveryStartText);
}
$volumeDisplay = (string)($item['volume'] ?? '');
if (trim($volumeDisplay) === '') {
    $volumeDisplay = trim((string)($raw['volume'] ?? ''));
}
if ($volumeDisplay !== '' && !str_contains($volumeDisplay, '分')) {
    if (preg_match('/^\d+$/', $volumeDisplay) === 1) {
        $volumeDisplay .= '分';
    }
}
$contentIdDisplay = trim((string)($item['content_id'] ?? ''));
if ($contentIdDisplay === '') {
    $contentIdDisplay = trim((string)($raw['content_id'] ?? ''));
}
$productIdDisplay = trim((string)($raw['maker_product'] ?? ''));
if ($productIdDisplay === '') {
    $productIdDisplay = trim((string)($item['product_id'] ?? ''));
}
if ($productIdDisplay === '') {
    $productIdDisplay = trim((string)($raw['product_id'] ?? ''));
}
$packageImage = pcf_item_image(is_array($item) ? $item : []);
if (str_starts_with($packageImage, 'data:image/svg+xml') || pcf_is_self_hosted_duga_image_url($packageImage)) {
    $packageImage = '';
}
$packageImageFallbacks = array_values(array_filter(pcf_item_image_candidates(is_array($item) ? $item : []), static fn($url): bool => trim((string)$url) !== '' && trim((string)$url) !== $packageImage));
$packageImageFallbackAttr = $packageImageFallbacks !== [] ? ' data-image-fallbacks="' . e((string)json_encode($packageImageFallbacks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"' : '';

$actressNames = array_values(array_filter(array_map(static fn($row) => trim((string)($row['name'] ?? '')), $actresses), static fn($name) => $name !== ''));
$genreNames = array_values(array_filter(array_map(static fn($row) => trim((string)($row['name'] ?? '')), $genres), static fn($name) => $name !== ''));
$pageDescriptionSource = $desc !== '' ? $desc : $title . 'のDUGA通販ページ。' . ($actressNames !== [] ? implode('、', array_slice($actressNames, 0, 3)) . '出演、' : '') . ($genreNames !== [] ? implode('、', array_slice($genreNames, 0, 3)) . '作品です。' : '作品です。');
$pageDescription = mb_strimwidth($pageDescriptionSource, 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('item.php') . '?id=' . rawurlencode((string)(int)$item['id']);
$ogImage = $packageImage;
if ($ogImage !== '' && str_starts_with($ogImage, '//')) {
    $ogImage = 'https:' . $ogImage;
}
$ogType = 'product';
$productJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $title,
    'description' => $pageDescription,
    'offers' => [
        '@type' => 'Offer',
        'url' => $affiliateUrl !== '' ? $affiliateUrl : $canonicalUrl,
        'priceCurrency' => 'JPY',
        'availability' => 'https://schema.org/InStock',
    ],
];
if ($ogImage !== '') {
    $productJsonLd['image'] = $ogImage;
}
if ($actressNames !== []) {
    $productJsonLd['actor'] = array_map(static fn($name) => ['@type' => 'Person', 'name' => $name], $actressNames);
}
$jsonLd = (string)json_encode($productJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$accessRankingPeriod = trim((string)get('rank_period', 'daily'));
$accessRankingTabs = [
    'daily' => ['label' => '本日'],
    'weekly' => ['label' => '週間'],
    'monthly' => ['label' => '月間'],
    'yearly' => ['label' => '年間'],
];
if (!isset($accessRankingTabs[$accessRankingPeriod])) {
    $accessRankingPeriod = 'daily';
}
$accessRankingRows = pcf_public_weighted_ranking('items', $accessRankingPeriod);

require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => '商品一覧', 'url' => public_url('items.php')],
    ['label' => $breadcrumbTitle],
]); ?>

<article>
  <h1 class="pcf-hero__title pcf-item-title"><?= e($breadcrumbTitle) ?></h1>

  <?php if ($sampleMovieUrl !== '' || $sampleImagesSmallLargeMap !== []): ?>
    <div class="pcf-item-samples" style="display:flex; gap:8px; align-items:flex-start; flex-wrap:nowrap;">
      <?php if ($sampleMovieUrl !== ''): ?>
      <div class="sample-movie-modal__frame-wrap pcf-item-sample-movie" style="width: min(720px, calc(100% - 400px)); max-width: 100%; aspect-ratio: 720 / 480;">
        <?php if ($sampleMovieIsVideo): ?>
        <video class="sample-movie-modal__frame" src="<?= e($sampleMovieUrl) ?>"<?= $sampleMoviePoster !== '' ? ' poster="' . e($sampleMoviePoster) . '"' : '' ?> controls playsinline preload="metadata" width="720" height="480" style="display:block;width:100%;height:100%;background:#000;"></video>
        <?php else: ?>
        <iframe class="sample-movie-modal__frame" src="<?= e($sampleMovieUrl) ?>" allow="autoplay; fullscreen" referrerpolicy="no-referrer" scrolling="no" width="720" height="480"></iframe>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="sample-movie-modal__frame-wrap pcf-item-sample-movie" style="width: min(720px, calc(100% - 400px)); max-width: 100%; aspect-ratio: 720 / 480; background:#d9d9d9; color:#666; display:flex; align-items:center; justify-content:center; font-weight:700;">no movie</div>
      <?php endif; ?>
      <?php if ($sampleImagesSmallLargeMap !== []): ?>
      <div class="pcf-item-sample-thumbs" style="width:392px; max-width:100%; height:480px; overflow:hidden;"><div style="display:grid; grid-template-rows:repeat(6, 72px); grid-auto-flow:column; grid-auto-columns:92px; gap:8px; align-content:start;">
        <?php foreach ($sampleImagesSmallLargeMap as $i => $imagePair): ?>
          <a href="<?= e((string)$imagePair['large']) ?>" class="pcf-image-viewer-trigger" data-image-index="<?= e((string)$i) ?>" style="display:block;">
            <img src="<?= e((string)$imagePair['small']) ?>" alt="サンプル画像 <?= e((string)($i + 1)) ?>" loading="lazy" style="display:block; width:100%; height:72px; object-fit:contain;">
          </a>
        <?php endforeach; ?>
      </div></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($affiliateUrl !== ''): ?>
    <p><a class="pcf-btn" style="display:block; text-align:center; border:2px solid #9aa0ab; font-weight:700; font-size:18px; padding:12px 14px;" href="<?= e($affiliateOutUrl) ?>" target="_blank" rel="noopener noreferrer">購入ボタン</a></p>
  <?php endif; ?>

  <section class="pcf-detail pcf-item-main">
    <div class="pcf-item-main__media" style="width:min(100%, 620px);">
      <?php if ($packageImage !== ''): ?>
      <a href="<?= e($packageImage) ?>" target="_blank" rel="noopener noreferrer">
        <img class="pcf-detail__package" data-package-image="1" src="<?= e($packageImage) ?>" alt="<?= e((string)($item['title'] ?? '')) ?>"<?= $packageImageFallbackAttr ?> style="display:block; width:100%; height:auto;">
      </a>
      <?php endif; ?>
      <?php if ($desc !== ''): ?><p><?= nl2br(e($desc)) ?></p><?php endif; ?>
    </div>

    <div class="pcf-item-main__info">
      <table style="width:100%; border-collapse:collapse; border:0; color:#000 !important; font-size:12px;">
        <tbody>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">対応デバイス</th><td style="padding:4px 0; border:0;"><?= e($deviceText !== '' ? $deviceText : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">配信開始日</th><td style="padding:4px 0; border:0;"><?= e($deliveryStartDisplay !== '' ? $deliveryStartDisplay : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">商品発売日</th><td style="padding:4px 0; border:0;"><?= e($releaseDateDisplay !== '' ? $releaseDateDisplay : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">収録時間</th><td style="padding:4px 0; border:0;"><?= e($volumeDisplay !== '' ? $volumeDisplay : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">出演者</th><td style="padding:4px 0; border:0;"><?= $performerLinks !== [] ? implode('、', $performerLinks) : e($performerText !== '' ? $performerText : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">監督</th><td style="padding:4px 0; border:0;"><?= $rawDirectorName !== '' ? '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $rawDirectorName])) . '">' . e($rawDirectorName) . '</a>' : '―' ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">作者</th><td style="padding:4px 0; border:0;"><?= $authorLinks !== [] ? implode('、', $authorLinks) : '―' ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">シリーズ</th><td style="padding:4px 0; border:0;"><?= $seriesLinks !== [] ? implode('、', $seriesLinks) : e($rawSeriesName !== '' ? $rawSeriesName : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">メーカー</th><td style="padding:4px 0; border:0;"><?= $makerLinks !== [] ? implode('、', $makerLinks) : e($rawMakerName !== '' ? $rawMakerName : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">レーベル</th><td style="padding:4px 0; border:0;"><?= $labelName !== '' ? '<a href="' . e(public_url('search.php') . '?' . http_build_query(['q' => $labelName])) . '">' . e($labelName) . '</a>' : '―' ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">ジャンル</th><td style="padding:4px 0; border:0;"><?= $genreLinks !== [] ? implode('、', $genreLinks) : e($genreText !== '' ? $genreText : '―') ?></td></tr>
          <tr><th style="text-align:left; font-weight:700; padding:4px 8px 4px 0; white-space:nowrap; border:0;">関連タグ</th><td style="padding:4px 0; border:0;"><?= $tagLinks !== [] ? implode(' ', $tagLinks) : e($tagText !== '' ? $tagText : '―') ?></td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($affiliateUrl !== ''): ?>
    <p><a class="pcf-btn" style="display:block; text-align:center; border:2px solid #9aa0ab; font-weight:700; font-size:18px; padding:12px 14px;" href="<?= e($affiliateOutUrl) ?>" target="_blank" rel="noopener noreferrer">購入ボタン</a></p>
  <?php endif; ?>

  <h2 class="pcf-section-title">関連作品</h2>
  <?php if ($relatedItems !== []): ?>
    <section class="pcf-related-grid pcf-item-related-grid">
      <?php foreach ($relatedItems as $related): pcf_render_item_card(is_array($related) ? $related : []); endforeach; ?>
    </section>
  <?php else: ?>
    <?php pcf_render_empty('関連作品はありません。'); ?>
  <?php endif; ?>


  <section id="access-ranking" class="block" style="margin-top:24px;">
    <h2 class="section-title">人気の作品ランキング！</h2>
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
    <?php foreach ($accessRankingTabs as $tabKey => $tabConfig): ?>
      <?php
      $tabQuery = ['rank_period' => (string)$tabKey];
      if ($id > 0) {
          $tabQuery['id'] = (string)$id;
      }
      if ($contentId !== '') {
          $tabQuery['content_id'] = $contentId;
      }
      if ($cid !== '') {
          $tabQuery['cid'] = $cid;
      }
      $tabUrl = public_url(basename(__FILE__)) . '?' . http_build_query($tabQuery) . '#access-ranking';
      ?>
      <?php $tabStyle = $accessRankingPeriod === $tabKey ? 'display:inline-block; padding:6px 12px; border:1px solid #0b5ed7; border-radius:6px; background:#0b5ed7; color:#fff; font-weight:700; text-decoration:none;' : 'display:inline-block; padding:6px 12px; border:1px solid #0b5ed7; border-radius:6px; background:#fff; color:#0b5ed7; font-weight:700; text-decoration:none;'; ?>
      <a href="<?= e($tabUrl) ?>" style="<?= e($tabStyle) ?>"><?= e((string)$tabConfig['label']) ?></a>
    <?php endforeach; ?>
  </div>
    <?php if ($accessRankingRows !== []): ?>
      <div style="max-height:800px; overflow-y:auto; border:1px solid #ddd;">
        <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
          <thead>
            <tr>
              <th style="width:80px; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">順位</th>
              <th style="width:auto; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">作品タイトル</th>
              <th style="width:120px; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">ランキング点</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accessRankingRows as $index => $rankingRow): ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)($index + 1)) ?></td>
                <td style="padding:8px; border-bottom:1px solid #eee; text-align:left;">
                <?php
                $rankingItemUrl = public_url('item.php') . '?id=' . rawurlencode((string)($rankingRow['id'] ?? ''));
                ?>
                <a href="<?= e($rankingItemUrl) ?>"><?= e((string)($rankingRow['title'] ?? '')) ?></a>
              </td>
                <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)((int)($rankingRow['access_count'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <?php pcf_render_empty('人気の作品ランキング！のデータがありません。'); ?>
    <?php endif; ?>
  </section>

</article>

<div id="pcf-image-viewer-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.92); z-index:1200;">
  <button type="button" data-image-close="1" style="position:absolute; top:12px; right:16px; color:#fff; background:transparent; border:0; font-size:40px; line-height:1; cursor:pointer;">×</button>
  <div style="max-width:1200px; margin:26px auto 0; padding:0 18px;">
    <div style="display:flex; align-items:center; justify-content:center; min-height:66vh;">
      <img id="pcf-image-viewer-main" src="" alt="サンプル画像" style="max-width:100%; max-height:66vh; object-fit:contain;">
    </div>
    <div id="pcf-image-viewer-thumbs" style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:12px;"></div>
  </div>
</div>

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
  const openMovie = (url, title) => {
    if (!modal || !frame || !titleNode || !url) return;
    titleNode.textContent = title || 'サンプル動画';
    frame.src = url;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };

  const imageViewer = document.getElementById('pcf-image-viewer-modal');
  const imageViewerMain = document.getElementById('pcf-image-viewer-main');
  const imageViewerThumbs = document.getElementById('pcf-image-viewer-thumbs');
  const imageList = <?= json_encode(array_map(static fn($pair) => ['small' => (string)($pair['small'] ?? ''), 'large' => (string)($pair['large'] ?? '')], $sampleImagesSmallLargeMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const showImage = (index) => {
    if (!imageViewerMain || !imageViewerThumbs || !Array.isArray(imageList) || imageList.length === 0) return;
    const idx = Math.max(0, Math.min(index, imageList.length - 1));
    imageViewerMain.src = imageList[idx].large || imageList[idx].small || '';
    imageViewerThumbs.innerHTML = '';
    imageList.forEach((item, i) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.style.border = i === idx ? '2px solid #ff6b6b' : '1px solid #777';
      btn.style.padding = '0';
      btn.style.background = 'transparent';
      btn.style.cursor = 'pointer';
      const img = document.createElement('img');
      img.src = item.small || item.large || '';
      img.alt = 'サムネイル ' + (i + 1);
      img.style.width = '76px';
      img.style.height = '50px';
      img.style.objectFit = 'cover';
      img.style.display = 'block';
      btn.appendChild(img);
      btn.addEventListener('click', () => showImage(i));
      imageViewerThumbs.appendChild(btn);
    });
  };
  const openImageViewer = (index) => {
    if (!imageViewer) return;
    showImage(index);
    imageViewer.style.display = 'block';
  };
  const closeImageViewer = () => {
    if (!imageViewer || !imageViewerMain) return;
    imageViewer.style.display = 'none';
    imageViewerMain.src = '';
  };

  const packageImage = document.querySelector('[data-package-image="1"]');
  const adjustPackageImage = () => {
    if (!packageImage || !packageImage.naturalWidth || !packageImage.naturalHeight) return;
    if (packageImage.naturalHeight <= packageImage.naturalWidth) return;
    packageImage.style.width = 'auto';
    packageImage.style.maxWidth = '100%';
    packageImage.style.height = '400px';
    packageImage.style.maxHeight = '400px';
    packageImage.style.objectFit = 'contain';
    packageImage.style.objectPosition = 'center top';
    packageImage.style.marginLeft = 'auto';
    packageImage.style.marginRight = 'auto';
  };
  if (packageImage) {
    if (packageImage.complete) {
      adjustPackageImage();
    } else {
      packageImage.addEventListener('load', adjustPackageImage, { once: true });
    }
  }

  const closeMovie = () => {
    if (!modal || !frame || !titleNode) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
  };
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.sample-movie-trigger');
    if (trigger) { event.preventDefault(); openMovie(trigger.dataset.movieUrl || '', trigger.dataset.movieTitle || ''); return; }
    const imageTrigger = event.target.closest('.pcf-image-viewer-trigger');
    if (imageTrigger) { event.preventDefault(); openImageViewer(parseInt(imageTrigger.dataset.imageIndex || '0', 10)); return; }
    if (event.target.closest('[data-image-close="1"]')) { event.preventDefault(); closeImageViewer(); return; }
    if (event.target.closest('[data-movie-close="1"]')) { event.preventDefault(); closeMovie(); }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) closeMovie();
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
