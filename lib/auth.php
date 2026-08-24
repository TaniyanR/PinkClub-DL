<?php

declare(strict_types=1);

function auth_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function auth_set_last_error(?string $error): void
{
    $GLOBALS['auth_last_error'] = $error;
}

function auth_last_error(): ?string
{
    $error = $GLOBALS['auth_last_error'] ?? null;
    return is_string($error) ? $error : null;
}

function auth_credentials_are_personalized(): bool
{
    if (function_exists('setting_get') && setting_get('auth.credentials_personalized', '0') === '1') {
        return true;
    }

    // Older releases saved the username, email and password without the
    // credentials_personalized flag. Recognize that completed configuration
    // instead of showing a false "initial credentials" warning.
    $user = auth_user();
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    if ($userId <= 0 || !function_exists('setting_admin_email')) {
        return false;
    }

    $email = trim(setting_admin_email(''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT username, password_hash FROM admins WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $username = trim((string)($row['username'] ?? ''));
        $passwordHash = (string)($row['password_hash'] ?? '');
        return $username !== ''
            && strcasecmp($username, 'admin') !== 0
            && $passwordHash !== ''
            && !password_verify('password', $passwordHash);
    } catch (Throwable) {
        return false;
    }
}

function auth_default_username_is_disabled(): bool
{
    try {
        $stmt = db()->query("SELECT 1 FROM admins WHERE username <> 'admin' LIMIT 1");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function auth_login_id_validation_error(string $loginId): ?string
{
    $loginId = trim($loginId);
    if ($loginId === '') {
        return 'ログインIDを入力してください。';
    }
    if (mb_strlen($loginId, 'UTF-8') < 4 || mb_strlen($loginId, 'UTF-8') > 50) {
        return 'ログインIDは4文字以上50文字以内で入力してください。';
    }
    if (strcasecmp($loginId, 'admin') === 0) {
        return '「admin」は初期値のため、ログインIDには使用できません。';
    }

    return null;
}

function auth_password_validation_error(string $password, string $loginId = ''): ?string
{
    if (strlen($password) < 12) {
        return 'パスワードは12文字以上で入力してください。';
    }
    if (strcasecmp($password, 'password') === 0) {
        return '「password」は初期値のため使用できません。';
    }
    if ($loginId !== '' && hash_equals(mb_strtolower($loginId, 'UTF-8'), mb_strtolower($password, 'UTF-8'))) {
        return 'ログインIDと同じパスワードは使用できません。';
    }

    return null;
}

function auth_verify_password_for_user(int $userId, string $password): bool
{
    if ($userId <= 0 || $password === '') {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    } catch (Throwable) {
        return false;
    }
}

function auth_attempt(string $username, string $password): bool
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    auth_set_last_error(null);

    $usesDefaultUsername = strcasecmp(trim($username), 'admin') === 0;
    if (($usesDefaultUsername && auth_default_username_is_disabled())
        || (auth_credentials_are_personalized()
            && ($usesDefaultUsername || strcasecmp($password, 'password') === 0))
    ) {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();
    } catch (PDOException|RuntimeException $exception) {
        auth_set_last_error('db_error');
        if (function_exists('installer_log')) {
            installer_log('auth db error: ' . $exception->getMessage());
        }
        return false;
    }

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
    ];

    return true;
}

function auth_require_admin(): void
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    if (!auth_user()) {
        app_redirect(LOGIN_PATH);
    }

    if ((installer_status()['completed'] ?? false) !== true) {
        app_redirect('/public/setup_check.php');
    }

    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
    }
}

function auth_logout(): void
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
