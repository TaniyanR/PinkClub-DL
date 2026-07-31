<?php

declare(strict_types=1);

function csrf_cookie_name(): string
{
    return 'pinkclub_duga_csrf';
}

function csrf_cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function csrf_set_cookie(string $token): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(csrf_cookie_name(), $token, [
        'expires' => time() + 86400,
        'path' => '/',
        'domain' => '',
        'secure' => csrf_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[csrf_cookie_name()] = $token;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $cookieToken = $_COOKIE[csrf_cookie_name()] ?? '';
        $_SESSION['_csrf'] = is_string($cookieToken) && preg_match('/\A[a-f0-9]{64}\z/', $cookieToken) === 1
            ? $cookieToken
            : bin2hex(random_bytes(32));
    }
    csrf_set_cookie((string)$_SESSION['_csrf']);
    return $_SESSION['_csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    $known = $_SESSION['_csrf'] ?? '';
    if (is_string($known) && $known !== '' && hash_equals($known, $token)) {
        csrf_set_cookie($known);
        return true;
    }

    $cookieToken = $_COOKIE[csrf_cookie_name()] ?? '';
    if (is_string($cookieToken) && $cookieToken !== '' && hash_equals($cookieToken, $token)) {
        $_SESSION['_csrf'] = $cookieToken;
        csrf_set_cookie($cookieToken);
        return true;
    }

    return false;
}

function csrf_validate_or_fail(?string $token): void
{
    if (!csrf_verify($token)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}
