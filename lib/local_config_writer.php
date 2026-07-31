<?php
declare(strict_types=1);

function local_config_path(): string
{
    $root = realpath(__DIR__ . '/..');
    return ($root !== false ? $root : dirname(__DIR__)) . '/config.local.php';
}

function local_config_load(): array
{
    $path = local_config_path();
    if (!is_file($path)) {
        return [];
    }

    $loaded = require $path;
    return is_array($loaded) ? $loaded : [];
}

function local_config_write(array $local): void
{
    $path = local_config_path();
    $dir = dirname($path);
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if (!is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリが存在しません: ' . $dir);
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('保存先ディレクトリに書き込みできません: ' . $dir);
    }

    if (is_file($path) && !is_writable($path)) {
        throw new RuntimeException('設定ファイルに書き込みできません: ' . $path);
    }

    $export = "<?php\n";
    $export .= "declare(strict_types=1);\n\n";
    $export .= 'return ' . var_export($local, true) . ";\n";

    $result = @file_put_contents($tmp, $export, LOCK_EX);
    if ($result === false) {
        $error = error_get_last();
        throw new RuntimeException('設定ファイルの一時ファイル作成に失敗しました: ' . ($error['message'] ?? $tmp));
    }

    if (!@rename($tmp, $path)) {
        $error = error_get_last();
        @unlink($tmp);
        throw new RuntimeException('設定ファイルの反映(rename)に失敗しました: ' . ($error['message'] ?? $path));
    }

    @chmod($path, 0640);
}
