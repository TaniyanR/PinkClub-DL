<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
$title = 'アクセス解析';
analytics_ensure_tables();

$migrationNotice = '';
try {
    $appliedMigrationCount = installer_apply_migrations(dirname(__DIR__) . '/sql/migrations', 'admin_access_analytics_v2');
    $migrationNotice = $appliedMigrationCount > 0
        ? 'アクセス解析用DB更新を' . $appliedMigrationCount . '件適用しました。'
        : 'アクセス解析用DB更新は適用済みです。';
} catch (Throwable $e) {
    $migrationNotice = 'アクセス解析用DB更新を適用できませんでした。logs/install.log を確認してください。';
    installer_log_exception('admin_access_analytics_v2', $e);
}

$tab = (string)get('tab', 'graph');
$allowedTabs = ['graph', 'referrer', 'destination', 'engine', 'keyword', 'duration'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'graph';
}

$periodKey = (string)get('period', 'week');
$days = match ($periodKey) {
    'day' => 1,
    'month' => 30,
    'quarter' => 90,
    'year' => 365,
    default => 7,
};
$periodKey = match ($days) {
    1 => 'day',
    30 => 'month',
    90 => 'quarter',
    365 => 'year',
    default => 'week',
};

$to = new DateTimeImmutable('today');
$from = $to->sub(new DateInterval('P' . ($days - 1) . 'D'));
$prevFrom = $from->sub(new DateInterval('P' . $days . 'D'));
$prevTo = $from->sub(new DateInterval('P1D'));
$fromTs = $from->format('Y-m-d 00:00:00');
$toTs = $to->format('Y-m-d 23:59:59');
$prevFromTs = $prevFrom->format('Y-m-d 00:00:00');
$prevToTs = $prevTo->format('Y-m-d 23:59:59');

$rowsByDate = [];
for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
    $date = $day->format('Y-m-d');
    $rowsByDate[$date] = ['stat_date' => $date, 'pv' => 0, 'uu' => 0, 'in_count' => 0, 'out_count' => 0];
}

$dailyStmt = db()->prepare('SELECT stat_date,pv,uu,in_count,out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to ORDER BY stat_date');
$dailyStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $date = (string)($r['stat_date'] ?? '');
    if (!isset($rowsByDate[$date])) {
        continue;
    }
    $rowsByDate[$date] = [
        'stat_date' => $date,
        'pv' => (int)($r['pv'] ?? 0),
        'uu' => (int)($r['uu'] ?? 0),
        'in_count' => (int)($r['in_count'] ?? 0),
        'out_count' => (int)($r['out_count'] ?? 0),
    ];
}
$rows = array_values($rowsByDate);

$sumStmt = db()->prepare('SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(in_count),0) in_count,COALESCE(SUM(out_count),0) out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to');
$sumStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['pv' => 0, 'in_count' => 0, 'out_count' => 0];
$sum = ['pv' => (int)$sum['pv'], 'in_count' => (int)$sum['in_count'], 'out_count' => (int)$sum['out_count'], 'uu' => 0];

$periodUuStmt = db()->prepare('SELECT COUNT(DISTINCT visitor_hash) FROM visit_sessions WHERE stat_date BETWEEN :from AND :to');
$periodUuStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
$sum['uu'] = (int)$periodUuStmt->fetchColumn();

$prevStmt = db()->prepare('SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(in_count),0) in_count,COALESCE(SUM(out_count),0) out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to');
$prevStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['pv' => 0, 'in_count' => 0, 'out_count' => 0];
$periodUuStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev['uu'] = (int)$periodUuStmt->fetchColumn();

$refRows = [];
$outRows = [];
$engineRows = [];
$keywordRows = [];
$durationRows = [];
$durationSummary = ['views' => 0, 'avg_duration' => 0, 'avg_active' => 0, 'avg_scroll' => 0, 'engaged' => 0, 'short' => 0];
$topPages = [];
$suspiciousPvVisitors = 0;
$suspiciousOutVisitors = 0;

if ($tab === 'referrer' || $tab === 'engine') {
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(referer_host,''),'（不明）') referer_host, COUNT(*) cnt FROM in_logs WHERE created_at BETWEEN :from AND :to GROUP BY referer_host ORDER BY cnt DESC, referer_host ASC LIMIT 200");
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $refRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($tab === 'destination') {
    $stmt = db()->prepare('SELECT target_url,COUNT(*) cnt FROM out_logs WHERE created_at BETWEEN :from AND :to GROUP BY target_url ORDER BY cnt DESC,target_url ASC LIMIT 200');
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $outRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($tab === 'engine') {
    foreach ($refRows as $row) {
        $host = strtolower((string)($row['referer_host'] ?? ''));
        $count = (int)($row['cnt'] ?? 0);
        $engine = null;
        if (str_contains($host, 'google.')) $engine = 'Google';
        elseif (str_contains($host, 'yahoo.')) $engine = 'Yahoo!';
        elseif (str_contains($host, 'bing.')) $engine = 'Bing';
        elseif (str_contains($host, 'duckduckgo.')) $engine = 'DuckDuckGo';
        elseif (str_contains($host, 'baidu.')) $engine = 'Baidu';
        elseif (str_contains($host, 'yandex.')) $engine = 'Yandex';
        if ($engine !== null) {
            $engineRows[$engine] = ($engineRows[$engine] ?? 0) + $count;
        }
    }
    arsort($engineRows);
}

if ($tab === 'keyword') {
    $stmt = db()->prepare("SELECT path,COUNT(*) cnt FROM site_events WHERE event_type='pv' AND session_id_hash=:marker AND path LIKE '%/search.php?%' AND created_at BETWEEN :from AND :to GROUP BY path ORDER BY cnt DESC,path ASC LIMIT 1000");
    $stmt->execute([':marker' => analytics_beacon_marker_hash(), ':from' => $fromTs, ':to' => $toTs]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $query = (string)parse_url((string)($row['path'] ?? ''), PHP_URL_QUERY);
        $params = [];
        parse_str($query, $params);
        $word = trim((string)($params['q'] ?? ''));
        if ($word !== '') {
            $counts[$word] = ($counts[$word] ?? 0) + (int)($row['cnt'] ?? 0);
        }
    }
    arsort($counts);
    foreach (array_slice($counts, 0, 200, true) as $word => $count) {
        $keywordRows[] = ['keyword' => $word, 'cnt' => $count];
    }
}

if (db_table_exists('analytics_page_engagement')) {
    $stmt = db()->prepare('SELECT COUNT(*) views,COALESCE(AVG(duration_seconds),0) avg_duration,COALESCE(AVG(active_seconds),0) avg_active,COALESCE(AVG(max_scroll_percent),0) avg_scroll,SUM(CASE WHEN active_seconds >= 10 THEN 1 ELSE 0 END) engaged,SUM(CASE WHEN duration_seconds < 10 THEN 1 ELSE 0 END) short FROM analytics_page_engagement WHERE viewed_at BETWEEN :from AND :to');
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $durationSummary = [
        'views' => (int)($d['views'] ?? 0),
        'avg_duration' => (int)round((float)($d['avg_duration'] ?? 0)),
        'avg_active' => (int)round((float)($d['avg_active'] ?? 0)),
        'avg_scroll' => (int)round((float)($d['avg_scroll'] ?? 0)),
        'engaged' => (int)($d['engaged'] ?? 0),
        'short' => (int)($d['short'] ?? 0),
    ];

    if ($tab === 'duration') {
        $stmt = db()->prepare('SELECT path,COUNT(*) views,ROUND(AVG(duration_seconds)) avg_duration,ROUND(AVG(active_seconds)) avg_active,ROUND(AVG(max_scroll_percent)) avg_scroll FROM analytics_page_engagement WHERE viewed_at BETWEEN :from AND :to GROUP BY path ORDER BY avg_active DESC,views DESC LIMIT 200');
        $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
        $durationRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if ($tab === 'graph') {
    $stmt = db()->prepare("SELECT path,COUNT(*) cnt FROM site_events WHERE event_type='pv' AND session_id_hash=:marker AND created_at BETWEEN :from AND :to GROUP BY path ORDER BY cnt DESC,path ASC LIMIT 20");
    $stmt->execute([':marker' => analytics_beacon_marker_hash(), ':from' => $fromTs, ':to' => $toTs]);
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = db()->prepare("SELECT COUNT(*) FROM (SELECT ip_hash FROM site_events WHERE event_type='pv' AND session_id_hash=:marker AND created_at BETWEEN :from AND :to GROUP BY ip_hash,DATE(created_at) HAVING COUNT(*) > 200) x");
    $stmt->execute([':marker' => analytics_beacon_marker_hash(), ':from' => $fromTs, ':to' => $toTs]);
    $suspiciousPvVisitors = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM (SELECT ip_hash FROM site_events WHERE event_type='out' AND created_at BETWEEN :from AND :to GROUP BY ip_hash,DATE(created_at) HAVING COUNT(*) > 80) x");
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $suspiciousOutVisitors = (int)$stmt->fetchColumn();
}

$fmtSeconds = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) return $seconds . '秒';
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    if ($minutes < 60) return $minutes . '分' . ($rest > 0 ? $rest . '秒' : '');
    $hours = intdiv($minutes, 60);
    $minutes %= 60;
    return $hours . '時間' . ($minutes > 0 ? $minutes . '分' : '');
};

$delta = static function (int $current, int $previous): string {
    $diff = $current - $previous;
    return ($diff > 0 ? '+' : '') . number_format($diff);
};

$queryBase = static function (string $targetTab, string $targetPeriod) use ($periodKey): string {
    return admin_url('analytics.php') . '?' . http_build_query(['tab' => $targetTab, 'period' => $targetPeriod !== '' ? $targetPeriod : $periodKey]);
};

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>アクセス解析</h1>
  <p class="admin-form-note">管理者・既知Bot/クローラー・自動ブラウザ・外部送信を除外し、実ブラウザのPV/UU/流入/流出と滞在状況を集計します。</p>

  <div class="admin-actions" style="margin-bottom:14px">
    <?php foreach (['day' => '今日', 'week' => '7日', 'month' => '30日', 'quarter' => '90日', 'year' => '365日'] as $key => $label): ?>
      <a class="button-secondary" href="<?= e($queryBase($tab, $key)) ?>" <?= $periodKey === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'graph'): ?>
    <p class="flash success"><?= e($migrationNotice) ?></p>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>ページビュー</strong><p><?= e(number_format($sum['pv'])) ?></p><small>前期間 <?= e($delta($sum['pv'], (int)$prev['pv'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>ユニークユーザー</strong><p><?= e(number_format($sum['uu'])) ?></p><small>期間内の匿名化IPハッシュ重複を除外 / 前期間 <?= e($delta($sum['uu'], (int)$prev['uu'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>流入</strong><p><?= e(number_format($sum['in_count'])) ?></p><small>前期間 <?= e($delta($sum['in_count'], (int)$prev['in_count'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>流出</strong><p><?= e(number_format($sum['out_count'])) ?></p><small>同一訪問者・同一遷移先の日次重複を除外 / 前期間 <?= e($delta($sum['out_count'], (int)$prev['out_count'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>平均PV / UU</strong><p><?= e($sum['uu'] > 0 ? number_format($sum['pv'] / $sum['uu'], 2) : '0.00') ?></p><small>回遊の目安</small></article>
      <article class="admin-card admin-status-card"><strong>平均アクティブ時間</strong><p><?= e($fmtSeconds($durationSummary['avg_active'])) ?></p><small>タブが表示されていた時間のみ</small></article>
      <article class="admin-card admin-status-card"><strong>平均スクロール</strong><p><?= e((string)$durationSummary['avg_scroll']) ?>%</p><small>新しい計測データから集計</small></article>
      <article class="admin-card admin-status-card"><strong>異常アクセス候補</strong><p><?= e(number_format($suspiciousPvVisitors + $suspiciousOutVisitors)) ?></p><small>1日200PV超または80流出超の匿名訪問者。自動遮断ではなく監査用です。</small></article>
    </div>

    <h2>日別 PV / UU</h2>
    <table class="admin-table">
      <thead><tr><th>日付</th><th>PV</th><th>UU（日次）</th><th>流入</th><th>流出</th></tr></thead>
      <tbody><?php foreach ($rows as $row): ?><tr><td><?= e((string)$row['stat_date']) ?></td><td><?= e(number_format((int)$row['pv'])) ?></td><td><?= e(number_format((int)$row['uu'])) ?></td><td><?= e(number_format((int)$row['in_count'])) ?></td><td><?= e(number_format((int)$row['out_count'])) ?></td></tr><?php endforeach; ?></tbody>
    </table>

    <h2>よく見られたページ</h2>
    <table class="admin-table"><thead><tr><th>ページ</th><th>PV</th></tr></thead><tbody><?php foreach ($topPages as $row): ?><tr><td><?= e((string)($row['path'] ?? '')) ?></td><td><?= e(number_format((int)($row['cnt'] ?? 0))) ?></td></tr><?php endforeach; ?><?php if ($topPages === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table>

  <?php elseif ($tab === 'referrer'): ?>
    <table class="admin-table"><thead><tr><th>リンク元</th><th>流入数</th></tr></thead><tbody><?php foreach ($refRows as $row): ?><tr><td><?= e((string)$row['referer_host']) ?></td><td><?= e(number_format((int)$row['cnt'])) ?></td></tr><?php endforeach; ?><?php if ($refRows === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table>

  <?php elseif ($tab === 'destination'): ?>
    <table class="admin-table"><thead><tr><th>クリック先</th><th>流出数</th></tr></thead><tbody><?php foreach ($outRows as $row): ?><tr><td style="word-break:break-all"><?= e((string)$row['target_url']) ?></td><td><?= e(number_format((int)$row['cnt'])) ?></td></tr><?php endforeach; ?><?php if ($outRows === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table>

  <?php elseif ($tab === 'engine'): ?>
    <table class="admin-table"><thead><tr><th>検索エンジン</th><th>流入数</th></tr></thead><tbody><?php foreach ($engineRows as $name => $count): ?><tr><td><?= e((string)$name) ?></td><td><?= e(number_format((int)$count)) ?></td></tr><?php endforeach; ?><?php if ($engineRows === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table>

  <?php elseif ($tab === 'keyword'): ?>
    <p class="admin-form-note">ここではサイト内検索キーワードを集計します。Googleなど外部検索の検索語は、検索エンジン側がRefererに送らないため正確には取得できません。</p>
    <table class="admin-table"><thead><tr><th>サイト内検索キーワード</th><th>PV</th></tr></thead><tbody><?php foreach ($keywordRows as $row): ?><tr><td><?= e((string)$row['keyword']) ?></td><td><?= e(number_format((int)$row['cnt'])) ?></td></tr><?php endforeach; ?><?php if ($keywordRows === []): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table>

  <?php elseif ($tab === 'duration'): ?>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>計測ページ数</strong><p><?= e(number_format($durationSummary['views'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均滞在時間</strong><p><?= e($fmtSeconds($durationSummary['avg_duration'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均アクティブ時間</strong><p><?= e($fmtSeconds($durationSummary['avg_active'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均スクロール</strong><p><?= e((string)$durationSummary['avg_scroll']) ?>%</p></article>
      <article class="admin-card admin-status-card"><strong>10秒以上閲覧</strong><p><?= e(number_format($durationSummary['engaged'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>10秒未満</strong><p><?= e(number_format($durationSummary['short'])) ?></p><small>直帰率ではなく短時間閲覧の目安です。</small></article>
    </div>
    <table class="admin-table"><thead><tr><th>ページ</th><th>計測数</th><th>平均滞在</th><th>平均アクティブ</th><th>平均スクロール</th></tr></thead><tbody><?php foreach ($durationRows as $row): ?><tr><td><?= e((string)$row['path']) ?></td><td><?= e(number_format((int)$row['views'])) ?></td><td><?= e($fmtSeconds((int)$row['avg_duration'])) ?></td><td><?= e($fmtSeconds((int)$row['avg_active'])) ?></td><td><?= e((string)(int)$row['avg_scroll']) ?>%</td></tr><?php endforeach; ?><?php if ($durationRows === []): ?><tr><td colspan="5">新しい滞在時間データはまだありません。デプロイ後の実ブラウザアクセスから記録されます。</td></tr><?php endif; ?></tbody></table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

