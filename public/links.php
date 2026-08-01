<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/partials/_helpers.php';

function public_links_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return $stmt->fetchColumn() !== false;
}

$hasIsEnabled = public_links_column_exists('mutual_links', 'is_enabled');
$hasDisplayOrder = public_links_column_exists('mutual_links', 'display_order');

$from = (int)($_GET['from'] ?? 0);
if ($from > 0) {
    $ipHash = hash('sha256', ((string)($_SERVER['REMOTE_ADDR'] ?? '')) . (string)config_get('security.ip_hash_salt', 'pinkclub-default-salt'));
    $stmt = db()->prepare('INSERT INTO access_events(event_type,event_at,path,referrer,link_id,ip_hash) VALUES("link_in",NOW(),:p,:r,:id,:ip)');
    $stmt->execute([
        ':p' => (string)($_SERVER['REQUEST_URI'] ?? '/links.php'),
        ':r' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
        ':id' => $from,
        ':ip' => $ipHash,
    ]);
}

$where = ["status='approved'"];
if ($hasIsEnabled) {
    $where[] = 'is_enabled=1';
}
$orderBy = $hasDisplayOrder ? 'display_order ASC, id ASC' : 'id ASC';
$sql = 'SELECT * FROM mutual_links WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy;
$rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'リンク集';
include __DIR__ . '/partials/header.php';
?>
<section class="block"><h1 class="section-title">リンク集</h1>
<ul>
<?php foreach ($rows as $r) : ?>
<li><a href="<?php echo e(public_url('out.php') . '?id=' . (string)$r['id']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e((string)$r['site_name']); ?></a></li>
<?php endforeach; ?>
<?php if ($rows === []) : ?><li>まだリンクがありません。</li><?php endif; ?>
</ul></section>
<?php include __DIR__ . '/partials/footer.php';
