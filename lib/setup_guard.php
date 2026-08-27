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
    if (@file_put_contents($tmp, "PinkClub installed\n" . date('c') . "\n", LOCK_EX) === false) {
        error_log('[setup] unable to write installed-state marker');
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        error_log('[setup] unable to publish installed-state marker');
        return false;
    }
    return true;
}

function setup_guard_redact_diagnostic_text(string $text): string
{
    foreach ([
        '/\binitial_password=\S+/u' => 'initial_password=[REDACTED]',
        '/\b(?:password|passwd|pass|pwd)=\S+/iu' => 'password=[REDACTED]',
        '/\blocation=\S+:\d+/u' => 'location=[REDACTED]',
        '/\bfailed_sql=.*?(?=\r?\n|$)/u' => 'failed_sql=[REDACTED]',
        '/\bsql=.*?(?=\r?\n|$)/u' => 'sql=[REDACTED]',
    ] as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }
    return $text;
}

function setup_guard_capture_initial_password_once(string $raw): void
{
    if (session_status() !== PHP_SESSION_ACTIVE
        || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        || (string)($_POST['action'] ?? '') !== 'run_installer') {
        return;
    }
    if (preg_match_all('/\binitial_password=([^\s]+)/u', $raw, $matches) < 1) return;
    $passwords = $matches[1] ?? [];
    $password = is_array($passwords) && $passwords !== [] ? (string)end($passwords) : '';
    if ($password !== '' && $password !== '[REDACTED]') {
        $_SESSION['setup_initial_password_once'] = $password;
    }
}

function setup_guard_sanitize_installer_diagnostics(): void
{
    if (function_exists('installer_log_file_path')) {
        $path = installer_log_file_path();
        if (is_file($path) && is_readable($path) && is_writable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw)) {
                setup_guard_capture_initial_password_once($raw);
                $safe = setup_guard_redact_diagnostic_text($raw);
                if ($safe !== $raw) @file_put_contents($path, $safe, LOCK_EX);
            }
        }
    }
    if (function_exists('installer_last_error_file_path')) {
        $path = installer_last_error_file_path();
        if (is_file($path) && is_readable($path) && is_writable($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (is_array($decoded)) {
                unset($decoded['file'], $decoded['line'], $decoded['failed_sql']);
                if (isset($decoded['message'])) {
                    $decoded['message'] = 'セットアップ処理に失敗しました。入力内容とサーバー設定を確認してください。';
                }
                @file_put_contents($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
    }
}

function setup_guard_log_has_completion_evidence(): bool
{
    if (!function_exists('installer_log_file_path')) return false;
    $path = installer_log_file_path();
    if (!is_file($path) || !is_readable($path)) return false;
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) return false;
    $readSize = min($size, 65536);
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return false;
    if ($size > $readSize) @fseek($handle, -$readSize, SEEK_END);
    $tail = (string)@fread($handle, $readSize);
    fclose($handle);
    return str_contains($tail, 'step=completed status=ok');
}

function setup_guard_db_has_completion_evidence(): bool
{
    try {
        if (!db_can_connect() || !db_table_exists('admins') || !db_table_exists('settings')) return false;
        $adminStmt = db()->query('SELECT 1 FROM admins ORDER BY id ASC LIMIT 1');
        if ($adminStmt === false || $adminStmt->fetchColumn() === false) return false;
        $readyStmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $readyStmt->execute([':key' => 'installer.ready']);
        return (string)($readyStmt->fetchColumn() ?: '') === '1';
    } catch (Throwable) {
        return false;
    }
}

function setup_guard_is_known_installed(): bool
{
    if (setup_guard_marker_exists()) return true;
    if (setup_guard_log_has_completion_evidence() || setup_guard_db_has_completion_evidence()) {
        setup_guard_mark_installed();
        return true;
    }
    return false;
}

function setup_guard_bootstrap_installed_marker(): void
{
    if (setup_guard_marker_exists() || PHP_SAPI === 'cli') return;
    if (setup_guard_log_has_completion_evidence() || setup_guard_db_has_completion_evidence()) setup_guard_mark_installed();
}

function setup_guard_recovery_is_authorized(): bool
{
    $user = function_exists('auth_user') ? auth_user() : null;
    if (is_array($user) && (int)($user['id'] ?? 0) > 0) return true;
    return function_exists('installer_is_local_request') && installer_is_local_request();
}

function setup_guard_enforce_for_setup_page(): void
{
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    setup_guard_sanitize_installer_diagnostics();
    ob_start('setup_guard_redact_diagnostic_text');
    if (setup_guard_is_known_installed() && !setup_guard_recovery_is_authorized()) {
        if (function_exists('app_redirect')) app_redirect(LOGIN_PATH);
        header('Location: ' . LOGIN_PATH, true, 302);
        exit;
    }
    register_shutdown_function(static function (): void {
        setup_guard_sanitize_installer_diagnostics();
        if (!setup_guard_marker_exists() && setup_guard_db_has_completion_evidence()) setup_guard_mark_installed();
    });
}
