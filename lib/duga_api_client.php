<?php

declare(strict_types=1);

final class DugaApiClient
{
    public function __construct(
        private readonly string $appId,
        private readonly string $agentId,
        private readonly string $bannerId,
        private readonly string $endpoint,
        private readonly string $referer
    ) {
    }

    public function fetchItems(array $params = []): array
    {
        if (trim($this->appId) === '' || trim($this->agentId) === '') {
            throw new RuntimeException('DUGAのアプリケーションIDまたは代理店IDが未設定です。');
        }

        $endpoint = rtrim(trim($this->endpoint), '?');
        $endpointParts = parse_url($endpoint);
        if (!is_array($endpointParts)
            || strtolower((string)($endpointParts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($endpointParts['host'] ?? '')) !== 'affapi.duga.jp'
            || isset($endpointParts['user'])
            || isset($endpointParts['pass'])
        ) {
            throw new RuntimeException('DUGA APIの接続先設定が正しくありません。');
        }

        $query = array_filter(array_merge([
            'version' => '1.2',
            'appid' => $this->appId,
            'agentid' => $this->agentId,
            'bannerid' => $this->bannerId,
            'format' => 'json',
            'adult' => 1,
        ], $params), static fn (mixed $value): bool => $value !== null && $value !== '');

        $url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $safeUrl = $endpoint . '?' . http_build_query($this->maskSensitiveParams($query), '', '&', PHP_QUERY_RFC3986);
        $requestHash = hash('sha256', $url);

        $cached = $this->fetchCachedResponse($requestHash);
        if ($cached !== null) {
            return $cached;
        }

        $rateLimitLock = $this->acquireRateLimitLock();
        $ch = curl_init($url);
        if ($ch === false) {
            $this->releaseRateLimitLock($rateLimitLock);
            throw new RuntimeException('DUGA API接続の初期化に失敗しました。');
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (filter_var($this->referer, FILTER_VALIDATE_URL) !== false) {
            $refererParts = parse_url($this->referer);
            if (is_array($refererParts) && in_array(strtolower((string)($refererParts['scheme'] ?? '')), ['http', 'https'], true)) {
                $curlOptions[CURLOPT_REFERER] = $this->referer;
            }
        }
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $this->releaseRateLimitLock($rateLimitLock);
        if ($response === false) {
            curl_close($ch);
            $this->insertApiLog('DUGA ItemList', $safeUrl, $requestHash, 0, '{"error":"connection_failed"}', false);
            throw new RuntimeException('DUGA APIへの接続に失敗しました。');
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->insertApiLog('DUGA ItemList', $safeUrl, $requestHash, $httpCode, $response, false);

        if ($httpCode >= 300) {
            throw new RuntimeException('DUGA APIエラー（HTTP ' . $httpCode . '）が発生しました。');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('DUGA APIのJSONを読み取れませんでした。');
        }

        if (isset($decoded['error'])) {
            throw new RuntimeException('DUGA APIからエラーが返されました。');
        }

        return $decoded;
    }

    private function acquireRateLimitLock()
    {
        $handle = @fopen(sys_get_temp_dir() . '/pinkclub-duga-duga-api.lock', 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            usleep(1000000);
            return null;
        }

        rewind($handle);
        $lastRequestAt = (float)trim((string)stream_get_contents($handle));
        $waitMicroseconds = (int)ceil((1.0 - (microtime(true) - $lastRequestAt)) * 1000000);
        if ($lastRequestAt > 0 && $waitMicroseconds > 0) {
            usleep($waitMicroseconds);
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, sprintf('%.6F', microtime(true)));
        fflush($handle);

        return $handle;
    }

    private function releaseRateLimitLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function maskSensitiveParams(array $query): array
    {
        if (array_key_exists('appid', $query)) {
            $query['appid'] = '***';
        }
        if (array_key_exists('agentid', $query)) {
            $query['agentid'] = '***';
        }
        return $query;
    }

    private function fetchCachedResponse(string $requestHash): ?array
    {
        if (!function_exists('db')) {
            return null;
        }

        $stmt = db()->prepare('SELECT response_body FROM api_logs WHERE request_hash = :request_hash AND response_status = 200 AND cache_hit = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1');
        $stmt->execute([':request_hash' => $requestHash]);
        $body = $stmt->fetchColumn();
        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function insertApiLog(string $apiName, string $requestUrl, string $requestHash, int $status, string $responseBody, bool $cacheHit): void
    {
        if (!function_exists('db')) {
            return;
        }

        $isSuccess = $status >= 200 && $status < 300;
        $bodyLimit = $isSuccess ? 65535 : 4096;
        $body = mb_substr($responseBody, 0, $bodyLimit);
        try {
            $stmt = db()->prepare('INSERT INTO api_logs (api_name, endpoint, request_params, request_url, request_hash, response_status, status_code, response_body, cache_hit, is_success, message, created_at) VALUES (:api_name, :endpoint, :request_params, :request_url, :request_hash, :response_status, :status_code, :response_body, :cache_hit, :is_success, :message, NOW())');
            $stmt->execute([
                ':api_name' => $apiName,
                ':endpoint' => 'search',
                ':request_params' => json_encode(['url' => $requestUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':request_url' => $requestUrl,
                ':request_hash' => $requestHash,
                ':response_status' => $status,
                ':status_code' => $status,
                ':response_body' => $body,
                ':cache_hit' => $cacheHit ? 1 : 0,
                ':is_success' => $isSuccess ? 1 : 0,
                ':message' => $cacheHit ? 'cache' : ($isSuccess ? 'ok' : 'error'),
            ]);
            return;
        } catch (Throwable) {
            error_log('duga_api_log status=extended_insert_failed');
        }

        try {
            $stmt = db()->prepare('INSERT INTO api_logs (api_name, request_url, request_hash, response_status, response_body, cache_hit, created_at) VALUES (:api_name, :request_url, :request_hash, :response_status, :response_body, :cache_hit, NOW())');
            $stmt->execute([
                ':api_name' => $apiName,
                ':request_url' => $requestUrl,
                ':request_hash' => $requestHash,
                ':response_status' => $status,
                ':response_body' => $body,
                ':cache_hit' => $cacheHit ? 1 : 0,
            ]);
        } catch (Throwable) {
            error_log('duga_api_log status=legacy_insert_failed');
        }
    }
}