<?php

declare(strict_types=1);

/**
 * Allow remote HTTP requests only to publicly routable hosts.
 *
 * Every resolved address is checked because a syntactically valid hostname can
 * still point at loopback, link-local, or private infrastructure.
 */
function http_url_is_public(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return false;
    }

    $host = trim((string)($parts['host'] ?? ''), '[]');
    if ($host === '' || strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.localhost')) {
        return false;
    }

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $addresses[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return false;
        }
        foreach ($records as $record) {
            $address = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            if ($address !== '') {
                $addresses[] = $address;
            }
        }
    }

    if ($addresses === []) {
        return false;
    }

    foreach (array_unique($addresses) as $address) {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }
    }

    return true;
}

/**
 * Fetch a bounded response without following redirects implicitly.
 * Redirect targets receive the same SSRF validation as the original URL.
 */
function http_fetch_public(string $url, int $timeoutSeconds = 3, int $maxBytes = 1048576, int $maxRedirects = 2): ?string
{
    $timeoutSeconds = max(1, min(10, $timeoutSeconds));
    $maxBytes = max(1, min(5242880, $maxBytes));
    $currentUrl = trim($url);

    for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
        if (!http_url_is_public($currentUrl)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'user_agent' => 'PinkClubRSS/1.0',
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ]);
        $handle = @fopen($currentUrl, 'rb', false, $context);
        if (!is_resource($handle)) {
            return null;
        }

        $metadata = stream_get_meta_data($handle);
        $headers = is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [];
        $status = 0;
        $location = '';
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string)$header, $matches) === 1) {
                $status = (int)$matches[1];
            } elseif (stripos((string)$header, 'Location:') === 0) {
                $location = trim(substr((string)$header, 9));
            }
        }

        if ($status >= 300 && $status < 400 && $location !== '') {
            fclose($handle);
            if ($redirect >= $maxRedirects) {
                return null;
            }
            if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
                $currentUrl = $location;
                continue;
            }
            $base = parse_url($currentUrl);
            if (!is_array($base) || !str_starts_with($location, '/')) {
                return null;
            }
            $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
            $currentUrl = (string)$base['scheme'] . '://' . (string)$base['host'] . $port . $location;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            fclose($handle);
            return null;
        }

        $body = stream_get_contents($handle, $maxBytes + 1);
        fclose($handle);
        if (!is_string($body) || $body === '' || strlen($body) > $maxBytes) {
            return null;
        }

        return $body;
    }

    return null;
}
