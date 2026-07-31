<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';

$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

/**
 * 想定外URLの吸収
 * - /admin/index.php/public... に来たら /admin/index.php に 301 で戻す
 * - /admin/index.php/xxxx のように index.php の後ろに余計なパスが付いたら 404
 */
if ($requestPath !== '' && preg_match('#/admin/index\.php/public(?:/.*)?$#', $requestPath) === 1) {
    header('Location: ' . app_url('/admin/index.php'), true, 301);
    exit;
}

if ($requestPath !== '' && preg_match('#/admin/index\.php/(.+)$#', $requestPath) === 1) {
    http_response_code(404);
    exit('Not Found');
}

auth_require_admin();

$title = 'ダッシュボード';
$tables = ['items'];
$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int) db()->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
}
$labels = [
    'items' => '商品',
];
$pvStats = [
    'today' => 0,
    'yesterday' => 0,
    'last7' => 0,
    'thisMonth' => 0,
    'last3Months' => 0,
    'total' => 0,
];
try {
    analytics_ensure_tables();
    $pvStmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END),0) AS today,
        COALESCE(SUM(CASE WHEN DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END),0) AS yesterday,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END),0) AS last7,
        COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END),0) AS thisMonth,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END),0) AS last3Months,
        COALESCE(COUNT(*),0) AS total
        FROM site_events WHERE event_type = 'pv' AND session_id_hash = :marker");
    $pvStmt->execute([':marker' => analytics_beacon_marker_hash()]);
    $pvRow = $pvStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($pvStats as $key => $value) {
        $pvStats[$key] = (int)($pvRow[$key] ?? 0);
    }
} catch (Throwable $e) {
}
$errorStats = [
    'today' => 0,
    'last24' => 0,
    'last7' => 0,
    'last3Months' => 0,
];
$latestError = null;
$failedLogs = [];
try {
    $errorStmt = db()->query("SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END),0) AS today,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END),0) AS last24,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END),0) AS last7,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END),0) AS last3Months
        FROM sync_logs
        WHERE is_success = 0");
    $errorRow = $errorStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($errorStats as $key => $value) {
        $errorStats[$key] = (int)($errorRow[$key] ?? 0);
    }
    $latestError = db()->query('SELECT * FROM sync_logs WHERE is_success = 0 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $failedLogs = db()->query('SELECT * FROM sync_logs WHERE is_success = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) ORDER BY id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) {
    $failedLogs = [];
}
$errorStatus = '正常';
if ($latestError === null) {
    $errorStatus = 'データなし';
} elseif ($errorStats['today'] > 0 || $errorStats['last24'] > 0) {
    $errorStatus = 'エラーあり';
} elseif ($errorStats['last7'] > 0 || $errorStats['last3Months'] > 0) {
    $errorStatus = '注意';
}
$popularPages = [];
try {
    $popularStmt = db()->query('SELECT i.id, i.content_id, i.title, COUNT(pv.id) AS access_count FROM page_views pv INNER JOIN items i ON i.id = pv.item_id WHERE pv.viewed_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) GROUP BY i.id, i.content_id, i.title ORDER BY access_count DESC, i.id DESC LIMIT 5');
    $popularPages = $popularStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $popularPages = [];
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-dashboard-hero">
  <p class="admin-dashboard-hero__eyebrow">Overview</p>
  <h1>ダッシュボード</h1>
  <p class="admin-form-note">アクセス数と直近のエラー状況を中心に確認できます。</p>
</section>

<section class="admin-card admin-dashboard-section">
  <h2>アクセス概要</h2>
  <div class="admin-status-grid">
    <article class="admin-card admin-status-card"><strong>本日のPV</strong><p><?= e((string)$pvStats['today']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>昨日のPV</strong><p><?= e((string)$pvStats['yesterday']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>直近7日間PV</strong><p><?= e((string)$pvStats['last7']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>今月PV</strong><p><?= e((string)$pvStats['thisMonth']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>直近3ヶ月PV</strong><p><?= e((string)$pvStats['last3Months']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>総PV</strong><p><?= e((string)$pvStats['total']) ?></p></article>
  </div>
</section>

<section class="admin-card admin-dashboard-section">
  <h2>エラー概要</h2>
  <div class="admin-status-grid">
    <article class="admin-card admin-status-card"><strong>本日のエラー数</strong><p><?= e((string)$errorStats['today']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>直近24時間のエラー数</strong><p><?= e((string)$errorStats['last24']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>直近7日間のエラー数</strong><p><?= e((string)$errorStats['last7']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>直近3ヶ月のエラー数</strong><p><?= e((string)$errorStats['last3Months']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>最終エラー</strong><p><?= e($latestError !== null ? (string)$latestError['created_at'] : 'データなし') ?></p></article>
    <article class="admin-card admin-status-card"><strong>状態</strong><p><?= e($errorStatus) ?></p></article>
  </div>
  <?php if ($latestError !== null): ?>
    <p class="admin-form-note">最終エラー内容: <?= e((string)$latestError['message']) ?></p>
  <?php endif; ?>
</section>

<section class="admin-card admin-dashboard-section">
  <h2>直近エラーログ</h2>
  <?php if ($failedLogs): ?>
  <table class="admin-table">
    <tr><th>ID</th><th>種別</th><th>メッセージ</th><th>時刻</th></tr>
    <?php foreach ($failedLogs as $log): ?>
      <tr>
        <td><?= e($log['id']) ?></td>
        <td><?= e($log['sync_type']) ?></td>
        <td><?= e($log['message']) ?></td>
        <td><?= e($log['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p>現在、直近のエラーはありません。</p>
  <?php endif; ?>
</section>

<section class="admin-card admin-dashboard-section">
  <h2>人気ページ TOP5</h2>
  <?php if ($popularPages): ?>
  <table class="admin-table">
    <tr><th>商品</th><th>アクセス数</th></tr>
    <?php foreach ($popularPages as $page): ?>
      <tr><td><?= e((string)$page['title']) ?></td><td><?= e((string)$page['access_count']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p>人気ページ表示はページ別アクセス集計の既存実装が要確認、または直近3ヶ月のデータがありません。</p>
  <?php endif; ?>
</section>

<section class="admin-card admin-dashboard-section">
  <h2>同期対象データ件数</h2>
  <table class="admin-table">
    <tr><th>項目</th><th>件数</th></tr>
    <?php foreach ($counts as $key => $count): ?>
      <tr><td><?= e($labels[$key] ?? $key) ?></td><td><?= e((string)$count) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
