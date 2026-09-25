<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

function pcf_site_feed_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function pcf_site_feed_item_url(array $item): string
{
    $id = (int)($item['id'] ?? 0);
    if ($id > 0) {
        return public_url('item.php?id=' . $id);
    }

    $contentId = trim((string)($item['content_id'] ?? ''));
    if ($contentId !== '') {
        return public_url('item.php?cid=' . rawurlencode($contentId));
    }

    return public_url('');
}

function pcf_site_feed_date(?string $value): string
{
    $timestamp = $value !== null && trim($value) !== '' ? strtotime($value) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    return date(DATE_RSS, $timestamp);
}

function pcf_site_feed_normalize_image_url(string $value): string
{
    $url = trim($value);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $url;
}

function pcf_site_feed_first_image_from_mixed(mixed $value): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return pcf_site_feed_first_image_from_mixed($decoded);
            }
        }
        $parts = preg_split('/[\r\n,|\s]+/', $trimmed) ?: [];
        foreach ($parts as $part) {
            $url = pcf_site_feed_normalize_image_url((string)$part);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }
    if (!is_array($value)) {
        return '';
    }

    foreach (['large', 'list', 'small', 'image', 'url', 'src', 'value'] as $key) {
        if (array_key_exists($key, $value)) {
            $url = pcf_site_feed_first_image_from_mixed($value[$key]);
            if ($url !== '') {
                return $url;
            }
        }
    }
    foreach ($value as $child) {
        $url = pcf_site_feed_first_image_from_mixed($child);
        if ($url !== '') {
            return $url;
        }
    }

    return '';
}

function pcf_site_feed_item_image(array $item): string
{
    foreach ([
        'full_package_url',
        'main_image_url',
        'image_url',
        'image_large',
        'package_image_large',
        'image_small',
        'package_image_small',
        'image_list',
    ] as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $url = pcf_site_feed_first_image_from_mixed($item[$key]);
        if ($url !== '') {
            return $url;
        }
    }

    $raw = $item['raw_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (['imageURL', 'packageImage', 'image', 'images'] as $key) {
                if (!array_key_exists($key, $decoded)) {
                    continue;
                }
                $url = pcf_site_feed_first_image_from_mixed($decoded[$key]);
                if ($url !== '') {
                    return $url;
                }
            }
        }
    }

    return '';
}

function pcf_site_feed_image_mime(string $url): string
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    return match (true) {
        str_ends_with($path, '.png') => 'image/png',
        str_ends_with($path, '.gif') => 'image/gif',
        str_ends_with($path, '.webp') => 'image/webp',
        default => 'image/jpeg',
    };
}

$siteTitle = trim(site_setting_get('site.title', site_setting_get('site.name', APP_NAME)));
if ($siteTitle === '') {
    $siteTitle = APP_NAME;
}

$siteUrl = trim(site_setting_get('site.url', app_url()));
if ($siteUrl === '') {
    $siteUrl = app_url();
}

$description = trim(site_setting_get('site.tagline', $siteTitle));
if ($description === '') {
    $description = $siteTitle;
}

try {
    $items = fetch_items('date_published_desc', 20, 0);
} catch (Throwable $e) {
    $items = [];
}

$lastBuildDate = date(DATE_RSS);
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }

    $updatedAt = trim((string)($item['updated_at'] ?? ''));
    if ($updatedAt !== '') {
        $lastBuildDate = pcf_site_feed_date($updatedAt);
        break;
    }

    $releaseDate = trim((string)($item['release_date'] ?? ''));
    if ($releaseDate !== '') {
        $lastBuildDate = pcf_site_feed_date($releaseDate);
        break;
    }
}

header('Content-Type: application/rss+xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title><?= pcf_site_feed_xml($siteTitle) ?></title>
    <link><?= pcf_site_feed_xml($siteUrl) ?></link>
    <description><?= pcf_site_feed_xml($description) ?></description>
    <language>ja</language>
    <lastBuildDate><?= pcf_site_feed_xml($lastBuildDate) ?></lastBuildDate>
<?php foreach ($items as $item): ?>
<?php
    if (!is_array($item)) {
        continue;
    }

    $itemTitle = trim((string)($item['title'] ?? ''));
    if ($itemTitle === '') {
        continue;
    }

    $itemLink = pcf_site_feed_item_url($item);
    $itemGuid = trim((string)($item['content_id'] ?? ''));
    if ($itemGuid === '') {
        $itemGuid = $itemLink;
    }

    $itemDate = trim((string)($item['release_date'] ?? ''));
    if ($itemDate === '') {
        $itemDate = trim((string)($item['updated_at'] ?? ''));
    }

    $itemDescription = trim((string)($item['category_name'] ?? ''));
    $itemImage = pcf_site_feed_item_image($item);
    $itemImageMime = $itemImage !== '' ? pcf_site_feed_image_mime($itemImage) : '';
?>
    <item>
      <title><?= pcf_site_feed_xml($itemTitle) ?></title>
      <link><?= pcf_site_feed_xml($itemLink) ?></link>
      <guid isPermaLink="false"><?= pcf_site_feed_xml($itemGuid) ?></guid>
      <pubDate><?= pcf_site_feed_xml(pcf_site_feed_date($itemDate)) ?></pubDate>
<?php if ($itemDescription !== ''): ?>
      <description><?= pcf_site_feed_xml($itemDescription) ?></description>
<?php endif; ?>
<?php if ($itemImage !== ''): ?>
      <media:content url="<?= pcf_site_feed_xml($itemImage) ?>" medium="image" type="<?= pcf_site_feed_xml($itemImageMime) ?>" />
      <media:thumbnail url="<?= pcf_site_feed_xml($itemImage) ?>" />
      <enclosure url="<?= pcf_site_feed_xml($itemImage) ?>" length="0" type="<?= pcf_site_feed_xml($itemImageMime) ?>" />
<?php endif; ?>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
