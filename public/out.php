<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$to = trim((string)($_GET['to'] ?? ''));
$ref = trim((string)($_GET['ref'] ?? ''));
$path = (string)($_SERVER['REQUEST_URI'] ?? '/out.php');
$resolvedMutualLink = false;

// backward compatibility: ?id=nnn
$id = (int)($_GET['id'] ?? 0);
if ($to === '' && $id > 0) {
    $st = db()->prepare('SELECT link_url, site_url, ref_code FROM mutual_links WHERE id = :id AND status = "approved" LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $to = (string)($row['link_url'] ?? $row['site_url'] ?? '');
        $resolvedMutualLink = true;
        if ($ref === '') {
            $ref = (string)($row['ref_code'] ?? '');
        }
    }
}

$valid = filter_var($to, FILTER_VALIDATE_URL) !== false;
$scheme = strtolower((string)parse_url($to, PHP_URL_SCHEME));
$host = strtolower((string)parse_url($to, PHP_URL_HOST));
$isDugaDestination = $host === 'duga.jp'
    || $host === 'duga.co.jp'
    || $host === 'duga.com'
    || str_ends_with($host, '.duga.jp')
    || str_ends_with($host, '.duga.co.jp')
    || str_ends_with($host, '.duga.com');
if (!$valid || !in_array($scheme, ['http', 'https'], true) || (!$resolvedMutualLink && !$isDugaDestination)) {
    header('Location: ' . app_url('/'), true, 302);
    exit;
}

try {
    analytics_log_out($to, $ref, $path);
} catch (Throwable $e) {
    error_log('out.php tracking error: ' . $e->getMessage());
}

header('Location: ' . $to, true, 302);
exit;
