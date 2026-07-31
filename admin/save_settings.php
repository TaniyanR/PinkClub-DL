<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('admin/settings.php');
}
csrf_validate_or_fail(post('_csrf'));
settings_save(trim((string)post('api_id','')), trim((string)post('affiliate_id','')));
flash_set('success', '設定を保存しました。');
app_redirect('admin/settings.php');
