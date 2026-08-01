<?php

declare(strict_types=1);

function pcf_public_page_cache_start(int $ttlSeconds = 120): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    if (function_exists('auth_user') && auth_user()) {
        return;
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/');
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $excludedScripts = [
        'login0718.php',
        'forgot_password.php',
        'reset_password.php',
        'setup_check.php',
        'ranking_refresh.php',
        'link_apply.php',
        'page.php',
    ];

    if (
        str_contains($requestPath, '/admin/')
        || str_contains($requestPath, '/api/')
        || $scriptName === 'page_view_beacon.php'
        || in_array($scriptName, $excludedScripts, true)
        || isset($_GET['pcf_nocache'])
    ) {
        if (in_array($scriptName, $excludedScripts, true)) {
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
        }
        return;
    }

    $ttlSeconds = max(30, min(600, $ttlSeconds));
    $cacheDirectory = dirname(__DIR__) . '/storage/cache/public-pages';
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        return;
    }
    if (!is_writable($cacheDirectory)) {
        return;
    }

    $viewportCookie = (string)($_COOKIE['pcf_viewport'] ?? '');
    $clientHintMobile = (string)($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isMobile = $viewportCookie === 'sp'
        || $clientHintMobile === '?1'
        || ($userAgent !== '' && preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|webOS/i', $userAgent));

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $variant = $isMobile ? 'sp' : 'pc';
    $cacheQuery = [];
    parse_str((string)(parse_url($requestUri, PHP_URL_QUERY) ?? ''), $cacheQuery);
    foreach (array_keys($cacheQuery) as $queryKey) {
        if (str_starts_with(strtolower((string)$queryKey), 'utm_')
            || in_array(strtolower((string)$queryKey), ['gclid', 'fbclid', 'yclid', 'ref'], true)
        ) {
            unset($cacheQuery[$queryKey]);
        }
    }
    ksort($cacheQuery);
    $normalizedRequestUri = $requestPath;
    $normalizedQuery = http_build_query($cacheQuery);
    if ($normalizedQuery !== '') {
        $normalizedRequestUri .= '?' . $normalizedQuery;
    }
    $cacheKey = hash('sha256', 'v7|' . $host . '|' . $variant . '|' . $normalizedRequestUri);
    $cacheFile = $cacheDirectory . '/' . $cacheKey . '.html';

    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttlSeconds) {
        $content = @file_get_contents($cacheFile);
        if (is_string($content) && $content !== '') {
            header('X-PCF-Page-Cache: HIT');
            if ($method !== 'HEAD') {
                echo $content;
            }
            exit;
        }
    }

    header('X-PCF-Page-Cache: MISS');
    ob_start();

    register_shutdown_function(static function () use ($cacheFile, $cacheDirectory, $method, $scriptName): void {
        if (ob_get_level() < 1) {
            return;
        }

        $content = ob_get_clean();
        if (!is_string($content)) {
            return;
        }

        $status = http_response_code();
        if ($status === false) {
            $status = 200;
        }

        if ($status === 200 && $content !== '') {
            if ($scriptName === 'item.php' && str_contains($content, '</body>')) {
                $beaconUrl = function_exists('public_url') ? public_url('page_view_beacon.php') : 'page_view_beacon.php';
                $beaconScript = '<script>(()=>{try{const p=new URLSearchParams(location.search);const b=new URLSearchParams();for(const k of ["id","content_id","cid"]){const v=p.get(k);if(v)b.set(k,v);}if([...b].length){navigator.sendBeacon(' . json_encode($beaconUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',b);}}catch(e){}})();</script>';
                $content = str_replace('</body>', $beaconScript . '</body>', $content);
            }

            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (Throwable) {
                $suffix = uniqid('', true);
            }
            $temporaryFile = $cacheDirectory . '/.' . basename($cacheFile) . '.' . $suffix . '.tmp';
            if (@file_put_contents($temporaryFile, $content, LOCK_EX) !== false) {
                @rename($temporaryFile, $cacheFile);
            } else {
                @unlink($temporaryFile);
            }
        }

        if ($method !== 'HEAD') {
            echo $content;
        }
    });
}


function pcf_public_page_cache_clear(): int
{
    $cacheDirectory = dirname(__DIR__) . '/storage/cache/public-pages';
    if (!is_dir($cacheDirectory)) {
        return 0;
    }

    $deleted = 0;
    foreach (glob($cacheDirectory . '/*') ?: [] as $cacheFile) {
        if (is_file($cacheFile) && @unlink($cacheFile)) {
            $deleted++;
        }
    }
    return $deleted;
}
