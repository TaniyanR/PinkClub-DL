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
    $contentId = trim((string)($item['content_id'] ?? ''));
    if ($contentId !== '') {
        return public_url('item.php?cid=' . rawurlencode($contentId));
    }

    $id = (int)($item['id'] ?? 0);
    if ($id > 0) {
        return public_url('item.php?id=' . $id);
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
<rss version="2.0">
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
?>
    <item>
      <title><?= pcf_site_feed_xml($itemTitle) ?></title>
      <link><?= pcf_site_feed_xml($itemLink) ?></link>
      <guid isPermaLink="false"><?= pcf_site_feed_xml($itemGuid) ?></guid>
      <pubDate><?= pcf_site_feed_xml(pcf_site_feed_date($itemDate)) ?></pubDate>
<?php if ($itemDescription !== ''): ?>
      <description><?= pcf_site_feed_xml($itemDescription) ?></description>
<?php endif; ?>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
