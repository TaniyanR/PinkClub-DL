<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
$title = 'アクセス解析';
analytics_ensure_tables();

$publicCacheDirectory = dirname(__DIR__) . '/storage/cache/public-pages';
$publicCacheWritable = is_dir($publicCacheDirectory) && is_writable($publicCacheDirectory);
$publicCacheFiles = is_dir($publicCacheDirectory) ? glob($publicCacheDirectory . '/*.html') : [];
$publicCacheFileCount = is_array($publicCacheFiles) ? count($publicCacheFiles) : 0;
$publicCacheStatusLabel = $publicCacheWritable ? ($publicCacheFileCount > 0 ? '正常に動作中' : '準備完了（キャッシュ未作成）') : '要確認（保存先へ書き込めません）';
$logRetentionDays = (int)(setting_get('analytics.cleanup.retention_days', '730') ?? '730');
$lastLogCleanupAt = trim((string)(setting_get('analytics.cleanup.last_success_at', '') ?? ''));
$lastLogCleanupRows = (int)(setting_get('analytics.cleanup.last_deleted_rows', '0') ?? '0');

$migrationNotice = '';
try {
    $appliedMigrationCount = installer_apply_migrations(dirname(__DIR__) . '/sql/migrations', 'admin_access_analytics');
    $migrationNotice = $appliedMigrationCount > 0
        ? '高速化用DB更新を' . $appliedMigrationCount . '件適用しました。'
        : '高速化用DB更新は適用済みです。';
} catch (Throwable $e) {
    $migrationNotice = '高速化用DB更新を適用できませんでした。logs/install.log を確認してください。';
    installer_log_exception('admin_access_analytics', $e);
}

$tab = (string)get('tab', 'graph');
$allowedTabs = ['graph','referrer','destination','engine','keyword','duration'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'graph';
}

$periodKey = (string)get('period', 'week');
$days = match ($periodKey) {
    'day' => 1,
    'month' => 30,
    default => 7,
};

$to = new DateTimeImmutable('today');
$from = $to->sub(new DateInterval('P' . ($days - 1) . 'D'));
$prevFrom = $from->sub(new DateInterval('P' . $days . 'D'));
$prevTo = $from->sub(new DateInterval('P1D'));

$rowsByDate = [];
for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
    $rowsByDate[$day->format('Y-m-d')] = ['stat_date' => $day->format('Y-m-d'), 'pv' => 0, 'uu' => 0, 'in_count' => 0, 'out_count' => 0];
}

$dailyStmt = db()->prepare('SELECT stat_date,pv,uu,in_count,out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to ORDER BY stat_date');
$dailyStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $d = (string)($r['stat_date'] ?? '');
    if (isset($rowsByDate[$d])) {
        $rowsByDate[$d] = [
            'stat_date' => $d,
            'pv' => (int)$r['pv'],
            'uu' => (int)$r['uu'],
            'in_count' => (int)$r['in_count'],
            'out_count' => (int)$r['out_count'],
        ];
    }
}
$rows = array_values($rowsByDate);

$prevStmt = db()->prepare('SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(in_count),0) in_count,COALESCE(SUM(out_count),0) out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to');
$prevStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['pv'=>0,'in_count'=>0,'out_count'=>0];
$periodUuStmt = db()->prepare('SELECT COUNT(DISTINCT visitor_hash) FROM visit_sessions WHERE stat_date BETWEEN :from AND :to');
$periodUuStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
$periodUu = (int)$periodUuStmt->fetchColumn();
$periodUuStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev['uu'] = (int)$periodUuStmt->fetchColumn();

$sum = ['pv'=>0,'uu'=>0,'in_count'=>0,'out_count'=>0];
foreach ($rows as $r) { $sum['pv']+=(int)$r['pv']; $sum['uu']+=(int)$r['uu']; $sum['in_count']+=(int)$r['in_count']; $sum['out_count']+=(int)$r['out_count']; }
$sum['uu'] = $periodUu;

$refRows = [];
$outRows = [];
$engineRows = [];
$keywordRows = [];
if ($tab === 'referrer' || $tab === 'engine' || $tab === 'keyword') {
    $refStmt = db()->prepare('SELECT referer_host,COUNT(*) cnt FROM in_logs WHERE created_at BETWEEN :from AND :to GROUP BY referer_host ORDER BY cnt DESC, referer_host ASC LIMIT 200');
    $refStmt->execute([':from' => $from->format('Y-m-d 00:00:00'), ':to' => $to->format('Y-m-d 23:59:59')]);
    $refRows = $refStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($tab === 'destination') {
    $outStmt = db()->prepare('SELECT target_url,COUNT(*) cnt FROM out_logs WHERE created_at BETWEEN :from AND :to GROUP BY target_url ORDER BY cnt DESC, target_url ASC LIMIT 200');
    $outStmt->execute([':from' => $from->format('Y-m-d 00:00:00'), ':to' => $to->format('Y-m-d 23:59:59')]);
    $outRows = $outStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($tab === 'engine') {
    foreach ($refRows as $row) {
        $host = strtolower((string)($row['referer_host'] ?? ''));
        $cnt = (int)($row['cnt'] ?? 0);
        if ($host === '') { continue; }
        if (str_contains($host, 'google.')) { $engine = 'Google'; }
        elseif (str_contains($host, 'yahoo.')) { $engine = 'Yahoo'; }
        elseif (str_contains($host, 'bing.')) { $engine = 'Bing'; }
        elseif (str_contains($host, 'duckduckgo.')) { $engine = 'DuckDuckGo'; }
        else { continue; }
        if (!isset($engineRows[$engine])) { $engineRows[$engine] = 0; }
        $engineRows[$engine] += $cnt;
    }
    arsort($engineRows);
}
if ($tab === 'keyword') {
    $kwStmt = db()->prepare("SELECT path, COUNT(*) cnt FROM site_events WHERE event_type = 'pv' AND session_id_hash = :marker AND path LIKE '/search.php?%' AND created_at BETWEEN :from AND :to GROUP BY path ORDER BY cnt DESC, path ASC LIMIT 500");
    $kwStmt->execute([':marker' => analytics_beacon_marker_hash(), ':from' => $from->format('Y-m-d 00:00:00'), ':to' => $to->format('Y-m-d 23:59:59')]);
    $kwRaw = $kwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $kwCounts = [];
    foreach ($kwRaw as $kwRow) {
        $kwQs = [];
        parse_str(ltrim((string)strstr((string)($kwRow['path'] ?? ''), '?'), '?'), $kwQs);
        $kwWord = trim((string)($kwQs['q'] ?? ''));
        if ($kwWord !== '') {
            $kwCounts[$kwWord] = ($kwCounts[$kwWord] ?? 0) + (int)$kwRow['cnt'];
        }
    }
    arsort($kwCounts);
    $keywordRows = [];
    foreach (array_slice($kwCounts, 0, 200, true) as $kwWord => $kwCnt) {
        $keywordRows[] = ['keyword' => $kwWord, 'cnt' => $kwCnt];
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>アクセス解析</h1>
  <?php if ($tab === 'graph' && $migrationNotice !== ''): ?>
    <p><?= e($migrationNotice) ?></p>
  <?php endif; ?>
  <?php if ($tab === 'graph'): ?>
  <div class="admin-status-grid">
    <article class="admin-card admin-status-card">
      <strong>公開ページキャッシュ</strong>
      <p><?= e($publicCacheStatusLabel) ?></p>
      <small>保存済みページ: <?= e((string)$publicCacheFileCount) ?>件</small>
    </article>
    <article class="admin-card admin-status-card">
      <strong>アクセス生ログ保存期間</strong>
      <p><?= e((string)$logRetentionDays) ?>日</p>
      <small>最終整理: <?= e($lastLogCleanupAt !== '' ? $lastLogCleanupAt : '未実行') ?> / 前回削除: <?= e((string)$lastLogCleanupRows) ?>件</small>
    </article>
  </div>
  <?php endif; ?>
  <?php if ($tab === 'graph'): ?>
  <div class="admin-status-grid">
    <article class="admin-card admin-status-card"><strong>ページビュー</strong><p><?= e((string)$sum['pv']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>推定ユニークユーザー</strong><p><?= e((string)$sum['uu']) ?></p><small>選択期間内のIPハッシュ重複を除外</small></article>
    <article class="admin-card admin-status-card"><strong>流入数</strong><p><?= e((string)$sum['in_count']) ?></p></article>
    <article class="admin-card admin-status-card"><strong>流出数</strong><p><?= e((string)$sum['out_count']) ?></p></article>
  </div>
  <p>前期間比較: ページビュー <?= e((string)($sum['pv'] - (int)$prev['pv'])) ?> / ユニークユーザー <?= e((string)($sum['uu'] - (int)$prev['uu'])) ?> / 流入数 <?= e((string)($sum['in_count'] - (int)$prev['in_count'])) ?> / 流出数 <?= e((string)($sum['out_count'] - (int)$prev['out_count'])) ?></p>
  <?php elseif ($tab === 'referrer'): ?>
  <table class="admin-table"><tr><th>リンク元</th><th>アクセス数</th></tr><?php foreach ($refRows as $r): ?><tr><td><?= e((string)$r['referer_host']) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'destination'): ?>
  <table class="admin-table"><tr><th>クリック先</th><th>クリック数</th></tr><?php foreach ($outRows as $r): ?><tr><td><?= e((string)$r['target_url']) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'engine'): ?>
  <table class="admin-table"><tr><th>検索エンジン</th><th>アクセス数</th></tr><?php foreach ($engineRows as $name => $cnt): ?><tr><td><?= e((string)$name) ?></td><td><?= e((string)$cnt) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'keyword'): ?>
  <table class="admin-table"><tr><th>検索キーワード</th><th>件数</th></tr><?php foreach ($keywordRows as $r): ?><tr><td><?= e((string)($r['keyword'] ?? '')) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?><?php if ($keywordRows === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></table>
  <?php elseif ($tab === 'duration'): ?>
  <p>要確認: 既存ログテーブルに滞在時間を計算する開始/終了時刻の対応データが無いため、現状では算出できません。</p>
  <?php endif; ?>

  <?php if ($tab === 'graph'): ?>
  <h2>日別PV / UU（棒グラフ）</h2><p class="analytics-bars__legend"><span class="analytics-bars__legend-pv">PV</span><span class="analytics-bars__legend-uu">UU</span></p><div class="analytics-bars analytics-bars--vertical"><?php $maxCount = 0; foreach ($rows as $barRow) { $maxCount = max($maxCount, (int)$barRow['pv'], (int)$barRow['uu']); } $maxCount = max($maxCount, 1); ?><?php foreach ($rows as $barRow): $pv = (int)$barRow['pv']; $uu = (int)$barRow['uu']; $pvRatio = (int)round(($pv / $maxCount) * 100); $uuRatio = (int)round(($uu / $maxCount) * 100); ?><div class="analytics-bars__col"><div class="analytics-bars__values"><span class="analytics-bars__value">PV <?= e((string)$pv) ?></span><span class="analytics-bars__value">UU <?= e((string)$uu) ?></span></div><div class="analytics-bars__pair"><div class="analytics-bars__track"><span class="analytics-bars__fill" style="height: <?= e((string)$pvRatio) ?>%;"></span></div><div class="analytics-bars__track"><span class="analytics-bars__fill analytics-bars__fill--uu" style="height: <?= e((string)$uuRatio) ?>%;"></span></div></div><span class="analytics-bars__date"><?= e((string)date('m/d', strtotime((string)$barRow['stat_date']))) ?></span></div><?php endforeach; ?></div>
  <table class="admin-table"><tr><th>日付</th><th>PV</th><th>UU</th><th>流入</th><th>流出</th></tr><?php foreach ($rows as $r): ?><tr><td><?= e((string)$r['stat_date']) ?></td><td><?= e((string)$r['pv']) ?></td><td><?= e((string)$r['uu']) ?></td><td><?= e((string)$r['in_count']) ?></td><td><?= e((string)$r['out_count']) ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
