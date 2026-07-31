<?php
declare(strict_types=1);

/**
 * Polyfills for environments where mbstring extension is disabled.
 * We only need safe truncation/length for validation/logging.
 */

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        // Fallback: byte length
        return strlen($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        if ($length === null) {
            return substr($string, $start);
        }

        return substr($string, $start, $length);
    }
}
