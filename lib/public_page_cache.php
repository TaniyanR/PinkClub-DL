<?php

declare(strict_types=1);

function pcf_public_request_is_mobile(): bool
{
    static $isMobile = null;
    if (is_bool($isMobile)) return $isMobile;
    $viewportCookie=(string)($_COOKIE['pcf_viewport']??'');
    $clientHint=(string)($_SERVER['HTTP_SEC_CH_UA_MOBILE']??'');
    $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
    $isMobile=$viewportCookie==='sp'||$clientHint==='?1'||($ua!==''&&preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|webOS/i',$ua)===1);
    return $isMobile;
}

function pcf_public_page_cache_analytics_token(string $path): string
{
    if (!empty($GLOBALS['__pcf_public_page_cache_active'])) return '__PCF_ANALYTICS_BEACON_TOKEN__';
    return function_exists('analytics_beacon_token') ? analytics_beacon_token($path) : '';
}

function pcf_public_page_cache_hydrate_analytics_token(string $content): string
{
    $marker='__PCF_ANALYTICS_BEACON_TOKEN__';
    if(!str_contains($content,$marker)||!function_exists('analytics_beacon_token'))return $content;
    if(!headers_sent()){header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');}
    return str_replace($marker,analytics_beacon_token((string)($_SERVER['REQUEST_URI']??'/')),$content);
}

function pcf_public_page_cache_authority(): string
{
    $parts=parse_url(defined('BASE_URL')?(string)BASE_URL:'');
    if(!is_array($parts))return 'localhost';
    $host=strtolower(trim((string)($parts['host']??'')));if($host==='')return 'localhost';
    $port=isset($parts['port'])?(int)$parts['port']:0;return $port>0?$host.':'.$port:$host;
}

function pcf_public_page_cache_start(int $ttlSeconds=120): void
{
    if(PHP_SAPI==='cli'||headers_sent())return;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if(!in_array($method,['GET','HEAD'],true))return;
    if(function_exists('auth_user')&&auth_user())return;
    $requestUri=(string)($_SERVER['REQUEST_URI']??'/');$requestPath=(string)(parse_url($requestUri,PHP_URL_PATH)?:'/');$scriptName=basename((string)($_SERVER['SCRIPT_NAME']??''));
    $excluded=['login0718.php','forgot_password.php','reset_password.php','setup_check.php','search.php','ranking_refresh.php','recently_viewed_items.php','link_apply.php','deletion_request_submit.php'];
    $pageSlug=trim((string)($_GET['slug']??''));$isContact=$scriptName==='page.php'&&in_array($pageSlug,['que','contact'],true);$isExcluded=in_array($scriptName,$excluded,true)||$isContact;
    if(str_contains($requestPath,'/admin/')||str_contains($requestPath,'/api/')||$scriptName==='page_view_beacon.php'||$isExcluded||isset($_GET['pcf_nocache'])){if($isExcluded){header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');}return;}
    $ttlSeconds=max(30,min(600,$ttlSeconds));$dir=dirname(__DIR__).'/storage/cache/public-pages';if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return;if(!is_writable($dir))return;
    $query=[];parse_str((string)(parse_url($requestUri,PHP_URL_QUERY)??''),$query);$allowed=['all','cid','content_id','format','fragment','group','id','ids','index','limit','name','order','page','part','q','rank_period','slug','type'];$numeric=['id'=>2000000000,'index'=>10000,'limit'=>200,'page'=>1000,'part'=>1000];$high=['fragment','ids','q'];
    foreach(array_keys($query) as $key){$normalized=strtolower((string)$key);$value=$query[$key]??null;if(!in_array($normalized,$allowed,true)){unset($query[$key]);continue;}if(is_array($value)||strlen((string)$value)>160||(in_array($normalized,$high,true)&&trim((string)$value)!=='')||(isset($numeric[$normalized])&&(preg_match('/^\d{1,9}$/',(string)$value)!==1||(int)$value>$numeric[$normalized]))){header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');return;}}
    ksort($query);$normalizedUri=$requestPath;$qs=http_build_query($query);if($qs!=='')$normalizedUri.='?'.$qs;
    $key=hash('sha256','v19|'.pcf_public_page_cache_authority().'|'.(pcf_public_request_is_mobile()?'sp':'pc').'|'.$normalizedUri);$file=$dir.'/'.$key.'.html';$lockFile=$dir.'/.regenerate-'.substr($key,0,1).'.lock';
    if(is_file($file)&&time()-(int)filemtime($file)<$ttlSeconds){$content=@file_get_contents($file);if(is_string($content)&&$content!==''){header('X-PCF-Page-Cache: HIT');header('Cache-Control: public, max-age=60, stale-while-revalidate=300');if($method!=='HEAD')echo pcf_public_page_cache_hydrate_analytics_token($content);exit;}}
    $lock=@fopen($lockFile,'c');if(is_resource($lock)&&!@flock($lock,LOCK_EX|LOCK_NB)){if(is_file($file)){ $stale=@file_get_contents($file);if(is_string($stale)&&$stale!==''){header('X-PCF-Page-Cache: STALE');header('Cache-Control: public, max-age=30, stale-while-revalidate=300');if($method!=='HEAD')echo pcf_public_page_cache_hydrate_analytics_token($stale);fclose($lock);exit;}}if(!@flock($lock,LOCK_EX)){fclose($lock);$lock=false;}}
    if(is_resource($lock)&&is_file($file)&&time()-(int)filemtime($file)<$ttlSeconds){$content=@file_get_contents($file);if(is_string($content)&&$content!==''){header('X-PCF-Page-Cache: HIT-AFTER-WAIT');header('Cache-Control: public, max-age=60, stale-while-revalidate=300');@flock($lock,LOCK_UN);fclose($lock);if($method!=='HEAD')echo pcf_public_page_cache_hydrate_analytics_token($content);exit;}}
    header('X-PCF-Page-Cache: MISS');$GLOBALS['__pcf_public_page_cache_active']=true;ob_start();
    register_shutdown_function(static function()use($file,$dir,$method,$lock):void{if(ob_get_level()<1){if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}return;}$content=ob_get_clean();if(!is_string($content))return;$status=http_response_code();if($status===false)$status=200;if($status===200&&$content!==''){try{$suffix=bin2hex(random_bytes(4));}catch(Throwable){$suffix=uniqid('',true);}$tmp=$dir.'/.'.basename($file).'.'.$suffix.'.tmp';if(@file_put_contents($tmp,$content,LOCK_EX)!==false)@rename($tmp,$file);else @unlink($tmp);}if($method!=='HEAD')echo pcf_public_page_cache_hydrate_analytics_token($content);if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}});
}

function pcf_public_page_cache_clear(): int
{
    $dir=dirname(__DIR__).'/storage/cache/public-pages';if(!is_dir($dir))return 0;$deleted=0;try{foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $file){if($file->isFile()&&!$file->isLink()&&@unlink($file->getPathname()))$deleted++;}}catch(Throwable){error_log('[public_page_cache_clear] failed');}return $deleted;
}
