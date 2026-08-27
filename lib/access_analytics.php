<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crawler_guard.php';
require_once __DIR__ . '/site_settings.php';

function analytics_beacon_marker_hash(): string { return hash('sha256','pinkclub-browser-beacon'); }

function analytics_normalize_host(string $host): string
{
    $host=strtolower(trim($host));if($host==='')return '';
    if(str_contains($host,'://'))$host=(string)(parse_url($host,PHP_URL_HOST)?:'');
    $host=(string)preg_replace('/:\d+$/','',$host);$host=rtrim($host,'.');return (string)preg_replace('/^www\./','',$host);
}

function analytics_site_host(): string
{
    $host=analytics_normalize_host((string)(parse_url(defined('BASE_URL')?(string)BASE_URL:'',PHP_URL_HOST)?:''));
    return $host!==''?$host:analytics_normalize_host((string)($_SERVER['HTTP_HOST']??''));
}

function analytics_visitor_hash(string $ua): string
{
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??''));if($ip==='')$ip='unknown';
    $salt=trim((string)config_get('security.ip_hash_salt',''));
    if($salt===''){$dbName=(string)config_get('db.name',config_get('db.dbname','pinkclub'));$salt=hash('sha256',__DIR__.'|'.$dbName.'|pinkclub-analytics');}
    return hash_hmac('sha256',$ip,$salt);
}

function analytics_normalize_beacon_path(string $rawPath): string
{
    $path=(string)parse_url($rawPath,PHP_URL_PATH);if($path===''||$path[0]!=='/')$path='/';
    $query=[];parse_str((string)(parse_url($rawPath,PHP_URL_QUERY)??''),$query);unset($query['rank_period']);ksort($query);$qs=http_build_query($query);
    return mb_substr($path.($qs!==''?'?'.$qs:''),0,255);
}

function analytics_beacon_token(string $path,?int $issuedAt=null): string
{
    $issuedAt??=time();$path=analytics_normalize_beacon_path($path);$visitor=analytics_visitor_hash((string)($_SERVER['HTTP_USER_AGENT']??''));
    $secret=(string)config_get('security.ip_hash_salt','');if($secret==='')$secret=hash('sha256',__DIR__.'|'.(string)config_get('db.name','pinkclub').'|pinkclub-beacon-token');
    return $issuedAt.'.'.hash_hmac('sha256',$issuedAt."\n".$path."\n".$visitor,$secret);
}

function analytics_beacon_token_is_valid(string $token,string $path): bool
{
    if(preg_match('/^(\d{10})\.([a-f0-9]{64})$/',$token,$matches)!==1)return false;$issuedAt=(int)$matches[1];
    if($issuedAt>time()-2||$issuedAt<time()-1800)return false;return hash_equals(analytics_beacon_token($path,$issuedAt),$token);
}

function analytics_request_is_automated(?string $userAgent=null): bool
{
    $userAgent??=(string)($_SERVER['HTTP_USER_AGENT']??'');if($userAgent==='')return true;
    if(function_exists('pcf_crawler_guard_is_known_crawler')&&pcf_crawler_guard_is_known_crawler($userAgent))return true;
    if(preg_match('/(?:bot\b|spider|crawler|headless|lighthouse|pagespeed|pingdom|uptime|monitoring|python-requests|python-urllib|curl\/|wget\/|httpclient|go-http-client|java\/|okhttp|libwww-perl|phantomjs|selenium|playwright|puppeteer)/i',$userAgent)===1)return true;
    foreach(['HTTP_PURPOSE','HTTP_SEC_PURPOSE','HTTP_X_MOZ'] as $header){$value=strtolower((string)($_SERVER[$header]??''));if($value!==''&&preg_match('/\b(prefetch|prerender|preview)\b/',$value)===1)return true;}
    return false;
}

function analytics_request_is_valid_browser_beacon(): bool
{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'||auth_user())return false;
    if((string)($_SERVER['HTTP_DNT']??'')==='1'||strtolower((string)($_SERVER['HTTP_SEC_GPC']??''))==='1')return false;
    $siteHost=analytics_site_host();if($siteHost==='')return false;$fetchSite=strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE']??'')));if($fetchSite!==''&&$fetchSite!=='same-origin')return false;
    $originHost=analytics_normalize_host((string)($_SERVER['HTTP_ORIGIN']??''));$refererHost=analytics_normalize_host((string)($_SERVER['HTTP_REFERER']??''));
    if($originHost!=='')return hash_equals($siteHost,$originHost);if($refererHost!=='')return hash_equals($siteHost,$refererHost);return $fetchSite==='same-origin';
}

function analytics_maybe_cleanup_old_logs(int $retentionDays=730,int $batchSize=2000,bool $forceCheck=false): void
{
    if(!$forceCheck&&mt_rand(1,20)!==1)return;$retentionDays=max(365,min(3650,$retentionDays));$batchSize=max(100,min(5000,$batchSize));
    $last=(int)(setting_get('analytics.cleanup.last_at','0')??'0');if($last>0&&$last>time()-21600)return;
    try{setting_set('analytics.cleanup.last_at',(string)time());$cutoffDateTime=date('Y-m-d H:i:s',strtotime('-'.$retentionDays.' days'));$cutoffDate=date('Y-m-d',strtotime('-'.$retentionDays.' days'));
        $targets=[['site_events','created_at',$cutoffDateTime],['in_logs','created_at',$cutoffDateTime],['out_logs','created_at',$cutoffDateTime],['item_out_click_daily','clicked_at',$cutoffDateTime],['page_views','viewed_at',$cutoffDateTime],['visit_sessions','stat_date',$cutoffDate]];$deleted=0;
        foreach($targets as [$table,$column,$cutoff]){if(!db_table_exists($table))continue;$sql=sprintf('DELETE FROM `%s` WHERE `%s` < :cutoff ORDER BY `%s` ASC LIMIT %d',$table,$column,$column,$batchSize);$stmt=db()->prepare($sql);$stmt->execute([':cutoff'=>$cutoff]);$deleted+=$stmt->rowCount();}
        setting_set('analytics.cleanup.last_success_at',date('Y-m-d H:i:s'));setting_set('analytics.cleanup.last_deleted_rows',(string)$deleted);setting_set('analytics.cleanup.retention_days',(string)$retentionDays);
    }catch(Throwable){error_log('analytics log cleanup failed');}
}

function analytics_track_beacon(): void
{
    if(!analytics_ensure_tables()||!analytics_request_is_valid_browser_beacon())return;
    try{$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');if(analytics_request_is_automated($ua))return;$rawPath=(string)($_POST['path']??'/');$token=trim((string)($_POST['token']??''));if(!analytics_beacon_token_is_valid($token,$rawPath))return;
        $hash=analytics_visitor_hash($ua);$path=(string)parse_url($rawPath,PHP_URL_PATH)?:'/';$pathForStats=analytics_normalize_beacon_path($rawPath);$today=date('Y-m-d');$referrer=(string)($_POST['referrer']??'');$refererHost=(string)(parse_url($referrer,PHP_URL_HOST)?:'');$refCode=trim((string)($_POST['ref']??''));$pdo=db();
        $dup=$pdo->prepare("SELECT 1 FROM site_events WHERE event_type='pv' AND session_id_hash=:marker AND ip_hash=:visitor AND path=:path AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY LIMIT 1");$dup->execute([':marker'=>analytics_beacon_marker_hash(),':visitor'=>$hash,':path'=>$pathForStats]);if($dup->fetchColumn()!==false)return;
        $visit=$pdo->prepare('INSERT IGNORE INTO visit_sessions(stat_date,visitor_hash,first_seen_at) VALUES(:d,:h,NOW())');$visit->execute([':d'=>$today,':h'=>$hash]);$isUnique=$visit->rowCount()===1;
        $pdo->prepare("INSERT INTO site_events(event_type,path,referrer,ua_hash,ip_hash,session_id_hash,created_at) VALUES('pv',:path,:referrer,:ua,:ip,:marker,NOW())")->execute([':path'=>$pathForStats,':referrer'=>$referrer!==''?mb_substr($referrer,0,500):null,':ua'=>$ua!==''?hash('sha256',$ua):null,':ip'=>$hash,':marker'=>analytics_beacon_marker_hash()]);
        $pdo->prepare('INSERT INTO daily_stats(stat_date,pv,uu,in_count,out_count,updated_at) VALUES(:d,1,:uu,0,0,NOW()) ON DUPLICATE KEY UPDATE pv=pv+1,uu=uu+VALUES(uu),updated_at=NOW()')->execute([':d'=>$today,':uu'=>$isUnique?1:0]);
        $host=analytics_site_host();$external=$host!==''&&$refererHost!==''&&analytics_normalize_host($refererHost)!==$host;
        if($refCode!==''||$external){$source=$refCode!==''?'ref:'.mb_substr($refCode,0,64):'host:'.analytics_normalize_host($refererHost);$inDup=$pdo->prepare("SELECT 1 FROM site_events WHERE event_type='in' AND session_id_hash=:visitor AND referrer=:source AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY LIMIT 1");$inDup->execute([':visitor'=>$hash,':source'=>$source]);if($inDup->fetchColumn()===false){$pdo->prepare("INSERT INTO site_events(event_type,path,referrer,ua_hash,ip_hash,session_id_hash,created_at) VALUES('in',:path,:source,NULL,:ip,:visitor,NOW())")->execute([':path'=>mb_substr($path,0,255),':source'=>$source,':ip'=>$hash,':visitor'=>$hash]);$pdo->prepare('INSERT INTO in_logs(created_at,ref_code,referer_host,path) VALUES(NOW(),:ref,:host,:path)')->execute([':ref'=>$refCode,':host'=>mb_substr($refererHost,0,255),':path'=>mb_substr($path,0,255)]);$pdo->prepare('UPDATE daily_stats SET in_count=in_count+1,updated_at=NOW() WHERE stat_date=:d')->execute([':d'=>$today]);}}
    }catch(Throwable $e){analytics_disable_for_request($e);}analytics_maybe_cleanup_old_logs(730,2000);
}

function analytics_log_out(string $targetUrl,string $refCode,string $path): void
{
    if(!analytics_ensure_tables()||analytics_request_is_automated())return;
    try{$today=date('Y-m-d');$pdo=db();$visitor=analytics_visitor_hash((string)($_SERVER['HTTP_USER_AGENT']??''));$targetHash=hash('sha256',$targetUrl);$dup=$pdo->prepare("SELECT 1 FROM site_events WHERE event_type='out' AND session_id_hash=:visitor AND path=:target AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY LIMIT 1");$dup->execute([':visitor'=>$visitor,':target'=>$targetHash]);if($dup->fetchColumn()!==false)return;
        $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');$pdo->prepare("INSERT INTO site_events(event_type,path,referrer,ua_hash,ip_hash,session_id_hash,created_at) VALUES('out',:target,:url,:ua,:ip,:visitor,NOW())")->execute([':target'=>$targetHash,':url'=>mb_substr($targetUrl,0,500),':ua'=>$ua!==''?hash('sha256',$ua):null,':ip'=>$visitor,':visitor'=>$visitor]);$pdo->prepare('INSERT INTO out_logs(created_at,ref_code,target_url,path) VALUES(NOW(),:ref,:url,:path)')->execute([':ref'=>mb_substr($refCode,0,64),':url'=>mb_substr($targetUrl,0,1000),':path'=>mb_substr($path,0,255)]);$pdo->prepare('INSERT INTO daily_stats(stat_date,pv,uu,in_count,out_count,updated_at) VALUES(:d,0,0,0,1,NOW()) ON DUPLICATE KEY UPDATE out_count=out_count+1,updated_at=NOW()')->execute([':d'=>$today]);
        if(db_table_exists('item_out_click_daily')){$stmt=$pdo->prepare('SELECT id FROM items WHERE affiliate_url=:url LIMIT 1');$stmt->execute([':url'=>$targetUrl]);$itemId=(int)($stmt->fetchColumn()?:0);if($itemId>0){$click=$pdo->prepare('INSERT IGNORE INTO item_out_click_daily(item_id,click_date,visitor_hash,clicked_at) VALUES(:item_id,:click_date,:visitor_hash,NOW())');$click->execute([':item_id'=>$itemId,':click_date'=>$today,':visitor_hash'=>$visitor]);}}
    }catch(Throwable $e){analytics_disable_for_request($e);}
}

function analytics_ensure_tables(): bool { return empty($GLOBALS['__analytics_disabled_for_request']); }
function analytics_disable_for_request(Throwable $e): void { $GLOBALS['__analytics_disabled_for_request']=true;error_log('analytics disabled'); }
