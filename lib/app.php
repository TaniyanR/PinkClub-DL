<?php

declare(strict_types=1);

require_once __DIR__ . '/duga_api_client.php';
require_once __DIR__ . '/duga_sync_service.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/api_credentials.php';
require_once __DIR__ . '/config.php';


function settings_normalize_token(string $value, string $fallback): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $fallback;
    }

    if (preg_match_all('/[A-Za-z][A-Za-z0-9_.-]*/', $trimmed, $matches) === 1 && !empty($matches[0])) {
        return (string)$matches[0][count($matches[0]) - 1];
    }

    return $fallback;
}

function settings_get(): array
{
    $defaults = app_config()['duga'] ?? [];

    $envApiId = trim((string)(getenv('DUGA_APP_ID') ?: ''));
    $envAgentId = trim((string)(getenv('DUGA_AGENT_ID') ?: ''));
    $envBannerId = trim((string)(getenv('DUGA_BANNER_ID') ?: ''));

    $itemCred = api_credential_get('items');
    $dbApiId = trim((string)($itemCred['api_id'] ?? ''));
    $dbAffiliateId = trim((string)($itemCred['affiliate_id'] ?? ''));

    return [
        'api_id' => $dbApiId !== '' ? $dbApiId : ($envApiId !== '' ? $envApiId : ''),
        'affiliate_id' => $dbAffiliateId !== '' ? $dbAffiliateId : ($envAgentId !== '' ? $envAgentId : ''),
        'banner_id' => settings_normalize_banner_id(site_setting_get('duga_banner_id', $envBannerId !== '' ? $envBannerId : (string)($defaults['banner_id'] ?? '01'))),
        'site' => 'DUGA',
        'service' => 'duga',
        'floor' => 'adult',
        'item_sync_batch' => settings_allowed_item_sync_batch(settings_int('item_sync_batch', 100)),
        'item_sync_enabled' => settings_bool('item_sync_enabled', false),
        'item_sync_interval_minutes' => settings_int('item_sync_interval_minutes', 60),
        'last_item_sync_at' => site_setting_get('last_item_sync_at', ''),
        'item_sync_offset' => settings_int('item_sync_offset', 1),
        'item_sync_test_offset' => settings_int('item_sync_test_offset', 1),
    ];
}

function settings_normalize_banner_id(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{1,2}$/', $value) === 1 ? str_pad($value, 2, '0', STR_PAD_LEFT) : '01';
}

function settings_int(string $key, int $default): int
{
    $value = site_setting_get($key, (string)$default);
    if (!preg_match('/^-?\d+$/', $value)) {
        return $default;
    }
    return (int)$value;
}

function settings_allowed_item_sync_batch(int $value): int
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($value, $allowed, true)) {
        return 100;
    }
    return $value;
}

function settings_bool(string $key, bool $default): bool
{
    return settings_int($key, $default ? 1 : 0) === 1;
}

function settings_save(string $apiId, string $affiliateId, int $itemSyncBatch = 100, ?string $bannerId = null): void
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($itemSyncBatch, $allowed, true)) {
        $itemSyncBatch = 100;
    }

    api_credential_set('items', trim($apiId), trim($affiliateId));
    $payload = ['item_sync_batch' => (string)$itemSyncBatch];
    if ($bannerId !== null) {
        $payload['duga_banner_id'] = settings_normalize_banner_id($bannerId);
    }

    site_setting_set_many($payload);
}

function duga_client_for_type(string $apiType): DugaApiClient
{
    $cred = api_credential_get($apiType);
    $settings = settings_get();
    $endpoint = (string)(app_config()['duga']['endpoint'] ?? 'https://affapi.duga.jp/search');
    return new DugaApiClient(
        (string)($cred['api_id'] ?? ''),
        (string)($cred['affiliate_id'] ?? ''),
        (string)($settings['banner_id'] ?? '01'),
        $endpoint,
        defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : ''
    );
}

function duga_client_from_settings(): DugaApiClient
{
    return duga_client_for_type('items');
}

function duga_sync_service(?string $apiType = null): DugaSyncService
{
    return new DugaSyncService($apiType === null ? duga_client_from_settings() : duga_client_for_type($apiType), db());
}

