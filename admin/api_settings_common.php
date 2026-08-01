<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';

/** @var string $pageTitle */
/** @var string $testButtonLabel */

if (!isset($pageTitle)) {
    throw new RuntimeException('api settings page title is not initialized.');
}

auth_require_admin();

$apiType = 'items';
$title = $pageTitle;
$testButtonLabel = (string)($testButtonLabel ?? '商品情報を10件テスト取得して保存');
$message = '';
$messageType = 'success';
$cred = api_credential_get($apiType);
$apiId = (string)($cred['api_id'] ?? '');
$affiliateId = (string)($cred['affiliate_id'] ?? '');
$bannerId = (string)(settings_get()['banner_id'] ?? '01');
$testResult = null;
$savedRows = [];
$currentPage = 1;
$perPage = 50;
$totalRows = 0;
$totalPages = 1;

$saveTargets = [
    'items' => ['table' => 'items', 'label' => '商品', 'id_column' => 'id', 'name_column' => 'title'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $action = (string)post('action', 'save');

    if ($action === 'save') {
        $apiId = trim((string)post('api_id', ''));
        $affiliateId = trim((string)post('affiliate_id', ''));
        $bannerId = settings_normalize_banner_id((string)post('banner_id', '01'));
        api_credential_set($apiType, $apiId, $affiliateId);
        site_setting_set_many(['duga_banner_id' => $bannerId]);
        $message = '設定を保存しました。';
        $messageType = 'success';
    }

    if ($action === 'test_save') {
        try {
            $apiId = trim((string)post('api_id', $apiId));
            $affiliateId = trim((string)post('affiliate_id', $affiliateId));
            $bannerId = settings_normalize_banner_id((string)post('banner_id', $bannerId));
            api_credential_set($apiType, $apiId, $affiliateId);
            site_setting_set_many(['duga_banner_id' => $bannerId]);
            $sync = duga_sync_service($apiType);

            $s = settings_get();
            $beforeCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
            $testOffset = (int)site_setting_get('item_sync_test_offset', '1');
            if ($testOffset < 1) {
                $testOffset = 1;
            }
            $sortModes = ['release', 'new', 'favorite'];
            $sortIndex = (int)site_setting_get('item_sync_test_sort_index', '0');
            if ($sortIndex < 0) {
                $sortIndex = 0;
            }
            $sort = $sortModes[$sortIndex % count($sortModes)];
            $processed = 0;
            $nextOffset = $testOffset;
            $attempts = 0;
            $afterCount = $beforeCount;
            while ($attempts < 12) {
                $attempts++;
                $result = $sync->syncItemsBatch(
                    (string)$s['site'],
                    (string)$s['service'],
                    (string)$s['floor'],
                    10,
                    $nextOffset,
                    ['sort' => $sort]
                );
                $processed += (int)($result['synced_count'] ?? 0);
                $nextOffset = (int)($result['next_offset'] ?? 1);
                if ($nextOffset < 1) {
                    $nextOffset = 1;
                }
                $afterCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
                if ($afterCount > $beforeCount) {
                    break;
                }
            }
            site_setting_set_many([
                'item_sync_test_offset' => (string)$nextOffset,
                'item_sync_test_sort_index' => (string)($sortIndex + 1),
            ]);
            $inserted = max(0, $afterCount - $beforeCount);
            $updated = max(0, $processed - $inserted);

            $message = '商品情報を10件テスト取得して保存しました。処理件数: ' . (string)$processed
                . ' / 保存済み合計: ' . (string)$afterCount
                . ' / 新規追加: ' . (string)$inserted
                . ' / 更新: ' . (string)$updated
                . ' / sort: ' . $sort
                . ' / 試行: ' . (string)$attempts
                . ' / 次回offset: ' . (string)$nextOffset;
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = '保存に失敗しました: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($action === 'delete_row') {
        $target = $saveTargets[$apiType] ?? null;
        if (is_array($target)) {
            $id = (int)post('row_id', 0);
            if ($id > 0) {
                if ($apiType === 'items') {
                    $deleteStmt = db()->prepare('DELETE FROM items WHERE id = :id');
                    $deleteStmt->execute([':id' => $id]);
                    $message = '商品を削除しました（商品ページで使用する画像情報を含む）。';
                } else {
                    db()->prepare('DELETE FROM ' . $target['table'] . ' WHERE ' . $target['id_column'] . ' = :id')->execute([':id' => $id]);
                    $message = $target['label'] . 'を削除しました。';
                }
                $messageType = 'success';
            }
        }
    }
}

$target = $saveTargets[$apiType] ?? null;
if (is_array($target)) {
    $currentPage = max(1, (int)($_GET['page'] ?? 1));
    $countStmt = db()->query('SELECT COUNT(*) AS c FROM ' . $target['table']);
    $totalRows = (int)(($countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : ['c' => 0])['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $stmt = db()->prepare(
        'SELECT ' . $target['id_column'] . ' AS row_id, content_id, ' . $target['name_column'] . ' AS row_name, updated_at
         FROM ' . $target['table'] . '
         ORDER BY ' . $target['id_column'] . ' DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $savedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1><?= e($pageTitle) ?></h1>
  <p>PinkClub-DLではDUGAの商品検索APIだけを使用します。ここで商品取得用のアプリケーションID・代理店ID・バナーIDを設定します。</p>

  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>">
      <p><?= e($message) ?></p>
    </div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:700px;" id="duga-api-settings-form">
    <?= csrf_input() ?>
    <div>
      <label>アプリケーションID<br><input type="password" name="api_id" value="<?= e($apiId) ?>" autocomplete="off" style="width:100%"></label>
    </div>
    <div>
      <label>代理店ID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" inputmode="numeric" style="width:100%"></label>
    </div>
    <div>
      <label>バナーID<br><input type="text" name="banner_id" value="<?= e($bannerId) ?>" inputmode="numeric" maxlength="2" style="width:100%"></label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" name="action" value="save">保存</button>
      <button type="submit" name="action" value="test_save" class="button-secondary"><?= e($testButtonLabel) ?></button>
    </div>
  </form>
  <div id="duga-api-progress" class="admin-notice admin-notice--success" style="display:none;margin-top:12px;" role="status" aria-live="polite">
    <p style="margin-bottom:8px;">DUGA APIから取得中です。1秒に1回の制限を守っているため、そのままお待ちください。</p>
    <progress style="width:100%;"></progress>
  </div>
  <p style="margin-top:12px;"><small>アプリケーションIDはGitHubのファイルへ書かず、この画面から保存してください。</small></p>

  <?php if (is_array($testResult)): ?>
    <h2>テスト結果</h2>
    <pre style="white-space:pre-wrap;background:#111;color:#f3f3f3;padding:12px;border-radius:6px;"><?= e((string)json_encode($testResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  <?php endif; ?>

  <?php if (is_array($target)): ?>
    <h2>保存済み<?= e($target['label']) ?>（全<?= e((string)$totalRows) ?>件 / 最新50件）</h2>
    <table class="admin-table">
      <tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr>
      <?php foreach ($savedRows as $index => $row): ?>
        <tr>
          <td><?= e((string)max(1, $totalRows - $offset - (int)$index)) ?></td>
          <td><a href="<?= e(public_url('item.php?cid=' . rawurlencode((string)($row['content_id'] ?? '')))) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($row['row_name'] ?? '')) ?></a></td>
          <td><?= e((string)($row['updated_at'] ?? '')) ?></td>
          <td>
            <form method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="delete_row">
              <input type="hidden" name="row_id" value="<?= e((string)($row['row_id'] ?? '0')) ?>">
              <button type="submit" class="button-secondary">削除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($totalPages > 1): ?>
      <?php
      $pageStart = $currentPage;
      $pageEnd = min($totalPages, $pageStart + 9);
      ?>
      <nav style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($currentPage > 1): ?>
          <a href="<?= e(admin_url(basename((string)$_SERVER['PHP_SELF']) . '?page=' . (string)($currentPage - 1))) ?>">&lt;&lt;前へ</a>
        <?php endif; ?>
        <?php for ($i = $pageStart; $i <= $pageEnd; $i++): ?>
          <?php if ($i === $currentPage): ?>
            <strong><?= e((string)$i) ?></strong>
          <?php else: ?>
            <a href="<?= e(admin_url(basename((string)$_SERVER['PHP_SELF']) . '?page=' . (string)$i)) ?>"><?= e((string)$i) ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?>
          <a href="<?= e(admin_url(basename((string)$_SERVER['PHP_SELF']) . '?page=' . (string)($currentPage + 1))) ?>">次へ&gt;&gt;</a>
        <?php endif; ?>
        <form method="get" style="display:flex;gap:4px;align-items:center;margin:0 0 0 auto;">
          <input type="number" name="page" value="<?= e((string)$currentPage) ?>" min="1" max="<?= e((string)$totalPages) ?>" style="width:70px;">
          <span>/ <?= e((string)$totalPages) ?></span>
          <button type="submit" class="button-secondary">移動</button>
        </form>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<script>
(() => {
  const form = document.getElementById('duga-api-settings-form');
  const progress = document.getElementById('duga-api-progress');
  if (!form || !progress) return;
  form.addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (!submitter || submitter.value !== 'test_save') return;
    progress.style.display = 'block';
    submitter.disabled = true;
    submitter.textContent = '取得中…';
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

