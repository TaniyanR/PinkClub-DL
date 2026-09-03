<?php
declare(strict_types=1);

const PCF_RSS_IMAGE_MAX_BYTES = 5000000;
const PCF_RSS_IMAGE_MAX_REDIRECTS = 3;
const PCF_RSS_IMAGE_CACHE_TTL = 259200;

function pcf_rss_image_http_url(string $value): string
{
    $url = trim($value);
    if ($url === '' || strlen($url) > 4096 || str_contains($url, "\r") || str_contains($url, "\n")) return '';
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http','https'], true) || $host === '' || !in_array($port, [80,443], true) || isset($parts['user']) || isset($parts['pass'])) return '';
    if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return '';
    return $url;
}

function pcf_rss_image_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function pcf_rss_image_resolve_ips(string $host): array
{
    $ips=[];
    foreach ((array)@dns_get_record($host, DNS_A|DNS_AAAA) as $record) {
        $ip=trim((string)($record['ip']??$record['ipv6']??''));
        if ($ip!=='' && pcf_rss_image_public_ip($ip)) $ips[$ip]=true;
    }
    if ($ips===[]) foreach ((array)@gethostbynamel($host) as $ip) { $ip=trim((string)$ip); if ($ip!=='' && pcf_rss_image_public_ip($ip)) $ips[$ip]=true; }
    return array_slice(array_keys($ips),0,3);
}

function pcf_rss_image_redirect_url(string $current,string $location): string
{
    $location=trim($location); if($location==='') return '';
    if(str_starts_with($location,'//')) return pcf_rss_image_http_url('https:'.$location);
    if(preg_match('#^https?://#i',$location)===1) return pcf_rss_image_http_url($location);
    $parts=parse_url($current); if(!is_array($parts)||empty($parts['host'])) return '';
    $scheme=strtolower((string)($parts['scheme']??'https')); $host=(string)$parts['host']; $port=isset($parts['port'])?':'.(int)$parts['port']:''; $base=$scheme.'://'.$host.$port;
    if(str_starts_with($location,'/')) return pcf_rss_image_http_url($base.$location);
    $dir=rtrim(str_replace('\\','/',dirname((string)($parts['path']??'/'))),'/'); if($dir==='.'||$dir==='/') $dir='';
    $segments=[]; foreach(explode('/',$dir.'/'.$location) as $segment){ if($segment===''||$segment==='.')continue; if($segment==='..'){array_pop($segments);continue;} $segments[]=$segment; }
    return pcf_rss_image_http_url($base.'/'.implode('/',$segments));
}

function pcf_rss_image_referer(string $articleUrl): string
{
    $parts=parse_url($articleUrl); if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host'])) return '';
    $scheme=strtolower((string)$parts['scheme']); if(!in_array($scheme,['http','https'],true)) return '';
    return $scheme.'://'.(string)$parts['host'].'/';
}

function pcf_rss_image_fetch(string $url,string $referer): ?array
{
    if(!function_exists('curl_init')) return null;
    $current=pcf_rss_image_http_url($url); if($current==='') return null; $seen=[];
    for($hop=0;$hop<=PCF_RSS_IMAGE_MAX_REDIRECTS;$hop++){
        if(isset($seen[$current])) return null; $seen[$current]=true; $parts=parse_url($current); if(!is_array($parts)) return null;
        $host=strtolower(trim((string)($parts['host']??''),'[]')); $port=isset($parts['port'])?(int)$parts['port']:(strtolower((string)($parts['scheme']??''))==='https'?443:80); $ips=pcf_rss_image_resolve_ips($host); if($ips===[]) return null; $redirect='';
        foreach($ips as $ip){
            $body='';$type='';$tooLarge=false;$ch=curl_init($current);if($ch===false)continue;$pin=str_contains($ip,':')?'['.$ip.']':$ip;
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; PinkClub-RSS-Image/1.0)',CURLOPT_REFERER=>$referer,CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$pin],CURLOPT_HEADERFUNCTION=>static function($curl,string $header)use(&$type,&$redirect,&$tooLarge):int{if(stripos($header,'Content-Type:')===0)$type=trim(substr($header,13));elseif(stripos($header,'Content-Length:')===0&&(int)trim(substr($header,15))>PCF_RSS_IMAGE_MAX_BYTES)$tooLarge=true;elseif(stripos($header,'Location:')===0)$redirect=trim(substr($header,9));return strlen($header);},CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>PCF_RSS_IMAGE_MAX_BYTES){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($tooLarge)return null;if($ok===false)continue;if($status>=300&&$status<400&&$redirect!=='')break;
            if($status>=200&&$status<300&&$body!==''){ $info=function_exists('getimagesizefromstring')?@getimagesizefromstring($body):false;$mime=is_array($info)?strtolower((string)($info['mime']??'')):strtolower(trim((string)(explode(';',$type,2)[0]??'')));if(in_array($mime,['image/jpeg','image/png','image/gif','image/webp'],true))return['bytes'=>$body,'type'=>$mime];return null; }
        }
        if($redirect===''||$hop>=PCF_RSS_IMAGE_MAX_REDIRECTS)return null;$current=pcf_rss_image_redirect_url($current,$redirect);if($current==='')return null;
    }
    return null;
}

function pcf_rss_image_send(string $bytes,string $type,int $mtime): never
{
    $etag='"'.hash('sha256',$bytes).'"';header('Content-Type: '.$type);header('Content-Length: '.strlen($bytes));header('Cache-Control: public, max-age=86400, stale-if-error=604800');header('ETag: '.$etag);header('Last-Modified: '.gmdate('D, d M Y H:i:s',$mtime).' GMT');header('X-Content-Type-Options: nosniff');if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='HEAD')echo $bytes;exit;
}

function pcf_rss_image_run(): never
{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    $sourceId=filter_input(INPUT_GET,'source',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$articleUrl=pcf_rss_image_http_url((string)($_GET['url']??''));if(!is_int($sourceId)||$articleUrl===''){http_response_code(404);exit;}
    try{$stmt=db()->prepare('SELECT image_url FROM rss_items WHERE source_id = :source_id AND url = :url ORDER BY id DESC LIMIT 1');$stmt->execute([':source_id'=>$sourceId,':url'=>$articleUrl]);$imageUrl=pcf_rss_image_http_url((string)($stmt->fetchColumn()?:''));}catch(Throwable){http_response_code(404);exit;}
    if($imageUrl===''){http_response_code(404);exit;}
    $cacheDir=dirname(__DIR__).'/storage/cache/rss-images';$cacheKey=hash('sha256',$sourceId.'|'.$articleUrl.'|'.$imageUrl);$bodyPath=$cacheDir.'/'.$cacheKey.'.bin';$metaPath=$cacheDir.'/'.$cacheKey.'.json';
    if(is_file($bodyPath)&&is_file($metaPath)){ $meta=json_decode((string)@file_get_contents($metaPath),true);$bytes=@file_get_contents($bodyPath);$mtime=(int)(@filemtime($bodyPath)?:time());if(is_string($bytes)&&$bytes!==''&&is_array($meta)&&isset($meta['type'])&&time()-$mtime<=PCF_RSS_IMAGE_CACHE_TTL)pcf_rss_image_send($bytes,(string)$meta['type'],$mtime); }
    $fetched=pcf_rss_image_fetch($imageUrl,pcf_rss_image_referer($articleUrl));if(is_array($fetched)){ $bytes=(string)($fetched['bytes']??'');$type=(string)($fetched['type']??'');if($bytes!==''&&$type!==''){if(!is_dir($cacheDir))@mkdir($cacheDir,0775,true);if(is_dir($cacheDir)&&is_writable($cacheDir)){try{$suffix=bin2hex(random_bytes(4));}catch(Throwable){$suffix=(string)mt_rand();}$tmp=$bodyPath.'.'.$suffix.'.tmp';if(@file_put_contents($tmp,$bytes,LOCK_EX)!==false){@rename($tmp,$bodyPath);@file_put_contents($metaPath,json_encode(['type'=>$type],JSON_UNESCAPED_SLASHES),LOCK_EX);}else @unlink($tmp);}pcf_rss_image_send($bytes,$type,time());} }
    if(is_file($bodyPath)&&is_file($metaPath)){ $meta=json_decode((string)@file_get_contents($metaPath),true);$bytes=@file_get_contents($bodyPath);$mtime=(int)(@filemtime($bodyPath)?:time());if(is_string($bytes)&&$bytes!==''&&is_array($meta)&&isset($meta['type']))pcf_rss_image_send($bytes,(string)$meta['type'],$mtime); }
    http_response_code(404);header('Cache-Control: public, max-age=60');exit;
}
