<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect(ADMIN_HOME_PATH);
}

csrf_validate_or_fail((string)post('_csrf', ''));
auth_logout();
app_redirect(login_url());
