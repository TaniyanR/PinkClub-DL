<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/site_article_feeds.php';

function pcf_site_feed_xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function pcf_site_feed_item_url(array $item): string
{
    $id = (int)($item['id'] ?? 0);
    if ($id > 0) return public_url('item.php?id=' . $id);
    $contentId = trim((string)($item['content_id'] ?? ''));
    return $contentId !== '' ? public_url('item.php?cid=' . rawurlencode($contentId)) : public_url('');
}
function pcf_site_feed_date(?string $value): string
{
    $timestamp=$value!==null&&trim($value)!==''?strtotime($value):false;if($timestamp===false)$timestamp=time();return date(DATE_RSS,$timestamp);
}
function pcf_site_feed_image_mime(string $url): string
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return match (pathinfo($path, PATHINFO_EXTENSION)) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

$siteTitle=trim(site_setting_get('site.title',site_setting_get('site.name',APP_NAME)));if($siteTitle==='')$siteTitle=APP_NAME;
$siteUrl=trim(site_setting_get('site.url',app_url()));$parts=$siteUrl!==''?parse_url($siteUrl):false;
if($siteUrl===''||filter_var($siteUrl,FILTER_VALIDATE_URL)===false||!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||trim((string)($parts['host']??''))==='')$siteUrl=app_url();
$description=trim(site_setting_get('site.tagline',$siteTitle));if($description==='')$description=$siteTitle;
try{$items=fetch_items('date_published_desc',20,0);}catch(Throwable){$items=[];}
$lastBuildDate=date(DATE_RSS);foreach($items as $item){if(!is_array($item))continue;$updated=trim((string)($item['updated_at']??''));$release=trim((string)($item['release_date']??''));if($updated!==''){$lastBuildDate=pcf_site_feed_date($updated);break;}if($release!==''){$lastBuildDate=pcf_site_feed_date($release);break;}}
header('Content-Type: application/rss+xml; charset=UTF-8');echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title><?= pcf_site_feed_xml($siteTitle) ?></title>
    <link><?= pcf_site_feed_xml($siteUrl) ?></link>
    <description><?= pcf_site_feed_xml($description) ?></description>
    <language>ja</language>
    <lastBuildDate><?= pcf_site_feed_xml($lastBuildDate) ?></lastBuildDate>
<?php foreach($items as $item): ?>
<?php if(!is_array($item))continue;$itemTitle=trim((string)($item['title']??''));if($itemTitle==='')continue;$itemLink=pcf_site_feed_item_url($item);$itemGuid=trim((string)($item['content_id']??''));if($itemGuid==='')$itemGuid=$itemLink;$itemDate=trim((string)($item['release_date']??''));if($itemDate==='')$itemDate=trim((string)($item['updated_at']??''));$itemDescription=trim((string)($item['category_name']??''));$itemImage=site_article_feed_image($item);$itemImageMime=$itemImage!==''?pcf_site_feed_image_mime($itemImage):''; ?>
    <item>
      <title><?= pcf_site_feed_xml($itemTitle) ?></title>
      <link><?= pcf_site_feed_xml($itemLink) ?></link>
      <guid isPermaLink="false"><?= pcf_site_feed_xml($itemGuid) ?></guid>
      <pubDate><?= pcf_site_feed_xml(pcf_site_feed_date($itemDate)) ?></pubDate>
<?php if($itemDescription!==''): ?>      <description><?= pcf_site_feed_xml($itemDescription) ?></description>
<?php endif; ?>
<?php if($itemImage!==''): ?>      <media:content url="<?= pcf_site_feed_xml($itemImage) ?>" medium="image" type="<?= pcf_site_feed_xml($itemImageMime) ?>" />
      <media:thumbnail url="<?= pcf_site_feed_xml($itemImage) ?>" />
<?php endif; ?>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
