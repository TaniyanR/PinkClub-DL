<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';

const PCF_SOCIAL_IMAGE_MAX_BYTES = 12582912;
const PCF_SOCIAL_IMAGE_TTL = 259200;
const PCF_SOCIAL_IMAGE_ERROR_TTL = 300;
const PCF_SOCIAL_IMAGE_MAX_REDIRECTS = 3;

header('X-Content-Type-Options: nosniff', true);
header('Referrer-Policy: no-referrer', true);
header('X-Robots-Tag: noindex, nofollow', true);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

function pcf_social_image_allowed_host(string $host): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    return $host === 'duga.jp' || str_ends_with($host, '.duga.jp');
}

function pcf_social_image_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function pcf_social_image_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return pcf_social_image_public_ip($host) ? [$host] : [];
    }
    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '' && pcf_social_image_public_ip($ip)) $ips[$ip] = true;
        }
    }
    if ($ips === []) {
        foreach ((array)@gethostbynamel($host) as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '' && pcf_social_image_public_ip($ip)) $ips[$ip] = true;
        }
    }
    return array_keys($ips);
}

function pcf_social_image_normalize_url(string $value): string
{
    $url = trim($value);
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) return '';
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http','https'], true) || !pcf_social_image_allowed_host($host)
        || !in_array($port, [80,443], true) || isset($parts['user']) || isset($parts['pass'])) return '';
    if ($scheme === 'http') $url = 'https://' . substr($url, 7);
    return $url;
}

function pcf_social_image_candidates(array $item): array
{
    $urls = [];
    if (function_exists('pcf_item_image_candidates')) {
        foreach (pcf_item_image_candidates($item) as $candidate) {
            $url = pcf_social_image_normalize_url((string)$candidate);
            if ($url !== '') $urls[] = $url;
        }
    }
    foreach (['image_large','image_small','image_list','full_package_url','package_image_url','main_image_url','image_url'] as $key) {
        if (!array_key_exists($key, $item)) continue;
        $value = $item[$key];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        $stack = [$value];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (is_array($current)) { foreach ($current as $child) $stack[] = $child; continue; }
            if (!is_string($current)) continue;
            foreach (preg_split('/[\r\n,|\s]+/', trim($current)) ?: [] as $part) {
                $url = pcf_social_image_normalize_url((string)$part);
                if ($url !== '') $urls[] = $url;
            }
        }
    }
    return array_values(array_unique($urls));
}

function pcf_social_image_redirect_url(string $currentUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') return '';
    if (str_starts_with($location, '//')) return pcf_social_image_normalize_url('https:' . $location);
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) return pcf_social_image_normalize_url($location);
    $parts = parse_url($currentUrl);
    if (!is_array($parts) || empty($parts['host'])) return '';
    $base = 'https://' . (string)$parts['host'];
    if (str_starts_with($location, '/')) return pcf_social_image_normalize_url($base . $location);
    if (str_starts_with($location, '?')) return pcf_social_image_normalize_url($base . (string)($parts['path'] ?? '/') . $location);
    $dir = rtrim(str_replace('\\','/',dirname((string)($parts['path'] ?? '/'))), '/');
    if ($dir === '.' || $dir === '/') $dir = '';
    $segments=[];
    foreach (explode('/', $dir . '/' . $location) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[]=$segment;
    }
    return pcf_social_image_normalize_url($base . '/' . implode('/', $segments));
}

function pcf_social_image_detect_type(string $bytes, string $reportedType): string
{
    $allowed=['image/jpeg','image/png','image/gif','image/webp'];
    if (function_exists('finfo_open')) {
        $finfo=@finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected=strtolower((string)@finfo_buffer($finfo,$bytes)); @finfo_close($finfo);
            if (in_array($detected,$allowed,true)) return $detected;
        }
    }
    $reportedType=strtolower(trim((string)(explode(';',$reportedType,2)[0]??'')));
    return in_array($reportedType,$allowed,true)?$reportedType:'';
}

function pcf_social_image_fetch_once(string $url): ?array
{
    if (!function_exists('curl_init')) return null;
    $parts=parse_url($url); if(!is_array($parts))return null;
    $host=strtolower((string)($parts['host']??''));
    if(strtolower((string)($parts['scheme']??''))!=='https'||!pcf_social_image_allowed_host($host))return null;
    $ips=pcf_social_image_resolve_public_ips($host); if($ips===[])return null;
    foreach($ips as $ip){$body='';$contentType='';$location='';$tooLarge=false;$ch=curl_init($url);if($ch===false)continue;$resolveIp=str_contains($ip,':')?'['.$ip.']':$ip;
        $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; PinkClub-DL-SocialCard/1.1; +https://pinkclub-dl.com/)',CURLOPT_REFERER=>'https://duga.jp/',CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$resolveIp],CURLOPT_HEADERFUNCTION=>static function($curl,string $header)use(&$contentType,&$location):int{if(stripos($header,'Content-Type:')===0)$contentType=trim(substr($header,13));if(stripos($header,'Location:')===0)$location=trim(substr($header,9));return strlen($header);},CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>PCF_SOCIAL_IMAGE_MAX_BYTES){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}];
        if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        curl_setopt_array($ch,$options);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($ok===false||$tooLarge)continue;if($status>=300&&$status<400&&$location!=='')return['redirect'=>$location];if($status<200||$status>=300||$body==='')continue;$type=pcf_social_image_detect_type($body,$contentType);if($type!=='')return['bytes'=>$body,'type'=>$type];
    }
    return null;
}

function pcf_social_image_fetch(string $url): ?array
{
    $current=pcf_social_image_normalize_url($url);if($current==='')return null;$seen=[];
    for($hop=0;$hop<=PCF_SOCIAL_IMAGE_MAX_REDIRECTS;$hop++){if(isset($seen[$current]))return null;$seen[$current]=true;$result=pcf_social_image_fetch_once($current);if(!is_array($result))return null;if(isset($result['bytes'],$result['type']))return $result;if($hop>=PCF_SOCIAL_IMAGE_MAX_REDIRECTS)return null;$current=pcf_social_image_redirect_url($current,(string)($result['redirect']??''));if($current==='')return null;}
    return null;
}

function pcf_social_image_extension(string $type):string{return match($type){'image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',default=>'jpg'};}
function pcf_social_image_serve(string $path,string $type,bool $headOnly):never{$size=@filesize($path);header('Content-Type: '.$type);header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');if(is_int($size)&&$size>=0)header('Content-Length: '.$size);header('ETag: "'.sha1((string)@filemtime($path).'|'.(string)$size).'"');if(!$headOnly)readfile($path);exit;}
function pcf_social_image_fallback(bool $headOnly):never{$logoPath='';try{$logoPath=trim((string)site_setting_get('site.logo_path',''));}catch(Throwable){}if($logoPath!==''){$relative=ltrim($logoPath,'/');foreach([dirname(__DIR__).'/'.$relative,dirname(__DIR__).'/public/'.$relative] as $candidate){if(!is_file($candidate)||!is_readable($candidate))continue;$bytes=@file_get_contents($candidate);if(!is_string($bytes)||$bytes==='')continue;$type=pcf_social_image_detect_type($bytes,'');if($type!=='')pcf_social_image_serve($candidate,$type,$headOnly);}}http_response_code(404);exit;}

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!is_int($id)||$id<=0){http_response_code(404);exit;}
try{$stmt=db()->prepare('SELECT * FROM items WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$id]);$item=$stmt->fetch(PDO::FETCH_ASSOC);}catch(Throwable){$item=false;}
if(!is_array($item)){http_response_code(404);exit;}
$headOnly=$method==='HEAD';$cacheDir=dirname(__DIR__).'/storage/cache/social-images';if(!is_dir($cacheDir))@mkdir($cacheDir,0755,true);if(!is_dir($cacheDir)||!is_writable($cacheDir))pcf_social_image_fallback($headOnly);
$base=$cacheDir.'/'.$id;$metaPath=$base.'.json';$errorPath=$base.'.error';$lockPath=$base.'.lock';
$serveCached=static function()use($cacheDir,$metaPath,$headOnly):void{if(!is_file($metaPath))return;$meta=json_decode((string)@file_get_contents($metaPath),true);if(!is_array($meta))return;$file=$cacheDir.'/'.basename((string)($meta['file']??''));$type=(string)($meta['type']??'');if(is_file($file)&&$type!==''&&time()-(int)@filemtime($file)<PCF_SOCIAL_IMAGE_TTL)pcf_social_image_serve($file,$type,$headOnly);};
$serveCached();if(is_file($errorPath)&&time()-(int)@filemtime($errorPath)<PCF_SOCIAL_IMAGE_ERROR_TTL)pcf_social_image_fallback($headOnly);
$lock=@fopen($lockPath,'c');if(is_resource($lock))@flock($lock,LOCK_EX);$serveCached();
foreach(pcf_social_image_candidates($item) as $url){$result=pcf_social_image_fetch($url);if(!is_array($result))continue;$bytes=(string)($result['bytes']??'');$type=(string)($result['type']??'');if($bytes===''||$type==='')continue;$ext=pcf_social_image_extension($type);$fileName=$id.'.'.$ext;$path=$cacheDir.'/'.$fileName;try{$suffix=bin2hex(random_bytes(4));}catch(Throwable){$suffix=str_replace('.','',uniqid('',true));}$tmp=$path.'.'.$suffix.'.tmp';if(@file_put_contents($tmp,$bytes,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);continue;}@file_put_contents($metaPath,json_encode(['file'=>$fileName,'type'=>$type],JSON_UNESCAPED_SLASHES),LOCK_EX);@unlink($errorPath);if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}pcf_social_image_serve($path,$type,$headOnly);}
@touch($errorPath);if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}pcf_social_image_fallback($headOnly);
