<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';

function init_db(): array
{
    $result = installer_run();
    if (($result['success'] ?? false) !== true) {
        throw new RuntimeException((string)($result['error'] ?? 'セットアップに失敗しました。'));
    }
    return $result;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = init_db();
        fwrite(STDOUT, sprintf("DB初期化が完了しました。（完了ステップ: %d）\n", count($result['steps'] ?? [])));
        if (isset($result['initial_credentials']) && is_array($result['initial_credentials'])) {
            fwrite(STDOUT, '初期管理者: ' . (string)$result['initial_credentials']['username'] . "\n");
            fwrite(STDOUT, '初期パスワード: ' . (string)$result['initial_credentials']['password'] . "\n");
            fwrite(STDOUT, "初回ログイン後、個人設定でログインIDとパスワードを変更してください。\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "DB初期化に失敗しました: " . $e->getMessage() . "\n");
        exit(1);
    }
}
