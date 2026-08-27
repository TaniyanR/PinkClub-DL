<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rss_display_balance.php';

function rss_trade_enrich_items(array $items): array
{
    $sourceIds=[];
    foreach($items as $item){if(!is_array($item))continue;$sourceId=(int)($item['source_id']??0);if($sourceId>0)$sourceIds[$sourceId]=true;}
    if($sourceIds===[])return $items;
    $metaBySource=[];
    try{
        $ids=array_keys($sourceIds);$placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=db()->prepare('SELECT rs.id AS source_id, pr.partner_site_id, ps.ref_code, ps.url AS partner_site_url FROM rss_sources rs LEFT JOIN partner_rss pr ON pr.id=rs.source_ref_id LEFT JOIN partner_sites ps ON ps.id=pr.partner_site_id WHERE rs.id IN ('.$placeholders.')');
        $stmt->execute($ids);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$sourceId=(int)($row['source_id']??0);if($sourceId<=0)continue;$metaBySource[$sourceId]=['partner_site_id'=>(int)($row['partner_site_id']??0),'partner_ref_code'=>trim((string)($row['ref_code']??'')),'partner_site_url'=>trim((string)($row['partner_site_url']??''))];}
    }catch(Throwable){error_log('[rss] access-trade metadata lookup failed');return $items;}
    foreach($items as &$item){if(!is_array($item))continue;$meta=$metaBySource[(int)($item['source_id']??0)]??null;if(is_array($meta))$item=array_merge($item,$meta);}unset($item);
    return $items;
}

function rss_trade_metrics_by_ref(array $refs,int $days=30):array
{
    $refs=array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$refs),static fn(string $v):bool=>$v!=='')));
    if($refs===[])return [];$days=max(1,min(365,$days));$metrics=[];foreach($refs as $ref)$metrics[$ref]=['in'=>0,'out'=>0];
    try{$ph=implode(',',array_fill(0,count($refs),'?'));$stmt=db()->prepare('SELECT ref_code,COUNT(*) c FROM in_logs WHERE created_at>=DATE_SUB(NOW(), INTERVAL '.$days.' DAY) AND ref_code IN ('.$ph.') GROUP BY ref_code');$stmt->execute($refs);foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$ref=trim((string)($row['ref_code']??''));if(isset($metrics[$ref]))$metrics[$ref]['in']=(int)($row['c']??0);} $stmt=db()->prepare('SELECT ref_code,COUNT(*) c FROM out_logs WHERE created_at>=DATE_SUB(NOW(), INTERVAL '.$days.' DAY) AND ref_code IN ('.$ph.') GROUP BY ref_code');$stmt->execute($refs);foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$ref=trim((string)($row['ref_code']??''));if(isset($metrics[$ref]))$metrics[$ref]['out']=(int)($row['c']??0);}}catch(Throwable){error_log('[rss] access-trade metrics lookup failed');}
    return $metrics;
}

function rss_trade_weight(int $inCount,int $outCount):float
{
    $inCount=max(0,$inCount);$outCount=max(0,$outCount);if($inCount===0&&$outCount===0)return .5;$debt=max(0,$inCount-$outCount);if($debt>0)return min(120.0,1.0+(float)$debt+min(10.0,sqrt((float)$inCount)/2.0));return max(.15,.5/(1.0+(max(0,$outCount-$inCount)/10.0)));
}

function rss_trade_select(array $items,int $maxTotal,int $hardPerSiteCap,int $days=30):array
{
    if($maxTotal<=0||$items===[])return [];$maxTotal=max(1,$maxTotal);$hardPerSiteCap=max(1,$hardPerSiteCap);$items=rss_trade_enrich_items($items);$seen=[];$titles=[];$buckets=[];$refs=[];
    foreach($items as $item){if(!is_array($item))continue;$key=function_exists('rss_normalize_display_key')?rss_normalize_display_key($item):trim((string)($item['link']??''));if($key==='')$key=mb_strtolower(trim((string)($item['title']??'')));if($key!==''&&isset($seen[$key]))continue;$titleKey=mb_strtolower(preg_replace('/\s+/u',' ',trim((string)($item['title']??'')))??'');if($titleKey!==''&&isset($titles[$titleKey]))continue;if($key!=='')$seen[$key]=true;if($titleKey!=='')$titles[$titleKey]=true;$siteKey=rss_partner_display_source_key($item);if($siteKey==='')$siteKey='unknown:'.(string)($item['source_id']??0);$ref=trim((string)($item['partner_ref_code']??''));if($ref!=='')$refs[$ref]=true;$buckets[$siteKey][]=$item;}
    if($buckets===[])return [];foreach($buckets as &$bucket)if(count($bucket)>1)shuffle($bucket);unset($bucket);$activeSiteCount=count($buckets);$fairShare=(int)ceil($maxTotal/max(1,$activeSiteCount));$cap=min($hardPerSiteCap,max(1,$fairShare+1));$metrics=rss_trade_metrics_by_ref(array_keys($refs),$days);$state=[];foreach($buckets as $siteKey=>$bucket){$first=$bucket[0]??[];$ref=trim((string)($first['partner_ref_code']??''));$m=$ref!==''?($metrics[$ref]??['in'=>0,'out'=>0]):['in'=>0,'out'=>0];$state[$siteKey]=['weight'=>rss_trade_weight((int)$m['in'],(int)$m['out']),'current'=>0.0,'picked'=>0];}
    $result=[];$order=array_keys($buckets);shuffle($order);foreach($order as $siteKey){if(count($result)>=$maxTotal)break;$item=array_shift($buckets[$siteKey]);if(is_array($item)){$result[]=$item;$state[$siteKey]['picked']++;}}
    $last=$result!==[]?rss_partner_display_source_key($result[count($result)-1]):null;while(count($result)<$maxTotal){$active=[];$total=0.0;foreach($buckets as $siteKey=>$bucket){if($bucket===[]||$state[$siteKey]['picked']>=$cap)continue;$state[$siteKey]['current']+=$state[$siteKey]['weight'];$active[$siteKey]=$state[$siteKey]['current'];$total+=$state[$siteKey]['weight'];}if($active===[])break;arsort($active,SORT_NUMERIC);$keys=array_keys($active);$chosen=$keys[0];if($chosen===$last&&count($keys)>1)$chosen=$keys[1];$item=array_shift($buckets[$chosen]);if(!is_array($item))continue;$state[$chosen]['current']-=max(.0001,$total);$state[$chosen]['picked']++;$result[]=$item;$last=$chosen;}
    return function_exists('rss_spread_items_by_partner_site')?rss_spread_items_by_partner_site($result):$result;
}

function rss_trade_split_columns(array $items):array
{
    $left=[];$right=[];$lastLeft=null;$lastRight=null;foreach($items as $item){if(!is_array($item))continue;$key=rss_partner_display_source_key($item);if(count($left)<count($right))$target='left';elseif(count($right)<count($left))$target='right';else{$lr=$key!==''&&$key===$lastLeft;$rr=$key!==''&&$key===$lastRight;if($lr&&!$rr)$target='right';elseif($rr&&!$lr)$target='left';else$target=random_int(0,1)===0?'left':'right';}if($target==='left'){$left[]=$item;$lastLeft=$key;}else{$right[]=$item;$lastRight=$key;}}return[$left,$right];
}

function rss_trade_out_url(array $item):string
{
    $target=trim((string)($item['link']??''));$partnerId=(int)($item['partner_site_id']??0);$ref=trim((string)($item['partner_ref_code']??''));if($target===''||$partnerId<=0||$ref==='')return $target;if(filter_var($target,FILTER_VALIDATE_URL)===false)return '';$scheme=strtolower((string)(parse_url($target,PHP_URL_SCHEME)?:''));if(!in_array($scheme,['http','https'],true))return '';$q=http_build_query(['partner'=>$partnerId,'ref'=>$ref,'to'=>$target]);return function_exists('public_url')?public_url('out.php?'.$q):'/out.php?'.$q;
}
