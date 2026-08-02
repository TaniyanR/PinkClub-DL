<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

if (!function_exists('pcf_placeholder_data_uri')) {
    function pcf_placeholder_data_uri(string $label = 'No Image'): string
    {
        $safeLabel = e($label);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="900" viewBox="0 0 640 900"><rect width="100%" height="100%" fill="#161821"/><rect x="24" y="24" width="592" height="852" rx="20" fill="#202534" stroke="#2e3750"/><text x="50%" y="50%" fill="#9ea8c7" font-size="34" text-anchor="middle" font-family="Arial, sans-serif">' . $safeLabel . '</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }
}

if (!function_exists('pcf_parse_image_urls')) {
    function pcf_parse_image_urls(?string $value): array
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
}

if (!function_exists('pcf_maybe_decode_json_value')) {
    function pcf_maybe_decode_json_value(mixed $value): mixed
    {
        static $decodedJsonCache = [];

        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }

        $cacheKey = hash('sha256', $trimmed);
        if (array_key_exists($cacheKey, $decodedJsonCache)) {
            return $decodedJsonCache[$cacheKey];
        }

        $decoded = json_decode($trimmed, true);
        if (is_string($decoded)) {
            $decodedAgain = json_decode($decoded, true);
            $result = is_array($decodedAgain) ? $decodedAgain : $decoded;
        } else {
            $result = is_array($decoded) ? $decoded : $value;
        }

        if (count($decodedJsonCache) >= 200) {
            $decodedJsonCache = [];
        }
        $decodedJsonCache[$cacheKey] = $result;
        return $result;
    }
}

if (!function_exists('pcf_looks_like_image_url')) {
    function pcf_looks_like_image_url(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        return (bool)preg_match('#^(?:https?:)?//#i', $v) && (bool)preg_match('#(?:\.(?:jpe?g|png|gif|webp)(?:[?&].*)?$|/mono/|/digital/|pics\.duga\.)#i', $v);
    }
}


if (!function_exists('pcf_is_self_hosted_duga_image_url')) {
    function pcf_is_self_hosted_duga_image_url(string $url): bool
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
}

if (!function_exists('pcf_first_image_from_mixed')) {
    function pcf_first_image_from_mixed(mixed $value): string
    {
        $value = pcf_maybe_decode_json_value($value);
        if (is_string($value)) {
            foreach (pcf_parse_image_urls($value) as $candidate) {
                $v = trim((string)$candidate);
                if (pcf_looks_like_image_url($v)) {
                    return $v;
                }
            }
            return '';
        }
        if (!is_array($value)) {
            return '';
        }

        foreach (['large', 'small', 'list', 'image', 'url', 'src', 'value'] as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = pcf_first_image_from_mixed($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($value as $child) {
            $candidate = pcf_first_image_from_mixed($child);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('pcf_first_text_from_mixed')) {
    function pcf_first_text_from_mixed(mixed $value): string
    {
        $value = pcf_maybe_decode_json_value($value);
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }
        if (!is_array($value)) {
            return '';
        }

        foreach (['name', 'value', 'title', 'productTitle'] as $key) {
            if (isset($value[$key])) {
                $candidate = pcf_first_text_from_mixed($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($value as $child) {
            $candidate = pcf_first_text_from_mixed($child);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('pcf_first_text_by_keys_from_mixed')) {
    function pcf_first_text_by_keys_from_mixed(mixed $value, array $keys): string
    {
        $value = pcf_maybe_decode_json_value($value);
        if (!is_array($value)) {
            return '';
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = pcf_first_text_from_mixed($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($value as $child) {
            $candidate = pcf_first_text_by_keys_from_mixed($child, $keys);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('pcf_duga_candidate_content_ids')) {
    function pcf_duga_candidate_content_ids(array $item): array
    {
        $values = [
            (string)($item['content_id'] ?? ''),
            (string)($item['product_id'] ?? ''),
        ];

        $rawJson = (string)($item['raw_json'] ?? '');
        if ($rawJson !== '') {
            $raw = pcf_maybe_decode_json_value($rawJson);
            if (is_array($raw)) {
                foreach (['content_id', 'contentid', 'product_id', 'productid', 'maker_product', 'productCode'] as $key) {
                    $values[] = (string)($raw[$key] ?? '');
                }
                $values[] = pcf_first_text_by_keys_from_mixed($raw, ['content_id', 'contentid', 'product_id', 'productid', 'maker_product', 'productCode']);
            }
        }

        foreach ([$item['affiliate_url'] ?? '', $item['url'] ?? ''] as $url) {
            $path = parse_url((string)$url, PHP_URL_PATH);
            if (is_string($path) && preg_match('#/ppv/([a-z0-9]+[-_][0-9]+)/#i', $path, $matches) === 1) {
                $values[] = $matches[1];
            }
        }

        foreach ([(string)($item['image_large'] ?? ''), (string)($item['image_small'] ?? ''), (string)($item['image_list'] ?? '')] as $value) {
            foreach (pcf_parse_image_urls($value) as $url) {
                $path = parse_url((string)$url, PHP_URL_PATH);
                if (is_string($path) && preg_match('#/unsecure/([a-z0-9]+)/([0-9]+)/noauth/#i', $path, $matches) === 1) {
                    $values[] = $matches[1] . '-' . $matches[2];
                }
            }
        }

        $ids = [];
        foreach ($values as $value) {
            $normalized = strtolower(trim((string)$value));
            $normalized = str_replace('_', '-', $normalized);
            if (preg_match('/^([a-z0-9]+)-([0-9]+)$/i', $normalized, $matches) !== 1) {
                continue;
            }
            $ids[] = $matches[1] . '-' . $matches[2];
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('pcf_duga_package_image_candidates')) {
    function pcf_duga_package_image_candidates(array $item): array
    {
        $candidates = [];
        foreach (pcf_duga_candidate_content_ids($item) as $contentId) {
            if (preg_match('/^([a-z0-9]+)-([0-9]+)$/i', $contentId, $matches) !== 1) {
                continue;
            }
            $maker = strtolower($matches[1]);
            $number = $matches[2];
            $base = 'https://pic.duga.jp/unsecure/' . rawurlencode($maker) . '/' . rawurlencode($number) . '/noauth/';
            foreach ([
                'package.jpg',
                'jacket.jpg',
                'jacket_l.jpg',
                'jacket_m.jpg',
                'cover.jpg',
                'poster.jpg',
                'main.jpg',
                'pac.jpg',
                'str.jpg',
            ] as $file) {
                $candidates[] = $base . $file;
            }
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('pcf_item_image_candidates')) {
    function pcf_item_image_candidates(array $item): array
    {
        $candidates = [
            (string)($item['full_package_url'] ?? ''),
            (string)($item['package_image_url'] ?? ''),
            (string)($item['package_image_large'] ?? ''),
            (string)($item['package_image_small'] ?? ''),
        ];

        $rawJson = (string)($item['raw_json'] ?? '');
        if ($rawJson !== '') {
            $raw = pcf_maybe_decode_json_value($rawJson);
            if (is_array($raw)) {
                foreach (['jacketimage', 'packageimagelarge', 'packageimage', 'package', 'jacket', 'packageImage', 'poster', 'posterimage'] as $rawKey) {
                    $candidates[] = pcf_first_image_from_mixed($raw[$rawKey] ?? null);
                }
                $candidates[] = (string)($raw['packageImage']['large'] ?? '');
                $candidates[] = (string)($raw['packageImage']['small'] ?? '');
                $candidates[] = (string)($raw['imageURL']['large'] ?? '');
                $candidates[] = (string)($raw['imageURL']['small'] ?? '');
            }
        }

        $packageCandidates = pcf_duga_package_image_candidates($item);
        $sampleCandidates = [
            (string)($item['main_image_url'] ?? ''),
            (string)($item['image_url'] ?? ''),
            (string)($item['image_large'] ?? ''),
            pcf_first_image_from_mixed($item['image_list'] ?? ''),
            (string)($item['image_small'] ?? ''),
        ];

        return array_values(array_unique(array_filter(array_map(static function ($candidate): string {
            $value = trim((string)$candidate);
            return $value !== '' && !pcf_is_self_hosted_duga_image_url($value) ? $value : '';
        }, array_merge($candidates, $packageCandidates, $sampleCandidates)))));
    }
}

if (!function_exists('pcf_item_image')) {
    function pcf_item_image(array $item): string
    {
        return (string)(pcf_item_image_candidates($item)[0] ?? '');
    }
}

if (!function_exists('pcf_item_title')) {
    function pcf_item_title(array $item): string
    {
        $raw = [];
        $rawJson = (string)($item['raw_json'] ?? '');
        if ($rawJson !== '') {
            $decoded = pcf_maybe_decode_json_value($rawJson);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $candidates = [
            pcf_first_text_from_mixed($item['title'] ?? ''),
            pcf_first_text_from_mixed($raw['title'] ?? ''),
            pcf_first_text_from_mixed($raw['name'] ?? ''),
            pcf_first_text_from_mixed($raw['productTitle'] ?? ''),
            pcf_first_text_from_mixed($raw['iteminfo']['title'] ?? ''),
            pcf_first_text_by_keys_from_mixed($raw, ['title', 'productTitle']),
        ];

        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value !== '' && $value !== 'タイトル未設定') {
                return $value;
            }
        }
        return 'タイトル未設定';
    }
}

if (!function_exists('pcf_normalize_movie_url')) {
    function pcf_normalize_movie_url(string $url): string
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
}

if (!function_exists('pcf_collect_movie_urls_from_value')) {
    function pcf_collect_movie_urls_from_value(mixed $value, array &$urls): void
    {
        if (is_string($value)) {
            $candidate = pcf_normalize_movie_url($value);
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            pcf_collect_movie_urls_from_value($child, $urls);
        }
    }
}

if (!function_exists('pcf_pick_sample_movie_urls_from_raw')) {
    function pcf_pick_sample_movie_urls_from_raw(array $raw): array
    {
        $urls = [];
        foreach (['sampleMovieURL', 'sample_movie_url', 'sampleMovieUrl', 'samplemovie', 'sampleMovie', 'sample_movie'] as $movieKeyName) {
            $rawMovie = $raw[$movieKeyName] ?? null;

            if (is_string($rawMovie)) {
                $candidate = pcf_normalize_movie_url($rawMovie);
                if ($candidate !== '') {
                    $urls[] = $candidate;
                }
            }

            if (is_array($rawMovie)) {
                foreach (['size_720_480', 'size_644_414', 'size_560_360', 'size_476_306'] as $movieKey) {
                    $candidate = pcf_normalize_movie_url((string)($rawMovie[$movieKey] ?? ''));
                    if ($candidate !== '') {
                        $urls[] = $candidate;
                    }
                }

                pcf_collect_movie_urls_from_value($rawMovie, $urls);
            }
        }

        return array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)$u), $urls))));
    }
}

if (!function_exists('pcf_collect_sample_image_urls_from_value')) {
    function pcf_collect_sample_image_urls_from_value(mixed $value, array &$images): void
    {
        $value = pcf_maybe_decode_json_value($value);
        if (is_string($value)) {
            foreach (pcf_parse_image_urls($value) as $candidate) {
                $url = trim((string)$candidate);
                if ($url !== '' && !pcf_is_self_hosted_duga_image_url($url)) {
                    $images[] = $url;
                }
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            pcf_collect_sample_image_urls_from_value($child, $images);
        }
    }
}

if (!function_exists('pcf_pick_sample_image_urls_from_raw')) {
    function pcf_pick_sample_image_urls_from_raw(array $raw): array
    {
        $images = [];
        $sampleImageURL = $raw['sampleImageURL'] ?? null;
        if (is_array($sampleImageURL)) {
            foreach (['sample_l', 'sample_s'] as $sampleKey) {
                $sampleImages = [];
                pcf_collect_sample_image_urls_from_value($sampleImageURL[$sampleKey]['image'] ?? null, $sampleImages);
                if ($sampleImages !== []) {
                    $images = array_merge($images, $sampleImages);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)$u), $images))));
    }
}

if (!function_exists('pcf_render_sample_movie_modal')) {
    function pcf_render_sample_movie_modal(): void
    {
        ?>
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
      const card = trigger.closest('.pcf-dm-card');
      const fallbackTitle = card ? (card.querySelector('.pcf-dm-card__title')?.textContent || '') : '';
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
<?php
    }
}

if (!function_exists('pcf_render_hero')) {
    function pcf_render_hero(string $title, string $subtitle = ''): void
    {
        echo '<section class="pcf-hero">';
        echo '<h1 class="pcf-hero__title">' . e($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="pcf-hero__subtitle">' . e($subtitle) . '</p>';
        }
        echo '</section>';
    }
}

if (!function_exists('pcf_is_noise_name')) {
    function pcf_is_noise_name(string $name): bool
    {
        $v = mb_strtolower(trim($name), 'UTF-8');
        if ($v === '') {
            return true;
        }

        if (str_contains($v, 'http://') || str_contains($v, 'https://') || str_contains($v, 'www.')) {
            return true;
        }

        if (preg_match('/\.(com|net|jp|org|info|biz)(?:$|[^a-z])/i', $v)) {
            return true;
        }

        if (str_contains($v, '/')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('pcf_pick_oldest_item')) {
    function pcf_pick_oldest_item(array $items): ?array
    {
        $oldest = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($oldest === null) {
                $oldest = $item;
                continue;
            }

            $itemDate = trim((string)($item['release_date'] ?? ''));
            $oldestDate = trim((string)($oldest['release_date'] ?? ''));
            if ($itemDate !== '' && ($oldestDate === '' || strcmp($itemDate, $oldestDate) < 0)) {
                $oldest = $item;
                continue;
            }
            if ($itemDate === $oldestDate && (int)($item['id'] ?? 0) < (int)($oldest['id'] ?? 0)) {
                $oldest = $item;
            }
        }

        return $oldest;
    }
}

if (!function_exists('pcf_render_breadcrumbs')) {
    function pcf_render_breadcrumbs(array $items): void
    {
        if ($items === []) {
            return;
        }

        $jsonItems = [];
        echo '<nav class="pcf-breadcrumb" aria-label="パンくず">';
        foreach ($items as $index => $item) {
            $label = (string)($item['label'] ?? '');
            $url = trim((string)($item['url'] ?? ''));
            $isLast = $index === array_key_last($items);
            echo '<span class="pcf-breadcrumb__item">';
            if (!$isLast && $url !== '') {
                echo '<a href="' . e($url) . '">' . e($label) . '</a>';
            } else {
                echo '<span>' . e($label) . '</span>';
            }
            echo '</span>';
            $jsonItems[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $label,
                'item' => $url !== '' ? $url : public_url(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'))),
            ];
        }
        echo '</nav>';
        echo '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $jsonItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
}

if (!function_exists('pcf_render_item_card')) {
    function pcf_render_item_card(array $item, int $width = 180, bool $preferFullPackageImage = false): void
    {
        $title = pcf_item_title($item);
        $contentId = trim((string)($item['content_id'] ?? ''));
        if ($contentId === '') {
            $raw = [];
            $rawJson = (string)($item['raw_json'] ?? '');
            if ($rawJson !== '') {
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                }
            }
            $contentId = trim((string)($raw['content_id'] ?? ''));
        }
        $itemId = (int)($item['id'] ?? 0);
        $itemUrl = $itemId > 0 ? public_url('item.php?id=' . $itemId) : public_url('item.php?cid=' . rawurlencode($contentId));
        $imageUrl = trim(pcf_item_image($item));
        $imageFallbacks = array_values(array_filter(pcf_item_image_candidates($item), static fn($url): bool => trim((string)$url) !== '' && trim((string)$url) !== $imageUrl));
        $imageFallbackAttr = $imageFallbacks !== [] ? ' data-image-fallbacks="' . e((string)json_encode($imageFallbacks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"' : '';
        if ($preferFullPackageImage) {
            foreach (pcf_item_image_candidates($item) as $imageCandidate) {
                $fullPackageImage = trim($imageCandidate);
                if ($fullPackageImage !== '' && !pcf_is_self_hosted_duga_image_url($fullPackageImage)) {
                    $imageUrl = $fullPackageImage;
                    break;
                }
            }
        }
        $sampleMovieUrl = '';
        $raw = [];
        $rawJson = (string)($item['raw_json'] ?? '');
        if ($rawJson !== '') {
            $decoded = pcf_maybe_decode_json_value($rawJson);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        foreach (['sample_movie_url_720', 'sample_movie_url_644', 'sample_movie_url_560', 'sample_movie_url_476'] as $movieColumn) {
            $candidate = pcf_normalize_movie_url((string)($item[$movieColumn] ?? ''));
            if ($candidate !== '') {
                $sampleMovieUrl = $candidate;
                break;
            }
        }
        if ($sampleMovieUrl === '') {
            $movieUrls = pcf_pick_sample_movie_urls_from_raw($raw);
            $sampleMovieUrl = (string)($movieUrls[0] ?? '');
        }
        $affiliateUrl = trim((string)($item['affiliate_url'] ?? $item['url'] ?? ''));
        $sampleFallbackUrl = $affiliateUrl !== '' ? public_url('out.php') . '?' . http_build_query(['to' => $affiliateUrl]) : '';

        echo '<article class="pcf-dm-card">';
        echo '<a class="pcf-dm-card__image-link" href="' . e($itemUrl) . '">';
        if ($imageUrl !== '') {
            echo '<img class="pcf-dm-card__image" src="' . e($imageUrl) . '" alt="' . e($title) . '" loading="lazy"' . $imageFallbackAttr . '>';
        } else {
            echo '<div class="pcf-dm-card__no-image">No Image</div>';
        }
        echo '</a>';
        echo '<h3 class="pcf-dm-card__title"><a href="' . e($itemUrl) . '">' . e($title) . '</a></h3>';
        echo '<div class="pcf-dm-card__actions">';
        $releaseDateRaw = trim((string)($item['release_date'] ?? ''));
        $releaseDateLabel = $releaseDateRaw !== '' ? '発売日：' . e(format_date($releaseDateRaw)) : '発売日';
        echo '<span style="display:block;width:100%;padding:12px 10px;text-align:center;color:#000;background:transparent;border:1px solid #000;border-radius:4px;font-size:14px;font-weight:700;box-sizing:border-box;">' . $releaseDateLabel . '</span>';
        if ($sampleMovieUrl !== '') {
            echo '<button type="button" class="pcf-dm-card__button sample-movie-trigger" data-movie-url="' . e($sampleMovieUrl) . '" data-movie-title="' . e($title) . '">サンプル動画</button>';
        } elseif ($sampleFallbackUrl !== '') {
            echo '<a class="pcf-dm-card__button" href="' . e($sampleFallbackUrl) . '" target="_blank" rel="noopener noreferrer">サンプル動画</a>';
        } else {
            echo '<span class="pcf-dm-card__button is-disabled">サンプル動画</span>';
        }
        echo '</div>';
        echo '</article>';
    }
}

if (!function_exists('pcf_render_taxonomy_card')) {
    function pcf_render_taxonomy_card(string $name, string $url, mixed $count = null): void
    {
        $title = trim($name);
        if ($title === '') {
            $title = '名称未設定';
        }
        if (pcf_is_noise_name($title)) {
            return;
        }

        echo '<article class="pcf-card pcf-list-card pcf-taxonomy-card">';
        echo '<h3 class="pcf-list-card__title pcf-taxonomy-card__title">' . e($title) . '</h3>';
        if ($count !== null && (string)$count !== '' && (int)$count > 0) {
            echo '<div class="pcf-list-card__meta">作品数: ' . e((string)$count) . '</div>';
        }
        echo '<p><a class="pcf-btn" href="' . e($url) . '">詳細を見る</a></p>';
        echo '</article>';
    }
}

if (!function_exists('pcf_render_empty')) {
    function pcf_render_empty(string $message): void
    {
        echo '<div class="pcf-empty">' . e($message) . '</div>';
    }
}

if (!function_exists('pcf_render_pagination')) {
    function pcf_render_pagination(array $pg, string $path, array $extraQuery = []): void
    {
        $page = (int)($pg['page'] ?? 1);
        $pages = (int)($pg['pages'] ?? 1);
        if ($pages <= 1) {
            return;
        }

        echo '<nav class="pcf-pagination" aria-label="ページネーション">';
        if ($page > 1) {
            $query = $extraQuery;
            $query['page'] = $page - 1;
            $url = $path . '?' . http_build_query($query);
            echo '<a class="pcf-pagination__link" href="' . e($url) . '">&laquo;</a>';
        }

        $displayPages = [1, $pages];
        for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
            $displayPages[] = $i;
        }
        $displayPages = array_values(array_unique($displayPages));
        sort($displayPages);

        $previousDisplayPage = 0;
        foreach ($displayPages as $i) {
            if ($previousDisplayPage > 0 && $i > $previousDisplayPage + 1) {
                echo '<span class="pcf-pagination__ellipsis">...</span>';
            }
            $query = $extraQuery;
            $query['page'] = $i;
            $url = $path . '?' . http_build_query($query);
            $class = 'pcf-pagination__link' . ($i === $page ? ' is-current' : '');
            echo '<a class="' . e($class) . '" href="' . e($url) . '">' . e((string)$i) . '</a>';
            $previousDisplayPage = $i;
        }

        if ($page < $pages) {
            $query = $extraQuery;
            $query['page'] = $page + 1;
            $url = $path . '?' . http_build_query($query);
            echo '<a class="pcf-pagination__link" href="' . e($url) . '">&raquo;</a>';
        }
        echo '</nav>';
    }
}
