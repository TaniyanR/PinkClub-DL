<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_counts.php';

auth_require_admin();

$title = '自動設定';
$message = '';
$messageType = 'success';

$intervalOptions = [10, 20, 30, 60, 120, 180, 360, 720];
$batchOptions = [1, 10, 20, 30, 50, 100, 200, 300, 500];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));

    $enabled = post('item_sync_enabled', '0') === '1' ? '1' : '0';
    $interval = (int)post('item_sync_interval_minutes', 60);
    if (!in_array($interval, $intervalOptions, true)) {
        $interval = 60;
    }

    $batch = (int)post('item_sync_batch', 100);
    if (!in_array($batch, $batchOptions, true)) {
        $batch = 100;
    }

    $compoundKeywords = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim((string)post('item_sync_compound_' . $i, ''));
        if ($value !== '') {
            $compoundKeywords[] = $value;
        }
    }

    $excludeKeywords = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim((string)post('item_sync_exclude_' . $i, ''));
        if ($value !== '') {
            $excludeKeywords[] = $value;
        }
    }

    site_setting_set_many([
        'item_sync_enabled' => $enabled,
        'item_sync_interval_minutes' => (string)$interval,
        'item_sync_batch' => (string)$batch,
        'item_sync_compound_keywords' => implode("\n", $compoundKeywords),
        'item_sync_exclude_keywords' => implode("\n", $excludeKeywords),
    ]);

    $pdo = db();
    scheduler_ensure_schedule_table($pdo);
    scheduler_seed_default_schedules($pdo);
    scheduler_apply_auto_settings($pdo);

    $message = '自動設定を保存しました。';
}

$settings = settings_get();
$currentInterval = (int)($settings['item_sync_interval_minutes'] ?? 60);
$currentBatch = (int)($settings['item_sync_batch'] ?? 100);
if (!in_array($currentBatch, $batchOptions, true)) {
    $currentBatch = 100;
}
$enabled = settings_bool('item_sync_enabled', false);
$compoundLines = preg_split('/\R/u', site_setting_get('item_sync_compound_keywords', '')) ?: [];
$excludeLines = preg_split('/\R/u', site_setting_get('item_sync_exclude_keywords', '')) ?: [];
$pdo = db();
scheduler_ensure_schedule_table($pdo);
scheduler_seed_default_schedules($pdo);
scheduler_apply_auto_settings($pdo);
$stateStmt = $pdo->query("SELECT job_key, last_run_at, last_success, last_message, next_offset, lock_until FROM sync_job_state WHERE job_key = 'items' ORDER BY job_key");
$autoStates = $stateStmt ? $stateStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$storedItemCount = null;
$publicItemCount = null;
$nonPublicItemCount = null;
try {
    if (db_table_exists('items')) {
        $storedStmt = $pdo->query('SELECT COUNT(*) FROM items');
        $storedItemCount = $storedStmt ? (int)$storedStmt->fetchColumn() : null;

        $publicWhere = items_product_source_where('items');
        $publicStmt = $pdo->query('SELECT COUNT(*) FROM items WHERE ' . $publicWhere);
        $publicItemCount = $publicStmt ? (int)$publicStmt->fetchColumn() : null;

        if ($storedItemCount !== null && $publicItemCount !== null) {
            $nonPublicItemCount = max(0, $storedItemCount - $publicItemCount);
        }
    }
} catch (Throwable) {
    $storedItemCount = null;
    $publicItemCount = null;
    $nonPublicItemCount = null;
}

$storedActressCount = null;
$publicActressCount = null;
$nonPublicActressCount = null;
try {
    if (db_table_exists('actresses')) {
        $storedActressStmt = $pdo->query('SELECT COUNT(*) FROM actresses');
        $storedActressCount = $storedActressStmt ? (int)$storedActressStmt->fetchColumn() : null;

        $publicCounts = pcf_public_counts();
        $publicActressCount = isset($publicCounts['actresses']) && $publicCounts['actresses'] !== null
            ? (int)$publicCounts['actresses']
            : null;

        if ($storedActressCount !== null && $publicActressCount !== null) {
            $nonPublicActressCount = max(0, $storedActressCount - $publicActressCount);
        }
    }
} catch (Throwable) {
    $storedActressCount = null;
    $publicActressCount = null;
    $nonPublicActressCount = null;
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>自動設定</h1>
  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>">
      <p><?= e($message) ?></p>
    </div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:980px;">
    <?= csrf_input() ?>

    <div class="admin-auto-grid" style="display:grid;grid-template-columns:160px minmax(280px,1fr);gap:12px 16px;align-items:center;">
      <div><strong>自動更新を有効化</strong></div>
      <div class="admin-auto-toggle" style="text-align:left;">
        <input type="hidden" name="item_sync_enabled" value="0">
        <label style="display:inline-flex;align-items:center;gap:10px;"><input type="checkbox" name="item_sync_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> <span>ON</span></label>
      </div>

      <div><strong>自動更新間隔（分）</strong></div>
      <div>
        <select name="item_sync_interval_minutes" style="width:100%;">
          <?php foreach ($intervalOptions as $value): ?>
            <option value="<?= e((string)$value) ?>" <?= $currentInterval === $value ? 'selected' : '' ?>><?= e((string)$value) ?>分</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div><strong>取得する記事数</strong></div>
      <div>
        <select name="item_sync_batch" style="width:100%;">
          <?php foreach ($batchOptions as $value): ?>
            <option value="<?= e((string)$value) ?>" <?= $currentBatch === $value ? 'selected' : '' ?>><?= e((string)$value) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h2 style="margin-top:20px;">複合キーワード（最大5）</h2>
    <p>単体キーワード（例: 学園）または複合（例: A,B）を入力できます。複合はAPIに「BはAが大好き」として渡します。</p>

    <div class="admin-auto-grid" style="display:grid;grid-template-columns:160px minmax(280px,1fr);gap:12px 16px;align-items:center;">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <div><strong>キーワード<?= e((string)$i) ?></strong></div>
        <div><input type="text" name="item_sync_compound_<?= e((string)$i) ?>" value="<?= e((string)($compoundLines[$i - 1] ?? '')) ?>" placeholder="A,B" style="width:100%;"></div>
      <?php endfor; ?>
    </div>

    <h2 style="margin-top:20px;">拒否（禁止）キーワード（最大5）</h2>
    <p>タイトル部分一致で除外します（表示/投稿どちらにも適用）。</p>

    <div class="admin-auto-grid" style="display:grid;grid-template-columns:160px minmax(280px,1fr);gap:12px 16px;align-items:center;">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <div><strong>除外キーワード<?= e((string)$i) ?></strong></div>
        <div><input type="text" name="item_sync_exclude_<?= e((string)$i) ?>" value="<?= e((string)($excludeLines[$i - 1] ?? '')) ?>" style="width:100%;"></div>
      <?php endfor; ?>
    </div>

    <div class="admin-actions" style="margin-top:20px;"><button type="submit">保存</button></div>
  </form>


  <h2 style="margin-top:24px;">商品数の内訳</h2>
  <?php if ($storedItemCount !== null && $publicItemCount !== null && $nonPublicItemCount !== null): ?>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>保存済み商品</strong><p><?= e(number_format($storedItemCount)) ?>件</p></article>
      <article class="admin-card admin-status-card"><strong>公開作品</strong><p><?= e(number_format($publicItemCount)) ?>件</p></article>
      <article class="admin-card admin-status-card"><strong>公開前・除外</strong><p><?= e(number_format($nonPublicItemCount)) ?>件</p></article>
    </div>
    <p class="admin-form-note">「公開前・除外」には、発売日前の商品や公開対象外の商品などが含まれます。保存済み商品と公開作品の件数が異なるのは正常です。</p>
  <?php else: ?>
    <p class="admin-form-note">商品数の内訳を取得できませんでした。</p>
  <?php endif; ?>

  <h2 style="margin-top:24px;">女優数の内訳</h2>
  <?php if ($storedActressCount !== null && $publicActressCount !== null && $nonPublicActressCount !== null): ?>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>保存済み女優</strong><p><?= e(number_format($storedActressCount)) ?>人</p></article>
      <article class="admin-card admin-status-card"><strong>公開女優</strong><p><?= e(number_format($publicActressCount)) ?>人</p></article>
      <article class="admin-card admin-status-card"><strong>公開前・除外</strong><p><?= e(number_format($nonPublicActressCount)) ?>人</p></article>
    </div>
    <p class="admin-form-note">「公開女優」は、フロントの女優一覧に表示される人数です。保存済み女優との差には、公開作品に関連付いていない女優などが含まれます。</p>
  <?php else: ?>
    <p class="admin-form-note">女優数の内訳を取得できませんでした。</p>
  <?php endif; ?>

  <h2 style="margin-top:24px;">自動更新状態</h2>
  <table class="admin-table">
    <tr><th>ジョブ</th><th>最終実行日時</th><th>成功</th><th>メッセージ</th><th>次回offset</th><th>ロック期限</th></tr>
    <?php foreach ($autoStates as $state): ?>
      <tr>
        <td><?= e(['items' => '商品', 'actresses' => '女優'][(string)($state['job_key'] ?? '')] ?? (string)($state['job_key'] ?? '')) ?></td>
        <td><?= e((string)($state['last_run_at'] ?? '')) ?></td>
        <td><?= ((int)($state['last_success'] ?? 0) === 1) ? '成功' : '未成功' ?></td>
        <td><?= e((string)($state['last_message'] ?? '')) ?></td>
        <td><?= e((string)($state['next_offset'] ?? '1')) ?></td>
        <td><?= e((string)($state['lock_until'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="admin-form-note">「新規: 0件 / 更新: ○件」は、cronが正常に動作し、取得した商品がすべて登録済みだったことを表します。新しい商品が見つかった回だけ保存済み商品数が増えます。</p>

  <?php if ($enabled): ?>
    <div class="admin-notice admin-notice--success" id="auto-timer-status">
      <p>自動更新はONです。同期はcronでのみ実行されます。この画面を開いていても自動更新は開始しません。</p>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
