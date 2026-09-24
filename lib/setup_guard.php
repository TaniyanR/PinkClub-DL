<?php
declare(strict_types=1);

function setup_guard_marker_path(): string
{
    return __DIR__ . '/../storage/install/installed.lock';
}

function setup_guard_marker_exists(): bool
{
    return is_file(setup_guard_marker_path());
}

function setup_guard_mark_installed(): bool
{
    $path = setup_guard_marker_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('[setup] unable to create installed-state directory');
        return false;
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $suffix = uniqid('', true);
    }

    $tmp = $path . '.tmp-' . $suffix;
    $payload = "PinkClub installed\n" . date('c') . "\n";
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function setup_guard_log_has_completion_evidence(): bool
{
    if (!function_exists('installer_log_file_path')) {
        return false;
    }
    $path = installer_log_file_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) {
        return false;
    }
    $readSize = min($size, 65536);
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    if ($size > $readSize) {
        @fseek($handle, -$readSize, SEEK_END);
    }
    $tail = (string)@fread($handle, $readSize);
    fclose($handle);
    return str_contains($tail, 'step=completed status=ok');
}

function setup_guard_db_has_completion_evidence(): bool
{
    try {
        if (!db_can_connect() || !db_table_exists('admins') || !db_table_exists('settings')) {
            return false;
        }
        $adminStmt = db()->query('SELECT 1 FROM admins ORDER BY id ASC LIMIT 1');
        if ($adminStmt === false || $adminStmt->fetchColumn() === false) {
            return false;
        }
        $readyStmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $readyStmt->execute([':key' => 'installer.ready']);
        return (string)($readyStmt->fetchColumn() ?: '') === '1';
    } catch (Throwable) {
        return false;
    }
}

function setup_guard_is_known_installed(): bool
{
    if (setup_guard_marker_exists()) {
        return true;
    }
    if (setup_guard_log_has_completion_evidence() || setup_guard_db_has_completion_evidence()) {
        setup_guard_mark_installed();
        return true;
    }
    return false;
}

function setup_guard_bootstrap_installed_marker(): void
{
    if (PHP_SAPI === 'cli' || setup_guard_marker_exists()) {
        return;
    }
    if (setup_guard_log_has_completion_evidence() || setup_guard_db_has_completion_evidence()) {
        setup_guard_mark_installed();
    }
}

function setup_guard_recovery_is_authorized(): bool
{
    try {
        $user = function_exists('auth_user') ? auth_user() : null;
        if (is_array($user) && (int)($user['id'] ?? 0) > 0) {
            return true;
        }
    } catch (Throwable) {
    }
    return function_exists('installer_is_local_request') && installer_is_local_request();
}

function setup_guard_enforce_for_setup_page(): void
{
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    if (setup_guard_is_known_installed() && !setup_guard_recovery_is_authorized()) {
        if (function_exists('app_redirect')) {
            app_redirect(LOGIN_PATH);
        }
        header('Location: ' . LOGIN_PATH, true, 302);
        exit;
    }

    register_shutdown_function(static function (): void {
        if (setup_guard_marker_exists()) {
            return;
        }
        if (setup_guard_db_has_completion_evidence()) {
            setup_guard_mark_installed();
        }
    });
}
