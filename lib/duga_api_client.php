<?php

declare(strict_types=1);

final class DugaApiClient
{
    public function __construct(
        private readonly string $appId,
        private readonly string $agentId,
        private readonly string $bannerId,
        private readonly string $endpoint
    ) {
    }

    public function fetchItems(array $params = []): array
    {
        if (trim($this->appId) === '' || trim($this->agentId) === '') {
            throw new RuntimeException('DUGAのアプリケーションIDまたは代理店IDが未設定です。');
        }

        $query = array_filter(array_merge([
            'version' => '1.2',
            'appid' => $this->appId,
            'agentid' => $this->agentId,
            'bannerid' => $this->bannerId,
            'format' => 'json',
            'adult' => 1,
        ], $params), static fn (mixed $value): bool => $value !== null && $value !== '');

        $url = rtrim($this->endpoint, '?') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $safeUrl = rtrim($this->endpoint, '?') . '?' . http_build_query($this->maskSensitiveParams($query), '', '&', PHP_QUERY_RFC3986);
        $requestHash = hash('sha256', $url);

        $cached = $this->fetchCachedResponse($requestHash);
        if ($cached !== null) {
            $this->insertApiLog('DUGA ItemList', $safeUrl, $requestHash, 200, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', true);
            return $cached;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->insertApiLog('DUGA ItemList', $safeUrl, $requestHash, 0, json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', false);
            throw new RuntimeException('DUGA APIへの接続に失敗しました: ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->insertApiLog('DUGA ItemList', $safeUrl, $requestHash, $httpCode, $response, false);

        if ($httpCode >= 400) {
            throw new RuntimeException('DUGA APIエラー（HTTP ' . $httpCode . '）: ' . $this->redactSensitiveText(mb_substr(trim($response), 0, 1000), $query));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('DUGA APIのJSONを読み取れませんでした。');
        }

        if (isset($decoded['error'])) {
            $message = is_scalar($decoded['error']) ? (string)$decoded['error'] : (json_encode($decoded['error'], JSON_UNESCAPED_UNICODE) ?: '不明なエラー');
            throw new RuntimeException('DUGA APIエラー: ' . $this->redactSensitiveText($message, $query));
        }

        return $decoded;
    }

    private function maskSensitiveParams(array $query): array
    {
        if (array_key_exists('appid', $query)) {
            $query['appid'] = '***';
        }
        return $query;
    }

    private function redactSensitiveText(string $text, array $query): string
    {
        $appId = trim((string)($query['appid'] ?? ''));
        return $appId === '' ? $text : str_replace($appId, '***', $text);
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

        $body = mb_substr($responseBody, 0, 65535);
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
                ':is_success' => ($status >= 200 && $status < 400) ? 1 : 0,
                ':message' => $cacheHit ? 'cache' : (($status >= 200 && $status < 400) ? 'ok' : 'error'),
            ]);
            return;
        } catch (Throwable $e) {
            error_log('api_logs extended insert failed, fallback to legacy columns: ' . $e->getMessage());
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
        } catch (Throwable $e) {
            error_log('api_logs legacy insert failed: ' . $e->getMessage());
        }
    }
}
