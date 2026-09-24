<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/social_image_endpoint.php';

pcf_social_image_run([
    'service_key' => 'pinkclub-duga',
    'allowed_hosts' => ['duga.jp'],
    'candidate_fields' => ['image_large', 'image_small', 'image_list', 'full_package_url', 'package_image_url', 'main_image_url', 'image_url'],
    'raw_candidate_fields' => ['jacketimage', 'packageimagelarge', 'packageimage', 'package', 'jacket', 'packageImage', 'poster', 'posterimage', 'imageURL'],
    'referer' => 'https://duga.jp/',
    'user_agent' => 'Mozilla/5.0 (compatible; PinkClub-DL-SocialCard/2.0; +https://pinkclub-dl.com/)',
]);
