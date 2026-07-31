<?php
declare(strict_types=1);

require_once __DIR__ . '/polyfills.php';

function config_base(): array
{
    static $base = null;
    if (is_array($base)) {
        return $base;
    }

    $path = __DIR__ . '/../config.php';
    $base = is_file($path) ? require $path : [];

    if (!is_array($base)) {
        $base = [];
    }

    return $base;
}

function config_local(): array
{
    static $local = null;
    if (is_array($local)) {
        return $local;
    }

    $path = __DIR__ . '/../config.local.php';
    if (!is_file($path)) {
        $local = [];
        return $local;
    }

    $local = require $path;
    if (!is_array($local)) {
        $local = [];
    }

    return $local;
}

function config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    // base + local（localが上書き）
    $config = array_replace_recursive(config_base(), config_local());

    // timezone（一度だけ）
    date_default_timezone_set('Asia/Tokyo');

    // 互換吸収：古い `api` が残っていたら `duga_api` に寄せる
    if (isset($config['api']) && !isset($config['duga_api']) && is_array($config['api'])) {
        $config['duga_api'] = $config['api'];
    }
    unset($config['api']);

    return $config;
}

function config_get(string $path, mixed $default = null): mixed
{
    $segments = explode('.', $path);
    $value = config();

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}
