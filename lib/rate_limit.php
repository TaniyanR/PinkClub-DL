<?php

declare(strict_types=1);

function rate_limit_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if ($ip === '') {
        return 'unknown';
    }

    return $ip;
}

function rate_limit_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pinkclub_duga_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

function rate_limit_allow(string $action, int $maxAttempts = 5, int $windowSeconds = 60): bool
{
    if ($maxAttempts <= 0 || $windowSeconds <= 0) {
        return true;
    }

    $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $action);
    if (!is_string($key) || $key === '') {
        $key = 'default';
    }

    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key . '|' . rate_limit_client_ip()) . '.json';
    $now = time();
    $attempts = [];

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return true;
    }

    if (@flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (is_array($decoded)) {
            foreach ($decoded as $timestamp) {
                $timestamp = (int)$timestamp;
                if ($timestamp > $now - $windowSeconds) {
                    $attempts[] = $timestamp;
                }
            }
        }

        if (count($attempts) >= $maxAttempts) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return false;
        }

        $attempts[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($attempts));
        fflush($handle);
        @flock($handle, LOCK_UN);
    }

    @fclose($handle);
    return true;
}

function rate_limit_check(string $action, int $maxAttempts = 5, int $windowSeconds = 60): void
{
    if (rate_limit_allow($action, $maxAttempts, $windowSeconds)) {
        return;
    }

    http_response_code(429);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Too Many Requests';
    exit;
}
