<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

header('Content-Type: application/xml; charset=UTF-8');

function sitemap_e(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function sitemap_url(string $loc, string $changefreq, string $priority): void
{
    echo "  <url>\n";
    echo '    <loc>' . sitemap_e($loc) . "</loc>\n";
    echo '    <changefreq>' . sitemap_e($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sitemap_e($priority) . "</priority>\n";
    echo "  </url>\n";
}

function sitemap_table_count(string $table, string $where = ''): int
{
    try {
        if (!db_table_exists($table)) return 0;
        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($where !== '') $sql .= ' WHERE ' . $where;
        return (int)db()->query($sql)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function sitemap_emit_table(string $table, string $path, string $changefreq, string $priority, int $start, int &$remaining, string $where = ''): int
{
    $count = sitemap_table_count($table, $where);
    if ($remaining <= 0 || $start >= $count) return $count;

    $limit = min($remaining, $count - $start);
    try {
        $sql = 'SELECT id FROM ' . $table;
        if ($where !== '') $sql .= ' WHERE ' . $where;
        $sql .= ' ORDER BY id ASC LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            sitemap_url(public_url($path) . '?id=' . rawurlencode((string)$id), $changefreq, $priority);
            $remaining--;
        }
    } catch (Throwable) {
    }
    return $count;
}

$perSitemap = 10000;
$staticUrls = [
    [public_url('index.php'), 'daily', '1.0'],
    [public_url('items.php'), 'daily', '0.9'],
];
$tables = [
    ['items', 'item.php', 'weekly', '0.8', items_product_source_where()],
];
$totalUrls = count($staticUrls);
foreach ($tables as $table) {
    $totalUrls += sitemap_table_count((string)$table[0], (string)($table[4] ?? ''));
}
$totalParts = max(1, (int)ceil($totalUrls / $perSitemap));

if ((isset($_GET['index']) && (string)$_GET['index'] === '1') || ($totalUrls > $perSitemap && !isset($_GET['part']))) {
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    for ($i = 1; $i <= $totalParts; $i++) {
        echo "  <sitemap>\n";
        echo '    <loc>' . sitemap_e(public_url('sitemap.php') . '?part=' . $i) . "</loc>\n";
        echo "  </sitemap>\n";
    }
    echo "</sitemapindex>\n";
    return;
}

$part = 1;
if (isset($_GET['part'])) {
    $validatedPart = filter_var($_GET['part'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $totalParts],
    ]);
    if ($validatedPart === false) {
        http_response_code(404);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"/>\n";
        return;
    }
    $part = $validatedPart;
}
$start = ($part - 1) * $perSitemap;
$remaining = $perSitemap;

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

foreach ($staticUrls as $index => $url) {
    if ($index < $start) continue;
    if ($remaining <= 0) break;
    sitemap_url((string)$url[0], (string)$url[1], (string)$url[2]);
    $remaining--;
}
$start = max(0, $start - count($staticUrls));

foreach ($tables as $table) {
    $count = sitemap_emit_table((string)$table[0], (string)$table[1], (string)$table[2], (string)$table[3], $start, $remaining, (string)($table[4] ?? ''));
    $start = max(0, $start - $count);
}

echo "</urlset>\n";
