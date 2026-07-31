<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
header('Location: ' . admin_url('api_items.php'));
exit;
