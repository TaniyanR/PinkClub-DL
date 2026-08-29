<?php
declare(strict_types=1);

require_once __DIR__ . '/site_article_feeds.php';

function site_article_feed_media_image_url(array $item): string
{
    $raw = site_article_feed_image($item);
    $url = trim($raw);
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || trim((string)($parts['host'] ?? '')) === '') {
        return '';
    }
    return $url;
}

function site_article_feed_media_mime(string $url): string
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return match (pathinfo($path, PATHINFO_EXTENSION)) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

function site_article_feed_media_item_url(array $item): string
{
    $id = (int)($item['id'] ?? 0);
    if ($id > 0) {
        return public_url('item.php?id=' . $id);
    }
    $contentId = trim((string)($item['content_id'] ?? ''));
    return $contentId !== '' ? public_url('item.php?cid=' . rawurlencode($contentId)) : public_url('');
}

function site_article_feed_media_cdata(string $value): string
{
    return str_replace(']]>', ']]]]><![CDATA[>', $value);
}

function site_article_feed_render_media(string $feedKey): void
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
    if ($siteTitle === '') $siteTitle = APP_NAME;

    $siteUrl = trim(site_setting_get('site.url', app_url()));
    $siteParts = $siteUrl !== '' ? parse_url($siteUrl) : false;
    if (
        filter_var($siteUrl, FILTER_VALIDATE_URL) === false
        || !is_array($siteParts)
        || !in_array(strtolower((string)($siteParts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string)($siteParts['host'] ?? '')) === ''
    ) {
        $siteUrl = app_url();
    }

    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title><?= site_article_feed_xml($siteTitle . ' - ' . (string)$config['title']) ?></title>
    <link><?= site_article_feed_xml($siteUrl) ?></link>
    <description><?= site_article_feed_xml((string)$config['description']) ?></description>
    <language>ja</language>
    <lastBuildDate><?= site_article_feed_xml(date(DATE_RSS)) ?></lastBuildDate>
<?php foreach ($items as $item): ?>
<?php
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') continue;
        $link = site_article_feed_media_item_url($item);
        $image = site_article_feed_media_image_url($item);
        $imageMime = $image !== '' ? site_article_feed_media_mime($image) : '';
        $publishedAt = trim((string)($item['feed_published_at'] ?? ''));
        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
        $description = $image !== ''
            ? '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"><img src="' . htmlspecialchars($image, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"></a>'
            : '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a>';
        $guidKey = trim((string)($item['content_id'] ?? ''));
        if ($guidKey === '') $guidKey = (string)((int)($item['id'] ?? 0));
?>
    <item>
      <title><?= site_article_feed_xml($title) ?></title>
      <link><?= site_article_feed_xml($link) ?></link>
      <guid isPermaLink="false"><?= site_article_feed_xml($feedKey . ':' . $guidKey) ?></guid>
      <pubDate><?= site_article_feed_xml(date(DATE_RSS, $timestamp !== false ? $timestamp : time())) ?></pubDate>
      <description><![CDATA[<?= site_article_feed_media_cdata($description) ?>]]></description>
<?php if ($image !== ''): ?>
      <media:content url="<?= site_article_feed_xml($image) ?>" medium="image" type="<?= site_article_feed_xml($imageMime) ?>" />
      <media:thumbnail url="<?= site_article_feed_xml($image) ?>" />
<?php endif; ?>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
<?php
}
